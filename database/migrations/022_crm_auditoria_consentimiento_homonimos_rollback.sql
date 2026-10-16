-- Reversión SEGURA de 022. La auditoría y el historial de consentimiento son evidencia de negocio: la reversión
-- se NIEGA (error, sin cambiar nada) si contienen filas que no sean el retro-llenado reproducible de la migración.
-- Cada guarda es una subconsulta escalar que devuelve más de una fila (error 1242) únicamente cuando hay datos.
-- Para revertir de todos modos, primero archive las tablas (mysqldump) y vacíelas con un usuario con privilegios
-- de administración (los triggers de solo anexado no impiden TRUNCATE ni DROP: es una decisión humana y respaldada).
DO (SELECT 'crm_exportaciones_auditoria tiene registros: respáldela y vacíela antes de revertir' FROM crm_exportaciones_auditoria CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'altas_homonimos_decisiones tiene registros: respáldela y vacíela antes de revertir' FROM altas_homonimos_decisiones CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'consentimientos_historial tiene decisiones de personas (no solo el retro-llenado): respáldelo antes de revertir' FROM (SELECT 1 FROM consentimientos_historial WHERE origen <> 'migracion') AS x CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'consentimiento_versiones tiene versiones publicadas además de la 1: respáldelas antes de revertir' FROM (SELECT 1 FROM consentimiento_versiones WHERE version > 1) AS x CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles_usuario' AND COLUMN_NAME = 'consentimiento_marketing_version_id') > 0,
 'ALTER TABLE perfiles_usuario DROP FOREIGN KEY fk_perfil_consent_version, DROP COLUMN consentimiento_marketing_version_id',
 'DO 0');
PREPARE st_perfil_version FROM @sql;
EXECUTE st_perfil_version;
DEALLOCATE PREPARE st_perfil_version;

DROP TABLE IF EXISTS altas_homonimos_decisiones;
DROP TABLE IF EXISTS crm_exportaciones_auditoria;
DROP TABLE IF EXISTS consentimientos_historial;
DROP TABLE IF EXISTS consentimiento_versiones;
