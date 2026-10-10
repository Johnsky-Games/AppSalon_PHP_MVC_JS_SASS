<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Classes\RateLimiter;
use Controllers\LoginController;
use Model\ActiveRecord;
use Model\Usuario;
use MVC\Router;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\PersistenceException;
use Repositories\UsuarioRepository;
use Services\AuthService;

class UsuarioAuthIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        self::$db = new mysqli($host, $user, $pass, $name, $port);
        self::$db->set_charset('utf8mb4');
        ActiveRecord::setDB(self::$db);
    }

    protected function setUp(): void
    {
        validar_base_datos_prueba(self::$db);

        self::$db->query("DROP TRIGGER IF EXISTS test_fail_usuarios_insert");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_usuarios_update");
        self::$db->query("DELETE FROM citasservicios");
        self::$db->query("DELETE FROM citas");
        self::$db->query("DELETE FROM usuarios");
        self::$db->query("DELETE FROM servicios");
        self::$db->query("DELETE FROM intentos_login");

        Email::setTransport(function (): bool {
            return true;
        });
        Email::limpiarEmailsEnviados();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.25';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        LoginController::setAuthService(null);
        ActiveRecord::setDB(self::$db);
        Usuario::limpiarAlertas();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_usuarios_insert");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_usuarios_update");
        LoginController::setAuthService(null);
        ActiveRecord::setDB(self::$db);
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
    }

    public function testCicloCompletoRegistroConfirmacionLoginYRecuperacionConMySqlReal(): void
    {
        $repo = new UsuarioRepository(self::$db);
        $service = new AuthService($repo);

        // 1. Registro intentando manipular id, admin, confirmado y campos de token
        $reg = $service->registrar([
            'id' => '9999',
            'nombre' => 'Valeria',
            'apellido' => 'Mora',
            'telefono' => '3001234567',
            'email' => 'valeria@correo.com',
            'password' => 'claveSegura123',
            'admin' => '1',
            'confirmado' => '1',
            'token' => 'forzado',
            'token_hash' => 'forzado_hash'
        ]);

        $this->assertSame(AuthService::STATUS_OK, $reg['status']);
        $this->assertTrue($reg['resultado']);
        $this->assertNotSame(9999, $reg['id']);
        $this->assertCount(1, Email::$emailsEnviados);
        $tokenConfirmacion = Email::$emailsEnviados[0]['token'];

        $enBd = $repo->findById($reg['id']);
        $this->assertNotNull($enBd);
        $this->assertSame('0', (string)$enBd->admin);
        $this->assertSame('0', (string)$enBd->confirmado);
        $this->assertSame('', (string)$enBd->token);
        $this->assertSame(hash('sha256', $tokenConfirmacion), $enBd->token_hash);

        // 2. Intento de login antes de confirmar la cuenta -> STATUS_UNAUTHORIZED
        $loginAntes = $service->login([
            'email' => 'valeria@correo.com',
            'password' => 'claveSegura123'
        ], '198.51.100.25');
        $this->assertSame(AuthService::STATUS_UNAUTHORIZED, $loginAntes['status']);

        // 3. Confirmar cuenta con el token emitido
        $conf = $service->confirmarCuenta($tokenConfirmacion);
        $this->assertSame(AuthService::STATUS_OK, $conf['status']);

        // 4. Login exitoso tras confirmar
        $loginOk = $service->login([
            'email' => 'valeria@correo.com',
            'password' => 'claveSegura123'
        ], '198.51.100.25');
        $this->assertSame(AuthService::STATUS_OK, $loginOk['status']);
        $this->assertSame((string)$reg['id'], (string)$loginOk['usuario']->id);

        // 5. Solicitar recuperación de contraseña y restablecerla
        Email::limpiarEmailsEnviados();
        $olvide = $service->solicitarRecuperacion(['email' => 'valeria@correo.com'], '198.51.100.25');
        $this->assertSame(AuthService::STATUS_OK, $olvide['status']);
        $this->assertCount(1, Email::$emailsEnviados);
        $tokenRecuperacion = Email::$emailsEnviados[0]['token'];

        $reset = $service->restablecerPassword($tokenRecuperacion, [
            'password' => 'nuevaClaveValeria456'
        ]);
        $this->assertSame(AuthService::STATUS_OK, $reset['status']);

        // 6. La clave anterior ya no autentica y la nueva sí autentica
        $loginVieja = $service->login([
            'email' => 'valeria@correo.com',
            'password' => 'claveSegura123'
        ], '198.51.100.25');
        $this->assertSame(AuthService::STATUS_UNAUTHORIZED, $loginVieja['status']);

        $loginNueva = $service->login([
            'email' => 'valeria@correo.com',
            'password' => 'nuevaClaveValeria456'
        ], '198.51.100.25');
        $this->assertSame(AuthService::STATUS_OK, $loginNueva['status']);
    }

    public function testFallosDePersistenciaEnControladorYServicioDevuelvenHttp500SinEnviarCorreoNiExponerSql(): void
    {
        $router = new Router();
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;

        // 1. Fallo SQL en INSERT durante registro (/crear-cuenta)
        self::$db->query(
            "CREATE TRIGGER test_fail_usuarios_insert BEFORE INSERT ON usuarios
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Detalle SQL interno secreto en insert usuarios'"
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $csrf,
            'nombre' => 'Mario',
            'apellido' => 'Silva',
            'telefono' => '3109876543',
            'email' => 'mario@correo.com',
            'password' => 'clave1234'
        ];

        ob_start();
        LoginController::crear($router);
        $htmlCrear = ob_get_clean();

        $this->assertSame(500, http_response_code(), 'Fallo SQL en crear cuenta debe responder HTTP 500');
        $this->assertEmpty(Email::$emailsEnviados, 'No debe enviarse correo de confirmación si el INSERT falló');
        $alertasCrear = Usuario::getAlertas();
        $this->assertNotEmpty($alertasCrear['error'] ?? []);
        $this->assertStringContainsString('Hubo un error al procesar el registro', $alertasCrear['error'][0]);
        $this->assertStringNotContainsString('Detalle SQL interno secreto', $htmlCrear);
        $this->assertStringNotContainsString('Detalle SQL interno secreto', json_encode($alertasCrear));

        self::$db->query("DROP TRIGGER IF EXISTS test_fail_usuarios_insert");

        // 2. Fallo SQL en UPDATE durante reenvío de confirmación (/reenviar-confirmacion)
        $repo = new UsuarioRepository(self::$db);
        $noConfirmado = new Usuario([
            'nombre' => 'Pendiente',
            'apellido' => 'User',
            'email' => 'pendiente@correo.com',
            'password' => password_hash('clave1234', PASSWORD_BCRYPT),
            'telefono' => '3101112233',
            'confirmado' => '0'
        ]);
        $repo->create($noConfirmado);

        self::$db->query(
            "CREATE TRIGGER test_fail_usuarios_update BEFORE UPDATE ON usuarios
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Detalle SQL interno secreto en update usuarios'"
        );

        http_response_code(200);
        Email::limpiarEmailsEnviados();
        $_POST = [
            'csrf_token' => $csrf,
            'email' => 'pendiente@correo.com'
        ];

        ob_start();
        LoginController::reenviarConfirmacion($router);
        $htmlReenviar = ob_get_clean();

        $this->assertSame(500, http_response_code(), 'Fallo SQL al emitir token de reenvío debe devolver HTTP 500');
        $this->assertEmpty(Email::$emailsEnviados, 'No debe enviarse correo si falló la persistencia del nuevo token');
        $this->assertStringContainsString('No fue posible procesar la solicitud', $htmlReenviar);
        $this->assertStringNotContainsString('Detalle SQL interno secreto', $htmlReenviar);

        // 3. Fallo de persistencia al consultar usuario en LoginController::login devuelve HTTP 500 sin exponer errores SQL
        $repoFalloLectura = new class(self::$db) extends UsuarioRepository {
            public function findByEmail(string $email): ?Usuario
            {
                throw new PersistenceException("SQLSTATE[HY000]: Fallo interno secreto en SELECT usuarios");
            }
        };

        LoginController::setAuthService(new AuthService($repoFalloLectura));
        http_response_code(200);
        $_POST = [
            'csrf_token' => $csrf,
            'email' => 'pendiente@correo.com',
            'password' => 'clave1234'
        ];

        ob_start();
        LoginController::login($router);
        $htmlLoginError = ob_get_clean();

        $this->assertSame(500, http_response_code(), 'Fallo de persistencia en login debe devolver HTTP 500');
        $this->assertStringContainsString('No fue posible procesar el inicio de sesión', $htmlLoginError);
        $this->assertStringNotContainsString('SQLSTATE', $htmlLoginError);
    }

    public function testRepositorioLanzaPersistenceExceptionEnOperacionesConConexionCerrada(): void
    {
        $closedDb = new mysqli(
            getenv('DB_HOST') ?: 'appsalon-test-db',
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASS') ?: 'root',
            getenv('DB_NAME') ?: 'appsalon_test',
            (int)(getenv('DB_PORT') ?: 3306)
        );
        $closedDb->close();

        $repo = new UsuarioRepository($closedDb);

        $this->expectException(PersistenceException::class);
        $repo->existsByEmail('test@correo.com');
    }
}
