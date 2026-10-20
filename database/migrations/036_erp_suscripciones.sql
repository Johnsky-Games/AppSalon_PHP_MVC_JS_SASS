CREATE TABLE IF NOT EXISTS erp_suscripciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 contacto_id INT NOT NULL,
 plan_id BIGINT NOT NULL,
 inicio DATE NOT NULL,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 estado ENUM('activa','pausada','cancelada') NOT NULL DEFAULT 'activa',
 consentimiento VARCHAR(300) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 proximo_intento DATETIME NULL,
 ultimo_error VARCHAR(100) NULL,
 INDEX idx_suscripcion_estado(estado,inicio,id),
 CONSTRAINT fk_suscripcion_contacto FOREIGN KEY(contacto_id) REFERENCES erp_contactos(id),
 CONSTRAINT fk_suscripcion_plan FOREIGN KEY(plan_id) REFERENCES erp_paquete_planes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_suscripcion_ciclos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 suscripcion_id BIGINT NOT NULL,
 desde DATE NOT NULL,
 hasta DATE NOT NULL,
 paquete_id BIGINT NOT NULL UNIQUE,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_suscripcion_ciclo(suscripcion_id,desde),
 CONSTRAINT fk_suscripcion_ciclo FOREIGN KEY(suscripcion_id) REFERENCES erp_suscripciones(id),
 CONSTRAINT fk_ciclo_paquete FOREIGN KEY(paquete_id) REFERENCES erp_paquetes(id),
 CONSTRAINT ck_ciclo_periodo CHECK(hasta>desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_suscripcion_ciclo_no_update;
CREATE TRIGGER trg_suscripcion_ciclo_no_update BEFORE UPDATE ON erp_suscripcion_ciclos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El ciclo conserva sus condiciones originales';
DROP TRIGGER IF EXISTS trg_suscripcion_ciclo_no_delete;
CREATE TRIGGER trg_suscripcion_ciclo_no_delete BEFORE DELETE ON erp_suscripcion_ciclos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve ciclos, financiación y sesiones';
DROP TRIGGER IF EXISTS trg_suscripcion_contrato_fijo;
CREATE TRIGGER trg_suscripcion_contrato_fijo BEFORE UPDATE ON erp_suscripciones FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.contacto_id<>OLD.contacto_id OR NEW.plan_id<>OLD.plan_id OR NEW.inicio<>OLD.inicio OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.huella<>OLD.huella OR NEW.consentimiento<>OLD.consentimiento OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve las condiciones originales del contrato'; END IF;
