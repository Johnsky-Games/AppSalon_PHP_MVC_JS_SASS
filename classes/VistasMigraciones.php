<?php
namespace Classes;

/**
 * Vistas de lectura definidas por las migraciones (SQL de confianza del repositorio, nunca del archivo de respaldo).
 * Cada una se escribe como `CREATE OR REPLACE SQL SECURITY DEFINER VIEW nombre AS SELECT ...;` sin `;` internos, y se retira con
 * `DROP VIEW [IF EXISTS] nombre;`. Se conservan las definiciones POR MIGRACIÓN: un respaldo histórico que solo tenga aplicada una
 * migración anterior recupera la definición de esa época; con varias aplicadas rige, para cada vista, la última acción
 * (crear o redefinir, o retirarla) y se crean en orden de dependencias.
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
     * las que una migración aplicada retiró, y ordenadas para que cada vista se cree después de las vistas que usa.
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
        $orden = [];
        $estado = [];
        $visitar = static function (string $vista, array $pila) use (&$visitar, &$orden, &$estado, $vigentes): void {
            if (($estado[$vista] ?? 0) === 2) {
                return;
            }
            if (($estado[$vista] ?? 0) === 1) {
                throw new \RuntimeException('Dependencia circular entre vistas: ' . implode(' → ', [...$pila, $vista]));
            }
            $estado[$vista] = 1;
            foreach (array_keys($vigentes) as $otra) {
                if ($otra !== $vista && preg_match('/\b' . preg_quote($otra, '/') . '\b/', preg_replace('/^[^;]*?\bVIEW\s+[A-Za-z0-9_]+\s+AS\b/is', '', $vigentes[$vista]) ?? '')) {
                    $visitar($otra, [...$pila, $vista]);
                }
            }
            $estado[$vista] = 2;
            $orden[] = $vigentes[$vista];
        };
        foreach (array_keys($vigentes) as $vista) {
            $visitar($vista, []);
        }
        return $orden;
    }

    /** Recrea en la base seleccionada las vistas de las migraciones aplicadas en ella. @return int sentencias ejecutadas */
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
        $sentencias = self::sentencias($directorio, $aplicadas);
        foreach ($sentencias as $sql) {
            $db->query($sql);
        }
        return count($sentencias);
    }
}
