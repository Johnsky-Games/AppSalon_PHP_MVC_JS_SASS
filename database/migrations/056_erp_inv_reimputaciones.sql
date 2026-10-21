-- 056: reimputación contable de ajustes de inventario ya clasificados (concepto → otro concepto), de solo anexar y encadenada.
-- Reclasificar cambia la IMPUTACIÓN contable, nunca la existencia física: no toca inventario_movimientos ni inventario_productos. Cada reimputación remite al asiento vigente
-- (el original o la imputación anterior), que se revierte, y al asiento nuevo con el concepto elegido. La cadena (movimiento_id, anterior_id) es única: dos personas no pueden
-- reimputar a la vez el mismo estado, y una cadena no se bifurca. anterior_id = 0 en la primera reimputación del ajuste.
CREATE TABLE IF NOT EXISTS erp_inv_reimputaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 movimiento_id BIGINT NOT NULL,
 anterior_id BIGINT NOT NULL DEFAULT 0,
 concepto_origen_id BIGINT NOT NULL,
 concepto_nuevo_id BIGINT NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 evento_id BIGINT NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_inv_reimp_cadena (movimiento_id, anterior_id),
 UNIQUE KEY uq_inv_reimp_evento (evento_id),
 CONSTRAINT fk_inv_reimp_mov FOREIGN KEY (movimiento_id) REFERENCES inventario_movimientos(id),
 CONSTRAINT fk_inv_reimp_origen FOREIGN KEY (concepto_origen_id) REFERENCES erp_inv_conceptos(id),
 CONSTRAINT fk_inv_reimp_nuevo FOREIGN KEY (concepto_nuevo_id) REFERENCES erp_inv_conceptos(id),
 CONSTRAINT ck_inv_reimp CHECK(CHAR_LENGTH(motivo)>=5 AND concepto_origen_id<>concepto_nuevo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_inv_reimp_no_update;
DROP TRIGGER IF EXISTS trg_inv_reimp_no_delete;
CREATE TRIGGER trg_inv_reimp_no_update BEFORE UPDATE ON erp_inv_reimputaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La reimputacion del ajuste no se edita';
CREATE TRIGGER trg_inv_reimp_no_delete BEFORE DELETE ON erp_inv_reimputaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La reimputacion del ajuste se conserva';
