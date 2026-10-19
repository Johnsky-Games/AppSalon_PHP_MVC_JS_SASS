-- Corrección de comprobantes rechazados por SUSTITUCIÓN: el rechazado se conserva intacto y un documento nuevo (otro número y otra clave)
-- lo sustituye. Aditiva sobre 040; ninguna garantía se elimina sin reemplazo.
ALTER TABLE erp_fiscal_documentos
 ADD COLUMN sustituye_a_id BIGINT NULL AFTER documento_origen_id,
 ADD CONSTRAINT fk_fiscal_sustituye FOREIGN KEY (sustituye_a_id) REFERENCES erp_fiscal_documentos(id),
 ADD UNIQUE KEY uq_fiscal_sustituto (sustituye_a_id);
-- Una factura RAÍZ por venta (como antes); una sustituta solo existe como sucesora única de una rechazada.
ALTER TABLE erp_fiscal_documentos
 ADD UNIQUE KEY uq_fiscal_factura_raiz ((IF(tipo='01' AND sustituye_a_id IS NULL,venta_id,NULL))),
 DROP INDEX uq_fiscal_factura_unica;
CREATE TABLE IF NOT EXISTS erp_fiscal_verificaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 documento_id BIGINT NOT NULL,
 estado ENUM('solicitada','confirmado','autorizado_en_sri','incierta') NOT NULL DEFAULT 'solicitada',
 solicitado_por INT NOT NULL,
 solicitado_en DATETIME NOT NULL,
 respuesta_json JSON NULL,
 verificado_en DATETIME NULL,
 intentos INT NOT NULL DEFAULT 0,
 proximo_intento DATETIME NULL,
 lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 lease_hasta DATETIME NULL,
 UNIQUE KEY uq_fiscal_verificacion_abierta ((IF(estado IN ('solicitada','incierta'),documento_id,NULL))),
 INDEX idx_fiscal_verificacion_proceso (estado,proximo_intento,lease_hasta,id),
 INDEX idx_fiscal_verificacion_doc (documento_id,id),
 CONSTRAINT fk_fiscal_verificacion_doc FOREIGN KEY (documento_id) REFERENCES erp_fiscal_documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_fiscal_verificacion_no_delete;
CREATE TRIGGER trg_fiscal_verificacion_no_delete BEFORE DELETE ON erp_fiscal_verificaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve la evidencia fiscal';
DROP TRIGGER IF EXISTS trg_fiscal_verificacion_fija;
CREATE TRIGGER trg_fiscal_verificacion_fija BEFORE UPDATE ON erp_fiscal_verificaciones FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.documento_id<>OLD.documento_id OR NEW.solicitado_por<>OLD.solicitado_por OR NEW.solicitado_en<>OLD.solicitado_en OR (OLD.estado IN('confirmado','autorizado_en_sri') AND (NEW.estado<>OLD.estado OR NOT(NEW.respuesta_json<=>OLD.respuesta_json) OR NOT(NEW.verificado_en<=>OLD.verificado_en))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La verificación fiscal conserva su resultado'; END IF;
DROP TRIGGER IF EXISTS trg_fiscal_documento_fijo;
CREATE TRIGGER trg_fiscal_documento_fijo BEFORE UPDATE ON erp_fiscal_documentos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.perfil_id<>OLD.perfil_id OR NEW.tipo<>OLD.tipo OR NOT(NEW.documento_origen_id<=>OLD.documento_origen_id) OR NOT(NEW.sustituye_a_id<=>OLD.sustituye_a_id) OR NEW.venta_id<>OLD.venta_id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.huella<>OLD.huella OR NEW.clave_acceso<>OLD.clave_acceso OR NEW.numero<>OLD.numero OR NOT(NEW.snapshot_json<=>OLD.snapshot_json) OR BINARY NEW.xml_firmado<>BINARY OLD.xml_firmado OR NEW.xml_sha256<>OLD.xml_sha256 OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en OR (OLD.estado IN('autorizada','rechazada') AND (NEW.estado<>OLD.estado OR NOT(NEW.respuesta_json<=>OLD.respuesta_json))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El comprobante fiscal conserva su identidad y contenido'; END IF;
