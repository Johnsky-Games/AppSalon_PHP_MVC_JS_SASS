<?php

/**
 * Guard del rollback de la 025. Nunca modifica datos. Los comprobantes emitidos (y el contador que ya avanzó) son evidencia de
 * negocio: mientras existan, la reversión se niega (el propio rollback SQL también, error 1242).
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $existe = static function (string $nombre) use ($db): bool {
        $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($nombre) . "'");
        return (bool)$r && $r->num_rows > 0;
    };
    $perdidas = [];
    if ($existe('comprobantes')) {
        $r = $db->query('SELECT COUNT(*) n FROM comprobantes');
        $n = $r ? (int)($r->fetch_assoc()['n'] ?? 0) : 0;
        if ($n > 0) { $perdidas[] = ['mensaje' => "{$n} comprobantes emitidos se eliminan.", 'tabla' => 'comprobantes']; }
    }
    if ($existe('comprobantes_secuencia')) {
        $r = $db->query('SELECT COALESCE(MAX(ultimo),0) n FROM comprobantes_secuencia');
        $n = $r ? (int)($r->fetch_assoc()['n'] ?? 0) : 0;
        if ($n > 0) { $perdidas[] = ['mensaje' => "La numeración correlativa avanzó hasta {$n}; se reiniciaría.", 'tabla' => 'comprobantes_secuencia']; }
    }
    return $perdidas;
};
