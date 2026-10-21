<?php
namespace Classes;

/**
 * Vistas de lectura definidas por las migraciones (SQL de confianza del repositorio, nunca del archivo de respaldo).
 * Cada una se escribe como `CREATE OR REPLACE SQL SECURITY DEFINER VIEW nombre AS SELECT ...;` sin `;` internos.
 * Se conservan las definiciones POR MIGRACIÓN y en orden: una migración posterior puede redefinir una vista, y un respaldo
 * histórico que solo tenga la anterior aplicada debe recuperar la definición de esa época.
 */
final class VistasMigraciones
{
    private const PATRON = '/CREATE\s+OR\s+REPLACE\s+SQL\s+SECURITY\s+DEFINER\s+VIEW\s+([A-Za-z0-9_]+)\s+AS\s[^;]*;/i';

    /** @return list<array{migracion:string,vista:string,sql:string}> en orden de migración y de aparición en el archivo */
    public static function definiciones(string $directorio): array
    {
        $archivos = glob(rtrim($directorio, '/') . '/*.sql') ?: [];
        sort($archivos, SORT_STRING);
        $definiciones = [];
        foreach ($archivos as $archivo) {
            if (str_ends_with($archivo, '_rollback.sql')) {
                continue;
            }
            if (preg_match_all(self::PATRON, (string)file_get_contents($archivo), $m, PREG_SET_ORDER)) {
                foreach ($m as $c) {
                    $definiciones[] = ['migracion' => basename($archivo, '.sql'), 'vista' => $c[1], 'sql' => rtrim($c[0], ';')];
                }
            }
        }
        return $definiciones;
    }

    /** @return list<string> nombres de vista que alguna migración sabe construir */
    public static function nombres(string $directorio): array
    {
        return array_values(array_unique(array_column(self::definiciones($directorio), 'vista')));
    }

    /**
     * @param array<string,bool> $aplicadas migraciones aplicadas (nombre => true)
     * @return list<string> sentencias a ejecutar, en orden: todas las definiciones de las migraciones aplicadas
     */
    public static function sentencias(string $directorio, array $aplicadas): array
    {
        $sentencias = [];
        foreach (self::definiciones($directorio) as $d) {
            if (isset($aplicadas[$d['migracion']])) {
                $sentencias[] = $d['sql'];
            }
        }
        return $sentencias;
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
