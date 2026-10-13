-- 021: auditoría de reasignación asistida de citas futuras (baja/suspensión de un profesional).
-- Cada fila conserva QUIÉN movió/canceló la cita, DE qué profesional a cuál, por qué y cómo estaba la cita
-- (snapshot del horario, servicios y estado de pago). Nunca sustituye ni reescribe los snapshots históricos de
-- citasservicios ni los movimientos de comisión: la cita conserva su historial y solo cambia citas.profesionalId.
CREATE TABLE IF NOT EXISTS citas_reasignaciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 cita_id INT NULL,
 cita_referencia_id INT NOT NULL,
 accion ENUM('reasignada','cancelada') NOT NULL,
 profesional_origen_id INT NULL,
 profesional_origen_nombre VARCHAR(120) NOT NULL,
 profesional_destino_id INT NULL,
 profesional_destino_nombre VARCHAR(120) NULL,
 actor_id INT NULL,
 motivo VARCHAR(255) NOT NULL,
 snapshot_json JSON NOT NULL,
 clave_unica VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_reasignacion_clave (clave_unica),
 KEY idx_reasignacion_cita (cita_referencia_id, creado_en),
 KEY idx_reasignacion_origen (profesional_origen_id, creado_en),
 KEY idx_reasignacion_destino (profesional_destino_id),
 CONSTRAINT fk_reasignacion_cita FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE SET NULL,
 CONSTRAINT fk_reasignacion_origen FOREIGN KEY (profesional_origen_id) REFERENCES profesionales(id) ON DELETE SET NULL,
 CONSTRAINT fk_reasignacion_destino FOREIGN KEY (profesional_destino_id) REFERENCES profesionales(id) ON DELETE SET NULL,
 CONSTRAINT fk_reasignacion_actor FOREIGN KEY (actor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Decisiones del operador ante posibles homónimos al dar de alta un PROFESIONAL (ficha de agenda): /profesionales/crear y
-- la ficha nueva del asistente de invitación. Solo anexado, como altas_homonimos_decisiones (clientes). Los ids son de
-- `profesionales`; no hay claves foráneas a propósito: la auditoría sobrevive aunque la ficha se elimine.
CREATE TABLE IF NOT EXISTS profesionales_homonimos_decisiones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ocurrido_en DATETIME NOT NULL COMMENT 'UTC',
 actor_usuario_id INT NOT NULL,
 actor_rol VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ambito VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'profesional_nuevo | ficha_asistente',
 decision ENUM('continuo','cancelo') NOT NULL,
 coincidencias_ids JSON NOT NULL COMMENT 'ids de profesionales coincidentes tal como el servidor los recalculó',
 n_coincidencias SMALLINT UNSIGNED NOT NULL,
 criterios VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SHA-256 del token de confirmación aceptado',
 profesional_creado_id INT NULL,
 clave_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 de la clave de operación del formulario',
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 UNIQUE KEY uq_prof_homonimo_decision (actor_usuario_id, clave_hash, decision),
 KEY idx_prof_homonimo_fecha (ocurrido_en, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_prof_homonimo_no_update;
CREATE TRIGGER trg_prof_homonimo_no_update BEFORE UPDATE ON profesionales_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'profesionales_homonimos_decisiones es de solo anexado';
DROP TRIGGER IF EXISTS trg_prof_homonimo_no_delete;
CREATE TRIGGER trg_prof_homonimo_no_delete BEFORE DELETE ON profesionales_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'profesionales_homonimos_decisiones es de solo anexado';
