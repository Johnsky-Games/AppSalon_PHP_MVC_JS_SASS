-- 051: tesorería bancaria. Cuentas bancarias ligadas a una cuenta contable de activo, extractos importados (solo de anexar) y conciliación
-- línea de extracto <-> línea del libro. No genera asientos: una partida del extracto sin registro en el libro se contabiliza con un asiento manual.
-- El número de cuenta bancaria NO se guarda completo: solo sus últimos dígitos para identificarla en pantalla.
CREATE TABLE IF NOT EXISTS erp_tes_cuentas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 cuenta_cont_id BIGINT NOT NULL,
 nombre VARCHAR(80) NOT NULL,
 entidad VARCHAR(80) NOT NULL,
 ultimos_digitos VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NULL,
 activa TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_tes_cuenta_cont (cuenta_cont_id),
 CONSTRAINT fk_tes_cuenta_cont FOREIGN KEY (cuenta_cont_id) REFERENCES erp_cont_cuentas(id),
 CONSTRAINT ck_tes_cuenta CHECK(activa IN(0,1) AND (ultimos_digitos IS NULL OR ultimos_digitos REGEXP '^[0-9]{2,6}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_tes_extractos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 tes_cuenta_id BIGINT NOT NULL,
 desde DATE NOT NULL,
 hasta DATE NOT NULL,
 saldo_inicial_centavos BIGINT NOT NULL,
 saldo_final_centavos BIGINT NOT NULL,
 n_lineas SMALLINT NOT NULL,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_tes_extracto_huella (tes_cuenta_id,huella),
 INDEX idx_tes_extracto_cuenta (tes_cuenta_id,hasta),
 CONSTRAINT fk_tes_extracto_cuenta FOREIGN KEY (tes_cuenta_id) REFERENCES erp_tes_cuentas(id),
 CONSTRAINT ck_tes_extracto CHECK(hasta>=desde AND n_lineas BETWEEN 1 AND 2000 AND ABS(saldo_inicial_centavos)<=99999999999 AND ABS(saldo_final_centavos)<=99999999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- monto_centavos con signo: positivo = dinero que entra al banco (debe de la cuenta), negativo = sale (haber).
CREATE TABLE IF NOT EXISTS erp_tes_extracto_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 extracto_id BIGINT NOT NULL,
 nro SMALLINT NOT NULL,
 fecha DATE NOT NULL,
 descripcion VARCHAR(160) NOT NULL,
 referencia VARCHAR(60) NULL,
 monto_centavos BIGINT NOT NULL,
 UNIQUE KEY uq_tes_extracto_linea (extracto_id,nro),
 INDEX idx_tes_linea_monto (monto_centavos,fecha),
 CONSTRAINT fk_tes_linea_extracto FOREIGN KEY (extracto_id) REFERENCES erp_tes_extractos(id),
 CONSTRAINT ck_tes_linea CHECK(monto_centavos<>0 AND ABS(monto_centavos)<=99999999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un vínculo vigente por línea de extracto y por línea del libro. Deshacer cambia el estado (con motivo) y deja la historia.
CREATE TABLE IF NOT EXISTS erp_tes_conciliaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 extracto_linea_id BIGINT NOT NULL,
 cont_linea_id BIGINT NOT NULL,
 metodo ENUM('automatica','manual') NOT NULL,
 estado ENUM('vigente','deshecha') NOT NULL DEFAULT 'vigente',
 vigente_ext BIGINT GENERATED ALWAYS AS (IF(estado='vigente',extracto_linea_id,NULL)) STORED,
 vigente_cont BIGINT GENERATED ALWAYS AS (IF(estado='vigente',cont_linea_id,NULL)) STORED,
 nota VARCHAR(160) NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 deshecho_por INT NULL,
 deshecho_en DATETIME NULL,
 motivo_deshecho VARCHAR(160) NULL,
 UNIQUE KEY uq_tes_conc_ext (vigente_ext),
 UNIQUE KEY uq_tes_conc_cont (vigente_cont),
 INDEX idx_tes_conc_ext (extracto_linea_id),
 CONSTRAINT fk_tes_conc_ext FOREIGN KEY (extracto_linea_id) REFERENCES erp_tes_extracto_lineas(id),
 CONSTRAINT fk_tes_conc_cont FOREIGN KEY (cont_linea_id) REFERENCES erp_cont_lineas(id),
 CONSTRAINT ck_tes_conc CHECK((estado='deshecha') = (deshecho_en IS NOT NULL AND deshecho_por IS NOT NULL AND motivo_deshecho IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_tes_extracto_no_update;
DROP TRIGGER IF EXISTS trg_tes_extracto_no_delete;
DROP TRIGGER IF EXISTS trg_tes_linea_no_update;
DROP TRIGGER IF EXISTS trg_tes_linea_no_delete;
DROP TRIGGER IF EXISTS trg_tes_conc_update;
DROP TRIGGER IF EXISTS trg_tes_conc_no_delete;
CREATE TRIGGER trg_tes_extracto_no_update BEFORE UPDATE ON erp_tes_extractos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un extracto importado no se edita';
CREATE TRIGGER trg_tes_extracto_no_delete BEFORE DELETE ON erp_tes_extractos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un extracto importado se conserva';
CREATE TRIGGER trg_tes_linea_no_update BEFORE UPDATE ON erp_tes_extracto_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lineas del extracto no se editan';
CREATE TRIGGER trg_tes_linea_no_delete BEFORE DELETE ON erp_tes_extracto_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lineas del extracto se conservan';
-- Solo se admite pasar de vigente a deshecha con su motivo: nada más de la conciliación cambia.
CREATE TRIGGER trg_tes_conc_update BEFORE UPDATE ON erp_tes_conciliaciones FOR EACH ROW IF OLD.estado<>'vigente' OR NEW.estado<>'deshecha' OR NEW.extracto_linea_id<>OLD.extracto_linea_id OR NEW.cont_linea_id<>OLD.cont_linea_id OR NEW.metodo<>OLD.metodo OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La conciliacion solo se deshace con su motivo'; END IF;
CREATE TRIGGER trg_tes_conc_no_delete BEFORE DELETE ON erp_tes_conciliaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La conciliacion se conserva';
