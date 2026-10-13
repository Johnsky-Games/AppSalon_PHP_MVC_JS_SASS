-- Rollback SEGURO de 021: la auditoría es un dato de negocio. Si la tabla tiene filas NO se elimina: se renombra a
-- citas_reasignaciones_respaldo_021 (los datos se conservan) y se le quitan las claves foráneas (sus nombres son únicos
-- por esquema y bloquearían volver a aplicar 021). Si ese respaldo ya existe, el RENAME falla a propósito y el rollback
-- se detiene sin perder nada; el operador debe revisarlo y archivarlo antes de reintentar.
SET @existe := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'citas_reasignaciones');
SET @filas := 0;
SET @contar := IF(@existe = 0, 'SELECT 0 INTO @filas', 'SELECT COUNT(*) INTO @filas FROM citas_reasignaciones');
PREPARE contar_021 FROM @contar;
EXECUTE contar_021;
DEALLOCATE PREPARE contar_021;
SET @sentencia := IF(@existe = 0, 'SELECT 1',
    IF(@filas = 0, 'DROP TABLE citas_reasignaciones', 'RENAME TABLE citas_reasignaciones TO citas_reasignaciones_respaldo_021'));
PREPARE paso_021 FROM @sentencia;
EXECUTE paso_021;
DEALLOCATE PREPARE paso_021;
SET @sentencia := IF(@existe = 0 OR @filas = 0, 'SELECT 1',
    'ALTER TABLE citas_reasignaciones_respaldo_021 DROP FOREIGN KEY fk_reasignacion_cita, DROP FOREIGN KEY fk_reasignacion_origen, DROP FOREIGN KEY fk_reasignacion_destino, DROP FOREIGN KEY fk_reasignacion_actor');
PREPARE paso_021 FROM @sentencia;
EXECUTE paso_021;
DEALLOCATE PREPARE paso_021;

-- Decisiones de homónimos de profesionales (auditoría de solo anexado): mismo criterio. Con filas se renombra a
-- profesionales_homonimos_decisiones_respaldo_021 y se retiran sus disparadores (sus nombres son únicos por esquema
-- y bloquearían volver a aplicar 021); vacía, se elimina.
SET @existe := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'profesionales_homonimos_decisiones');
SET @filas := 0;
SET @contar := IF(@existe = 0, 'SELECT 0 INTO @filas', 'SELECT COUNT(*) INTO @filas FROM profesionales_homonimos_decisiones');
PREPARE contar_021 FROM @contar;
EXECUTE contar_021;
DEALLOCATE PREPARE contar_021;
SET @sentencia := IF(@existe = 0, 'SELECT 1',
    IF(@filas = 0, 'DROP TABLE profesionales_homonimos_decisiones', 'RENAME TABLE profesionales_homonimos_decisiones TO profesionales_homonimos_decisiones_respaldo_021'));
PREPARE paso_021 FROM @sentencia;
EXECUTE paso_021;
DEALLOCATE PREPARE paso_021;
DROP TRIGGER IF EXISTS trg_prof_homonimo_no_update;
DROP TRIGGER IF EXISTS trg_prof_homonimo_no_delete;
