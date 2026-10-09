<?php

/**
 * Worker auxiliar para pruebas de concurrencia simultánea en el flujo de login.
 * Se sincroniza mediante STDIN para disparar peticiones paralelas exactas.
 */

require_once __DIR__ . '/../../includes/app.php';

use MVC\Router;
use Controllers\LoginController;
use Model\Usuario;
use Model\ActiveRecord;
use Classes\Email;

$host = getenv('DB_HOST') ?: 'appsalon-test-db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'root';
$name = getenv('DB_NAME') ?: 'appsalon_test';
$port = (int)(getenv('DB_PORT') ?: 3306);

// Conexión independiente para este proceso
$db = new mysqli($host, $user, $pass, $name, $port);
$db->set_charset('utf8mb4');
ActiveRecord::setDB($db);

// Evitar envíos SMTP durante la prueba
Email::setTransport(function(): bool { return true; });

// Preparar sesión y credenciales independientes
if (session_status() === PHP_SESSION_NONE) {
    session_id(bin2hex(random_bytes(16)));
    session_start();
}
$csrf = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;

$emailTarget = $argv[1] ?? 'concurrente_login@correo.com';
$ipTarget = $argv[2] ?? '198.51.100.55';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = $ipTarget;
$_POST['csrf_token'] = $csrf;
$_POST['email'] = $emailTarget;
$_POST['password'] = 'clave_invalida_concurrente';

// Barrera de sincronización: esperar señal de inicio por STDIN
fgets(STDIN);

// Ejecutar flujo real de LoginController::login
$router = new Router();
$codigo = 200;
ob_start();
try {
    LoginController::login($router);
    $codigo = http_response_code();
} catch (\Classes\AppTerminationException $e) {
    $codigo = $e->getStatusCode();
} catch (\AppTerminationException $e) {
    $codigo = $e->getStatusCode();
} catch (\Throwable $e) {
    $codigo = 500;
} finally {
    ob_end_clean();
}

$alertas = Usuario::getAlertas();
$bloqueado = false;
foreach ($alertas['error'] ?? [] as $err) {
    if (str_contains($err, 'Demasiados intentos')) {
        $bloqueado = true;
    }
}

$db->close();

echo json_encode([
    'status' => $codigo,
    'bloqueado' => $bloqueado
]);
