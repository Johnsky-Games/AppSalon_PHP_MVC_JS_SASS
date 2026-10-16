-- ============================================================================
-- Migración 007: Estado de Citas, Trazabilidad de Cancelación y Optimización de Agenda
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/correcciones-auditoria-integral
-- ============================================================================

ALTER TABLE citas
    ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'confirmada' AFTER profesionalId,
    ADD COLUMN cancelado_por VARCHAR(50) NULL DEFAULT NULL AFTER estado,
    ADD COLUMN cancelado_at DATETIME NULL DEFAULT NULL AFTER cancelado_por,
    ADD COLUMN motivo_cancelacion VARCHAR(255) NULL DEFAULT NULL AFTER cancelado_at,
    ADD INDEX idx_citas_estado (estado),
    ADD INDEX idx_citas_prof_fecha_estado (profesionalId, fecha, estado);
