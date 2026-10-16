<?php
namespace Tests\Integration;

/** Bases efímeras para pruebas que no deben heredar evidencia financiera de ids de citas reutilizados. */
final class BaseDatosAislada
{
    public static function crear(string $prefijo): \mysqli
    {
        if (!preg_match('/^[a-z0-9_]+$/', $prefijo)) { throw new \InvalidArgumentException('Prefijo de prueba no válido.'); }
        $nombre = 'appsalon_fixture_' . $prefijo . '_' . bin2hex(random_bytes(5)) . '_test';
        $db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', '', (int)(getenv('DB_PORT') ?: 3306));
        try {
            if (!$db->query("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) { throw new \RuntimeException('No se pudo crear la base de pruebas.'); }
            $db->select_db($nombre); $db->set_charset('utf8mb4'); validar_base_datos_prueba($db);
            if (!$db->multi_query((string)file_get_contents(__DIR__ . '/../../database/schema_base.sql'))) { throw new \RuntimeException('No se pudo cargar el esquema de pruebas.'); }
            do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
            if ($db->errno) { throw new \RuntimeException('El esquema de pruebas quedó incompleto.'); }
            $p = proc_open([PHP_BINARY, __DIR__ . '/../../database/migrador.php', 'up'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                array_merge(getenv(), ['DB_NAME' => $nombre]));
            if (!is_resource($p)) { throw new \RuntimeException('No se pudo iniciar el migrador de pruebas.'); }
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($p) !== 0) { throw new \RuntimeException('Migrador de pruebas: ' . $out . $err); }
            return $db;
        } catch (\Throwable $e) {
            $db->query("DROP DATABASE IF EXISTS `$nombre`"); $db->close(); throw $e;
        }
    }

    public static function nombre(\mysqli $db): string
    {
        return (string)$db->query('SELECT DATABASE()')->fetch_row()[0];
    }

    public static function destruir(\mysqli $db): void
    {
        validar_base_datos_prueba($db);
        $nombre = self::nombre($db);
        if (!preg_match('/^appsalon_fixture_[a-z0-9_]+_test$/', $nombre)) { throw new \RuntimeException('La base no pertenece a este arnés de pruebas.'); }
        try {
            if (!$db->query("DROP DATABASE `$nombre`")) { throw new \RuntimeException('No se pudo eliminar la base de pruebas.'); }
        } finally { $db->close(); }
    }
}
