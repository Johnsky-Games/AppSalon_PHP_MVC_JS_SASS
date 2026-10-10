<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Model\Usuario;
use Model\Servicio;
use Model\Cita;
use Model\CitaServicio;
use Classes\RateLimiter;
use Classes\Email;
use PHPMailer\PHPMailer\PHPMailer;
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
        $servicioId = (new \Repositories\ServicioRepository(self::$db))->create($servicio);

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

        // 1. Crear usuario en la base de datos y verificar explícitamente su existencia, confirmación y hash válido antes de iniciar
        $usuario = new Usuario([
            'nombre' => 'UserConcurrente',
            'apellido' => 'Test',
            'email' => $emailTarget,
            'password' => 'clave123',
            'telefono' => '1122334455',
            'confirmado' => '1'
        ]);
        $usuario->hashPassword();
        $guardado = $usuario->guardar();
        $this->assertTrue((bool)(is_array($guardado) ? ($guardado['resultado'] ?? false) : $guardado), 'El usuario de prueba debe guardarse correctamente');
        $this->assertNotEmpty($usuario->id, 'El usuario de prueba debe tener ID asignado');

        // Verificación previa explícita requerida por auditoría antes de iniciar subprocesos
        $usuarioEnDb = Usuario::where('email', $emailTarget);
        $this->assertNotNull($usuarioEnDb, 'El usuario de prueba debe existir en base de datos antes de iniciar los trabajadores');
        $this->assertSame('1', (string)$usuarioEnDb->confirmado, 'El usuario de prueba debe estar confirmado antes de iniciar los trabajadores');
        $this->assertTrue(password_verify('clave123', $usuarioEnDb->password), 'El hash del usuario de prueba debe verificar la clave esperada antes de iniciar los trabajadores');

        // 2. Limpiar registros previos en rate limit
        RateLimiter::limpiarIntentos(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $emailTarget);
        RateLimiter::limpiarIntentos(self::$db, RateLimiter::TIPO_IP_LOGIN, $ipTarget);

        $numProcesos = 10;
        $timeoutSegundos = 10.0;
        $procesos = [];
        $pipes = [];

        $workerScript = __DIR__ . '/concurrent_login_worker.php';
        $this->assertFileExists($workerScript);

        try {
            // 3. Levantar 10 subprocesos simultáneos con sesiones independientes y streams no bloqueantes
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
                stream_set_blocking($procPipes[1], false);
                stream_set_blocking($procPipes[2], false);
                $procesos[$i] = $proc;
                $pipes[$i] = $procPipes;
            }

            // 4. Esperar señal explícita de READY de cada uno de los 10 trabajadores con tiempo límite
            $listos = array_fill(0, $numProcesos, false);
            $inicioEsperaReady = microtime(true);
            $todosListos = false;

            while ((microtime(true) - $inicioEsperaReady) < $timeoutSegundos) {
                for ($i = 0; $i < $numProcesos; $i++) {
                    if (!$listos[$i]) {
                        $line = fgets($pipes[$i][1]);
                        if ($line !== false && trim($line) === 'READY') {
                            $listos[$i] = true;
                        }
                    }
                }
                if (!in_array(false, $listos, true)) {
                    $todosListos = true;
                    break;
                }
                usleep(10000); // 10 ms
            }

            $this->assertTrue($todosListos, "Timeout ({$timeoutSegundos}s): no todos los trabajadores concurrentes emitieron la señal READY");

            // 5. Liberar la barrera para todos los procesos concurrentemente
            for ($i = 0; $i < $numProcesos; $i++) {
                fwrite($pipes[$i][0], "GO\n");
                fflush($pipes[$i][0]);
                fclose($pipes[$i][0]);
            }

            // 6. Recolectar resultados de los 10 procesos con tiempo límite y validación estricta
            $salidas = array_fill(0, $numProcesos, '');
            $errores = array_fill(0, $numProcesos, '');
            $terminados = array_fill(0, $numProcesos, false);
            $codigosSalida = array_fill(0, $numProcesos, -1);
            $inicioEjecucion = microtime(true);
            $todosTerminados = false;

            while ((microtime(true) - $inicioEjecucion) < $timeoutSegundos) {
                for ($i = 0; $i < $numProcesos; $i++) {
                    if (!$terminados[$i]) {
                        $chunkOut = stream_get_contents($pipes[$i][1]);
                        if ($chunkOut !== false && $chunkOut !== '') {
                            $salidas[$i] .= $chunkOut;
                        }
                        $chunkErr = stream_get_contents($pipes[$i][2]);
                        if ($chunkErr !== false && $chunkErr !== '') {
                            $errores[$i] .= $chunkErr;
                        }

                        $procStatus = proc_get_status($procesos[$i]);
                        if (!$procStatus['running']) {
                            $salidas[$i] .= stream_get_contents($pipes[$i][1]);
                            $errores[$i] .= stream_get_contents($pipes[$i][2]);
                            $codigosSalida[$i] = (int)$procStatus['exitcode'];
                            $terminados[$i] = true;
                        }
                    }
                }
                if (!in_array(false, $terminados, true)) {
                    $todosTerminados = true;
                    break;
                }
                usleep(10000); // 10 ms
            }

            $this->assertTrue($todosTerminados, "Timeout ({$timeoutSegundos}s): no todos los trabajadores completaron su ejecución");

            $admitidos = 0;
            $rechazados429 = 0;
            $totalVerificacionesPassword = 0;
            $totalRechazadosSinVerificacion = 0;

            for ($i = 0; $i < $numProcesos; $i++) {
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                $closeCode = proc_close($procesos[$i]);
                $procesos[$i] = null;
                $exitCode = ($codigosSalida[$i] !== -1) ? $codigosSalida[$i] : $closeCode;

                $this->assertSame(0, $exitCode, "El proceso hijo #{$i} terminó con código {$exitCode}. STDERR: {$errores[$i]}");
                $data = json_decode(trim($salidas[$i]), true);
                $this->assertIsArray($data, "El subproceso #{$i} no retornó JSON válido. Salida: '{$salidas[$i]}' | STDERR: '{$errores[$i]}'");

                // Rechazar errores 500, excepciones o respuestas inesperadas
                $this->assertContains($data['status'], [200, 429], "Respuesta inesperada en proceso #{$i}: status={$data['status']}. Error: " . ($data['error_msg'] ?? 'ninguno'));

                if ($data['status'] === 429) {
                    $this->assertTrue($data['bloqueado'], "El proceso #{$i} rechazado con 429 debe reportar bloqueado=true");
                    // Comprobación explícita de observación: cero verificaciones en las solicitudes rechazadas con 429
                    $this->assertFalse($data['verificacion_password_ejecutada'], "El proceso #{$i} rechazado con 429 NO debe ejecutar la verificación de contraseña");
                    $rechazados429++;
                    $totalRechazadosSinVerificacion++;
                } elseif ($data['status'] === 200) {
                    $this->assertFalse($data['bloqueado'], "El proceso #{$i} admitido no debe reportar bloqueo");
                    // Comprobación explícita de observación: llamada real al verificador de contraseña
                    $this->assertTrue($data['verificacion_password_ejecutada'], "El proceso #{$i} admitido DEBE haber ejecutado la llamada real a comprobarPasswordAndVerificado");
                    $admitidos++;
                    $totalVerificacionesPassword++;
                }
            }

            // 7. Verificar conteo exacto: exactamente 5 admisiones y 5 verificaciones reales de contraseña
            // frente a exactamente 5 rechazos con 429 y cero verificaciones de contraseña
            $this->assertSame(5, $admitidos, 'Exactamente 5 peticiones deben ser admitidas por el rate limiter');
            $this->assertSame(5, $rechazados429, 'Exactamente 5 peticiones deben ser rechazadas con 429 bajo concurrencia');
            $this->assertSame(5, $totalVerificacionesPassword, 'Exactamente 5 solicitudes deben haber ejecutado la llamada real a comprobarPasswordAndVerificado');
            $this->assertSame(5, $totalRechazadosSinVerificacion, 'Exactamente 5 solicitudes rechazadas con 429 deben tener cero verificaciones de contraseña');
            $this->assertSame(10, $admitidos + $rechazados429, 'El total de procesos debe ser exactamente 10');

            // 8. Verificar estado final en MySQL
            $estado = RateLimiter::consultarEstado(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $emailTarget);
            $this->assertTrue($estado['bloqueado'], 'La cuenta debe permanecer en estado bloqueado tras alcanzar el umbral');
            $this->assertSame(5, $estado['intentos'], 'El número de intentos registrados en BD debe ser exactamente 5');
        } finally {
            // Limpieza estricta de procesos y descriptores para evitar que fallos dejen la suite esperando indefinidamente
            foreach ($pipes as $procPipes) {
                if (is_array($procPipes)) {
                    foreach ($procPipes as $p) {
                        if (is_resource($p)) {
                            @fclose($p);
                        }
                    }
                }
            }
            foreach ($procesos as $proc) {
                if (is_resource($proc)) {
                    $st = @proc_get_status($proc);
                    if ($st && $st['running']) {
                        @proc_terminate($proc, 9);
                    }
                    @proc_close($proc);
                }
            }
        }
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

    public function testEmisionTokenRecuperacionConcurrenteNoRevierteCambioDePassword(): void
    {
        $email = 'concurrente_pwd@correo.com';
        $passOriginalHash = password_hash('clave_original_123', PASSWORD_BCRYPT);
        $passNuevoHash = password_hash('clave_nueva_456', PASSWORD_BCRYPT);

        $usuario = new Usuario([
            'nombre' => 'Ana',
            'apellido' => 'Lopez',
            'email' => $email,
            'password' => $passOriginalHash,
            'telefono' => '1122334455',
            'confirmado' => '1',
            'admin' => '0'
        ]);
        $usuario->guardar();
        $id = (int)$usuario->id;

        // Simulación de concurrencia:
        // Hilo 1: Lee el objeto usuario en memoria con la contraseña original
        $hilo1Usuario = Usuario::where('email', $email);
        $this->assertSame($passOriginalHash, $hilo1Usuario->password);

        // Hilo 2 (intercalado): El usuario cambia su contraseña en otra petición
        self::$db->query("UPDATE usuarios SET password = '{$passNuevoHash}' WHERE id = {$id}");

        // Hilo 1: Emite token de recuperación mediante actualización preparada específica
        $tokenRaw = $hilo1Usuario->generarYPersistirTokenRecuperacion(2);
        $this->assertNotNull($tokenRaw, 'Debe emitir token exitosamente para cuenta confirmada');

        // Verificación en base de datos: La nueva contraseña de Hilo 2 NO fue revertida por Hilo 1
        $usuarioFinal = Usuario::where('email', $email);
        $this->assertSame($passNuevoHash, $usuarioFinal->password, 'El cambio de contraseña concurrente NO debe ser revertido');
        $this->assertSame('recuperacion', $usuarioFinal->token_tipo);
        $this->assertSame(hash('sha256', $tokenRaw), $usuarioFinal->token_hash);
        $this->assertNull($usuarioFinal->token);
    }

    public function testEmisionTokenConfirmacionConcurrenteNoRevierteConfirmacion(): void
    {
        $email = 'concurrente_conf@correo.com';
        $usuario = new Usuario([
            'nombre' => 'Pedro',
            'apellido' => 'Soto',
            'email' => $email,
            'password' => password_hash('clave123', PASSWORD_BCRYPT),
            'telefono' => '9988776655',
            'confirmado' => '0',
            'admin' => '0'
        ]);
        $usuario->guardar();
        $id = (int)$usuario->id;

        // Hilo 1: Lee usuario no confirmado
        $hilo1Usuario = Usuario::where('email', $email);
        $this->assertSame('0', (string)$hilo1Usuario->confirmado);

        // Hilo 2: Se confirma la cuenta concurrentemente
        self::$db->query("UPDATE usuarios SET confirmado = '1' WHERE id = {$id}");

        // Hilo 1: Intenta emitir token de confirmación condicionado a confirmado = '0'
        $tokenRaw = $hilo1Usuario->generarYPersistirTokenConfirmacion(24);

        // Debe retornar null porque afectó 0 filas (ya estaba confirmada)
        $this->assertNull($tokenRaw, 'No debe emitir token si la cuenta fue confirmada concurrentemente');

        $usuarioFinal = Usuario::where('email', $email);
        $this->assertSame('1', (string)$usuarioFinal->confirmado, 'La confirmación concurrente debe mantenerse intacta');
        $this->assertNull($usuarioFinal->token_hash);
    }

    public function testLoginExitosoNoReiniciaPresupuestoIpAtaqueMultiplesCuentas(): void
    {
        $ip = '198.51.100.99';
        $controlEmail = 'cuenta_control@correo.com';
        $controlPassword = 'clave_control_123';

        // Crear cuenta de control legítima
        $usuarioControl = new Usuario([
            'nombre' => 'Control',
            'apellido' => 'Owner',
            'email' => $controlEmail,
            'password' => $controlPassword,
            'telefono' => '1234567890',
            'confirmado' => '1'
        ]);
        $usuarioControl->hashPassword();
        $usuarioControl->guardar();

        // Limpiar registros previos de rate limiting
        RateLimiter::limpiarIntentos(self::$db, RateLimiter::TIPO_IP_LOGIN, $ip);
        RateLimiter::limpiarIntentos(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $controlEmail);

        $router = new Router();
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // 1. Simular 4 intentos fallidos hacia cuenta victima1 desde la misma IP
        for ($i = 1; $i <= 4; $i++) {
            $csrf = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $csrf;
            $_POST['csrf_token'] = $csrf;
            $_POST['email'] = 'victima1@correo.com';
            $_POST['password'] = 'clave_invalida';

            ob_start();
            LoginController::login($router);
            ob_end_clean();
        }

        // 2. Realizar 1 inicio de sesión exitoso hacia la cuenta de control desde la misma IP
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;
        $_POST['csrf_token'] = $csrf;
        $_POST['email'] = $controlEmail;
        $_POST['password'] = $controlPassword;

        ob_start();
        try {
            LoginController::login($router);
        } catch (\Classes\AppTerminationException $e) {
            // Esperado redirect 302
        } catch (\AppTerminationException $e) {
            // Esperado redirect 302
        }
        ob_end_clean();

        // Verificar que el contador de la cuenta de control fue limpiado
        $estadoControl = RateLimiter::consultarEstado(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $controlEmail);
        $this->assertFalse($estadoControl['bloqueado']);
        $this->assertSame(0, $estadoControl['intentos']);

        // Verificar que el presupuesto de la IP NO se reinició (registra intentos previos sin ser borrado)
        $estadoIp = RateLimiter::consultarEstado(self::$db, RateLimiter::TIPO_IP_LOGIN, $ip);
        $this->assertGreaterThanOrEqual(4, $estadoIp['intentos'], 'El presupuesto de la IP NO debe ser reiniciado por un login exitoso');

        // 3. Continuar intentos hacia otras cuentas víctimas desde la misma IP hasta agotar el límite de IP (MAX_INTENTOS_IP = 15)
        $intentosRestantes = RateLimiter::MAX_INTENTOS_IP - $estadoIp['intentos'];
        for ($k = 0; $k < $intentosRestantes; $k++) {
            $csrf = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $csrf;
            $_POST['csrf_token'] = $csrf;
            $_POST['email'] = "victima_extra_{$k}@correo.com";
            $_POST['password'] = 'clave_invalida';

            ob_start();
            LoginController::login($router);
            ob_end_clean();
        }

        // 4. El siguiente intento desde esa IP (incluso hacia la cuenta de control) debe ser bloqueado con 429
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;
        $_POST['csrf_token'] = $csrf;
        $_POST['email'] = $controlEmail;
        $_POST['password'] = $controlPassword;

        ob_start();
        LoginController::login($router);
        ob_end_clean();

        $this->assertSame(429, http_response_code(), 'La IP debe bloquearse con 429 al agotar su presupuesto compartido');
        $alertas = Usuario::getAlertas();
        $this->assertNotEmpty($alertas['error']);
        $this->assertStringContainsString('Demasiados intentos fallidos', $alertas['error'][0]);
    }

    public function testOlvideNoIntentaEnviarCorreoSiFallaPersistenciaToken(): void
    {
        $email = 'falla_persistencia_olvide@correo.com';
        $usuario = new Usuario([
            'nombre' => 'TestPersist',
            'apellido' => 'Olvide',
            'email' => $email,
            'password' => password_hash('clave123', PASSWORD_BCRYPT),
            'telefono' => '1122334455',
            'confirmado' => '1'
        ]);
        $usuario->guardar();

        $enviosIntentados = 0;
        Email::setTransport(function(PHPMailer $mailer, string $proposito, array $meta) use (&$enviosIntentados): bool {
            $enviosIntentados++;
            return true;
        });

        // Crear trigger temporal que hace fallar la actualización de tokens de recuperación
        self::$db->query("DROP TRIGGER IF EXISTS trg_fail_token_update");
        self::$db->query(
            "CREATE TRIGGER trg_fail_token_update BEFORE UPDATE ON usuarios
             FOR EACH ROW BEGIN
                 IF NEW.token_tipo = 'recuperacion' AND NEW.email = '{$email}' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced update failure';
                 END IF;
             END"
        );

        $router = new Router();
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;
        $_POST['csrf_token'] = $csrf;
        $_POST['email'] = $email;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        try {
            ob_start();
            LoginController::olvide($router);
            ob_end_clean();

            // Comprobar que NO se intentó enviar ningún correo
            $this->assertSame(0, $enviosIntentados, 'Si la persistencia del token falla, no debe llamarse al transporte de correo');
            $this->assertEmpty(Email::$emailsEnviados, 'No debe registrarse ningún correo enviado si la persistencia falló');
        } finally {
            self::$db->query("DROP TRIGGER IF EXISTS trg_fail_token_update");
        }
    }

    public function testReenviarConfirmacionNoEnviaCorreoSiCuentaYaEstaConfirmada(): void
    {
        $email = 'confirmada_no_envio@correo.com';
        $usuario = new Usuario([
            'nombre' => 'Usuario',
            'apellido' => 'Confirmado',
            'email' => $email,
            'password' => password_hash('clave123', PASSWORD_BCRYPT),
            'telefono' => '1122334455',
            'confirmado' => '1'
        ]);
        $usuario->guardar();

        $enviosIntentados = 0;
        Email::setTransport(function(PHPMailer $mailer, string $proposito, array $meta) use (&$enviosIntentados): bool {
            $enviosIntentados++;
            return true;
        });

        $router = new Router();
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;
        $_POST['csrf_token'] = $csrf;
        $_POST['email'] = $email;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        LoginController::reenviarConfirmacion($router);
        ob_end_clean();

        $this->assertSame(0, $enviosIntentados, 'No debe intentar enviar correo de confirmación para cuenta ya confirmada');
        $this->assertEmpty(Email::$emailsEnviados);
    }
}

