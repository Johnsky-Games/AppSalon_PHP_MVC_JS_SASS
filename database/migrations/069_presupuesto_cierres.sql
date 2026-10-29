-- 069: cierre MENSUAL de presupuestos con referencia a la foto inmutable (068). Decisión del propietario: el cierre mensual usa la foto como referencia oficial de lo informado.
-- Un cierre vincula explícitamente: presupuesto + mes + foto elegida + la versión APROBADA con la que se fotografió. La foto debe estar cortada el último día de ese mes.
-- Una corrección del cierre es un cierre NUEVO (número siguiente, motivo obligatorio, corrige_cierre_id) que apunta a otra foto (p. ej. la corrección de la foto): el anterior y su
-- foto se conservan y no se editan ni se borran (triggers). Esto NO es el cierre del período contable: no escribe en el libro, no cierra períodos ni genera asientos.
CREATE TABLE IF NOT EXISTS erp_presupuesto_cierres (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 presupuesto_id BIGINT NOT NULL,
 anio SMALLINT NOT NULL,
 mes TINYINT NOT NULL,
 foto_id BIGINT NOT NULL,
 version_id BIGINT NOT NULL,
 numero INT NOT NULL,
 corrige_cierre_id BIGINT NULL,
 motivo VARCHAR(200) NULL,
 cerrado_por INT NOT NULL,
 cerrado_en DATETIME NOT NULL,
 UNIQUE KEY uq_presup_cierre (presupuesto_id,mes,numero),
 UNIQUE KEY uq_presup_cierre_corrige (corrige_cierre_id),
 CONSTRAINT fk_presup_cierre_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES erp_presupuestos(id),
 CONSTRAINT fk_presup_cierre_foto FOREIGN KEY (foto_id) REFERENCES erp_presupuesto_fotos(id),
 CONSTRAINT fk_presup_cierre_version FOREIGN KEY (version_id) REFERENCES erp_presupuesto_versiones(id),
 CONSTRAINT fk_presup_cierre_corrige FOREIGN KEY (corrige_cierre_id) REFERENCES erp_presupuesto_cierres(id),
 CONSTRAINT ck_presup_cierre CHECK(mes BETWEEN 1 AND 12 AND numero>=1 AND (numero=1)=(corrige_cierre_id IS NULL) AND (corrige_cierre_id IS NULL)=(motivo IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_presup_cierre_insert;
DROP TRIGGER IF EXISTS trg_presup_cierre_no_update;
DROP TRIGGER IF EXISTS trg_presup_cierre_no_delete;
-- La base exige que el cierre sea coherente con su foto: mismo presupuesto, misma versión aprobada, mismo año y corte en el último día del mes; y que una corrección sea del mismo presupuesto y mes.
-- Triggers de una sola sentencia (IF ... END IF) para que la restauración los reponga.
CREATE TRIGGER trg_presup_cierre_insert BEFORE INSERT ON erp_presupuesto_cierres FOR EACH ROW IF (SELECT presupuesto_id FROM erp_presupuesto_fotos WHERE id=NEW.foto_id)<>NEW.presupuesto_id
  OR (SELECT version_id FROM erp_presupuesto_fotos WHERE id=NEW.foto_id)<>NEW.version_id OR (SELECT anio FROM erp_presupuesto_fotos WHERE id=NEW.foto_id)<>NEW.anio
  OR (SELECT corte FROM erp_presupuesto_fotos WHERE id=NEW.foto_id)<>LAST_DAY(STR_TO_DATE(CONCAT(NEW.anio,'-',NEW.mes,'-01'),'%Y-%m-%d'))
  OR (NEW.corrige_cierre_id IS NOT NULL AND (SELECT CONCAT(presupuesto_id,'-',mes) FROM erp_presupuesto_cierres WHERE id=NEW.corrige_cierre_id)<>CONCAT(NEW.presupuesto_id,'-',NEW.mes))
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El cierre no coincide con su foto: presupuesto, version aprobada, anio y corte al ultimo dia del mes'; END IF;
CREATE TRIGGER trg_presup_cierre_no_update BEFORE UPDATE ON erp_presupuesto_cierres FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un cierre mensual no se edita: se corrige con un cierre nuevo vinculado';
CREATE TRIGGER trg_presup_cierre_no_delete BEFORE DELETE ON erp_presupuesto_cierres FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los cierres mensuales se conservan';

-- Vista BI (solo lectura, sin personas ni columnas de fecha). es_vigente = ningún cierre posterior lo corrige.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_cierres AS
SELECT DISTINCT c.id AS cierre_id, c.presupuesto_id, p.anio, p.escenario, c.mes, (p.anio * 100 + c.mes) AS anio_mes, c.numero AS cierre_numero, c.corrige_cierre_id,
 NOT EXISTS (SELECT 1 FROM erp_presupuesto_cierres o WHERE o.corrige_cierre_id = c.id) AS es_vigente,
 c.foto_id, f.numero AS foto_numero, c.version_id, v.numero AS version_presupuesto_numero, (YEAR(c.cerrado_en) * 100 + MONTH(c.cerrado_en)) AS cerrado_anio_mes
FROM erp_presupuesto_cierres c JOIN erp_presupuestos p ON p.id = c.presupuesto_id JOIN erp_presupuesto_fotos f ON f.id = c.foto_id JOIN erp_presupuesto_versiones v ON v.id = c.version_id;
