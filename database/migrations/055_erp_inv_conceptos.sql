-- 055: conceptos de AJUSTE DE INVENTARIO configurables, para contabilizar los ajustes aprobados (mermas, faltantes y sobrantes de conteo, uso interno).
-- Igual que en caja (054): el negocio crea los conceptos y los liga a una cuenta de SU plan contable; no se siembran conceptos ni códigos. La naturaleza fija el sentido
-- del ajuste y el tipo de cuenta admitido:
--   merma_o_perdida (salida · gasto o costo) · faltante_de_conteo (salida · gasto o costo) · sobrante_de_conteo (entrada · ingreso o costo) · uso_interno_u_obsequio (salida · gasto)
-- El ajuste se valoriza con el costo del propio movimiento de inventario (foto del costo al aprobar). Sin cuenta asignada el ajuste se registra y solo su contabilización
-- queda pendiente. Los ajustes anteriores a esta migración no se reclasifican: no tienen fila en erp_inv_ajuste_conceptos.
CREATE TABLE IF NOT EXISTS erp_inv_conceptos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(80) NOT NULL,
 naturaleza ENUM('merma_o_perdida','faltante_de_conteo','sobrante_de_conteo','uso_interno_u_obsequio') NOT NULL,
 tipo ENUM('entrada','salida') NOT NULL,
 cuenta_id BIGINT NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 actualizado_por INT NULL,
 actualizado_en DATETIME NULL,
 UNIQUE KEY uq_inv_concepto_nombre (nombre),
 CONSTRAINT fk_inv_concepto_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_cont_cuentas(id),
 CONSTRAINT ck_inv_concepto CHECK(CHAR_LENGTH(nombre)>=3 AND ((naturaleza='sobrante_de_conteo' AND tipo='entrada') OR (naturaleza<>'sobrante_de_conteo' AND tipo='salida')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_inv_ajuste_conceptos (
 movimiento_id BIGINT PRIMARY KEY,
 concepto_id BIGINT NOT NULL,
 solicitud_id BIGINT NULL,
 evento_id BIGINT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_inv_ajuste_solicitud (solicitud_id),
 CONSTRAINT fk_inv_ajuste_mov FOREIGN KEY (movimiento_id) REFERENCES inventario_movimientos(id),
 CONSTRAINT fk_inv_ajuste_concepto FOREIGN KEY (concepto_id) REFERENCES erp_inv_conceptos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_inv_concepto_update;
DROP TRIGGER IF EXISTS trg_inv_concepto_no_delete;
DROP TRIGGER IF EXISTS trg_inv_ajuste_no_update;
DROP TRIGGER IF EXISTS trg_inv_ajuste_no_delete;
CREATE TRIGGER trg_inv_concepto_update BEFORE UPDATE ON erp_inv_conceptos FOR EACH ROW IF NEW.nombre<>OLD.nombre OR NEW.naturaleza<>OLD.naturaleza OR NEW.tipo<>OLD.tipo OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Del concepto de inventario solo cambian su cuenta y su estado'; END IF;
CREATE TRIGGER trg_inv_concepto_no_delete BEFORE DELETE ON erp_inv_conceptos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El concepto de inventario se desactiva, no se borra';
CREATE TRIGGER trg_inv_ajuste_no_update BEFORE UPDATE ON erp_inv_ajuste_conceptos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La clasificacion del ajuste no se edita';
CREATE TRIGGER trg_inv_ajuste_no_delete BEFORE DELETE ON erp_inv_ajuste_conceptos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La clasificacion del ajuste se conserva';
