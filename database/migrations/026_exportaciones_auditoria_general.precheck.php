<?php

/**
 * Pre-validación de la migración 026. Se ejecuta ANTES de alterar nada.
 *
 * La auditoría es de solo anexado y lo garantiza la base de datos con triggers. Crear triggers exige el privilegio
 * TRIGGER y, con el registro binario activo, SUPER o `log_bin_trust_function_creators=1`. En vez de fallar a medias,
 * se comprueba aquí con una sonda real (tabla y trigger temporales que se eliminan) y, si no es posible, se informa
 * con el remedio. No toca datos de negocio.
 *
 * @return callable(\mysqli): string[] Lista de problemas (vacía si es seguro continuar).
 */
return static function (\mysqli $db): array {
    $problemas = [];
    $version = (string)($db->query('SELECT VERSION() v')->fetch_assoc()['v'] ?? '');
    if (stripos($version, 'mariadb') !== false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '8.0.0', '<')) {
        $problemas[] = "El motor ({$version}) no es MySQL 8.0 o superior; la migración 026 usa JSON, SIGNAL y triggers de MySQL 8.";
    }
    // Cada consulta puede devolver false (mysqli_report OFF, como en la aplicación) o lanzar mysqli_sql_exception (modo estricto).
    $ejecutar = static function (string $sql) use ($db): bool {
        try { return (bool)@$db->query($sql); } catch (\mysqli_sql_exception) { return false; }
    };
    $ejecutar('DROP TRIGGER IF EXISTS _sonda026_trg');
    $ejecutar('DROP TABLE IF EXISTS _sonda026');
    if (!$ejecutar('CREATE TABLE _sonda026 (id INT) ENGINE=InnoDB')) {
        $problemas[] = 'No se puede crear tablas con el usuario de la migración: ' . $db->error;
        return $problemas;
    }
    if (!$ejecutar("CREATE TRIGGER _sonda026_trg BEFORE UPDATE ON _sonda026 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x'")) {
        $problemas[] = 'No se pueden crear triggers (' . $db->error . '). Conceda TRIGGER al usuario de la migración y, si el registro '
            . 'binario está activo, ejecute la migración con un usuario con SUPER o active log_bin_trust_function_creators=1. '
            . 'La auditoría de solo anexado no es opcional: no se aplica la migración a medias.';
    }
    $ejecutar('DROP TRIGGER IF EXISTS _sonda026_trg');
    $ejecutar('DROP TABLE IF EXISTS _sonda026');
    return $problemas;
};
