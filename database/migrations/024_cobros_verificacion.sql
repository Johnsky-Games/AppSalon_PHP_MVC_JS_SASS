-- 024: cobro desde la cita con caja y conciliación coherentes (ronda 5, fallo C).
--
-- Qué añade (todo retrocompatible con los pagos manuales y de pasarela ya existentes):
--  · pagos: efectivo recibido y cambio, referencia de transferencia, estado de verificación y quién/cuándo verificó,
--    y quién registró el cobro (antes solo estaba dentro del JSON del proveedor).
--  · cobros_devoluciones: devoluciones por importe (parciales o totales) imputadas a un pago, solo anexado.
--  · cobros_eventos: auditoría solo anexada de cada mutación financiera (actor, antes y después).
--  · caja_movimientos.devolucion_id y comisiones_movimientos.devolucion_id: permiten VARIAS devoluciones parciales de un mismo
--    pago sin romper la unicidad (un ingreso por pago, un egreso por devolución, una reversa por devolución).
--  · caja_exportaciones_auditoria: auditoría PROVISIONAL de exportaciones de caja, solo anexada; la sustituye la tabla general
--    de la migración 026 cuando esta exista (ver Services\AuditoriaExportacion).
--
-- Retrocarga sin inventar datos: los pagos manuales ya completados/reembolsados cuentan como confirmados (no hay verificador
-- ni fecha de verificación que atribuirles: quedan NULL); `registrado_por` se copia del actor que ya figuraba en el JSON del
-- pago; las devoluciones manuales previas (egreso de caja con pago) se reflejan como filas de origen `migracion`.
-- Idempotente: cada paso comprueba el estado real del esquema. Compatible con MySQL 8.0.13+.

-- ---------------------------------------------------------------------------------------------------
-- 1) pagos
-- ---------------------------------------------------------------------------------------------------
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos' AND COLUMN_NAME = 'estado_verificacion') = 0,
 'ALTER TABLE pagos
    ADD COLUMN monto_recibido_centavos BIGINT NULL DEFAULT NULL COMMENT ''efectivo: importe entregado por la persona (>= aplicado)'' AFTER monto,
    ADD COLUMN cambio_centavos BIGINT NULL DEFAULT NULL COMMENT ''efectivo: vuelto; NO es ingreso'' AFTER monto_recibido_centavos,
    ADD COLUMN referencia_transferencia VARCHAR(80) NULL DEFAULT NULL AFTER cambio_centavos,
    ADD COLUMN referencia_unica VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''referencia normalizada; NULL cuando la transferencia se rechaza (libera la referencia)'' AFTER referencia_transferencia,
    ADD COLUMN estado_verificacion VARCHAR(24) NULL DEFAULT NULL COMMENT ''NULL = pasarela; pendiente_verificacion | confirmado | rechazado'' AFTER referencia_unica,
    ADD COLUMN registrado_por INT NULL DEFAULT NULL AFTER estado_verificacion,
    ADD COLUMN verificado_por INT NULL DEFAULT NULL AFTER registrado_por,
    ADD COLUMN verificado_en DATETIME NULL DEFAULT NULL AFTER verificado_por,
    ADD COLUMN verificacion_nota VARCHAR(180) NULL DEFAULT NULL AFTER verificado_en,
    ADD UNIQUE KEY uq_pagos_referencia_unica (referencia_unica),
    ADD INDEX idx_pagos_estado_verificacion (estado_verificacion, estado),
    ADD CONSTRAINT fk_pagos_registrado_por FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_pagos_verificado_por FOREIGN KEY (verificado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    ADD CONSTRAINT ck_pagos_estado_verificacion CHECK (estado_verificacion IS NULL OR estado_verificacion IN (''pendiente_verificacion'',''confirmado'',''rechazado'')),
    ADD CONSTRAINT ck_pagos_efectivo CHECK ((monto_recibido_centavos IS NULL AND cambio_centavos IS NULL) OR (monto_recibido_centavos >= 0 AND cambio_centavos >= 0))',
 'DO 0');
