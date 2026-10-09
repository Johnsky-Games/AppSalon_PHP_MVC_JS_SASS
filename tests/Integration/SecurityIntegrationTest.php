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

        \Classes\Email::setTransport(function(): bool {
            return true;
        });
        \Classes\Email::limpiarEmailsEnviados();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Usuario::limpiarAlertas();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_citasservicios");
        \Classes\Email::setTransport(null);
        \Classes\Email::limpiarEmailsEnviados();
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

    public function testRateLimiterUmbralExactoIntentoPorIntento(): void
    {
        $identificador = 'umbral_exacto@correo.com';
        $maxIntentos = 5;

        // Intentos 1 al 4 deben ser estrictamente permitidos (no bloqueados)
        for ($i = 1; $i <= 4; $i++) {
            $estado = RateLimiter::registrarIntentoFallido(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
            $this->assertFalse($estado['bloqueado'], "El intento {$i} no debe estar bloqueado");
            $this->assertSame($i, $estado['intentos'], "El contador de intentos debe ser exactamente {$i}");
            $this->assertSame(0, $estado['segundos_restantes']);
        }

        // Intento 5 es el umbral exacto: debe bloquearse inmediatamente
        $estado5 = RateLimiter::registrarIntentoFallido(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertTrue($estado5['bloqueado'], "El intento 5 debe alcanzar el umbral y bloquearse");
        $this->assertSame(5, $estado5['intentos']);
        $this->assertGreaterThan(0, $estado5['segundos_restantes']);

        // Intento 6 (subsiguiente) debe mantenerse bloqueado sin inflar contador
        $estado6 = RateLimiter::registrarIntentoFallido(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertTrue($estado6['bloqueado'], "Cualquier intento subsiguiente debe permanecer bloqueado");
        $this->assertSame(5, $estado6['intentos']);
    }

    public function testRateLimiterConexionesIndependientesConcurrencia(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        // Dos conexiones mysqli completamente independientes
        $conn1 = new mysqli($host, $user, $pass, $name, $port);
        $conn2 = new mysqli($host, $user, $pass, $name, $port);

        $identificador = 'concurrente@correo.com';
        $maxIntentos = 5;

        // Conn 1 registra intento 1
        $e1 = RateLimiter::registrarIntentoFallido($conn1, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertSame(1, $e1['intentos']);
        $this->assertFalse($e1['bloqueado']);

        // Conn 2 registra intento 2
        $e2 = RateLimiter::registrarIntentoFallido($conn2, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertSame(2, $e2['intentos']);
        $this->assertFalse($e2['bloqueado']);

        // Conn 1 registra intento 3
        $e3 = RateLimiter::registrarIntentoFallido($conn1, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertSame(3, $e3['intentos']);

        // Conn 2 registra intento 4
        $e4 = RateLimiter::registrarIntentoFallido($conn2, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertSame(4, $e4['intentos']);

        // Conn 1 registra intento 5 -> Umbral alcanzado desde Conn 1
        $e5 = RateLimiter::registrarIntentoFallido($conn1, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertTrue($e5['bloqueado']);

        // Conn 2 consulta e intenta operar: debe estar inmediatamente bloqueada sin saltarse el límite
        $bloqueoConn2 = RateLimiter::obtenerSegundosBloqueo($conn2, RateLimiter::TIPO_EMAIL_LOGIN, $identificador);
        $this->assertGreaterThan(0, $bloqueoConn2, "Conn2 debe percibir el bloqueo establecido por Conn1");

        $e6 = RateLimiter::registrarIntentoFallido($conn2, RateLimiter::TIPO_EMAIL_LOGIN, $identificador, $maxIntentos);
        $this->assertTrue($e6['bloqueado'], "Conn2 debe ser rechazada bajo el mismo bloqueo atómico");

        $conn1->close();
        $conn2->close();
    }

    public function testRateLimiterProcesosSimultaneosSincronizadosFlujoRealLogin(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        $emailTarget = 'concurrente_login@correo.com';
        $ipTarget = '198.51.100.77';

        // 1. Crear usuario en la base de datos
        $usuario = new Usuario([
            'nombre' => 'UserConcurrente',
            'apellido' => 'Test',
            'email' => $emailTarget,
            'password' => 'clave123',
            'telefono' => '1122334455',
            'confirmado' => '1'
        ]);
        $usuario->hashPassword();
        $usuario->guardar();

        // 2. Limpiar registros previos en rate limit
        RateLimiter::limpiarIntentos(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $emailTarget);
        RateLimiter::limpiarIntentos(self::$db, RateLimiter::TIPO_IP_LOGIN, $ipTarget);

        $numProcesos = 10;
        $procesos = [];
        $pipes = [];

        $workerScript = __DIR__ . '/concurrent_login_worker.php';
        $this->assertFileExists($workerScript);

        // 3. Levantar 10 subprocesos simultáneos con sesiones independientes
        for ($i = 0; $i < $numProcesos; $i++) {
            $cmd = "php " . escapeshellarg($workerScript) . " " . escapeshellarg($emailTarget) . " " . escapeshellarg($ipTarget);
            $spec = [
                0 => ["pipe", "r"], // STDIN: barrera sincronizadora
                1 => ["pipe", "w"], // STDOUT
                2 => ["pipe", "w"]  // STDERR
            ];
            $env = array_merge($_ENV, [
                'DB_HOST' => $host,
                'DB_USER' => $user,
                'DB_PASS' => $pass,
                'DB_NAME' => $name,
                'DB_PORT' => (string)$port
            ]);
            $proc = proc_open($cmd, $spec, $procPipes, __DIR__, $env);
            $this->assertIsResource($proc, "No se pudo iniciar el proceso concurrente #{$i}");
            $procesos[$i] = $proc;
            $pipes[$i] = $procPipes;
        }

        // Breve pausa para asegurar que los 10 procesos alcancen la barrera de sincronización en fgets(STDIN)
        usleep(300000); // 300 ms

        // 4. Liberar la barrera para todos los procesos concurrentemente
        for ($i = 0; $i < $numProcesos; $i++) {
            fwrite($pipes[$i][0], "GO\n");
            fflush($pipes[$i][0]);
            fclose($pipes[$i][0]);
        }

        // 5. Recolectar resultados de los 10 procesos
        $admitidos = 0;
        $rechazados429 = 0;

        for ($i = 0; $i < $numProcesos; $i++) {
            $stdout = stream_get_contents($pipes[$i][1]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $exitCode = proc_close($procesos[$i]);

            $this->assertSame(0, $exitCode, "El proceso hijo #{$i} terminó con error inesperado");
            $data = json_decode($stdout, true);
            $this->assertIsArray($data, "El subproceso #{$i} no retornó JSON válido. Salida: {$stdout}");

            if ($data['status'] === 429 || $data['bloqueado'] === true) {
                $rechazados429++;
            } else {
                $admitidos++;
            }
        }

        // 6. Verificar conteo exacto de peticiones admitidas vs rechazadas bajo concurrencia
        // Con MAX_INTENTOS_EMAIL = 5, exactamente 5 peticiones deben ser admitidas antes del bloqueo,
        // y exactamente 5 deben ser rechazadas con HTTP 429 / bloqueado sin llegar a verificación redundante.
        $this->assertSame(5, $admitidos, 'Exactamente 5 peticiones deben ser admitidas por el rate limiter');
        $this->assertSame(5, $rechazados429, 'Exactamente 5 peticiones deben ser rechazadas con 429 bajo concurrencia');
        $this->assertSame(10, $admitidos + $rechazados429, 'El total de procesos admitidos + rechazados debe ser 10');

        // 7. Verificar estado final en MySQL
        $estado = RateLimiter::consultarEstado(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $emailTarget);
        $this->assertTrue($estado['bloqueado'], 'La cuenta debe permanecer en estado bloqueado tras alcanzar el umbral');
        $this->assertSame(5, $estado['intentos'], 'El número de intentos registrados en BD debe ser exactamente 5');
    }

    public function testRateLimiterManejoFalloSqlFailSecure(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        // Conexión cerrada para simular indisponibilidad o fallo de red/SQL
        $brokenDb = new mysqli($host, $user, $pass, $name, $port);
        $brokenDb->close();

        // En postura fail-secure, nunca se asume permitido
        $segundos = RateLimiter::obtenerSegundosBloqueo($brokenDb, RateLimiter::TIPO_EMAIL_LOGIN, 'cualquiera@correo.com');
        $this->assertSame(RateLimiter::DURACION_BLOQUEO, $segundos, 'Ante error SQL, obtenerSegundosBloqueo debe asumir bloqueo completo (fail-secure)');

        $resultado = RateLimiter::registrarIntentoFallido($brokenDb, RateLimiter::TIPO_EMAIL_LOGIN, 'cualquiera@correo.com', 5);
        $this->assertTrue($resultado['bloqueado'], 'Ante error SQL, registrarIntentoFallido debe devolver bloqueado (fail-secure)');
        $this->assertSame(RateLimiter::DURACION_BLOQUEO, $resultado['segundos_restantes']);
    }

    public function testOlvideConRateLimitBloqueaSinGenerarNiPersistirToken(): void
    {
        $usuario = new Usuario([
            'nombre' => 'Victima', 'apellido' => 'Test', 'email' => 'victima_olvide@correo.com',
            'password' => 'password123', 'telefono' => '1234567890', 'confirmado' => '1'
        ]);
        $usuario->guardar();

        $router = new Router();
        $ip = RateLimiter::obtenerIP();
        $email = 'victima_olvide@correo.com';

        // Simular que el rate limit ya está en su umbral de 5 intentos
        for ($i = 1; $i <= 5; $i++) {
            RateLimiter::registrarIntentoFallido(self::$db, RateLimiter::TIPO_EMAIL_RECOVERY, $email, 5);
        }

        // Petición POST a /olvide con CSRF válido
        $tokenCsrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $tokenCsrf;
        $_POST['csrf_token'] = $tokenCsrf;
        $_POST['email'] = $email;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        LoginController::olvide($router);
        $output = ob_get_clean();

        // Debe responder con HTTP 429
        $this->assertSame(429, http_response_code(), 'Debe responder con 429 por bloqueo de rate limiting');

        // Verificar en BD que NUNCA se generó ni persistió un token
        $usuarioDb = Usuario::where('email', $email);
        $this->assertNull($usuarioDb->token_hash, 'El usuario no debe tener ningún token_hash generado');
        $this->assertNull($usuarioDb->token_expira, 'No debe registrarse expiración');
    }

    public function testReenviarConfirmacionGeneraTokenNuevoSoloParaCuentasNoConfirmadas(): void
    {
        // 1. Usuario NO confirmado
        $unconfirmed = new Usuario([
            'nombre' => 'NoConfirmado', 'apellido' => 'Perez', 'email' => 'noconfirmado@correo.com',
            'password' => 'password123', 'telefono' => '1234567890', 'confirmado' => '0'
        ]);
        $unconfirmed->guardar();

        // 2. Usuario SÍ confirmado
        $confirmed = new Usuario([
            'nombre' => 'YaConfirmado', 'apellido' => 'Gomez', 'email' => 'yaconfirmado@correo.com',
            'password' => 'password123', 'telefono' => '0987654321', 'confirmado' => '1'
        ]);
        $confirmed->guardar();

        $router = new Router();
        $tokenCsrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $tokenCsrf;
        $_POST['csrf_token'] = $tokenCsrf;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Caso A: Reenvío a usuario no confirmado
        $_POST['email'] = 'noconfirmado@correo.com';
        ob_start();
        LoginController::reenviarConfirmacion($router);
        $outA = ob_get_clean();

        $uA = Usuario::where('email', 'noconfirmado@correo.com');
        $this->assertNotNull($uA->token_hash, 'La cuenta no confirmada debe recibir un nuevo token_hash');
        $this->assertSame('confirmacion', $uA->token_tipo);
        $this->assertNotNull($uA->token_expira);
        $this->assertStringContainsString('Si la cuenta existe y está pendiente de confirmación', $outA);

        // Caso B: Reenvío a cuenta ya confirmada (No debe generar token pero muestra mensaje genérico anti-enumeración)
        $_POST['email'] = 'yaconfirmado@correo.com';
        ob_start();
        LoginController::reenviarConfirmacion($router);
        $outB = ob_get_clean();

        $uB = Usuario::where('email', 'yaconfirmado@correo.com');
        $this->assertNull($uB->token_hash, 'La cuenta confirmada NO debe recibir ningún token');
        $this->assertStringContainsString('Si la cuenta existe y está pendiente de confirmación', $outB);

        // Caso C: Email inexistente (Muestra exactamente el mismo mensaje genérico)
        $_POST['email'] = 'fantasma_inexistente@correo.com';
        ob_start();
        LoginController::reenviarConfirmacion($router);
        $outC = ob_get_clean();

        $this->assertStringContainsString('Si la cuenta existe y está pendiente de confirmación', $outC);
    }
}

