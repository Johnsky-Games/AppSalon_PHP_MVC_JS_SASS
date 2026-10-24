-- 068: foto inmutable del comparativo de presupuestos al cierre de un corte. Congela, para un presupuesto (año + escenario), la versión APROBADA, la fecha de corte, el
-- criterio oficial (por eventos, 066/067), la versión del cálculo y los componentes reales por mes y concepto, con quién la generó y cuándo.
-- Una foto NO se edita ni se borra (triggers). Una corrección genera una foto NUEVA con número siguiente, vinculada a la anterior (corrige_foto_id) y con motivo: las dos se conservan.
-- No genera ventas, cobros ni asientos y no cambia el presupuesto: solo guarda una lectura. La huella (sha256 del contenido canónico) permite comprobar que no cambió.
CREATE TABLE IF NOT EXISTS erp_presupuesto_fotos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 presupuesto_id BIGINT NOT NULL,
 version_id BIGINT NOT NULL,
 anio SMALLINT NOT NULL,
 corte DATE NOT NULL,
 numero INT NOT NULL,
 corrige_foto_id BIGINT NULL,
 motivo VARCHAR(200) NULL,
 criterio VARCHAR(20) NOT NULL,
 version_calculo SMALLINT NOT NULL,
 n_lineas INT NOT NULL,
 limitaciones_json JSON NOT NULL,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 generado_por INT NOT NULL,
 generado_en DATETIME NOT NULL,
 UNIQUE KEY uq_presup_foto (presupuesto_id,corte,numero),
 UNIQUE KEY uq_presup_foto_corrige (corrige_foto_id),
 CONSTRAINT fk_presup_foto_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES erp_presupuestos(id),
 CONSTRAINT fk_presup_foto_version FOREIGN KEY (version_id) REFERENCES erp_presupuesto_versiones(id),
 CONSTRAINT fk_presup_foto_corrige FOREIGN KEY (corrige_foto_id) REFERENCES erp_presupuesto_fotos(id),
 CONSTRAINT ck_presup_foto CHECK(numero>=1 AND n_lineas>=0 AND criterio IN('eventos') AND version_calculo>=1 AND YEAR(corte)=anio
   AND (numero=1)=(corrige_foto_id IS NULL) AND (corrige_foto_id IS NULL)=(motivo IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Componentes reales por mes y concepto, tal como se calcularon al generar la foto (el nombre del concepto también queda congelado).
-- presupuesto_* es NULL cuando el mes/concepto no tenía cifra presupuestada («sin presupuesto»), distinto de 0 («presupuesto cero»).
CREATE TABLE IF NOT EXISTS erp_presupuesto_foto_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 foto_id BIGINT NOT NULL,
 mes TINYINT NOT NULL,
 tipo ENUM('servicio','producto','gasto','comisiones','sin_clasificar') NOT NULL,
 objetivo_id BIGINT NOT NULL,
 nombre VARCHAR(200) NULL,
 presupuesto_base_centavos BIGINT NULL,
 presupuesto_impuesto_centavos BIGINT NULL,
 ventas_bruta_base_centavos BIGINT NOT NULL,
 descuentos_base_centavos BIGINT NOT NULL,
 ventas_base_centavos BIGINT NOT NULL,
 devoluciones_base_centavos BIGINT NOT NULL,
 anulaciones_base_centavos BIGINT NOT NULL,
 real_base_centavos BIGINT NOT NULL,
 real_impuesto_centavos BIGINT NOT NULL,
 anulaciones_sin_evento INT NOT NULL DEFAULT 0,
 UNIQUE KEY uq_presup_foto_linea (foto_id,mes,tipo,objetivo_id),
 CONSTRAINT fk_presup_foto_linea FOREIGN KEY (foto_id) REFERENCES erp_presupuesto_fotos(id),
 CONSTRAINT ck_presup_foto_linea CHECK(mes BETWEEN 1 AND 12 AND (presupuesto_base_centavos IS NULL)=(presupuesto_impuesto_centavos IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_presup_foto_no_update;
DROP TRIGGER IF EXISTS trg_presup_foto_no_delete;
DROP TRIGGER IF EXISTS trg_presup_foto_linea_insert;
DROP TRIGGER IF EXISTS trg_presup_foto_linea_no_update;
DROP TRIGGER IF EXISTS trg_presup_foto_linea_no_delete;
CREATE TRIGGER trg_presup_foto_no_update BEFORE UPDATE ON erp_presupuesto_fotos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una foto del comparativo no se edita: se genera una correccion vinculada';
CREATE TRIGGER trg_presup_foto_no_delete BEFORE DELETE ON erp_presupuesto_fotos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las fotos del comparativo se conservan';
-- El contenido de una foto queda sellado por su n_lineas: no se agregan lineas despues de completarla. Triggers de una sola sentencia (IF ... END IF) para que la restauracion los reponga.
CREATE TRIGGER trg_presup_foto_linea_insert BEFORE INSERT ON erp_presupuesto_foto_lineas FOR EACH ROW IF (SELECT COUNT(*) FROM erp_presupuesto_foto_lineas WHERE foto_id=NEW.foto_id)>=(SELECT n_lineas FROM erp_presupuesto_fotos WHERE id=NEW.foto_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La foto ya esta completa: no se agregan lineas'; END IF;
CREATE TRIGGER trg_presup_foto_linea_no_update BEFORE UPDATE ON erp_presupuesto_foto_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lineas de una foto no se editan';
CREATE TRIGGER trg_presup_foto_linea_no_delete BEFORE DELETE ON erp_presupuesto_foto_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lineas de una foto se conservan';

-- Vistas BI (solo lectura, sin datos personales y sin columnas de fecha: el corte va como AAAAMMDD numérico y el mes como anio_mes). es_vigente = ninguna otra foto la corrige.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_fotos AS
SELECT DISTINCT f.id AS foto_id, f.presupuesto_id, p.anio, p.escenario, f.version_id, v.numero AS version_presupuesto_numero, (YEAR(f.corte) * 10000 + MONTH(f.corte) * 100 + DAY(f.corte)) AS corte_aaaammdd,
 (YEAR(f.corte) * 100 + MONTH(f.corte)) AS corte_anio_mes, f.numero AS foto_numero, f.corrige_foto_id, NOT EXISTS (SELECT 1 FROM erp_presupuesto_fotos o WHERE o.corrige_foto_id = f.id) AS es_vigente,
 f.criterio, f.version_calculo, f.n_lineas, (YEAR(f.generado_en) * 100 + MONTH(f.generado_en)) AS generado_anio_mes,
 COALESCE((SELECT SUM(l.anulaciones_sin_evento) FROM erp_presupuesto_foto_lineas l WHERE l.foto_id = f.id), 0) AS anulaciones_sin_evento
FROM erp_presupuesto_fotos f JOIN erp_presupuestos p ON p.id = f.presupuesto_id JOIN erp_presupuesto_versiones v ON v.id = f.version_id;

CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_foto_lineas AS
SELECT DISTINCT l.foto_id, f.presupuesto_id, f.version_id, f.numero AS foto_numero, p.anio, (p.anio * 100 + l.mes) AS anio_mes, l.mes, l.tipo, l.objetivo_id, l.nombre,
 IF(l.presupuesto_base_centavos IS NULL, 'sin_presupuesto', IF(l.presupuesto_base_centavos + l.presupuesto_impuesto_centavos = 0, 'presupuesto_cero', 'con_presupuesto')) AS estado_presupuesto,
 l.presupuesto_base_centavos, l.presupuesto_impuesto_centavos, l.ventas_bruta_base_centavos, l.descuentos_base_centavos AS descuentos_informativo_centavos, l.ventas_base_centavos,
 l.devoluciones_base_centavos, l.anulaciones_base_centavos, l.real_base_centavos, l.real_impuesto_centavos, (l.real_base_centavos + l.real_impuesto_centavos) AS real_total_centavos,
 l.anulaciones_sin_evento
FROM erp_presupuesto_foto_lineas l JOIN erp_presupuesto_fotos f ON f.id = l.foto_id JOIN erp_presupuestos p ON p.id = f.presupuesto_id;
