-- 019: la cola de correos (correos_salida) conoce al USUARIO al que pertenece un evento de cuenta.
-- Sirve para cancelar por usuario los enlaces de confirmación que un reenvío deja obsoletos y para el diagnóstico
-- operativo. Es idempotente (comprueba el catálogo antes de alterar) y NO modifica ni borra eventos.
-- La columna es informativa y sin FK a propósito: el outbox no debe bloquear ni arrastrar el borrado de usuarios.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correos_salida' AND COLUMN_NAME = 'usuario_id');
SET @sql := IF(@col = 0, 'ALTER TABLE correos_salida ADD COLUMN usuario_id INT NULL DEFAULT NULL AFTER tipo', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índice SIN la columna estado: el worker actualiza `estado` por clave primaria y un índice que la incluyera
-- cruzaría sus bloqueos con los del reenvío (posible interbloqueo 1213).
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correos_salida' AND INDEX_NAME = 'idx_correo_usuario');
SET @sql := IF(@idx = 0, 'ALTER TABLE correos_salida ADD KEY idx_correo_usuario (usuario_id, tipo)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Retro-relleno: la clave única de las confirmaciones es «confirmacion:<usuario_id>:<huella>».
UPDATE correos_salida
   SET usuario_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(clave_unica, ':', 2), ':', -1) AS UNSIGNED)
 WHERE usuario_id IS NULL AND tipo = 'confirmacion_cuenta' AND clave_unica REGEXP '^confirmacion:[0-9]+:';
