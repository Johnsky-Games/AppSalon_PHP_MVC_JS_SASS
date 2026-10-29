-- 070: conteo físico de inventario. Un conteo registra, al iniciar, su ALCANCE, su RESPONSABLE y las EXISTENCIAS DE REFERENCIA de cada producto; las cantidades se cuentan después
-- (NULL = sin contar, distinto de 0 = contado en cero); al cerrar pasa a aprobación, y al aprobar cada diferencia genera UN solo ajuste a través del flujo existente de solicitudes de
-- ajuste (erp_solicitudes tipo ajuste_stock → movimiento de inventario → concepto → hecho contable), vinculado aquí con el movimiento y su valoración.
-- La diferencia se calcula contra la existencia FÍSICA que el sistema tenía al momento de contar (no contra la referencia del inicio) y se aplica como DIFERENCIA sobre el stock actual:
-- nunca se sobrescribe el stock con una lectura antigua. Las reservas activas no son faltantes (la mercadería reservada sigue en el estante): solo limitan que el stock baje de lo reservado.
-- Todo se conserva: ni los conteos, ni sus líneas, ni su historial de lecturas, ni los ajustes aplicados se borran; un conteo aprobado solo se revierte con ajustes inversos.
CREATE TABLE IF NOT EXISTS erp_inv_conteos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 estado ENUM('en_conteo','pendiente','aprobado','rechazado','cancelado','revertido') NOT NULL DEFAULT 'en_conteo',
 alcance ENUM('todos_activos','seleccion') NOT NULL,
 descripcion VARCHAR(200) NOT NULL,
 responsable_id INT NOT NULL,
 iniciado_por INT NOT NULL,
 iniciado_en DATETIME NOT NULL,
 ultimo_movimiento_inicio BIGINT NOT NULL DEFAULT 0,
 cerrado_por INT NULL,
 cerrado_en DATETIME NULL,
 resuelto_por INT NULL,
 resuelto_en DATETIME NULL,
 motivo VARCHAR(300) NULL,
 concepto_faltante_id BIGINT NULL,
 concepto_sobrante_id BIGINT NULL,
 revertido_por INT NULL,
 revertido_en DATETIME NULL,
 motivo_reverso VARCHAR(300) NULL,
 CONSTRAINT fk_inv_conteo_faltante FOREIGN KEY (concepto_faltante_id) REFERENCES erp_inv_conceptos(id),
 CONSTRAINT fk_inv_conteo_sobrante FOREIGN KEY (concepto_sobrante_id) REFERENCES erp_inv_conceptos(id),
 CONSTRAINT ck_inv_conteo CHECK(CHAR_LENGTH(TRIM(descripcion))>=3
   AND (estado NOT IN('pendiente','aprobado','rechazado','revertido') OR (cerrado_en IS NOT NULL AND cerrado_por IS NOT NULL))
   AND (estado NOT IN('aprobado','rechazado','cancelado','revertido') OR (resuelto_en IS NOT NULL AND resuelto_por IS NOT NULL AND motivo IS NOT NULL))
   AND (estado<>'revertido' OR (revertido_en IS NOT NULL AND revertido_por IS NOT NULL AND motivo_reverso IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_inv_conteo_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 conteo_id BIGINT NOT NULL,
 producto_id INT NOT NULL,
 sku VARCHAR(40) NOT NULL,
 nombre VARCHAR(120) NOT NULL,
 unidad VARCHAR(20) NOT NULL,
 referencia_milesimas BIGINT NOT NULL,
 costo_ref_centavos BIGINT NOT NULL,
 ultimo_mov_ref BIGINT NOT NULL DEFAULT 0,
 contado_milesimas BIGINT NULL,
 contado_por INT NULL,
 contado_en DATETIME NULL,
 sistema_al_contar_milesimas BIGINT NULL,
 reservado_al_contar_milesimas BIGINT NULL,
 ultimo_mov_al_contar BIGINT NULL,
 diferencia_milesimas BIGINT GENERATED ALWAYS AS (IF(contado_milesimas IS NULL,NULL,contado_milesimas-sistema_al_contar_milesimas)) STORED,
 UNIQUE KEY uq_inv_conteo_producto (conteo_id,producto_id),
 INDEX idx_inv_conteo_linea_producto (producto_id),
 CONSTRAINT fk_inv_conteo_linea_conteo FOREIGN KEY (conteo_id) REFERENCES erp_inv_conteos(id),
 CONSTRAINT fk_inv_conteo_linea_producto FOREIGN KEY (producto_id) REFERENCES inventario_productos(id),
 CONSTRAINT ck_inv_conteo_linea CHECK(referencia_milesimas>=0 AND (contado_milesimas IS NULL OR contado_milesimas>=0)
   AND (contado_milesimas IS NULL)=(contado_en IS NULL) AND (contado_milesimas IS NULL)=(sistema_al_contar_milesimas IS NULL)
   AND (contado_milesimas IS NULL)=(reservado_al_contar_milesimas IS NULL) AND (contado_milesimas IS NULL)=(ultimo_mov_al_contar IS NULL) AND (contado_milesimas IS NULL)=(contado_por IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historial de lecturas (solo de anexar): cada vez que se cuenta o se recuenta un producto queda lo contado, lo que el sistema tenía en ese instante y quién lo hizo.
CREATE TABLE IF NOT EXISTS erp_inv_conteo_registros (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 conteo_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 contado_milesimas BIGINT NOT NULL,
 sistema_milesimas BIGINT NOT NULL,
 reservado_milesimas BIGINT NOT NULL,
 ultimo_mov_id BIGINT NOT NULL,
 actor_id INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_inv_conteo_registro_linea (linea_id,id),
 CONSTRAINT fk_inv_conteo_registro_conteo FOREIGN KEY (conteo_id) REFERENCES erp_inv_conteos(id),
 CONSTRAINT fk_inv_conteo_registro_linea FOREIGN KEY (linea_id) REFERENCES erp_inv_conteo_lineas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un ajuste por línea y por rol (conteo | reverso): la base impide generarlo dos veces. Guarda el movimiento de inventario, su costo y su valor al aplicarse y el concepto.
CREATE TABLE IF NOT EXISTS erp_inv_conteo_ajustes (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 conteo_id BIGINT NOT NULL,
 linea_id BIGINT NOT NULL,
 rol ENUM('conteo','reverso') NOT NULL,
 solicitud_id BIGINT NOT NULL,
 movimiento_id BIGINT NOT NULL,
 tipo ENUM('entrada','salida') NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 costo_centavos BIGINT NOT NULL,
 valor_centavos BIGINT NOT NULL,
 concepto_id BIGINT NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_inv_conteo_ajuste_linea (linea_id,rol),
 UNIQUE KEY uq_inv_conteo_ajuste_solicitud (solicitud_id),
 UNIQUE KEY uq_inv_conteo_ajuste_movimiento (movimiento_id),
 CONSTRAINT fk_inv_conteo_ajuste_conteo FOREIGN KEY (conteo_id) REFERENCES erp_inv_conteos(id),
 CONSTRAINT fk_inv_conteo_ajuste_linea FOREIGN KEY (linea_id) REFERENCES erp_inv_conteo_lineas(id),
 CONSTRAINT fk_inv_conteo_ajuste_solicitud FOREIGN KEY (solicitud_id) REFERENCES erp_solicitudes(id),
 CONSTRAINT fk_inv_conteo_ajuste_mov FOREIGN KEY (movimiento_id) REFERENCES inventario_movimientos(id),
 CONSTRAINT fk_inv_conteo_ajuste_concepto FOREIGN KEY (concepto_id) REFERENCES erp_inv_conceptos(id),
 CONSTRAINT ck_inv_conteo_ajuste CHECK(cantidad_milesimas>0 AND costo_centavos>=0 AND valor_centavos>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_inv_conteo_update;
DROP TRIGGER IF EXISTS trg_inv_conteo_no_delete;
DROP TRIGGER IF EXISTS trg_inv_conteo_linea_insert;
DROP TRIGGER IF EXISTS trg_inv_conteo_linea_update;
DROP TRIGGER IF EXISTS trg_inv_conteo_linea_no_delete;
DROP TRIGGER IF EXISTS trg_inv_conteo_registro_no_update;
DROP TRIGGER IF EXISTS trg_inv_conteo_registro_no_delete;
DROP TRIGGER IF EXISTS trg_inv_conteo_ajuste_no_update;
DROP TRIGGER IF EXISTS trg_inv_conteo_ajuste_no_delete;
-- Triggers de una sola sentencia (IF ... END IF) para que la restauración los reponga.
-- Un conteo solo avanza: en_conteo → pendiente|cancelado, pendiente → aprobado|rechazado|cancelado, aprobado → revertido; lo fijado al iniciar no cambia.
CREATE TRIGGER trg_inv_conteo_update BEFORE UPDATE ON erp_inv_conteos FOR EACH ROW IF NEW.id<>OLD.id OR NEW.alcance<>OLD.alcance OR NEW.descripcion<>OLD.descripcion OR NEW.responsable_id<>OLD.responsable_id OR NEW.iniciado_por<>OLD.iniciado_por OR NEW.iniciado_en<>OLD.iniciado_en OR NEW.ultimo_movimiento_inicio<>OLD.ultimo_movimiento_inicio
  OR NOT ((OLD.estado='en_conteo' AND NEW.estado IN('pendiente','cancelado')) OR (OLD.estado='pendiente' AND NEW.estado IN('aprobado','rechazado','cancelado')) OR (OLD.estado='aprobado' AND NEW.estado='revertido'))
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un conteo solo avanza de estado y lo fijado al iniciar no cambia'; END IF;
CREATE TRIGGER trg_inv_conteo_no_delete BEFORE DELETE ON erp_inv_conteos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los conteos de inventario se conservan';
CREATE TRIGGER trg_inv_conteo_linea_insert BEFORE INSERT ON erp_inv_conteo_lineas FOR EACH ROW IF (SELECT estado FROM erp_inv_conteos WHERE id=NEW.conteo_id)<>'en_conteo' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El alcance del conteo se fija al iniciarlo'; END IF;
-- Las lecturas solo cambian mientras el conteo está en conteo; lo fijado al iniciar (producto, referencia, costo) no cambia nunca.
CREATE TRIGGER trg_inv_conteo_linea_update BEFORE UPDATE ON erp_inv_conteo_lineas FOR EACH ROW IF NEW.conteo_id<>OLD.conteo_id OR NEW.producto_id<>OLD.producto_id OR NEW.referencia_milesimas<>OLD.referencia_milesimas OR NEW.costo_ref_centavos<>OLD.costo_ref_centavos OR NEW.ultimo_mov_ref<>OLD.ultimo_mov_ref OR NEW.sku<>OLD.sku OR NEW.nombre<>OLD.nombre
  OR (SELECT estado FROM erp_inv_conteos WHERE id=OLD.conteo_id)<>'en_conteo' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lecturas solo cambian mientras el conteo esta en conteo'; END IF;
CREATE TRIGGER trg_inv_conteo_linea_no_delete BEFORE DELETE ON erp_inv_conteo_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las lineas de un conteo se conservan';
CREATE TRIGGER trg_inv_conteo_registro_no_update BEFORE UPDATE ON erp_inv_conteo_registros FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El historial del conteo no se edita';
CREATE TRIGGER trg_inv_conteo_registro_no_delete BEFORE DELETE ON erp_inv_conteo_registros FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El historial del conteo se conserva';
CREATE TRIGGER trg_inv_conteo_ajuste_no_update BEFORE UPDATE ON erp_inv_conteo_ajustes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un ajuste aplicado no se edita: se revierte con un ajuste inverso';
CREATE TRIGGER trg_inv_conteo_ajuste_no_delete BEFORE DELETE ON erp_inv_conteo_ajustes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los ajustes aplicados se conservan';
