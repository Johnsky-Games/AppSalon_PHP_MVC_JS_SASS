CREATE TABLE caja_movimientos (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_id INT NULL,
 tipo VARCHAR(16) NOT NULL,
 monto_centavos BIGINT NOT NULL,
 concepto VARCHAR(180) NOT NULL,
 metodo VARCHAR(24) NOT NULL,
 pago_id INT NULL,
 idempotencia VARCHAR(120) NOT NULL,
 huella CHAR(64) NOT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_caja_idempotencia (idempotencia),
 UNIQUE KEY uq_caja_pago (pago_id, tipo),
 INDEX idx_caja_fecha (registrado_en),
 CONSTRAINT fk_caja_actor FOREIGN KEY (actor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 CONSTRAINT fk_caja_pago FOREIGN KEY (pago_id) REFERENCES pagos(id) ON DELETE SET NULL,
 CONSTRAINT ck_caja_monto CHECK (monto_centavos > 0),
 CONSTRAINT ck_caja_tipo CHECK (tipo IN ('ingreso','egreso'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE inventario_productos (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 sku VARCHAR(40) NOT NULL UNIQUE,
 nombre VARCHAR(120) NOT NULL,
 unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
 stock_milesimas BIGINT NOT NULL DEFAULT 0,
 minimo_milesimas BIGINT NOT NULL DEFAULT 0,
 costo_centavos BIGINT NOT NULL DEFAULT 0,
 activo TINYINT NOT NULL DEFAULT 1,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT ck_stock CHECK(stock_milesimas >= 0 AND minimo_milesimas >= 0 AND costo_centavos >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE inventario_movimientos (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 producto_id INT NOT NULL,
 actor_id INT NULL,
 tipo VARCHAR(12) NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 saldo_milesimas BIGINT NOT NULL,
 costo_centavos BIGINT NOT NULL,
 motivo VARCHAR(180) NOT NULL,
 idempotencia VARCHAR(120) NOT NULL UNIQUE,
 huella CHAR(64) NOT NULL,
 registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_inventario_producto (producto_id, registrado_en),
 CONSTRAINT fk_inv_producto FOREIGN KEY (producto_id) REFERENCES inventario_productos(id),
 CONSTRAINT fk_inv_actor FOREIGN KEY (actor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 CONSTRAINT ck_inv_cantidad CHECK(cantidad_milesimas > 0 AND saldo_milesimas >= 0),
 CONSTRAINT ck_inv_tipo CHECK(tipo IN ('entrada','salida'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
