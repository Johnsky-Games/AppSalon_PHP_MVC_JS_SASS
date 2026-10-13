-- Rollback SEGURO de 019: solo retira la columna informativa y su índice. No toca eventos (pendientes, fallidos
-- ni enviados), por lo que no se pierde ningún correo en cola. El usuario de las confirmaciones sigue siendo
-- recuperable de clave_unica («confirmacion:<usuario_id>:<huella>»).
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correos_salida' AND INDEX_NAME = 'idx_correo_usuario');
SET @sql := IF(@idx > 0, 'ALTER TABLE correos_salida DROP INDEX idx_correo_usuario', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correos_salida' AND COLUMN_NAME = 'usuario_id');
SET @sql := IF(@col > 0, 'ALTER TABLE correos_salida DROP COLUMN usuario_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
