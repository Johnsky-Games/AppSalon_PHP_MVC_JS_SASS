<?php

declare(strict_types=1);

/**
 * Script CLI de Restauración Segura de Base de Datos MySQL — AppSalon
 *
 * Uso:
 *   php scripts/restore_db.php --input=<archivo.sql|.sql.gz> --db=<base_nueva_o_vacia> [--hash=<sha256>] [--manifest=<ruta>] [--create-db] [--safe-restore]
 *
 * Características de Integridad y Resiliencia:
 * - Ejecutable exclusivamente desde CLI (`php_sapi_name() === 'cli'`).
 * - Soporta archivos `.sql` en texto plano y `.sql.gz` comprimidos con GZIP.
 * - Verificación criptográfica obligatoria: valida el hash SHA-256 del archivo contra el parámetro `--hash`,
 *   el archivo `--manifest`, o el manifiesto adyacente `.manifest.json` antes de ejecutar cualquier sentencia DDL.
 * - Detección estricta de truncamiento y validación sintáctica previa antes de aplicar cambios a la base destino.
 * - Importa únicamente en una base nueva o vacía indicada explícitamente con --db.
 * - Valida en staging antes de importar. Nunca renombra ni elimina tablas originales.
 * - Un destino nuevo incompleto se conserva para diagnóstico si falla la importación final.
 * - Repone los triggers de protección desde las migraciones locales aplicadas; nunca ejecuta triggers del dump.
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
    echo "Uso: php scripts/restore_db.php --input=<archivo.sql|.sql.gz> --db=<base_nueva_o_vacia> [--hash=<sha256>] [--manifest=<ruta>] [--create-db] [--safe-restore]\n";
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
if (!isset($opciones['db']) || !is_string($opciones['db']) || trim($opciones['db']) === '') {
    fwrite(STDERR, "[ERROR] Es obligatorio indicar una base nueva o vacía con --db.\n");
    exit(1);
}
if (isset($opciones['no-safe-restore']) || isset($opciones['skip-hash-check'])) {
    fwrite(STDERR, "[ERROR] No se permite omitir la validación de una restauración.\n");
    exit(1);
}

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
$datosMan = null;

// Intentar cargar siempre el manifiesto si se especificó --manifest o si existe .manifest.json adyacente
$rutaMan = null;
if (isset($opciones['manifest']) && is_string($opciones['manifest']) && is_file($opciones['manifest'])) {
    $rutaMan = $opciones['manifest'];
} elseif (is_file($rutaEntrada . '.manifest.json')) {
    $rutaMan = $rutaEntrada . '.manifest.json';
}

if ($rutaMan !== null) {
    $rawMan = @file_get_contents($rutaMan);
    $datosMan = $rawMan !== false ? json_decode($rawMan, true) : null;
    if (!is_array($datosMan)) {
        fwrite(STDERR, "[ERROR] Manifiesto ilegible o JSON inválido.\n");
        exit(1);
    }
    if (is_array($datosMan) && isset($datosMan['sha256']) && is_string($datosMan['sha256'])) {
        $hashEsperado = strtolower(trim($datosMan['sha256']));
    }
}

if (isset($opciones['hash']) && is_string($opciones['hash']) && trim($opciones['hash']) !== '') {
    $hashParam = strtolower(trim($opciones['hash']));
    // Si ya teníamos hash del manifiesto y no coincide con el parámetro --hash, abortar por inconsistencia
    if ($hashEsperado !== null && $hashEsperado !== $hashParam) {
        fwrite(STDERR, sprintf(
            "[ERROR] Verificación de integridad fallida: el hash provisto en --hash (%s) no coincide con el declarado en el manifiesto (%s).\n",
            $hashParam,
            $hashEsperado
        ));
        exit(1);
    }
    $hashEsperado = $hashParam;
}

if (!isset($opciones['skip-hash-check']) && !isset($opciones['insecure'])) {
    if ($hashEsperado === null) {
        fwrite(STDERR, "[ERROR] Modo seguro (--safe-restore): Se requiere verificación criptográfica obligatoria. No se encontró archivo de manifiesto (.manifest.json) ni se proporcionó un hash SHA-256 esperado con --hash. Proporcione --hash o un manifiesto válido.\n");
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


function validar_manifiesto_y_esquema(mysqli $mysqli, string $dbTarget, ?array $manifiesto): void
{
    if (!$mysqli->select_db($dbTarget)) {
        throw new RuntimeException("No fue posible seleccionar la base de datos '{$dbTarget}' para validación: " . $mysqli->error);
    }

    $resTablas = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if (!$resTablas) {
        throw new RuntimeException("Error al listar las tablas de la base '{$dbTarget}': " . $mysqli->error);
    }

    $tablasReales = [];
    while ($fila = $resTablas->fetch_row()) {
        $tablasReales[] = (string)$fila[0];
    }
    $resTablas->free();

    if ($manifiesto === null) {
        return;
    }

    // 1. Validar versión de esquema si está declarada
    if (array_key_exists('schema_version', $manifiesto) && in_array('migraciones', $tablasReales, true)) {
        $versionDeclarada = (string)$manifiesto['schema_version'];
        if (trim($versionDeclarada) === '' || !preg_match('/^[a-zA-Z0-9_\.\-]+$/', $versionDeclarada)) {
            throw new RuntimeException("Validación de manifiesto fallida: 'schema_version' está vacío o es inválido.");
        }
        $resVersion = $mysqli->query('SELECT migracion FROM migraciones ORDER BY id DESC LIMIT 1');
        if (!$resVersion) {
            throw new RuntimeException('No fue posible comprobar la versión de migraciones: ' . $mysqli->error);
        }
        $versionReal = (string)($resVersion->fetch_assoc()['migracion'] ?? 'sin_migraciones');
        $resVersion->free();
        if ($versionReal !== $versionDeclarada) {
            throw new RuntimeException('schema_version no coincide con las migraciones restauradas.');
        }
    }

    // 2. Extraer conjunto de tablas declaradas
    $tablasEsperadas = [];
    if (isset($manifiesto['tablas']) && is_array($manifiesto['tablas'])) {
        foreach ($manifiesto['tablas'] as $clave => $valor) {
            $nom = is_string($clave) && !is_numeric($clave) ? $clave : (string)$valor;
            $tablasEsperadas[] = $nom;
        }
    }

    // Si hay tablas declaradas, comprobar conjunto exacto (ni faltan ni sobran)
    if (!empty($tablasEsperadas)) {
        $tablasFaltantes = array_diff($tablasEsperadas, $tablasReales);
        if (!empty($tablasFaltantes)) {
            throw new RuntimeException(sprintf(
                "Validación de manifiesto fallida en '%s': faltan las siguientes tablas declaradas: %s.",
                $dbTarget,
                implode(', ', $tablasFaltantes)
            ));
        }

        $tablasExtras = array_diff($tablasReales, $tablasEsperadas);
        if (!empty($tablasExtras)) {
            throw new RuntimeException(sprintf(
                "Validación de manifiesto fallida en '%s': se encontraron tablas adicionales no declaradas: %s.",
                $dbTarget,
                implode(', ', $tablasExtras)
            ));
        }
    }

    // 3. Validar total_tablas si está declarado
    if (isset($manifiesto['total_tablas']) && is_numeric($manifiesto['total_tablas'])) {
        $totalEsperado = (int)$manifiesto['total_tablas'];
        $totalReal = count($tablasReales);
        if ($totalReal !== $totalEsperado) {
            throw new RuntimeException(sprintf(
                "Validación de manifiesto fallida en '%s': total_tablas es %d pero el manifiesto declara %d.",
                $dbTarget,
                $totalReal,
                $totalEsperado
            ));
        }
    }

    // 4. Validar filas por tabla
    if (isset($manifiesto['filas_por_tabla']) && is_array($manifiesto['filas_por_tabla'])) {
        foreach ($manifiesto['filas_por_tabla'] as $tabla => $filasEsperadas) {
            $tablaStr = (string)$tabla;
            if (!in_array($tablaStr, $tablasReales, true)) {
                throw new RuntimeException("Validación de manifiesto fallida: la tabla '{$tablaStr}' requerida para comprobación de filas no existe en '{$dbTarget}'.");
            }
            $tablaEsc = str_replace('`', '``', $tablaStr);
            $resCount = $mysqli->query("SELECT COUNT(*) AS total FROM `{$tablaEsc}`");
            if (!$resCount) {
                throw new RuntimeException("Error al consultar el conteo de filas en la tabla '{$tablaStr}': " . $mysqli->error);
            }
            $filasReales = (int)($resCount->fetch_assoc()['total'] ?? 0);
            $resCount->free();

            if ($filasReales !== (int)$filasEsperadas) {
                throw new RuntimeException(sprintf(
                    "Validación de manifiesto fallida en '%s': la tabla '%s' tiene %d filas pero el manifiesto declara %d.",
                    $dbTarget,
                    $tablaStr,
                    $filasReales,
                    (int)$filasEsperadas
                ));
            }
        }
    }

    // 5. Validar total_filas
    if (isset($manifiesto['total_filas']) && is_numeric($manifiesto['total_filas'])) {
        $sumaFilas = 0;
        foreach ($tablasReales as $t) {
            $tEsc = str_replace('`', '``', $t);
            $resCnt = $mysqli->query("SELECT COUNT(*) AS total FROM `{$tEsc}`");
            if (!$resCnt) {
                throw new RuntimeException("Error al consultar el conteo de filas en '{$t}': " . $mysqli->error);
            }
            $sumaFilas += (int)($resCnt->fetch_assoc()['total'] ?? 0);
            $resCnt->free();
        }
        $totalFilasEsperado = (int)$manifiesto['total_filas'];
        if ($sumaFilas !== $totalFilasEsperado) {
            throw new RuntimeException(sprintf(
                "Validación de manifiesto fallida en '%s': total_filas es %d pero el manifiesto declara %d.",
                $dbTarget,
                $sumaFilas,
                $totalFilasEsperado
            ));
        }
    }
}



/** El manifiesto (si existe) no puede declarar una vista que este repositorio no sabe construir. Se comprueba antes de tocar nada. */
function validar_vistas_del_manifiesto(?array $manifiesto): void
{
    $nombres = is_array($manifiesto['vistas'] ?? null) ? $manifiesto['vistas'] : [];
    $conocidas = \Classes\VistasMigraciones::nombres(__DIR__ . '/../database/migrations');
    foreach ($nombres as $nombre) {
        if (!is_string($nombre) || !in_array($nombre, $conocidas, true)) {
            throw new RuntimeException('El respaldo declara la vista ' . (is_string($nombre) ? $nombre : '?') . ' y este repositorio no tiene su definición: no se restaura nada.');
        }
    }
}

