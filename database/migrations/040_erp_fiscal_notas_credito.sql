-- Notas de crédito fiscales (codDoc 04): la identidad pasa a incluir el tipo de documento.
-- Aditiva: los documentos existentes quedan como factura '01'; ninguna garantía se elimina sin su reemplazo.
ALTER TABLE erp_fiscal_documentos
 ADD COLUMN tipo CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '01' AFTER perfil_id,
 ADD COLUMN documento_origen_id BIGINT NULL AFTER tipo,
 ADD INDEX idx_fiscal_venta (venta_id),
 ADD INDEX idx_fiscal_origen (documento_origen_id),
 ADD CONSTRAINT ck_fiscal_tipo CHECK ((tipo='01' AND documento_origen_id IS NULL) OR (tipo='04' AND documento_origen_id IS NOT NULL)),
 ADD CONSTRAINT fk_fiscal_origen FOREIGN KEY (documento_origen_id) REFERENCES erp_fiscal_documentos(id);
-- Índice funcional (sin columna generada: los respaldos portables no escriben columnas generadas). Una factura por venta (como antes), pero ya no una sola fila fiscal por venta: las notas de crédito comparten la venta.
ALTER TABLE erp_fiscal_documentos
 ADD UNIQUE KEY uq_fiscal_factura_unica ((IF(tipo='01',venta_id,NULL))),
 DROP INDEX uq_fiscal_factura_venta;
-- Facturas y notas pueden compartir serie y secuencial; se distinguen por tipo, emisor y ambiente.
ALTER TABLE erp_fiscal_documentos
 ADD UNIQUE KEY uq_fiscal_identidad_documento (
  (CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc')) AS CHAR(13))),
  (CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente')) AS CHAR(1))),
  tipo,
  numero
 ),
 DROP INDEX uq_fiscal_numero_emisor;
CREATE TABLE IF NOT EXISTS erp_fiscal_nc_devoluciones (
 documento_id BIGINT NOT NULL,
 devolucion_id BIGINT NOT NULL,
 PRIMARY KEY (documento_id, devolucion_id),
 UNIQUE KEY uq_fiscal_nc_devolucion (devolucion_id),
 CONSTRAINT fk_fiscal_ncd_doc FOREIGN KEY (documento_id) REFERENCES erp_fiscal_documentos(id),
 CONSTRAINT fk_fiscal_ncd_dev FOREIGN KEY (devolucion_id) REFERENCES erp_devoluciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_fiscal_nc_lineas (
 documento_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 base_centavos BIGINT NOT NULL,
 iva_centavos BIGINT NOT NULL,
 total_centavos BIGINT NOT NULL,
 PRIMARY KEY (documento_id, linea_id),
 INDEX idx_fiscal_ncl_linea (linea_id),
 CONSTRAINT fk_fiscal_ncl_doc FOREIGN KEY (documento_id) REFERENCES erp_fiscal_documentos(id),
 CONSTRAINT fk_fiscal_ncl_linea FOREIGN KEY (linea_id) REFERENCES erp_venta_lineas(id),
 CONSTRAINT ck_fiscal_ncl CHECK (cantidad_milesimas>0 AND base_centavos>=0 AND iva_centavos>=0 AND total_centavos=base_centavos+iva_centavos AND total_centavos>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_fiscal_ncd_no_update;
CREATE TRIGGER trg_fiscal_ncd_no_update BEFORE UPDATE ON erp_fiscal_nc_devoluciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve la evidencia fiscal';
DROP TRIGGER IF EXISTS trg_fiscal_ncd_no_delete;
CREATE TRIGGER trg_fiscal_ncd_no_delete BEFORE DELETE ON erp_fiscal_nc_devoluciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve la evidencia fiscal';
DROP TRIGGER IF EXISTS trg_fiscal_ncl_no_update;
CREATE TRIGGER trg_fiscal_ncl_no_update BEFORE UPDATE ON erp_fiscal_nc_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve la evidencia fiscal';
DROP TRIGGER IF EXISTS trg_fiscal_ncl_no_delete;
CREATE TRIGGER trg_fiscal_ncl_no_delete BEFORE DELETE ON erp_fiscal_nc_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve la evidencia fiscal';
DROP TRIGGER IF EXISTS trg_fiscal_documento_fijo;
CREATE TRIGGER trg_fiscal_documento_fijo BEFORE UPDATE ON erp_fiscal_documentos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.perfil_id<>OLD.perfil_id OR NEW.tipo<>OLD.tipo OR NOT(NEW.documento_origen_id<=>OLD.documento_origen_id) OR NEW.venta_id<>OLD.venta_id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.huella<>OLD.huella OR NEW.clave_acceso<>OLD.clave_acceso OR NEW.numero<>OLD.numero OR NOT(NEW.snapshot_json<=>OLD.snapshot_json) OR BINARY NEW.xml_firmado<>BINARY OLD.xml_firmado OR NEW.xml_sha256<>OLD.xml_sha256 OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en OR (OLD.estado IN('autorizada','rechazada') AND (NEW.estado<>OLD.estado OR NOT(NEW.respuesta_json<=>OLD.respuesta_json))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El comprobante fiscal conserva su identidad y contenido'; END IF;
