-- ============================================================================
-- Rollback de Migración 007: Estado de Citas, Trazabilidad de Cancelación y Optimización de Agenda
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/correcciones-auditoria-integral
-- ============================================================================

ALTER TABLE citas
    DROP INDEX idx_citas_prof_fecha_estado,
    DROP INDEX idx_citas_estado,
    DROP COLUMN motivo_cancelacion,
    DROP COLUMN cancelado_at,
    DROP COLUMN cancelado_por,
    DROP COLUMN estado;
