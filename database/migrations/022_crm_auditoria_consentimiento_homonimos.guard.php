<?php

/**
 * Guard del rollback de la 022. Nunca modifica datos.
 * Coincide con las guardas del propio rollback: son evidencia de negocio la auditoría de exportaciones, las
 * decisiones de homónimos, las decisiones de personas en el historial de consentimiento y las versiones
 * publicadas además de la 1. El retro-llenado reproducible de la migración (versión 1 e historial de origen
 * `migracion`) NO cuenta: se vuelve a generar al reaplicar. Mientras haya datos de negocio el propio rollback SQL
 * también se niega (error 1242): hay que archivar y vaciar las tablas con un usuario administrador, ver docs/CRM_CLIENTES.md.
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $existe = static function (string $nombre) use ($db): bool {
        $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($nombre) . "'");
        return (bool)$r && $r->num_rows > 0;
    };
    $contar = static function (string $sql) use ($db): int {
        $r = $db->query($sql);
        return $r ? (int)($r->fetch_assoc()['n'] ?? 0) : 0;
    };
    $perdidas = [];
    if ($existe('crm_exportaciones_auditoria') && ($n = $contar('SELECT COUNT(*) n FROM crm_exportaciones_auditoria')) > 0) {
        $perdidas[] = ['mensaje' => "{$n} registros de auditoría de exportaciones CRM se eliminan.", 'tabla' => 'crm_exportaciones_auditoria'];
    }
    if ($existe('altas_homonimos_decisiones') && ($n = $contar('SELECT COUNT(*) n FROM altas_homonimos_decisiones')) > 0) {
        $perdidas[] = ['mensaje' => "{$n} decisiones de homónimos en altas de clientes se eliminan.", 'tabla' => 'altas_homonimos_decisiones'];
    }
    if ($existe('consentimientos_historial') && ($n = $contar("SELECT COUNT(*) n FROM consentimientos_historial WHERE origen <> 'migracion'")) > 0) {
        $perdidas[] = ['mensaje' => "{$n} decisiones de consentimiento de personas (no solo el retro-llenado) se eliminan.", 'tabla' => 'consentimientos_historial'];
    }
    if ($existe('consentimiento_versiones') && ($n = $contar('SELECT COUNT(*) n FROM consentimiento_versiones WHERE version > 1')) > 0) {
        $perdidas[] = ['mensaje' => "{$n} versiones de consentimiento publicadas además de la 1 se eliminan.", 'tabla' => 'consentimiento_versiones'];
    }
    return $perdidas;
};
