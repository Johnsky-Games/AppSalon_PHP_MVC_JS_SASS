-- El dinero vive en prepagos; las sesiones no son un segundo saldo monetario.
CREATE TABLE IF NOT EXISTS erp_paquete_planes (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(120) NOT NULL,
 servicio_id INT NOT NULL,
 sesiones INT NOT NULL,
 precio_centavos BIGINT NOT NULL,
 impuesto_pb INT NOT NULL,
 activo TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_paquete_plan_servicio FOREIGN KEY(servicio_id) REFERENCES servicios(id),
 CONSTRAINT ck_paquete_plan CHECK(sesiones BETWEEN 1 AND 100 AND precio_centavos BETWEEN 1 AND 99999999 AND impuesto_pb BETWEEN 0 AND 10000 AND activo IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_paquetes (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 plan_id BIGINT NOT NULL,
 prepago_id BIGINT NOT NULL UNIQUE,
 sesiones INT NOT NULL,
 precio_centavos BIGINT NOT NULL,
 impuesto_pb INT NOT NULL,
 importe_sesion BIGINT NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_paquete_plan FOREIGN KEY(plan_id) REFERENCES erp_paquete_planes(id),
 CONSTRAINT fk_paquete_prepago FOREIGN KEY(prepago_id) REFERENCES erp_prepagos(id),
 CONSTRAINT ck_paquete_importe CHECK(sesiones BETWEEN 1 AND 100 AND precio_centavos>0 AND impuesto_pb BETWEEN 0 AND 10000 AND importe_sesion=precio_centavos+((precio_centavos*impuesto_pb+5000) DIV 10000) AND importe_sesion*sesiones<=99999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_paquete_aplicaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 paquete_id BIGINT NOT NULL,
 venta_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL UNIQUE,
 pago_id INT NULL UNIQUE,
 operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 estado ENUM('reservada','consumida','restituida') NOT NULL DEFAULT 'reservada',
 importe_centavos BIGINT NOT NULL,
 actor_id INT NOT NULL,
 creado_en DATETIME NOT NULL,
 consumido_en DATETIME NULL,
 restitucion_id BIGINT NULL UNIQUE,
 INDEX idx_paquete_operacion(operacion),
 INDEX idx_paquete_uso(paquete_id,estado,id),
 CONSTRAINT fk_paquete_uso FOREIGN KEY(paquete_id) REFERENCES erp_paquetes(id),
 CONSTRAINT fk_paquete_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT fk_paquete_linea FOREIGN KEY(linea_id) REFERENCES erp_venta_lineas(id),
 CONSTRAINT fk_paquete_pago FOREIGN KEY(pago_id) REFERENCES pagos(id),
 CONSTRAINT fk_paquete_restitucion FOREIGN KEY(restitucion_id) REFERENCES erp_devoluciones(id),
 CONSTRAINT ck_paquete_uso CHECK(importe_centavos>0 AND ((estado='restituida' AND restitucion_id IS NOT NULL) OR (estado<>'restituida' AND restitucion_id IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_paquete_uso_no_delete;
CREATE TRIGGER trg_paquete_uso_no_delete BEFORE DELETE ON erp_paquete_aplicaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el historial de sesiones del paquete';
DROP TRIGGER IF EXISTS trg_paquete_no_update;
CREATE TRIGGER trg_paquete_no_update BEFORE UPDATE ON erp_paquetes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El contrato de sesiones y financiación es inmutable';
DROP TRIGGER IF EXISTS trg_paquete_no_delete;
CREATE TRIGGER trg_paquete_no_delete BEFORE DELETE ON erp_paquetes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el contrato del paquete';
DROP TRIGGER IF EXISTS trg_paquete_plan_no_update;
CREATE TRIGGER trg_paquete_plan_no_update BEFORE UPDATE ON erp_paquete_planes FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.nombre<>OLD.nombre OR NEW.servicio_id<>OLD.servicio_id OR NEW.sesiones<>OLD.sesiones OR NEW.precio_centavos<>OLD.precio_centavos OR NEW.impuesto_pb<>OLD.impuesto_pb OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cree una nueva versión del plan de sesiones'; END IF;
