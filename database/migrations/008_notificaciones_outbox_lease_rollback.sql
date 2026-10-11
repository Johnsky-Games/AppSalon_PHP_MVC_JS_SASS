-- ============================================================================
-- Rollback Migración 008: Worker Lease Tokens y Recuperación Resiliente de Outbox
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/correcciones-auditoria-integral
-- ============================================================================

ALTER TABLE notificaciones_citas
    DROP INDEX idx_notificaciones_lease,
    DROP COLUMN lease_expires_at,
    DROP COLUMN worker_token;
