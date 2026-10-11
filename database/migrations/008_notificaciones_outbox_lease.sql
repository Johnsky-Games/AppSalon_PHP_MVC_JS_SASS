-- ============================================================================
-- Migración 008: Worker Lease Tokens y Recuperación Resiliente de Outbox
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/correcciones-auditoria-integral
-- ============================================================================

ALTER TABLE notificaciones_citas
    ADD COLUMN worker_token VARCHAR(64) NULL DEFAULT NULL AFTER proximo_intento_at,
    ADD COLUMN lease_expires_at DATETIME NULL DEFAULT NULL AFTER worker_token,
    ADD INDEX idx_notificaciones_lease (estado, lease_expires_at);
