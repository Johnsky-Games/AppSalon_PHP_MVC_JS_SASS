<?php

/**
 * Bootstrap de pruebas automatizadas de AppSalon.
 * Valida de forma estricta que la base de datos sea exclusiva de pruebas
 * antes de permitir cualquier ejecución destructiva o de aserción.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/app.php';

// Verificación obligatoria de entorno seguro de pruebas
$dbName = $_ENV['DB_NAME'] ?? '';
if (!str_ends_with($dbName, '_test') && $dbName !== 'appsalon_test') {
    fwrite(STDERR, "\n[ERROR CRÍTICO DE SEGURIDAD] Ejecución de pruebas cancelada.\n");
    fwrite(STDERR, "La base de datos actual '{$dbName}' NO está autorizada para pruebas.\n");
    fwrite(STDERR, "El nombre de la base de datos debe finalizar en '_test' o ser 'appsalon_test'.\n\n");
    exit(1);
}

/**
 * Helper global para comprobar que la base conectada es de pruebas antes de truncar tablas.
 */
function validar_base_datos_prueba(\mysqli $db): void
{
    $res = $db->query("SELECT DATABASE() AS db_actual");
    $fila = $res ? $res->fetch_assoc() : null;
    $nombreDb = $fila['db_actual'] ?? '';

    if (!str_ends_with($nombreDb, '_test') && $nombreDb !== 'appsalon_test') {
        throw new \RuntimeException(
            "ACCESO DENEGADO: Operación destructiva bloqueada. La base de datos activa ('{$nombreDb}') no es un entorno de prueba autorizado."
        );
    }
}
