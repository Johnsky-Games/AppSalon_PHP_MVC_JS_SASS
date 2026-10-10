-- ============================================================================
-- Migración 005: Notificaciones Transaccionales de Citas, Idempotencia y Reintentos
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-6a-notificaciones
-- ============================================================================

CREATE TABLE IF NOT EXISTS notificaciones_citas (
    id INT NOT NULL AUTO_INCREMENT,
    cita_id INT NULL DEFAULT NULL,
    cita_referencia_id INT NOT NULL,
    usuario_id INT NULL DEFAULT NULL,
    tipo VARCHAR(32) NOT NULL,
    clave_idempotencia VARCHAR(120) NOT NULL,
    destinatario_email VARCHAR(60) NOT NULL,
    destinatario_nombre VARCHAR(120) NOT NULL,
    asunto VARCHAR(180) NOT NULL,
    payload_json JSON NULL DEFAULT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    intentos INT NOT NULL DEFAULT 0,
    max_intentos INT NOT NULL DEFAULT 3,
    ultimo_error VARCHAR(255) NULL DEFAULT NULL,
    enviado_at DATETIME NULL DEFAULT NULL,
    proximo_intento_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notificaciones_clave_idempotencia (clave_idempotencia),
    INDEX idx_notificaciones_estado_proximo (estado, proximo_intento_at),
    INDEX idx_notificaciones_cita_ref_tipo (cita_referencia_id, tipo),
    INDEX idx_notificaciones_cita_id (cita_id),
    INDEX idx_notificaciones_usuario_id (usuario_id),
    CONSTRAINT fk_notificaciones_cita
        FOREIGN KEY (cita_id) REFERENCES citas (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notificaciones_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
