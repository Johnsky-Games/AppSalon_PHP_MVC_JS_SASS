<?php

$db_host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';
$db_name = $_ENV['DB_NAME'] ?? 'appsalon_mvc';
$db_port = isset($_ENV['DB_PORT']) ? (int)$_ENV['DB_PORT'] : 3306;

// Silenciar advertencias para manejar errores de conexión sin filtrar internals
mysqli_report(MYSQLI_REPORT_OFF);

$db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($db) {
    $db->set_charset('utf8mb4');
} else {
    error_log("Error de conexión a MySQL (errno " . mysqli_connect_errno() . "): " . mysqli_connect_error());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        echo "Error: No fue posible conectar con el servicio de base de datos.";
        exit;
    }
}