/** Accept only the portable table dump emitted by backup_db.php. */
function validar_sentencia_respaldo(string $sql): void
{
    // Remove data literals for inspection; retain quoted identifiers.
    $estructura = \Classes\SqlRespaldo::estructura($sql);
    if (preg_match('/\/\*|\b(?:USE|SCHEMA|TRIGGER|PROCEDURE|FUNCTION|EVENT|OUTFILE|DUMPFILE|LOAD_FILE)\b/i', $estructura)
        || preg_match('/(?:`[^`]+`|[A-Za-z_]\w*)\s*\.\s*(?:`[^`]+`|[A-Za-z_]\w*)/', $estructura)) {
        throw new RuntimeException('Sentencia no admitida: el respaldo no puede cambiar de base ni ejecutar operaciones externas.');
    }
    if (preg_match("/^SET\\s+(?:NAMES\\s+utf8mb4|TIME_ZONE\\s*=\\s*'\\+00:00'|FOREIGN_KEY_CHECKS\\s*=\\s*[01]|UNIQUE_CHECKS\\s*=\\s*[01]|SQL_MODE\\s*=\\s*'NO_AUTO_VALUE_ON_ZERO')\\s*$/i", $sql)) {
        return;
    }
    if (preg_match('/\b(?:CONNECTION|DIRECTORY)\b|\bENGINE\s*=\s*(?!InnoDB\b)\w+/i', $estructura)) {
        throw new RuntimeException('Sentencia no admitida: no se permiten motores externos ni rutas del sistema.');
    }
    if (preg_match('/^DROP/i', $estructura)) {
        if (!preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+(?:`[A-Za-z0-9_]+`|[A-Za-z_]\w*)\s*$/i', $estructura)) {
            throw new RuntimeException('Sentencia no admitida: DROP solo permite una tabla local.');
        }
        return;
    }
    if (!preg_match('/^(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO)\s+(?:`[A-Za-z0-9_]+`|[A-Za-z_]\w*)(?=\s|\()/i', $estructura)) {
        throw new RuntimeException('Sentencia no admitida: solo se restauran tablas del formato de backup de AppSalon.');
    }
}

