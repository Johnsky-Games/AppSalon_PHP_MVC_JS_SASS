-- Nómina propia (Ecuador): parámetros legales versionados, condiciones por colaborador, novedades aprobadas, corridas con revisión y aprobación,
-- líneas inmutables, vínculo único con las comisiones pagadas por nómina y liquidación de beneficios. Aditiva sobre 043.
-- Una corrida aprobada no se edita: se corrige con novedades de ajuste trazables en la siguiente corrida, o se anula antes de pagarse.
CREATE TABLE IF NOT EXISTS erp_nomina_parametros (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 vigente_desde DATE NOT NULL,
 estado ENUM('vigente','derogado') NOT NULL DEFAULT 'vigente',
 valor_entero BIGINT NULL,
 valor_texto VARCHAR(4000) NULL,
 fuente VARCHAR(200) NOT NULL,
 fuente_url VARCHAR(300) NULL,
 consultado_en DATE NOT NULL,
 nota VARCHAR(300) NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_nomina_parametro_version (clave,vigente_desde),
 CONSTRAINT ck_nomina_parametro_valor CHECK((estado='derogado' AND valor_entero IS NULL AND valor_texto IS NULL) OR (estado='vigente' AND ((valor_entero IS NULL) <> (valor_texto IS NULL))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_nomina_parametro_no_update;
CREATE TRIGGER trg_nomina_parametro_no_update BEFORE UPDATE ON erp_nomina_parametros FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Registra una versión nueva del parámetro de nómina';
DROP TRIGGER IF EXISTS trg_nomina_parametro_no_delete;
CREATE TRIGGER trg_nomina_parametro_no_delete BEFORE DELETE ON erp_nomina_parametros FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el historial de parámetros de nómina';

CREATE TABLE IF NOT EXISTS erp_nomina_condiciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 vigente_desde DATE NOT NULL,
 region ENUM('sierra_amazonia','costa_galapagos') NOT NULL,
 mensualiza_d13 TINYINT NOT NULL,
 mensualiza_d14 TINYINT NOT NULL,
 fondos_reserva ENUM('mensualizado','acumulado','no_aplica') NOT NULL,
 aporta_iess TINYINT NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_nomina_condicion (colaborador_id,vigente_desde),
 CONSTRAINT fk_nomina_condicion_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_nomina_condicion CHECK(mensualiza_d13 IN(0,1) AND mensualiza_d14 IN(0,1) AND aporta_iess IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_nomina_condicion_no_update;
CREATE TRIGGER trg_nomina_condicion_no_update BEFORE UPDATE ON erp_nomina_condiciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Registra una condición nueva con otra vigencia';
DROP TRIGGER IF EXISTS trg_nomina_condicion_no_delete;
CREATE TRIGGER trg_nomina_condicion_no_delete BEFORE DELETE ON erp_nomina_condiciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el historial de condiciones de nómina';

CREATE TABLE IF NOT EXISTS erp_nomina_corridas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 anio SMALLINT NOT NULL,
 mes TINYINT NOT NULL,
 estado ENUM('calculada','revisada','aprobada','anulada') NOT NULL DEFAULT 'calculada',
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 calculada_por INT NOT NULL,
 calculada_en DATETIME NOT NULL,
 revisada_por INT NULL,
 revisada_en DATETIME NULL,
 aprobada_por INT NULL,
 aprobada_en DATETIME NULL,
 anulada_por INT NULL,
 anulada_en DATETIME NULL,
 anulacion_motivo VARCHAR(300) NULL,
 totales_json JSON NULL,
 evento_aprobacion_id BIGINT NULL,
 obligacion_iess_id BIGINT NULL,
 obligacion_ir_id BIGINT NULL,
 UNIQUE KEY uq_nomina_corrida_activa ((IF(estado<>'anulada',anio*100+mes,NULL))),
 UNIQUE KEY uq_nomina_obligacion_iess (obligacion_iess_id),
 UNIQUE KEY uq_nomina_obligacion_ir (obligacion_ir_id),
 CONSTRAINT fk_nomina_corrida_iess FOREIGN KEY (obligacion_iess_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT fk_nomina_corrida_ir FOREIGN KEY (obligacion_ir_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT ck_nomina_corrida CHECK(mes BETWEEN 1 AND 12 AND anio BETWEEN 2000 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_nomina_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 corrida_id BIGINT NOT NULL,
 colaborador_id INT NOT NULL,
 contrato_id BIGINT NOT NULL,
 dias SMALLINT NOT NULL,
 base_centavos BIGINT NOT NULL,
 sueldo_centavos BIGINT NOT NULL,
 horas_centavos BIGINT NOT NULL,
 bonos_centavos BIGINT NOT NULL,
 comisiones_centavos BIGINT NOT NULL,
 d13_mensual_centavos BIGINT NOT NULL,
 d14_mensual_centavos BIGINT NOT NULL,
 fondos_mensual_centavos BIGINT NOT NULL,
 ingresos_centavos BIGINT NOT NULL,
 gravado_iess_centavos BIGINT NOT NULL,
 iess_personal_centavos BIGINT NOT NULL,
 ir_centavos BIGINT NOT NULL,
 descuentos_centavos BIGINT NOT NULL,
 neto_centavos BIGINT NOT NULL,
 iess_patronal_centavos BIGINT NOT NULL,
 d13_prov_centavos BIGINT NOT NULL,
 d14_prov_centavos BIGINT NOT NULL,
 fondos_acum_centavos BIGINT NOT NULL,
 snapshot_json JSON NOT NULL,
 obligacion_id BIGINT NULL,
 UNIQUE KEY uq_nomina_linea (corrida_id,colaborador_id),
 UNIQUE KEY uq_nomina_linea_obligacion (obligacion_id),
 INDEX idx_nomina_linea_colaborador (colaborador_id,corrida_id),
 CONSTRAINT fk_nomina_linea_corrida FOREIGN KEY (corrida_id) REFERENCES erp_nomina_corridas(id),
 CONSTRAINT fk_nomina_linea_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_nomina_linea_contrato FOREIGN KEY (contrato_id) REFERENCES erp_contratos(id),
 CONSTRAINT fk_nomina_linea_obligacion FOREIGN KEY (obligacion_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT ck_nomina_linea_neto CHECK(neto_centavos>0 AND neto_centavos=ingresos_centavos-iess_personal_centavos-ir_centavos-descuentos_centavos),
 CONSTRAINT ck_nomina_linea_ingresos CHECK(ingresos_centavos=sueldo_centavos+horas_centavos+bonos_centavos+comisiones_centavos+d13_mensual_centavos+d14_mensual_centavos+fondos_mensual_centavos),
 CONSTRAINT ck_nomina_linea_positivos CHECK(dias BETWEEN 1 AND 30 AND sueldo_centavos>=0 AND horas_centavos>=0 AND bonos_centavos>=0 AND comisiones_centavos>=0 AND iess_personal_centavos>=0 AND ir_centavos>=0 AND descuentos_centavos>=0 AND iess_patronal_centavos>=0 AND d13_prov_centavos>=0 AND d14_prov_centavos>=0 AND fondos_acum_centavos>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_nomina_novedades (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 colaborador_id INT NOT NULL,
 anio SMALLINT NOT NULL,
 mes TINYINT NOT NULL,
 tipo ENUM('hora_suplementaria','hora_extraordinaria','hora_nocturna','bono','descuento','retencion_ir') NOT NULL,
 cantidad_milesimas BIGINT NULL,
 monto_centavos BIGINT NULL,
 grava_iess TINYINT NOT NULL DEFAULT 1,
 motivo VARCHAR(300) NOT NULL,
 ajusta_linea_id BIGINT NULL,
 estado ENUM('pendiente','aprobada','rechazada','consumida') NOT NULL DEFAULT 'pendiente',
 registrado_por INT NOT NULL,
 registrado_en DATETIME NOT NULL,
 resuelto_por INT NULL,
 resuelto_en DATETIME NULL,
 corrida_id BIGINT NULL,
 INDEX idx_nomina_novedad_periodo (colaborador_id,anio,mes,estado),
 CONSTRAINT fk_nomina_novedad_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_nomina_novedad_linea FOREIGN KEY (ajusta_linea_id) REFERENCES erp_nomina_lineas(id),
 CONSTRAINT fk_nomina_novedad_corrida FOREIGN KEY (corrida_id) REFERENCES erp_nomina_corridas(id),
 CONSTRAINT ck_nomina_novedad CHECK(mes BETWEEN 1 AND 12 AND grava_iess IN(0,1) AND (
   (tipo IN('hora_suplementaria','hora_extraordinaria','hora_nocturna') AND cantidad_milesimas BETWEEN 1 AND 744000 AND monto_centavos IS NULL)
   OR (tipo IN('bono','descuento','retencion_ir') AND monto_centavos BETWEEN 1 AND 99999999 AND cantidad_milesimas IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una comisión liquidada por nómina entra en una sola corrida activa; anular la corrida la libera.
CREATE TABLE IF NOT EXISTS erp_nomina_comisiones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 liquidacion_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 monto_centavos BIGINT NOT NULL,
 activa TINYINT NOT NULL DEFAULT 1,
 UNIQUE KEY uq_nomina_comision_activa ((IF(activa=1,liquidacion_id,NULL))),
 INDEX idx_nomina_comision_linea (linea_id),
 CONSTRAINT fk_nomina_comision_liquidacion FOREIGN KEY (liquidacion_id) REFERENCES erp_comision_liquidaciones(id),
 CONSTRAINT fk_nomina_comision_linea FOREIGN KEY (linea_id) REFERENCES erp_nomina_lineas(id),
 CONSTRAINT ck_nomina_comision CHECK(monto_centavos>0 AND activa IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Beneficios acumulados (décimos) liquidados al colaborador: cada línea de nómina alimenta un solo pago de cada tipo.
CREATE TABLE IF NOT EXISTS erp_nomina_beneficios (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 clave_operacion CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 colaborador_id INT NOT NULL,
 tipo ENUM('decimo_tercero','decimo_cuarto') NOT NULL,
 monto_centavos BIGINT NOT NULL,
 obligacion_id BIGINT NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_nomina_beneficio_obligacion (obligacion_id),
 CONSTRAINT fk_nomina_beneficio_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT fk_nomina_beneficio_obligacion FOREIGN KEY (obligacion_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT ck_nomina_beneficio CHECK(monto_centavos>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_nomina_beneficio_lineas (
 beneficio_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 tipo ENUM('decimo_tercero','decimo_cuarto') NOT NULL,
 monto_centavos BIGINT NOT NULL,
 PRIMARY KEY (linea_id,tipo),
 CONSTRAINT fk_nomina_benlinea_beneficio FOREIGN KEY (beneficio_id) REFERENCES erp_nomina_beneficios(id),
 CONSTRAINT fk_nomina_benlinea_linea FOREIGN KEY (linea_id) REFERENCES erp_nomina_lineas(id),
 CONSTRAINT ck_nomina_benlinea CHECK(monto_centavos>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Protecciones de la corrida y sus líneas
DROP TRIGGER IF EXISTS trg_nomina_corrida_fija;
CREATE TRIGGER trg_nomina_corrida_fija BEFORE UPDATE ON erp_nomina_corridas FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.anio<>OLD.anio OR NEW.mes<>OLD.mes OR NEW.huella<>OLD.huella OR NEW.calculada_por<>OLD.calculada_por OR NEW.calculada_en<>OLD.calculada_en OR (OLD.totales_json IS NOT NULL AND NOT(NEW.totales_json<=>OLD.totales_json)) OR (OLD.obligacion_iess_id IS NOT NULL AND NOT(NEW.obligacion_iess_id<=>OLD.obligacion_iess_id)) OR (OLD.obligacion_ir_id IS NOT NULL AND NOT(NEW.obligacion_ir_id<=>OLD.obligacion_ir_id)) OR (OLD.revisada_por IS NOT NULL AND NOT(NEW.revisada_por<=>OLD.revisada_por)) OR (OLD.aprobada_por IS NOT NULL AND NOT(NEW.aprobada_por<=>OLD.aprobada_por)) OR NOT((OLD.estado='calculada' AND NEW.estado IN('calculada','revisada','anulada')) OR (OLD.estado='revisada' AND NEW.estado IN('revisada','aprobada','anulada')) OR (OLD.estado='aprobada' AND NEW.estado IN('aprobada','anulada'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La corrida de nómina no retrocede ni se edita: se anula o se corrige con novedades de ajuste'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_corrida_no_delete;
CREATE TRIGGER trg_nomina_corrida_no_delete BEFORE DELETE ON erp_nomina_corridas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las corridas de nómina se anulan, no se borran';
DROP TRIGGER IF EXISTS trg_nomina_linea_insert;
CREATE TRIGGER trg_nomina_linea_insert BEFORE INSERT ON erp_nomina_lineas FOR EACH ROW
 IF COALESCE((SELECT estado FROM erp_nomina_corridas WHERE id=NEW.corrida_id),'x')<>'calculada' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Solo una corrida recién calculada admite líneas'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_linea_fija;
CREATE TRIGGER trg_nomina_linea_fija BEFORE UPDATE ON erp_nomina_lineas FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.corrida_id<>OLD.corrida_id OR NEW.colaborador_id<>OLD.colaborador_id OR NEW.contrato_id<>OLD.contrato_id OR NEW.dias<>OLD.dias OR NEW.base_centavos<>OLD.base_centavos OR NEW.sueldo_centavos<>OLD.sueldo_centavos OR NEW.horas_centavos<>OLD.horas_centavos OR NEW.bonos_centavos<>OLD.bonos_centavos OR NEW.comisiones_centavos<>OLD.comisiones_centavos OR NEW.d13_mensual_centavos<>OLD.d13_mensual_centavos OR NEW.d14_mensual_centavos<>OLD.d14_mensual_centavos OR NEW.fondos_mensual_centavos<>OLD.fondos_mensual_centavos OR NEW.gravado_iess_centavos<>OLD.gravado_iess_centavos OR NEW.ingresos_centavos<>OLD.ingresos_centavos OR NEW.iess_personal_centavos<>OLD.iess_personal_centavos OR NEW.ir_centavos<>OLD.ir_centavos OR NEW.descuentos_centavos<>OLD.descuentos_centavos OR NEW.neto_centavos<>OLD.neto_centavos OR NEW.iess_patronal_centavos<>OLD.iess_patronal_centavos OR NEW.d13_prov_centavos<>OLD.d13_prov_centavos OR NEW.d14_prov_centavos<>OLD.d14_prov_centavos OR NEW.fondos_acum_centavos<>OLD.fondos_acum_centavos OR NOT(NEW.snapshot_json<=>OLD.snapshot_json) OR (OLD.obligacion_id IS NOT NULL AND NOT(NEW.obligacion_id<=>OLD.obligacion_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La línea de nómina es inmutable; solo se enlaza su obligación al aprobar'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_linea_delete;
CREATE TRIGGER trg_nomina_linea_delete BEFORE DELETE ON erp_nomina_lineas FOR EACH ROW
 IF COALESCE((SELECT estado FROM erp_nomina_corridas WHERE id=OLD.corrida_id),'x')<>'calculada' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las líneas de una corrida revisada, aprobada o anulada se conservan'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_novedad_fija;
CREATE TRIGGER trg_nomina_novedad_fija BEFORE UPDATE ON erp_nomina_novedades FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.clave_operacion<>OLD.clave_operacion OR NEW.colaborador_id<>OLD.colaborador_id OR NEW.anio<>OLD.anio OR NEW.mes<>OLD.mes OR NEW.tipo<>OLD.tipo OR NOT(NEW.cantidad_milesimas<=>OLD.cantidad_milesimas) OR NOT(NEW.monto_centavos<=>OLD.monto_centavos) OR NEW.grava_iess<>OLD.grava_iess OR NEW.motivo<>OLD.motivo OR NOT(NEW.ajusta_linea_id<=>OLD.ajusta_linea_id) OR NEW.registrado_por<>OLD.registrado_por OR NEW.registrado_en<>OLD.registrado_en OR NOT((OLD.estado='pendiente' AND NEW.estado IN('aprobada','rechazada')) OR (OLD.estado='aprobada' AND NEW.estado IN('consumida','aprobada')) OR (OLD.estado='consumida' AND NEW.estado IN('aprobada','consumida')) OR (OLD.estado=NEW.estado AND OLD.estado IN('pendiente','rechazada'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La novedad de nómina solo cambia de estado; corrige con otra novedad'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_novedad_no_delete;
CREATE TRIGGER trg_nomina_novedad_no_delete BEFORE DELETE ON erp_nomina_novedades FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las novedades de nómina se rechazan o se compensan, no se borran';
DROP TRIGGER IF EXISTS trg_nomina_comision_ctrl;
CREATE TRIGGER trg_nomina_comision_ctrl BEFORE INSERT ON erp_nomina_comisiones FOR EACH ROW
 IF COALESCE((SELECT via FROM erp_comision_liquidaciones WHERE id=NEW.liquidacion_id),'x')<>'nomina' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Solo las comisiones liquidadas por nómina entran a la nómina; las de pago directo no'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_comision_fija;
CREATE TRIGGER trg_nomina_comision_fija BEFORE UPDATE ON erp_nomina_comisiones FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.liquidacion_id<>OLD.liquidacion_id OR NEW.linea_id<>OLD.linea_id OR NEW.monto_centavos<>OLD.monto_centavos OR NOT(OLD.activa=1 AND NEW.activa=0) AND NEW.activa<>OLD.activa THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El vínculo de la comisión solo se libera al anular la corrida'; END IF;
DROP TRIGGER IF EXISTS trg_nomina_comision_no_delete;
CREATE TRIGGER trg_nomina_comision_no_delete BEFORE DELETE ON erp_nomina_comisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el vínculo entre comisión y nómina';
DROP TRIGGER IF EXISTS trg_nomina_beneficio_no_update;
CREATE TRIGGER trg_nomina_beneficio_no_update BEFORE UPDATE ON erp_nomina_beneficios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La liquidación de beneficios es inmutable';
DROP TRIGGER IF EXISTS trg_nomina_beneficio_no_delete;
CREATE TRIGGER trg_nomina_beneficio_no_delete BEFORE DELETE ON erp_nomina_beneficios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La liquidación de beneficios es inmutable';
DROP TRIGGER IF EXISTS trg_nomina_benlinea_no_update;
CREATE TRIGGER trg_nomina_benlinea_no_update BEFORE UPDATE ON erp_nomina_beneficio_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La liquidación de beneficios es inmutable';
DROP TRIGGER IF EXISTS trg_nomina_benlinea_no_delete;
CREATE TRIGGER trg_nomina_benlinea_no_delete BEFORE DELETE ON erp_nomina_beneficio_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La liquidación de beneficios es inmutable';

-- Cuentas y roles contables que necesita la nómina (se suman al plan base de la 043).
INSERT IGNORE INTO erp_cont_cuentas(codigo,nombre,tipo,padre_id,imputable,creado_por,creado_en)
 SELECT x.codigo,x.nombre,'pasivo',(SELECT id FROM erp_cont_cuentas WHERE codigo='2.1'),1,0,NOW() FROM (SELECT '2.1.08' codigo,'Retención del impuesto a la renta por pagar' nombre UNION ALL SELECT '2.1.09','Beneficios sociales por pagar (décimos)' UNION ALL SELECT '2.1.10','Descuentos de nómina por liquidar') x;
INSERT IGNORE INTO erp_cont_roles(rol,cuenta_id,actualizado_por,actualizado_en)
 SELECT r.rol,c.id,0,NOW() FROM (SELECT 'ir_por_pagar' rol,'2.1.08' codigo UNION ALL SELECT 'beneficios_por_pagar','2.1.09' UNION ALL SELECT 'descuentos_nomina','2.1.10') r JOIN erp_cont_cuentas c ON c.codigo=r.codigo;
