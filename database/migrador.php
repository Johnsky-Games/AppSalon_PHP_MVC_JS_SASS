<?php

/**
 * Script de ejecución y control de migraciones de base de datos
 * Uso: php database/migrador.php [status|up|down]
 */

require_once __DIR__ . '/../includes/app.php';

use Model\ActiveRecord;

$comando = $argv[1] ?? 'status';
$db = ActiveRecord::getDB();

if (!$db) {
    echo "Error: Conexión a la base de datos no disponible.\n";
    exit(1);
}

// 1. Asegurar tabla de migraciones versionadas
$db->query("CREATE TABLE IF NOT EXISTS migraciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migracion VARCHAR(255) NOT NULL UNIQUE,
    aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Obtener archivos de migración ordenados (excluyendo archivos de rollback)
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
        if (!$db->multi_query($sql)) {
            echo "[ERROR] Error al iniciar la ejecución de {$nombre}: " . $db->error . "\n";
            exit(1);
        }

        $fallo = false;
        $mensajeError = '';
        do {
            if ($db->errno) {
                $fallo = true;
                $mensajeError = $db->error;
                break;
            }
            if ($res = $db->store_result()) {
                $res->free();
            }
            if (!$db->more_results()) {
                break;
            }
            if (!$db->next_result()) {
                if ($db->errno) {
                    $fallo = true;
                    $mensajeError = $db->error;
                }
                break;
            }
        } while (true);

        if ($fallo) {
            echo "[ERROR] Fallo intermedio en la migración {$nombre}: {$mensajeError}\n";
            echo "[AVISO] La migración NO se registró como completada. Revise los errores y aplique corrección manual antes de reintentar.\n";
            exit(1);
        }

        // Registrar en migraciones únicamente tras completar todas las sentencias sin error
        $stmt = $db->prepare("INSERT INTO migraciones (migracion, aplicada_en) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE aplicada_en = NOW()");
        if ($stmt) {
            $stmt->bind_param('s', $nombre);
            $stmt->execute();
            $stmt->close();
        }

        echo "[OK] Migración {$nombre} aplicada exitosamente.\n";
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
        if (!$db->multi_query($sql)) {
            echo "[ERROR] Error al iniciar la reversión de {$nombre}: " . $db->error . "\n";
            exit(1);
        }

        $fallo = false;
        $mensajeError = '';
        do {
            if ($db->errno) {
                $fallo = true;
                $mensajeError = $db->error;
                break;
            }
            if ($res = $db->store_result()) {
                $res->free();
            }
            if (!$db->more_results()) {
                break;
            }
            if (!$db->next_result()) {
                if ($db->errno) {
                    $fallo = true;
                    $mensajeError = $db->error;
                }
                break;
            }
        } while (true);

        if ($fallo) {
            echo "[ERROR] Fallo intermedio en la reversión de {$nombre}: {$mensajeError}\n";
            exit(1);
        }

        // Eliminar registro de tabla de migraciones
        $stmt = $db->prepare("DELETE FROM migraciones WHERE migracion = ?");
        if ($stmt) {
            $stmt->bind_param('s', $nombre);
            $stmt->execute();
            $stmt->close();
        }

        echo "[OK] Reversión de {$nombre} completada exitosamente.\n";
    }
} else {
    echo "Comando no reconocido. Uso: php database/migrador.php [status|up|down]\n";
    exit(1);
}
