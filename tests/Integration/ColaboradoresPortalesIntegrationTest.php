<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Classes\Idempotencia;
use Controllers\EquipoController;
use Controllers\InvitacionController;
use Controllers\LoginController;
use Model\ActiveRecord;
use Model\Usuario;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Repositories\UsuarioRepository;
use Services\AuthorizationService;
use Services\AuthService;
use Services\ColaboradorService;
use Services\CorreoSalidaService;

/**
 * Colaboradores, invitaciones formales y portales separados de clientes y equipo.
 */
final class ColaboradoresPortalesIntegrationTest extends TestCase
{
    private const SUF = '@colab-portal.test';
    private const PASS = 'ClaveSegura123';
    private \mysqli $db;
    private int $owner;
    private array $enviados = [];

    protected function setUp(): void
    {
        // Estas pruebas verifican la entrega en línea tras el commit (camino CORREOS_ENVIO_INMEDIATO=1); el valor por defecto
        // (solo cola + worker) se prueba en ColaSinEnvioEnLineaIntegrationTest y en la suite Qa.
        $_ENV['CORREOS_ENVIO_INMEDIATO'] = '1'; putenv('CORREOS_ENVIO_INMEDIATO=1');
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->limpiar();
        $this->enviados = [];
        Email::setTransport(function ($mailer, string $proposito, array $datos): bool {
            $this->enviados[] = ['proposito' => $proposito, 'para' => $datos['destinatario'], 'token' => $datos['token'], 'enlace' => $datos['enlace']];
            return true;
        });
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.51';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        LoginController::setAuthService(null);
        Usuario::limpiarAlertas();
        http_response_code(200);
        unset($GLOBALS['__appsalon_redireccion']);
        $this->owner = $this->cuenta('propietario');
    }

    protected function tearDown(): void
    {
        unset($_ENV['CORREOS_ENVIO_INMEDIATO']); putenv('CORREOS_ENVIO_INMEDIATO');
        if ($this->db->ping()) {
            $this->db->query('DROP TRIGGER IF EXISTS test_fail_invitacion');
            $this->limpiar();
        }
        ActiveRecord::setDB($this->db);
        LoginController::setAuthService(null);
        Email::setTransport(null);
        $_SESSION = []; $_POST = []; $_GET = [];
        http_response_code(200);
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM profesionales WHERE nombre LIKE 'ColabPortal %'");
        $this->db->query("DELETE FROM colaboradores WHERE email_norm LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM servicios WHERE nombre='Servicio ColabPortal'");
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE actor LIKE 'equipo:%'");
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

    /** Cuenta con acceso laboral heredado (sin invitación) para usar como actor. */
    private function cuenta(string $rol, bool $confirmada = true): int
    {
        $email = $this->email($rol);
        $hash = password_hash(self::PASS, PASSWORD_BCRYPT);
        $conf = (int)$confirmada;
        $admin = in_array($rol, ['propietario', 'gerente'], true) ? 1 : 0;
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('" . ucfirst($rol) . "','Pruebas','$email','$hash','0991234567',$admin,$conf)");
        $id = (int)$this->db->insert_id;
        if ($rol !== 'cliente') {
            $this->db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ($id,'$rol',1)");
            $this->db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,codigo) VALUES ($id,'$email','" . ucfirst($rol) . "','Pruebas','$rol','activo',UTC_TIMESTAMP(),CONCAT('T-',$id))");
        }
        return $id;
    }

    private function emailDe(int $id): string
    {
        return (string)$this->db->query("SELECT email FROM usuarios WHERE id=$id")->fetch_assoc()['email'];
    }

    private function servicios(): ColaboradorService
    {
        return new ColaboradorService(new ColaboradorRepository($this->db), new CorreoSalidaService(new CorreoSalidaRepository($this->db)));
    }

    private function invitar(string $email, string $rol = 'recepcion', array $extra = [], ?string $clave = null, ?int $actor = null): array
    {
        return $this->servicios()->invitar($actor ?? $this->owner, array_merge(['nombre' => 'Laura', 'apellido' => 'Mena', 'email' => $email, 'rol' => $rol, 'puesto' => 'Mostrador'], $extra), $clave ?? Idempotencia::nuevaClave());
    }

    private function tokenEnviado(string $email): string
    {
        foreach (array_reverse($this->enviados) as $e) { if ($e['para'] === $email) { return $e['token']; } }
        $this->fail("No se envió invitación a $email");
    }

    private function aceptar(string $token, array $post): array
    {
        return $this->servicios()->aceptar($token, $post + ['acepto' => '1'], '198.51.100.51');
    }

    private function nuevaAceptacion(string $token): array
    {
        return $this->aceptar($token, ['password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026']);
    }

    // ------------------------------------------------------------------ Invitación

    public function testInvitarCreaColaboradorPendienteSinCuentaNiAccesoYUnSoloEventoDeCorreo(): void
    {
        $email = $this->email();
        $r = $this->invitar($email);
        $this->assertSame(ColaboradorService::OK, $r['status']);
        $this->assertSame('enviado', $r['correo']);
        $this->assertMatchesRegularExpression('/^COL-\d{6}$/', $r['codigo']);
        $fila = $this->db->query("SELECT c.estado, c.usuario_id, c.rol FROM colaboradores c WHERE c.email_norm='$email'")->fetch_assoc();
        $this->assertSame('invitado', $fila['estado']);
        $this->assertNull($fila['usuario_id'], 'No se crea cuenta ni credenciales antes de aceptar');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM usuarios WHERE email='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='$email' AND i.estado='pendiente'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
        $this->assertCount(1, $this->enviados);
        $this->assertSame('invitacion', $this->enviados[0]['proposito']);
        $this->assertStringContainsString('/equipo/aceptar?token=', $this->enviados[0]['enlace']);
    }

    public function testElTokenNoSeGuardaEnClaroNiEnLaCola(): void
    {
        $email = $this->email();
        $this->invitar($email);
        $token = $this->tokenEnviado($email);
        $hash = $this->db->query("SELECT i.token_hash FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='$email'")->fetch_assoc()['token_hash'];
        $this->assertSame(hash('sha256', $token), $hash);
        $cola = $this->db->query("SELECT * FROM correos_salida WHERE destinatario_email='$email'")->fetch_assoc();
        $this->assertNull($cola['carga_cifrada'], 'Tras el envío la carga con el token se borra');
        $this->assertStringNotContainsString($token, json_encode($cola));
    }

    public function testInvitacionPendienteNoConcedePrivilegiosNiPermiteEntrarAlEquipo(): void
    {
        $email = $this->email();
        $this->invitar($email);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM usuario_accesos a JOIN usuarios u ON u.id=a.usuario_id WHERE u.email='$email'"));
        // Un cliente existente invitado: sigue siendo cliente, sin permisos laborales hasta aceptar.
        $cliente = $this->cuenta('cliente');
        $emailCliente = $this->emailDe($cliente);
        $this->invitar($emailCliente, 'gerente');
        $ctx = (new AuthorizationService($this->db))->contexto($cliente);
        $this->assertSame('cliente', $ctx['rol']);
        $this->assertSame('invitado', $ctx['colaborador_estado']);
        $this->assertFalse((new AuthorizationService($this->db))->permite($cliente, 'agenda.gestionar'));
        $r = $this->loginPortal('equipo', $emailCliente);
        $this->assertSame(403, $r['http']);
        $this->assertStringContainsString('invitación al equipo sigue pendiente', $r['salida']);
        $this->assertFalse($_SESSION['login'] ?? false);
    }

    // ------------------------------------------------------------------ Aceptación

