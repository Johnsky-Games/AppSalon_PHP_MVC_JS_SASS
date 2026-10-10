-- ============================================================================
-- Reversión de Migración 004: Reservas con Profesional, Ocupación Real y Snapshot Histórico
-- Repositorio: AppSalon_PHP_MVC_JS_SASS
-- Rama: feature/fase-4b-reservas-concurrencia
-- ============================================================================

ALTER TABLE citasservicios
    DROP COLUMN duracion_minutos,
    DROP COLUMN precio_servicio,
    DROP COLUMN nombre_servicio;

ALTER TABLE citas
    DROP FOREIGN KEY fk_citas_profesional,
    DROP INDEX idx_citas_prof_fecha_intervalo,
    DROP COLUMN profesionalId,
    DROP COLUMN duracion_total_minutos,
    DROP COLUMN hora_fin,
    DROP COLUMN hora_inicio;

DELETE FROM migraciones WHERE migracion = '004_reservas_profesional_ocupacion_historico';
