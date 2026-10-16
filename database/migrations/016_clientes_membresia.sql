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

-- Resguardo persistente de membresías (DEF-16). El rollback de esta migración copia aquí TODAS las filas de `clientes`
-- antes de eliminar la tabla y el rollback NUNCA elimina esta tabla. Al reaplicar, las filas pendientes se restauran
-- (ver más abajo) y se marcan con `restaurado_en`. Está vacía en una instalación que nunca revirtió la 016.
-- Purga manual (solo cuando el operador confirme que ya no hace falta):  DELETE FROM clientes_resguardo_016 WHERE restaurado_en IS NOT NULL;
CREATE TABLE IF NOT EXISTS clientes_resguardo_016 (
 usuario_id INT NOT NULL PRIMARY KEY,
 estado ENUM('activo','suspendido') NOT NULL,
 origen ENUM('registro_publico','migracion','recepcion') NOT NULL,
 suspendido_motivo VARCHAR(160) NULL,
 creado_en DATETIME NOT NULL,
 resguardado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 restaurado_en DATETIME NULL DEFAULT NULL,
 INDEX idx_resguardo_pendiente (restaurado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Restauración tras la retrocarga: (1) recupera membresías que la retrocarga no deriva (p. ej. clientes creados en recepción
-- o cuentas con doble perfil sin citas); (2) reaplica origen y fecha de alta; (3) una suspensión explícita NUNCA se reactiva sola:
-- queda suspendida si el resguardo O la retrocarga la marcan así. Solo se usan filas aún no restauradas.
INSERT IGNORE INTO clientes(usuario_id,estado,origen,suspendido_motivo,creado_en)
SELECT r.usuario_id, r.estado, r.origen, r.suspendido_motivo, r.creado_en
FROM clientes_resguardo_016 r INNER JOIN usuarios u ON u.id=r.usuario_id
WHERE r.restaurado_en IS NULL;

UPDATE clientes c INNER JOIN clientes_resguardo_016 r ON r.usuario_id=c.usuario_id AND r.restaurado_en IS NULL
SET c.estado=IF(r.estado='suspendido' OR c.estado='suspendido','suspendido','activo'),
    c.suspendido_motivo=IF(r.estado='suspendido',r.suspendido_motivo,c.suspendido_motivo),
    c.origen=r.origen,
    c.creado_en=r.creado_en;

UPDATE clientes_resguardo_016 SET restaurado_en=UTC_TIMESTAMP() WHERE restaurado_en IS NULL;
