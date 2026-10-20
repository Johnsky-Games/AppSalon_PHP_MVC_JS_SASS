-- Participación de trabajadores en las utilidades. Aditiva sobre 044–046. Sin cifras legales de fábrica: los porcentajes (y el tope opcional) son parámetros de nómina con su fuente.
-- La utilidad base sale del libro contable del ejercicio (más ajustes del contador con respaldo); el reparto es exacto en centavos (mayor resto). Una corrida por ejercicio vigente.
CREATE TABLE IF NOT EXISTS erp_nomina_cargas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 anio SMALLINT NOT NULL,
 cargas SMALLINT NOT NULL,
 fuente VARCHAR(200) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_nomina_cargas (colaborador_id,anio,id),
 CONSTRAINT fk_nomina_cargas_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_nomina_cargas CHECK(anio BETWEEN 2000 AND 2100 AND cargas BETWEEN 0 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_nomina_cargas_no_update;
CREATE TRIGGER trg_nomina_cargas_no_update BEFORE UPDATE ON erp_nomina_cargas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Registra una declaración de cargas nueva y conserva las anteriores';
DROP TRIGGER IF EXISTS trg_nomina_cargas_no_delete;
CREATE TRIGGER trg_nomina_cargas_no_delete BEFORE DELETE ON erp_nomina_cargas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el historial de cargas familiares';

CREATE TABLE IF NOT EXISTS erp_nomina_utilidades (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 anio SMALLINT NOT NULL,
 estado ENUM('calculada','aprobada','anulada') NOT NULL DEFAULT 'calculada',
 resultado_contable_centavos BIGINT NOT NULL,
 ajuste_mas_centavos BIGINT NOT NULL,
 ajuste_menos_centavos BIGINT NOT NULL,
 base_centavos BIGINT NOT NULL,
 pb_trabajadores INT NOT NULL,
 pb_cargas INT NOT NULL,
 pozo_trabajo_centavos BIGINT NOT NULL,
 pozo_cargas_centavos BIGINT NOT NULL,
 cargas_a_trabajo TINYINT NOT NULL,
 tope_centavos BIGINT NULL,
 excedente_centavos BIGINT NOT NULL,
 total_centavos BIGINT NOT NULL,
 nota VARCHAR(300) NULL,
 snapshot_json JSON NOT NULL,
 calculada_por INT NOT NULL,
 calculada_en DATETIME NOT NULL,
 aprobada_por INT NULL,
 aprobada_en DATETIME NULL,
 anulada_por INT NULL,
 anulada_en DATETIME NULL,
 anulacion_motivo VARCHAR(300) NULL,
 evento_aprobacion_id BIGINT NULL,
 UNIQUE KEY uq_nomina_utilidades_activa ((IF(estado<>'anulada',anio,NULL))),
 CONSTRAINT ck_nomina_utilidades CHECK(anio BETWEEN 2000 AND 2100 AND base_centavos>0 AND ajuste_mas_centavos>=0 AND ajuste_menos_centavos>=0 AND base_centavos=resultado_contable_centavos+ajuste_mas_centavos-ajuste_menos_centavos AND pb_trabajadores BETWEEN 0 AND 10000 AND pb_cargas BETWEEN 0 AND 10000 AND pb_trabajadores+pb_cargas<=10000
   AND pozo_trabajo_centavos>=0 AND pozo_cargas_centavos>=0 AND excedente_centavos>=0 AND total_centavos>=0 AND cargas_a_trabajo IN(0,1) AND total_centavos+excedente_centavos=pozo_trabajo_centavos+pozo_cargas_centavos
   AND (estado<>'aprobada' OR (aprobada_por IS NOT NULL AND aprobada_en IS NOT NULL)) AND (estado<>'anulada' OR (anulada_por IS NOT NULL AND anulada_en IS NOT NULL AND anulacion_motivo IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_nomina_utilidades_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 utilidad_id BIGINT NOT NULL,
 colaborador_id INT NOT NULL,
 dias SMALLINT NOT NULL,
 cargas SMALLINT NOT NULL,
 parte_trabajo_centavos BIGINT NOT NULL,
 parte_cargas_centavos BIGINT NOT NULL,
 excedente_centavos BIGINT NOT NULL,
 monto_centavos BIGINT NOT NULL,
 obligacion_id BIGINT NULL,
 UNIQUE KEY uq_nomina_utilidades_linea (utilidad_id,colaborador_id),
 UNIQUE KEY uq_nomina_utilidades_obligacion (obligacion_id),
 CONSTRAINT fk_nomina_utillinea_utilidad FOREIGN KEY (utilidad_id) REFERENCES erp_nomina_utilidades(id),
 CONSTRAINT fk_nomina_utillinea_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_nomina_utillinea_obligacion FOREIGN KEY (obligacion_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT ck_nomina_utillinea CHECK(dias BETWEEN 1 AND 366 AND cargas>=0 AND parte_trabajo_centavos>=0 AND parte_cargas_centavos>=0 AND excedente_centavos>=0 AND monto_centavos>=0 AND monto_centavos+excedente_centavos=parte_trabajo_centavos+parte_cargas_centavos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_nomina_utilidades_fija;
CREATE TRIGGER trg_nomina_utilidades_fija BEFORE UPDATE ON erp_nomina_utilidades FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.anio<>OLD.anio OR NEW.resultado_contable_centavos<>OLD.resultado_contable_centavos OR NEW.ajuste_mas_centavos<>OLD.ajuste_mas_centavos OR NEW.ajuste_menos_centavos<>OLD.ajuste_menos_centavos OR NEW.base_centavos<>OLD.base_centavos OR NEW.pb_trabajadores<>OLD.pb_trabajadores OR NEW.pb_cargas<>OLD.pb_cargas
    OR NEW.pozo_trabajo_centavos<>OLD.pozo_trabajo_centavos OR NEW.pozo_cargas_centavos<>OLD.pozo_cargas_centavos OR NEW.cargas_a_trabajo<>OLD.cargas_a_trabajo OR NOT(NEW.tope_centavos<=>OLD.tope_centavos) OR NEW.excedente_centavos<>OLD.excedente_centavos OR NEW.total_centavos<>OLD.total_centavos OR NOT(NEW.nota<=>OLD.nota) OR NEW.snapshot_json<>OLD.snapshot_json OR NEW.calculada_por<>OLD.calculada_por OR NEW.calculada_en<>OLD.calculada_en
    OR OLD.estado='anulada' OR (OLD.estado='aprobada' AND NEW.estado NOT IN('aprobada','anulada')) OR (OLD.estado='aprobada' AND NEW.estado='aprobada' AND (NOT(NEW.aprobada_por<=>OLD.aprobada_por) OR NOT(NEW.aprobada_en<=>OLD.aprobada_en) OR NOT(NEW.evento_aprobacion_id<=>OLD.evento_aprobacion_id)))
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El reparto de utilidades no se edita: se aprueba o se anula'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_utilidades_no_delete;
CREATE TRIGGER trg_nomina_utilidades_no_delete BEFORE DELETE ON erp_nomina_utilidades FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los repartos de utilidades se anulan, no se borran';
DROP TRIGGER IF EXISTS trg_nomina_utillinea_fija;
CREATE TRIGGER trg_nomina_utillinea_fija BEFORE UPDATE ON erp_nomina_utilidades_lineas FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.utilidad_id<>OLD.utilidad_id OR NEW.colaborador_id<>OLD.colaborador_id OR NEW.dias<>OLD.dias OR NEW.cargas<>OLD.cargas OR NEW.parte_trabajo_centavos<>OLD.parte_trabajo_centavos OR NEW.parte_cargas_centavos<>OLD.parte_cargas_centavos OR NEW.excedente_centavos<>OLD.excedente_centavos OR NEW.monto_centavos<>OLD.monto_centavos OR (OLD.obligacion_id IS NOT NULL AND NOT(NEW.obligacion_id<=>OLD.obligacion_id))
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las líneas de utilidades no se editan'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_utillinea_no_delete;
CREATE TRIGGER trg_nomina_utillinea_no_delete BEFORE DELETE ON erp_nomina_utilidades_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve las líneas de utilidades';

-- Cuenta y rol contable del gasto por participación de trabajadores (se suman al plan base de la 043). El código es poco común a propósito: si el usuario ya tiene una cuenta propia
-- con ese código no se pisa ni se le asigna el rol en silencio (el rol queda sin cuenta y el reparto pide asignarla).
INSERT IGNORE INTO erp_cont_cuentas(codigo,nombre,tipo,padre_id,imputable,creado_por,creado_en)
 SELECT '6.1.90','Participación de trabajadores en utilidades','gasto',(SELECT id FROM erp_cont_cuentas WHERE codigo='6'),1,0,NOW();
INSERT IGNORE INTO erp_cont_roles(rol,cuenta_id,actualizado_por,actualizado_en)
 SELECT 'gasto_utilidades',c.id,0,NOW() FROM erp_cont_cuentas c WHERE c.codigo='6.1.90' AND c.creado_por=0 AND c.nombre='Participación de trabajadores en utilidades';
