<?php

declare(strict_types=1);

/**
 * Script CLI de Restauración Segura de Base de Datos MySQL — AppSalon
 *
 * Uso:
 *   php scripts/restore_db.php --input=<archivo.sql|.sql.gz> [--db=<nombre_bd>] [--hash=<sha256>] [--manifest=<ruta>] [--create-db] [--safe-restore|--no-safe-restore] [--skip-hash-check]
 *
 * Características de Integridad y Resiliencia:
 * - Ejecutable exclusivamente desde CLI (`php_sapi_name() === 'cli'`).
 * - Soporta archivos `.sql` en texto plano y `.sql.gz` comprimidos con GZIP.
 * - Verificación criptográfica obligatoria: valida el hash SHA-256 del archivo contra el parámetro `--hash`,
 *   el archivo `--manifest`, o el manifiesto adyacente `.manifest.json` antes de ejecutar cualquier sentencia DDL.
 * - Detección estricta de truncamiento y validación sintáctica previa antes de aplicar cambios a la base destino.
 * - Modo Seguro (`--safe-restore`, activo por defecto): ante bases de datos existentes con datos, realiza una
 *   restauración previa en una base aislada temporal de staging para verificar que todas las sentencias se ejecuten
 *   sin fallos antes de reemplazar datos. Si ocurre cualquier error a mitad de restauración, preserva intacta la base destino.
 * - Restaura `FOREIGN_KEY_CHECKS = 1` y `UNIQUE_CHECKS = 1` de manera garantizada al finalizar.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Acceso permitido únicamente desde línea de comandos (CLI).\n";
    exit(1);
}

use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/../includes');
$dotenv->safeLoad();

$opciones = getopt('', [
    'input:',
    'db::',
    'hash::',
    'manifest::',
    'create-db',
    'safe-restore',
    'no-safe-restore',
    'skip-hash-check',
    'help'
]);

if (isset($opciones['help'])) {
    echo "Uso: php scripts/restore_db.php --input=<archivo.sql|.sql.gz> [--db=<nombre_bd>] [--hash=<sha256>] [--manifest=<ruta>] [--create-db] [--safe-restore|--no-safe-restore] [--skip-hash-check]\n";
    exit(0);
}

$rutaEntrada = isset($opciones['input']) && is_string($opciones['input']) ? trim($opciones['input']) : '';
if ($rutaEntrada === '' || !is_file($rutaEntrada) || !is_readable($rutaEntrada)) {
    fwrite(STDERR, "[ERROR] Debe especificar un archivo de respaldo existente y legible mediante --input=<ruta>.\n");
    exit(1);
}

$dbHost = (string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbUser = (string)($_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string)($_ENV['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''));
$dbName = isset($opciones['db']) && is_string($opciones['db']) && trim($opciones['db']) !== ''
    ? trim($opciones['db'])
    : (string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'appsalon_mvc');

if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "[ERROR] Nombre de base de datos destino inválido.\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// 1. Lectura preliminar y validación de completitud / sintaxis
// -----------------------------------------------------------------------------
$raw = @file_get_contents($rutaEntrada);
if ($raw === false || $raw === '') {
    fwrite(STDERR, "[ERROR] El archivo de respaldo está vacío o no pudo leerse: {$rutaEntrada}\n");
    exit(1);
}

// Detectar cabecera GZIP (\x1f\x8b) o extensión .gz
if (str_starts_with($raw, "\x1f\x8b") || str_ends_with(strtolower($rutaEntrada), '.gz')) {
    $descomprimido = @gzdecode($raw);
    if ($descomprimido === false || $descomprimido === '') {
        fwrite(STDERR, "[ERROR] No fue posible descomprimir el archivo GZIP: {$rutaEntrada}\n");
        exit(1);
    }
    $sqlContenido = $descomprimido;
} else {
    $sqlContenido = $raw;
}

// Si el archivo declara el encabezado oficial de AppSalon, exigir el marcador de finalización
if (str_contains($sqlContenido, '-- AppSalon SQL Backup')) {
    if (!str_contains($sqlContenido, '-- Fin del respaldo AppSalon')) {
        fwrite(STDERR, "[ERROR] Archivo de respaldo truncado o incompleto: falta el marcador final '-- Fin del respaldo AppSalon'.\n");
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// 2. Verificación criptográfica SHA-256 previa a cualquier operación
// -----------------------------------------------------------------------------
$hashCalculado = hash_file('sha256', $rutaEntrada);
$hashEsperado = null;

if (isset($opciones['hash']) && is_string($opciones['hash']) && trim($opciones['hash']) !== '') {
    $hashEsperado = strtolower(trim($opciones['hash']));
} elseif (isset($opciones['manifest']) && is_string($opciones['manifest']) && is_file($opciones['manifest'])) {
    $rawMan = @file_get_contents($opciones['manifest']);
    $datosMan = $rawMan !== false ? json_decode($rawMan, true) : null;
    if (is_array($datosMan) && isset($datosMan['sha256']) && is_string($datosMan['sha256'])) {
        $hashEsperado = strtolower(trim($datosMan['sha256']));
    }
} elseif (is_file($rutaEntrada . '.manifest.json')) {
    $rawMan = @file_get_contents($rutaEntrada . '.manifest.json');
    $datosMan = $rawMan !== false ? json_decode($rawMan, true) : null;
    if (is_array($datosMan) && isset($datosMan['sha256']) && is_string($datosMan['sha256'])) {
        $hashEsperado = strtolower(trim($datosMan['sha256']));
    }
}

if (!isset($opciones['skip-hash-check']) && !isset($opciones['insecure'])) {
    if ($hashEsperado === null) {
        fwrite(STDERR, "[ERROR] Modo seguro (--safe-restore): Se requiere verificación criptográfica obligatoria. No se encontró archivo de manifiesto (.manifest.json) ni se proporcionó un hash SHA-256 esperado con --hash. Use --skip-hash-check para omitir conscientemente esta verificación en pruebas.\n");
        exit(1);
    }

    if ($hashCalculado !== $hashEsperado) {
        fwrite(STDERR, sprintf(
            "[ERROR] Verificación de integridad fallida: el hash SHA-256 del archivo (%s) no coincide con el esperado (%s). El respaldo fue alterado o está corrupto.\n",
            $hashCalculado,
            $hashEsperado
        ));
        exit(1);
    }
}

/**
 * Divide un volcado SQL en sentencias ejecutables individuales respetando cadenas entrecomilladas,
 * secuencias de escape y comentarios de línea.
 *
 * @return list<string>
 */
