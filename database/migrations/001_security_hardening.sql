-- ============================================================================
-- Migración 001: Endurecimiento de Seguridad y Consistencia
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/seguridad-y-consistencia-inicial
-- ============================================================================

-- 1. Tabla de Control de Migraciones Versionadas
CREATE TABLE IF NOT EXISTS migraciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migracion VARCHAR(255) NOT NULL UNIQUE,
    aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Asegurar motor InnoDB en todas las tablas existentes para garantizar transacciones ACID
ALTER TABLE usuarios ENGINE = InnoDB;
ALTER TABLE servicios ENGINE = InnoDB;
ALTER TABLE citas ENGINE = InnoDB;
ALTER TABLE citasservicios ENGINE = InnoDB;

-- 3. Modificaciones en la tabla `usuarios`
-- Columnas añadidas para soporte de tokens criptográficos con hash, tipo y vencimiento
ALTER TABLE usuarios
    ADD COLUMN token_hash VARCHAR(64) NULL AFTER token,
    ADD COLUMN token_tipo VARCHAR(20) NULL AFTER token_hash,
    ADD COLUMN token_expira DATETIME NULL AFTER token_tipo;

-- Índices para búsquedas eficientes por token y tipo
ALTER TABLE usuarios
    ADD INDEX idx_token_hash (token_hash),
    ADD INDEX idx_token_expira (token_expira);

-- 4. Tabla Unificada para Rate Limiting Atómico (`intentos_login`)
CREATE TABLE IF NOT EXISTS intentos_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identificador VARCHAR(100) NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    intentos INT NOT NULL DEFAULT 1,
    bloqueado_hasta DATETIME NULL,
    primera_peticion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_intento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tipo_identificador (tipo, identificador),
    INDEX idx_bloqueado_hasta (bloqueado_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registrar migración como aplicada
INSERT INTO migraciones (migracion) VALUES ('001_security_hardening')
ON DUPLICATE KEY UPDATE aplicada_en = CURRENT_TIMESTAMP;
