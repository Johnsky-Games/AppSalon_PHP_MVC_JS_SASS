<?php

/**
 * Reversión segura de migraciones (DEF-16). Lo usa `database/migrador.php down`.
 *
 * Principio: una reversión que destruiría datos de negocio NO se ejecuta salvo que el operador lo acepte de forma
 * explícita, migración por migración (`--aceptar-perdida=<nombre>`), Y aporte un respaldo reciente y verificable
 * (`--respaldo=<ruta .sql|.sql.gz>` generado por scripts/backup_db.php con su manifiesto SHA-256). Todo se comprueba
 * ANTES de tocar nada: si falta algo, el migrador sale con código 2 y la base queda intacta.
 *
 * Cómo se detecta la pérdida:
 *  1. Si existe `NNN_nombre.guard.php` se usa: devuelve `fn(mysqli): array` con elementos
 *     `['mensaje' => string, 'tabla' => ?string]` (o simples cadenas). Lista vacía = reversión segura.
 *  2. Si no, se analiza el SQL del rollback: cada `DROP TABLE t` y cada `ALTER TABLE t ... DROP COLUMN c` se evalúa
 *     contra los datos actuales (filas en t / filas con valor en t.c). Solo se exceptúan datos efímeros declarados abajo.
 *
 * Límite honesto: ejecutar a mano un `*_rollback.sql` evita estos controles. El procedimiento soportado es siempre el migrador.
 */

/** Tablas cuyo contenido es regenerable o transitorio (contadores de intentos, semilla del mutex): perderlas no es pérdida de negocio. */
const MIGRADOR_TABLAS_EFIMERAS = ['intentos_login', 'equipo_mutex'];
/** Columnas transitorias (tokens de un solo uso, contador de versión de sesión, arrendamientos de worker). */
const MIGRADOR_COLUMNAS_EFIMERAS = [
    'usuarios.token_hash', 'usuarios.token_tipo', 'usuarios.token_expira', 'usuarios.version_sesion',
    'notificaciones_citas.worker_token', 'notificaciones_citas.lease_expires_at',
];
/** Antigüedad máxima aceptada del respaldo exigido para aceptar una pérdida. */
const MIGRADOR_RESPALDO_MAX_HORAS = 24;

function migrador_tabla_existe(mysqli $db, string $tabla): bool
{
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($tabla) . "'");
    return (bool)$r && $r->num_rows > 0;
}

function migrador_columna_existe(mysqli $db, string $tabla, string $columna): bool
{
    $r = $db->query('SHOW COLUMNS FROM `' . $tabla . "` LIKE '" . $db->real_escape_string($columna) . "'");
    return (bool)$r && $r->num_rows > 0;
}

/**
 * Análisis genérico del SQL de rollback contra los datos actuales.
 * @return array<int,array{mensaje:string,tabla:?string}>
 */
function migrador_perdida_generica(mysqli $db, string $sqlRollback): array
{
    $sql = preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $sqlRollback);
    $perdidas = [];
    foreach (explode(';', (string)$sql) as $sentencia) {
        if (preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $sentencia, $m)) {
            $tabla = $m[1];
            if (in_array($tabla, MIGRADOR_TABLAS_EFIMERAS, true) || !migrador_tabla_existe($db, $tabla)) {
                continue;
            }
            $n = (int)$db->query('SELECT COUNT(*) n FROM `' . $tabla . '`')->fetch_assoc()['n'];
            if ($n > 0) {
                $perdidas[] = ['mensaje' => "{$n} filas de `{$tabla}` se eliminan con la tabla.", 'tabla' => $tabla];
            }
        } elseif (preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?/i', $sentencia, $m)) {
            $tabla = $m[1];
            if (!migrador_tabla_existe($db, $tabla) || !preg_match_all('/DROP\s+COLUMN\s+`?(\w+)`?/i', $sentencia, $cols)) {
                continue;
            }
            foreach ($cols[1] as $columna) {
                if (in_array($tabla . '.' . $columna, MIGRADOR_COLUMNAS_EFIMERAS, true) || !migrador_columna_existe($db, $tabla, $columna)) {
                    continue;
                }
                $n = (int)$db->query('SELECT COUNT(*) n FROM `' . $tabla . '` WHERE `' . $columna . '` IS NOT NULL')->fetch_assoc()['n'];
                if ($n > 0) {
                    $perdidas[] = ['mensaje' => "{$n} valores de `{$tabla}.{$columna}` se eliminan con la columna.", 'tabla' => null];
                }
            }
        }
    }
    return $perdidas;
}

/**
 * Pérdida de datos que causaría revertir `$nombre`: guard explícito si existe, análisis genérico si no.
 * @return array<int,array{mensaje:string,tabla:?string}>
 */
function migrador_perdida(mysqli $db, string $directorio, string $nombre): array
{
    $guard = $directorio . '/' . $nombre . '.guard.php';
    if (is_file($guard)) {
        $evaluador = require $guard;
        $crudo = is_callable($evaluador) ? $evaluador($db) : [];
    } else {
        $crudo = migrador_perdida_generica($db, (string)file_get_contents($directorio . '/' . $nombre . '_rollback.sql'));
    }
    $normalizado = [];
    foreach ($crudo as $p) {
        $normalizado[] = is_array($p) ? ['mensaje' => (string)$p['mensaje'], 'tabla' => $p['tabla'] ?? null] : ['mensaje' => (string)$p, 'tabla' => null];
    }
    return $normalizado;
}

