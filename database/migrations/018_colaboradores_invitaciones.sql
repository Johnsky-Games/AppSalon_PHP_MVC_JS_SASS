-- Colaboradores (ficha laboral), invitaciones formales, correos de salida (outbox) y vínculo profesional ↔ colaborador.
-- La cuenta de acceso (usuarios) sigue siendo la identidad compartida; el colaborador es la MEMBRESÍA laboral.

CREATE TABLE colaboradores (
 id INT AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
 usuario_id INT NULL,
 email_norm VARCHAR(120) NOT NULL,
 nombre VARCHAR(60) NOT NULL,
 apellido VARCHAR(60) NOT NULL,
 telefono VARCHAR(10) NULL,
 puesto VARCHAR(80) NOT NULL DEFAULT '',
 rol ENUM('propietario','gerente','recepcion','profesional','finanzas') NOT NULL,
 estado ENUM('invitado','activo','suspendido','baja') NOT NULL DEFAULT 'invitado',
 fecha_ingreso DATE NULL,
 notas_rh VARCHAR(500) NULL,
 creado_por INT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 aceptado_en DATETIME NULL,
 baja_en DATETIME NULL,
 UNIQUE KEY uq_colaborador_email (email_norm),
 UNIQUE KEY uq_colaborador_usuario (usuario_id),
 UNIQUE KEY uq_colaborador_codigo (codigo),
 KEY idx_colaborador_estado (estado, rol),
 CONSTRAINT fk_colaborador_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Retrocarga: cada acceso laboral existente se convierte en ficha; se conservan roles y suspensiones
-- (nunca se reactiva una cuenta ni se cambia un rol).
INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,telefono,rol,estado,aceptado_en)
SELECT u.id, LOWER(TRIM(u.email)), u.nombre, u.apellido, u.telefono, a.rol, IF(a.activo=1,'activo','suspendido'), CURRENT_TIMESTAMP
FROM usuarios u INNER JOIN usuario_accesos a ON a.usuario_id=u.id WHERE a.rol<>'cliente';
-- Propietarios heredados por la bandera admin (sin fila de acceso): conservan su rol efectivo.
INSERT IGNORE INTO colaboradores(usuario_id,email_norm,nombre,apellido,telefono,rol,estado,aceptado_en)
SELECT u.id, LOWER(TRIM(u.email)), u.nombre, u.apellido, u.telefono, 'propietario', 'activo', CURRENT_TIMESTAMP
FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id WHERE u.admin=1 AND a.usuario_id IS NULL;
UPDATE colaboradores SET codigo=CONCAT('COL-',LPAD(id,6,'0')) WHERE codigo IS NULL;

CREATE TABLE colaborador_invitaciones (
 id INT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 estado ENUM('pendiente','aceptada','expirada','revocada') NOT NULL DEFAULT 'pendiente',
 expira_en DATETIME NOT NULL,
 creada_por INT NULL,
 creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 aceptada_en DATETIME NULL,
 revocada_en DATETIME NULL,
 UNIQUE KEY uq_invitacion_token (token_hash),
 -- A lo sumo UNA invitación pendiente por colaborador (el resto de estados no compiten: NULL no choca).
 UNIQUE KEY uq_invitacion_pendiente (colaborador_id, (IF(estado='pendiente',1,NULL))),
 KEY idx_invitacion_colaborador (colaborador_id, estado),
 CONSTRAINT fk_invitacion_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outbox de correos: el evento se escribe en la MISMA transacción que el alta y su clave única impide
-- dos eventos para la misma invitación. La carga con el token va cifrada y se borra tras el envío.
CREATE TABLE correos_salida (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clave_unica VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 tipo VARCHAR(40) CHARACTER SET ascii NOT NULL,
 destinatario_email VARCHAR(120) NOT NULL,
 destinatario_nombre VARCHAR(120) NOT NULL,
 carga_cifrada TEXT NULL,
 estado ENUM('pendiente','enviando','enviado','fallido','cancelado') NOT NULL DEFAULT 'pendiente',
 intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
 proximo_intento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ultimo_error VARCHAR(255) NULL,
 worker_token CHAR(32) NULL,
 lease_expires_at DATETIME NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 enviado_en DATETIME NULL,
 UNIQUE KEY uq_correo_clave (clave_unica),
 KEY idx_correo_cola (estado, proximo_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una ficha operativa por colaborador; las fichas heredadas sin cuenta conservan colaborador_id NULL.
ALTER TABLE profesionales ADD COLUMN colaborador_id INT NULL DEFAULT NULL,
 ADD UNIQUE KEY uq_profesional_colaborador (colaborador_id),
 ADD CONSTRAINT fk_profesional_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id) ON DELETE SET NULL;
UPDATE profesionales p INNER JOIN colaboradores c ON c.usuario_id=p.usuarioId SET p.colaborador_id=c.id WHERE p.usuarioId IS NOT NULL;
