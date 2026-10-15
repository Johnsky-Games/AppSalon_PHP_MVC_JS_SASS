<?php

/**
 * Guard del rollback de la 024. Nunca modifica datos.
 * Es evidencia de negocio: eventos de cobro, devoluciones registradas desde la caja, auditoría de exportaciones y los datos de
 * cobro que NO se pueden reconstruir (efectivo recibido/cambio, referencias, verificaciones y rechazos de transferencias).
 * No cuenta el retro-llenado reproducible: `estado_verificacion = confirmado` de los manuales previos, `registrado_por`
 * copiado del JSON y las devoluciones de origen `migracion`. Mientras haya datos el propio rollback SQL también se niega (1242).
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $existe = static function (string $nombre) use ($db): bool {
        $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($nombre) . "'");
        return (bool)$r && $r->num_rows > 0;
    };
    $columna = static function (string $tabla, string $col) use ($db): bool {
        $r = $db->query('SHOW COLUMNS FROM `' . $tabla . "` LIKE '" . $db->real_escape_string($col) . "'");
        return (bool)$r && $r->num_rows > 0;
    };
    $contar = static function (string $sql) use ($db): int {
        $r = $db->query($sql);
        return $r ? (int)($r->fetch_assoc()['n'] ?? 0) : 0;
    };
    $perdidas = [];
    if ($existe('cobros_eventos') && ($n = $contar('SELECT COUNT(*) n FROM cobros_eventos')) > 0) {
        $perdidas[] = ['mensaje' => "{$n} eventos de auditoría de cobros se eliminan.", 'tabla' => 'cobros_eventos'];
    }
    if ($existe('cobros_devoluciones') && ($n = $contar("SELECT COUNT(*) n FROM cobros_devoluciones WHERE origen <> 'migracion'")) > 0) {
        $perdidas[] = ['mensaje' => "{$n} devoluciones registradas desde la caja se eliminan.", 'tabla' => 'cobros_devoluciones'];
    }
    if ($existe('caja_exportaciones_auditoria') && ($n = $contar('SELECT COUNT(*) n FROM caja_exportaciones_auditoria')) > 0) {
        $perdidas[] = ['mensaje' => "{$n} registros de auditoría de exportaciones de caja se eliminan.", 'tabla' => 'caja_exportaciones_auditoria'];
    }
    if ($columna('pagos', 'estado_verificacion') && ($n = $contar("SELECT COUNT(*) n FROM pagos WHERE monto_recibido_centavos IS NOT NULL OR cambio_centavos IS NOT NULL OR referencia_transferencia IS NOT NULL OR verificado_por IS NOT NULL OR verificado_en IS NOT NULL OR verificacion_nota IS NOT NULL OR estado_verificacion IN ('pendiente_verificacion','rechazado')")) > 0) {
        $perdidas[] = ['mensaje' => "{$n} pagos con efectivo recibido, referencias o verificaciones de transferencia pierden esos datos.", 'tabla' => null];
    }
    if ($columna('caja_movimientos', 'devolucion_id') && ($n = $contar('SELECT COUNT(*) n FROM caja_movimientos WHERE devolucion_id <> 0')) > 0) {
        $perdidas[] = ['mensaje' => "{$n} movimientos de caja de devoluciones parciales no caben en el esquema anterior.", 'tabla' => null];
    }
    if ($columna('comisiones_movimientos', 'devolucion_id') && ($n = $contar('SELECT COUNT(*) n FROM comisiones_movimientos WHERE devolucion_id <> 0')) > 0) {
        $perdidas[] = ['mensaje' => "{$n} reversas de comisión por devolución parcial no caben en el esquema anterior.", 'tabla' => null];
    }
    return $perdidas;
};
