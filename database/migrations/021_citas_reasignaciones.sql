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
