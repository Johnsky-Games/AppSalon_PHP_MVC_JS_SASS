-- Identidad de equipo, revocación y bloqueo global para proteger al último propietario.
ALTER TABLE usuarios ADD COLUMN version_sesion INT UNSIGNED NOT NULL DEFAULT 1;
CREATE TABLE usuario_accesos (
 usuario_id INT PRIMARY KEY,
 rol ENUM('propietario','gerente','recepcion','profesional','finanzas','cliente') NOT NULL DEFAULT 'cliente',
 activo TINYINT(1) NOT NULL DEFAULT 1,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_acceso_usuario FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO usuario_accesos(usuario_id,rol) SELECT id,IF(admin=1,'propietario','cliente') FROM usuarios;
ALTER TABLE profesionales ADD COLUMN usuarioId INT NULL DEFAULT NULL,
 ADD UNIQUE KEY uq_profesional_cuenta(usuarioId),
 ADD CONSTRAINT fk_profesional_cuenta FOREIGN KEY(usuarioId) REFERENCES usuarios(id) ON DELETE SET NULL;
CREATE TABLE equipo_mutex (id INT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO equipo_mutex VALUES(1);
CREATE TABLE equipo_auditoria (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id INT NULL,
 usuario_id INT NULL,
 accion VARCHAR(40) NOT NULL,
 detalle VARCHAR(255) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_equipo_fecha(creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
