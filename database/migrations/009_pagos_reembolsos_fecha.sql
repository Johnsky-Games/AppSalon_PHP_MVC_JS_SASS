-- ============================================================================
-- Migración 009: Fecha explícita de reembolso en tabla `pagos`
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/correcciones-auditoria-integral
-- ============================================================================

ALTER TABLE pagos
    ADD COLUMN reembolsado_at DATETIME NULL DEFAULT NULL AFTER pagado_at,
    ADD INDEX idx_pagos_reembolsado_at (reembolsado_at);
