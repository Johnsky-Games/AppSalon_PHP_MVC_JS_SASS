CREATE TABLE perfiles_usuario (
    usuarioId INT NOT NULL PRIMARY KEY,
    contacto_email TINYINT(1) NOT NULL DEFAULT 1,
    marketing_email TINYINT(1) NOT NULL DEFAULT 0,
    recordatorios TINYINT(1) NOT NULL DEFAULT 1,
    foto_publica TINYINT(1) NOT NULL DEFAULT 0,
    consentimiento_marketing_at DATETIME NULL,
    actualizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_perfil_usuario FOREIGN KEY (usuarioId) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE perfil_fotos (
    usuarioId INT NOT NULL PRIMARY KEY,
    archivo CHAR(68) NOT NULL,
    mime VARCHAR(32) NOT NULL DEFAULT 'image/jpeg',
    actualizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_foto_usuario FOREIGN KEY (usuarioId) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
