<?php
namespace Classes;

/**
 * Vistas de lectura definidas por las migraciones (SQL de confianza del repositorio, nunca del archivo de respaldo).
 * Cada una se escribe como `CREATE OR REPLACE SQL SECURITY DEFINER VIEW nombre AS SELECT ...;` sin `;` internos, y se retira con
 * `DROP VIEW [IF EXISTS] nombre;`. Se conservan las definiciones POR MIGRACIÓN: un respaldo histórico que solo tenga aplicada una
 * migración anterior recupera la definición de esa época; con varias aplicadas rige, para cada vista, la última acción
 * (crear o redefinir, o retirarla).
 */
final class VistasMigraciones
{
    private const CREAR = '/CREATE\s+OR\s+REPLACE\s+SQL\s+SECURITY\s+DEFINER\s+VIEW\s+([A-Za-z0-9_]+)\s+AS\s[^;]*;/i';
    private const RETIRAR = '/DROP\s+VIEW\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*;/i';

    /** @return list<string> archivos de migración (sin rollback) en orden */
    private static function archivos(string $directorio): array
    {
        $archivos = glob(rtrim($directorio, '/') . '/*.sql') ?: [];
        sort($archivos, SORT_STRING);
        return array_values(array_filter($archivos, static fn(string $a): bool => !str_ends_with($a, '_rollback.sql')));
    }

    /**
     * @return list<array{migracion:string,accion:string,vista:string,sql:string}> acciones sobre vistas en orden de migración y de
     *         aparición en el archivo; accion = 'crear' | 'retirar'
     */
    public static function acciones(string $directorio): array
    {
        $acciones = [];
        foreach (self::archivos($directorio) as $archivo) {
            $contenido = (string)file_get_contents($archivo);
            $hallazgos = [];
            if (preg_match_all(self::CREAR, $contenido, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $c) {
                    $hallazgos[] = [$c[0][1], 'crear', $c[1][0], rtrim($c[0][0], ';')];
                }
            }
            if (preg_match_all(self::RETIRAR, $contenido, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $c) {
                    $hallazgos[] = [$c[0][1], 'retirar', $c[1][0], ''];
                }
            }
            usort($hallazgos, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($hallazgos as [, $accion, $vista, $sql]) {
                $acciones[] = ['migracion' => basename($archivo, '.sql'), 'accion' => $accion, 'vista' => $vista, 'sql' => $sql];
            }
        }
        return $acciones;
    }

    /** @return list<string> nombres de vista que alguna migración sabe construir */
    public static function nombres(string $directorio): array
    {
        $nombres = [];
        foreach (self::acciones($directorio) as $a) {
            if ($a['accion'] === 'crear') {
                $nombres[$a['vista']] = true;
            }
        }
        return array_keys($nombres);
    }

    /**
     * Sentencias para recrear las vistas de una base restaurada, que ya tiene el esquema final del volcado: para cada vista, solo su
     * definición vigente entre las migraciones aplicadas (una definición antigua podría apuntar a una columna renombrada), omitiendo
     * las que una migración aplicada retiró. El orden entre vistas que dependen unas de otras lo resuelve `ejecutar()`.
     *
     * @param array<string,bool> $aplicadas migraciones aplicadas (nombre => true)
     * @return list<string> sentencias a ejecutar, una por vista vigente
     */
    public static function sentencias(string $directorio, array $aplicadas): array
    {
        $vigentes = [];
        foreach (self::acciones($directorio) as $a) {
            if (!isset($aplicadas[$a['migracion']])) {
                continue;
            }
            if ($a['accion'] === 'retirar') {
                unset($vigentes[$a['vista']]);
            } else {
                $vigentes[$a['vista']] = $a['sql'];
            }
        }
        return array_values($vigentes);
    }

    /**
     * Ejecuta las sentencias reintentando las que fallan hasta que no haya progreso: una vista que usa otra falla mientras esa no exista y
     * se crea en la ronda siguiente. El motor decide la dependencia real (sin interpretar el texto SQL); si queda alguna sin crear, error
     * con el motivo de la base.
     *
     * @param list<string> $sentencias
     * @param callable(string):void $ejecutar lanza una excepción si la sentencia falla
     * @return int sentencias ejecutadas
     */
    public static function ejecutar(array $sentencias, callable $ejecutar): int
    {
        $pendientes = $sentencias;
        while ($pendientes) {
            $siguientes = [];
            $ultimoError = '';
            foreach ($pendientes as $sql) {
                try {
                    $ejecutar($sql);
                } catch (\Throwable $e) {
                    $siguientes[] = $sql;
                    $ultimoError = $e->getMessage();
                }
            }
            if (count($siguientes) === count($pendientes)) {
                throw new \RuntimeException('No se pudieron recrear las vistas (' . count($siguientes) . ' pendientes): ' . $ultimoError);
            }
            $pendientes = $siguientes;
        }
        return count($sentencias);
    }

    /** Recrea en la base seleccionada las vistas de las migraciones aplicadas en ella. @return int vistas recreadas */
    public static function recrear(\mysqli $db, string $directorio): int
    {
        if ($db->query("SHOW TABLES LIKE 'migraciones'")->num_rows === 0) {
            return 0;
        }
        $aplicadas = [];
        $res = $db->query('SELECT migracion FROM migraciones');
        while ($fila = $res->fetch_row()) {
            $aplicadas[(string)$fila[0]] = true;
        }
        $res->free();
        return self::ejecutar(self::sentencias($directorio, $aplicadas), static function (string $sql) use ($db): void {
            if ($db->query($sql) === false) { // la aplicación corre con mysqli_report(OFF); con STRICT la propia consulta lanza
                throw new \RuntimeException($db->error);
            }
        });
    }
}
