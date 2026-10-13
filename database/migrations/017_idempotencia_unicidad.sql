-- Idempotencia persistente de altas (cliente, colaborador, profesional, invitación, propietario) y
-- unicidad de la identidad por correo normalizado, resistente a cualquier colación o espacio sobrante.
-- La clave del formulario nunca se guarda en claro (SHA-256) y la huella del contenido es un HMAC con
-- secreto del servidor: no permite adivinar contraseñas ni datos sensibles a partir de la tabla.
CREATE TABLE operaciones_idempotentes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 scope VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 clave_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 recurso_tipo VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 recurso_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 resultado TEXT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expira_en DATETIME NOT NULL,
 UNIQUE KEY uq_idem_operacion (scope, actor, clave_hash),
 KEY idx_idem_expira (expira_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un correo equivalente (mayúsculas, espacios laterales) no puede crear otra identidad.
ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_email_normalizado ((LOWER(TRIM(email))));