$mysqli = null;
$stagingDb = null;
$lockTomado = false;
try {
    $sentencias = dividir_sentencias_sql_respaldo($sqlContenido);
    if (!$sentencias) {
        throw new RuntimeException('El archivo no contiene sentencias SQL ejecutables.');
    }
    foreach ($sentencias as $sql) {
        validar_sentencia_respaldo($sql);
    }
    validar_vistas_del_manifiesto($datosMan);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, '', $dbPort);
    $mysqli->set_charset('utf8mb4');
    $mysqli->query("SET SESSION SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
    // Serializes invocations of this CLI for the same target; does not replace maintenance coordination.
    $stmtLock = $mysqli->prepare('SELECT GET_LOCK(?, 0) AS adquirido');
    $lockName = 'appsalon_restore_' . substr(hash('sha256', $dbName), 0, 40);
    $stmtLock->bind_param('s', $lockName);
    $stmtLock->execute();
    $lockTomado = (int)$stmtLock->get_result()->fetch_assoc()['adquirido'] === 1;
    $stmtLock->close();
    if (!$lockTomado) {
        throw new RuntimeException('Otra restauración está usando este destino.');
    }
    $stmtExists = $mysqli->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmtExists->bind_param('s', $dbName);
    $stmtExists->execute();
    $existe = $stmtExists->get_result()->num_rows === 1;
    $stmtExists->close();
    if ($existe) {
        $mysqli->select_db($dbName);
        if ($mysqli->query('SHOW FULL TABLES')->num_rows > 0) {
            throw new RuntimeException('Destino poblado: se rechaza el reemplazo. Restaure en una base nueva o vacía; los originales permanecen intactos.');
        }
    } elseif (!isset($opciones['create-db'])) {
        throw new RuntimeException('La base destino no existe. Use --create-db con un nombre nuevo.');
    }
    $stagingDb = 'appsalon_restore_stg_' . bin2hex(random_bytes(8));
    $mysqli->query("CREATE DATABASE `{$stagingDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysqli->select_db($stagingDb);
    foreach ($sentencias as $indice => $sql) {
        try {
            $mysqli->query($sql);
        } catch (Throwable $error) {
            throw new RuntimeException('Error en sentencia #' . ($indice + 1) . ' durante staging: ' . $error->getMessage(), 0, $error);
        }
    }
    \Classes\VistasMigraciones::recrear($mysqli, __DIR__ . '/../database/migrations');
    validar_manifiesto_y_esquema($mysqli, $stagingDb, $datosMan);
    \Classes\ProteccionesRestauradas::aplicar($mysqli);
    // Recheck immediately before any destination DDL. Existing data is never altered.
    if ($existe) {
        $mysqli->select_db($dbName);
        if ($mysqli->query('SHOW FULL TABLES')->num_rows > 0) {
            throw new RuntimeException('Destino poblado durante la validación; no se importó ningún dato.');
        }
    } else {
        $mysqli->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $mysqli->select_db($dbName);
    }
    foreach ($sentencias as $indice => $sql) {
        try {
            $mysqli->query($sql);
        } catch (Throwable $error) {
            throw new RuntimeException('Error en sentencia #' . ($indice + 1) . ' del destino nuevo. Se conserva para diagnóstico; no hubo reemplazo de originales: ' . $error->getMessage(), 0, $error);
        }
    }
    \Classes\VistasMigraciones::recrear($mysqli, __DIR__ . '/../database/migrations');
    validar_manifiesto_y_esquema($mysqli, $dbName, $datosMan);
    $protecciones=\Classes\ProteccionesRestauradas::aplicar($mysqli);
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
    $mysqli->query('SET UNIQUE_CHECKS = 1');
    $mysqli->query("DROP DATABASE `{$stagingDb}`");
    $stagingDb = null;
    echo "[OK] Restauración completada: base nueva/vacía {$dbName}; protecciones versionadas={$protecciones}. Verifique antes de cambiar la conexión de la aplicación.\n";
    $exitCode = 0;
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($mysqli instanceof mysqli) {
        if ($stagingDb !== null) {
            try {
                $mysqli->query("DROP DATABASE `{$stagingDb}`");
            } catch (Throwable $cleanupError) {
                fwrite(STDERR, "[ERROR] Staging retenido para limpieza manual: {$stagingDb}: " . $cleanupError->getMessage() . "\n");
                $exitCode = 1;
            }
        }
        $mysqli->close(); // Releases GET_LOCK even after failure.
    }
}
exit($exitCode);
