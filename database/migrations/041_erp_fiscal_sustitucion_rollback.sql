-- Solo sin sustituciones ni verificaciones registradas (la guarda lo verifica).
DROP TRIGGER IF EXISTS trg_fiscal_documento_fijo;
CREATE TRIGGER trg_fiscal_documento_fijo BEFORE UPDATE ON erp_fiscal_documentos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.perfil_id<>OLD.perfil_id OR NEW.tipo<>OLD.tipo OR NOT(NEW.documento_origen_id<=>OLD.documento_origen_id) OR NEW.venta_id<>OLD.venta_id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.huella<>OLD.huella OR NEW.clave_acceso<>OLD.clave_acceso OR NEW.numero<>OLD.numero OR NOT(NEW.snapshot_json<=>OLD.snapshot_json) OR BINARY NEW.xml_firmado<>BINARY OLD.xml_firmado OR NEW.xml_sha256<>OLD.xml_sha256 OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en OR (OLD.estado IN('autorizada','rechazada') AND (NEW.estado<>OLD.estado OR NOT(NEW.respuesta_json<=>OLD.respuesta_json))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El comprobante fiscal conserva su identidad y contenido'; END IF;
DROP TABLE IF EXISTS erp_fiscal_verificaciones;
ALTER TABLE erp_fiscal_documentos
 ADD UNIQUE KEY uq_fiscal_factura_unica ((IF(tipo='01',venta_id,NULL))),
 DROP INDEX uq_fiscal_factura_raiz,
 DROP FOREIGN KEY fk_fiscal_sustituye,
 DROP INDEX uq_fiscal_sustituto,
 DROP COLUMN sustituye_a_id;
