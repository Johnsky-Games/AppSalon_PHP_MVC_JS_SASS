<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Idempotencia;
use Classes\Secretos;
use Classes\ServiceUnavailableException;
use Controllers\ProfileController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Services\AuthorizationService;
use Services\ColaboradorService;
use Services\CorreoSalidaService;

/** Regresiones de los defectos hallados por el QA independiente (ver docs/QA_INDEPENDIENTE_IDENTIDADES.md). */
final class CorreccionesQaIntegrationTest extends TestCase
{
    private const SUF = '@correcciones-qa.test';
    private \mysqli $db;
    private int $owner;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->limpiar();
        \Classes\Email::setTransport(fn() => true);
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_ACCEPT'] = 'text/html'; $_SERVER['REQUEST_URI'] = '/';
        $this->owner = $this->cuenta('propietario');
    }

    protected function tearDown(): void
    {
        if ($this->db->ping()) { $this->limpiar(); }
        \Classes\Email::setTransport(null);
        ActiveRecord::setDB($this->db);
        $_SESSION = []; $_POST = []; $_GET = [];
        http_response_code(200);
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM profesionales WHERE nombre LIKE 'CorrQa %'");
        $this->db->query("DELETE FROM colaboradores WHERE email_norm LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM servicios WHERE nombre='Servicio CorrQa'");
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE actor LIKE 'equipo:%'");
        $this->db->query('DELETE FROM intentos_login');
    }

    private function n(string $sql): int { return (int)$this->db->query($sql)->fetch_assoc()['n']; }

    private function cuenta(string $rol): int
    {
        $email = $rol . '-' . bin2hex(random_bytes(4)) . self::SUF;
        $hash = password_hash('ClaveSegura123', PASSWORD_BCRYPT);
        $admin = in_array($rol, ['propietario', 'gerente'], true) ? 1 : 0;
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('" . ucfirst($rol) . "','Qa','$email','$hash','0991234567',$admin,1)");
        $id = (int)$this->db->insert_id;
        if ($rol !== 'cliente') {
            $this->db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ($id,'$rol',1)");
            $this->db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,codigo) VALUES ($id,'$email','" . ucfirst($rol) . "','Qa','$rol','activo',UTC_TIMESTAMP(),CONCAT('Q-',$id))");
        }
        return $id;
    }

    private function emailDe(int $id): string { return (string)$this->db->query("SELECT email FROM usuarios WHERE id=$id")->fetch_assoc()['email']; }

    private function svc(): ColaboradorService
    {
        return new ColaboradorService(new ColaboradorRepository($this->db), new CorreoSalidaService(new CorreoSalidaRepository($this->db)));
    }

    private function servicio(): int
    {
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES ('Servicio CorrQa',10.00,30)");
        return (int)$this->db->insert_id;
    }

    /** Invita a un profesional y lo acepta; devuelve [colaboradorId, usuarioId, fichaId]. */
    private function profesionalAceptado(): array
    {
        $email = 'prof-' . bin2hex(random_bytes(4)) . self::SUF;
        $r = $this->svc()->invitar($this->owner, ['nombre' => 'Pro', 'apellido' => 'Fesional', 'email' => $email, 'rol' => 'profesional', 'nombre_profesional' => 'CorrQa ' . bin2hex(random_bytes(2)), 'servicios' => [(string)$this->servicio()]], Idempotencia::nuevaClave());
        $this->assertSame('ok', $r['status']);
        $token = null;
        \Classes\Email::setTransport(function ($m, $p, $d) use (&$token) { $token = $d['token']; return true; });
        $this->db->query("UPDATE correos_salida SET proximo_intento=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), estado='pendiente' WHERE destinatario_email='$email' AND carga_cifrada IS NOT NULL");
        // El token ya se envió (transporte de setUp) y la carga se borró: se renueva con un reenvío.
        $this->db->query("UPDATE colaborador_invitaciones SET creada_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE colaborador_id={$r['colaborador_id']}");
        $this->assertSame('ok', $this->svc()->reenviar($this->owner, $r['colaborador_id'], Idempotencia::nuevaClave())['status']);
        $this->assertNotNull($token);
        $this->assertSame('ok', $this->svc()->aceptar($token, ['acepto' => '1', 'password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026'], '198.51.100.9')['status']);
        $ficha = $this->db->query("SELECT p.id, p.usuarioId FROM profesionales p WHERE p.colaborador_id={$r['colaborador_id']}")->fetch_assoc();
        return [$r['colaborador_id'], (int)$ficha['usuarioId'], (int)$ficha['id']];
    }

    // DEF-01
    public function testGuardarSuspenderYReactivarUnProfesionalNoRompeSuVinculoConLaCuenta(): void
    {
        [$colab, $usuario, $ficha] = $this->profesionalAceptado();
        $this->assertGreaterThan(0, $usuario);
        $this->assertSame('ok', $this->svc()->cambiarAcceso($this->owner, $colab, 'profesional', 'activo')['status'], 'Guardar sin cambios');
        $this->assertSame((string)$usuario, $this->db->query("SELECT usuarioId FROM profesionales WHERE id=$ficha")->fetch_assoc()['usuarioId']);
        $this->assertSame('ok', $this->svc()->cambiarAcceso($this->owner, $colab, 'profesional', 'suspendido')['status']);
        $this->assertSame('ok', $this->svc()->cambiarAcceso($this->owner, $colab, 'profesional', 'activo')['status']);
        $fila = $this->db->query("SELECT usuarioId,activo FROM profesionales WHERE id=$ficha")->fetch_assoc();
        $this->assertSame((string)$usuario, $fila['usuarioId'], 'La cuenta sigue vinculada a su ficha');
        $this->assertSame('1', $fila['activo'], 'Reactivar vuelve a habilitar la agenda');
        $this->assertSame($ficha, (new AuthorizationService($this->db))->contexto($usuario)['profesional_id'] === null ? 0 : (int)(new AuthorizationService($this->db))->contexto($usuario)['profesional_id']);
        // Dejar de ser profesional sí desvincula.
        $this->assertSame('ok', $this->svc()->cambiarAcceso($this->owner, $colab, 'recepcion', 'activo')['status']);
        $this->assertNull($this->db->query("SELECT usuarioId FROM profesionales WHERE id=$ficha")->fetch_assoc()['usuarioId']);
    }

    // DEF-03 / DEF-04
    public function testCadaMembresiaTieneSuPropioEstadoParaLaVigenciaDeLaSesion(): void
    {
        $persona = $this->cuenta('gerente');
        $this->db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES ($persona,'activo','registro_publico')");
        $authz = new AuthorizationService($this->db);
        $this->assertTrue(AuthorizationService::sesionVigente($authz->contexto($persona), 'cliente'));
        $this->assertTrue(AuthorizationService::sesionVigente($authz->contexto($persona), 'equipo'));
        // Suspender solo el cliente: el equipo sigue; el portal de clientes deja de ser vigente.
        $this->db->query("UPDATE clientes SET estado='suspendido' WHERE usuario_id=$persona");
        $this->assertFalse(AuthorizationService::sesionVigente($authz->contexto($persona), 'cliente'));
        $this->assertTrue(AuthorizationService::sesionVigente($authz->contexto($persona), 'equipo'));
        // Suspender solo lo laboral: el cliente sigue.
        $this->db->query("UPDATE clientes SET estado='activo' WHERE usuario_id=$persona");
        $this->db->query("UPDATE colaboradores SET estado='suspendido' WHERE usuario_id=$persona");
        $this->assertTrue(AuthorizationService::sesionVigente($authz->contexto($persona), 'cliente'));
        $this->assertFalse(AuthorizationService::sesionVigente($authz->contexto($persona), 'equipo'));
        // Cuenta solo laboral: no tiene portal de clientes.
        $solo = $this->cuenta('recepcion');
        $this->assertFalse(AuthorizationService::sesionVigente($authz->contexto($solo), 'cliente'));
    }

    public function testSesionDeClienteSuspendidoConRolLaboralSeInvalidaEnLaSiguientePeticion(): void
    {
        $persona = $this->cuenta('gerente');
        $this->db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES ($persona,'activo','registro_publico')");
        $_SESSION = ['login' => true, 'id' => $persona, 'portal' => 'cliente', 'version_sesion' => (int)(new AuthorizationService($this->db))->contexto($persona)['version_sesion']];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $status = $this->capturar(fn() => isAuth());
        $this->assertSame(200, $status, 'Con la membresía vigente la sesión sirve');
        $this->db->query("UPDATE clientes SET estado='suspendido' WHERE usuario_id=$persona");
        $this->assertSame(401, $this->capturar(fn() => isAuth()));
    }

    private function capturar(callable $f): int
    {
        http_response_code(200);
        ob_start();
        try { $f(); $codigo = 200; } catch (AppTerminationException $e) { $codigo = $e->getStatusCode(); }
        ob_end_clean();
        return $codigo;
    }

    // DEF-07 / DEF-08
    public function testSePuedeReinvitarElMismoCorreoTrasRevocarYLaFichaHeredadaQuedaLibre(): void
    {
        $this->db->query("INSERT INTO profesionales(nombre,activo) VALUES ('CorrQa Heredada',1)");
        $ficha = (int)$this->db->insert_id;
        $email = 'reinvita-' . bin2hex(random_bytes(4)) . self::SUF;
        $base = ['nombre' => 'Rei', 'apellido' => 'Nvita', 'email' => $email, 'rol' => 'profesional', 'profesional_id' => (string)$ficha];
        $a = $this->svc()->invitar($this->owner, $base, Idempotencia::nuevaClave());
        $this->assertSame('ok', $a['status']);
        $this->assertSame('ok', $this->svc()->revocar($this->owner, $a['colaborador_id'])['status']);
        $this->assertNull($this->db->query("SELECT colaborador_id FROM profesionales WHERE id=$ficha")->fetch_assoc()['colaborador_id'], 'La ficha vuelve a estar disponible');
        $b = $this->svc()->invitar($this->owner, ['rol' => 'recepcion'] + $base, Idempotencia::nuevaClave());
        $this->assertSame('ok', $b['status'], json_encode($b));
        $this->assertSame($a['colaborador_id'], $b['colaborador_id'], 'Un correo = una ficha: se reutiliza');
        $fila = $this->db->query("SELECT estado,rol FROM colaboradores WHERE id={$a['colaborador_id']}")->fetch_assoc();
        $this->assertSame(['estado' => 'invitado', 'rol' => 'recepcion'], $fila);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones WHERE colaborador_id={$a['colaborador_id']} AND estado='pendiente'"));
    }

    public function testUnaFichaYaAceptadaYDadaDeBajaNoSeReinvitaComoNueva(): void
    {
        [$colab] = $this->profesionalAceptado();
        $this->db->query("UPDATE citas SET estado='cancelada' WHERE profesionalId IN (SELECT id FROM profesionales WHERE colaborador_id=$colab)");
        $this->assertSame('ok', $this->svc()->cambiarAcceso($this->owner, $colab, 'profesional', 'baja')['status']);
        $email = $this->db->query("SELECT email_norm FROM colaboradores WHERE id=$colab")->fetch_assoc()['email_norm'];
        $r = $this->svc()->invitar($this->owner, ['nombre' => 'X', 'apellido' => 'Y', 'email' => $email, 'rol' => 'recepcion'], Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::CONFLICT, $r['status'], 'Quien ya trabajó se reincorpora cambiando su estado, no con una ficha nueva');
    }

    // DEF-12
    public function testCamposQueNoSonCadenaSeRechazanSinAdvertenciasNiAltas(): void
    {
        foreach (['nombre', 'apellido', 'email', 'telefono', 'puesto', 'fecha_ingreso'] as $campo) {
            $datos = ['nombre' => 'A', 'apellido' => 'B', 'email' => 'arr-' . bin2hex(random_bytes(3)) . self::SUF, 'rol' => 'recepcion'];
            $datos[$campo] = ['x', 'y'];
            $r = $this->svc()->invitar($this->owner, $datos, Idempotencia::nuevaClave());
            $this->assertSame(ColaboradorService::INVALID, $r['status'], $campo);
        }
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm LIKE 'arr-%" . self::SUF . "'"));
    }

    // DEF-15
    public function testNombreYApellidoDeSesentaCaracteresNoImpidenLaInvitacion(): void
    {
        $largo = str_repeat('ñ', 60);
        $email = 'largo-' . bin2hex(random_bytes(3)) . self::SUF;
        $r = $this->svc()->invitar($this->owner, ['nombre' => $largo, 'apellido' => $largo, 'email' => $email, 'rol' => 'recepcion'], Idempotencia::nuevaClave());
        $this->assertSame('ok', $r['status'], json_encode($r));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
    }

    // DEF-09
    public function testLaClaveMaestraSoloSeDerivaEnUnEntornoExplicitoDeDesarrollo(): void
    {
        $previo = [$_ENV['APP_ENV'] ?? null, $_ENV['APP_KEY'] ?? null];
        try {
            unset($_ENV['APP_KEY']); putenv('APP_KEY');
            // Criterio ESTRICTO (Classes\Entorno): solo el valor exacto, en minúsculas y sin espacios, es de desarrollo/pruebas.
            foreach (['', 'staging', 'Production ', 'prod-eu', 'live', ' Local ', 'TESTING', 'Development', 'DEV', "testing\n", 'Test '] as $entorno) {
                $_ENV['APP_ENV'] = $entorno; putenv('APP_ENV=' . $entorno);
                try { Secretos::clave('prueba'); $this->fail("APP_ENV='{$entorno}' no debe derivar la clave"); }
                catch (ServiceUnavailableException) { $this->addToAssertionCount(1); }
            }
            foreach (['development', 'dev', 'local', 'testing', 'test'] as $entorno) {
                $_ENV['APP_ENV'] = $entorno; putenv('APP_ENV=' . $entorno);
                $this->assertSame(32, strlen(Secretos::clave('prueba')));
            }
        } finally {
            $_ENV['APP_ENV'] = $previo[0] ?? 'testing'; putenv('APP_ENV=' . ($previo[0] ?? 'testing'));
            if ($previo[1] !== null) { $_ENV['APP_KEY'] = $previo[1]; }
        }
    }

    // DEF-14
    public function testRecepcionNoEditaElPerfilDelPersonalPeroSiElDeUnCliente(): void
    {
        $recepcion = $this->cuenta('recepcion');
        $dueno = $this->owner;
        $cliente = $this->cuenta('cliente');
        $entrar = function (int $objetivo) use ($recepcion): int {
            $ctx = (new AuthorizationService($this->db))->contexto($recepcion);
            $_SESSION = ['login' => true, 'id' => $recepcion, 'portal' => 'equipo', 'version_sesion' => (int)$ctx['version_sesion'], 'csrf_token' => 'c'];
            $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = ['id' => (string)$objetivo];
            $_POST = ['csrf_token' => 'c', 'nombre' => 'Cambiado', 'apellido' => 'Indebido', 'telefono' => '0990000000'];
            return $this->capturar(function () { ProfileController::index(new Router()); });
        };
        $this->assertSame(403, $entrar($dueno));
        $this->assertNotSame('Cambiado', $this->db->query("SELECT nombre FROM usuarios WHERE id=$dueno")->fetch_assoc()['nombre']);
        $entrar($cliente);
        $this->assertSame('Cambiado', $this->db->query("SELECT nombre FROM usuarios WHERE id=$cliente")->fetch_assoc()['nombre'], 'Recepción sigue atendiendo perfiles de clientes');
    }
}
