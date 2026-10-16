<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Classes\Idempotencia;
use Controllers\EquipoController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Repositories\ProfesionalRepository;
use Services\AgendaSemanalValidador;
use Services\AuthorizationService;
use Services\CitaService;
use Services\ColaboradorService;
use Services\CorreoSalidaService;
use Services\DisponibilidadService;
use Services\ProfesionalService;

/**
 * Asistente del profesional: servicios y horario semanal (franjas, descansos) al invitar y al editar la ficha.
 * Cubre validación de servidor, atomicidad, idempotencia, permisos, CSRF, fichas heredadas y la prueba negativa
 * de que una ficha no activa jamás aparece en disponibilidad ni en reservas.
 */
final class AsistenteProfesionalIntegrationTest extends TestCase
{
    private const SUF = '@asistente-prof.test';
    private const PASS = 'ClaveSegura123';
    private \mysqli $db;
    private int $owner;
    private array $enviados = [];
    /** @var int[] */
    private array $servicios = [];

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->limpiar();
        $this->enviados = [];
        Email::setTransport(function ($mailer, string $proposito, array $datos): bool {
            $this->enviados[] = ['proposito' => $proposito, 'para' => $datos['destinatario'], 'token' => $datos['token'] ?? null];
            return true;
        });
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.71';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        http_response_code(200);
        unset($GLOBALS['__appsalon_redireccion']);
        $this->owner = $this->cuenta('propietario');
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES ('Servicio AsistProf A',10.00,30),('Servicio AsistProf B',20.00,60)");
        $primero = (int)$this->db->insert_id;
        $this->servicios = [$primero, $primero + 1];
    }

    protected function tearDown(): void
    {
        if ($this->db->ping()) {
            $this->db->query('DROP TRIGGER IF EXISTS test_fail_horario_asist');
            $this->limpiar();
        }
        ActiveRecord::setDB($this->db);
        Email::setTransport(null);
        $_SESSION = []; $_POST = []; $_GET = [];
        http_response_code(200);
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM citas WHERE usuarioId IN (SELECT id FROM usuarios WHERE email LIKE '%" . self::SUF . "')");
        $this->db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM profesionales WHERE nombre LIKE 'AsistProf %'");
        $this->db->query("DELETE FROM colaboradores WHERE email_norm LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM servicios WHERE nombre LIKE 'Servicio AsistProf %'");
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE actor LIKE 'equipo:%'");
        $this->db->query("DELETE FROM equipo_auditoria WHERE accion IN ('agenda_profesional','colaborador_invitado','invitacion_aceptada')");
        $this->db->query('DELETE FROM intentos_login');
    }

    private function n(string $sql): int
    {
        return (int)$this->db->query($sql)->fetch_assoc()['n'];
    }

    private function email(string $p = 'p'): string
    {
        return $p . '-' . bin2hex(random_bytes(4)) . self::SUF;
    }

    private function cuenta(string $rol): int
    {
        $email = $this->email($rol);
        $hash = password_hash(self::PASS, PASSWORD_BCRYPT);
        $admin = in_array($rol, ['propietario', 'gerente'], true) ? 1 : 0;
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('" . ucfirst($rol) . "','Pruebas','$email','$hash','0991234567',$admin,1)");
        $id = (int)$this->db->insert_id;
        if ($rol !== 'cliente') {
            $this->db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ($id,'$rol',1)");
            $this->db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,codigo) VALUES ($id,'$email','" . ucfirst($rol) . "','Pruebas','$rol','activo',UTC_TIMESTAMP(),CONCAT('T-',$id))");
        }
        return $id;
    }

    private function svc(): ColaboradorService
    {
        return new ColaboradorService(new ColaboradorRepository($this->db), new CorreoSalidaService(new CorreoSalidaRepository($this->db)));
    }

    /** Horario típico: lunes en dos turnos con un descanso en el primero; martes un turno. */
    private function agenda(): array
    {
        return [
            'franjas' => [1 => [['inicio' => '09:00', 'fin' => '13:00'], ['inicio' => '15:00', 'fin' => '19:00']], 2 => [['inicio' => '10:00', 'fin' => '16:00']]],
            'descansos' => [1 => [['inicio' => '11:00', 'fin' => '11:30', 'motivo' => 'Café']]],
        ];
    }

    private function invitar(string $email, array $extra = [], ?string $clave = null, ?int $actor = null): array
    {
        $base = ['nombre' => 'Marta', 'apellido' => 'Asist', 'email' => $email, 'rol' => 'profesional', 'puesto' => 'Estilista',
            'nombre_profesional' => 'AsistProf ' . substr($email, 0, 8), 'servicios' => array_map('strval', $this->servicios)] + $this->agenda();
        return $this->svc()->invitar($actor ?? $this->owner, $extra + $base, $clave ?? Idempotencia::nuevaClave());
    }

    private function fichaDe(int $colaboradorId): array
    {
        return $this->db->query("SELECT * FROM profesionales WHERE colaborador_id=$colaboradorId")->fetch_assoc();
    }

    private function proximo(int $diaIso, int $minimoDias = 3): string
    {
        $d = new \DateTimeImmutable("+$minimoDias days", new \DateTimeZone('America/Guayaquil'));
        while ((int)$d->format('N') !== $diaIso) { $d = $d->modify('+1 day'); }
        return $d->format('Y-m-d');
    }

    private function aceptar(string $email): array
    {
        $token = null;
        foreach (array_reverse($this->enviados) as $e) { if ($e['para'] === $email) { $token = $e['token']; break; } }
        $this->assertNotNull($token, 'La invitación debía enviarse');
        return $this->svc()->aceptar($token, ['acepto' => '1', 'password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026'], '198.51.100.71');
    }

    // ------------------------------------------------------------------ Validador puro

    /** @return array<string,array{0:mixed,1:mixed,2:string}> */
    public static function casosInvalidos(): array
    {
        $f = static fn(string $i, string $fin, int $dia = 1): array => [$dia => [['inicio' => $i, 'fin' => $fin]]];
        return [
            'fin antes del inicio' => [$f('12:00', '09:00'), null, 'terminar después'],
            'duración cero' => [$f('09:00', '09:00'), null, 'terminar después'],
            'hora 25:00' => [$f('09:00', '25:00'), null, 'HH:MM'],
            'hora 24:00 no admitida' => [$f('09:00', '24:00'), null, 'HH:MM'],
            'minutos no múltiplos de 5' => [$f('09:03', '12:00'), null, 'múltiplos'],
            'franja menor a 15 minutos' => [$f('09:00', '09:10'), null, 'al menos 15'],
            'día 8' => [$f('09:00', '12:00', 8), null, 'día de la semana'],
            'día 0' => [$f('09:00', '12:00', 0), null, 'día de la semana'],
            'franjas solapadas' => [[1 => [['inicio' => '09:00', 'fin' => '13:00'], ['inicio' => '12:00', 'fin' => '15:00']]], null, 'se solapan'],
            'fila a medias' => [[1 => [['inicio' => '09:00', 'fin' => '']]], null, 'HH:MM'],
            'descanso fuera de la franja' => [$f('09:00', '13:00'), [1 => [['inicio' => '13:00', 'fin' => '14:00', 'motivo' => '']]], 'dentro de una franja'],
            'descanso que cubre toda la franja' => [$f('09:00', '13:00'), [1 => [['inicio' => '09:00', 'fin' => '13:00', 'motivo' => '']]], 'no puede cubrir'],
            'descanso sin franja ese día' => [$f('09:00', '13:00'), [2 => [['inicio' => '10:00', 'fin' => '10:30', 'motivo' => '']]], 'no hay franja'],
            'descansos solapados' => [$f('08:00', '18:00'), [1 => [['inicio' => '10:00', 'fin' => '11:00', 'motivo' => ''], ['inicio' => '10:30', 'fin' => '11:30', 'motivo' => '']]], 'se solapan'],
            'estructura no arreglo' => ['basura', null, 'formato'],
            'fila con valores no texto' => [[1 => [['inicio' => ['x'], 'fin' => '12:00']]], null, 'formato'],
            'motivo largo' => [$f('08:00', '18:00'), [1 => [['inicio' => '10:00', 'fin' => '10:30', 'motivo' => str_repeat('a', 121)]]], 'motivo'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casosInvalidos')]
    public function testElValidadorRechazaConfiguracionesInvalidas(mixed $franjas, mixed $descansos, string $fragmento): void
    {
        $r = AgendaSemanalValidador::normalizar($franjas, $descansos);
        $this->assertNotEmpty($r['errores'], 'Debía rechazarse');
        $this->assertStringContainsStringIgnoringCase($fragmento, implode(' | ', $r['errores']));
    }

    public function testElValidadorNormalizaOrdenaIgnoraFilasEnBlancoYAceptaTurnosContiguos(): void
    {
        $r = AgendaSemanalValidador::normalizar([
            '3' => [['inicio' => '', 'fin' => ''], ['inicio' => '14:00', 'fin' => '18:00'], ['inicio' => '09:00:00', 'fin' => '14:00']],
            '1' => [['inicio' => '09:00', 'fin' => '23:59']],
        ], ['1' => [['inicio' => '13:00', 'fin' => '14:00', 'motivo' => '  Almuerzo   largo ']]]);
        $this->assertSame([], $r['errores']);
        $this->assertSame([['dia' => 1, 'inicio' => '09:00', 'fin' => '23:59'], ['dia' => 3, 'inicio' => '09:00', 'fin' => '14:00'], ['dia' => 3, 'inicio' => '14:00', 'fin' => '18:00']], $r['franjas']);
        $this->assertSame('Almuerzo largo', $r['descansos'][0]['motivo']);
        $this->assertSame([], AgendaSemanalValidador::normalizar(null, null)['franjas'], 'Sin horario es válido (la ficha queda sin turnos)');
    }

    // ------------------------------------------------------------------ Invitación con servicios y horario

    public function testInvitarGuardaServiciosFranjasYDescansosYLaFichaNoEsReservableHastaAceptar(): void
    {
        $email = $this->email();
        $r = $this->invitar($email);
        $this->assertSame(ColaboradorService::OK, $r['status'], json_encode($r));
        $ficha = $this->fichaDe($r['colaborador_id']);
        $pid = (int)$ficha['id'];
        $this->assertSame('0', $ficha['activo'], 'La ficha nace inactiva');
        $this->assertSame($this->servicios, array_map('intval', array_column($this->db->query("SELECT servicioId FROM profesionales_servicios WHERE profesionalId=$pid ORDER BY servicioId")->fetch_all(MYSQLI_ASSOC), 'servicioId')));
        $this->assertSame(3, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM descansos_profesionales WHERE profesionalId=$pid AND dia_semana=1 AND hora_inicio='11:00:00' AND hora_fin='11:30:00' AND motivo='Café'"));
        $this->assertSame(2, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid AND dia_semana=1"), 'Varias franjas por día');

        // --- Prueba NEGATIVA: no activo => ni disponibilidad, ni catálogo de reserva, ni reserva.
        $lunes = $this->proximo(1);
        $disp = (new DisponibilidadService())->consultar($pid, $lunes, [$this->servicios[0]]);
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_INACTIVE, $disp['status']);
        $this->assertSame([], $disp['intervalos']);
        $catalogo = (new ProfesionalService())->consultarParaReserva(['servicios' => (string)$this->servicios[0]]);
        $this->assertNotContains($pid, array_column($catalogo['profesionales'], 'id'), 'No aparece entre los profesionales reservables');
        $this->assertNotContains($pid, array_column($catalogo['profesionales_compatibles'], 'id'));
        $cliente = $this->cuenta('cliente');
        $res = (new CitaService())->reservarConProfesional($cliente, ['profesionalId' => $pid, 'fecha' => $lunes, 'hora' => '09:00', 'servicios' => [$this->servicios[0]]]);
        $this->assertNotSame('ok', $res['status'], 'La reserva real rechaza una ficha inactiva');
        $this->assertSame('profesional_inactivo', $res['codigo']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM citas WHERE profesionalId=$pid"));

        // --- Al aceptar se activa con la configuración ya guardada.
        $this->assertSame(ColaboradorService::OK, $this->aceptar($email)['status']);
        $this->assertSame('1', $this->fichaDe($r['colaborador_id'])['activo']);
        $disp = (new DisponibilidadService())->consultar($pid, $lunes, [$this->servicios[0]]);
        $this->assertSame(DisponibilidadService::STATUS_OK, $disp['status']);
        $inicios = array_column($disp['intervalos'], 'inicio');
        $this->assertContains('09:00', $inicios);
        $this->assertNotContains('11:00', $inicios, 'El descanso 11:00–11:30 no es reservable');
        $this->assertNotContains('13:00', $inicios, 'Entre turnos no hay disponibilidad');
        $this->assertContains('15:00', $inicios, 'El segundo turno está disponible');
        $reservada = (new CitaService())->reservarConProfesional($cliente, ['profesionalId' => $pid, 'fecha' => $lunes, 'hora' => '09:00', 'servicios' => [$this->servicios[0]]]);
        $this->assertSame('ok', $reservada['status'], json_encode($reservada));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casosInvalidos')]
    public function testUnaInvitacionConHorarioInvalidoNoCreaNadaNiEnviaCorreo(mixed $franjas, mixed $descansos, string $fragmento): void
    {
        $email = $this->email();
        $profesionales = $this->n('SELECT COUNT(*) n FROM profesionales');
        $r = $this->invitar($email, ['franjas' => $franjas, 'descansos' => $descansos]);
        $this->assertSame(ColaboradorService::INVALID, $r['status']);
        $this->assertStringContainsStringIgnoringCase($fragmento, implode(' | ', $r['errores']));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame($profesionales, $this->n('SELECT COUNT(*) n FROM profesionales'));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
        $this->assertSame([], $this->enviados);
    }

    public function testUnServicioInexistenteRevierteLaInvitacionCompleta(): void
    {
        $email = $this->email();
        $profesionales = $this->n('SELECT COUNT(*) n FROM profesionales');
        $r = $this->invitar($email, ['servicios' => [(string)$this->servicios[0], '999999']]);
        $this->assertSame(ColaboradorService::INVALID, $r['status']);
        $this->assertStringContainsString('ya no existe en el catálogo', $r['errores'][0]);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame($profesionales, $this->n('SELECT COUNT(*) n FROM profesionales'));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='equipo:{$this->owner}' AND scope='colaborador.invitar'"), 'Sin rastro de éxito idempotente');
    }

    public function testFalloAMitadDelHorarioDeshaceColaboradorFichaServiciosHorarioYCorreo(): void
    {
        $email = $this->email();
        $profesionales = $this->n('SELECT COUNT(*) n FROM profesionales');
        $this->db->query("CREATE TRIGGER test_fail_horario_asist BEFORE INSERT ON horarios_profesionales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated failure in horarios_profesionales'");
        $r = $this->invitar($email);
        $this->db->query('DROP TRIGGER test_fail_horario_asist');
        $this->assertSame(ColaboradorService::ERROR, $r['status']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame($profesionales, $this->n('SELECT COUNT(*) n FROM profesionales'), 'No queda ficha huérfana');
        $this->assertSame(0, $this->n('SELECT COUNT(*) n FROM profesionales_servicios ps LEFT JOIN profesionales p ON p.id=ps.profesionalId WHERE p.id IS NULL'));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
        // Reintento con la misma clave tras el fallo: ahora funciona, una sola vez.
        $clave = Idempotencia::nuevaClave();
        $ok = $this->invitar($email, [], $clave);
        $this->assertSame(ColaboradorService::OK, $ok['status']);
    }

    public function testDobleClicConLaMismaClaveNoDuplicaFichaServiciosNiHorarios(): void
    {
        $email = $this->email();
        $clave = Idempotencia::nuevaClave();
        $a = $this->invitar($email, [], $clave);
        $b = $this->invitar($email, [], $clave);
        $this->assertSame(ColaboradorService::OK, $a['status']);
        $this->assertTrue($b['repetida']);
        $this->assertSame($a['profesional_id'], $b['profesional_id']);
        $pid = (int)$a['profesional_id'];
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE colaborador_id={$a['colaborador_id']}"));
        $this->assertSame(2, $this->n("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId=$pid"));
        $this->assertSame(3, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM descansos_profesionales WHERE profesionalId=$pid"));
        // Misma clave con otro horario => conflicto, sin cambios.
        $c = $this->invitar($email, ['franjas' => [3 => [['inicio' => '08:00', 'fin' => '12:00']]], 'descansos' => []], $clave);
        $this->assertSame(ColaboradorService::CONFLICT_KEY, $c['status']);
        $this->assertSame(3, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid"));
    }

    // ------------------------------------------------------------------ Ficha: edición posterior

    private function fichaActiva(): array
    {
        $email = $this->email();
        $r = $this->invitar($email);
        $this->aceptar($email);
        return ['colaborador' => $r['colaborador_id'], 'profesional' => (int)$r['profesional_id'], 'email' => $email];
    }

    private function postAgenda(array $extra = []): array
    {
        return $extra + ['servicios' => [(string)$this->servicios[0]], 'franjas' => [4 => [['inicio' => '08:00', 'fin' => '12:00']]], 'descansos' => []];
    }

    public function testEditarLaFichaReemplazaServiciosHorarioYDescansosDeFormaAtomica(): void
    {
        $f = $this->fichaActiva();
        $r = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda(), Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::OK, $r['status'], json_encode($r));
        $pid = $f['profesional'];
        $this->assertSame([$this->servicios[0]], array_map('intval', array_column($this->db->query("SELECT servicioId FROM profesionales_servicios WHERE profesionalId=$pid")->fetch_all(MYSQLI_ASSOC), 'servicioId')));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid AND dia_semana=4 AND hora_inicio='08:00:00'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM descansos_profesionales WHERE profesionalId=$pid"));
        $this->assertSame('1', $this->fichaDe($f['colaborador'])['activo'], 'Editar no desactiva una ficha activa');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM equipo_auditoria WHERE accion='agenda_profesional' AND actor_id={$this->owner}"));
    }

    public function testElMismoPostConLaMismaClaveEsIdempotenteYConOtroContenidoEsConflicto(): void
    {
        $f = $this->fichaActiva();
        $clave = Idempotencia::nuevaClave();
        $a = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda(), $clave);
        $b = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda(), $clave);
        $this->assertFalse($a['repetida']);
        $this->assertTrue($b['repetida']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM equipo_auditoria WHERE accion='agenda_profesional'"), 'Una sola escritura');
        $c = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda(['servicios' => array_map('strval', $this->servicios)]), $clave);
        $this->assertSame(ColaboradorService::CONFLICT_KEY, $c['status']);
        $sinClave = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda(), null);
        $this->assertSame(ColaboradorService::INVALID, $sinClave['status']);
    }

    public function testSoloQuienTieneRrhhYGestionDeProfesionalesPuedeConfigurar(): void
    {
        $f = $this->fichaActiva();
        $antes = $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId={$f['profesional']}");
        foreach (['gerente', 'recepcion', 'finanzas', 'profesional', 'cliente'] as $rol) {
            $r = $this->svc()->configurarAgenda($this->cuenta($rol), $f['colaborador'], $this->postAgenda(), Idempotencia::nuevaClave());
            $this->assertSame(ColaboradorService::FORBIDDEN, $r['status'], $rol);
        }
        $this->assertSame($antes, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId={$f['profesional']}"));
    }

    public function testUnaConfiguracionInvalidaEnLaFichaNoCambiaNada(): void
    {
        $f = $this->fichaActiva();
        $pid = $f['profesional'];
        $foto = fn() => json_encode([$this->db->query("SELECT dia_semana,hora_inicio,hora_fin FROM horarios_profesionales WHERE profesionalId=$pid ORDER BY 1,2")->fetch_all(),
            $this->db->query("SELECT servicioId FROM profesionales_servicios WHERE profesionalId=$pid ORDER BY 1")->fetch_all(),
            $this->db->query("SELECT dia_semana,hora_inicio,hora_fin FROM descansos_profesionales WHERE profesionalId=$pid")->fetch_all()]);
        $antes = $foto();
        foreach ([
            $this->postAgenda(['franjas' => [1 => [['inicio' => '09:00', 'fin' => '13:00'], ['inicio' => '12:00', 'fin' => '15:00']]]]),
            $this->postAgenda(['servicios' => []]),
            $this->postAgenda(['servicios' => ['999999']]),
            $this->postAgenda(['servicios' => ['abc']]),
        ] as $malo) {
            $r = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $malo, Idempotencia::nuevaClave());
            $this->assertSame(ColaboradorService::INVALID, $r['status']);
            $this->assertSame($antes, $foto(), 'Un rechazo no modifica servicios, horario ni descansos');
        }
    }

    public function testNoSeRetiraUnServicioNiSeAcortaElHorarioQueUnaCitaFuturaNecesita(): void
    {
        $f = $this->fichaActiva();
        $pid = $f['profesional'];
        $cliente = $this->cuenta('cliente');
        $lunes = $this->proximo(1, 4);
        $res = (new CitaService())->reservarConProfesional($cliente, ['profesionalId' => $pid, 'fecha' => $lunes, 'hora' => '09:00', 'servicios' => [$this->servicios[1]]]);
        $this->assertSame('ok', $res['status'], json_encode($res));
        // Quitar el servicio B que la cita necesita.
        $r = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda(['servicios' => [(string)$this->servicios[0]], 'franjas' => [1 => [['inicio' => '09:00', 'fin' => '13:00']]]]), Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::INVALID, $r['status']);
        $this->assertStringContainsString('No se puede retirar un servicio', $r['errores'][0]);
        // Mantener servicios pero eliminar el lunes: la cita ya no cabe.
        $r = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda(['servicios' => array_map('strval', $this->servicios)]), Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::INVALID, $r['status']);
        $this->assertStringContainsString('colisiona con la cita confirmada', $r['errores'][0]);
        $this->assertSame(2, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid AND dia_semana=1"), 'Horario original intacto');
        // Un descanso que pisa la cita (09:00–10:00) tampoco se admite.
        $r = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda([
            'servicios' => array_map('strval', $this->servicios),
            'franjas' => [1 => [['inicio' => '08:00', 'fin' => '18:00']]],
            'descansos' => [1 => [['inicio' => '09:30', 'fin' => '10:00', 'motivo' => '']]],
        ]), Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::INVALID, $r['status']);
        $this->assertStringContainsString('colisiona con la cita confirmada', $r['errores'][0]);
        // Ampliar sí es posible.
        $ok = $this->svc()->configurarAgenda($this->owner, $f['colaborador'], $this->postAgenda([
            'servicios' => array_map('strval', $this->servicios), 'franjas' => [1 => [['inicio' => '08:00', 'fin' => '18:00']]],
        ]), Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::OK, $ok['status'], json_encode($ok));
    }

    public function testUnaFichaInvitadaGuardaLaConfiguracionSinVolverseReservable(): void
    {
        $email = $this->email();
        $r = $this->invitar($email, ['franjas' => [], 'descansos' => []]);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId={$r['profesional_id']}"));
        $cfg = $this->svc()->configurarAgenda($this->owner, $r['colaborador_id'], $this->postAgenda(), Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::OK, $cfg['status']);
        $this->assertSame('invitado', $cfg['estado']);
        $pid = (int)$r['profesional_id'];
        $this->assertSame('0', $this->fichaDe($r['colaborador_id'])['activo'], 'Configurar no activa');
        $disp = (new DisponibilidadService())->consultar($pid, $this->proximo(4), [$this->servicios[0]]);
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_INACTIVE, $disp['status']);
        $this->assertSame(ColaboradorService::OK, $this->aceptar($email)['status']);
        $this->assertSame(DisponibilidadService::STATUS_OK, (new DisponibilidadService())->consultar($pid, $this->proximo(4), [$this->servicios[0]])['status']);
    }

    public function testRevocarLaInvitacionDejaLaFichaInactivaYConservaSuConfiguracionParaReutilizarla(): void
    {
        $email = $this->email();
        $r = $this->invitar($email);
        $pid = (int)$r['profesional_id'];
        $this->assertSame(ColaboradorService::OK, $this->svc()->revocar($this->owner, $r['colaborador_id'])['status']);
        $ficha = $this->db->query("SELECT activo,colaborador_id FROM profesionales WHERE id=$pid")->fetch_assoc();
        $this->assertSame('0', $ficha['activo']);
        $this->assertNull($ficha['colaborador_id']);
        $this->assertSame(3, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid"), 'No se pierde lo configurado');
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_INACTIVE, (new DisponibilidadService())->consultar($pid, $this->proximo(1), [$this->servicios[0]])['status']);
        // Una baja (suspendido) de un profesional activo también retira su ficha de las reservas.
        $f = $this->fichaActiva();
        $this->assertSame(ColaboradorService::OK, $this->svc()->cambiarAcceso($this->owner, $f['colaborador'], 'profesional', 'suspendido')['status']);
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_INACTIVE, (new DisponibilidadService())->consultar($f['profesional'], $this->proximo(1), [$this->servicios[0]])['status']);
        $cat = (new ProfesionalService())->consultarParaReserva([]);
        $this->assertNotContains($f['profesional'], array_column($cat['profesionales'], 'id'));
    }

    // ------------------------------------------------------------------ Fichas heredadas sin cuenta

    private function heredada(): int
    {
        $this->db->query("INSERT INTO profesionales(nombre,activo) VALUES ('AsistProf Heredada',1)");
        $id = (int)$this->db->insert_id;
        $this->db->query("INSERT INTO profesionales_servicios(profesionalId,servicioId) VALUES ($id,{$this->servicios[0]})");
        $this->db->query("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES ($id,5,'09:00:00','17:00:00')");
        return $id;
    }

    public function testInvitarAUnaFichaHeredadaConservaSuConfiguracionSalvoQueSePidaReemplazarla(): void
    {
        $id = $this->heredada();
        $r = $this->invitar($this->email(), ['profesional_id' => (string)$id, 'nombre_profesional' => '']);
        $this->assertSame(ColaboradorService::OK, $r['status'], json_encode($r));
        $this->assertSame((int)$id, (int)$r['profesional_id']);
        $this->assertSame('1', $this->db->query("SELECT activo FROM profesionales WHERE id=$id")->fetch_assoc()['activo'], 'Una ficha heredada conserva su estado');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$id AND dia_semana=5"), 'El horario heredado no se toca');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$id AND dia_semana=1"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId=$id"));
    }

    public function testInvitarAUnaFichaHeredadaPuedeReemplazarServiciosYHorarioSiSePideExpresamente(): void
    {
        $id = $this->heredada();
        $r = $this->invitar($this->email(), ['profesional_id' => (string)$id, 'ajustar_agenda' => '1', 'nombre_profesional' => '']);
        $this->assertSame(ColaboradorService::OK, $r['status'], json_encode($r));
        $this->assertSame(2, $this->n("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId=$id"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$id AND dia_semana=5"));
        $this->assertSame(3, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$id"));
        // Reemplazar exige servicios.
        $sin = $this->invitar($this->email(), ['profesional_id' => (string)$this->heredada(), 'ajustar_agenda' => '1', 'servicios' => [], 'nombre_profesional' => '']);
        $this->assertSame(ColaboradorService::INVALID, $sin['status']);
    }

    // ------------------------------------------------------------------ Capa HTTP (controlador): permisos y CSRF

    private function sesion(int $id): void
    {
        $ctx = (new AuthorizationService($this->db))->contexto($id);
        $_SESSION = ['login' => true, 'id' => $id, 'portal' => 'equipo', 'version_sesion' => (int)$ctx['version_sesion'], 'csrf_token' => 'csrf-asist'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function ejecutar(callable $fn): int
    {
        $status = null;
        http_response_code(200);
        ob_start();
        try { $fn(); } catch (AppTerminationException $e) { $status = $e->getStatusCode(); }
        ob_end_clean();
        return $status ?? (int)http_response_code();
    }

    public function testElControladorExigeCsrfPermisoYClaveYGuardaUnaSolaVez(): void
    {
        $f = $this->fichaActiva();
        $col = $f['colaborador'];
        $post = $this->postAgenda();
        $horarios = fn() => $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId={$f['profesional']}");
        $antes = $horarios();

        // Sin permiso (gerente tiene profesionales.gestionar pero no rh.gestionar; recepción ninguno).
        foreach (['gerente', 'recepcion', 'profesional'] as $rol) {
            $this->sesion($this->cuenta($rol));
            $_GET = ['id' => (string)$col];
            $_POST = ['csrf_token' => 'csrf-asist', 'idem_key' => Idempotencia::nuevaClave()] + $post;
            $this->assertSame(403, $this->ejecutar(fn() => EquipoController::fichaAgenda(new Router())), $rol);
            $this->assertSame($antes, $horarios());
        }

        $this->sesion($this->owner);
        $_GET = ['id' => (string)$col];
        $clave = Idempotencia::nuevaClave();
        // CSRF inválido.
        $_POST = ['csrf_token' => 'otro', 'idem_key' => $clave] + $post;
        $this->assertSame(403, $this->ejecutar(fn() => EquipoController::fichaAgenda(new Router())));
        $this->assertSame($antes, $horarios());
        // Sin clave de operación: se rechaza con mensaje, sin escribir.
        $_POST = ['csrf_token' => 'csrf-asist'] + $post;
        $this->assertSame(422, $this->ejecutar(fn() => EquipoController::fichaAgenda(new Router())));
        $this->assertSame($antes, $horarios());
        // Correcto, y repetido (doble clic): una sola escritura.
        $_POST = ['csrf_token' => 'csrf-asist', 'idem_key' => $clave] + $post;
        $this->assertSame(302, $this->ejecutar(fn() => EquipoController::fichaAgenda(new Router())));
        $this->assertSame('/admin/equipo/ficha?id=' . $col, $GLOBALS['__appsalon_redireccion']);
        $this->assertSame(1, $horarios());
        $this->assertSame(302, $this->ejecutar(fn() => EquipoController::fichaAgenda(new Router())));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM equipo_auditoria WHERE accion='agenda_profesional'"));
        // GET no escribe.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame(405, $this->ejecutar(fn() => EquipoController::fichaAgenda(new Router())));
    }

    private function paginaFicha(int $colaboradorId): string
    {
        $this->sesion($this->owner);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['id' => (string)$colaboradorId];
        ob_start();
        try { EquipoController::ficha(new Router()); } catch (AppTerminationException) {}
        return (string)ob_get_clean();
    }

    public function testLaFichaRenderizaElAsistenteConLoGuardadoSoloParaProfesionales(): void
    {
        $f = $this->fichaActiva();
        $html = $this->paginaFicha($f['colaborador']);
        $this->assertStringContainsString('data-agenda-asistente', $html);
        $this->assertStringContainsString('name="franjas[1][0][inicio]" value="09:00"', $html, 'Muestra la primera franja guardada');
        $this->assertStringContainsString('name="franjas[1][1][inicio]" value="15:00"', $html, 'Muestra la jornada partida');
        $this->assertStringContainsString('name="descansos[1][0][motivo]" value="Café"', $html);
        $this->assertMatchesRegularExpression('/name="servicios\[\]" value="' . $this->servicios[0] . '" checked/', $html);
        $this->assertStringContainsString('action="/admin/equipo/ficha/agenda?id=' . $f['colaborador'] . '"', $html);
        $this->assertStringContainsString('name="idem_key"', $html);
        // Una persona que no es profesional no ve el asistente.
        $recepcion = $this->cuenta('recepcion');
        $idRec = (int)$this->db->query("SELECT id FROM colaboradores WHERE usuario_id=$recepcion")->fetch_assoc()['id'];
        $this->assertStringNotContainsString('data-agenda-asistente', $this->paginaFicha($idRec));
    }

    public function testElErrorDelAsistenteConservaLoEscritoYMuestraElMotivoSinGuardar(): void
    {
        $f = $this->fichaActiva();
        $this->sesion($this->owner);
        $_GET = ['id' => (string)$f['colaborador']];
        $_POST = ['csrf_token' => 'csrf-asist', 'idem_key' => Idempotencia::nuevaClave(), 'servicios' => [(string)$this->servicios[0]],
            'franjas' => [3 => [['inicio' => '09:00', 'fin' => '12:00'], ['inicio' => '11:00', 'fin' => '14:00']]], 'descansos' => []];
        ob_start();
        try { EquipoController::fichaAgenda(new Router()); } catch (AppTerminationException) {}
        $html = (string)ob_get_clean();
        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString('se solapan', $html);
        $this->assertStringContainsString('name="franjas[3][1][inicio]" value="11:00"', $html, 'Conserva lo que escribió el operador');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId={$f['profesional']} AND dia_semana=3"));
    }

    public function testElFormularioDeInvitacionOfreceServiciosHorarioYAjusteDeFichaHeredada(): void
    {
        $this->heredada();
        $this->sesion($this->owner);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try { EquipoController::index(new Router()); } catch (AppTerminationException) {}
        $html = (string)ob_get_clean();
        $this->assertStringContainsString('data-agenda-asistente', $html);
        $this->assertStringContainsString('name="ajustar_agenda"', $html);
        $this->assertStringContainsString('name="franjas[1][0][inicio]"', $html);
        $this->assertStringContainsString('<template data-ag-plantilla="franja">', $html);
        $this->assertStringContainsString('name="idem_key"', $html);
    }

    // ------------------------------------------------------------------ Concurrencia real (procesos PHP)

    private function lanzarAgenda(array $cfg): array
    {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_reasignacion_worker.php', json_encode(['modo' => 'agenda', 'actor' => $this->owner] + $cfg)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("READY\n", fgets($pipes[1]));
        return [$proc, $pipes];
    }

    private function liberarAgenda(array $trabajadores): array
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

    public function testDosProcesosConLaMismaClaveConfiguranLaAgendaUnaSolaVez(): void
    {
        $f = $this->fichaActiva();
        $cfg = ['colab' => $f['colaborador'], 'post' => $this->postAgenda(), 'clave' => Idempotencia::nuevaClave(), 'demoraMs' => 600];
        $res = $this->liberarAgenda([$this->lanzarAgenda($cfg), $this->lanzarAgenda($cfg)]);
        $this->assertSame(['ok', 'ok'], array_column($res, 'status'), json_encode($res));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM equipo_auditoria WHERE accion='agenda_profesional'"), 'Una sola escritura');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId={$f['profesional']}"));
    }

    public function testDosEdicionesSimultaneasDeLaMismaFichaNuncaMezclanHorarios(): void
    {
        $f = $this->fichaActiva();
        $a = ['servicios' => [(string)$this->servicios[0]], 'franjas' => [1 => [['inicio' => '08:00', 'fin' => '12:00']], 2 => [['inicio' => '08:00', 'fin' => '12:00']]], 'descansos' => []];
        $b = ['servicios' => array_map('strval', $this->servicios), 'franjas' => [5 => [['inicio' => '14:00', 'fin' => '20:00']]], 'descansos' => []];
        $res = $this->liberarAgenda([
            $this->lanzarAgenda(['colab' => $f['colaborador'], 'post' => $a, 'clave' => Idempotencia::nuevaClave(), 'demoraMs' => 500]),
            $this->lanzarAgenda(['colab' => $f['colaborador'], 'post' => $b, 'clave' => Idempotencia::nuevaClave(), 'demoraMs' => 500]),
        ]);
        $this->assertSame(['ok', 'ok'], array_column($res, 'status'), json_encode($res));
        $pid = $f['profesional'];
        $dias = array_column($this->db->query("SELECT dia_semana FROM horarios_profesionales WHERE profesionalId=$pid ORDER BY dia_semana")->fetch_all(MYSQLI_ASSOC), 'dia_semana');
        $servicios = $this->n("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId=$pid");
        $this->assertTrue(($dias == [1, 2] && $servicios === 1) || ($dias == [5] && $servicios === 2), 'Gana una edición completa, nunca una mezcla: ' . json_encode([$dias, $servicios]));
    }
}
