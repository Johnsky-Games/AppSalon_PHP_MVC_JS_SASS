-- 022: auditoría de exportaciones CRM, versionado del consentimiento comercial y decisiones sobre posibles
-- homónimos en el alta de clientes hecha por el equipo.
--
-- Todas las tablas nuevas de registro son de SOLO ANEXADO: triggers BEFORE UPDATE / BEFORE DELETE rechazan
-- cualquier modificación (SQLSTATE 45000), también para el propietario y para el usuario de la aplicación.
-- Límites conocidos (ver docs/CRM_CLIENTES.md): TRUNCATE/DROP no activan triggers (se mitiga retirando esos
-- privilegios al usuario de la aplicación) y las bajas en cascada de `usuarios` (derecho de supresión) no los activan.
-- Ningún registro de auditoría guarda contenido exportado, correos, teléfonos ni texto de búsqueda en claro.

-- ---------------------------------------------------------------------------------------------------
-- 1) Versiones del texto de consentimiento (inmutables). Vigente = mayor versión ya en vigor.
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consentimiento_versiones (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 proposito VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 texto TEXT NOT NULL,
 texto_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 vigente_desde DATETIME NULL COMMENT 'UTC. NULL solo en la versión histórica: no se conoce desde cuándo regía',
 es_historica TINYINT(1) NOT NULL DEFAULT 0,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_consent_version (proposito, version),
 KEY idx_consent_vigencia (proposito, vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versión 1: texto que ya se mostraba. No se inventa fecha de vigencia (NULL) ni se atribuye a nadie.
INSERT INTO consentimiento_versiones (proposito, version, texto, texto_hash, vigente_desde, es_historica, creado_en)
SELECT 'marketing_email', 1, 'Acepto recibir promociones por correo (opcional)',
       SHA2('Acepto recibir promociones por correo (opcional)', 256), NULL, 1, UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM consentimiento_versiones WHERE proposito = 'marketing_email' AND version = 1);

DROP TRIGGER IF EXISTS trg_consent_versiones_no_update;
CREATE TRIGGER trg_consent_versiones_no_update BEFORE UPDATE ON consentimiento_versiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consentimiento_versiones es de solo anexado: publica una versión nueva';
DROP TRIGGER IF EXISTS trg_consent_versiones_no_delete;
CREATE TRIGGER trg_consent_versiones_no_delete BEFORE DELETE ON consentimiento_versiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consentimiento_versiones es de solo anexado';

-- ---------------------------------------------------------------------------------------------------
-- 2) Historial de decisiones de consentimiento (aceptar / retirar). Solo anexado.
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consentimientos_historial (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT NOT NULL,
 proposito VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 valor TINYINT(1) NOT NULL COMMENT '1 = aceptó, 0 = retiró',
 version_id INT UNSIGNED NOT NULL COMMENT 'versión del texto que la persona vio al decidir',
 version_informada TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = la versión llegó en el formulario; 0 = la asignó el servidor (migración o cliente sin versión)',
 origen VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'perfil | bienestar | migracion',
 canal VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'portal_cliente | portal_equipo | migracion',
 actor_id INT NULL COMMENT 'quien ejecutó la acción (la propia persona o quien la retiró en su nombre)',
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'HMAC de la IP truncada; nunca la IP en claro',
 ocurrido_en DATETIME NULL COMMENT 'UTC; en filas migradas, la fecha original guardada (puede ser NULL = desconocida)',
 registrado_en DATETIME NOT NULL COMMENT 'UTC; cuándo se escribió esta fila',
 KEY idx_consent_hist_usuario (usuario_id, id),
 KEY idx_consent_hist_version (version_id),
 CONSTRAINT fk_consent_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
 CONSTRAINT fk_consent_hist_version FOREIGN KEY (version_id) REFERENCES consentimiento_versiones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_consent_hist_no_update;
CREATE TRIGGER trg_consent_hist_no_update BEFORE UPDATE ON consentimientos_historial FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consentimientos_historial es de solo anexado';
DROP TRIGGER IF EXISTS trg_consent_hist_no_delete;
CREATE TRIGGER trg_consent_hist_no_delete BEFORE DELETE ON consentimientos_historial FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consentimientos_historial es de solo anexado';

-- Versión asociada al consentimiento VIGENTE de cada persona (estado actual; el historial es la evidencia).
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles_usuario' AND COLUMN_NAME = 'consentimiento_marketing_version_id') = 0,
 'ALTER TABLE perfiles_usuario ADD COLUMN consentimiento_marketing_version_id INT UNSIGNED NULL AFTER consentimiento_marketing_at, ADD CONSTRAINT fk_perfil_consent_version FOREIGN KEY (consentimiento_marketing_version_id) REFERENCES consentimiento_versiones(id)',
 'DO 0');
