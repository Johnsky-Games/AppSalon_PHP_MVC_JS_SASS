-- ============================================================================
-- Reversión de Migración 003: Profesionales, Duración de Servicios y Horarios
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-3a-profesionales-horarios
-- ============================================================================

DROP TABLE IF EXISTS bloqueos_profesionales;
DROP TABLE IF EXISTS descansos_profesionales;
DROP TABLE IF EXISTS horarios_profesionales;
DROP TABLE IF EXISTS profesionales_servicios;
DROP TABLE IF EXISTS profesionales;

ALTER TABLE servicios
    DROP COLUMN duracion_minutos;

DELETE FROM migraciones WHERE migracion = '003_profesionales_duracion_y_horarios';
