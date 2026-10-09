<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Model\Usuario;
use Model\Servicio;
use Model\Cita;
use Model\CitaServicio;
use Classes\RateLimiter;
use Controllers\APIController;
use Controllers\LoginController;
use MVC\Router;
use AppTerminationException;
use mysqli;

class SecurityIntegrationTest extends TestCase
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

        // Limpiar cualquier trigger de prueba previo
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_citasservicios");

        // Limpiar datos entre pruebas
        self::$db->query("DELETE FROM citasservicios");
        self::$db->query("DELETE FROM citas");
        self::$db->query("DELETE FROM usuarios");
        self::$db->query("DELETE FROM servicios");
        self::$db->query("DELETE FROM intentos_login");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_citasservicios");
    }

    public function testEntradasMaliciosasSeProcesanComoDatosEnConsultasPreparadas(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'email' => 'ana@correo.com',
            'password' => 'password123',
            'telefono' => '1122334455'
        ]);
        $usuario->hashPassword();
        $usuario->guardar();

        $payloadSQLi = "ana@correo.com' OR '1'='1";
        $encontrado = Usuario::where('email', $payloadSQLi);

        $this->assertNull($encontrado, 'La consulta preparada debe buscar el literal y no ejecutar el OR 1=1');

        $legitimo = Usuario::where('email', 'ana@correo.com');
        $this->assertNotNull($legitimo);
        $this->assertSame('Ana', $legitimo->nombre);
    }

    public function testRegistroEnBaseDeDatosForzaPrivilegiosEnCero(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Hacker',
            'apellido' => 'Test',
            'email' => 'hacker@correo.com',
            'password' => 'secreto123',
            'telefono' => '9988776655',
            'admin' => '1',
            'confirmado' => '1',
            'id' => '1234'
        ]);
        $usuario->hashPassword();
        $resultado = $usuario->guardar();

        $this->assertTrue((bool)$resultado['resultado']);

        $guardado = Usuario::find($resultado['id']);
        $this->assertSame('0', (string)$guardado->admin, 'Admin en BD debe ser 0');
        $this->assertSame('0', (string)$guardado->confirmado, 'Confirmado en BD debe ser 0');
    }

    public function testTokenConfirmacionUsoUnicoYRechazoConcurrente(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Carlos',
            'apellido' => 'Perez',
            'email' => 'carlos@correo.com',
            'password' => 'clave123',
            'telefono' => '5544332211'
        ]);
        $tokenRaw = $usuario->generarTokenSeguro('confirmacion', 24);
        $usuario->guardar();

        // 1. Primer intento de consumo atómico (Solicitud 1) -> Debe ser exitoso
        $exito1 = Usuario::confirmarCuentaPorToken($tokenRaw);
        $this->assertTrue($exito1, 'El primer consumo del token de confirmación debe ser exitoso');

        $usuarioActualizado = Usuario::find($usuario->id);
        $this->assertSame('1', (string)$usuarioActualizado->confirmado);
        $this->assertNull($usuarioActualizado->token_hash, 'El token_hash debe ser null tras consumirse');

        // 2. Intento concurrente o secundario con el mismo token (Solicitud 2) -> Debe fallar
        $exito2 = Usuario::confirmarCuentaPorToken($tokenRaw);
        $this->assertFalse($exito2, 'El segundo intento de consumo debe fallar (0 filas afectadas)');
    }

    public function testTokenPropositoIncorrectoEsRechazado(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Elena',
            'apellido' => 'Rios',
            'email' => 'elena@correo.com',
            'password' => 'clave123',
            'telefono' => '5544332213'
        ]);
        // Token generado para confirmación
        $tokenConfirmacion = $usuario->generarTokenSeguro('confirmacion', 24);
        $usuario->guardar();

        // Intento indebido de usar token de confirmación en el endpoint de recuperación
        $nuevoHash = password_hash('nuevo_password_123', PASSWORD_BCRYPT);
        $resRecuperar = Usuario::restablecerPasswordPorToken($tokenConfirmacion, $nuevoHash);
        $this->assertFalse($resRecuperar, 'Un token de confirmación no debe permitirse para restablecer contraseña');

        $busquedaRecuperacion = Usuario::buscarPorTokenSeguro($tokenConfirmacion, 'recuperacion');
        $this->assertNull($busquedaRecuperacion, 'La búsqueda con propósito incorrecto debe retornar null');
    }

    public function testTokenSustituidoPorUnoNuevoInvalidaElAnterior(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Marcos',
            'apellido' => 'Diaz',
            'email' => 'marcos@correo.com',
            'password' => 'clave123',
            'telefono' => '5544332214'
        ]);
        // Solicitud 1 de recuperación
        $token1 = $usuario->generarTokenSeguro('recuperacion', 2);
        $usuario->guardar();

        // Solicitud 2 de recuperación (el usuario solicita nuevo enlace)
        $token2 = $usuario->generarTokenSeguro('recuperacion', 2);
        $usuario->guardar();

        $hashNuevo = password_hash('password_marcos_final', PASSWORD_BCRYPT);

        // Intento de consumir el enlace viejo (token1)
        $resTokenViejo = Usuario::restablecerPasswordPorToken($token1, $hashNuevo);
        $this->assertFalse($resTokenViejo, 'El enlace anterior sustituido debe ser rechazado');

        // Consumo del enlace vigente (token2)
        $resTokenNuevo = Usuario::restablecerPasswordPorToken($token2, $hashNuevo);
        $this->assertTrue($resTokenNuevo, 'El nuevo token vigente debe consumirse exitosamente');
    }

    public function testExpiracionEntreLecturaYActualizacionRechazaConsumo(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Lucia',
            'apellido' => 'Vega',
            'email' => 'lucia@correo.com',
            'password' => 'clave123',
            'telefono' => '5544332215'
        ]);
        $tokenRaw = $usuario->generarTokenSeguro('recuperacion', 1);
        $usuario->guardar();

        // 1. Paso 1 (GET): El usuario carga el formulario y el token es válido
        $usuarioLeido = Usuario::buscarPorTokenSeguro($tokenRaw, 'recuperacion');
        $this->assertNotNull($usuarioLeido);

        // 2. Simular que el token expira antes del envío del POST
        self::$db->query("UPDATE usuarios SET token_expira = DATE_SUB(NOW(), INTERVAL 5 SECOND) WHERE id = {$usuario->id}");

        // 3. Paso 2 (POST): Se intenta la actualización atómica
        $hashNuevo = password_hash('password_lucia_nuevo', PASSWORD_BCRYPT);
        $resultado = Usuario::restablecerPasswordPorToken($tokenRaw, $hashNuevo);

        $this->assertFalse($resultado, 'La sentencia atómica condicional debe rechazar tokens expirados (0 filas afectadas)');
    }

    public function testFallaAlGuardarServiciosRevierteCitaCompletaEnFlujoRealApi(): void
    {
        // 1. Crear usuario cliente y servicio válido
        $cliente = new Usuario([
            'nombre' => 'Cliente', 'apellido' => 'Rollback', 'email' => 'rollback@correo.com',
            'password' => 'password', 'telefono' => '1234567890', 'confirmado' => '1'
        ]);
        $resCliente = $cliente->guardar();
        $clienteId = (int)$resCliente['id'];

        $servicio = new Servicio(['nombre' => 'Tintura', 'precio' => '120.00']);
        $resServicio = $servicio->guardar();
        $servicioId = (int)$resServicio['id'];

        // 2. Iniciar sesión como el cliente
        $_SESSION['login'] = true;
        $_SESSION['id'] = $clienteId;
        $tokenCsrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $tokenCsrf;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = $tokenCsrf;
        $_POST['fecha'] = date('Y-m-d', strtotime('next Friday'));
        $_POST['hora'] = '14:00';
        $_POST['servicios'] = (string)$servicioId;

        // 3. Crear un trigger MySQL que fuerce un error durante la inserción en citasservicios
        // Esto permite que el insert de citas se complete, pero falle inmediatamente al persistir servicios
        self::$db->query(
            "CREATE TRIGGER test_fail_citasservicios BEFORE INSERT ON citasservicios
             FOR EACH ROW BEGIN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced rollback test error';
             END"
        );

        // 4. Invocar el flujo REAL de APIController::guardar()
        ob_start();
        APIController::guardar();
        $output = ob_get_clean();

        // 5. Comprobar que la respuesta fue HTTP 500 controlada sin fuga de internals
        $this->assertSame(500, http_response_code());
        $json = json_decode($output, true);
        $this->assertFalse($json['resultado']);
        $this->assertSame('No se pudo procesar la reserva. Operación cancelada.', $json['error']);

        // 6. Verificar que la transacción ejecutó ROLLBACK: No existe ninguna cita en la base de datos
        $resCitas = self::$db->query("SELECT COUNT(*) AS total FROM citas WHERE usuarioId = {$clienteId}");
        $filaCitas = $resCitas->fetch_assoc();
        $this->assertSame(0, (int)$filaCitas['total'], 'La transacción debió revertir la cita tras la falla en servicios');

        $resCS = self::$db->query("SELECT COUNT(*) AS total FROM citasservicios");
        $filaCS = $resCS->fetch_assoc();
        $this->assertSame(0, (int)$filaCS['total'], 'No deben existir registros en citasservicios');

        // Limpieza de trigger
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_citasservicios");
    }

    public function testTransicionDeRolAdminAClienteEnMismaSesionRevocaAccesoAdmin(): void
    {
        // 1. Crear Administrador y Cliente
        $admin = new Usuario([
            'nombre' => 'Admin', 'apellido' => 'Boss', 'email' => 'admin@appsalon.com',
            'password' => 'admin123', 'telefono' => '1111111111', 'admin' => '1', 'confirmado' => '1'
        ]);
        $admin->hashPassword();
        $admin->guardar();

        $cliente = new Usuario([
            'nombre' => 'Cliente', 'apellido' => 'Normal', 'email' => 'cliente@appsalon.com',
            'password' => 'cliente123', 'telefono' => '2222222222', 'admin' => '0', 'confirmado' => '1'
        ]);
        $cliente->hashPassword();
        $cliente->guardar();

        $router = new Router();

        // 2. Simular login inicial del Administrador en la sesión
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $tokenCsrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $tokenCsrf;
        $_POST['csrf_token'] = $tokenCsrf;
        $_POST['email'] = 'admin@appsalon.com';
        $_POST['password'] = 'admin123';

        try {
            ob_start();
            LoginController::login($router);
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        $this->assertSame('1', (string)($_SESSION['admin'] ?? '0'));
        $this->assertSame($admin->id, $_SESSION['id']);

        // 3. En la MISMA sesión, se autentica el Cliente
        $tokenCsrf2 = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $tokenCsrf2;
        $_POST['csrf_token'] = $tokenCsrf2;
        $_POST['email'] = 'cliente@appsalon.com';
        $_POST['password'] = 'cliente123';

        try {
            ob_start();
            LoginController::login($router);
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // 4. Verificar que el rol de Administrador fue completamente revocado y la sesión reconstruida
        $this->assertSame($cliente->id, $_SESSION['id']);
        $this->assertArrayNotHasKey('admin', $_SESSION, 'El rol de administrador debe ser eliminado de la sesión');

        // 5. Comprobar que isAdmin() rechaza inmediatamente al cliente
        $this->expectException(AppTerminationException::class);
        isAdmin();
    }

    public function testLogoutExigePostConCsrfYDestruyeSesion(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $_SESSION['csrf_token'] = $token;

        // Petición GET a /logout debe ser rechazada y redirigida
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            LoginController::logout();
            $this->fail('Logout por GET debe terminar con redirección');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        // Petición POST con CSRF inválido debe ser rechazada con 403
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'token_invalido';
        try {
            ob_start();
            LoginController::logout();
            $this->fail('Logout con CSRF inválido debe lanzar excepción');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // Petición POST con CSRF válido ejecuta cierre efectivo
        $_POST['csrf_token'] = $token;
        try {
            LoginController::logout();
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        $this->assertEmpty($_SESSION, 'La sesión debe quedar completamente limpia tras logout');
    }

    public function testRateLimiterVentanaYBloqueo429(): void
    {
        $ip = '203.0.113.195';
        $email = 'ataque@correo.com';

        // 1. Ejecutar 5 intentos fallidos
        for ($i = 1; $i <= 5; $i++) {
            $estado = RateLimiter::registrarIntentoFallido(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $email, 5);
        }

        $this->assertTrue($estado['bloqueado']);
        $this->assertGreaterThan(0, $estado['segundos_restantes']);

        // 2. Simular que la ventana de tiempo de 15 minutos (900 s) ha expirado
        self::$db->query(
            "UPDATE intentos_login 
             SET primera_peticion = DATE_SUB(NOW(), INTERVAL 950 SECOND),
                 bloqueado_hasta = NULL
             WHERE tipo = '" . RateLimiter::TIPO_EMAIL_LOGIN . "' AND identificador = '{$email}'"
        );

        // 3. El siguiente intento debe reiniciar el contador a 1 en una nueva ventana
        $nuevoEstado = RateLimiter::registrarIntentoFallido(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $email, 5);
        $this->assertSame(1, $nuevoEstado['intentos'], 'La ventana expirada debe reiniciar el contador a 1');
        $this->assertFalse($nuevoEstado['bloqueado']);
    }
}
