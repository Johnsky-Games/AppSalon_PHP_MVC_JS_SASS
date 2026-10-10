-- ============================================================================
-- Migración 003: Profesionales, Duración de Servicios y Configuración de Horarios
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-3a-profesionales-horarios
-- ============================================================================

-- 1. Duración de servicios en minutos (`duracion_minutos`)
-- Nota de diseño: El valor inicial DEFAULT 30 se asigna a los servicios existentes
-- como un supuesto técnico configurable para compatibilidad de esquema, y NO como
-- un dato confirmado del negocio. Cada servicio puede ajustarse individualmente.
ALTER TABLE servicios
    ADD COLUMN duracion_minutos INT NOT NULL DEFAULT 30 AFTER precio;

-- 2. Catálogo de profesionales (`profesionales`)
-- Se prefiere la desactivación lógica (`activo = 0`) para conservar referencias históricas.
CREATE TABLE IF NOT EXISTS profesionales (
    id INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(120) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_profesionales_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Relación entre profesionales y los servicios que pueden realizar (`profesionales_servicios`)
CREATE TABLE IF NOT EXISTS profesionales_servicios (
    id INT NOT NULL AUTO_INCREMENT,
    profesionalId INT NOT NULL,
    servicioId INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_profesional_servicio (profesionalId, servicioId),
    INDEX idx_prof_serv_servicio (servicioId),
    CONSTRAINT fk_prof_serv_profesional FOREIGN KEY (profesionalId) REFERENCES profesionales (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_prof_serv_servicio FOREIGN KEY (servicioId) REFERENCES servicios (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Horarios semanales por profesional (`horarios_profesionales`)
-- `dia_semana` sigue la convención ISO-8601: 1 = Lunes ... 7 = Domingo.
CREATE TABLE IF NOT EXISTS horarios_profesionales (
    id INT NOT NULL AUTO_INCREMENT,
    profesionalId INT NOT NULL,
    dia_semana TINYINT NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_horarios_prof_dia (profesionalId, dia_semana),
    CONSTRAINT fk_horarios_profesional FOREIGN KEY (profesionalId) REFERENCES profesionales (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Descansos semanales recurrentes por profesional (`descansos_profesionales`)
-- `dia_semana` sigue la convención ISO-8601: 1 = Lunes ... 7 = Domingo.
CREATE TABLE IF NOT EXISTS descansos_profesionales (
    id INT NOT NULL AUTO_INCREMENT,
    profesionalId INT NOT NULL,
    dia_semana TINYINT NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    motivo VARCHAR(120) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_descansos_prof_dia (profesionalId, dia_semana),
    CONSTRAINT fk_descansos_profesional FOREIGN KEY (profesionalId) REFERENCES profesionales (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Bloqueos de agenda por fecha completa o por intervalo (`bloqueos_profesionales`)
-- Fechas evaluadas en zona horaria America/Guayaquil.
-- Si `hora_inicio` y `hora_fin` son NULL, el bloqueo aplica al día o rango de fechas completo.
CREATE TABLE IF NOT EXISTS bloqueos_profesionales (
    id INT NOT NULL AUTO_INCREMENT,
    profesionalId INT NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    hora_inicio TIME NULL DEFAULT NULL,
    hora_fin TIME NULL DEFAULT NULL,
    motivo VARCHAR(160) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_bloqueos_prof_fechas (profesionalId, fecha_inicio, fecha_fin),
    CONSTRAINT fk_bloqueos_profesional FOREIGN KEY (profesionalId) REFERENCES profesionales (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