    public function testAceptarActivaElAccesoUnaSolaVezYElTokenNoSirveDeNuevo(): void
    {
        $email = $this->email();
        $this->invitar($email, 'finanzas');
        $token = $this->tokenEnviado($email);
        $ok = $this->nuevaAceptacion($token);
        $this->assertSame(ColaboradorService::OK, $ok['status']);
        $fila = $this->db->query("SELECT u.id,u.admin,u.confirmado,a.rol,a.activo,c.estado,c.aceptado_en,c.fecha_ingreso,(SELECT COUNT(*) FROM clientes cl WHERE cl.usuario_id=u.id) cliente FROM usuarios u JOIN usuario_accesos a ON a.usuario_id=u.id JOIN colaboradores c ON c.usuario_id=u.id WHERE u.email='$email'")->fetch_assoc();
        $this->assertSame('finanzas', $fila['rol']);
        $this->assertSame('activo', $fila['estado']);
        $this->assertSame('1', $fila['confirmado']);
        $this->assertSame('0', $fila['admin']);
        $this->assertSame('0', $fila['cliente'], 'Una cuenta solo laboral no se convierte en cliente');
        $this->assertNotNull($fila['aceptado_en']);
        $this->assertNotNull($fila['fecha_ingreso']);
        // Segundo uso (reintento o enlace reenviado por un tercero): rechazado, nada cambia.
        $otra = $this->aceptar($token, ['password' => 'OtraClave2026', 'password_confirmacion' => 'OtraClave2026']);
        $this->assertSame(ColaboradorService::INVALID, $otra['status']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM usuarios WHERE email='$email'"));
        $this->assertTrue(password_verify('NuevaClave2026', $this->db->query("SELECT password FROM usuarios WHERE email='$email'")->fetch_assoc()['password']));
    }

    public function testAceptarSinMarcarLaConfirmacionExplicitaNoConsumeLaInvitacion(): void
    {
        $email = $this->email();
        $this->invitar($email);
        $token = $this->tokenEnviado($email);
        $r = $this->servicios()->aceptar($token, ['password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026'], '198.51.100.51');
        $this->assertSame(ColaboradorService::INVALID, $r['status']);
        $this->assertSame('invitado', $this->db->query("SELECT estado FROM colaboradores WHERE email_norm='$email'")->fetch_assoc()['estado']);
        $this->assertSame(ColaboradorService::OK, $this->nuevaAceptacion($token)['status']);
    }

    public function testInvitacionExpiradaNoOtorgaAccesoYLaSiguienteInvitacionSigueDisponible(): void
    {
        $email = $this->email();
        $this->invitar($email);
        $token = $this->tokenEnviado($email);
        $this->db->query("UPDATE colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id SET i.expira_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE c.email_norm='$email'");
        $this->assertSame('expirada', $this->servicios()->vistaInvitacion($token)['estado']);
        $this->assertSame(ColaboradorService::INVALID, $this->nuevaAceptacion($token)['status']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM usuarios WHERE email='$email'"));
        // Reenviar emite una nueva (la expirada ya no es la pendiente).
        $this->db->query("UPDATE colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id SET i.creada_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE c.email_norm='$email'");
        $id = (int)$this->db->query("SELECT id n FROM colaboradores WHERE email_norm='$email'")->fetch_assoc()['n'];
        $this->assertSame(ColaboradorService::OK, $this->servicios()->reenviar($this->owner, $id, Idempotencia::nuevaClave())['status']);
        $this->assertSame(ColaboradorService::OK, $this->nuevaAceptacion($this->tokenEnviado($email))['status']);
    }

    public function testReenviarRevocaElEnlaceAnteriorLimitaLaFrecuenciaYSoloHayUnaPendiente(): void
    {
        $email = $this->email();
        $r = $this->invitar($email);
        $primero = $this->tokenEnviado($email);
        $id = $r['colaborador_id'];
        $inmediato = $this->servicios()->reenviar($this->owner, $id, Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::FORBIDDEN, $inmediato['status'], 'Máximo un reenvío por minuto');
        $this->db->query("UPDATE colaborador_invitaciones SET creada_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE colaborador_id=$id");
        $this->assertSame(ColaboradorService::OK, $this->servicios()->reenviar($this->owner, $id, Idempotencia::nuevaClave())['status']);
        $segundo = $this->tokenEnviado($email);
        $this->assertNotSame($primero, $segundo);
        $this->assertSame('revocada', $this->servicios()->vistaInvitacion($primero)['estado']);
        $this->assertSame(ColaboradorService::INVALID, $this->nuevaAceptacion($primero)['status']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones WHERE colaborador_id=$id AND estado='pendiente'"));
        // La restricción SQL impide una segunda pendiente aunque se salte la aplicación.
        $stmt = $this->db->prepare("INSERT INTO colaborador_invitaciones(colaborador_id,token_hash,expira_en) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))");
        $hash = hash('sha256', 'otra');
        $stmt->bind_param('is', $id, $hash);
        $this->assertFalse($stmt->execute());
        $this->assertSame(1062, $stmt->errno);
        $stmt->close();
    }

    public function testDobleClicEnReenviarNoEmiteDosInvitaciones(): void
    {
        $email = $this->email();
        $r = $this->invitar($email);
        $id = $r['colaborador_id'];
        $this->db->query("UPDATE colaborador_invitaciones SET creada_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE colaborador_id=$id");
        $clave = Idempotencia::nuevaClave();
        $a = $this->servicios()->reenviar($this->owner, $id, $clave);
        $b = $this->servicios()->reenviar($this->owner, $id, $clave);
        $this->assertSame(ColaboradorService::OK, $a['status']);
        $this->assertSame(ColaboradorService::OK, $b['status']);
        $this->assertTrue($b['repetida']);
        $this->assertSame(2, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones WHERE colaborador_id=$id"));
        $this->assertCount(2, $this->enviados, 'Una por el alta y una por el único reenvío');
    }

    public function testElPropositoDeInvitacionNoSirveComoRecuperacionNiViceversa(): void
    {
        $email = $this->email();
        $this->invitar($email);
        $tokenInvitacion = $this->tokenEnviado($email);
        $auth = new AuthService(new UsuarioRepository($this->db));
        $this->assertNotSame(AuthService::STATUS_OK, $auth->validarTokenRecuperacion($tokenInvitacion)['status']);
        $this->assertNotSame(AuthService::STATUS_OK, $auth->activarCuenta(['token' => $tokenInvitacion, 'password' => 'ClaveSegura123', 'password2' => 'ClaveSegura123', 'nombre' => 'In', 'apellido' => 'Vi', 'telefono' => '0991234567'])['status']);
        // Token de recuperación de un usuario cualquiera no acepta invitaciones.
        $cliente = $this->cuenta('cliente');
        $usuario = (new UsuarioRepository($this->db))->findById($cliente);
        $recuperacion = (new UsuarioRepository($this->db))->issueRecoveryToken($usuario, 2);
        $this->assertSame(ColaboradorService::INVALID, $this->nuevaAceptacion($recuperacion)['status']);
    }

    public function testClienteExistenteConservaSuCuentaYReservasYAceptaConSuContrasenaActual(): void
    {
        $cliente = $this->cuenta('cliente');
        $email = $this->emailDe($cliente);
        $this->db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES ($cliente,'activo','registro_publico')");
        $hashAntes = $this->db->query("SELECT password FROM usuarios WHERE id=$cliente")->fetch_assoc()['password'];
        $this->invitar($email, 'recepcion');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM usuarios WHERE LOWER(email)='" . strtolower($email) . "'"), 'No se duplican credenciales');
        $token = $this->tokenEnviado($email);
        $this->assertSame('existente_confirmada', $this->servicios()->vistaInvitacion($token)['identidad']);
        // Contraseña incorrecta: no consume la invitación ni cambia nada.
        $mal = $this->aceptar($token, ['password_actual' => 'incorrecta']);
        $this->assertSame(ColaboradorService::FORBIDDEN, $mal['status']);
        $this->assertSame('invitado', $this->db->query("SELECT estado FROM colaboradores WHERE email_norm='$email'")->fetch_assoc()['estado']);
        $ok = $this->aceptar($token, ['password_actual' => self::PASS]);
        $this->assertSame(ColaboradorService::OK, $ok['status']);
        $this->assertSame($hashAntes, $this->db->query("SELECT password FROM usuarios WHERE id=$cliente")->fetch_assoc()['password'], 'La contraseña actual no se cambia');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM clientes WHERE usuario_id=$cliente"), 'Su membresía de cliente se conserva');
    }

    public function testPersonaConAmbosPerfilesTieneSesionesYPrivilegiosSeparadosPorPortal(): void
    {
        $persona = $this->cuenta('cliente');
        $email = $this->emailDe($persona);
        $this->db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES ($persona,'activo','registro_publico')");
        $this->invitar($email, 'gerente');
        $this->assertSame(ColaboradorService::OK, $this->aceptar($this->tokenEnviado($email), ['password_actual' => self::PASS])['status']);

        // Portal de clientes: entra como cliente, sin ningún privilegio laboral ni bandera de administrador.
        $r = $this->loginPortal('cliente', $email);
        $this->assertSame(302, $r['status']);
        $this->assertSame('/cita', $GLOBALS['__appsalon_redireccion']);
        $this->assertSame('cliente', $_SESSION['portal']);
        $this->assertSame('cliente', $_SESSION['rol']);
        $this->assertArrayNotHasKey('admin', $_SESSION);
        $this->assertFalse(puede('agenda.gestionar'));
        $this->assertFalse(puede('equipo.gestionar'));
        $this->assertSame('cliente', rol_actual());
        $this->assertSame('/cita', inicio_portal());

        // Portal del equipo: privilegios de gerente y destino laboral.
        $_SESSION = []; unset($GLOBALS['__appsalon_redireccion']);
        $r = $this->loginPortal('equipo', $email);
        $this->assertSame(302, $r['status']);
        $this->assertSame('/admin', $GLOBALS['__appsalon_redireccion']);
        $this->assertSame('equipo', $_SESSION['portal']);
        $this->assertTrue(puede('agenda.gestionar'));
        $this->assertSame('gerente', rol_actual());
        $this->assertSame('/admin', inicio_portal());
    }

    public function testCuentaExistenteSinConfirmarFijaSuContrasenaYInvalidaSesionesPrevias(): void
    {
        $id = $this->cuenta('cliente', false);
        $email = $this->emailDe($id);
        $this->invitar($email, 'recepcion');
        $token = $this->tokenEnviado($email);
        $this->assertSame('existente_sin_confirmar', $this->servicios()->vistaInvitacion($token)['identidad']);
        $versionAntes = (int)$this->db->query("SELECT version_sesion v FROM usuarios WHERE id=$id")->fetch_assoc()['v'];
        $this->assertSame(ColaboradorService::OK, $this->nuevaAceptacion($token)['status']);
        $fila = $this->db->query("SELECT confirmado,version_sesion,password FROM usuarios WHERE id=$id")->fetch_assoc();
        $this->assertSame('1', $fila['confirmado']);
        $this->assertGreaterThan($versionAntes, (int)$fila['version_sesion']);
        $this->assertTrue(password_verify('NuevaClave2026', $fila['password']));
    }

    // ------------------------------------------------------------------ Portales

    private function loginPortal(string $portal, string $email, string $password = self::PASS): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'csrf-colab';
        $_POST = ['csrf_token' => 'csrf-colab', 'email' => $email, 'password' => $password];
        http_response_code(200);
        $res = ['status' => null, 'http' => 200, 'salida' => ''];
        ob_start();
        try { $portal === 'equipo' ? LoginController::loginEquipo(new Router()) : LoginController::loginCliente(new Router()); }
        catch (AppTerminationException $e) { $res['status'] = $e->getStatusCode(); }
        $res['salida'] = (string)ob_get_clean();
        $res['http'] = http_response_code();
        return $res;
    }

    public function testClienteSinMembresiaLaboralNoEntraAlPortalDelEquipo(): void
    {
        $cliente = $this->cuenta('cliente');
        $r = $this->loginPortal('equipo', $this->emailDe($cliente));
        $this->assertSame(403, $r['http']);
        $this->assertStringContainsString('no tiene acceso al equipo', $r['salida']);
        $this->assertFalse($_SESSION['login'] ?? false);
        $this->assertArrayNotHasKey('portal', $_SESSION);
    }

    public function testCuentaSoloLaboralNoEntraAlPortalDeClientesNiConservaPrivilegios(): void
    {
        $rec = $this->cuenta('recepcion');
        $r = $this->loginPortal('cliente', $this->emailDe($rec));
        $this->assertSame(422, $r['http']);
        $this->assertStringContainsString('pertenece al equipo', $r['salida']);
        $this->assertStringContainsString('/equipo/login', $r['salida']);
        $this->assertFalse($_SESSION['login'] ?? false);
    }

    public function testIniciarSesionComoClienteDespuesDeEquipoNoConservaPrivilegiosDeLaSesionAnterior(): void
    {
        $gerente = $this->cuenta('gerente');
        $persona = $this->cuenta('cliente');
        $this->db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES ($persona,'activo','registro_publico')");
        $this->loginPortal('equipo', $this->emailDe($gerente));
        $this->assertSame('1', $_SESSION['admin']);
        $this->loginPortal('cliente', $this->emailDe($persona));
        $this->assertSame('cliente', $_SESSION['portal']);
        $this->assertArrayNotHasKey('admin', $_SESSION);
        $this->assertSame($persona, (int)$_SESSION['id']);
    }

    public function testColaboradorSuspendidoDadoDeBajaOSinAceptarNoObtieneContextoLaboral(): void
    {
        $rec = $this->cuenta('recepcion');
        $svc = new AuthorizationService($this->db);
        $this->assertTrue($svc->permite($rec, 'agenda.gestionar'));
        $id = (int)$this->db->query("SELECT id n FROM colaboradores WHERE usuario_id=$rec")->fetch_assoc()['n'];
        $this->assertSame(ColaboradorService::OK, $this->servicios()->cambiarAcceso($this->owner, $id, 'recepcion', 'suspendido')['status']);
        $this->assertFalse($svc->permite($rec, 'agenda.gestionar'));
        $r = $this->loginPortal('equipo', $this->emailDe($rec));
        $this->assertSame(403, $r['http']);
        $this->assertStringContainsString('suspendido', $r['salida']);
        $this->assertSame(ColaboradorService::OK, $this->servicios()->cambiarAcceso($this->owner, $id, 'recepcion', 'baja')['status']);
        $this->assertFalse($svc->permite($rec, 'agenda.gestionar'));
    }

    public function testCambiarRolORevocaLaSesionAnteriorEnLaSiguienteSolicitud(): void
    {
        $rec = $this->cuenta('recepcion');
        $this->loginPortal('equipo', $this->emailDe($rec));
        $this->assertTrue(puede('agenda.gestionar'));
        $id = (int)$this->db->query("SELECT id n FROM colaboradores WHERE usuario_id=$rec")->fetch_assoc()['n'];
        $this->servicios()->cambiarAcceso($this->owner, $id, 'finanzas', 'activo');
        $this->assertFalse(puede('agenda.gestionar'), 'La sesión previa pierde privilegios');
        $this->assertFalse(puede('finanzas.gestionar'), 'Y debe volver a autenticarse para recibir los nuevos');
    }

    // ------------------------------------------------------------------ Unicidad y duplicados

    public function testMismoCorreoEquivalenteNoCreaOtraFichaNiOtraInvitacion(): void
    {
        $email = $this->email('Mixto');
        $this->assertSame(ColaboradorService::OK, $this->invitar($email)['status']);
        foreach ([strtoupper($email), ' ' . $email . ' ', strtolower($email)] as $variante) {
            $r = $this->invitar($variante);
            $this->assertSame(ColaboradorService::CONFLICT, $r['status'], json_encode($variante));
        }
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='" . strtolower($email) . "'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='" . strtolower($email) . "'"));
        $this->assertCount(1, $this->enviados);
    }

    public function testDobleClicEnInvitarCreaUnSoloColaboradorUnaInvitacionYUnCorreo(): void
    {
        $email = $this->email();
        $clave = Idempotencia::nuevaClave();
        $a = $this->invitar($email, 'recepcion', [], $clave);
        $b = $this->invitar($email, 'recepcion', [], $clave);
        $c = $this->invitar($email, 'recepcion', [], $clave);
        $this->assertFalse($a['repetida'] ?? true);
        $this->assertTrue($b['repetida']);
        $this->assertTrue($c['repetida']);
        $this->assertSame($a['colaborador_id'], $b['colaborador_id']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
        $this->assertCount(1, $this->enviados);
    }

    public function testMismaClaveConDatosDistintosEsConflictoYOtroActorNoRecuperaElResultado(): void
    {
        $email = $this->email();
        $clave = Idempotencia::nuevaClave();
        $this->invitar($email, 'recepcion', [], $clave);
        $conflicto = $this->invitar($email, 'finanzas', [], $clave);
        $this->assertSame(ColaboradorService::CONFLICT_KEY, $conflicto['status']);
        $this->assertSame('recepcion', $this->db->query("SELECT rol FROM colaboradores WHERE email_norm='$email'")->fetch_assoc()['rol']);
        $otroOwner = $this->cuenta('propietario');
        $ajeno = $this->invitar($email, 'recepcion', [], $clave, $otroOwner);
        $this->assertNotSame(ColaboradorService::OK, $ajeno['status'] === ColaboradorService::OK && !empty($ajeno['repetida']) ? 'x' : 'fuga', 'No debe devolver el alta ajena como repetida');
        $this->assertEmpty($ajeno['repetida'] ?? false);
    }

    public function testFalloSmtpDejaLaInvitacionGuardadaReintentableYElReintentoNoLaDuplica(): void
    {
        Email::setTransport(fn() => false);
        $email = $this->email();
        $ajeno = $this->email();
        $this->assertSame(ColaboradorService::OK, $this->invitar($ajeno)['status']); // correo pendiente de otra persona
        $r = $this->invitar($email);
        $this->assertSame(ColaboradorService::OK, $r['status']);
        $this->assertSame('pendiente', $r['correo'], 'La UI no finge que se envió');
        $evento = $this->db->query("SELECT estado,intentos,carga_cifrada IS NOT NULL tiene FROM correos_salida WHERE destinatario_email='$email'")->fetch_assoc();
        $this->assertSame('pendiente', $evento['estado']);
        $this->assertSame('1', $evento['intentos']);
        $this->assertSame('1', $evento['tiene']);
        // Se restablece el SMTP y el worker reintenta cuando toca el siguiente intento.
        Email::setTransport(function ($m, string $p, array $d): bool { $this->enviados[] = ['para' => $d['destinatario'], 'token' => $d['token']]; return true; });
        // La cola es compartida con el resto de la suite: otra invitación pendiente y vencida (de otra prueba, en orden aleatorio) no debe alterar este resultado.
        $this->db->query("UPDATE correos_salida SET proximo_intento=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE destinatario_email IN ('$email','$ajeno')");
        $this->assertGreaterThanOrEqual(1, (new CorreoSalidaService(new CorreoSalidaRepository($this->db)))->procesarPendientes());
        $this->assertSame('enviado', $this->db->query("SELECT estado FROM correos_salida WHERE destinatario_email='$email'")->fetch_assoc()['estado']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='$email'"));
        $this->assertSame(ColaboradorService::OK, $this->nuevaAceptacion($this->tokenEnviado($email))['status']);
    }

    public function testFalloIntermedioRevierteColaboradorFichaEInvitacionSinCorreoNiFilaIdempotente(): void
    {
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES ('Servicio ColabPortal',10.00,30)");
        $servicio = (int)$this->db->insert_id;
        $this->db->query("CREATE TRIGGER test_fail_invitacion BEFORE INSERT ON colaborador_invitaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fallo simulado'");
        $email = $this->email();
        $clave = Idempotencia::nuevaClave();
        $r = $this->invitar($email, 'profesional', ['nombre_profesional' => 'ColabPortal Rollback', 'servicios' => [(string)$servicio]], $clave);
        $this->assertSame(ColaboradorService::ERROR, $r['status']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM profesionales WHERE nombre='ColabPortal Rollback'"), 'Sin ficha huérfana');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='equipo:{$this->owner}'"));
        $this->assertCount(0, $this->enviados, 'Sin correo de éxito falso');
        $this->db->query('DROP TRIGGER test_fail_invitacion');
        $ok = $this->invitar($email, 'profesional', ['nombre_profesional' => 'ColabPortal Rollback', 'servicios' => [(string)$servicio]], $clave);
        $this->assertSame(ColaboradorService::OK, $ok['status'], 'Con la misma clave el reintento ya sin fallo funciona');
    }

    // ------------------------------------------------------------------ Profesionales

    private function servicioId(): int
    {
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES ('Servicio ColabPortal',10.00,30)");
        return (int)$this->db->insert_id;
    }

    public function testAsistenteDeProfesionalCreaColaboradorFichaInactivaYLaActivaAlAceptar(): void
    {
        $servicio = $this->servicioId();
        $email = $this->email();
        $r = $this->invitar($email, 'profesional', ['nombre_profesional' => 'ColabPortal Estilista', 'servicios' => [(string)$servicio]]);
        $this->assertSame(ColaboradorService::OK, $r['status']);
        $ficha = $this->db->query("SELECT p.id,p.activo,p.usuarioId,p.colaborador_id,(SELECT COUNT(*) FROM profesionales_servicios ps WHERE ps.profesionalId=p.id) servicios FROM profesionales p WHERE p.nombre='ColabPortal Estilista'")->fetch_assoc();
        $this->assertSame('0', $ficha['activo'], 'No se agenda hasta que acepte');
        $this->assertNull($ficha['usuarioId']);
        $this->assertSame((string)$r['colaborador_id'], $ficha['colaborador_id']);
        $this->assertSame('1', $ficha['servicios']);
        $this->assertSame(ColaboradorService::OK, $this->nuevaAceptacion($this->tokenEnviado($email))['status']);
        $despues = $this->db->query("SELECT p.activo,p.usuarioId,u.email FROM profesionales p JOIN usuarios u ON u.id=p.usuarioId WHERE p.id={$ficha['id']}")->fetch_assoc();
        $this->assertSame('1', $despues['activo']);
        $this->assertSame($email, $despues['email']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE colaborador_id={$r['colaborador_id']}"));
    }

    public function testProfesionalExigeServiciosYAdvierteHomonimosSinBloquearlos(): void
    {
        $servicio = $this->servicioId();
        $sin = $this->invitar($this->email(), 'profesional', ['servicios' => []]);
        $this->assertSame(ColaboradorService::INVALID, $sin['status']);
        $this->db->query("INSERT INTO profesionales(nombre,activo) VALUES ('ColabPortal Repetida',1)");
        $datos = ['nombre_profesional' => 'colabportal  repetida', 'servicios' => [(string)$servicio]];
        $aviso = $this->invitar($this->email(), 'profesional', $datos);
        $this->assertSame(ColaboradorService::INVALID, $aviso['status']);
        $this->assertTrue($aviso['requiere_homonimo']);
        // La casilla heredada «confirmar_homonimo» ya no basta: hace falta el token del aviso (no forjable).
        $this->assertSame(ColaboradorService::INVALID, $this->invitar($this->email(), 'profesional', $datos + ['confirmar_homonimo' => '1'])['status']);
        $clave = Idempotencia::nuevaClave();
        $primero = $this->invitar($this->email(), 'profesional', $datos, $clave);
        $ok = $this->invitar($this->email(), 'profesional', $datos + ['decision' => 'confirmar', 'token_confirmacion' => $primero['homonimos']['token']], $clave);
        $this->assertSame(ColaboradorService::OK, $ok['status'], 'Los homónimos legítimos no se bloquean');
    }

    public function testInvitacionAsistidaAFichaHeredadaConservaSuHistorialSinCrearOtraFichaNiCorreoFicticio(): void
    {
        $this->db->query("INSERT INTO profesionales(nombre,activo) VALUES ('ColabPortal Heredada',1)");
        $fichaId = (int)$this->db->insert_id;
        $cliente = $this->cuenta('cliente');
        $this->db->query("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado) VALUES (DATE_ADD(CURRENT_DATE, INTERVAL 10 DAY),'10:00:00','10:00:00','10:30:00',30,$cliente,$fichaId,'completada')");
        $citaId = (int)$this->db->insert_id;
        $total = $this->n('SELECT COUNT(*) n FROM profesionales');
        $email = $this->email();
        $r = $this->invitar($email, 'profesional', ['profesional_id' => (string)$fichaId]);
        $this->assertSame(ColaboradorService::OK, $r['status']);
        $this->assertSame($total, $this->n('SELECT COUNT(*) n FROM profesionales'), 'No se crea otra ficha');
        $this->assertSame((string)$r['colaborador_id'], $this->db->query("SELECT colaborador_id FROM profesionales WHERE id=$fichaId")->fetch_assoc()['colaborador_id']);
        $this->assertSame(ColaboradorService::OK, $this->nuevaAceptacion($this->tokenEnviado($email))['status']);
        $this->assertSame((string)$fichaId, $this->db->query("SELECT profesionalId FROM citas WHERE id=$citaId")->fetch_assoc()['profesionalId'], 'Sus reservas siguen en la misma ficha');
        // Una ficha ya vinculada no puede ofrecerse a otra persona.
        $otra = $this->invitar($this->email(), 'profesional', ['profesional_id' => (string)$fichaId]);
        $this->assertSame(ColaboradorService::FORBIDDEN, $otra['status']);
        $this->db->query("DELETE FROM citas WHERE id=$citaId");
    }

    public function testNoSeSuspendeUnProfesionalConCitasFuturasSinResolverlas(): void
    {
        $servicio = $this->servicioId();
        $email = $this->email();
        $r = $this->invitar($email, 'profesional', ['nombre_profesional' => 'ColabPortal Agenda', 'servicios' => [(string)$servicio]]);
        $this->nuevaAceptacion($this->tokenEnviado($email));
        $fichaId = (int)$this->db->query("SELECT id FROM profesionales WHERE colaborador_id={$r['colaborador_id']}")->fetch_assoc()['id'];
        $cliente = $this->cuenta('cliente');
        $this->db->query("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado) VALUES (DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY),'10:00:00','10:00:00','10:30:00',30,$cliente,$fichaId,'confirmada')");
        $citaId = (int)$this->db->insert_id;
        $bloqueo = $this->servicios()->cambiarAcceso($this->owner, $r['colaborador_id'], 'profesional', 'suspendido');
        $this->assertSame(ColaboradorService::FORBIDDEN, $bloqueo['status']);
        $this->assertStringContainsString('1 cita(s) futura(s)', $bloqueo['errores'][0]);
        $this->assertSame('activo', $this->db->query("SELECT estado FROM colaboradores WHERE id={$r['colaborador_id']}")->fetch_assoc()['estado']);
        $operativo = new \Services\ProfesionalService();
        $this->assertSame('invalid', $operativo->cambiarEstado($fichaId, '0')['status'], 'Desactivar desde Profesionales tampoco puede dejar citas futuras sin atención.');
        $this->db->query("UPDATE citas SET estado='cancelada' WHERE id=$citaId");
        $ok = $this->servicios()->cambiarAcceso($this->owner, $r['colaborador_id'], 'profesional', 'suspendido');
        $this->assertSame(ColaboradorService::OK, $ok['status']);
        $this->assertSame('0', $this->db->query("SELECT activo FROM profesionales WHERE id=$fichaId")->fetch_assoc()['activo']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM citas WHERE id=$citaId"), 'El historial se conserva');
        $this->db->query("DELETE FROM citas WHERE id=$citaId");
    }

    public function testElUltimoPropietarioActivoSigueProtegidoDesdeElFlujoDeColaboradores(): void
    {
        $this->db->query("UPDATE usuario_accesos SET activo=0 WHERE rol='propietario' AND usuario_id<>{$this->owner}");
        $this->db->query("UPDATE usuarios SET admin=0 WHERE admin=1 AND id<>{$this->owner}");
        $id = (int)$this->db->query("SELECT id n FROM colaboradores WHERE usuario_id={$this->owner}")->fetch_assoc()['n'];
        $r = $this->servicios()->cambiarAcceso($this->owner, $id, 'propietario', 'suspendido');
        $this->assertSame(ColaboradorService::FORBIDDEN, $r['status']);
        $this->assertStringContainsString('último propietario', $r['errores'][0]);
    }

    // ------------------------------------------------------------------ Autorización y privacidad

    private function sesion(int $id, string $portal = 'equipo'): void
    {
        $ctx = (new AuthorizationService($this->db))->contexto($id);
        $_SESSION = ['login' => true, 'id' => $id, 'portal' => $portal, 'version_sesion' => (int)$ctx['version_sesion'], 'csrf_token' => 'csrf-colab'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf_token' => 'csrf-colab'];
    }

    private function ejecutar(callable $fn): int
    {
        $status = 200;
        ob_start();
        try { $fn(); } catch (AppTerminationException $e) { $status = $e->getStatusCode(); }
        ob_end_clean();
        return $status;
    }

    public function testSoloElPropietarioInvitaYLasDemasSesionesRecibenDenegacionSinEscrituras(): void
    {
        foreach (['recepcion', 'finanzas', 'gerente', 'profesional'] as $rol) {
            $id = $this->cuenta($rol);
            $this->sesion($id);
            $email = $this->email('x');
            $_POST += ['idem_key' => Idempotencia::nuevaClave(), 'nombre' => 'A', 'apellido' => 'B', 'email' => $email, 'rol' => 'recepcion'];
            $this->assertSame(403, $this->ejecutar(fn() => EquipoController::crear()), $rol);
            $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        }
        // Persona con ambos perfiles en el portal de clientes: tampoco, aunque su rol laboral sea propietario.
        $dual = $this->cuenta('propietario');
        $this->db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES ($dual,'activo','registro_publico')");
        $this->sesion($dual, 'cliente');
        $email = $this->email('y');
        $_POST += ['idem_key' => Idempotencia::nuevaClave(), 'nombre' => 'A', 'apellido' => 'B', 'email' => $email, 'rol' => 'recepcion'];
        $this->assertSame(403, $this->ejecutar(fn() => EquipoController::crear()));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
    }

    public function testElPropietarioInvitaPorElControladorYSinClaveOCsrfNoSeCreaNada(): void
    {
        $email = $this->email('ctl');
        $this->sesion($this->owner);
        $post = ['idem_key' => Idempotencia::nuevaClave(), 'nombre' => 'Ana', 'apellido' => 'Control', 'email' => $email, 'rol' => 'recepcion'];
        $_POST = ['csrf_token' => 'incorrecto'] + $post;
        $this->assertSame(403, $this->ejecutar(fn() => EquipoController::crear()));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $_POST = ['csrf_token' => 'csrf-colab'] + array_diff_key($post, ['idem_key' => 1]);
        $this->assertSame(302, $this->ejecutar(fn() => EquipoController::crear()));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"), 'Sin clave de operación no se admite el alta');
        $_POST = ['csrf_token' => 'csrf-colab'] + $post;
        $this->assertSame(302, $this->ejecutar(fn() => EquipoController::crear()));
        $this->assertSame('/admin/equipo', $GLOBALS['__appsalon_redireccion']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame(302, $this->ejecutar(fn() => EquipoController::crear()), 'Reenviar el mismo formulario no duplica');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
    }

    public function testFichaLaboralSoloParaRrhhYSinFiltrarseARecepcionNiFinanzas(): void
    {
        $id = (int)$this->db->query("SELECT id n FROM colaboradores WHERE usuario_id={$this->owner}")->fetch_assoc()['n'];
        foreach (['recepcion', 'finanzas', 'gerente'] as $rol) {
            $this->sesion($this->cuenta($rol));
            $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['id' => (string)$id];
            $this->assertSame(403, $this->ejecutar(fn() => EquipoController::ficha(new Router())), $rol);
        }
        $this->sesion($this->owner);
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = ['id' => (string)$id];
        $_POST = ['csrf_token' => 'csrf-colab', 'puesto' => 'Dirección', 'notas_rh' => 'Nota interna', 'telefono' => '0991234567', 'fecha_ingreso' => '2026-01-15'];
        $this->assertSame(302, $this->ejecutar(fn() => EquipoController::ficha(new Router())));
        $this->assertSame('Nota interna', $this->db->query("SELECT notas_rh FROM colaboradores WHERE id=$id")->fetch_assoc()['notas_rh']);
    }

    public function testCsrfInvalidoEnAceptarNoConsumeLaInvitacion(): void
    {
        $email = $this->email();
        $this->invitar($email);
        $token = $this->tokenEnviado($email);
        $_SESSION = ['csrf_token' => 'csrf-colab'];
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = ['token' => $token];
        $_POST = ['csrf_token' => 'otro', 'acepto' => '1', 'password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026'];
        $this->assertSame(403, $this->ejecutar(fn() => InvitacionController::aceptar(new Router())));
        $this->assertSame('invitado', $this->db->query("SELECT estado FROM colaboradores WHERE email_norm='$email'")->fetch_assoc()['estado']);
        // GET nunca consume.
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
        $this->ejecutar(fn() => InvitacionController::aceptar(new Router()));
        $this->assertSame('invitado', $this->db->query("SELECT estado FROM colaboradores WHERE email_norm='$email'")->fetch_assoc()['estado']);
    }

    public function testElCorreoDeRecuperacionDeUnaCuentaSoloLaboralApuntaAlPortalDelEquipo(): void
    {
        $rec = $this->cuenta('recepcion');
        $email = $this->emailDe($rec);
        $auth = new AuthService(new UsuarioRepository($this->db));
        $this->assertSame(AuthService::STATUS_OK, $auth->solicitarRecuperacion(['email' => $email], '198.51.100.51')['status']);
        $correo = array_values(array_filter($this->enviados, fn($e) => $e['proposito'] === 'recuperacion'))[0] ?? null;
        $this->assertNotNull($correo);
        $this->assertStringContainsString('portal=equipo', $correo['enlace']);
        $cliente = $this->cuenta('cliente');
        $this->enviados = [];
        $auth->solicitarRecuperacion(['email' => $this->emailDe($cliente)], '198.51.100.52');
        $this->assertStringNotContainsString('portal=equipo', $this->enviados[0]['enlace']);
    }

    // ------------------------------------------------------------------ Concurrencia real

    private function lanzar(string $clave, string $email, int $demoraMs): array
    {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_alta_worker.php', 'colaborador', $clave, $email, (string)$demoraMs, 'equipo:' . $this->owner, (string)$this->owner],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_timeout($pipes[1], 15);
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

    public function testDosProcesosConLaMismaClaveInvitanUnaSolaVez(): void
    {
        $email = $this->email('conc');
        $clave = Idempotencia::nuevaClave();
        $res = $this->liberar([$this->lanzar($clave, $email, 500), $this->lanzar($clave, $email, 500)]);
        $this->assertSame([ColaboradorService::OK, ColaboradorService::OK], array_column($res, 'status'));
        $this->assertSame($res[0]['id'], $res[1]['id']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
    }

    public function testDosProcesosConClavesDistintasParaElMismoCorreoSoloCreanUnaFicha(): void
    {
        $email = $this->email('carrera');
        $res = $this->liberar([$this->lanzar(Idempotencia::nuevaClave(), $email, 400), $this->lanzar(Idempotencia::nuevaClave(), $email, 400)]);
        $estados = array_column($res, 'status');
        sort($estados);
        $this->assertSame([ColaboradorService::CONFLICT, ColaboradorService::OK], $estados, json_encode($res));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaboradores WHERE email_norm='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM correos_salida WHERE destinatario_email='$email'"));
    }

    public function testDosAceptacionesSimultaneasDelMismoEnlaceActivanUnaSolaVez(): void
    {
        $email = $this->email('acepta');
        $this->invitar($email);
        $token = $this->tokenEnviado($email);
        $procesos = [];
        $codigo = '<?php require "' . __DIR__ . '/../bootstrap.php"; echo "READY\n"; fgets(STDIN); $s = new Services\ColaboradorService(new Repositories\ColaboradorRepository(Model\ActiveRecord::getDB()), new Services\CorreoSalidaService(new Repositories\CorreoSalidaRepository(Model\ActiveRecord::getDB()))); $r = $s->aceptar($argv[1], ["acepto"=>"1","password"=>"NuevaClave2026","password_confirmacion"=>"NuevaClave2026"], "198.51.100." . $argv[2]); echo json_encode(["status"=>$r["status"]]), "\n";';
        $archivo = sys_get_temp_dir() . '/acepta_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($archivo, $codigo);
        try {
            $pipes = [];
            foreach ([1, 2, 3] as $i) {
                $procesos[$i] = proc_open([PHP_BINARY, $archivo, $token, (string)(60 + $i)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
                stream_set_timeout($pipes[$i][1], 15);
                $this->assertSame("READY\n", fgets($pipes[$i][1]));
            }
            foreach ($pipes as $p) { fwrite($p[0], "GO\n"); fflush($p[0]); fclose($p[0]); }
            $estados = [];
            foreach ($procesos as $i => $proc) { $estados[] = json_decode((string)fgets($pipes[$i][1]), true)['status']; fclose($pipes[$i][1]); fclose($pipes[$i][2]); proc_close($proc); }
        } finally { @unlink($archivo); }
        sort($estados);
        $this->assertSame([ColaboradorService::INVALID, ColaboradorService::INVALID, ColaboradorService::OK], $estados);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM usuarios WHERE email='$email'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM usuario_accesos a JOIN usuarios u ON u.id=a.usuario_id WHERE u.email='$email'"));
    }
    public function testAltaProfesionalHttpCreaLaMismaPersonaEnRhYAgenda(): void
    {
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio ColabPortal',20,30)");
        $sid = (int)$this->db->insert_id;
        $_SESSION = ['id' => $this->owner, 'login' => true, 'portal' => 'equipo', 'version_sesion' => 1, 'csrf_token' => 'csrf-continuidad'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $email = $this->email('alta-prof');
        $_POST = ['csrf_token' => 'csrf-continuidad', 'idem_key' => Idempotencia::nuevaClave(), 'nombre' => 'ColabPortal', 'apellido' => 'Continuidad',
            'email' => $email, 'servicios' => [(string)$sid], 'franjas' => [1 => [['inicio' => '09:00', 'fin' => '18:00']]]];
        foreach ([1, 2] as $intento) {
            ob_start();
            try { \Controllers\ProfesionalController::crear(new Router()); } catch (AppTerminationException $e) { $this->assertSame(302, $e->getStatusCode()); }
            finally { ob_end_clean(); }
        }
        $col = $this->db->query("SELECT * FROM colaboradores WHERE email_norm='$email'")->fetch_assoc();
        $this->assertSame('invitado', $col['estado']);
        $cid = (int)$col['id'];
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE colaborador_id=$cid"));
        $p = $this->db->query("SELECT * FROM profesionales WHERE colaborador_id=$cid")->fetch_assoc();
        $pid = (int)$p['id'];
        $this->assertSame('0', $p['activo']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId=$pid AND servicioId=$sid"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM colaborador_invitaciones WHERE colaborador_id=$cid"));
        $this->assertNull($col['usuario_id'], 'Invitar no concede credenciales.');
        $directorio = (new ColaboradorRepository($this->db))->directorio($email, null, null, 1);
        $this->assertSame($pid, (int)$directorio['filas'][0]['profesional_id']);
    }

    public function testPromocionSuspensionYCambioDeRolConservanLaMismaFicha(): void
    {
        $usuario = $this->cuenta('recepcion');
        $cid = (int)$this->db->query("SELECT id FROM colaboradores WHERE usuario_id=$usuario")->fetch_assoc()['id'];
        $repo = new ColaboradorRepository($this->db);
        $repo->cambiarAcceso($this->owner, $cid, 'profesional', 'activo');
        $p = $this->db->query("SELECT * FROM profesionales WHERE colaborador_id=$cid")->fetch_assoc();
        $pid = (int)$p['id'];
        $this->db->query("UPDATE profesionales SET nombre='ColabPortal Promocion' WHERE id=$pid");
        $this->assertSame($usuario, (int)$p['usuarioId']);
        $this->assertSame('1', $p['activo']);
        $repo->cambiarAcceso($this->owner, $cid, 'profesional', 'suspendido');
        $this->assertSame($usuario, (int)$this->db->query("SELECT usuarioId FROM profesionales WHERE id=$pid")->fetch_assoc()['usuarioId'], 'La suspensión no pierde el vínculo con su cuenta.');
        $servicio = new \Services\ProfesionalService(new \Repositories\ProfesionalRepository($this->db), new \Repositories\ServicioRepository($this->db));
        $this->assertSame('invalid', $servicio->cambiarEstado($pid, '1')['status'], 'Un botón operativo no elude la suspensión laboral.');
        // Una incoherencia antigua en el flag no vuelve reservable a una persona suspendida.
        $this->db->query("UPDATE profesionales SET activo=1 WHERE id=$pid");
        $this->assertFalse((new \Repositories\ProfesionalRepository($this->db))->findById($pid)->estaActivo());
        $reserva = (new \Repositories\CitaRepository($this->db))->crearReservaConProfesionalAtomica($this->owner, $pid, (new \DateTimeImmutable('+10 days'))->format('Y-m-d'), '10:00:00', 1, []);
        $this->assertSame('profesional_inactivo', $reserva['codigo'], 'La escritura de reserva también falla cerrada ante un flag incoherente.');
        $this->assertNotContains($pid, array_map(static fn($p) => (int)$p->id, (new \Repositories\ProfesionalRepository($this->db))->findAll(true)));

        $repo->cambiarAcceso($this->owner, $cid, 'profesional', 'activo');
        $this->assertSame($pid, (int)(new AuthorizationService($this->db))->contexto($usuario)['profesional_id']);
        $repo->cambiarAcceso($this->owner, $cid, 'recepcion', 'activo');
        $this->assertSame('invalid', $servicio->aplicarGrupoSemanal($pid, ['dias' => ['1'], 'hora_inicio' => '09:00', 'hora_fin' => '18:00'], 'horario')['status']);
        $this->assertNull((new AuthorizationService($this->db))->contexto($usuario)['profesional_id']);
        $this->assertSame('invalid', $servicio->cambiarEstado($pid, '1')['status']);
        $repo->cambiarAcceso($this->owner, $cid, 'profesional', 'activo');
        $this->assertSame($pid, (int)(new AuthorizationService($this->db))->contexto($usuario)['profesional_id']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE colaborador_id=$cid"));
    }

    public function testReinvitarUnaInvitacionRevocadaReutilizaFichaSinCrearUnHomonimo(): void
    {
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio ColabPortal',20,30)");
        $sid = (int)$this->db->insert_id;
        $email = $this->email('reinvitar');
        $extra = ['nombre_profesional' => 'ColabPortal Reinvitar', 'servicios' => [(string)$sid]];
        $r = $this->invitar($email, 'profesional', $extra);
        $this->assertSame(ColaboradorService::OK, $r['status']);
        $cid = (int)$r['colaborador_id']; $pid = (int)$r['profesional_id'];
        $this->assertSame(ColaboradorService::OK, $this->servicios()->revocar($this->owner, $cid)['status']);
        $nuevo = $this->invitar($email, 'profesional', $extra);
        $this->assertSame(ColaboradorService::OK, $nuevo['status'], json_encode($nuevo));
        $this->assertSame($cid, (int)$nuevo['colaborador_id']);
        $this->assertSame($pid, (int)$nuevo['profesional_id']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales WHERE colaborador_id=$cid"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId=$pid AND servicioId=$sid"));
    }

    public function testUnaFichaArchivadaEsDeConsultaEnAmbasVistasYNoAdmiteMutacionesDeAgenda(): void
    {
        $sid = $this->servicioId();
        $email = $this->email('archivo');
        $r = $this->invitar($email, 'profesional', ['nombre_profesional' => 'ColabPortal Archivo', 'servicios' => [(string)$sid]]);
        $this->assertSame(ColaboradorService::OK, $this->nuevaAceptacion($this->tokenEnviado($email))['status']);
        $cid = (int)$r['colaborador_id']; $pid = (int)$r['profesional_id'];
        $svc = new \Services\ProfesionalService();
        $horarios = [1 => ['dia_semana' => '1', 'activo' => '1', 'hora_inicio' => '09:00', 'hora_fin' => '18:00']];
        $this->assertSame('ok', $svc->guardarHorariosSemanales($pid, $horarios)['status']);
        $descanso = $svc->crearDescanso($pid, ['dia_semana' => '1', 'hora_inicio' => '13:00', 'hora_fin' => '14:00', 'motivo' => 'Comida']);
        $this->assertSame('ok', $descanso['status']);
        $fecha = (new \DateTimeImmutable('+10 days', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $bloqueo = $svc->crearBloqueo($pid, ['fecha' => $fecha, 'motivo' => 'Permiso']);
        $this->assertSame('ok', $bloqueo['status']);
        $this->assertSame('ok', $this->servicios()->cambiarAcceso($this->owner, $cid, 'profesional', 'baja')['status']);
        $huella = function () use ($pid): string {
            $filas = [];
            foreach (['profesionales' => 'id', 'horarios_profesionales' => 'profesionalId', 'descansos_profesionales' => 'profesionalId', 'bloqueos_profesionales' => 'profesionalId', 'profesionales_servicios' => 'profesionalId'] as $tabla => $campo) {
                $filas[$tabla] = $this->db->query("SELECT * FROM $tabla WHERE $campo=$pid")->fetch_all(MYSQLI_ASSOC);
            }
            return json_encode($filas);
        };
        $antes = $huella();
        $acciones = [
            'ficha' => fn() => $svc->actualizar($pid, ['nombre' => 'Modificada', 'activo' => '0', 'servicios' => [$sid]]),
            'estado' => fn() => $svc->cambiarEstado($pid, '0'),
            'horario' => fn() => $svc->guardarHorariosSemanales($pid, $horarios),
            'grupo' => fn() => $svc->aplicarGrupoSemanal($pid, ['dias' => ['1'], 'hora_inicio' => '09:00', 'hora_fin' => '18:00'], 'horario'),
            'descanso' => fn() => $svc->crearDescanso($pid, ['dia_semana' => '1', 'hora_inicio' => '12:00', 'hora_fin' => '12:30']),
            'quitar_descanso' => fn() => $svc->eliminarDescanso($pid, $descanso['id']),
            'bloqueo' => fn() => $svc->crearBloqueo($pid, ['fecha' => (new \DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d'), 'motivo' => 'Cambio']),
            'quitar_bloqueo' => fn() => $svc->eliminarBloqueo($pid, $bloqueo['id']),
        ];
        foreach ($acciones as $nombre => $accion) {
            $res = $accion();
            $this->assertSame('invalid', $res['status'], $nombre . ': ' . json_encode($res));
            $this->assertSame('estado_laboral', $res['codigo'], $nombre . ': debe rechazarse por la misma regla laboral.');
            $this->assertSame($antes, $huella(), $nombre . ' no debe alterar el archivo');
        }
        $_SESSION = ['id' => $this->owner, 'login' => true, 'portal' => 'equipo', 'version_sesion' => 1];
        $_GET = ['id' => (string)$pid]; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach (['horarios', 'actualizar'] as $accion) {
            ob_start(); \Controllers\ProfesionalController::$accion(new Router()); $html = (string)ob_get_clean();
            $this->assertStringContainsString('consulta', mb_strtolower($html));
            $this->assertStringNotContainsString('name="servicios[]"', $html);
            $this->assertStringNotContainsString('name="accion"', $html);
        }
    }

    /** Segunda conexión real mientras RH mantiene un bloqueo; falla si la agenda toma el candado inverso. */
    private function ejecutarOperacionConcurrente(string $codigo, array $datos): array
    {
        $fuente = 'require ' . var_export(__DIR__ . '/../bootstrap.php', true) . '; $d=json_decode($argv[1],true); ' . $codigo . '; echo json_encode($r), "\n";';
        $p = proc_open([PHP_BINARY, '-r', $fuente, json_encode($datos)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($p);
        try {
            fclose($pipes[0]); stream_set_timeout($pipes[1], 5);
            $linea = fgets($pipes[1]);
            $this->assertNotFalse($linea, 'La agenda no debe bloquear colaboradores mientras RH espera el profesional.');
            $res = json_decode($linea, true);
            $this->assertIsArray($res, (string)$linea);
            return $res;
        } finally {
            proc_terminate($p); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        }
    }

    public function testUnaConfiguracionConcurrenteConLaBajaSeSerializaSinInterbloquearRh(): void
    {
        $sid = $this->servicioId(); $email = $this->email('candado-agenda');
        $r = $this->invitar($email, 'profesional', ['nombre_profesional' => 'ColabPortal Candado Agenda', 'servicios' => [(string)$sid]]);
        $this->assertSame('ok', $this->nuevaAceptacion($this->tokenEnviado($email))['status']);
        $cid = (int)$r['colaborador_id']; $pid = (int)$r['profesional_id'];
        \Classes\Transaccion::ejecutar($this->db, function () use ($cid, $pid): void {
            $this->db->query("SELECT id FROM colaboradores WHERE id=$cid FOR UPDATE");
            $r = $this->ejecutarOperacionConcurrente('$r=(new Services\\ProfesionalService())->aplicarGrupoSemanal($d["pid"],["dias"=>["1"],"hora_inicio"=>"09:00","hora_fin"=>"18:00"],"horario")', ['pid' => $pid]);
            $this->assertSame('ok', $r['status'], json_encode($r));
            (new ColaboradorRepository($this->db))->cambiarAcceso($this->owner, $cid, 'profesional', 'baja');
        });
        $this->assertSame('baja', $this->db->query("SELECT estado FROM colaboradores WHERE id=$cid")->fetch_assoc()['estado']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM horarios_profesionales WHERE profesionalId=$pid"), 'La edición se confirmó antes de la baja, sin perder su configuración.');
        $this->assertSame('invalid', (new \Services\ProfesionalService())->aplicarGrupoSemanal($pid, ['dias' => ['2'], 'hora_inicio' => '09:00', 'hora_fin' => '18:00'], 'horario')['status']);
    }

    public function testLaReservaConcurrenteImpideConfirmarUnaBajaQueDejariaSuCitaSinAtencion(): void
    {
        $sid = $this->servicioId(); $email = $this->email('candado-reserva');
        $r = $this->invitar($email, 'profesional', ['nombre_profesional' => 'ColabPortal Candado Reserva', 'servicios' => [(string)$sid]]);
        $this->assertSame('ok', $this->nuevaAceptacion($this->tokenEnviado($email))['status']);
        $cid = (int)$r['colaborador_id']; $pid = (int)$r['profesional_id']; $cliente = $this->cuenta('cliente');
        $dia = new \DateTimeImmutable('+10 days', new \DateTimeZone('America/Guayaquil')); $dow = (int)$dia->format('N');
        $this->assertSame('ok', (new \Services\ProfesionalService())->guardarHorariosSemanales($pid, [$dow => ['dia_semana' => $dow, 'activo' => '1', 'hora_inicio' => '09:00', 'hora_fin' => '18:00']])['status']);
        $cita = 0;
        try {
            \Classes\Transaccion::ejecutar($this->db, function () use ($cid, $pid, $cliente, $sid, $dia, $dow, &$cita): void {
                $this->db->query("SELECT id FROM colaboradores WHERE id=$cid FOR UPDATE");
                $this->db->query('SELECT COUNT(*) FROM citas')->fetch_row(); // Snapshot previo: la protección debe usar una lectura actual.
                $r = $this->ejecutarOperacionConcurrente('$r=(new Repositories\\CitaRepository())->crearReservaConProfesionalAtomica($d["cliente"],$d["pid"],$d["fecha"],"10:00:00",$d["dow"],[$d["sid"]])', ['cliente' => $cliente, 'pid' => $pid, 'fecha' => $dia->format('Y-m-d'), 'dow' => $dow, 'sid' => $sid]);
                $this->assertSame('ok', $r['status'], json_encode($r)); $cita = (int)$r['id'];
                try { (new ColaboradorRepository($this->db))->cambiarAcceso($this->owner, $cid, 'profesional', 'baja'); $this->fail('La baja debía detectar la reserva confirmada por la otra conexión.'); }
                catch (\DomainException $e) { $this->assertStringContainsString('cita(s) futura(s)', $e->getMessage()); }
            });
            $this->assertSame('activo', $this->db->query("SELECT estado FROM colaboradores WHERE id=$cid")->fetch_assoc()['estado']);
            $this->assertSame('1', $this->db->query("SELECT activo FROM profesionales WHERE id=$pid")->fetch_assoc()['activo']);
        } finally {
            if ($cita) { $this->db->query("DELETE FROM citasservicios WHERE citaId=$cita"); $this->db->query("DELETE FROM citas WHERE id=$cita"); }
        }
    }

}
