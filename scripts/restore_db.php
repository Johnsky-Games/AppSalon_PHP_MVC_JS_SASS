<?php

declare(strict_types=1);

/**
 * Script CLI de Restauración Segura de Base de Datos MySQL — AppSalon
 *
 * Uso:
 *   php scripts/restore_db.php --input=<ruta_archivo.sql|.sql.gz> [--db=<nombre_bd>] [--create-db]
 *
 * Características:
 * - Ejecutable exclusivamente desde CLI (`php_sapi_name() === 'cli'`).
 * - Soporta archivos `.sql` en texto plano y `.sql.gz` comprimidos con GZIP.
 * - Analiza y ejecuta cada sentencia SQL verificando `errno === 0` para impedir restauraciones
 *   silenciosamente truncadas o corruptas.
 * - Restaura `FOREIGN_KEY_CHECKS = 1` y `UNIQUE_CHECKS = 1` al finalizar.
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

$opciones = getopt('', ['input:', 'db::', 'create-db', 'help']);
if (isset($opciones['help'])) {
    echo "Uso: php scripts/restore_db.php --input=<archivo.sql|.sql.gz> [--db=<nombre_bd>] [--create-db]\n";
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

try {
    if (isset($opciones['create-db'])) {
        $okCreate = $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        if (!$okCreate) {
            throw new RuntimeException("No fue posible crear o verificar la base destino `{$dbName}`: " . $mysqli->error);
        }
    }

    if (!$mysqli->select_db($dbName)) {
        throw new RuntimeException("La base de datos destino `{$dbName}` no existe o no es accesible: " . $mysqli->error);
    }

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
    @$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    @$mysqli->query("SET UNIQUE_CHECKS = 1");
    @$mysqli->close();
    fwrite(STDERR, "[ERROR] Fallo durante la restauración: " . $e->getMessage() . "\n");
    exit(1);
}
