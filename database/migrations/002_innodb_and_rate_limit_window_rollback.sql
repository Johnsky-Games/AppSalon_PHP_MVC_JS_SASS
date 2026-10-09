-- ============================================================================
-- Reversión de Migración 002: Conversión a InnoDB y Ventana Temporal de Rate Limiting
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/seguridad-y-consistencia-inicial
-- ============================================================================

ALTER TABLE intentos_login
    DROP COLUMN primera_peticion;

DELETE FROM migraciones WHERE migracion = '002_innodb_and_rate_limit_window';
