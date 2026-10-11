-- ============================================================================
-- Migración 006: Base para Pagos, Idempotencia Transaccional y Estados de Pago
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-6c-base-pagos
-- ============================================================================

-- 1. Incorporar estado de pago en la tabla `citas` (por defecto 'pendiente')
ALTER TABLE citas
    ADD COLUMN estado_pago VARCHAR(20) NOT NULL DEFAULT 'pendiente' AFTER profesionalId;

-- 2. Crear tabla `pagos` con restricciones únicas de referencia, transacción externa e idempotencia
CREATE TABLE IF NOT EXISTS pagos (
    id INT NOT NULL AUTO_INCREMENT,
    cita_id INT NULL DEFAULT NULL,
    cita_referencia_id INT NOT NULL,
    usuario_id INT NULL DEFAULT NULL,
    referencia_pago VARCHAR(64) NOT NULL,
    proveedor VARCHAR(32) NOT NULL,
    transaccion_externa_id VARCHAR(100) NULL DEFAULT NULL,
    monto DECIMAL(8,2) NOT NULL,
    moneda VARCHAR(3) NOT NULL DEFAULT 'USD',
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    metodo_pago VARCHAR(30) NULL DEFAULT NULL,
    idempotency_key VARCHAR(120) NOT NULL,
    payload_proveedor JSON NULL DEFAULT NULL,
    pagado_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pagos_referencia (referencia_pago),
    UNIQUE KEY uq_pagos_transaccion_externa (transaccion_externa_id),
    UNIQUE KEY uq_pagos_idempotency_key (idempotency_key),
    INDEX idx_pagos_cita_id (cita_id),
    INDEX idx_pagos_cita_ref_estado (cita_referencia_id, estado),
    INDEX idx_pagos_usuario_id (usuario_id),
    CONSTRAINT fk_pagos_cita
        FOREIGN KEY (cita_id) REFERENCES citas (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_pagos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
