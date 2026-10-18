<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Classes\Idempotencia;
use Controllers\EquipoController;
use Controllers\ProfesionalController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Repositories\ProfesionalHomonimosRepository;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\AuthorizationService;
use Services\ColaboradorService;
use Services\CorreoSalidaService;
use Services\HomonimosProfesionalesService;
use Services\ProfesionalService;

/**
 * Aviso de posibles homónimos al dar de alta un profesional (/profesionales/crear y ficha nueva del asistente):
 * confirmación explícita con token HMAC no forjable, decisión auditada en la misma transacción, idempotencia,
 * sin fusionar ni bloquear, sin exponer PII y con concurrencia real.
 */
final class HomonimosProfesionalesIntegrationTest extends TestCase
{
    private const SUF = '@homonimos-prof.test';
    private \mysqli $db;
    private int $owner;
    private int $servicio;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->limpiar();
        Email::setTransport(static fn(): bool => true);
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.73';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        http_response_code(200);
        unset($GLOBALS['__appsalon_redireccion']);
        $this->owner = $this->cuenta('propietario');
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES ('Servicio HomProf',10.00,30)");
        $this->servicio = (int)$this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if ($this->db->ping()) {
            $this->db->query('DROP TRIGGER IF EXISTS test_fail_decision_prof');
            $this->limpiar();
        }
        ActiveRecord::setDB($this->db);
        Email::setTransport(null);
        $_SESSION = []; $_POST = []; $_GET = [];
        http_response_code(200);
    }

    private function limpiar(): void
    {
        // La tabla de decisiones es de solo anexado (disparadores): se vacía de forma explícita solo en esta base de pruebas.
        $this->db->query('DROP TRIGGER IF EXISTS trg_prof_homonimo_no_delete');
        $this->db->query("DELETE FROM profesionales_homonimos_decisiones WHERE actor_usuario_id IN (SELECT id FROM usuarios WHERE email LIKE '%" . self::SUF . "') OR actor_usuario_id < 0");
        $this->db->query("CREATE TRIGGER trg_prof_homonimo_no_delete BEFORE DELETE ON profesionales_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'profesionales_homonimos_decisiones es de solo anexado'");
        $this->db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM profesionales WHERE nombre LIKE 'HomProf %'");
        $this->db->query("DELETE FROM colaboradores WHERE email_norm LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM servicios WHERE nombre='Servicio HomProf'");
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE actor LIKE 'equipo:%'");
        $this->db->query('DELETE FROM intentos_login');
    }

    private function n(string $sql): int
    {
        return (int)$this->db->query($sql)->fetch_assoc()['n'];
    }

    private function cuenta(string $rol): int
    {
        $email = $rol . '-' . bin2hex(random_bytes(4)) . self::SUF;
        $hash = password_hash('ClaveSegura123', PASSWORD_BCRYPT);
        $admin = in_array($rol, ['propietario', 'gerente'], true) ? 1 : 0;
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('" . ucfirst($rol) . "','Pruebas','$email','$hash','0991234567',$admin,1)");
        $id = (int)$this->db->insert_id;
        $this->db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ($id,'$rol',1)");
        $this->db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,codigo) VALUES ($id,'$email','" . ucfirst($rol) . "','Pruebas','$rol','activo',UTC_TIMESTAMP(),CONCAT('T-',$id))");
        return $id;
    }

    private function ficha(string $nombre, int $activo = 1): int
    {
        $this->db->query("INSERT INTO profesionales(nombre,activo) VALUES ('$nombre',$activo)");
        return (int)$this->db->insert_id;
    }

    private function servicioProf(): ProfesionalService
    {
        return new ProfesionalService(new ProfesionalRepository($this->db), new ServicioRepository($this->db));
    }

    private function colab(): ColaboradorService
    {
        return new ColaboradorService(new ColaboradorRepository($this->db), new CorreoSalidaService(new CorreoSalidaRepository($this->db)));
    }

    private function crear(string $nombre, string $clave, array $extra = [], ?int $actor = null): array
    {
        $actor ??= $this->owner;
        return $this->servicioProf()->crear(['nombre' => $nombre, 'activo' => '1', 'servicios' => [(string)$this->servicio]] + $extra, $clave,
            Idempotencia::actorUsuario($actor, 'equipo'), ['actor_id' => $actor, 'actor_rol' => 'propietario', 'ip_hash' => null]);
    }

    private function decisiones(): array
    {
        return $this->db->query("SELECT * FROM profesionales_homonimos_decisiones WHERE actor_usuario_id={$this->owner} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }

    // ------------------------------------------------------------------ Reglas reutilizadas

    public function testDetectaIgualOrdenInvertidoParecidoYAunInactivasPeroNoPersonasDistintas(): void
    {
        $a = $this->ficha('HomProf Laura Gómez');
        $b = $this->ficha('HomProf Inactiva Torres', 0);
        $svc = new HomonimosProfesionalesService(new ProfesionalHomonimosRepository($this->db));
        $ids = fn(string $n) => array_column($svc->buscar($n), 'id');
        $this->assertContains($a, $ids('homprof  laura GOMEZ'), 'Igual tras normalizar (tildes, mayúsculas, espacios)');
        $this->assertContains($a, $ids('Gómez Laura HomProf'), 'Mismos términos en otro orden');
        $this->assertContains($a, $ids('HomProf Laura Gomes'), 'Error de tecleo');
        $this->assertContains($b, $ids('HomProf Inactiva Torres'), 'Una ficha inactiva también es un posible duplicado');
        $this->assertNotContains($a, $ids('HomProf Camila Zambrano'));
        $c = $svc->buscar('HomProf Laura Gómez')[0];
        $this->assertSame(['id', 'nombre', 'activo', 'vinculada', 'criterios'], array_keys($c), 'Solo nombre, estado y vínculo: sin correos ni teléfonos');
    }

    // ------------------------------------------------------------------ /profesionales/crear (servicio)

    public function testSinCoincidenciasSeCreaDeInmediatoSinDecisionRegistrada(): void
    {
        $r = $this->crear('HomProf Unica Persona', Idempotencia::nuevaClave());
        $this->assertSame(ProfesionalService::STATUS_OK, $r['status'], json_encode($r['alertas']));
        $this->assertSame([], $this->decisiones());
    }

    public function testConCoincidenciaNoSeCreaHastaConfirmarYNuncaSeFusionaNiSeBloquea(): void
    {
        $existente = $this->ficha('HomProf Laura Gómez');
        $antes = $this->n('SELECT COUNT(*) n FROM profesionales');
        $clave = Idempotencia::nuevaClave();
        $r = $this->crear('HomProf Laura Gomez', $clave);
        $this->assertSame(ProfesionalService::STATUS_REQUIERE_CONFIRMACION, $r['status']);
        $this->assertSame([$existente], array_column($r['homonimos']['coincidencias'], 'id'));
        $this->assertSame($antes, $this->n('SELECT COUNT(*) n FROM profesionales'), 'Sin confirmación no se crea nada');
        $this->assertSame([], $this->decisiones());
        $this->assertSame(HomonimosProfesionalesService::token($this->owner, $clave, [$existente]), $r['homonimos']['token']);

        $ok = $this->crear('HomProf Laura Gomez', $clave, ['decision' => 'confirmar', 'token_confirmacion' => $r['homonimos']['token']]);
        $this->assertSame(ProfesionalService::STATUS_OK, $ok['status'], json_encode($ok['alertas']));
        $this->assertSame($antes + 1, $this->n('SELECT COUNT(*) n FROM profesionales'), 'Se crea otra ficha: no se fusiona ni se reutiliza la existente');
        $this->assertSame('HomProf Laura Gómez', $this->db->query("SELECT nombre FROM profesionales WHERE id=$existente")->fetch_assoc()['nombre'], 'La ficha existente no se toca');
        $d = $this->decisiones();
        $this->assertCount(1, $d);
        $this->assertSame('continuo', $d[0]['decision']);
        $this->assertSame('profesional_nuevo', $d[0]['ambito']);
        $this->assertSame([$existente], json_decode($d[0]['coincidencias_ids'], true));
        $this->assertSame((int)$ok['id'], (int)$d[0]['profesional_creado_id']);
        $this->assertSame(hash('sha256', $r['homonimos']['token']), $d[0]['token_hash']);
        $this->assertSame(hash('sha256', $clave), $d[0]['clave_hash']);
        $this->assertSame('propietario', $d[0]['actor_rol']);
        $this->assertStringContainsString('nombre_', $d[0]['criterios']);
    }

    public function testElTokenNoSePuedeForjarNiReutilizarConOtroOperadorClaveOConjunto(): void
    {
        $existente = $this->ficha('HomProf Laura Gómez');
        $clave = Idempotencia::nuevaClave();
        $otroOperador = $this->cuenta('gerente');
        $antes = $this->n('SELECT COUNT(*) n FROM profesionales');
        $forjados = [
            'aleatorio' => bin2hex(random_bytes(32)),
            'de otro operador' => HomonimosProfesionalesService::token($otroOperador, $clave, [$existente]),
            'de otra clave' => HomonimosProfesionalesService::token($this->owner, Idempotencia::nuevaClave(), [$existente]),
            'de otro conjunto' => HomonimosProfesionalesService::token($this->owner, $clave, [$existente, $existente + 1]),
            'de clientes' => hash_hmac('sha256', 'homonimos|' . $this->owner . '|' . $clave . '|' . $existente, 'x'),
            'vacío' => '',
        ];
        foreach ($forjados as $nombre => $token) {
            $r = $this->crear('HomProf Laura Gómez', $clave, ['decision' => 'confirmar', 'token_confirmacion' => $token]);
            $this->assertSame(ProfesionalService::STATUS_REQUIERE_CONFIRMACION, $r['status'], $nombre);
            $this->assertTrue($r['homonimos']['confirmacion_invalida'], $nombre);
        }
        $this->assertSame($antes, $this->n('SELECT COUNT(*) n FROM profesionales'));
        $this->assertSame([], $this->decisiones());
        // Si el conjunto cambia (aparece otro homónimo entre el aviso y la confirmación), el token anterior deja de valer.
        $visto = $this->crear('HomProf Laura Gómez', $clave)['homonimos']['token'];
        $nuevo = $this->ficha('HomProf Laura Gomez');
        $r = $this->crear('HomProf Laura Gómez', $clave, ['decision' => 'confirmar', 'token_confirmacion' => $visto]);
        $this->assertSame(ProfesionalService::STATUS_REQUIERE_CONFIRMACION, $r['status']);
        $this->assertEqualsCanonicalizing([$existente, $nuevo], array_column($r['homonimos']['coincidencias'], 'id'));
        $this->assertSame($antes + 1, $this->n('SELECT COUNT(*) n FROM profesionales'), 'Solo la ficha que acabamos de insertar a mano');
    }

    public function testDobleClicTrasConfirmarNoDuplicaFichaNiDecisionNiRepiteElAviso(): void
    {
        $this->ficha('HomProf Laura Gómez');
        $clave = Idempotencia::nuevaClave();
        $token = $this->crear('HomProf Laura Gomez', $clave)['homonimos']['token'];
        $extra = ['decision' => 'confirmar', 'token_confirmacion' => $token];
        $a = $this->crear('HomProf Laura Gomez', $clave, $extra);
        $b = $this->crear('HomProf Laura Gomez', $clave, $extra);
        $c = $this->crear('HomProf Laura Gomez', $clave); // reintento «a ciegas» del primer envío
        $this->assertSame([ProfesionalService::STATUS_OK, ProfesionalService::STATUS_OK, ProfesionalService::STATUS_OK], [$a['status'], $b['status'], $c['status']]);
        $this->assertTrue($b['repetida'] && $c['repetida']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'"));
        $this->assertCount(1, $this->decisiones());
    }

    public function testCancelarNoCreaNadaRegistraLaDecisionYLlevaALaFichaExistente(): void
    {
        $existente = $this->ficha('HomProf Laura Gómez');
        $antes = $this->n('SELECT COUNT(*) n FROM profesionales');
        $clave = Idempotencia::nuevaClave();
        $r = $this->crear('HomProf Laura Gómez', $clave, ['decision' => 'cancelar']);
        $this->assertSame(ProfesionalService::STATUS_CANCELADO, $r['status']);
        $this->assertSame($existente, $r['id_existente']);
        $this->assertSame($antes, $this->n('SELECT COUNT(*) n FROM profesionales'));
        $d = $this->decisiones();
        $this->assertCount(1, $d);
        $this->assertSame('cancelo', $d[0]['decision']);
        $this->assertNull($d[0]['profesional_creado_id']);
        // Repetir la cancelación no duplica la auditoría.
        $this->crear('HomProf Laura Gómez', $clave, ['decision' => 'cancelar']);
        $this->assertCount(1, $this->decisiones());
        // «Cancelar» sin coincidencias tampoco crea nada.
        $this->assertSame(ProfesionalService::STATUS_CANCELADO, $this->crear('HomProf Sin Parecidos', Idempotencia::nuevaClave(), ['decision' => 'cancelar'])['status']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM profesionales WHERE nombre='HomProf Sin Parecidos'"));
    }

    public function testElAvisoNoExponeCorreosNiTelefonosDeLaPersonaVinculada(): void
    {
        $cuenta = $this->cuenta('profesional');
        $email = (string)$this->db->query("SELECT email FROM usuarios WHERE id=$cuenta")->fetch_assoc()['email'];
        $colab = (int)$this->db->query("SELECT id FROM colaboradores WHERE usuario_id=$cuenta")->fetch_assoc()['id'];
        $this->db->query("INSERT INTO profesionales(nombre,activo,usuarioId,colaborador_id) VALUES ('HomProf Con Cuenta',1,$cuenta,$colab)");
        $r = $this->crear('HomProf Con Cuenta', Idempotencia::nuevaClave());
        $this->assertSame(ProfesionalService::STATUS_REQUIERE_CONFIRMACION, $r['status']);
        $this->assertTrue($r['homonimos']['coincidencias'][0]['vinculada']);
        $json = json_encode($r);
        $this->assertStringNotContainsString($email, $json);
        $this->assertStringNotContainsString('0991234567', $json);
    }

    public function testSiFallaLaAuditoriaNoQuedaLaFichaCreada(): void
    {
        $this->ficha('HomProf Laura Gómez');
        $clave = Idempotencia::nuevaClave();
        $token = $this->crear('HomProf Laura Gomez', $clave)['homonimos']['token'];
        $this->db->query("CREATE TRIGGER test_fail_decision_prof BEFORE INSERT ON profesionales_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated failure in decisiones'");
        $r = $this->crear('HomProf Laura Gomez', $clave, ['decision' => 'confirmar', 'token_confirmacion' => $token]);
        $this->db->query('DROP TRIGGER test_fail_decision_prof');
        $this->assertSame(ProfesionalService::STATUS_ERROR, $r['status']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'"), 'O existen ficha y decisión, o ninguna');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE scope='profesional.crear' AND actor='equipo:{$this->owner}'"));
        $ok = $this->crear('HomProf Laura Gomez', $clave, ['decision' => 'confirmar', 'token_confirmacion' => $token]);
        $this->assertSame(ProfesionalService::STATUS_OK, $ok['status']);
    }

    public function testElAuditoriaEsDeSoloAnexado(): void
    {
        $this->ficha('HomProf Laura Gómez');
        $this->crear('HomProf Laura Gómez', Idempotencia::nuevaClave(), ['decision' => 'cancelar']);
        $this->assertCount(1, $this->decisiones());
        try {
            $ok = $this->db->query("UPDATE profesionales_homonimos_decisiones SET decision='continuo' WHERE actor_usuario_id={$this->owner}");
        } catch (\mysqli_sql_exception) {
            $ok = false;
        }
        $this->assertFalse($ok, 'El disparador impide modificar decisiones ya registradas');
        $this->assertSame('cancelo', $this->decisiones()[0]['decision']);
    }

    // ------------------------------------------------------------------ Asistente de invitación (ficha nueva)

    private function invitar(string $nombreProf, ?string $clave = null, array $extra = []): array
    {
        $email = 'inv-' . bin2hex(random_bytes(4)) . self::SUF;
        return $this->colab()->invitar($this->owner, $extra + ['nombre' => 'Marta', 'apellido' => 'Homonima', 'email' => $email, 'rol' => 'profesional', 'nombre_profesional' => $nombreProf,
            'servicios' => [(string)$this->servicio], 'franjas' => [], 'descansos' => []], $clave ?? Idempotencia::nuevaClave());
    }

    public function testElAsistenteExigeTokenParaCrearUnaFichaNuevaHomonimaYAudita(): void
    {
        $existente = $this->ficha('HomProf Laura Gómez');
        $colaboradores = $this->n('SELECT COUNT(*) n FROM colaboradores');
        $fichas = $this->n('SELECT COUNT(*) n FROM profesionales');
        $clave = Idempotencia::nuevaClave();
        $email = 'inv-fijo' . self::SUF;
        $r = $this->invitar('HomProf Laura Gomez', $clave, ['email' => $email]);
        $this->assertSame(ColaboradorService::INVALID, $r['status']);
        $this->assertTrue($r['requiere_homonimo']);
        $this->assertSame([$existente], array_column($r['homonimos']['coincidencias'], 'id'));
        $this->assertSame([$colaboradores, $fichas], [$this->n('SELECT COUNT(*) n FROM colaboradores'), $this->n('SELECT COUNT(*) n FROM profesionales')]);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
        // La casilla antigua, un token forjado o el de otra clave no sirven.
        foreach ([['confirmar_homonimo' => '1'], ['decision' => 'confirmar', 'token_confirmacion' => 'forjado'], ['decision' => 'confirmar', 'token_confirmacion' => HomonimosProfesionalesService::token($this->owner, Idempotencia::nuevaClave(), [$existente])]] as $extra) {
            $this->assertSame(ColaboradorService::INVALID, $this->invitar('HomProf Laura Gomez', $clave, ['email' => $email] + $extra)['status']);
        }
        $this->assertSame($colaboradores, $this->n('SELECT COUNT(*) n FROM colaboradores'));
        $ok = $this->invitar('HomProf Laura Gomez', $clave, ['email' => $email, 'decision' => 'confirmar', 'token_confirmacion' => $r['homonimos']['token']]);
        $this->assertSame(ColaboradorService::OK, $ok['status'], json_encode($ok));
        $d = $this->decisiones();
        $this->assertCount(1, $d);
        $this->assertSame('ficha_asistente', $d[0]['ambito']);
        $this->assertSame((int)$ok['profesional_id'], (int)$d[0]['profesional_creado_id']);
        $this->assertSame($colaboradores + 1, $this->n('SELECT COUNT(*) n FROM colaboradores'));
        // Doble clic con la misma clave: nada se duplica.
        $otra = $this->invitar('HomProf Laura Gomez', $clave, ['email' => $email, 'decision' => 'confirmar', 'token_confirmacion' => $r['homonimos']['token']]);
        $this->assertTrue($otra['repetida']);
        $this->assertCount(1, $this->decisiones());
        $this->assertSame($fichas + 1, $this->n('SELECT COUNT(*) n FROM profesionales'));
    }

    public function testElAsistenteSiFallaLaAuditoriaDeshaceInvitacionYFichaYCancelarNoCreaNada(): void
    {
        $this->ficha('HomProf Laura Gómez');
        $clave = Idempotencia::nuevaClave();
        $email = 'inv-atom' . self::SUF;
        $token = $this->invitar('HomProf Laura Gomez', $clave, ['email' => $email])['homonimos']['token'];
        $this->db->query("CREATE TRIGGER test_fail_decision_prof BEFORE INSERT ON profesionales_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated failure in decisiones'");
        $r = $this->invitar('HomProf Laura Gomez', $clave, ['email' => $email, 'decision' => 'confirmar', 'token_confirmacion' => $token]);
        $this->db->query('DROP TRIGGER test_fail_decision_prof');
        $this->assertSame(ColaboradorService::ERROR, $r['status']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
        $c = $this->invitar('HomProf Laura Gomez', Idempotencia::nuevaClave(), ['email' => $email, 'decision' => 'cancelar']);
        $this->assertSame(ColaboradorService::CANCELADO, $c['status']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame('cancelo', $this->decisiones()[0]['decision']);
    }

    public function testVincularUnaFichaHeredadaExistenteNoDisparaElAvisoPorqueNoCreaFicha(): void
    {
        $heredada = $this->ficha('HomProf Heredada Uno');
        $r = $this->invitar('', null, ['profesional_id' => (string)$heredada]);
        $this->assertSame(ColaboradorService::OK, $r['status'], json_encode($r));
        $this->assertSame([], $this->decisiones());
    }

    // ------------------------------------------------------------------ HTTP

    private function sesion(int $id): void
    {
        $ctx = (new AuthorizationService($this->db))->contexto($id);
        $_SESSION = ['login' => true, 'id' => $id, 'portal' => 'equipo', 'version_sesion' => (int)$ctx['version_sesion'], 'csrf_token' => 'csrf-hom'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function ejecutar(callable $fn): array
    {
        $status = null;
        http_response_code(200);
        ob_start();
        try { $fn(); } catch (AppTerminationException $e) { $status = $e->getStatusCode(); }
        $salida = (string)ob_get_clean();
        return [$status ?? (int)http_response_code(), $salida];
    }

    public function testElFormularioDeProfesionalesMuestraElAvisoYSoloCreaConElTokenCorrecto(): void
    {
        $existente = $this->ficha('HomProf Laura Gómez');
        $this->sesion($this->owner);
        $clave = Idempotencia::nuevaClave();
        $post = ['csrf_token' => 'csrf-hom', 'idem_key' => $clave, 'nombre' => 'Laura', 'apellido' => 'Gomez', 'nombre_agenda' => 'HomProf Laura Gomez', 'email' => 'alta-http' . self::SUF, 'activo' => '1', 'servicios' => [(string)$this->servicio]];
        $_POST = $post;
        [$http, $html] = $this->ejecutar(fn() => ProfesionalController::crear(new Router()));
        $this->assertSame(200, $http);
        $this->assertStringContainsString('Posibles profesionales ya registrados', $html);
        $this->assertStringContainsString('HomProf Laura Gómez', $html);
        $this->assertStringContainsString('Es una persona distinta: crear ficha', $html);
        $this->assertStringContainsString('name="idem_key" value="' . $clave . '"', $html, 'El aviso conserva la clave de operación (el token está ligado a ella)');
        $this->assertStringNotContainsString('value="Guardar Profesional"', $html);
        preg_match('/name="token_confirmacion" value="([a-f0-9]{64})"/', $html, $m);
        $this->assertNotEmpty($m, 'El aviso incluye el token');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'"));
        // El primer botón del formulario (el que activa Intro) nunca confirma.
        $this->assertLessThan(strpos($html, 'value="confirmar"'), strpos($html, 'name="decision" value="crear"'));
        // Token forjado: 409 y nada se crea.
        $_POST = $post + ['decision' => 'confirmar', 'token_confirmacion' => str_repeat('a', 64)];
        [$http] = $this->ejecutar(fn() => ProfesionalController::crear(new Router()));
        $this->assertSame(409, $http);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'"));
        // CSRF inválido.
        $_POST = ['csrf_token' => 'otro'] + $post + ['decision' => 'confirmar', 'token_confirmacion' => $m[1]];
        [$http] = $this->ejecutar(fn() => ProfesionalController::crear(new Router()));
        $this->assertSame(403, $http);
        // Confirmación correcta.
        $_POST = $post + ['decision' => 'confirmar', 'token_confirmacion' => $m[1]];
        [$http] = $this->ejecutar(fn() => ProfesionalController::crear(new Router()));
        $this->assertSame(302, $http);
        $this->assertSame('/profesionales', $GLOBALS['__appsalon_redireccion']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'"));
        // Cancelar lleva a la ficha existente.
        $clave2 = Idempotencia::nuevaClave();
        $_POST = ['csrf_token' => 'csrf-hom', 'idem_key' => $clave2, 'nombre' => 'Laura', 'apellido' => 'Gomez', 'nombre_agenda' => 'HomProf Laura Gómez', 'email' => 'cancel-http' . self::SUF, 'activo' => '1', 'servicios' => [(string)$this->servicio], 'decision' => 'cancelar'];
        [$http] = $this->ejecutar(fn() => ProfesionalController::crear(new Router()));
        $this->assertSame(302, $http);
        $this->assertSame('/profesionales/actualizar?id=' . $existente, $GLOBALS['__appsalon_redireccion']);
    }

    public function testSinPermisoDeProfesionalesNoSeVeElAvisoNiSeCrea(): void
    {
        $this->ficha('HomProf Laura Gómez');
        foreach (['recepcion', 'finanzas', 'profesional'] as $rol) {
            $this->sesion($this->cuenta($rol));
            $_POST = ['csrf_token' => 'csrf-hom', 'idem_key' => Idempotencia::nuevaClave(), 'nombre' => 'HomProf Laura Gomez', 'activo' => '1', 'servicios' => [(string)$this->servicio]];
            [$http, $html] = $this->ejecutar(fn() => ProfesionalController::crear(new Router()));
            $this->assertSame(403, $http, $rol);
            $this->assertStringNotContainsString('Laura', $html);
        }
        $this->assertSame([], $this->db->query("SELECT 1 FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'")->fetch_all());
    }

    public function testElFormularioDeInvitacionMuestraElAvisoConLaMismaClaveYSinElBotonDeEnviar(): void
    {
        $this->ficha('HomProf Laura Gómez');
        $this->sesion($this->owner);
        $clave = Idempotencia::nuevaClave();
        $_POST = ['csrf_token' => 'csrf-hom', 'idem_key' => $clave, 'nombre' => 'Marta', 'apellido' => 'Homonima', 'email' => 'inv-http' . self::SUF, 'rol' => 'profesional',
            'nombre_profesional' => 'HomProf Laura Gomez', 'servicios' => [(string)$this->servicio]];
        [$http] = $this->ejecutar(fn() => EquipoController::crear());
        $this->assertSame(302, $http);
        $this->assertSame($clave, $_SESSION['equipo_homonimos']['clave']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='inv-http" . self::SUF . "'"));
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
        [$http, $html] = $this->ejecutar(fn() => EquipoController::index(new Router()));
        $this->assertSame(200, $http);
        $this->assertStringContainsString('Posibles profesionales ya registrados', $html);
        $this->assertStringContainsString('name="idem_key" value="' . $clave . '"', $html);
        $this->assertStringNotContainsString('>Enviar invitación<', $html);
        $this->assertStringContainsString('name="nombre_profesional" maxlength="120" placeholder="Por defecto: nombre y apellido" value="HomProf Laura Gomez"', $html, 'Conserva lo escrito');
    }

    // ------------------------------------------------------------------ Concurrencia real

    private function lanzar(array $cfg): array
    {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_reasignacion_worker.php', json_encode(['modo' => 'profesional', 'actor' => $this->owner] + $cfg)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("READY\n", fgets($pipes[1]));
        return [$proc, $pipes];
    }

    private function liberar(array $trabajadores): array
    {
        foreach ($trabajadores as [, $p]) { fwrite($p[0], "GO\n"); fflush($p[0]); fclose($p[0]); }
        $out = [];
        foreach ($trabajadores as [$proc, $p]) {
            $linea = fgets($p[1]); $err = stream_get_contents($p[2]); fclose($p[1]); fclose($p[2]);
            $this->assertSame(0, proc_close($proc), $err);
            $out[] = json_decode((string)$linea, true);
        }
        return $out;
    }

    public function testDosProcesosConfirmandoElMismoFormularioCreanUnaFichaYUnaDecision(): void
    {
        $existente = $this->ficha('HomProf Laura Gómez');
        $clave = Idempotencia::nuevaClave();
        $post = ['nombre' => 'HomProf Laura Gomez', 'activo' => '1', 'servicios' => [(string)$this->servicio], 'decision' => 'confirmar',
            'token_confirmacion' => HomonimosProfesionalesService::token($this->owner, $clave, [$existente])];
        $cfg = ['post' => $post, 'clave' => $clave, 'demoraMs' => 600];
        $res = $this->liberar([$this->lanzar($cfg), $this->lanzar($cfg)]);
        $this->assertSame(['ok', 'ok'], array_column($res, 'status'), json_encode($res));
        $this->assertSame($res[0]['id'], $res[1]['id']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE BINARY nombre='HomProf Laura Gomez'"));
        $this->assertCount(1, $this->decisiones());
    }

    public function testDosOperadoresConfirmandoPorSeparadoCreanDosFichasLegitimasCadaUnaConSuDecision(): void
    {
        $existente = $this->ficha('HomProf Laura Gómez');
        $mk = function (string $clave) use ($existente): array {
            return ['post' => ['nombre' => 'HomProf Laura Gomez', 'activo' => '1', 'servicios' => [(string)$this->servicio], 'decision' => 'confirmar',
                'token_confirmacion' => HomonimosProfesionalesService::token($this->owner, $clave, [$existente])], 'clave' => $clave, 'demoraMs' => 400];
        };
        $res = $this->liberar([$this->lanzar($mk(Idempotencia::nuevaClave())), $this->lanzar($mk(Idempotencia::nuevaClave()))]);
        $this->assertSame(['ok', 'ok'], array_column($res, 'status'), json_encode($res));
        $this->assertNotSame($res[0]['id'], $res[1]['id'], 'Los homónimos legítimos nunca se bloquean ni se fusionan');
        $this->assertCount(2, $this->decisiones());
    }
}
