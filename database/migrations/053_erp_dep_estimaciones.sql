-- 053: cambios de estimación PROSPECTIVOS de la depreciación (vida restante y/o valor residual de un activo ya en depreciación).
-- Un cambio de estimación es un cambio contable que aplica hacia adelante: lo depreciado hasta el mes anterior queda como está (no se anula ni se recalcula) y
-- el saldo por depreciar se reparte en la vida restante desde el mes de vigencia. La fila guarda la foto del acumulado a esa fecha. Solo de anexar: se puede anular
-- (antes de que exista una corrida del activo desde esa vigencia) pero no editar ni borrar. No contiene cifras ni criterios tributarios.
CREATE TABLE IF NOT EXISTS erp_dep_estimaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 ficha_id BIGINT NOT NULL,
 activo_id INT NOT NULL,
 desde DATE NOT NULL,
 acumulado_previo_centavos BIGINT NOT NULL,
 residual_centavos BIGINT NOT NULL,
 base_centavos BIGINT NOT NULL,
 vida_meses SMALLINT NOT NULL,
 criterio VARCHAR(300) NOT NULL,
 estado ENUM('vigente','anulada') NOT NULL DEFAULT 'vigente',
 desde_vigente DATE GENERATED ALWAYS AS (IF(estado='vigente',desde,NULL)) STORED,
 evento_id BIGINT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 anulada_por INT NULL,
 anulada_en DATETIME NULL,
 anulacion_motivo VARCHAR(300) NULL,
 UNIQUE KEY uq_dep_est_vigente (ficha_id,desde_vigente),
 KEY idx_dep_est_activo (activo_id,desde),
 CONSTRAINT fk_dep_est_ficha FOREIGN KEY (ficha_id) REFERENCES erp_dep_fichas(id),
 CONSTRAINT fk_dep_est_activo FOREIGN KEY (activo_id) REFERENCES erp_activos(id),
 CONSTRAINT ck_dep_est CHECK(DAY(desde)=1 AND acumulado_previo_centavos>=0 AND residual_centavos>=0 AND base_centavos>0 AND vida_meses BETWEEN 1 AND 600 AND CHAR_LENGTH(criterio)>=5 AND ((estado='anulada') = (anulada_en IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_dep_est_update;
DROP TRIGGER IF EXISTS trg_dep_est_no_delete;
CREATE TRIGGER trg_dep_est_update BEFORE UPDATE ON erp_dep_estimaciones FOR EACH ROW IF OLD.estado<>'vigente' OR NEW.estado<>'anulada' OR NEW.ficha_id<>OLD.ficha_id OR NEW.activo_id<>OLD.activo_id OR NEW.desde<>OLD.desde OR NEW.acumulado_previo_centavos<>OLD.acumulado_previo_centavos OR NEW.residual_centavos<>OLD.residual_centavos OR NEW.base_centavos<>OLD.base_centavos OR NEW.vida_meses<>OLD.vida_meses OR NEW.criterio<>OLD.criterio THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La estimacion solo se anula con su motivo'; END IF;
CREATE TRIGGER trg_dep_est_no_delete BEFORE DELETE ON erp_dep_estimaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La estimacion de depreciacion se conserva';
