-- Reversión SEGURA de 027. Las regularizaciones (solicitudes, decisiones, quién y cuándo) son evidencia de negocio y afectan
-- cómo se atendió y cobró una cita: la reversión se NIEGA (error 1242, sin cambiar nada) si hay filas en citas_regularizaciones
-- o citas con datos de regularización. Para revertir de todos modos, respalde (mysqldump) y vacíe esos datos con una decisión humana.
DO (SELECT 'citas_regularizaciones tiene registros: respáldela y vacíela antes de revertir' FROM citas_regularizaciones CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND COLUMN_NAME = 'regularizada_at') > 0,
 'DO (SELECT ''citas con regularizacion: respaldelas antes de revertir'' FROM citas CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos WHERE citas.regularizada_at IS NOT NULL OR citas.atencion_efectiva_at IS NOT NULL OR citas.regularizada_por IS NOT NULL)',
 'DO 0');
PREPARE st_regul_guarda FROM @sql;
EXECUTE st_regul_guarda;
DEALLOCATE PREPARE st_regul_guarda;

DROP TABLE IF EXISTS citas_regularizaciones;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND INDEX_NAME = 'idx_citas_estado_fecha') > 0,
 'ALTER TABLE citas DROP INDEX idx_citas_estado_fecha', 'DO 0');
PREPARE st_regul_idx FROM @sql;
EXECUTE st_regul_idx;
DEALLOCATE PREPARE st_regul_idx;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND COLUMN_NAME = 'regularizada_at') > 0,
 'ALTER TABLE citas DROP FOREIGN KEY fk_citas_regularizada_por, DROP COLUMN regularizada_at, DROP COLUMN regularizada_por, DROP COLUMN atencion_efectiva_at',
 'DO 0');
PREPARE st_regul_cols FROM @sql;
EXECUTE st_regul_cols;
DEALLOCATE PREPARE st_regul_cols;
