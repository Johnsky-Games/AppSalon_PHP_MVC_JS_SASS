-- ============================================================================
-- Migración 004: Reservas con Profesional, Ocupación Real y Snapshot Histórico
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-4b-reservas-concurrencia
-- ============================================================================

-- 1. Ampliar tabla `citas` para almacenar profesional asignado, intervalo [hora_inicio, hora_fin)
-- y duración total calculada al momento de reservar.
-- Nota de diseño: Las columnas se definen como NULL DEFAULT NULL para conservar
-- intactas las citas históricas previas sin inventar asignaciones de profesional
-- ni intervalos no registrados originalmente.
ALTER TABLE citas
    ADD COLUMN hora_inicio TIME NULL DEFAULT NULL AFTER hora,
    ADD COLUMN hora_fin TIME NULL DEFAULT NULL AFTER hora_inicio,
    ADD COLUMN duracion_total_minutos INT NULL DEFAULT NULL AFTER hora_fin,
    ADD COLUMN profesionalId INT NULL DEFAULT NULL AFTER usuarioId,
    ADD INDEX idx_citas_prof_fecha_intervalo (profesionalId, fecha, hora_inicio, hora_fin),
    ADD CONSTRAINT fk_citas_profesional
        FOREIGN KEY (profesionalId) REFERENCES profesionales (id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- 2. Ampliar tabla `citasservicios` para conservar el snapshot histórico de cada
-- servicio (nombre, precio y duración en minutos) capturado al momento de reservar.
-- Esto garantiza inmutabilidad histórica ante cambios posteriores o eliminación del servicio en el catálogo.
ALTER TABLE citasservicios
    ADD COLUMN nombre_servicio VARCHAR(60) NULL DEFAULT NULL AFTER servicioId,
    ADD COLUMN precio_servicio DECIMAL(6,2) NULL DEFAULT NULL AFTER nombre_servicio,
    ADD COLUMN duracion_minutos INT NULL DEFAULT NULL AFTER precio_servicio;
