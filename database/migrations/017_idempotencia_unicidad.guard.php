<?php

/**
 * Guard del rollback de la 017. Nunca modifica datos.
 * Revertir elimina `operaciones_idempotentes` (claves de operación vigentes) y el UNIQUE de correo normalizado: sin ellos un
 * reintento o doble clic de un alta ya confirmada vuelve a crear la identidad, y la base admite correos equivalentes.
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $existe = $db->query("SHOW TABLES LIKE 'operaciones_idempotentes'");
    if (!$existe || $existe->num_rows === 0) {
        return [];
    }
    $n = (int)$db->query("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE expira_en >= UTC_TIMESTAMP()")->fetch_assoc()['n'];
    if ($n === 0) {
        return [];
    }
    return [[
        'mensaje' => "{$n} claves de operación vigentes (idempotencia de altas) se eliminan; mientras la 017 esté revertida un reintento o doble clic puede duplicar altas y la base admite correos equivalentes.",
        'tabla' => 'operaciones_idempotentes',
    ]];
};
