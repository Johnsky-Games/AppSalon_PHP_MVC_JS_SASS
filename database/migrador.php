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
$creacion = $db->query("CREATE TABLE IF NOT EXISTS migraciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migracion VARCHAR(255) NOT NULL UNIQUE,
    aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (!$creacion) {
    echo "[ERROR] Fallo crítico al crear o verificar la tabla de control 'migraciones': " . $db->error . "\n";
    exit(1);
}

// Obtener archivos de migración ordenados (excluyendo archivos de rollback)
$todosSql = glob(__DIR__ . '/migrations/*.sql');
$archivosMigracion = array_filter($todosSql, function($f) {
    return !str_ends_with($f, '_rollback.sql');
});
sort($archivosMigracion);

if ($comando === 'status') {
    echo "=== Estado de Migraciones ===\n";
    $result = $db->query("SELECT migracion, aplicada_en FROM migraciones ORDER BY id ASC");
    if (!$result) {
        echo "[ERROR] Fallo al consultar el estado en la tabla 'migraciones': " . $db->error . "\n";
        exit(1);
    }
    $aplicadas = [];
    while ($row = $result->fetch_assoc()) {
        $aplicadas[$row['migracion']] = $row['aplicada_en'];
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
        $stmtCheck = $db->prepare("SELECT id FROM migraciones WHERE migracion = ?");
        if (!$stmtCheck) {
            echo "[ERROR] Error al preparar consulta de verificación para {$nombre}: " . $db->error . "\n";
            exit(1);
        }
        $stmtCheck->bind_param('s', $nombre);
        if (!$stmtCheck->execute()) {
            echo "[ERROR] Error al verificar estado de la migración {$nombre}: " . $stmtCheck->error . "\n";
            $stmtCheck->close();
            exit(1);
        }
        $check = $stmtCheck->get_result();
        $stmtCheck->close();

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
        if (!$stmt) {
            echo "[ERROR] Fallo crítico al preparar el registro de la migración {$nombre} en la tabla 'migraciones': " . $db->error . "\n";
            echo "[ESTADO PARCIAL] Las sentencias DDL/DML de {$nombre} fueron aplicadas, pero el registro de control no pudo prepararse. Inserte manualmente el registro en 'migraciones' antes de continuar.\n";
            exit(1);
        }

        $stmt->bind_param('s', $nombre);
        $ejecutado = $stmt->execute();
        $errorInsert = $stmt->error;
        $stmt->close();

        if (!$ejecutado) {
            echo "[ERROR] Fallo crítico al registrar la migración {$nombre} en la tabla 'migraciones': {$errorInsert}\n";
            echo "[ESTADO PARCIAL] Las sentencias DDL/DML de la migración {$nombre} fueron aplicadas, pero el registro en 'migraciones' falló. La base de datos se encuentra en un estado parcial. Debe registrar manualmente la migración en la tabla 'migraciones' para resolver la discrepancia.\n";
            exit(1);
        }

        echo "[OK] Migración {$nombre} aplicada exitosamente.\n";
    }
} elseif ($comando === 'down') {
    $rollbackFiles = glob(__DIR__ . '/migrations/*_rollback.sql');
    rsort($rollbackFiles);

    foreach ($rollbackFiles as $archivo) {
        $nombre = basename($archivo, '_rollback.sql');
        $stmtCheck = $db->prepare("SELECT id FROM migraciones WHERE migracion = ?");
        if (!$stmtCheck) {
            echo "[ERROR] Error al preparar verificación de rollback para {$nombre}: " . $db->error . "\n";
            exit(1);
        }
        $stmtCheck->bind_param('s', $nombre);
        if (!$stmtCheck->execute()) {
            echo "[ERROR] Error al verificar migración {$nombre} para rollback: " . $stmtCheck->error . "\n";
            $stmtCheck->close();
            exit(1);
        }
        $check = $stmtCheck->get_result();
        $stmtCheck->close();

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
        if (!$stmt) {
            echo "[ERROR] Fallo crítico al preparar eliminación de {$nombre} en 'migraciones': " . $db->error . "\n";
            exit(1);
        }
        $stmt->bind_param('s', $nombre);
        $ejecutado = $stmt->execute();
        $errorDelete = $stmt->error;
        $stmt->close();

        if (!$ejecutado) {
            echo "[ERROR] Fallo crítico al eliminar el registro de {$nombre} en 'migraciones': {$errorDelete}\n";
            exit(1);
        }

        echo "[OK] Reversión de {$nombre} completada exitosamente.\n";
    }
} else {
    echo "Comando no reconocido. Uso: php database/migrador.php [status|up|down]\n";
    exit(1);
}
