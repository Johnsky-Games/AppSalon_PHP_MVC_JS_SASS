<?php

declare(strict_types=1);

/**
 * Script CLI de Respaldo Consistente de Base de Datos MySQL — AppSalon
 *
 * Uso:
 *   php scripts/backup_db.php [--db=<nombre_bd>] [--output=<ruta_archivo.sql|.sql.gz>] [--gzip] [--manifest=<ruta>] [--no-manifest]
 *
 * Características y Objetos Incluidos:
 * - Ejecutable exclusivamente desde CLI (`php_sapi_name() === 'cli'`).
 * - Incluye todas las tablas del esquema migrado (esquema base + todas las migraciones aplicadas; 35 tablas con la migración 018):
 *   (se descubren con SHOW FULL TABLES: no hay lista fija que mantener).
 * - Esquemas DDL completos (`DROP TABLE IF EXISTS`, `CREATE TABLE`).
 * - Inserciones de datos (`INSERT INTO`) escapadas en juego de caracteres `utf8mb4`.
 * - Snapshot transaccional consistente InnoDB (`REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`)
 *   sin bloquear lecturas concurrentes ni requerir binarios externos como `mysqldump`.
 * - Soporta compresión GZIP nativa (`.sql.gz` o `--gzip`).
 * - Genera un manifiesto verificable adyacente (`.manifest.json`) con fecha ISO 8601, timezone `America/Guayaquil`,
 *   conteo de tablas, desglose de filas por tabla, versión de esquema y hashes criptográficos SHA-256.
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

$opciones = getopt('', ['db::', 'output::', 'gzip', 'manifest::', 'no-manifest', 'help']);
if (isset($opciones['help'])) {
    echo "Uso: php scripts/backup_db.php [--db=<nombre_bd>] [--output=<ruta_salida>] [--gzip] [--manifest=<ruta>] [--no-manifest]\n";
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

$rutaManifiesto = null;
if (!isset($opciones['no-manifest'])) {
    $rutaManifiesto = isset($opciones['manifest']) && is_string($opciones['manifest']) && trim($opciones['manifest']) !== ''
        ? trim($opciones['manifest'])
        : ($rutaSalida . '.manifest.json');
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

    // Detectar version del esquema a partir de la tabla migraciones
    $schemaVersion = '008';
    $resMig = $mysqli->query("SHOW TABLES LIKE 'migraciones'");
    if ($resMig && $resMig->num_rows > 0) {
        $resMig->free();
        $resUltimaMig = $mysqli->query("SELECT migracion FROM migraciones ORDER BY id DESC LIMIT 1");
        if ($resUltimaMig && $rowMig = $resUltimaMig->fetch_assoc()) {
            $schemaVersion = (string)($rowMig['migracion'] ?? '008');
            $resUltimaMig->free();
        }
    } elseif ($resMig) {
        $resMig->free();
    }

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

    // Vistas (modelo de lectura bi_*, migración 057): no guardan datos, así que el volcado solo registra sus nombres en el manifiesto.
    // Su definición NO viaja en el archivo (no es SQL de confianza): al restaurar se recrean desde las migraciones del repositorio.
    $resVistas = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
    if (!$resVistas) {
        throw new RuntimeException('Fallo al listar vistas de la base de datos: ' . $mysqli->error);
    }
    $vistas = [];
    while ($fila = $resVistas->fetch_row()) {
        $vistas[] = (string)$fila[0];
    }
    $resVistas->free();
    sort($vistas);

    $lineas = [];
    $lineas[] = "-- ============================================================================";
    $lineas[] = "-- AppSalon SQL Backup (Snapshot Consistente InnoDB)";
    $lineas[] = "-- Base de datos origen: `" . $dbName . "`";
    $lineas[] = "-- Fecha de generación (America/Guayaquil): " . $ahora->format('Y-m-d H:i:sP');
    $lineas[] = "-- Versión de esquema detectada: " . $schemaVersion;
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
    $filasPorTabla = [];

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
        $filasPorTabla[$tabla] = $filasTabla;

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

    $sha256Archivo = hash('sha256', $contenidoEscribir);

    // Generar manifiesto JSON verificable si esta habilitado
    if ($rutaManifiesto !== null) {
        $manifiesto = [
            'version' => '1.0',
            'fecha' => $ahora->format('c'),
            'timezone' => 'America/Guayaquil',
            'database' => $dbName,
            'archivo' => basename($rutaSalida),
            'tamano_bytes' => strlen($contenidoEscribir),
            'sha256' => $sha256Archivo,
            'sha256_sql' => $sha256Sql,
            'comprimido_gzip' => $usarGzip,
            'total_tablas' => count($tablas),
            'total_filas' => $totalFilasRespaldadas,
            'schema_version' => $schemaVersion,
            'tablas' => $tablas,
            'vistas' => $vistas,
            'filas_por_tabla' => $filasPorTabla,
        ];

        $jsonManifiesto = json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonManifiesto === false) {
            throw new RuntimeException('Fallo al codificar el manifiesto de respaldo a JSON.');
        }

        $manifiestoTmp = $rutaManifiesto . '.tmp.' . getmypid();
        if (@file_put_contents($manifiestoTmp, $jsonManifiesto . "\n", LOCK_EX) === false) {
            throw new RuntimeException("No fue posible escribir el archivo de manifiesto en: {$rutaManifiesto}");
        }
        if (!@rename($manifiestoTmp, $rutaManifiesto)) {
            @unlink($manifiestoTmp);
            throw new RuntimeException("No fue posible finalizar el archivo de manifiesto en: {$rutaManifiesto}");
        }
    }

    echo sprintf(
        "[OK] Respaldo completado: bd=%s | tablas=%d | filas=%d | archivo=%s | bytes=%d | sha256=%s%s\n",
        $dbName,
        count($tablas),
        $totalFilasRespaldadas,
        $rutaSalida,
        strlen($contenidoEscribir),
        $sha256Archivo,
        $rutaManifiesto !== null ? " | manifiesto={$rutaManifiesto}" : ""
    );
    exit(0);
} catch (Throwable $e) {
    @$mysqli->rollback();
    @$mysqli->close();
    fwrite(STDERR, "[ERROR] Fallo durante el respaldo: " . $e->getMessage() . "\n");
    exit(1);
}