/**
 * Valida un respaldo de scripts/backup_db.php antes de aceptar una pérdida. Devuelve los motivos de rechazo (vacío = válido):
 * archivo y manifiesto presentes, SHA-256 del archivo y del SQL descomprimido, misma base, esquema = última migración
 * aplicada, antigüedad máxima, y que contenga al menos las filas actuales de las tablas en riesgo.
 *
 * @param string[] $tablasEnRiesgo
 * @return string[]
 */
function migrador_verificar_respaldo(mysqli $db, string $ruta, array $tablasEnRiesgo): array
{
    if ($ruta === '' || !is_file($ruta)) {
        return ["No existe el archivo de respaldo indicado en --respaldo ({$ruta})."];
    }
    $rutaManifiesto = $ruta . '.manifest.json';
    if (!is_file($rutaManifiesto)) {
        return ["Falta el manifiesto del respaldo ({$rutaManifiesto}); genéralo con scripts/backup_db.php sin --no-manifest."];
    }
    $m = json_decode((string)file_get_contents($rutaManifiesto), true);
    if (!is_array($m)) {
        return ['El manifiesto del respaldo no es JSON válido.'];
    }
    $errores = [];
    $contenido = (string)file_get_contents($ruta);
    if (!hash_equals((string)($m['sha256'] ?? ''), hash('sha256', $contenido))) {
        $errores[] = 'El SHA-256 del respaldo no coincide con su manifiesto (archivo alterado o truncado).';
    }
    $sql = $contenido;
    if (!empty($m['comprimido_gzip']) || str_ends_with(strtolower($ruta), '.gz')) {
        $sql = @gzdecode($contenido);
        if ($sql === false) {
            $errores[] = 'El respaldo comprimido no se puede descomprimir (archivo dañado).';
            $sql = '';
        }
    }
    if ($sql !== '' && isset($m['sha256_sql']) && !hash_equals((string)$m['sha256_sql'], hash('sha256', $sql))) {
        $errores[] = 'El SHA-256 del SQL descomprimido no coincide con el manifiesto.';
    }
    $dbActual = (string)($db->query('SELECT DATABASE() d')->fetch_assoc()['d'] ?? '');
    if (($m['database'] ?? null) !== $dbActual) {
        $errores[] = "El respaldo es de otra base de datos ('" . ($m['database'] ?? '?') . "'), no de '{$dbActual}'.";
    }
    $fecha = strtotime((string)($m['fecha'] ?? ''));
    if ($fecha === false) {
        $errores[] = 'El manifiesto no tiene una fecha válida.';
    } elseif (time() - $fecha > MIGRADOR_RESPALDO_MAX_HORAS * 3600 || $fecha - time() > 300) {
        $errores[] = 'El respaldo tiene más de ' . MIGRADOR_RESPALDO_MAX_HORAS . ' horas (o fecha futura): genera uno nuevo justo antes de revertir.';
    }
    $ultima = $db->query('SELECT migracion FROM migraciones ORDER BY id DESC LIMIT 1');
    $ultima = $ultima ? (string)($ultima->fetch_assoc()['migracion'] ?? '') : '';
    if ($ultima !== '' && ($m['schema_version'] ?? null) !== $ultima) {
        $errores[] = "El respaldo es del esquema '" . ($m['schema_version'] ?? '?') . "', pero la última migración aplicada es '{$ultima}': el respaldo es anterior.";
    }
    foreach (array_unique($tablasEnRiesgo) as $tabla) {
        if (!migrador_tabla_existe($db, $tabla)) {
            continue;
        }
        $actual = (int)$db->query('SELECT COUNT(*) n FROM `' . $tabla . '`')->fetch_assoc()['n'];
        $enRespaldo = (int)($m['filas_por_tabla'][$tabla] ?? -1);
        if ($enRespaldo < $actual) {
            $errores[] = "El respaldo tiene " . max(0, $enRespaldo) . " filas de `{$tabla}` y hay {$actual} ahora: es anterior a datos actuales.";
        }
    }
    return $errores;
}

/**
 * @param string[] $args argumentos tras `down`
 * @return array{aceptar:string[],respaldo:?string,simular:bool,hasta:?string,errores:string[]}
 */
function migrador_opciones_down(array $args): array
{
    $o = ['aceptar' => [], 'respaldo' => null, 'simular' => false, 'hasta' => null, 'errores' => []];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--aceptar-perdida=')) {
            $valor = substr($arg, strlen('--aceptar-perdida='));
            if (!preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $valor)) {
                $o['errores'][] = "Nombre de migración no válido en {$arg}.";
            } else {
                $o['aceptar'][] = $valor;
            }
        } elseif (str_starts_with($arg, '--hasta=')) {
            $valor = substr($arg, strlen('--hasta='));
            if (!preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $valor)) {
                $o['errores'][] = "Nombre de migración no válido en {$arg}.";
            } else {
                $o['hasta'] = $valor;
            }
        } elseif (str_starts_with($arg, '--respaldo=')) {
            $o['respaldo'] = substr($arg, strlen('--respaldo='));
        } elseif ($arg === '--simular') {
            $o['simular'] = true;
        } else {
            $o['errores'][] = "Opción no reconocida: {$arg}.";
        }
    }
    return $o;
}
