-- ============================================================================
-- Reversión de Migración 006: Base para Pagos
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-6c-base-pagos
-- ============================================================================

DROP TABLE IF EXISTS pagos;

ALTER TABLE citas
    DROP COLUMN estado_pago;

DELETE FROM migraciones WHERE migracion = '006_base_pagos';
