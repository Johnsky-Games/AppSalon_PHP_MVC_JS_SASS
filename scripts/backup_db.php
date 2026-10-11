<?php

declare(strict_types=1);

/**
 * Script CLI de Respaldo Consistente de Base de Datos MySQL — AppSalon
 *
 * Uso:
 *   php scripts/backup_db.php [--db=<nombre_bd>] [--output=<ruta_archivo.sql|.sql.gz>] [--gzip]
 *
 * Características:
 * - Ejecutable exclusivamente desde CLI (`php_sapi_name() === 'cli'`).
 * - Utiliza un snapshot transaccional consistente (`REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`)
 *   sobre tablas InnoDB sin bloquear lecturas concurrentes.
 * - Exporta DDL exacto (`SHOW CREATE TABLE`) y datos (`INSERT INTO`) escapados en `utf8mb4` sin requerir
 *   binarios externos del sistema operativo (`mysqldump`), funcionando en cualquier contenedor PHP 8.2.
 * - Soporta compresión GZIP nativa (`.sql.gz` o `--gzip`) y registra hash SHA-256 de verificación.
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

$opciones = getopt('', ['db::', 'output::', 'gzip', 'help']);
if (isset($opciones['help'])) {
    echo "Uso: php scripts/backup_db.php [--db=<nombre_bd>] [--output=<ruta_salida>] [--gzip]\n";
    exit(0);
}

$dbHost = (string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbUser = (string)($_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string)($_ENV['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''));
$dbName = isset($opciones['db']) && is_string($opciones['db']) && trim($opciones['db']) !== ''
    ? trim($opciones['db'])
    : (string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'appsalon_mvc');

if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "[ERROR] Nombre de base de datos inválido.\n");
    exit(1);
}

$tz = new DateTimeZone('America/Guayaquil');
$ahora = new DateTimeImmutable('now', $tz);
$usarGzip = isset($opciones['gzip']);

$rutaSalida = isset($opciones['output']) && is_string($opciones['output']) && trim($opciones['output']) !== ''
    ? trim($opciones['output'])
    : (__DIR__ . '/../storage/backups/backup_' . $dbName . '_' . $ahora->format('Ymd_His') . ($usarGzip ? '.sql.gz' : '.sql'));

if (str_ends_with(strtolower($rutaSalida), '.gz')) {
    $usarGzip = true;
}

$directorioSalida = dirname($rutaSalida);
if (!is_dir($directorioSalida)) {
    if (!@mkdir($directorioSalida, 0775, true) && !is_dir($directorioSalida)) {
        fwrite(STDERR, "[ERROR] No se pudo crear el directorio de salida para el respaldo: {$directorioSalida}\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if (!$mysqli) {
    fwrite(STDERR, sprintf(
        "[ERROR] No fue posible conectar a MySQL (errno %d) para respaldar la base '%s'.\n",
        mysqli_connect_errno(),
        $dbName
    ));
    exit(1);
}

$mysqli->set_charset('utf8mb4');

try {
    // Iniciar snapshot transaccional consistente en InnoDB
    $mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    $mysqli->query("START TRANSACTION WITH CONSISTENT SNAPSHOT");

    $resTablas = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if (!$resTablas) {
        throw new RuntimeException('Fallo al listar tablas de la base de datos: ' . $mysqli->error);
    }

    $tablas = [];
    while ($fila = $resTablas->fetch_row()) {
        $tablas[] = (string)$fila[0];
    }
    $resTablas->free();
    sort($tablas);

    $lineas = [];
    $lineas[] = "-- ============================================================================";
    $lineas[] = "-- AppSalon SQL Backup (Snapshot Consistente InnoDB)";
    $lineas[] = "-- Base de datos origen: `" . $dbName . "`";
    $lineas[] = "-- Fecha de generación (America/Guayaquil): " . $ahora->format('Y-m-d H:i:sP');
    $lineas[] = "-- Total de tablas: " . count($tablas);
    $lineas[] = "-- ============================================================================";
    $lineas[] = "";
    $lineas[] = "SET NAMES utf8mb4;";
    $lineas[] = "SET TIME_ZONE = '+00:00';";
    $lineas[] = "SET FOREIGN_KEY_CHECKS = 0;";
    $lineas[] = "SET UNIQUE_CHECKS = 0;";
    $lineas[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
    $lineas[] = "";

    $totalFilasRespaldadas = 0;

    foreach ($tablas as $tabla) {
        $tablaEsc = str_replace('`', '``', $tabla);
        $resCreate = $mysqli->query("SHOW CREATE TABLE `{$tablaEsc}`");
        if (!$resCreate) {
            throw new RuntimeException("Fallo al obtener estructura de la tabla `{$tabla}`: " . $mysqli->error);
        }
        $filaCreate = $resCreate->fetch_assoc();
        $resCreate->free();

        $createSql = (string)($filaCreate['Create Table'] ?? array_values($filaCreate)[1] ?? '');
        if ($createSql === '') {
            throw new RuntimeException("No se pudo reconstruir el DDL de la tabla `{$tabla}`.");
        }

        $lineas[] = "-- ----------------------------------------------------------------------------";
        $lineas[] = "-- Estructura de tabla para `{$tablaEsc}`";
        $lineas[] = "-- ----------------------------------------------------------------------------";
        $lineas[] = "DROP TABLE IF EXISTS `{$tablaEsc}`;";
        $lineas[] = $createSql . ";";
        $lineas[] = "";

        $resDatos = $mysqli->query("SELECT * FROM `{$tablaEsc}`");
        if (!$resDatos) {
            throw new RuntimeException("Fallo al leer filas de la tabla `{$tabla}`: " . $mysqli->error);
        }

        $camposMeta = $resDatos->fetch_fields();
        $nombresColumnas = array_map(
            static fn(object $f): string => '`' . str_replace('`', '``', (string)$f->name) . '`',
            $camposMeta
        );
        $listaColumnasSql = implode(', ', $nombresColumnas);

        $filasTabla = 0;
        while ($fila = $resDatos->fetch_assoc()) {
            $valoresSql = [];
            foreach ($camposMeta as $meta) {
                $colName = (string)$meta->name;
                $valor = $fila[$colName];
                if ($valor === null) {
                    $valoresSql[] = 'NULL';
                } else {
                    $valoresSql[] = "'" . $mysqli->real_escape_string((string)$valor) . "'";
                }
            }
            $lineas[] = "INSERT INTO `{$tablaEsc}` ({$listaColumnasSql}) VALUES (" . implode(', ', $valoresSql) . ");";
            $filasTabla++;
            $totalFilasRespaldadas++;
        }
        $resDatos->free();

        if ($filasTabla > 0) {
            $lineas[] = "";
        }
    }

    $lineas[] = "SET FOREIGN_KEY_CHECKS = 1;";
    $lineas[] = "SET UNIQUE_CHECKS = 1;";
    $lineas[] = "-- Fin del respaldo AppSalon";
    $lineas[] = "";

    $mysqli->commit();
    $mysqli->close();

    $sqlCompleto = implode("\n", $lineas);
    $sha256Sql = hash('sha256', $sqlCompleto);

    $contenidoEscribir = $sqlCompleto;
    if ($usarGzip) {
        $comprimido = gzencode($sqlCompleto, 9);
        if ($comprimido === false) {
            throw new RuntimeException('Fallo al comprimir el volcado SQL con GZIP.');
        }
        $contenidoEscribir = $comprimido;
    }

    $archivoTmp = $rutaSalida . '.tmp.' . getmypid();
    if (@file_put_contents($archivoTmp, $contenidoEscribir, LOCK_EX) === false) {
        throw new RuntimeException("No fue posible escribir el archivo de respaldo en: {$rutaSalida}");
    }
    if (!@rename($archivoTmp, $rutaSalida)) {
        @unlink($archivoTmp);
        throw new RuntimeException("No fue posible finalizar el archivo de respaldo en: {$rutaSalida}");
    }

    echo sprintf(
        "[OK] Respaldo completado: bd=%s | tablas=%d | filas=%d | archivo=%s | bytes=%d | sha256=%s\n",
        $dbName,
        count($tablas),
        $totalFilasRespaldadas,
        $rutaSalida,
        strlen($contenidoEscribir),
        $sha256Sql
    );
    exit(0);
} catch (Throwable $e) {
    @$mysqli->rollback();
    @$mysqli->close();
    fwrite(STDERR, "[ERROR] Fallo durante el respaldo: " . $e->getMessage() . "\n");
    exit(1);
}
