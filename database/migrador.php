<?php

/**
 * Script de ejecución de migraciones de base de datos
 * Uso: php database/migrador.php [up|down]
 */

require_once __DIR__ . '/../includes/app.php';

use Model\ActiveRecord;

$comando = $argv[1] ?? 'status';
$db = ActiveRecord::getDB();

if (!$db) {
    echo "Error: Conexión a la base de datos no disponible.\n";
    exit(1);
}

// 1. Asegurar tabla de migraciones
$db->query("CREATE TABLE IF NOT EXISTS migraciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migracion VARCHAR(255) NOT NULL UNIQUE,
    aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Obtener archivos de migración (excluyendo rollbacks)
$todosSql = glob(__DIR__ . '/migrations/*.sql');
$archivosMigracion = array_filter($todosSql, function($f) {
    return !str_ends_with($f, '_rollback.sql');
});
sort($archivosMigracion);

if ($comando === 'status') {
    echo "=== Estado de Migraciones ===\n";
    $result = $db->query("SELECT migracion, aplicada_en FROM migraciones ORDER BY id ASC");
    $aplicadas = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $aplicadas[$row['migracion']] = $row['aplicada_en'];
        }
    }

    foreach ($archivosMigracion as $archivo) {
        $nombre = basename($archivo, '.sql');
        if (isset($aplicadas[$nombre])) {
            echo "[APLICADA] {$nombre} (fecha: {$aplicadas[$nombre]})\n";
        } else {
            echo "[PENDIENTE] {$nombre}\n";
        }
    }
} elseif ($comando === 'up') {
    foreach ($archivosMigracion as $archivo) {
        $nombre = basename($archivo, '.sql');
        $check = $db->query("SELECT id FROM migraciones WHERE migracion = '{$db->escape_string($nombre)}'");
        if ($check && $check->num_rows > 0) {
            echo "[-] Migración ya aplicada: {$nombre}\n";
            continue;
        }

        echo "[+] Aplicando migración: {$nombre}...\n";
        $sql = file_get_contents($archivo);
        if ($db->multi_query($sql)) {
            do {
                if ($res = $db->store_result()) {
                    $res->free();
                }
            } while ($db->more_results() && $db->next_result());
            echo "[OK] Migración {$nombre} aplicada exitosamente.\n";
        } else {
            echo "[ERROR] Error al aplicar {$nombre}: " . $db->error . "\n";
            exit(1);
        }
    }
} elseif ($comando === 'down') {
    $rollbackFiles = glob(__DIR__ . '/migrations/*_rollback.sql');
    rsort($rollbackFiles);

    foreach ($rollbackFiles as $archivo) {
        $nombre = basename($archivo, '_rollback.sql');
        $check = $db->query("SELECT id FROM migraciones WHERE migracion = '{$db->escape_string($nombre)}'");
        if (!$check || $check->num_rows === 0) {
            echo "[-] Migración no registrada, omitiendo rollback: {$nombre}\n";
            continue;
        }

        echo "[-] Revirtiendo migración: {$nombre}...\n";
        $sql = file_get_contents($archivo);
        if ($db->multi_query($sql)) {
            do {
                if ($res = $db->store_result()) {
                    $res->free();
                }
            } while ($db->more_results() && $db->next_result());
            echo "[OK] Reversión de {$nombre} completada exitosamente.\n";
        } else {
            echo "[ERROR] Error al revertir {$nombre}: " . $db->error . "\n";
            exit(1);
        }
    }
} else {
    echo "Comando no reconocido. Uso: php database/migrador.php [status|up|down]\n";
}
