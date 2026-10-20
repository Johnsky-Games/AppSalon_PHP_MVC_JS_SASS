-- 049: control de avisos al IESS. Registra que el aviso de entrada o de salida de un colaborador se presentó y con qué referencia del portal.
-- No contiene plazos ni formatos oficiales: esos datos no se pudieron verificar y no se asumen. Solo de anexar: un aviso se anula con motivo, no se edita ni se borra.
CREATE TABLE IF NOT EXISTS erp_iess_avisos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 colaborador_id INT NOT NULL,
 contrato_id BIGINT NOT NULL,
 tipo ENUM('entrada','salida') NOT NULL,
 fecha_evento DATE NOT NULL,
 referencia VARCHAR(60) NOT NULL,
 estado ENUM('registrado','anulado') NOT NULL DEFAULT 'registrado',
 activo_clave VARCHAR(40) GENERATED ALWAYS AS (IF(estado='registrado',CONCAT(colaborador_id,':',tipo,':',fecha_evento),NULL)) STORED,
 registrado_por INT NOT NULL,
 registrado_en DATETIME NOT NULL,
 anulado_por INT NULL,
 anulado_en DATETIME NULL,
 motivo_anulacion VARCHAR(300) NULL,
 UNIQUE KEY uq_iess_aviso_clave (clave_operacion),
 UNIQUE KEY uq_iess_aviso_activo (activo_clave),
 INDEX idx_iess_aviso_colaborador (colaborador_id,tipo,fecha_evento),
 CONSTRAINT fk_iess_aviso_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_iess_aviso_contrato FOREIGN KEY (contrato_id) REFERENCES erp_contratos(id),
 CONSTRAINT ck_iess_aviso CHECK(CHAR_LENGTH(referencia)>=3 AND ((estado='registrado' AND anulado_por IS NULL AND anulado_en IS NULL AND motivo_anulacion IS NULL) OR (estado='anulado' AND anulado_por IS NOT NULL AND anulado_en IS NOT NULL AND CHAR_LENGTH(motivo_anulacion)>=5)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_iess_aviso_fijo;
DROP TRIGGER IF EXISTS trg_iess_aviso_no_delete;
CREATE TRIGGER trg_iess_aviso_fijo BEFORE UPDATE ON erp_iess_avisos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.colaborador_id<>OLD.colaborador_id OR NEW.contrato_id<>OLD.contrato_id OR NEW.tipo<>OLD.tipo OR NEW.fecha_evento<>OLD.fecha_evento OR NEW.referencia<>OLD.referencia OR NEW.registrado_por<>OLD.registrado_por OR NEW.registrado_en<>OLD.registrado_en OR OLD.estado='anulado' OR NEW.estado<>'anulado'
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El aviso no se edita: solo se anula'; END IF;
CREATE TRIGGER trg_iess_aviso_no_delete BEFORE DELETE ON erp_iess_avisos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los avisos se anulan, no se borran';
