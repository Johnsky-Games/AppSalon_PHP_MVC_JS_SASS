-- Parámetros fiscales versionados por régimen y vigencia, y estado «bloqueada» para sustitutos cuyo original apareció autorizado
-- antes de transmitirse. Aditiva sobre 041: ninguna garantía se elimina. Los parámetros son de solo anexar: una corrección es una
-- versión nueva con otra vigencia; «derogado» retira una regla sin borrar su historia.
CREATE TABLE IF NOT EXISTS erp_fiscal_parametros (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 regimen VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '*',
 vigente_desde DATE NOT NULL,
 estado ENUM('vigente','derogado') NOT NULL DEFAULT 'vigente',
 valor_entero BIGINT NULL,
 valor_texto VARCHAR(160) NULL,
 fuente VARCHAR(200) NOT NULL,
 fuente_url VARCHAR(300) NULL,
 consultado_en DATE NOT NULL,
 nota VARCHAR(300) NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_fiscal_parametro_version (clave,regimen,vigente_desde),
 INDEX idx_fiscal_parametro_clave (clave,regimen,vigente_desde),
 CONSTRAINT ck_fiscal_parametro_valor CHECK((estado='derogado' AND valor_entero IS NULL AND valor_texto IS NULL) OR (estado='vigente' AND ((valor_entero IS NULL) <> (valor_texto IS NULL))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_fiscal_parametro_no_update;
CREATE TRIGGER trg_fiscal_parametro_no_update BEFORE UPDATE ON erp_fiscal_parametros FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Registra una versión nueva del parámetro fiscal';
DROP TRIGGER IF EXISTS trg_fiscal_parametro_no_delete;
CREATE TRIGGER trg_fiscal_parametro_no_delete BEFORE DELETE ON erp_fiscal_parametros FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el historial de parámetros fiscales';

ALTER TABLE erp_fiscal_documentos MODIFY estado ENUM('pendiente','recibida','autorizada','rechazada','bloqueada') NOT NULL DEFAULT 'pendiente';
DROP TRIGGER IF EXISTS trg_fiscal_documento_fijo;
CREATE TRIGGER trg_fiscal_documento_fijo BEFORE UPDATE ON erp_fiscal_documentos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.perfil_id<>OLD.perfil_id OR NEW.tipo<>OLD.tipo OR NOT(NEW.documento_origen_id<=>OLD.documento_origen_id) OR NOT(NEW.sustituye_a_id<=>OLD.sustituye_a_id) OR NEW.venta_id<>OLD.venta_id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.huella<>OLD.huella OR NEW.clave_acceso<>OLD.clave_acceso OR NEW.numero<>OLD.numero OR NOT(NEW.snapshot_json<=>OLD.snapshot_json) OR BINARY NEW.xml_firmado<>BINARY OLD.xml_firmado OR NEW.xml_sha256<>OLD.xml_sha256 OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en OR (OLD.estado IN('autorizada','rechazada','bloqueada') AND (NEW.estado<>OLD.estado OR NOT(NEW.respuesta_json<=>OLD.respuesta_json))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El comprobante fiscal conserva su identidad y contenido'; END IF;
