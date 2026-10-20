-- Finiquito (liquidación de haberes). Aditiva sobre 044/045. Sin cifras legales de fábrica: los días de vacaciones, el porcentaje de desahucio y los meses de
-- indemnización se registran como parámetros de nómina con su fuente. El cálculo se aprueba (otra persona) y genera UNA obligación al colaborador; anular antes de pagar la cancela
-- y libera los décimos incluidos. Los registros no se editan: solo cambian de estado.
CREATE TABLE IF NOT EXISTS erp_nomina_finiquitos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 colaborador_id INT NOT NULL,
 contrato_id BIGINT NOT NULL,
 fecha_ingreso DATE NOT NULL,
 fecha_salida DATE NOT NULL,
 causa ENUM('renuncia','mutuo_acuerdo','fin_plazo','despido_intempestivo','visto_bueno','jubilacion','fallecimiento','otra') NOT NULL,
 desahucio TINYINT NOT NULL,
 estado ENUM('calculado','aprobado','anulado') NOT NULL DEFAULT 'calculado',
 base_centavos BIGINT NOT NULL,
 dias_servicio INT NOT NULL,
 anios_computados SMALLINT NOT NULL,
 vacaciones_devengadas_cent INT NOT NULL,
 vacaciones_tomadas_cent INT NOT NULL,
 d13_centavos BIGINT NOT NULL,
 d14_centavos BIGINT NOT NULL,
 vacaciones_centavos BIGINT NOT NULL,
 desahucio_centavos BIGINT NOT NULL,
 indemnizacion_centavos BIGINT NOT NULL,
 otros_ingresos_centavos BIGINT NOT NULL,
 descuentos_centavos BIGINT NOT NULL,
 total_centavos BIGINT NOT NULL,
 nota VARCHAR(300) NULL,
 snapshot_json JSON NOT NULL,
 calculado_por INT NOT NULL,
 calculado_en DATETIME NOT NULL,
 aprobado_por INT NULL,
 aprobado_en DATETIME NULL,
 anulado_por INT NULL,
 anulado_en DATETIME NULL,
 anulacion_motivo VARCHAR(300) NULL,
 evento_aprobacion_id BIGINT NULL,
 obligacion_id BIGINT NULL,
 UNIQUE KEY uq_nomina_finiquito_activo ((IF(estado<>'anulado',colaborador_id,NULL))),
 UNIQUE KEY uq_nomina_finiquito_obligacion (obligacion_id),
 CONSTRAINT fk_nomina_finiquito_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_nomina_finiquito_contrato FOREIGN KEY (contrato_id) REFERENCES erp_contratos(id),
 CONSTRAINT fk_nomina_finiquito_obligacion FOREIGN KEY (obligacion_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT ck_nomina_finiquito CHECK(desahucio IN(0,1) AND fecha_salida>=fecha_ingreso AND dias_servicio>=1 AND d13_centavos>=0 AND d14_centavos>=0 AND vacaciones_centavos>=0 AND desahucio_centavos>=0 AND indemnizacion_centavos>=0 AND otros_ingresos_centavos>=0 AND descuentos_centavos>=0 AND vacaciones_devengadas_cent>=0 AND vacaciones_tomadas_cent>=0
   AND total_centavos>=0 AND total_centavos=d13_centavos+d14_centavos+vacaciones_centavos+desahucio_centavos+indemnizacion_centavos+otros_ingresos_centavos-descuentos_centavos
   AND (estado<>'aprobado' OR (aprobado_por IS NOT NULL AND aprobado_en IS NOT NULL)) AND (estado<>'anulado' OR (anulado_por IS NOT NULL AND anulado_en IS NOT NULL AND anulacion_motivo IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Décimos acumulados que el finiquito liquida. Anular el finiquito libera el vínculo (activa=0); una línea de nómina no puede estar en dos finiquitos activos.
CREATE TABLE IF NOT EXISTS erp_nomina_finiquito_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 finiquito_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 tipo ENUM('decimo_tercero','decimo_cuarto') NOT NULL,
 monto_centavos BIGINT NOT NULL,
 activa TINYINT NOT NULL DEFAULT 1,
 UNIQUE KEY uq_nomina_finiquito_linea_activa ((IF(activa=1,CONCAT(linea_id,':',tipo),NULL))),
 INDEX idx_nomina_finiquito_linea (finiquito_id),
 CONSTRAINT fk_nomina_finlinea_finiquito FOREIGN KEY (finiquito_id) REFERENCES erp_nomina_finiquitos(id),
 CONSTRAINT fk_nomina_finlinea_linea FOREIGN KEY (linea_id) REFERENCES erp_nomina_lineas(id),
 CONSTRAINT ck_nomina_finlinea CHECK(monto_centavos>0 AND activa IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_nomina_finiquito_fijo;
CREATE TRIGGER trg_nomina_finiquito_fijo BEFORE UPDATE ON erp_nomina_finiquitos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.colaborador_id<>OLD.colaborador_id OR NEW.contrato_id<>OLD.contrato_id OR NEW.fecha_ingreso<>OLD.fecha_ingreso OR NEW.fecha_salida<>OLD.fecha_salida OR NEW.causa<>OLD.causa OR NEW.desahucio<>OLD.desahucio OR NEW.base_centavos<>OLD.base_centavos OR NEW.dias_servicio<>OLD.dias_servicio OR NEW.anios_computados<>OLD.anios_computados
    OR NEW.vacaciones_devengadas_cent<>OLD.vacaciones_devengadas_cent OR NEW.vacaciones_tomadas_cent<>OLD.vacaciones_tomadas_cent OR NEW.d13_centavos<>OLD.d13_centavos OR NEW.d14_centavos<>OLD.d14_centavos OR NEW.vacaciones_centavos<>OLD.vacaciones_centavos OR NEW.desahucio_centavos<>OLD.desahucio_centavos OR NEW.indemnizacion_centavos<>OLD.indemnizacion_centavos OR NEW.otros_ingresos_centavos<>OLD.otros_ingresos_centavos OR NEW.descuentos_centavos<>OLD.descuentos_centavos OR NEW.total_centavos<>OLD.total_centavos
    OR NOT(NEW.nota<=>OLD.nota) OR NEW.calculado_por<>OLD.calculado_por OR NEW.calculado_en<>OLD.calculado_en OR NEW.snapshot_json<>OLD.snapshot_json
    OR OLD.estado='anulado' OR (OLD.estado='aprobado' AND NEW.estado NOT IN('aprobado','anulado')) OR (OLD.estado='aprobado' AND NEW.estado='aprobado' AND (NOT(NEW.aprobado_por<=>OLD.aprobado_por) OR NOT(NEW.aprobado_en<=>OLD.aprobado_en) OR NOT(NEW.obligacion_id<=>OLD.obligacion_id) OR NOT(NEW.evento_aprobacion_id<=>OLD.evento_aprobacion_id)))
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El finiquito no se edita: se aprueba o se anula'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_finiquito_no_delete;
CREATE TRIGGER trg_nomina_finiquito_no_delete BEFORE DELETE ON erp_nomina_finiquitos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los finiquitos se anulan, no se borran';
DROP TRIGGER IF EXISTS trg_nomina_finlinea_fija;
CREATE TRIGGER trg_nomina_finlinea_fija BEFORE UPDATE ON erp_nomina_finiquito_lineas FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.finiquito_id<>OLD.finiquito_id OR NEW.linea_id<>OLD.linea_id OR NEW.tipo<>OLD.tipo OR NEW.monto_centavos<>OLD.monto_centavos OR NOT(OLD.activa=1 AND NEW.activa=0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El vínculo del finiquito solo puede liberarse al anularlo'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_finlinea_no_delete;
CREATE TRIGGER trg_nomina_finlinea_no_delete BEFORE DELETE ON erp_nomina_finiquito_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el vínculo entre finiquito y nómina';
