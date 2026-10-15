-- Reversión SEGURA de 024. La verificación de transferencias, las devoluciones y la auditoría de caja son evidencia de negocio:
-- la reversión se NIEGA (error, sin cambiar nada) si contienen algo que no sea el retro-llenado reproducible de la migración.
-- Cada guarda es una subconsulta escalar que devuelve más de una fila (error 1242) únicamente cuando hay datos.
-- Reproducible (no cuenta): `estado_verificacion = confirmado` de los pagos manuales previos, `registrado_por` copiado del JSON
-- y las devoluciones de origen `migracion`. Para revertir de todos modos: respalde (mysqldump) y vacíe las tablas con un usuario
-- administrador; los triggers de solo anexado no impiden TRUNCATE ni DROP: es una decisión humana y respaldada.
DO (SELECT 'cobros_eventos tiene registros: respáldela y vacíela antes de revertir' FROM cobros_eventos CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'cobros_devoluciones tiene devoluciones registradas desde la caja: respáldelas antes de revertir' FROM (SELECT 1 FROM cobros_devoluciones WHERE origen <> 'migracion') AS x CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'caja_exportaciones_auditoria tiene registros: respáldela y vacíela antes de revertir' FROM caja_exportaciones_auditoria CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'pagos tiene efectivo recibido, referencias o verificaciones de transferencia: respáldelos antes de revertir' FROM (SELECT 1 FROM pagos WHERE monto_recibido_centavos IS NOT NULL OR cambio_centavos IS NOT NULL OR referencia_transferencia IS NOT NULL OR verificado_por IS NOT NULL OR verificado_en IS NOT NULL OR verificacion_nota IS NOT NULL OR estado_verificacion IN ('pendiente_verificacion','rechazado')) AS x CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'caja_movimientos tiene devoluciones parciales: respáldelas antes de revertir' FROM (SELECT 1 FROM caja_movimientos WHERE devolucion_id <> 0) AS x CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'comisiones_movimientos tiene reversas por devolución parcial: respáldelas antes de revertir' FROM (SELECT 1 FROM comisiones_movimientos WHERE devolucion_id <> 0) AS x CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);

DROP TABLE IF EXISTS caja_exportaciones_auditoria;
DROP TABLE IF EXISTS cobros_eventos;
DROP TABLE IF EXISTS cobros_devoluciones;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND COLUMN_NAME = 'devolucion_id') > 0,
 'ALTER TABLE caja_movimientos ADD UNIQUE KEY uq_caja_pago (pago_id, tipo), DROP INDEX uq_caja_pago_evento, DROP COLUMN devolucion_id',
 'DO 0');
PREPARE st_caja_024 FROM @sql;
EXECUTE st_caja_024;
DEALLOCATE PREPARE st_caja_024;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comisiones_movimientos' AND COLUMN_NAME = 'devolucion_id') > 0,
 'ALTER TABLE comisiones_movimientos ADD UNIQUE KEY uq_com_pago_tipo (pago_id, tipo), ADD UNIQUE KEY origen_id (origen_id), DROP INDEX uq_com_pago_tipo_dev, DROP INDEX uq_com_origen_dev, DROP COLUMN devolucion_id',
 'DO 0');
PREPARE st_com_024 FROM @sql;
EXECUTE st_com_024;
DEALLOCATE PREPARE st_com_024;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'estado_verificacion') > 0,
 'ALTER TABLE pagos
    DROP CHECK ck_pagos_estado_verificacion,
    DROP CHECK ck_pagos_efectivo,
    DROP FOREIGN KEY fk_pagos_registrado_por,
    DROP FOREIGN KEY fk_pagos_verificado_por',
 'DO 0');
PREPARE st_pagos_024 FROM @sql;
EXECUTE st_pagos_024;
DEALLOCATE PREPARE st_pagos_024;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'estado_verificacion') > 0,
 'ALTER TABLE pagos
    DROP INDEX uq_pagos_referencia_unica,
    DROP INDEX idx_pagos_estado_verificacion,
    DROP COLUMN monto_recibido_centavos,
    DROP COLUMN cambio_centavos,
    DROP COLUMN referencia_transferencia,
    DROP COLUMN referencia_unica,
    DROP COLUMN estado_verificacion,
    DROP COLUMN registrado_por,
    DROP COLUMN verificado_por,
    DROP COLUMN verificado_en,
    DROP COLUMN verificacion_nota',
 'DO 0');
PREPARE st_pagos_024 FROM @sql;
EXECUTE st_pagos_024;
DEALLOCATE PREPARE st_pagos_024;
