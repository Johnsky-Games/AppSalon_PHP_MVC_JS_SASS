-- 023: trazabilidad de la atención de una cita (ronda 5, agenda → atención → finalización).
-- Cada transición de la máquina de estados (confirmada → en_servicio → completada, confirmada → no_asistio) deja, además del
-- evento append-only de `agenda_eventos`, QUIÉN la hizo y CUÁNDO en la propia cita, para que la agenda y el detalle lo muestren sin
-- reconstruirlo. Las horas son las del negocio (America/Guayaquil), igual que `agenda_eventos.creado_en` y `cancelado_at`.
--   atencion_inicio_at / _por : cuándo y quién inició la atención (confirmada → en_servicio)
--   atencion_fin_at    / _por : cuándo y quién la finalizó       (en_servicio → completada)
--   ausencia_at        / _por : cuándo y quién registró la ausencia (confirmada → no_asistio)
-- Idempotente: cada paso comprueba el estado real del esquema. Compatible con MySQL 8.0.13+.

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND COLUMN_NAME = 'atencion_inicio_at') = 0,
 'ALTER TABLE citas
    ADD COLUMN atencion_inicio_at DATETIME NULL DEFAULT NULL AFTER estado_pago,
    ADD COLUMN atencion_inicio_por INT NULL DEFAULT NULL AFTER atencion_inicio_at,
    ADD COLUMN atencion_fin_at DATETIME NULL DEFAULT NULL AFTER atencion_inicio_por,
    ADD COLUMN atencion_fin_por INT NULL DEFAULT NULL AFTER atencion_fin_at,
    ADD COLUMN ausencia_at DATETIME NULL DEFAULT NULL AFTER atencion_fin_por,
    ADD COLUMN ausencia_por INT NULL DEFAULT NULL AFTER ausencia_at,
    ADD CONSTRAINT fk_citas_atencion_inicio_por FOREIGN KEY (atencion_inicio_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_citas_atencion_fin_por FOREIGN KEY (atencion_fin_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_citas_ausencia_por FOREIGN KEY (ausencia_por) REFERENCES usuarios (id) ON DELETE SET NULL',
 'DO 0');
PREPARE st_citas_atencion FROM @sql;
EXECUTE st_citas_atencion;
DEALLOCATE PREPARE st_citas_atencion;

-- Resguardo persistente de la trazabilidad (lo escribe el rollback y NUNCA lo elimina). Al reaplicar, las filas pendientes se
-- restauran en `citas` y se marcan con `restaurado_en`. Está vacía en una instalación que nunca revirtió la 023.
-- Purga manual (solo cuando el operador confirme que ya no hace falta):  DELETE FROM citas_atencion_resguardo_023 WHERE restaurado_en IS NOT NULL;
CREATE TABLE IF NOT EXISTS citas_atencion_resguardo_023 (
 cita_id INT NOT NULL PRIMARY KEY,
 atencion_inicio_at DATETIME NULL,
 atencion_inicio_por INT NULL,
 atencion_fin_at DATETIME NULL,
 atencion_fin_por INT NULL,
 ausencia_at DATETIME NULL,
 ausencia_por INT NULL,
 resguardado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 restaurado_en DATETIME NULL DEFAULT NULL,
 INDEX idx_atencion_resguardo_pendiente (restaurado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- (1) Restaura lo resguardado por una reversión anterior (solo citas que siguen existiendo; el actor solo si la cuenta existe).
UPDATE citas c INNER JOIN citas_atencion_resguardo_023 r ON r.cita_id = c.id AND r.restaurado_en IS NULL
SET c.atencion_inicio_at = r.atencion_inicio_at,
    c.atencion_inicio_por = IF(EXISTS (SELECT 1 FROM usuarios u WHERE u.id = r.atencion_inicio_por), r.atencion_inicio_por, NULL),
    c.atencion_fin_at = r.atencion_fin_at,
    c.atencion_fin_por = IF(EXISTS (SELECT 1 FROM usuarios u WHERE u.id = r.atencion_fin_por), r.atencion_fin_por, NULL),
    c.ausencia_at = r.ausencia_at,
    c.ausencia_por = IF(EXISTS (SELECT 1 FROM usuarios u WHERE u.id = r.ausencia_por), r.ausencia_por, NULL);
UPDATE citas_atencion_resguardo_023 SET restaurado_en = UTC_TIMESTAMP() WHERE restaurado_en IS NULL;

-- (2) Retrocarga PRUDENTE desde el rastro que ya existe (`agenda_eventos`, tipo «estado»): solo rellena huecos y solo cuando el
-- evento más reciente que dejó a la cita en ese estado conserva su fecha y su actor. No inventa actores: las citas cuyo estado se
-- cambió sin evento (datos históricos o cambios directos en base) quedan con NULL y la pantalla lo muestra como «sin registro».
UPDATE citas c
INNER JOIN (SELECT citaId, MAX(id) AS eid FROM agenda_eventos WHERE tipo = 'estado' AND JSON_UNQUOTE(JSON_EXTRACT(despues_json, '$.estado')) = 'en_servicio' GROUP BY citaId) m ON m.citaId = c.id
INNER JOIN agenda_eventos e ON e.id = m.eid
SET c.atencion_inicio_at = e.creado_en, c.atencion_inicio_por = e.actorId
WHERE c.estado IN ('en_servicio', 'completada') AND c.atencion_inicio_at IS NULL;

UPDATE citas c
INNER JOIN (SELECT citaId, MAX(id) AS eid FROM agenda_eventos WHERE tipo = 'estado' AND JSON_UNQUOTE(JSON_EXTRACT(despues_json, '$.estado')) = 'completada' GROUP BY citaId) m ON m.citaId = c.id
INNER JOIN agenda_eventos e ON e.id = m.eid
SET c.atencion_fin_at = e.creado_en, c.atencion_fin_por = e.actorId
WHERE c.estado = 'completada' AND c.atencion_fin_at IS NULL;

UPDATE citas c
INNER JOIN (SELECT citaId, MAX(id) AS eid FROM agenda_eventos WHERE tipo = 'estado' AND JSON_UNQUOTE(JSON_EXTRACT(despues_json, '$.estado')) = 'no_asistio' GROUP BY citaId) m ON m.citaId = c.id
INNER JOIN agenda_eventos e ON e.id = m.eid
SET c.ausencia_at = e.creado_en, c.ausencia_por = e.actorId
WHERE c.estado = 'no_asistio' AND c.ausencia_at IS NULL;

-- (3) Defensa en profundidad de la máquina de estados: una cita completada o con ausencia registrada es histórica y NO cambia de
-- estado (la aplicación ya lo impide; esto lo garantiza aunque otro código o un UPDATE directo lo intente). «cancelada» conserva
-- su flujo propio y no se toca aquí.
DROP TRIGGER IF EXISTS trg_citas_estado_terminal;
CREATE TRIGGER trg_citas_estado_terminal BEFORE UPDATE ON citas FOR EACH ROW
IF OLD.estado IN ('completada', 'no_asistio') AND NEW.estado <> OLD.estado THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una cita completada o con ausencia registrada es historica y no cambia de estado';
END IF;
