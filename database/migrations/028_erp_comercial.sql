-- Extensión comercial aditiva. No retrocarga ventas ni genera movimientos sobre datos históricos.
CREATE TABLE IF NOT EXISTS erp_empresa (
 id INT PRIMARY KEY,
 nombre VARCHAR(120) NOT NULL DEFAULT 'Empresa por configurar',
 identificacion VARCHAR(30) NULL UNIQUE,
 direccion VARCHAR(300) NOT NULL DEFAULT '',
 email VARCHAR(254) NULL,
 moneda CHAR(3) NOT NULL DEFAULT 'USD',
 actualizado_por INT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO erp_empresa(id) VALUES(1);

CREATE TABLE IF NOT EXISTS erp_contactos (
 id INT AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NULL UNIQUE,
 nombre VARCHAR(120) NOT NULL,
 identificacion VARCHAR(30) NULL UNIQUE,
 email VARCHAR(254) NULL,
 telefono VARCHAR(40) NULL,
 direccion VARCHAR(300) NOT NULL DEFAULT '',
 tipo ENUM('cliente','proveedor','ambos') NOT NULL DEFAULT 'cliente',
 activo TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_contacto_nombre(nombre),
 CONSTRAINT fk_erp_contacto_usuario FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_catalogo (
 id INT AUTO_INCREMENT PRIMARY KEY,
 producto_id INT NULL UNIQUE,
 servicio_id INT NULL UNIQUE,
 precio_centavos BIGINT NOT NULL,
 impuesto_pb INT NOT NULL,
 tipo ENUM('venta','consumo','ambos') NOT NULL DEFAULT 'venta',
 activo TINYINT NOT NULL DEFAULT 1,
 actualizado_por INT NOT NULL,
 actualizado_en DATETIME NOT NULL,
 CONSTRAINT ck_erp_catalogo_origen CHECK((producto_id IS NULL) <> (servicio_id IS NULL)),
 CONSTRAINT ck_erp_catalogo_precio CHECK(precio_centavos>=0 AND precio_centavos<=99999999 AND impuesto_pb>=0 AND impuesto_pb<=10000),
 CONSTRAINT fk_erp_catalogo_producto FOREIGN KEY(producto_id) REFERENCES inventario_productos(id),
 CONSTRAINT fk_erp_catalogo_servicio FOREIGN KEY(servicio_id) REFERENCES servicios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_caja_sesiones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 actor_id INT NOT NULL,
 estado ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
 fondo_centavos BIGINT NOT NULL,
 apertura_en DATETIME NOT NULL,
 cierre_en DATETIME NULL,
 contado_centavos BIGINT NULL,
 esperado_centavos BIGINT NULL,
 diferencia_centavos BIGINT NULL,
 motivo VARCHAR(300) NULL,
 UNIQUE KEY uq_erp_caja_abierta(actor_id,(IF(estado='abierta',1,NULL))),
 CONSTRAINT ck_erp_fondo CHECK(fondo_centavos>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_ventas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 contacto_id INT NULL,
 cita_id INT NULL UNIQUE,
 cliente_usuario_id INT NULL,
 comprador_nombre VARCHAR(120) NOT NULL,
 comprador_json JSON NOT NULL,
 estado ENUM('borrador','confirmada','anulada') NOT NULL DEFAULT 'borrador',
 total_centavos BIGINT NOT NULL DEFAULT 0,
 subtotal_centavos BIGINT NOT NULL DEFAULT 0,
 descuento_centavos BIGINT NOT NULL DEFAULT 0,
 impuesto_centavos BIGINT NOT NULL DEFAULT 0,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 confirmado_en DATETIME NULL,
 version INT NOT NULL DEFAULT 1,
 INDEX idx_erp_venta_fecha(creado_en,id),
 CONSTRAINT fk_erp_venta_contacto FOREIGN KEY(contacto_id) REFERENCES erp_contactos(id),
 CONSTRAINT fk_erp_venta_cita FOREIGN KEY(cita_id) REFERENCES citas(id),
 CONSTRAINT ck_erp_venta_total CHECK(total_centavos>=0 AND total_centavos<=99999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_caja_movimientos (
 caja_id BIGINT PRIMARY KEY,
 sesion_id BIGINT NOT NULL,
 CONSTRAINT fk_erp_caja_mov FOREIGN KEY(caja_id) REFERENCES caja_movimientos(id),
 CONSTRAINT fk_erp_caja_sesion FOREIGN KEY(sesion_id) REFERENCES erp_caja_sesiones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_venta_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 venta_id BIGINT NOT NULL,
 catalogo_id INT NULL,
 cita_servicio_id INT NULL UNIQUE,
 producto_id INT NULL,
 servicio_id INT NULL,
 profesional_id INT NULL,
 nombre VARCHAR(180) NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 precio_centavos BIGINT NOT NULL,
 costo_centavos BIGINT NOT NULL DEFAULT 0,
 descuento_centavos BIGINT NOT NULL DEFAULT 0,
 impuesto_pb INT NOT NULL DEFAULT 0,
 impuesto_centavos BIGINT NOT NULL DEFAULT 0,
 total_centavos BIGINT NOT NULL,
 entregado_milesimas BIGINT NOT NULL DEFAULT 0,
 devuelto_milesimas BIGINT NOT NULL DEFAULT 0,
 CONSTRAINT fk_erp_linea_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_erp_linea_producto FOREIGN KEY(producto_id) REFERENCES inventario_productos(id),
 CONSTRAINT fk_erp_linea_servicio FOREIGN KEY(servicio_id) REFERENCES servicios(id),
 CONSTRAINT ck_erp_linea_importes CHECK(cantidad_milesimas>0 AND cantidad_milesimas<=999999999 AND precio_centavos>=0 AND descuento_centavos>=0 AND total_centavos>=0 AND impuesto_pb BETWEEN 0 AND 10000),
 CONSTRAINT ck_erp_linea_entrega CHECK(entregado_milesimas>=0 AND entregado_milesimas<=cantidad_milesimas AND devuelto_milesimas>=0 AND devuelto_milesimas<=cantidad_milesimas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_pago_aplicaciones (
 pago_id INT PRIMARY KEY,
 venta_id BIGINT NOT NULL,
 sesion_id BIGINT NULL,
 importe_centavos BIGINT NOT NULL,
 origen ENUM('pdv','cita') NOT NULL,
 INDEX idx_erp_aplicacion_venta(venta_id),
 CONSTRAINT fk_erp_aplicacion_pago FOREIGN KEY(pago_id) REFERENCES pagos(id),
 CONSTRAINT fk_erp_aplicacion_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_erp_aplicacion_sesion FOREIGN KEY(sesion_id) REFERENCES erp_caja_sesiones(id),
 CONSTRAINT ck_erp_aplicacion_importe CHECK(importe_centavos>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_stock_reservas (
 linea_id BIGINT PRIMARY KEY,
 producto_id INT NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 estado ENUM('activa','consumida','liberada') NOT NULL DEFAULT 'activa',
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_reserva_producto(producto_id,estado),
 CONSTRAINT fk_erp_reserva_linea FOREIGN KEY(linea_id) REFERENCES erp_venta_lineas(id),
 CONSTRAINT ck_erp_reserva_qty CHECK(cantidad_milesimas>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_stock_origenes (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 movimiento_id BIGINT NOT NULL UNIQUE,
 venta_linea_id BIGINT NULL,
 documento_tipo VARCHAR(30) NOT NULL,
 documento_id BIGINT NOT NULL,
 INDEX idx_erp_stock_documento(documento_tipo,documento_id),
 CONSTRAINT fk_erp_stock_mov FOREIGN KEY(movimiento_id) REFERENCES inventario_movimientos(id),
 CONSTRAINT fk_erp_stock_linea FOREIGN KEY(venta_linea_id) REFERENCES erp_venta_lineas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_devoluciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 venta_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 monto_centavos BIGINT NOT NULL,
 cantidad_entregada_milesimas BIGINT NOT NULL DEFAULT 0,
 reintegrar_stock TINYINT NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 actor_id INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_devolucion_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_erp_devolucion_linea FOREIGN KEY(linea_id) REFERENCES erp_venta_lineas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_eventos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 tipo VARCHAR(60) NOT NULL,
 entidad VARCHAR(40) NOT NULL,
 entidad_id BIGINT NOT NULL,
 actor_id INT NOT NULL,
 datos_json JSON NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_evento_objeto(entidad,entidad_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO comprobantes_secuencia(serie,ultimo) VALUES('PV',0);
CREATE TABLE IF NOT EXISTS erp_recibos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(30) NOT NULL UNIQUE,
 venta_id BIGINT NOT NULL,
 evento_id BIGINT NOT NULL UNIQUE,
 contenido_json LONGTEXT NOT NULL,
 contenido_sha256 CHAR(64) NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_recibo_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_erp_recibo_evento FOREIGN KEY(evento_id) REFERENCES erp_eventos(id),
 CONSTRAINT ck_erp_recibo_json CHECK(JSON_VALID(contenido_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_erp_recibo_no_update;
DROP TRIGGER IF EXISTS trg_erp_recibo_no_delete;
CREATE TRIGGER trg_erp_recibo_no_update BEFORE UPDATE ON erp_recibos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El recibo ERP es de solo anexado';
CREATE TRIGGER trg_erp_recibo_no_delete BEFORE DELETE ON erp_recibos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El recibo ERP es de solo anexado';

CREATE TABLE IF NOT EXISTS erp_integraciones_outbox (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 destino ENUM('fiscal','contabilidad','nomina') NOT NULL,
 evento_id BIGINT NOT NULL,
 referencia VARCHAR(100) NOT NULL UNIQUE,
 estado ENUM('sin_configurar','pendiente','enviando','confirmado','fallido') NOT NULL DEFAULT 'sin_configurar',
 payload_json JSON NOT NULL,
 respuesta_json JSON NULL,
 intentos INT NOT NULL DEFAULT 0,
 lease_token CHAR(32) NULL,
 lease_hasta DATETIME NULL,
 proximo_intento DATETIME NULL,
 actualizado_en DATETIME NOT NULL,
 UNIQUE KEY uq_erp_outbox_evento(destino,evento_id),
 CONSTRAINT fk_erp_outbox_evento FOREIGN KEY(evento_id) REFERENCES erp_eventos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_erp_evento_no_update;
DROP TRIGGER IF EXISTS trg_erp_evento_no_delete;
CREATE TRIGGER trg_erp_evento_no_update BEFORE UPDATE ON erp_eventos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El evento ERP es de solo anexado';
CREATE TRIGGER trg_erp_evento_no_delete BEFORE DELETE ON erp_eventos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El evento ERP es de solo anexado';