PREPARE st_pagos_024 FROM @sql;
EXECUTE st_pagos_024;
DEALLOCATE PREPARE st_pagos_024;

-- Retrocarga (solo donde falta): los cobros manuales ya completados o reembolsados cuentan como confirmados.
UPDATE pagos SET estado_verificacion = 'confirmado'
 WHERE proveedor = 'manual' AND estado IN ('completado','reembolsado') AND estado_verificacion IS NULL;
-- Quién registró el cobro: ya constaba en el JSON del pago (solo si la cuenta existe; no se inventa nada).
UPDATE pagos p INNER JOIN usuarios u ON u.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(p.payload_proveedor, '$.actor_id')) AS UNSIGNED)
   SET p.registrado_por = u.id
 WHERE p.proveedor = 'manual' AND p.registrado_por IS NULL AND JSON_VALID(p.payload_proveedor)
   AND JSON_EXTRACT(p.payload_proveedor, '$.actor_id') IS NOT NULL;

-- ---------------------------------------------------------------------------------------------------
-- 2) Devoluciones por importe (solo anexado)
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cobros_devoluciones (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 pago_id INT NOT NULL,
 cita_id INT NULL,
 monto_centavos BIGINT NOT NULL,
 metodo VARCHAR(30) NULL,
 motivo VARCHAR(180) NOT NULL,
 actor_id INT NULL,
 origen VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'caja' COMMENT 'caja | migracion (retrocarga de egresos previos)',
 registrado_en DATETIME NOT NULL,
 INDEX idx_cobros_dev_pago (pago_id),
 INDEX idx_cobros_dev_cita (cita_id),
 CONSTRAINT fk_cobros_dev_pago FOREIGN KEY (pago_id) REFERENCES pagos (id) ON DELETE RESTRICT,
 CONSTRAINT fk_cobros_dev_actor FOREIGN KEY (actor_id) REFERENCES usuarios (id) ON DELETE SET NULL,
 CONSTRAINT ck_cobros_dev_monto CHECK (monto_centavos > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_cobros_dev_no_update;
CREATE TRIGGER trg_cobros_dev_no_update BEFORE UPDATE ON cobros_devoluciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cobros_devoluciones es de solo anexado';
DROP TRIGGER IF EXISTS trg_cobros_dev_no_delete;
CREATE TRIGGER trg_cobros_dev_no_delete BEFORE DELETE ON cobros_devoluciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cobros_devoluciones es de solo anexado';

-- Devoluciones manuales previas: el egreso de caja ligado a un pago manual reembolsado ES la devolución (importe, actor, motivo y fecha
-- reales). El enlace con la caja de las devoluciones nuevas es caja_movimientos.devolucion_id (la tabla es de solo anexado y no puede
-- actualizarse después); las previas conservan devolucion_id = 0 y se enlazan por pago.
INSERT INTO cobros_devoluciones (pago_id, cita_id, monto_centavos, metodo, motivo, actor_id, origen, registrado_en)
SELECT c.pago_id, COALESCE(p.cita_id, p.cita_referencia_id), c.monto_centavos, p.metodo_pago, c.concepto, c.actor_id, 'migracion', c.registrado_en
  FROM caja_movimientos c INNER JOIN pagos p ON p.id = c.pago_id
 WHERE c.tipo = 'egreso' AND p.proveedor = 'manual' AND p.estado = 'reembolsado'
   AND NOT EXISTS (SELECT 1 FROM cobros_devoluciones d WHERE d.pago_id = c.pago_id AND d.origen = 'migracion');

-- ---------------------------------------------------------------------------------------------------
-- 3) Caja y comisiones: varias devoluciones parciales por pago
-- ---------------------------------------------------------------------------------------------------
-- El índice nuevo se crea ANTES de retirar el viejo en la misma sentencia: la clave foránea sobre pago_id necesita siempre uno.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND COLUMN_NAME = 'devolucion_id') = 0,
 'ALTER TABLE caja_movimientos
    ADD COLUMN devolucion_id BIGINT NOT NULL DEFAULT 0 COMMENT ''0 = ingreso o devolución previa a la 024; si no, cobros_devoluciones.id'',
    ADD UNIQUE KEY uq_caja_pago_evento (pago_id, tipo, devolucion_id),
    DROP INDEX uq_caja_pago',
 'DO 0');
