<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Classes\ServiceUnavailableException;
use Controllers\LoginController;
use Model\ActiveRecord;
use Model\Usuario;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\UsuarioRepository;
use Services\AuthService;
use Services\AuthorizationService;

/**
 * Acceso del cliente registrado: debe ingresar como cliente tras verificar su cuenta, sin aprobación
 * laboral, y la plataforma debe distinguir una cuenta suspendida de un fallo técnico de autorización.
 */
final class AccesoClienteIntegrationTest extends TestCase
{
    private \mysqli $db;
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->db->query("DROP TRIGGER IF EXISTS test_fail_clientes_insert");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%@acceso-cliente.test'");
        $this->db->query("DELETE FROM intentos_login");
        $this->tokens = [];
        Email::setTransport(function (): bool { return true; });
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        LoginController::setAuthService(null);
        Usuario::limpiarAlertas();
        http_response_code(200);
        unset($GLOBALS['__appsalon_redireccion']);
    }

    protected function tearDown(): void
    {
        if ($this->db->ping()) {
            $this->db->query("DROP TRIGGER IF EXISTS test_fail_clientes_insert");
            $this->db->query("DELETE FROM usuarios WHERE email LIKE '%@acceso-cliente.test'");
        }
        ActiveRecord::setDB($this->db);
        LoginController::setAuthService(null);
        Email::setTransport(null);
        $_SESSION = []; $_POST = []; $_GET = [];
        http_response_code(200);
    }

    private function servicio(): AuthService
    {
        return new AuthService(new UsuarioRepository($this->db), function (string $tipo, string $email, string $nombre, string $token): bool {
            $this->tokens[$email] = $token;
            return true;
        });
    }

    /** Registra y confirma una cuenta pública usando el servicio real y la base real. */
    private function clienteConfirmado(string $local = 'cliente'): string
    {
        $email = $local . '-' . bin2hex(random_bytes(4)) . '@acceso-cliente.test';
        $r = $this->servicio()->registrar(['nombre' => 'Ana', 'apellido' => 'Cliente', 'telefono' => '0991234567', 'email' => $email, 'password' => 'ClaveSegura123'], '198.51.100.77');
        $this->assertSame(AuthService::STATUS_OK, $r['status']);
        $this->assertSame(AuthService::STATUS_OK, $this->servicio()->confirmarCuenta($this->tokens[$email])['status']);
        return $email;
    }

    private function postLogin(string $email, string $password = 'ClaveSegura123'): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'csrf-acceso-cliente';
        $_POST = ['csrf_token' => 'csrf-acceso-cliente', 'email' => $email, 'password' => $password];
        LoginController::setAuthService($this->servicio());
        $resultado = ['status' => null, 'salida' => '', 'excepcion' => null];
        ob_start();
        try {
            LoginController::login(new Router());
        } catch (AppTerminationException $e) {
            $resultado['status'] = $e->getStatusCode();
        } catch (\Throwable $e) {
            $resultado['excepcion'] = $e;
        } finally {
            $resultado['salida'] = (string)ob_get_clean();
        }
        return $resultado;
    }

    public function testClienteRegistradoYConfirmadoIngresaComoClienteSinAprobacionLaboral(): void
    {
        $email = $this->clienteConfirmado();
        $fila = $this->db->query("SELECT u.id,u.admin,u.confirmado,c.estado,c.origen,(SELECT COUNT(*) FROM usuario_accesos a WHERE a.usuario_id=u.id) accesos FROM usuarios u LEFT JOIN clientes c ON c.usuario_id=u.id WHERE u.email='{$email}'")->fetch_assoc();
        $this->assertSame('activo', $fila['estado'], 'El registro público crea la membresía cliente de forma explícita');
        $this->assertSame('registro_publico', $fila['origen']);
        $this->assertSame('0', $fila['admin']);
        $this->assertSame('0', $fila['accesos'], 'No se crea acceso laboral alguno');

        $r = $this->postLogin($email);
        $this->assertNull($r['excepcion'], 'Un login válido no debe fallar técnicamente');
        $this->assertSame(302, $r['status']);
        $this->assertTrue($_SESSION['login']);
        $this->assertSame('cliente', $_SESSION['rol']);
        $this->assertArrayNotHasKey('admin', $_SESSION);
        $this->assertStringNotContainsString('inactiva', $r['salida']);
        $this->assertSame('/cita', $GLOBALS['__appsalon_redireccion'] ?? null);
    }

    public function testRegistroPublicoMaliciosoSoloProduceClienteNuncaEquipo(): void
    {
        $email = 'ataque-' . bin2hex(random_bytes(4)) . '@acceso-cliente.test';
        $r = $this->servicio()->registrar([
            'nombre' => 'Mal', 'apellido' => 'Intento', 'telefono' => '0991234567', 'email' => $email, 'password' => 'ClaveSegura123',
            'admin' => '1', 'rol' => 'propietario', 'activo' => '1', 'colaborador' => 'true', 'confirmado' => '1',
        ], '198.51.100.77');
        $this->assertSame(AuthService::STATUS_OK, $r['status']);
        $fila = $this->db->query("SELECT u.admin,u.confirmado,(SELECT COUNT(*) FROM usuario_accesos a WHERE a.usuario_id=u.id AND a.rol<>'cliente') staff,c.estado FROM usuarios u LEFT JOIN clientes c ON c.usuario_id=u.id WHERE u.email='{$email}'")->fetch_assoc();
        $this->assertSame('0', $fila['admin']);
        $this->assertSame('0', $fila['confirmado'], 'La verificación sólo se obtiene con el token del correo');
        $this->assertSame('0', $fila['staff']);
        $this->assertSame('activo', $fila['estado']);
    }

    public function testClienteSinFilaDeAccesoLegadoSigueEntrando(): void
    {
        // Usuarios previos a la membresía: sin fila en clientes ni en usuario_accesos.
        $email = $this->clienteConfirmado();
        $this->db->query("DELETE FROM clientes WHERE usuario_id=(SELECT id FROM usuarios WHERE email='{$email}')");
        $r = $this->postLogin($email);
        $this->assertSame(302, $r['status']);
        $this->assertSame('cliente', $_SESSION['rol']);
        $fila = $this->db->query("SELECT c.estado FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='{$email}'")->fetch_assoc();
        $this->assertSame('activo', $fila['estado'] ?? null, 'La membresía implícita se materializa al ingresar');
    }

    public function testClienteSuspendidoRecibeEstadoSuspendidoNoUnFalloTecnico(): void
    {
        $email = $this->clienteConfirmado();
        $this->db->query("UPDATE clientes SET estado='suspendido' WHERE usuario_id=(SELECT id FROM usuarios WHERE email='{$email}')");
        $r = $this->postLogin($email);
        $this->assertNull($r['excepcion']);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('suspendida', $r['salida']);
        $this->assertFalse($_SESSION['login'] ?? false);
        $this->assertArrayNotHasKey('rol', $_SESSION);
    }

    public function testCuentaNoVerificadaSigueFlujoDeVerificacionNoDeRolLaboral(): void
    {
        $email = 'pendiente-' . bin2hex(random_bytes(4)) . '@acceso-cliente.test';
        $this->servicio()->registrar(['nombre' => 'Pau', 'apellido' => 'Pendiente', 'telefono' => '0991234567', 'email' => $email, 'password' => 'ClaveSegura123'], '198.51.100.77');
        $r = $this->postLogin($email);
        $this->assertNull($r['excepcion']);
        $this->assertStringContainsString('reenviar-confirmacion', $r['salida']);
        $this->assertStringNotContainsString('administración', $r['salida']);
        $this->assertFalse($_SESSION['login'] ?? false);
    }

    public function testFalloSqlDeAutorizacionResponde503YNoSeDisfrazaDeInactivacion(): void
    {
        $email = $this->clienteConfirmado();
        // Credenciales válidas (consulta de usuarios viva) pero la consulta de autorización falla.
        $roto = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $roto->close();
        ActiveRecord::setDB($roto);
        $r = $this->postLogin($email);
        $this->assertInstanceOf(ServiceUnavailableException::class, $r['excepcion'], 'El fallo técnico se propaga para responder 503 coherente');
        $this->assertStringNotContainsString('inactiva', $r['salida']);
        $this->assertStringNotContainsString('suspendida', $r['salida']);
        $this->assertNotTrue($_SESSION['login'] ?? false, 'Sin contexto verificado no se abre sesión');
    }

    public function testEsquemaDesactualizadoSinMigracion010ProducePeticionNoDisponibleNoInactiva(): void
    {
        // Reproduce el reporte: instalación en 009 (sin version_sesion/usuario_accesos/usuarioId).
        $nombre = 'appsalon_acceso_esquema_viejo_test';
        $this->db->query("DROP DATABASE IF EXISTS {$nombre}");
        $this->db->query("CREATE DATABASE {$nombre} CHARACTER SET utf8mb4");
        $viejo = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', $nombre, (int)(getenv('DB_PORT') ?: 3306));
        try {
            $migraciones = array_filter(glob(__DIR__ . '/../../database/migrations/00*.sql'), fn($f) => !str_ends_with($f, '_rollback.sql'));
            sort($migraciones);
            $archivos = array_merge([__DIR__ . '/../../database/schema_base.sql'], $migraciones);
            foreach ($archivos as $archivo) {
                $viejo->multi_query((string)file_get_contents($archivo));
                do { if ($res = $viejo->store_result()) { $res->free(); } } while ($viejo->more_results() && $viejo->next_result());
                $this->assertSame(0, $viejo->errno, basename($archivo) . ': ' . $viejo->error);
            }
            $hash = password_hash('ClaveSegura123', PASSWORD_BCRYPT);
            $viejo->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Ana','Vieja','viejo@acceso-cliente.test','{$hash}','0991234567',0,1)");
            $id = (int)$viejo->insert_id;
            $this->expectException(ServiceUnavailableException::class);
            (new AuthorizationService($viejo))->contexto($id);
        } finally {
            $viejo->close();
            $this->db->query("DROP DATABASE IF EXISTS {$nombre}");
        }
    }

    public function testFalloAlCrearMembresiaRevierteElRegistroCompleto(): void
    {
        $this->db->query("CREATE TRIGGER test_fail_clientes_insert BEFORE INSERT ON clientes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fallo simulado de membresía'");
        $email = 'rollback-' . bin2hex(random_bytes(4)) . '@acceso-cliente.test';
        $r = $this->servicio()->registrar(['nombre' => 'Ro', 'apellido' => 'Llback', 'telefono' => '0991234567', 'email' => $email, 'password' => 'ClaveSegura123'], '198.51.100.77');
        $this->assertSame(AuthService::STATUS_ERROR, $r['status']);
        $this->assertArrayNotHasKey($email, $this->tokens, 'No se envía correo de éxito si el alta se revirtió');
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'")->fetch_assoc()['n'], 'Sin cuenta huérfana sin membresía');
    }
}
