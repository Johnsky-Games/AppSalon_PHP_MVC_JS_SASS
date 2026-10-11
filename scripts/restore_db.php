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
/**
 * Validador unificado de esquema y manifiesto para base nueva, vacía o poblada.
 *
 * @param mysqli $mysqli
 * @param string $dbTarget
 * @param array<string, mixed>|null $manifiesto
 * @throws RuntimeException
 */
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
    if (array_key_exists('schema_version', $manifiesto)) {
        $versionDeclarada = (string)$manifiesto['schema_version'];
        if (trim($versionDeclarada) === '' || !preg_match('/^[a-zA-Z0-9_\.\-]+$/', $versionDeclarada)) {
            throw new RuntimeException("Validación de manifiesto fallida: 'schema_version' está vacío o es inválido.");
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

/**
 * Consulta todas las foreign keys activas de la base dada.
 *
 * @return list<array<string, string>>
 */
function obtener_foreign_keys_esquema(mysqli $mysqli, string $dbName): array
{
    $dbEsc = $mysqli->real_escape_string($dbName);
    $sql = "SELECT 
                kcu.TABLE_NAME,
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
                ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA 
                AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            WHERE kcu.CONSTRAINT_SCHEMA = '{$dbEsc}'
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION";

    $res = $mysqli->query($sql);
    if (!$res) {
        throw new RuntimeException("Error al consultar foreign keys de la base '{$dbName}': " . $mysqli->error);
    }
    $fks = [];
    while ($fila = $res->fetch_assoc()) {
        $fks[] = [
            'table_name' => (string)$fila['TABLE_NAME'],
            'constraint_name' => (string)$fila['CONSTRAINT_NAME'],
            'column_name' => (string)$fila['COLUMN_NAME'],
            'referenced_table_name' => (string)$fila['REFERENCED_TABLE_NAME'],
            'referenced_column_name' => (string)$fila['REFERENCED_COLUMN_NAME'],
            'update_rule' => (string)($fila['UPDATE_RULE'] ?? 'CASCADE'),
            'delete_rule' => (string)($fila['DELETE_RULE'] ?? 'CASCADE'),
        ];
    }
    $res->free();
    return $fks;
}

    // Contar tablas previas en la base destino
    $resTablasPrevias = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $totalTablasPrevias = $resTablasPrevias ? $resTablasPrevias->num_rows : 0;
    if ($resTablasPrevias) {
        $resTablasPrevias->free();
    }

    // Fase 1: Validación previa obligatoria en Staging aislado bajo --safe-restore
    // Se ejecuta tanto en bases nuevas como vacías o pobladas para asegurar integridad del volcado
    if ($usarSafeRestore) {
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

        // Ejecutar el validador unificado de esquema y manifiesto en staging
        validar_manifiesto_y_esquema($mysqli, $stagingDb, $datosMan);

        // Staging exitoso: limpiar base temporal
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
        $mysqli->query("SET UNIQUE_CHECKS = 1");
        $mysqli->select_db($dbName);
        $mysqli->query("DROP DATABASE IF EXISTS `{$stagingDb}`");
        $stagingDb = null;
    }

    // Fase 2: Resguardo previo de tablas y gestión de Foreign Keys en la base destino
    $tablasRespaldadas = [];
    $fksOriginales = [];
    $prefijoBck = '_bck_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_';

    if ($usarSafeRestore && $totalTablasPrevias > 0) {
        $resTablasActuales = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $nombresTablas = [];
        if ($resTablasActuales) {
            while ($filaT = $resTablasActuales->fetch_row()) {
                $nombresTablas[] = (string)$filaT[0];
            }
            $resTablasActuales->free();
        }

        // Obtener la definición de todas las FKs existentes antes del renombre
        $fksOriginales = obtener_foreign_keys_esquema($mysqli, $dbName);

        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($nombresTablas as $nombreTabla) {
            $nombreBck = $prefijoBck . $nombreTabla;
            $okRename = $mysqli->query("RENAME TABLE `{$nombreTabla}` TO `{$nombreBck}`");
            if ($okRename) {
                $tablasRespaldadas[$nombreTabla] = $nombreBck;
            } else {
                // Si falla el renombre preliminar, revertir renombres ya hechos
                foreach ($tablasRespaldadas as $orig => $bck) {
                    $mysqli->query("RENAME TABLE `{$bck}` TO `{$orig}`");
                }
                throw new RuntimeException("No fue posible crear respaldo transaccional de tablas previas: " . $mysqli->error);
            }
        }

        // Soltar las FKs de las tablas _bck_* para liberar sus nombres explícitos de constraint en el schema
        // evitando colisiones (ERROR 1826) cuando el backup recree las tablas originales con los mismos nombres de FK
        foreach ($fksOriginales as $fk) {
            $tablaBck = $tablasRespaldadas[$fk['table_name']] ?? null;
            if ($tablaBck !== null) {
                $sqlDropFk = "ALTER TABLE `{$tablaBck}` DROP FOREIGN KEY `{$fk['constraint_name']}`";
                $okDropFk = $mysqli->query($sqlDropFk);
                if (!$okDropFk) {
                    // Si falla soltar la FK de la tabla resguardada, revertir
                    foreach ($tablasRespaldadas as $orig => $bck) {
                        $mysqli->query("RENAME TABLE `{$bck}` TO `{$orig}`");
                    }
                    throw new RuntimeException("No fue posible liberar constraint '{$fk['constraint_name']}' en tabla de resguardo: " . $mysqli->error);
                }
            }
        }
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    // Fase 3: Aplicar sentencias sobre la base destino definitiva
    $mysqli->select_db($dbName);
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    $mysqli->query("SET UNIQUE_CHECKS = 0");

    $ejecutadas = 0;
    try {
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

        // Si se aplicaron todas las sentencias y existían tablas de resguardo `_bck_`,
        // eliminarlas ANTES de la validación final del manifiesto para que no figuren como tablas extras no declaradas
        if (!empty($tablasRespaldadas)) {
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
            foreach ($tablasRespaldadas as $orig => $bck) {
                $mysqli->query("DROP TABLE IF EXISTS `{$bck}`");
            }
        }

        // Ejecutar el validador unificado de esquema y manifiesto sobre la base destino final
        validar_manifiesto_y_esquema($mysqli, $dbName, $datosMan);
    } catch (Throwable $eEjecucion) {
        // En caso de fallo post-staging, revertir y restituir de forma estricta comprobando cada operación
        if (!empty($tablasRespaldadas)) {
            fwrite(STDERR, "[ADVERTENCIA] Error post-staging detectado. Revertiendo cambios y restaurando tablas originales desde resguardo...\n");
            $erroresRestitucion = [];
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

            $resTablasNuevas = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            if ($resTablasNuevas) {
                while ($filaTN = $resTablasNuevas->fetch_row()) {
                    $tName = (string)$filaTN[0];
                    if (!str_starts_with($tName, $prefijoBck)) {
                        $okDrop = $mysqli->query("DROP TABLE IF EXISTS `{$tName}`");
                        if (!$okDrop) {
                            $erroresRestitucion[] = "Fallo al eliminar tabla parcial '{$tName}': " . $mysqli->error;
                        }
                    }
                }
                $resTablasNuevas->free();
            }

            foreach ($tablasRespaldadas as $orig => $bck) {
                $okRen = $mysqli->query("RENAME TABLE `{$bck}` TO `{$orig}`");
                if (!$okRen) {
                    $erroresRestitucion[] = "Fallo al restaurar nombre de '{$bck}' a '{$orig}': " . $mysqli->error;
                }
            }

            // Recrear cada una de las Foreign Keys originales que fueron retiradas
            foreach ($fksOriginales as $fk) {
                $sqlAddFk = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`%s`) ON UPDATE %s ON DELETE %s",
                    $fk['table_name'],
                    $fk['constraint_name'],
                    $fk['column_name'],
                    $fk['referenced_table_name'],
                    $fk['referenced_column_name'],
                    $fk['update_rule'],
                    $fk['delete_rule']
                );
                $okAdd = $mysqli->query($sqlAddFk);
                if (!$okAdd) {
                    $erroresRestitucion[] = "Fallo al recrear constraint '{$fk['constraint_name']}' en '{$fk['table_name']}': " . $mysqli->error;
                }
            }
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

            if (!empty($erroresRestitucion)) {
                fwrite(STDERR, "[CRÍTICO] La restitución de la base destino quedó incompleta. Errores:\n - " . implode("\n - ", $erroresRestitucion) . "\n");
                throw new RuntimeException("Fallo en recuperación de base destino: " . implode("; ", $erroresRestitucion), 0, $eEjecucion);
            }

            fwrite(STDERR, "[INFO] Base destino restaurada exitosamente a su estado anterior (esquema, datos y claves foráneas verificados).\n");
        }
        throw $eEjecucion;
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
