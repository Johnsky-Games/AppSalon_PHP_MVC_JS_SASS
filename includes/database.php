<?php

$db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$db_user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$db_pass = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
$db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'appsalon_mvc';
$db_port = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);

// Silenciar advertencias para manejar errores de conexión sin filtrar internals
mysqli_report(MYSQLI_REPORT_OFF);

$db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($db) {
    $db->set_charset('utf8mb4');
} else {
    error_log('[AppSalon] MySQL no disponible; errno=' . mysqli_connect_errno());
    if (php_sapi_name() !== 'cli') {
        throw new \Classes\ServiceUnavailableException('Servicio de datos temporalmente no disponible.');
    }
}
