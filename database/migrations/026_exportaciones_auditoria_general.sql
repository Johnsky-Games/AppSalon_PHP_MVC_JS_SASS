-- 026: auditoría general de exportaciones tabulares (CSV/XLSX) que NO son del CRM: reportes administrativos, caja,
-- comprobantes y las que se añadan. El CRM conserva su tabla propia (crm_exportaciones_auditoria, migración 022) porque
-- además registra la política de consentimiento aplicada.
--
-- SOLO ANEXADO (como la 022): triggers BEFORE UPDATE / BEFORE DELETE rechazan cualquier modificación (SQLSTATE 45000),
-- también para el propietario y para el usuario de la aplicación. Límites conocidos: TRUNCATE/DROP no activan triggers
-- (se mitiga retirando esos privilegios al usuario de la aplicación).
-- Ninguna fila guarda contenido exportado, correos, teléfonos, nombres, texto de búsqueda en claro ni tokens.

CREATE TABLE IF NOT EXISTS exportaciones_auditoria_general (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ocurrido_en DATETIME NOT NULL COMMENT 'UTC',
 origen VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'módulo y bloque, p. ej. reportes.reservas, caja.pagos, crecimiento.contactos',
 actor_usuario_id INT NULL COMMENT 'sin FK a propósito: la auditoría sobrevive a cualquier cambio de cuentas',
 actor_rol VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
 formato VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'csv' COMMENT 'csv | xlsx | invalido',
 resultado ENUM('exitosa','denegada','fallida') NOT NULL,
 motivo VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
 filtros_json JSON NULL COMMENT 'filtros normalizados sin datos personales (el texto de búsqueda solo deja longitud y huella)',
 filtros_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'HMAC de todos los filtros',
 filas INT UNSIGNED NULL,
 columnas_n SMALLINT UNSIGNED NULL,
 columnas_lista JSON NULL,
 truncado TINYINT(1) NOT NULL DEFAULT 0,
 bytes_entregados BIGINT UNSIGNED NULL,
 contenido_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SHA-256 del archivo entregado: permite comprobar un archivo sin guardarlo',
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'HMAC de la IP truncada; nunca la IP en claro',
 KEY idx_exp_gen_fecha (ocurrido_en, id),
 KEY idx_exp_gen_actor (actor_usuario_id, id),
 KEY idx_exp_gen_origen (origen, id),
 KEY idx_exp_gen_resultado (resultado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_exp_gen_no_update;
CREATE TRIGGER trg_exp_gen_no_update BEFORE UPDATE ON exportaciones_auditoria_general FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exportaciones_auditoria_general es de solo anexado';
DROP TRIGGER IF EXISTS trg_exp_gen_no_delete;
CREATE TRIGGER trg_exp_gen_no_delete BEFORE DELETE ON exportaciones_auditoria_general FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exportaciones_auditoria_general es de solo anexado';