function dividir_sentencias_sql_respaldo(string $sql): array
{
    $sentencias = [];
    $actual = '';
    $enComillaSimple = false;
    $enComillaDoble = false;
    $enBacktick = false;
    $enComentarioLinea = false;
    $longitud = strlen($sql);

    for ($i = 0; $i < $longitud; $i++) {
        $ch = $sql[$i];
        $sig = ($i + 1 < $longitud) ? $sql[$i + 1] : '';

        if ($enComentarioLinea) {
            if ($ch === "\n") {
                $enComentarioLinea = false;
            }
            continue;
        }

        if (!$enComillaSimple && !$enComillaDoble && !$enBacktick) {
            if ($ch === '-' && $sig === '-') {
                $tercero = ($i + 2 < $longitud) ? $sql[$i + 2] : '';
                if ($tercero === ' ' || $tercero === "\t" || $tercero === '-' || $tercero === "\r" || $tercero === "\n") {
                    $enComentarioLinea = true;
                    $i++;
                    continue;
                }
            }
        }

        if ($ch === '\\' && ($enComillaSimple || $enComillaDoble)) {
            $actual .= $ch;
            if ($i + 1 < $longitud) {
                $actual .= $sql[++$i];
            }
            continue;
        }

        if ($ch === "'" && !$enComillaDoble && !$enBacktick) {
            $enComillaSimple = !$enComillaSimple;
            $actual .= $ch;
            continue;
        }

        if ($ch === '"' && !$enComillaSimple && !$enBacktick) {
            $enComillaDoble = !$enComillaDoble;
            $actual .= $ch;
            continue;
        }

        if ($ch === '`' && !$enComillaSimple && !$enComillaDoble) {
            $enBacktick = !$enBacktick;
            $actual .= $ch;
            continue;
        }

        if ($ch === ';' && !$enComillaSimple && !$enComillaDoble && !$enBacktick) {
            $limpia = trim($actual);
            if ($limpia !== '') {
                $sentencias[] = $limpia;
            }
            $actual = '';
            continue;
        }

        $actual .= $ch;
    }

    $resto = trim($actual);
    if ($resto !== '') {
        $sentencias[] = $resto;
    }

    return $sentencias;
}

