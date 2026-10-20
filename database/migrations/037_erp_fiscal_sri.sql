-- Emisión nativa en ambiente de certificación. Producción requiere el cierre del piloto fiscal.
CREATE TABLE IF NOT EXISTS erp_fiscal_perfiles (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 ruc CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ambiente CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 establecimiento CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 punto_emision CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 datos_json JSON NOT NULL,
 certificado_cifrado MEDIUMTEXT NOT NULL,
 certificado_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT ck_fiscal_piloto CHECK(ambiente='1')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_fiscal_secuencias (
 ruc CHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ambiente CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 serie CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 documento CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ultimo BIGINT NOT NULL DEFAULT 0,
 PRIMARY KEY(ruc,ambiente,serie,documento),
 CONSTRAINT ck_fiscal_secuencia CHECK(ultimo>=0 AND ultimo<=999999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_fiscal_documentos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 perfil_id BIGINT NOT NULL,
 venta_id BIGINT NOT NULL,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 clave_acceso CHAR(49) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 numero CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_json JSON NOT NULL,
 xml_firmado MEDIUMTEXT NOT NULL,
 xml_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 estado ENUM('pendiente','recibida','autorizada','rechazada') NOT NULL DEFAULT 'pendiente',
 respuesta_json JSON NULL,
 intentos INT NOT NULL DEFAULT 0,
 proximo_intento DATETIME NULL,
 lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 lease_hasta DATETIME NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 actualizado_en DATETIME NOT NULL,
 UNIQUE KEY uq_fiscal_factura_venta(venta_id),
 INDEX idx_fiscal_proceso(estado,proximo_intento,lease_hasta,id),
 CONSTRAINT fk_fiscal_perfil FOREIGN KEY(perfil_id) REFERENCES erp_fiscal_perfiles(id),
 CONSTRAINT fk_fiscal_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_fiscal_perfil_no_update;
CREATE TRIGGER trg_fiscal_perfil_no_update BEFORE UPDATE ON erp_fiscal_perfiles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve versiones de emisor y certificado';
DROP TRIGGER IF EXISTS trg_fiscal_perfil_no_delete;
CREATE TRIGGER trg_fiscal_perfil_no_delete BEFORE DELETE ON erp_fiscal_perfiles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve versiones de emisor y certificado';
DROP TRIGGER IF EXISTS trg_fiscal_documento_no_delete;
CREATE TRIGGER trg_fiscal_documento_no_delete BEFORE DELETE ON erp_fiscal_documentos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve la evidencia fiscal';
DROP TRIGGER IF EXISTS trg_fiscal_documento_fijo;
CREATE TRIGGER trg_fiscal_documento_fijo BEFORE UPDATE ON erp_fiscal_documentos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.perfil_id<>OLD.perfil_id OR NEW.venta_id<>OLD.venta_id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.huella<>OLD.huella OR NEW.clave_acceso<>OLD.clave_acceso OR NEW.numero<>OLD.numero OR NOT(NEW.snapshot_json<=>OLD.snapshot_json) OR BINARY NEW.xml_firmado<>BINARY OLD.xml_firmado OR NEW.xml_sha256<>OLD.xml_sha256 OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en OR (OLD.estado IN('autorizada','rechazada') AND (NEW.estado<>OLD.estado OR NOT(NEW.respuesta_json<=>OLD.respuesta_json))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El comprobante fiscal conserva su identidad y contenido'; END IF;
