-- Membresía de cliente: la persona cliente es un dominio distinto de la cuenta de acceso y del equipo.
-- Una cuenta puede ser cliente, colaborador o ambas; cada membresía tiene su propio estado.
CREATE TABLE IF NOT EXISTS clientes (
 usuario_id INT NOT NULL PRIMARY KEY,
 estado ENUM('activo','suspendido') NOT NULL DEFAULT 'activo',
 origen ENUM('registro_publico','migracion','recepcion') NOT NULL DEFAULT 'registro_publico',
 suspendido_motivo VARCHAR(160) NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_clientes_estado_alta (estado, creado_en),
 CONSTRAINT fk_cliente_usuario FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Retrocarga controlada: sólo materializa membresías cliente; jamás activa una cuenta desactivada
-- (se conserva su estado) ni cambia roles, contraseñas o privilegios.
INSERT IGNORE INTO clientes(usuario_id,estado,origen,creado_en)
SELECT u.id, IF(COALESCE(a.activo,1)=1,'activo','suspendido'), 'migracion', CURRENT_TIMESTAMP
FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id
WHERE (a.usuario_id IS NULL AND u.admin=0) OR a.rol='cliente';

-- Quien ya reservó como cliente conserva su membresía aunque hoy tenga rol laboral.
INSERT IGNORE INTO clientes(usuario_id,estado,origen,creado_en)
SELECT DISTINCT c.usuarioId, 'activo', 'migracion', CURRENT_TIMESTAMP
FROM citas c INNER JOIN usuarios u ON u.id=c.usuarioId;
