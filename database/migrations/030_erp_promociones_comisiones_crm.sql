CREATE TABLE IF NOT EXISTS erp_promociones (
 id INT AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 nombre VARCHAR(120) NOT NULL,
 desde DATE NOT NULL,
 hasta DATE NOT NULL,
 porcentaje_pb INT NOT NULL,
 minimo_centavos BIGINT NOT NULL DEFAULT 0,
 max_usos INT NOT NULL,
 max_por_cliente INT NOT NULL DEFAULT 0,
 activo TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT ck_erp_promocion CHECK(hasta>=desde AND porcentaje_pb BETWEEN 1 AND 10000 AND max_usos>0 AND max_por_cliente>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_promocion_usos (
 venta_id BIGINT PRIMARY KEY,
 promocion_id INT NOT NULL,
 identidad VARCHAR(60) NOT NULL,
 estado ENUM('reservado','usado','liberado') NOT NULL,
 expira_en DATETIME NOT NULL,
 regla_json JSON NOT NULL,
 descuento_centavos BIGINT NOT NULL,
 INDEX idx_erp_promo_usos(promocion_id,estado,expira_en),
 CONSTRAINT fk_erp_uso_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_erp_uso_promocion FOREIGN KEY(promocion_id) REFERENCES erp_promociones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_comision_reglas (
 id INT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 porcentaje_pb INT NOT NULL,
 desde DATE NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_regla_colaborador(colaborador_id,desde,id),
 CONSTRAINT fk_erp_regla_colaborador FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_erp_comision_pct CHECK(porcentaje_pb BETWEEN 0 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @erp_col_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_venta_lineas' AND COLUMN_NAME='vendedor_colaborador_id')=0,'ALTER TABLE erp_venta_lineas ADD COLUMN vendedor_colaborador_id INT NULL','SELECT 1');
PREPARE erp_col_stmt FROM @erp_col_sql;
EXECUTE erp_col_stmt;
DEALLOCATE PREPARE erp_col_stmt;
SET @erp_col_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_ventas' AND COLUMN_NAME='atencion_sync_hash')=0,'ALTER TABLE erp_ventas ADD COLUMN atencion_sync_hash CHAR(64) NULL','SELECT 1');
PREPARE erp_col_stmt FROM @erp_col_sql;
EXECUTE erp_col_stmt;
DEALLOCATE PREPARE erp_col_stmt;
CREATE TABLE IF NOT EXISTS erp_comision_liquidaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 monto_centavos BIGINT NOT NULL,
 via ENUM('directa','nomina') NOT NULL,
 obligacion_id BIGINT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_liquidacion_colaborador FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_erp_liquidacion_obligacion FOREIGN KEY(obligacion_id) REFERENCES erp_obligaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_comision_movimientos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 venta_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 colaborador_id INT NOT NULL,
 porcentaje_pb INT NOT NULL,
 monto_centavos BIGINT NOT NULL,
 estado ENUM('pendiente','asignada') NOT NULL DEFAULT 'pendiente',
 liquidacion_id BIGINT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_comision_linea(linea_id,id),
 INDEX idx_erp_comision_pendiente(colaborador_id,estado,id),
 CONSTRAINT fk_erp_comision_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_erp_comision_linea FOREIGN KEY(linea_id) REFERENCES erp_venta_lineas(id),
 CONSTRAINT fk_erp_comision_persona FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_erp_comision_liquidacion FOREIGN KEY(liquidacion_id) REFERENCES erp_comision_liquidaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_oportunidades (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 contacto_id INT NOT NULL,
 titulo VARCHAR(180) NOT NULL,
 etapa ENUM('nuevo','contactado','cotizacion','reserva','ganado','perdido') NOT NULL DEFAULT 'nuevo',
 responsable_id INT NOT NULL,
 proximo_contacto DATE NOT NULL,
 origen VARCHAR(80) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_oportunidad_contacto FOREIGN KEY(contacto_id) REFERENCES erp_contactos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_interacciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 contacto_id INT NOT NULL,
 oportunidad_id BIGINT NULL,
 tipo ENUM('nota','llamada','reclamo','seguimiento') NOT NULL,
 texto VARCHAR(1000) NOT NULL,
 responsable_id INT NOT NULL,
 vence_en DATE NOT NULL,
 estado ENUM('pendiente','resuelta') NOT NULL DEFAULT 'pendiente',
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 resuelto_en DATETIME NULL,
 resolucion VARCHAR(1000) NULL,
 CONSTRAINT fk_erp_interaccion_contacto FOREIGN KEY(contacto_id) REFERENCES erp_contactos(id),
 CONSTRAINT fk_erp_interaccion_oportunidad FOREIGN KEY(oportunidad_id) REFERENCES erp_oportunidades(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
