-- 052: depreciación de activos fijos (política CONTABLE; no incluye reglas tributarias).
-- Categorías = políticas contables configurables (vida útil, valor residual, método) sin valores de fábrica. La ficha fija, por activo, una copia de esos
-- parámetros (editar o crear categorías no altera lo ya configurado). Las corridas mensuales se calculan, se contabilizan (hecho al adaptador contable)
-- y pueden anularse con reverso; las bajas retiran el activo. Todo es de solo anexar salvo las transiciones de estado de corridas y bajas.
-- Los límites de deducibilidad tributaria de la depreciación y la conciliación contable-tributaria NO están aquí: requieren fuente oficial verificada.
CREATE TABLE IF NOT EXISTS erp_dep_categorias (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(80) NOT NULL,
 vida_util_meses SMALLINT NOT NULL,
 residual_bp SMALLINT NOT NULL,
 metodo ENUM('lineal') NOT NULL DEFAULT 'lineal',
 fuente VARCHAR(200) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_dep_cat_nombre (nombre),
 CONSTRAINT ck_dep_cat CHECK(vida_util_meses BETWEEN 1 AND 600 AND residual_bp BETWEEN 0 AND 9999 AND CHAR_LENGTH(fuente)>=5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_dep_fichas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 activo_id INT NOT NULL,
 categoria_id BIGINT NOT NULL,
 inicio DATE NOT NULL,
 costo_centavos BIGINT NOT NULL,
 acumulado_inicial_centavos BIGINT NOT NULL DEFAULT 0,
 residual_centavos BIGINT NOT NULL,
 vida_util_meses SMALLINT NOT NULL,
 metodo ENUM('lineal') NOT NULL DEFAULT 'lineal',
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_dep_ficha_activo (activo_id),
 CONSTRAINT fk_dep_ficha_activo FOREIGN KEY (activo_id) REFERENCES erp_activos(id),
 CONSTRAINT fk_dep_ficha_cat FOREIGN KEY (categoria_id) REFERENCES erp_dep_categorias(id),
 CONSTRAINT ck_dep_ficha CHECK(DAY(inicio)=1 AND costo_centavos>0 AND costo_centavos<=99999999999 AND residual_centavos>=0 AND acumulado_inicial_centavos>=0
   AND acumulado_inicial_centavos+residual_centavos<costo_centavos AND vida_util_meses BETWEEN 1 AND 600)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_dep_corridas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 anio SMALLINT NOT NULL,
 mes TINYINT NOT NULL,
 version SMALLINT NOT NULL,
 estado ENUM('borrador','contabilizada','anulada') NOT NULL DEFAULT 'borrador',
 mes_vivo INT GENERATED ALWAYS AS (IF(estado<>'anulada',anio*100+mes,NULL)) STORED,
 total_centavos BIGINT NOT NULL,
 n_lineas SMALLINT NOT NULL,
 evento_contab_id BIGINT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 contabilizada_por INT NULL,
 contabilizada_en DATETIME NULL,
 anulada_por INT NULL,
 anulada_en DATETIME NULL,
 anulacion_motivo VARCHAR(300) NULL,
 UNIQUE KEY uq_dep_corrida_version (anio,mes,version),
 UNIQUE KEY uq_dep_corrida_mes_vivo (mes_vivo),
 CONSTRAINT ck_dep_corrida CHECK(mes BETWEEN 1 AND 12 AND anio BETWEEN 2000 AND 2100 AND total_centavos>0 AND n_lineas>0
   AND ((estado='borrador') = (contabilizada_en IS NULL AND anulada_en IS NULL)) AND ((estado='anulada') = (anulada_en IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_dep_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 corrida_id BIGINT NOT NULL,
 activo_id INT NOT NULL,
 nro_cuota SMALLINT NOT NULL,
 monto_centavos BIGINT NOT NULL,
 acumulado_centavos BIGINT NOT NULL,
 valor_libros_centavos BIGINT NOT NULL,
 UNIQUE KEY uq_dep_linea (corrida_id,activo_id),
 INDEX idx_dep_linea_activo (activo_id,corrida_id),
 CONSTRAINT fk_dep_linea_corrida FOREIGN KEY (corrida_id) REFERENCES erp_dep_corridas(id),
 CONSTRAINT fk_dep_linea_activo FOREIGN KEY (activo_id) REFERENCES erp_activos(id),
 CONSTRAINT ck_dep_linea CHECK(monto_centavos>0 AND nro_cuota>=1 AND acumulado_centavos>=monto_centavos AND valor_libros_centavos>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_dep_bajas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 activo_id INT NOT NULL,
 fecha DATE NOT NULL,
 motivo ENUM('obsolescencia','dano','perdida','donacion','otro') NOT NULL,
 nota VARCHAR(300) NULL,
 estado ENUM('vigente','anulada') NOT NULL DEFAULT 'vigente',
 activo_vigente INT GENERATED ALWAYS AS (IF(estado='vigente',activo_id,NULL)) STORED,
 costo_centavos BIGINT NOT NULL,
 acumulado_centavos BIGINT NOT NULL,
 valor_libros_centavos BIGINT NOT NULL,
 evento_contab_id BIGINT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 anulada_por INT NULL,
 anulada_en DATETIME NULL,
 anulacion_motivo VARCHAR(300) NULL,
 UNIQUE KEY uq_dep_baja_vigente (activo_vigente),
 CONSTRAINT fk_dep_baja_activo FOREIGN KEY (activo_id) REFERENCES erp_activos(id),
 CONSTRAINT ck_dep_baja CHECK(costo_centavos>0 AND acumulado_centavos>=0 AND valor_libros_centavos=costo_centavos-acumulado_centavos AND ((estado='anulada') = (anulada_en IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_dep_cat_no_update;
DROP TRIGGER IF EXISTS trg_dep_cat_no_delete;
DROP TRIGGER IF EXISTS trg_dep_ficha_no_update;
DROP TRIGGER IF EXISTS trg_dep_ficha_no_delete;
DROP TRIGGER IF EXISTS trg_dep_corrida_update;
DROP TRIGGER IF EXISTS trg_dep_corrida_no_delete;
DROP TRIGGER IF EXISTS trg_dep_linea_no_update;
DROP TRIGGER IF EXISTS trg_dep_linea_no_delete;
DROP TRIGGER IF EXISTS trg_dep_baja_update;
DROP TRIGGER IF EXISTS trg_dep_baja_no_delete;
CREATE TRIGGER trg_dep_cat_no_update BEFORE UPDATE ON erp_dep_categorias FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una politica de depreciacion no se edita: crea otra';
CREATE TRIGGER trg_dep_cat_no_delete BEFORE DELETE ON erp_dep_categorias FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una politica de depreciacion se conserva';
CREATE TRIGGER trg_dep_ficha_no_update BEFORE UPDATE ON erp_dep_fichas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La ficha de depreciacion no se edita';
CREATE TRIGGER trg_dep_ficha_no_delete BEFORE DELETE ON erp_dep_fichas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La ficha de depreciacion se conserva';
-- Corrida: solo borrador→contabilizada, borrador→anulada y contabilizada→anulada; los importes, el período y la versión no cambian nunca.
CREATE TRIGGER trg_dep_corrida_update BEFORE UPDATE ON erp_dep_corridas FOR EACH ROW IF OLD.estado='anulada' OR NEW.anio<>OLD.anio OR NEW.mes<>OLD.mes OR NEW.version<>OLD.version OR NEW.total_centavos<>OLD.total_centavos OR NEW.n_lineas<>OLD.n_lineas OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en OR (OLD.estado='contabilizada' AND NEW.estado<>'anulada') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transicion no permitida en la corrida de depreciacion'; END IF;
CREATE TRIGGER trg_dep_corrida_no_delete BEFORE DELETE ON erp_dep_corridas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La corrida de depreciacion se conserva';
CREATE TRIGGER trg_dep_linea_no_update BEFORE UPDATE ON erp_dep_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lineas de depreciacion no se editan';
CREATE TRIGGER trg_dep_linea_no_delete BEFORE DELETE ON erp_dep_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lineas de depreciacion se conservan';
CREATE TRIGGER trg_dep_baja_update BEFORE UPDATE ON erp_dep_bajas FOR EACH ROW IF OLD.estado<>'vigente' OR NEW.estado<>'anulada' OR NEW.activo_id<>OLD.activo_id OR NEW.fecha<>OLD.fecha OR NEW.costo_centavos<>OLD.costo_centavos OR NEW.acumulado_centavos<>OLD.acumulado_centavos OR NEW.valor_libros_centavos<>OLD.valor_libros_centavos OR NEW.motivo<>OLD.motivo THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La baja solo se anula con su motivo'; END IF;
CREATE TRIGGER trg_dep_baja_no_delete BEFORE DELETE ON erp_dep_bajas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La baja de activo se conserva';

-- Cuentas y roles contables nuevos (si el código ya existe con otro nombre en un plan editado, NO se asigna el rol: se configura a mano en el plan).
INSERT IGNORE INTO erp_cont_cuentas(codigo,nombre,tipo,imputable,creado_por,creado_en) VALUES
 ('1.2.02','Depreciación acumulada','activo',1,0,NOW()),('6.1.07','Gasto por depreciación','gasto',1,0,NOW()),('6.1.08','Pérdida por baja de activos','gasto',1,0,NOW());
UPDATE erp_cont_cuentas h JOIN erp_cont_cuentas p ON p.codigo=CASE WHEN h.codigo='1.2.02' THEN '1.2' ELSE '6' END SET h.padre_id=p.id WHERE h.padre_id IS NULL AND h.creado_por=0 AND h.codigo IN('1.2.02','6.1.07','6.1.08');
INSERT IGNORE INTO erp_cont_roles(rol,cuenta_id,actualizado_por,actualizado_en)
 SELECT r.rol,c.id,0,NOW() FROM (SELECT 'depreciacion_acumulada' rol,'1.2.02' codigo,'Depreciación acumulada' nombre UNION ALL SELECT 'gasto_depreciacion','6.1.07','Gasto por depreciación' UNION ALL SELECT 'perdida_baja_activos','6.1.08','Pérdida por baja de activos') r
 JOIN erp_cont_cuentas c ON c.codigo=r.codigo AND c.nombre=r.nombre;
