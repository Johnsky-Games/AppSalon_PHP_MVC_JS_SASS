-- ============================================================================
-- Rollback Migración 009: Eliminar columna reembolsado_at de tabla `pagos`
-- ============================================================================

ALTER TABLE pagos
    DROP INDEX idx_pagos_reembolsado_at,
    DROP COLUMN reembolsado_at;
