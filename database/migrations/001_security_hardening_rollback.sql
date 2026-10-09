-- ============================================================================
-- Reversión de Migración 001: Endurecimiento de Seguridad y Consistencia
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/seguridad-y-consistencia-inicial
-- ============================================================================

-- 1. Eliminar tabla de Rate Limiting
DROP TABLE IF EXISTS intentos_login;

-- 2. Eliminar índices y columnas de tokens en `usuarios`
ALTER TABLE usuarios
    DROP INDEX IF EXISTS idx_token_hash,
    DROP INDEX IF EXISTS idx_token_expira;

ALTER TABLE usuarios
    DROP COLUMN IF EXISTS token_expira,
    DROP COLUMN IF EXISTS token_tipo,
    DROP COLUMN IF EXISTS token_hash;

-- 3. Eliminar registro en tabla de migraciones
DELETE FROM migraciones WHERE migracion = '001_security_hardening';
