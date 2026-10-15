<?php

/**
 * Guard del rollback de la 026. Nunca modifica datos. Coincide con la guarda del propio rollback SQL: toda fila de
 * `exportaciones_auditoria_general` es evidencia de negocio (quién exportó qué, cuándo y con qué huella).
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $r = $db->query("SHOW TABLES LIKE 'exportaciones_auditoria_general'");
    if (!$r || $r->num_rows === 0) { return []; }
    $c = $db->query('SELECT COUNT(*) n FROM exportaciones_auditoria_general');
    $n = $c ? (int)($c->fetch_assoc()['n'] ?? 0) : 0;
    return $n > 0 ? [['mensaje' => "{$n} registros de auditoría de exportaciones se eliminan.", 'tabla' => 'exportaciones_auditoria_general']] : [];
};
