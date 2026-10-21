-- 054: conceptos de caja configurables para contabilizar los movimientos MANUALES de efectivo.
-- Un movimiento de dinero no es por sí mismo ingreso ni gasto: cada movimiento manual se clasifica con un CONCEPTO que el negocio crea y asocia a una cuenta
-- del plan contable existente (no se siembran conceptos ni códigos de cuenta). La naturaleza fija el sentido y el tipo de cuenta admitido:
--   aporte_propietario (ingreso · patrimonio) · retiro_propietario (egreso · patrimonio) · deposito_a_banco (egreso · activo) · retiro_de_banco (ingreso · activo)
--   gasto (egreso · gasto o costo) · sobrante (ingreso · ingreso) · faltante (egreso · gasto)
-- Sin cuenta asignada el concepto existe, el movimiento se registra en caja, y solo su contabilización queda pendiente hasta completar la configuración.
-- Los movimientos anteriores a esta migración no se reclasifican: no tienen fila en erp_caja_mov_conceptos.
CREATE TABLE IF NOT EXISTS erp_caja_conceptos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(80) NOT NULL,
 naturaleza ENUM('aporte_propietario','retiro_propietario','deposito_a_banco','retiro_de_banco','gasto','sobrante','faltante') NOT NULL,
 tipo ENUM('ingreso','egreso') NOT NULL,
 cuenta_id BIGINT NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 actualizado_por INT NULL,
 actualizado_en DATETIME NULL,
 UNIQUE KEY uq_caja_concepto_nombre (nombre),
 CONSTRAINT fk_caja_concepto_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_cont_cuentas(id),
 CONSTRAINT ck_caja_concepto CHECK(CHAR_LENGTH(nombre)>=3 AND ((naturaleza IN('aporte_propietario','retiro_de_banco','sobrante') AND tipo='ingreso') OR (naturaleza IN('retiro_propietario','deposito_a_banco','gasto','faltante') AND tipo='egreso')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_caja_mov_conceptos (
 caja_id BIGINT PRIMARY KEY,
 concepto_id BIGINT NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 reversa_de_id BIGINT NULL,
 evento_id BIGINT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_caja_mov_reversa (reversa_de_id),
 CONSTRAINT fk_caja_mov_concepto_mov FOREIGN KEY (caja_id) REFERENCES caja_movimientos(id),
 CONSTRAINT fk_caja_mov_concepto FOREIGN KEY (concepto_id) REFERENCES erp_caja_conceptos(id),
 CONSTRAINT fk_caja_mov_reversa FOREIGN KEY (reversa_de_id) REFERENCES caja_movimientos(id),
 CONSTRAINT ck_caja_mov_concepto CHECK(CHAR_LENGTH(motivo)>=5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_caja_concepto_update;
DROP TRIGGER IF EXISTS trg_caja_concepto_no_delete;
DROP TRIGGER IF EXISTS trg_caja_mov_concepto_no_update;
DROP TRIGGER IF EXISTS trg_caja_mov_concepto_no_delete;
CREATE TRIGGER trg_caja_concepto_update BEFORE UPDATE ON erp_caja_conceptos FOR EACH ROW IF NEW.nombre<>OLD.nombre OR NEW.naturaleza<>OLD.naturaleza OR NEW.tipo<>OLD.tipo OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Del concepto de caja solo cambian su cuenta y su estado'; END IF;
CREATE TRIGGER trg_caja_concepto_no_delete BEFORE DELETE ON erp_caja_conceptos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El concepto de caja se desactiva, no se borra';
CREATE TRIGGER trg_caja_mov_concepto_no_update BEFORE UPDATE ON erp_caja_mov_conceptos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La clasificacion del movimiento no se edita';
CREATE TRIGGER trg_caja_mov_concepto_no_delete BEFORE DELETE ON erp_caja_mov_conceptos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La clasificacion del movimiento se conserva';