PREPARE st_caja_024 FROM @sql;
EXECUTE st_caja_024;
DEALLOCATE PREPARE st_caja_024;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comisiones_movimientos' AND COLUMN_NAME = 'devolucion_id') = 0,
 'ALTER TABLE comisiones_movimientos
    ADD COLUMN devolucion_id BIGINT NOT NULL DEFAULT 0 COMMENT ''0 = devengo o reversa previa a la 024; si no, cobros_devoluciones.id'',
    ADD UNIQUE KEY uq_com_pago_tipo_dev (pago_id, tipo, devolucion_id),
    ADD UNIQUE KEY uq_com_origen_dev (origen_id, devolucion_id),
    DROP INDEX uq_com_pago_tipo,
    DROP INDEX origen_id',
 'DO 0');
PREPARE st_com_024 FROM @sql;
EXECUTE st_com_024;
DEALLOCATE PREPARE st_com_024;

-- ---------------------------------------------------------------------------------------------------
-- 4) Auditoría de mutaciones financieras (solo anexado)
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cobros_eventos (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tipo VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'cobro_efectivo | transferencia_registrada | transferencia_verificada | transferencia_rechazada | devolucion',
 cita_id INT NULL,
 pago_id INT NULL,
 devolucion_id BIGINT NULL,
 actor_id INT NULL COMMENT 'sin FK a propósito: la auditoría sobrevive a cualquier cambio de cuentas',
 actor_rol VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
 antes_json JSON NULL,
 despues_json JSON NULL,
 registrado_en DATETIME NOT NULL COMMENT 'America/Guayaquil, como el resto de la caja',
 INDEX idx_cobros_ev_cita (cita_id, id),
 INDEX idx_cobros_ev_pago (pago_id, id),
 INDEX idx_cobros_ev_actor (actor_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_cobros_ev_no_update;
CREATE TRIGGER trg_cobros_ev_no_update BEFORE UPDATE ON cobros_eventos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cobros_eventos es de solo anexado';
DROP TRIGGER IF EXISTS trg_cobros_ev_no_delete;
CREATE TRIGGER trg_cobros_ev_no_delete BEFORE DELETE ON cobros_eventos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cobros_eventos es de solo anexado';

-- ---------------------------------------------------------------------------------------------------
-- 5) Auditoría provisional de exportaciones de caja (solo anexado). Misma forma que la tabla general de la 026.
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS caja_exportaciones_auditoria (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ocurrido_en DATETIME NOT NULL COMMENT 'UTC',
 origen VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_usuario_id INT NULL,
 actor_rol VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
 formato VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'csv',
 resultado ENUM('exitosa','denegada','fallida') NOT NULL,
 motivo VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
 filtros_json JSON NULL,
 filtros_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 filas INT UNSIGNED NULL,
 columnas_n SMALLINT UNSIGNED NULL,
 truncado TINYINT(1) NOT NULL DEFAULT 0,
 bytes_entregados BIGINT UNSIGNED NULL,
 contenido_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 KEY idx_caja_exp_fecha (ocurrido_en, id),
 KEY idx_caja_exp_actor (actor_usuario_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_caja_exp_no_update;
CREATE TRIGGER trg_caja_exp_no_update BEFORE UPDATE ON caja_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'caja_exportaciones_auditoria es de solo anexado';
DROP TRIGGER IF EXISTS trg_caja_exp_no_delete;
CREATE TRIGGER trg_caja_exp_no_delete BEFORE DELETE ON caja_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'caja_exportaciones_auditoria es de solo anexado';
