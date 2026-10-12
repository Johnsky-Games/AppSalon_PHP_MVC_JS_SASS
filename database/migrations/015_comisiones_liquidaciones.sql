CREATE TABLE comisiones_config (
 profesional_id INT NOT NULL PRIMARY KEY,
 porcentaje_pb INT NOT NULL DEFAULT 0,
 actor_id INT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_com_config_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE CASCADE,
 CONSTRAINT fk_com_config_actor FOREIGN KEY (actor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 CONSTRAINT ck_com_porcentaje CHECK(porcentaje_pb >= 0 AND porcentaje_pb <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE comisiones_liquidaciones (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 profesional_id INT NULL,
 profesional_nombre VARCHAR(120) NOT NULL,
 actor_id INT NULL,
 hasta_fecha DATE NOT NULL,
 monto_centavos BIGINT NOT NULL,
 metodo VARCHAR(24) NOT NULL,
 comprobante VARCHAR(180) NOT NULL,
 caja_id BIGINT NULL,
 idempotencia VARCHAR(120) NOT NULL UNIQUE,
 huella CHAR(64) NOT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_com_liq_prof FOREIGN KEY(profesional_id) REFERENCES profesionales(id) ON DELETE SET NULL,
 CONSTRAINT fk_com_liq_actor FOREIGN KEY(actor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 CONSTRAINT fk_com_liq_caja FOREIGN KEY(caja_id) REFERENCES caja_movimientos(id) ON DELETE SET NULL,
 CONSTRAINT ck_com_liq_monto CHECK(monto_centavos > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE comisiones_movimientos (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 profesional_id INT NULL,
 profesional_nombre VARCHAR(120) NOT NULL,
 pago_id INT NULL,
 pago_referencia VARCHAR(64) NOT NULL,
 tipo VARCHAR(12) NOT NULL,
 base_centavos BIGINT NOT NULL,
 porcentaje_pb INT NOT NULL,
 monto_centavos BIGINT NOT NULL,
 estado VARCHAR(16) NOT NULL DEFAULT 'pendiente',
 origen_id BIGINT NULL UNIQUE,
 liquidacion_id BIGINT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_com_pago_tipo(pago_id,tipo),
 INDEX idx_com_pendientes (profesional_id,estado,registrado_en),
 CONSTRAINT fk_com_mov_prof FOREIGN KEY(profesional_id) REFERENCES profesionales(id) ON DELETE SET NULL,
 CONSTRAINT fk_com_mov_pago FOREIGN KEY(pago_id) REFERENCES pagos(id) ON DELETE SET NULL,
 CONSTRAINT fk_com_mov_liq FOREIGN KEY(liquidacion_id) REFERENCES comisiones_liquidaciones(id) ON DELETE SET NULL,
 CONSTRAINT ck_com_mov_tipo CHECK(tipo IN ('devengo','reversa')),
 CONSTRAINT ck_com_mov_estado CHECK(estado IN ('pendiente','liquidada','anulada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
