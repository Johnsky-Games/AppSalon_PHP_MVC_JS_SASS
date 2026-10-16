<?php

/**
 * Guard del rollback de la 016. Se ejecuta ANTES de revertir (`migrador.php down`) y nunca modifica datos.
 * Devuelve la lista de datos de negocio que la reversión sacaría del modelo vivo (vacía = reversión sin pérdida).
 *
 * El rollback copia las membresías a `clientes_resguardo_016` (no se pierden), pero mientras la 016 esté revertida las
 * suspensiones dejan de aplicarse, por eso cualquier fila exige confirmación explícita y respaldo verificable.
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $existe = $db->query("SHOW TABLES LIKE 'clientes'");
    if (!$existe || $existe->num_rows === 0) {
        return [];
    }
    $fila = $db->query("SELECT COUNT(*) n, COALESCE(SUM(estado='suspendido'),0) s FROM clientes")->fetch_assoc();
    if ((int)$fila['n'] === 0) {
        return [];
    }
    return [[
        'mensaje' => "{$fila['n']} membresías de cliente ({$fila['s']} suspendidas) salen de `clientes`: se copian a `clientes_resguardo_016` y se restauran al reaplicar la 016, pero mientras esté revertida ningún código lee ni aplica esas suspensiones.",
        'tabla' => 'clientes',
    ]];
};
