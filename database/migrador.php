<?php

/**
 * Script de ejecución y control de migraciones de base de datos
 * Uso: php database/migrador.php [status|up|down]
 *
 * `down` revierte TODAS las migraciones registradas (orden inverso) y se NIEGA a hacerlo si la reversión destruiría datos
 * de negocio (código de salida 2, sin tocar nada). Para aceptar la pérdida de forma explícita:
 *   php database/migrador.php down [--hasta=<migracion>] --aceptar-perdida=<migracion> [--aceptar-perdida=<otra>] --respaldo=<archivo.sql.gz>
 * `--hasta=NNN_nombre` limita la reversión a esa migración (inclusive) y las más recientes; sin él se revierten todas.
 * El respaldo (scripts/backup_db.php --gzip) debe ser reciente y verificable. `down --simular` solo informa.
 * Ver database/migrador_reversion.php y docs/GUIA_ACTUALIZACION_IDENTIDADES.md §7.
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/migrador_reversion.php';

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

        // Pre-validación opcional: detecta datos incompatibles ANTES de alterar el esquema,
        // para no dejar un despliegue a medias. Nunca modifica datos.
        $precheck = __DIR__ . '/migrations/' . $nombre . '.precheck.php';
        if (is_file($precheck)) {
            $validador = require $precheck;
            $problemas = is_callable($validador) ? $validador($db) : [];
            if (!empty($problemas)) {
                echo "[ERROR] La migración {$nombre} NO se aplicó: hay datos incompatibles.\n";
                foreach ($problemas as $problema) {
                    echo "  - {$problema}\n";
                }
                echo "[AVISO] No se modificó ninguna tabla. Ejecuta scripts/diagnosticar_duplicados.php (solo lectura), resuelve los casos con respaldo previo y reintenta.\n";
                exit(1);
            }
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
    $opciones = migrador_opciones_down(array_slice($argv, 2));
    if (!empty($opciones['errores'])) {
        foreach ($opciones['errores'] as $error) {
            echo "[ERROR] {$error}\n";
        }
        echo "Uso: php database/migrador.php down [--simular] [--hasta=<migracion>] [--aceptar-perdida=<migracion>]... [--respaldo=<archivo.sql.gz>]\n";
        exit(1);
    }

    $rollbackFiles = glob(__DIR__ . '/migrations/*_rollback.sql');
    rsort($rollbackFiles);

    // Fase 1 (sin tocar nada): qué se revertiría y qué datos de negocio se perderían.
    $plan = [];
    foreach ($rollbackFiles as $archivo) {
        $nombre = basename($archivo, '_rollback.sql');
        $stmtPlan = $db->prepare("SELECT id FROM migraciones WHERE migracion = ?");
        if (!$stmtPlan) {
            echo "[ERROR] Error al preparar verificación de rollback para {$nombre}: " . $db->error . "\n";
            exit(1);
        }
        $stmtPlan->bind_param('s', $nombre);
        $stmtPlan->execute();
        $registrada = $stmtPlan->get_result();
        $estaRegistrada = $registrada && $registrada->num_rows > 0;
        $stmtPlan->close();
        if ($estaRegistrada) {
            $plan[] = $nombre;
        }
    }
    if ($opciones['hasta'] !== null) {
        // Reversión parcial: solo las migraciones registradas desde la indicada (inclusive) hacia las más recientes.
        if (!in_array($opciones['hasta'], $plan, true)) {
            echo "[ERROR] --hasta no corresponde a una migración registrada con rollback: {$opciones['hasta']}\n";
            exit(1);
        }
        $plan = array_values(array_filter($plan, static fn($n) => strcmp($n, $opciones['hasta']) >= 0));
        $rollbackFiles = array_values(array_filter($rollbackFiles, static fn($f) => strcmp(basename($f, '_rollback.sql'), $opciones['hasta']) >= 0));
    }
    $perdidas = [];
    foreach ($plan as $nombre) {
        $p = migrador_perdida($db, __DIR__ . '/migrations', $nombre);
        if (!empty($p)) {
            $perdidas[$nombre] = $p;
        }
    }
    $desconocidas = array_diff($opciones['aceptar'], $plan);
    if (!empty($desconocidas)) {
        echo "[ERROR] --aceptar-perdida no corresponde a una migración registrada con rollback: " . implode(', ', $desconocidas) . "\n";
        exit(1);
    }

    if (!empty($perdidas)) {
        echo "[BLOQUEO] Revertir destruiría datos de negocio:\n";
        foreach ($perdidas as $nombre => $lista) {
            $aceptada = in_array($nombre, $opciones['aceptar'], true) ? 'aceptada' : 'NO aceptada';
            echo "  {$nombre} ({$aceptada}):\n";
            foreach ($lista as $p) {
                echo "    - {$p['mensaje']}\n";
            }
        }
        $sinAceptar = array_diff(array_keys($perdidas), $opciones['aceptar']);
        $tablas = [];
        foreach ($perdidas as $lista) {
            foreach ($lista as $p) {
                if ($p['tabla'] !== null) {
                    $tablas[] = $p['tabla'];
                }
            }
        }
        $motivos = [];
        if (!empty($sinAceptar)) {
            $motivos[] = 'Falta aceptar de forma explícita: ' . implode(' ', array_map(static fn($n) => "--aceptar-perdida={$n}", $sinAceptar));
        }
        if ($opciones['respaldo'] === null) {
            $motivos[] = 'Falta --respaldo=<archivo.sql.gz> (genéralo antes con: php scripts/backup_db.php --gzip).';
        } else {
            foreach (migrador_verificar_respaldo($db, $opciones['respaldo'], $tablas) as $motivo) {
                $motivos[] = $motivo;
            }
        }
        if (!empty($motivos)) {
            foreach ($motivos as $motivo) {
                echo "[BLOQUEO] {$motivo}\n";
            }
            echo $opciones['simular']
                ? "[SIMULACIÓN] No se modificó ninguna tabla.\n"
                : "[AVISO] No se modificó ninguna tabla ni la tabla de control 'migraciones'.\n";
            exit(2);
        }
        if ($opciones['simular']) {
            echo "[SIMULACIÓN] Con estas opciones la reversión se permitiría (pérdida aceptada y respaldo verificado). No se modificó ninguna tabla.\n";
            exit(0);
        }
        echo "[AVISO] Pérdida aceptada de forma explícita con respaldo verificado: " . implode(', ', array_keys($perdidas)) . ".\n";
    } elseif ($opciones['simular']) {
        echo "[SIMULACIÓN] La reversión no destruye datos de negocio (" . count($plan) . " migraciones). No se modificó ninguna tabla.\n";
        exit(0);
    }

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
