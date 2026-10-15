-- Reversión SEGURA de la 023. Antes de quitar las columnas copia QUIÉN y CUÁNDO de cada transición de atención a
-- `citas_atencion_resguardo_023`, tabla persistente que este rollback NO elimina; al reaplicar la 023 se restauran. El estado de la cita
-- (`citas.estado`) y `agenda_eventos` no se tocan, así que no se pierde ningún dato de negocio. Idempotente.
-- Mientras la 023 esté revertida la aplicación vigente no tiene dónde leer esa trazabilidad: este rollback sirve para reaplicar la
-- migración, no para ejecutar código anterior a la 023 con garantías de auditoría.
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

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND COLUMN_NAME = 'atencion_inicio_at') > 0,
 'INSERT INTO citas_atencion_resguardo_023 (cita_id, atencion_inicio_at, atencion_inicio_por, atencion_fin_at, atencion_fin_por, ausencia_at, ausencia_por, resguardado_en, restaurado_en)
  SELECT id, atencion_inicio_at, atencion_inicio_por, atencion_fin_at, atencion_fin_por, ausencia_at, ausencia_por, UTC_TIMESTAMP(), NULL FROM citas
  WHERE atencion_inicio_at IS NOT NULL OR atencion_fin_at IS NOT NULL OR ausencia_at IS NOT NULL OR atencion_inicio_por IS NOT NULL OR atencion_fin_por IS NOT NULL OR ausencia_por IS NOT NULL
  ON DUPLICATE KEY UPDATE atencion_inicio_at = VALUES(atencion_inicio_at), atencion_inicio_por = VALUES(atencion_inicio_por),
    atencion_fin_at = VALUES(atencion_fin_at), atencion_fin_por = VALUES(atencion_fin_por),
    ausencia_at = VALUES(ausencia_at), ausencia_por = VALUES(ausencia_por), resguardado_en = VALUES(resguardado_en), restaurado_en = NULL',
 'DO 0');
PREPARE st_atencion_resguardo FROM @sql;
EXECUTE st_atencion_resguardo;
DEALLOCATE PREPARE st_atencion_resguardo;

DROP TRIGGER IF EXISTS trg_citas_estado_terminal;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND COLUMN_NAME = 'atencion_inicio_at') > 0,
 'ALTER TABLE citas
    DROP FOREIGN KEY fk_citas_atencion_inicio_por,
    DROP FOREIGN KEY fk_citas_atencion_fin_por,
    DROP FOREIGN KEY fk_citas_ausencia_por,
    DROP COLUMN ausencia_por, DROP COLUMN ausencia_at,
    DROP COLUMN atencion_fin_por, DROP COLUMN atencion_fin_at,
    DROP COLUMN atencion_inicio_por, DROP COLUMN atencion_inicio_at',
 'DO 0');
PREPARE st_atencion_quitar FROM @sql;
EXECUTE st_atencion_quitar;
DEALLOCATE PREPARE st_atencion_quitar;
