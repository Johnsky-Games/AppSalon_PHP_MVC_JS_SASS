-- ============================================================================
-- Migración 002: Conversión a InnoDB y Ventana Temporal de Rate Limiting
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/seguridad-y-consistencia-inicial
-- ============================================================================

-- 1. Asegurar motor InnoDB en todas las tablas para soporte estricto de transacciones ACID y bloqueos
ALTER TABLE usuarios ENGINE = InnoDB;
ALTER TABLE servicios ENGINE = InnoDB;
ALTER TABLE citas ENGINE = InnoDB;
ALTER TABLE citasservicios ENGINE = InnoDB;
ALTER TABLE intentos_login ENGINE = InnoDB;

-- 2. Añadir columna `primera_peticion` para medición de ventana temporal en rate limiting
ALTER TABLE intentos_login
    ADD COLUMN primera_peticion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER bloqueado_hasta;
