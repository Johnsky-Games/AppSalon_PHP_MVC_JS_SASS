-- Reversión SEGURA de la 016 (DEF-16): antes de eliminar `clientes` copia TODAS sus filas (estado, motivo de suspensión, origen y
-- fecha de alta) a `clientes_resguardo_016`, tabla persistente que este rollback NO elimina. Al reaplicar la 016 las membresías y,
-- sobre todo, las suspensiones explícitas se restauran: una suspensión no se reactiva por revertir y reaplicar.
--
-- Mientras la 016 esté revertida la aplicación vigente NO tiene dónde leer la suspensión: este rollback sirve para reaplicar la
-- migración, no para ejecutar código anterior a la 016 (para eso, restaura un respaldo). Procedimiento y bloqueos en
-- docs/GUIA_ACTUALIZACION_IDENTIDADES.md §7; `php database/migrador.php down` se niega a ejecutarlo sin
-- `--aceptar-perdida=016_clientes_membresia` y un respaldo verificable. No lo ejecutes a mano sobre datos reales.
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

INSERT INTO clientes_resguardo_016 (usuario_id, estado, origen, suspendido_motivo, creado_en, resguardado_en, restaurado_en)
SELECT usuario_id, estado, origen, suspendido_motivo, creado_en, UTC_TIMESTAMP(), NULL FROM clientes
ON DUPLICATE KEY UPDATE estado=VALUES(estado), origen=VALUES(origen), suspendido_motivo=VALUES(suspendido_motivo),
    creado_en=VALUES(creado_en), resguardado_en=VALUES(resguardado_en), restaurado_en=NULL;

DROP TABLE IF EXISTS clientes;
