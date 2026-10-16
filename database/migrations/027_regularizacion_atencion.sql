-- 027: regularización de la atención de citas de días anteriores (decisión del usuario sobre la ronda 5).
--
-- Una cita confirmada cuyo día ya pasó NO se inicia de forma ordinaria: se reprograma (historial en agenda_eventos) o, si el
-- servicio sí ocurrió y faltó registrarlo, la REGULARIZAN el propietario o el gerente. Regularizar no retrodata nada: la fecha de la
-- cita no cambia, y quién regularizó y cuándo lo hizo (momento REAL) se guardan aparte de la fecha/hora EFECTIVA de la atención.
--
--   citas.atencion_efectiva_at : cuándo ocurrió realmente el servicio (la declara quien regulariza; nunca futura)
--   citas.regularizada_por     : quién regularizó      citas.regularizada_at : cuándo lo hizo (momento real del registro)
--   citas_regularizaciones     : solicitudes (profesional) y decisiones (propietario/gerente); historial, nunca se borra una fila.
--                                ON DELETE RESTRICT: una cita con regularización NO se puede eliminar (la auditoría no se pierde ni queda huérfana;
--                                la aplicación responde 409 y la base lo impone aunque otro código lo intente).
--
-- Idempotente (cada paso comprueba el esquema real). Compatible con MySQL 8.0.16+ (CHECK, columnas generadas).

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND COLUMN_NAME = 'atencion_efectiva_at') = 0,
 'ALTER TABLE citas
    ADD COLUMN atencion_efectiva_at DATETIME NULL DEFAULT NULL AFTER ausencia_por,
    ADD COLUMN regularizada_por INT NULL DEFAULT NULL AFTER atencion_efectiva_at,
    ADD COLUMN regularizada_at DATETIME NULL DEFAULT NULL AFTER regularizada_por,
    ADD CONSTRAINT fk_citas_regularizada_por FOREIGN KEY (regularizada_por) REFERENCES usuarios (id) ON DELETE SET NULL',
 'DO 0');
PREPARE st_citas_regularizacion FROM @sql;
EXECUTE st_citas_regularizacion;
DEALLOCATE PREPARE st_citas_regularizacion;

CREATE TABLE IF NOT EXISTS citas_regularizaciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 cita_id INT NOT NULL,
 estado ENUM('solicitada','aprobada','rechazada') NOT NULL,
 solicitada_por INT NULL COMMENT 'profesional que la pidió; NULL si la regularizó directamente el propietario o el gerente',
 solicitada_at DATETIME NULL COMMENT 'hora del negocio, momento real de la solicitud',
 efectiva_propuesta_at DATETIME NULL COMMENT 'cuándo dice el profesional que ocurrió el servicio',
 motivo_solicitud VARCHAR(500) NULL,
 resuelta_por INT NULL COMMENT 'propietario o gerente que aprobó o rechazó',
 resuelta_at DATETIME NULL COMMENT 'hora del negocio, momento real de la decisión',
 atencion_efectiva_at DATETIME NULL COMMENT 'cuándo ocurrió el servicio según la decisión aprobada (nunca posterior a resuelta_at)',
 motivo_resolucion VARCHAR(500) NULL,
 solicitud_activa TINYINT GENERATED ALWAYS AS (IF(estado = 'solicitada', 1, NULL)) STORED,
 aprobada_unica TINYINT GENERATED ALWAYS AS (IF(estado = 'aprobada', 1, NULL)) STORED,
 UNIQUE KEY uq_regul_solicitud_activa (cita_id, solicitud_activa),
 UNIQUE KEY uq_regul_aprobada (cita_id, aprobada_unica),
 INDEX idx_regul_estado (estado, solicitada_at),
 CONSTRAINT fk_regul_cita FOREIGN KEY (cita_id) REFERENCES citas (id) ON DELETE RESTRICT,
 CONSTRAINT fk_regul_solicitada_por FOREIGN KEY (solicitada_por) REFERENCES usuarios (id) ON DELETE SET NULL,
 CONSTRAINT fk_regul_resuelta_por FOREIGN KEY (resuelta_por) REFERENCES usuarios (id) ON DELETE SET NULL,
 CONSTRAINT ck_regul_efectiva_no_futura CHECK (atencion_efectiva_at IS NULL OR resuelta_at IS NULL OR atencion_efectiva_at <= resuelta_at),
 CONSTRAINT ck_regul_propuesta_no_futura CHECK (efectiva_propuesta_at IS NULL OR solicitada_at IS NULL OR efectiva_propuesta_at <= solicitada_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Las citas antiguas sin resolver («pendientes de revisión») se consultan por estado y fecha.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND INDEX_NAME = 'idx_citas_estado_fecha') = 0,
 'ALTER TABLE citas ADD INDEX idx_citas_estado_fecha (estado, fecha)', 'DO 0');
PREPARE st_citas_idx_pendientes FROM @sql;
EXECUTE st_citas_idx_pendientes;
DEALLOCATE PREPARE st_citas_idx_pendientes;