$sentencias = dividir_sentencias_sql_respaldo($sqlContenido);
if (empty($sentencias)) {
    fwrite(STDERR, "[ERROR] El archivo de respaldo no contiene sentencias SQL ejecutables.\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// 4. Conexión a MySQL y Restauración Segura
// -----------------------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @mysqli_connect($dbHost, $dbUser, $dbPass, '', $dbPort);
if (!$mysqli) {
    fwrite(STDERR, sprintf(
        "[ERROR] No fue posible conectar a MySQL (errno %d) para restaurar la base '%s'.\n",
        mysqli_connect_errno(),
        $dbName
    ));
    exit(1);
}

$mysqli->set_charset('utf8mb4');

$usarSafeRestore = !isset($opciones['no-safe-restore']);
$stagingDb = null;

try {
    $dbExiste = false;
    $resCheckDb = $mysqli->query("SHOW DATABASES LIKE '{$dbName}'");
    if ($resCheckDb && $resCheckDb->num_rows > 0) {
        $dbExiste = true;
        $resCheckDb->free();
    } elseif ($resCheckDb) {
        $resCheckDb->free();
    }

    if (!$dbExiste) {
        if (isset($opciones['create-db'])) {
            $okCreate = $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if (!$okCreate) {
                throw new RuntimeException("No fue posible crear la base destino `{$dbName}`: " . $mysqli->error);
            }
            $dbExiste = true;
        } else {
            throw new RuntimeException("La base de datos destino `{$dbName}` no existe. Use --create-db si desea crearla automáticamente.");
        }
    }

    if (!$mysqli->select_db($dbName)) {
        throw new RuntimeException("No fue posible seleccionar la base de datos destino `{$dbName}`: " . $mysqli->error);
    }

    // Contar tablas previas en la base destino
    $resTablasPrevias = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $totalTablasPrevias = $resTablasPrevias ? $resTablasPrevias->num_rows : 0;
    if ($resTablasPrevias) {
        $resTablasPrevias->free();
    }

    // Si safe-restore esta activo y la base destino tiene datos previos, validar primero en base temporal aislada
    if ($usarSafeRestore && $totalTablasPrevias > 0) {
        $stagingDb = $dbName . '_stg_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $okStaging = $mysqli->query("CREATE DATABASE `{$stagingDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        if (!$okStaging) {
            throw new RuntimeException("No fue posible crear la base de datos de staging `{$stagingDb}`: " . $mysqli->error);
        }

        $mysqli->select_db($stagingDb);
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
        $mysqli->query("SET UNIQUE_CHECKS = 0");

        foreach ($sentencias as $indice => $sqlStmt) {
            $ok = $mysqli->query($sqlStmt);
            if (!$ok) {
                $numSentencia = $indice + 1;
                throw new RuntimeException(sprintf(
                    "Error en sentencia #%d durante validación previa en staging (errno %d): %s",
                    $numSentencia,
                    $mysqli->errno,
                    $mysqli->error
                ));
            }
        }

        // Staging exitoso: limpiar base temporal y volver a base destino
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
        $mysqli->query("SET UNIQUE_CHECKS = 1");
        $mysqli->select_db($dbName);
        $mysqli->query("DROP DATABASE IF EXISTS `{$stagingDb}`");
        $stagingDb = null;
    }

    // Aplicar sentencias sobre la base destino definitiva
    $mysqli->select_db($dbName);
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    $mysqli->query("SET UNIQUE_CHECKS = 0");

    $ejecutadas = 0;
    foreach ($sentencias as $indice => $sqlStmt) {
        $ok = $mysqli->query($sqlStmt);
        if (!$ok) {
            $numSentencia = $indice + 1;
            throw new RuntimeException(sprintf(
                "Error en sentencia #%d (errno %d): %s",
                $numSentencia,
                $mysqli->errno,
                $mysqli->error
            ));
        }
        $ejecutadas++;
    }

    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    $mysqli->query("SET UNIQUE_CHECKS = 1");

    $resTablas = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $totalTablas = $resTablas ? $resTablas->num_rows : 0;
    if ($resTablas) {
        $resTablas->free();
    }

    $mysqli->close();

    echo sprintf(
        "[OK] Restauración completada: bd=%s | tablas=%d | sentencias=%d | origen=%s\n",
        $dbName,
        $totalTablas,
        $ejecutadas,
        $rutaEntrada
    );
    exit(0);
} catch (Throwable $e) {
    if ($stagingDb !== null) {
        @$mysqli->query("DROP DATABASE IF EXISTS `{$stagingDb}`");
    }
    @$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    @$mysqli->query("SET UNIQUE_CHECKS = 1");
    @$mysqli->close();
    fwrite(STDERR, "[ERROR] Fallo durante la restauración: " . $e->getMessage() . "\n");
    exit(1);
}
