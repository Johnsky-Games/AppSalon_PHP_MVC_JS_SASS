-- Saldos nominativos: recibir dinero no implica vender ni prestar un servicio.
CREATE TABLE IF NOT EXISTS erp_prepagos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 contacto_id INT NOT NULL,
 tipo ENUM('saldo','regalo') NOT NULL,
 nombre VARCHAR(120) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_prepago_contacto(contacto_id,id),
 CONSTRAINT fk_prepago_contacto FOREIGN KEY(contacto_id) REFERENCES erp_contactos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_prepago_cargas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 prepago_id BIGINT NOT NULL,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 monto_centavos BIGINT NOT NULL,
 metodo ENUM('efectivo','transferencia') NOT NULL,
 estado ENUM('pendiente','confirmada','rechazada') NOT NULL,
 recibido_centavos BIGINT NULL,
 cambio_centavos BIGINT NULL,
 referencia VARCHAR(80) NULL,
 referencia_unica VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 registrado_por INT NOT NULL,
 verificado_por INT NULL,
 verificacion_nota VARCHAR(180) NULL,
 creado_en DATETIME NOT NULL,
 resuelto_en DATETIME NULL,
 INDEX idx_prepago_carga(prepago_id,id),
 CONSTRAINT fk_prepago_carga FOREIGN KEY(prepago_id) REFERENCES erp_prepagos(id),
 CONSTRAINT ck_prepago_carga_monto CHECK(monto_centavos>0 AND monto_centavos<=99999999),
 CONSTRAINT ck_prepago_cambio CHECK((metodo='transferencia' AND recibido_centavos IS NULL AND cambio_centavos IS NULL) OR (metodo='efectivo' AND recibido_centavos IS NOT NULL AND cambio_centavos IS NOT NULL AND recibido_centavos>=monto_centavos AND cambio_centavos=recibido_centavos-monto_centavos))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_prepago_movimientos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 prepago_id BIGINT NOT NULL,
 tipo ENUM('carga','aplicacion','restitucion','reembolso') NOT NULL,
 monto_centavos BIGINT NOT NULL,
 carga_id BIGINT NULL,
 pago_id INT NULL,
 devolucion_id BIGINT NULL,
 venta_id BIGINT NULL,
 caja_id BIGINT NULL UNIQUE,
 referencia VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 motivo VARCHAR(300) NOT NULL,
 comprobante_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
 comprobante_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 actor_id INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_prepago_libro(prepago_id,id),
 CONSTRAINT fk_prepago_mov FOREIGN KEY(prepago_id) REFERENCES erp_prepagos(id),
 CONSTRAINT fk_prepago_mov_carga FOREIGN KEY(carga_id) REFERENCES erp_prepago_cargas(id),
 CONSTRAINT fk_prepago_mov_pago FOREIGN KEY(pago_id) REFERENCES pagos(id),
 CONSTRAINT fk_prepago_mov_devol FOREIGN KEY(devolucion_id) REFERENCES cobros_devoluciones(id),
 CONSTRAINT fk_prepago_mov_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_prepago_mov_caja FOREIGN KEY(caja_id) REFERENCES caja_movimientos(id),
 CONSTRAINT ck_prepago_comprobante CHECK((tipo IN ('carga','reembolso') AND comprobante_json IS NOT NULL AND JSON_VALID(comprobante_json) AND comprobante_sha256 IS NOT NULL) OR (tipo IN ('aplicacion','restitucion') AND comprobante_json IS NULL AND comprobante_sha256 IS NULL)),
 CONSTRAINT ck_prepago_mov_signo CHECK((tipo IN ('carga','restitucion') AND monto_centavos>0) OR (tipo IN ('aplicacion','reembolso') AND monto_centavos<0)),
 CONSTRAINT ck_prepago_mov_origen CHECK(
  (tipo='carga' AND carga_id IS NOT NULL AND caja_id IS NOT NULL AND pago_id IS NULL AND devolucion_id IS NULL AND venta_id IS NULL) OR
  (tipo='reembolso' AND carga_id IS NOT NULL AND caja_id IS NOT NULL AND pago_id IS NULL AND devolucion_id IS NULL AND venta_id IS NULL) OR
  (tipo='aplicacion' AND pago_id IS NOT NULL AND venta_id IS NOT NULL AND caja_id IS NULL AND carga_id IS NULL AND devolucion_id IS NULL) OR
  (tipo='restitucion' AND pago_id IS NOT NULL AND venta_id IS NOT NULL AND devolucion_id IS NOT NULL AND caja_id IS NULL AND carga_id IS NULL)
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_prepago_mov_no_update;
CREATE TRIGGER trg_prepago_mov_no_update BEFORE UPDATE ON erp_prepago_movimientos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El libro de prepagos es de solo anexado';
DROP TRIGGER IF EXISTS trg_prepago_mov_no_delete;
CREATE TRIGGER trg_prepago_mov_no_delete BEFORE DELETE ON erp_prepago_movimientos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El libro de prepagos es de solo anexado';

-- Una transferencia bancaria no puede financiar a la vez una venta y un prepago.
CREATE TABLE IF NOT EXISTS erp_transferencia_referencias (
 referencia VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 pago_id INT NULL UNIQUE,
 carga_id BIGINT NULL UNIQUE,
 CONSTRAINT ck_erp_transferencia_origen CHECK((pago_id IS NULL) <> (carga_id IS NULL)),
 CONSTRAINT fk_erp_transferencia_pago FOREIGN KEY(pago_id) REFERENCES pagos(id),
 CONSTRAINT fk_erp_transferencia_carga FOREIGN KEY(carga_id) REFERENCES erp_prepago_cargas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO erp_transferencia_referencias(referencia,pago_id)
 SELECT referencia_unica,id FROM pagos WHERE referencia_unica IS NOT NULL;
