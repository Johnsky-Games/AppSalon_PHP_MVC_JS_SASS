<?php

/**
 * Pre-validación de la migración 017. Se ejecuta ANTES de aplicar cualquier cambio.
 * Si hay datos incompatibles con la unicidad de correo normalizado, la migración no se aplica y se
 * informa qué cuentas revisar. Nunca borra ni fusiona nada: la resolución es manual y auditada
 * (ver scripts/diagnosticar_duplicados.php).
 *
 * @return callable(\mysqli): string[] Devuelve la lista de problemas (vacía si es seguro continuar).
 */
return static function (\mysqli $db): array {
    $problemas = [];
    $version = (string)($db->query('SELECT VERSION() v')->fetch_assoc()['v'] ?? '');
    if (stripos($version, 'mariadb') !== false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '8.0.13', '<')) {
        $problemas[] = "El motor ({$version}) no admite índices funcionales; se requiere MySQL 8.0.13 o superior.";
    }
    $res = $db->query("SELECT GROUP_CONCAT(id ORDER BY id) ids, COUNT(*) n FROM usuarios GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1");
    while ($res && ($fila = $res->fetch_assoc())) {
        $problemas[] = "Cuentas con el mismo correo normalizado (ids {$fila['ids']}): resuélvelas manualmente antes de migrar.";
    }
    return $problemas;
};
