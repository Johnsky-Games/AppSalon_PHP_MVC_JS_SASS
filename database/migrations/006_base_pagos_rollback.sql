-- ============================================================================
-- Reversión de Migración 006: Base para Pagos
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-6c-base-pagos
-- ============================================================================

DROP TABLE IF EXISTS pagos;

ALTER TABLE citas
    DROP INDEX idx_citas_estado_pago,
    DROP COLUMN estado_pago;

DELETE FROM migraciones WHERE migracion = '006_base_pagos';