PREPARE st_perfil_version FROM @sql;
EXECUTE st_perfil_version;
DEALLOCATE PREPARE st_perfil_version;

-- Retro-llenado: los consentimientos vigentes (marca y fecha) pasan a la versión 1 «histórica / sin versión
-- registrada». Se conserva la fecha guardada tal cual; no se inventa ninguna. Quien nunca aceptó no recibe fila.
UPDATE perfiles_usuario p
JOIN consentimiento_versiones v ON v.proposito = 'marketing_email' AND v.version = 1
SET p.consentimiento_marketing_version_id = v.id
WHERE p.marketing_email = 1 AND p.consentimiento_marketing_at IS NOT NULL AND p.consentimiento_marketing_version_id IS NULL;

INSERT INTO consentimientos_historial (usuario_id, proposito, valor, version_id, version_informada, origen, canal, actor_id, ip_hash, ocurrido_en, registrado_en)
SELECT p.usuarioId, 'marketing_email', 1, v.id, 0, 'migracion', 'migracion', NULL, NULL, p.consentimiento_marketing_at, UTC_TIMESTAMP()
FROM perfiles_usuario p
JOIN consentimiento_versiones v ON v.proposito = 'marketing_email' AND v.version = 1
WHERE p.marketing_email = 1 AND p.consentimiento_marketing_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM consentimientos_historial h WHERE h.usuario_id = p.usuarioId AND h.origen = 'migracion');

-- ---------------------------------------------------------------------------------------------------
-- 3) Auditoría de exportaciones del CRM. Solo anexado, sin contenido exportado ni datos personales.
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_exportaciones_auditoria (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ocurrido_en DATETIME NOT NULL COMMENT 'UTC',
 actor_usuario_id INT NULL COMMENT 'sin FK a propósito: la auditoría sobrevive a cualquier cambio de cuentas',
 actor_rol VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
 formato VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'csv',
 tipo VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'clientes | comercial | invalido',
 resultado ENUM('exitosa','denegada','fallida') NOT NULL,
 motivo VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
 filtros_json JSON NULL COMMENT 'filtros normalizados; el texto de búsqueda no se guarda (solo longitud y huella)',
 filtros_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'HMAC de todos los filtros, incluida la búsqueda',
 filas INT UNSIGNED NULL,
 columnas_n SMALLINT UNSIGNED NULL,
 columnas_lista JSON NULL,
 truncado TINYINT(1) NOT NULL DEFAULT 0,
 consentimiento_version_id INT UNSIGNED NULL COMMENT 'versión de consentimiento vigente al exportar',
 consentimiento_politica VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'solo_version_vigente | informativo',
 contenido_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SHA-256 del archivo entregado: permite comprobar un archivo sin guardarlo',
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'HMAC de la IP truncada; nunca la IP en claro',
 KEY idx_crm_exp_fecha (ocurrido_en, id),
 KEY idx_crm_exp_actor (actor_usuario_id, id),
 KEY idx_crm_exp_resultado (resultado, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_crm_exp_no_update;
CREATE TRIGGER trg_crm_exp_no_update BEFORE UPDATE ON crm_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'crm_exportaciones_auditoria es de solo anexado';
DROP TRIGGER IF EXISTS trg_crm_exp_no_delete;
CREATE TRIGGER trg_crm_exp_no_delete BEFORE DELETE ON crm_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'crm_exportaciones_auditoria es de solo anexado';

-- ---------------------------------------------------------------------------------------------------
-- 4) Decisiones del operador ante posibles homónimos en el alta de clientes. Solo anexado.
--    Tabla propia (no la de exportaciones): su forma es distinta y se consulta por otro motivo.
-- ---------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS altas_homonimos_decisiones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ocurrido_en DATETIME NOT NULL COMMENT 'UTC',
 actor_usuario_id INT NOT NULL,
 actor_rol VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
 decision ENUM('continuo','cancelo') NOT NULL,
 coincidencias_ids JSON NOT NULL COMMENT 'ids de usuarios coincidentes tal como el servidor los recalculó',
 n_coincidencias SMALLINT UNSIGNED NOT NULL,
 criterios VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SHA-256 del token de confirmación aceptado',
 usuario_creado_id INT NULL,
 clave_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 de la clave de operación del formulario',
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 UNIQUE KEY uq_homonimo_decision (actor_usuario_id, clave_hash, decision),
 KEY idx_homonimo_fecha (ocurrido_en, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_homonimo_no_update;
CREATE TRIGGER trg_homonimo_no_update BEFORE UPDATE ON altas_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'altas_homonimos_decisiones es de solo anexado';
DROP TRIGGER IF EXISTS trg_homonimo_no_delete;
CREATE TRIGGER trg_homonimo_no_delete BEFORE DELETE ON altas_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'altas_homonimos_decisiones es de solo anexado';
