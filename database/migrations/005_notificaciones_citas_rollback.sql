-- ============================================================================
-- Reversión de Migración 005: Notificaciones Transaccionales de Citas
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-6a-notificaciones
-- ============================================================================

DROP TABLE IF EXISTS notificaciones_citas;

DELETE FROM migraciones WHERE migracion = '005_notificaciones_citas';
