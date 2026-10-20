-- 050: identificación tributaria del trabajador (cédula o pasaporte), necesaria para el formulario 107 y el anexo RDEP.
-- Solo de anexar: la última declaración del trabajador rige y las anteriores se conservan (corrección = nueva fila). No contiene formatos ni casilleros oficiales:
-- el resto de los datos del anexo se agregará cuando se cuente con la ficha técnica y el catálogo vigentes del SRI.
CREATE TABLE IF NOT EXISTS erp_rh_identificaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 colaborador_id INT NOT NULL,
 tipo ENUM('cedula','pasaporte') NOT NULL,
 numero VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 nota VARCHAR(200) NULL,
 registrado_por INT NOT NULL,
 registrado_en DATETIME NOT NULL,
 UNIQUE KEY uq_rh_ident_clave (clave_operacion),
 INDEX idx_rh_ident_colaborador (colaborador_id,id),
 INDEX idx_rh_ident_numero (tipo,numero),
 CONSTRAINT fk_rh_ident_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_rh_ident CHECK(CHAR_LENGTH(numero) BETWEEN 5 AND 20 AND (tipo<>'cedula' OR numero REGEXP '^[0-9]{10}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_rh_ident_no_update;
DROP TRIGGER IF EXISTS trg_rh_ident_no_delete;
CREATE TRIGGER trg_rh_ident_no_update BEFORE UPDATE ON erp_rh_identificaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La identificación no se edita: se declara una nueva';
CREATE TRIGGER trg_rh_ident_no_delete BEFORE DELETE ON erp_rh_identificaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La identificación se conserva';
