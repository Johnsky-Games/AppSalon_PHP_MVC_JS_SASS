-- Modelo de lectura para BI, tercer corte: atenciones (citas y servicios) y ocupación de profesionales.
-- Definiciones y reconciliaciones en docs/BI_ATENCIONES_OCUPACION.md. Mismas reglas que 057/058: solo lectura (ninguna vista admite UPDATE ni DELETE;
-- la prueba lo verifica), SQL SECURITY DEFINER (el usuario de BI lee solo estas vistas) y sin datos personales (sin cliente, contacto, actores ni notas).
-- Los minutos reservados y los minutos disponibles siguen las mismas reglas que Services\ReporteService, contra el que la prueba reconcilia.

-- Grano: una fila por cita. Las canceladas se conservan (con es_cancelada = 1) para medir cancelaciones; no suman minutos ocupados.
-- minutos_reservados: duración total guardada en la cita; si falta, hora_fin - hora_inicio; si falta, suma de los servicios (instantánea histórica).
-- fecha_servicio_realizado: solo citas atendidas; la fecha efectiva de la regularización si existe, y si no la programada.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_atenciones AS
SELECT c.id AS cita_id, c.fecha AS fecha_programada, c.profesionalId AS profesional_id, c.estado,
 c.estado = 'cancelada' AS es_cancelada, c.estado = 'no_asistio' AS es_no_asistencia, c.estado = 'completada' AS es_atendida,
 c.estado IN ('confirmada', 'en_servicio') AS es_pendiente_de_atencion,
 IF(c.estado = 'completada', COALESCE(DATE(c.atencion_efectiva_at), c.fecha), NULL) AS fecha_servicio_realizado,
 c.atencion_efectiva_at IS NOT NULL AS fue_regularizada,
 COALESCE(c.hora_inicio, c.hora) AS hora_programada, c.hora_fin AS hora_fin_programada,
 c.atencion_inicio_at, c.atencion_fin_at, c.ausencia_at,
 CASE WHEN COALESCE(c.duracion_total_minutos, 0) > 0 THEN c.duracion_total_minutos
      WHEN c.hora_inicio IS NOT NULL AND c.hora_fin IS NOT NULL
           AND HOUR(c.hora_fin) * 60 + MINUTE(c.hora_fin) > HOUR(c.hora_inicio) * 60 + MINUTE(c.hora_inicio)
        THEN HOUR(c.hora_fin) * 60 + MINUTE(c.hora_fin) - HOUR(c.hora_inicio) * 60 - MINUTE(c.hora_inicio)
      ELSE COALESCE(sv.minutos, 0) END AS minutos_reservados,
 COALESCE(sv.cantidad, 0) AS cantidad_servicios,
 COALESCE(sv.importe_centavos, 0) AS importe_servicios_centavos
FROM citas c
LEFT JOIN (
  SELECT cs.citaId, COUNT(*) AS cantidad,
         SUM(COALESCE(cs.duracion_minutos, s.duracion_minutos, 0)) AS minutos,
         SUM(ROUND(COALESCE(cs.precio_servicio, s.precio, 0) * 100)) AS importe_centavos
  FROM citasservicios cs
  LEFT JOIN servicios s ON s.id = cs.servicioId
  GROUP BY cs.citaId
) sv ON sv.citaId = c.id;

-- Grano: una fila por servicio de una cita. Precio y duración son los capturados al reservar (si faltan, los del catálogo).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_atencion_servicios AS
SELECT cs.id AS cita_servicio_id, cs.citaId AS cita_id, cs.servicioId AS servicio_id,
 COALESCE(cs.nombre_servicio, s.nombre, 'Servicio eliminado') AS servicio_nombre,
 s.id IS NULL AS servicio_fuera_de_catalogo,
 ROUND(COALESCE(cs.precio_servicio, s.precio, 0) * 100) AS precio_centavos,
 COALESCE(cs.duracion_minutos, s.duracion_minutos, 0) AS duracion_minutos
FROM citasservicios cs
LEFT JOIN servicios s ON s.id = cs.servicioId;

-- Grano: una fila por profesional y fecha, desde la primera cita registrada hasta la última o el día de hoy (hora de Ecuador, UTC-5 sin cambio
-- de hora), lo que sea posterior; hasta 10.000 días. Las citas sin profesional no entran aquí: se leen en bi_atenciones (profesional_id nulo).
-- minutos_disponibles: horario semanal del profesional ese día menos los descansos y bloqueos que se solapan con él, sin restar dos veces
-- el solape entre descansos y bloqueos. Usa el horario VIGENTE (no hay historial de horarios) y vale 0 para profesionales inactivos.
-- La ocupación de un periodo es SUM(minutos_reservados) / SUM(minutos_disponibles) sobre las filas del periodo, nunca un promedio de filas.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_ocupacion_profesional_dia AS
WITH rango AS (
  SELECT MIN(fecha) AS desde, GREATEST(MAX(fecha), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)) AS hasta FROM citas
),
digitos AS (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
),
fechas AS (
  SELECT DATE_ADD(r.desde, INTERVAL d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n DAY) AS fecha
  FROM rango r
  JOIN digitos d0 JOIN digitos d1 JOIN digitos d2 JOIN digitos d3
  WHERE r.desde IS NOT NULL AND d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n <= DATEDIFF(r.hasta, r.desde)
),
dias AS (
  SELECT p.id AS profesional_id, p.activo, f.fecha, WEEKDAY(f.fecha) + 1 AS dia_semana
  FROM profesionales p CROSS JOIN fechas f
),
turnos AS (
  SELECT d.profesional_id, d.fecha, h.id AS turno_id,
         HOUR(h.hora_inicio) * 60 + MINUTE(h.hora_inicio) AS ini, HOUR(h.hora_fin) * 60 + MINUTE(h.hora_fin) AS fin
  FROM dias d
  JOIN horarios_profesionales h ON h.profesionalId = d.profesional_id AND h.dia_semana = d.dia_semana
  WHERE d.activo = 1
    AND HOUR(h.hora_inicio) * 60 + MINUTE(h.hora_inicio) < HOUR(h.hora_fin) * 60 + MINUTE(h.hora_fin)
),
restricciones AS (
  SELECT d.profesional_id, d.fecha,
         HOUR(x.hora_inicio) * 60 + MINUTE(x.hora_inicio) AS ini, HOUR(x.hora_fin) * 60 + MINUTE(x.hora_fin) AS fin
  FROM dias d JOIN descansos_profesionales x ON x.profesionalId = d.profesional_id AND x.dia_semana = d.dia_semana
  UNION ALL
  SELECT d.profesional_id, d.fecha,
         IF(b.hora_inicio IS NULL OR b.hora_fin IS NULL, 0, HOUR(b.hora_inicio) * 60 + MINUTE(b.hora_inicio)),
         IF(b.hora_inicio IS NULL OR b.hora_fin IS NULL, 1440, HOUR(b.hora_fin) * 60 + MINUTE(b.hora_fin))
  FROM dias d JOIN bloqueos_profesionales b ON b.profesionalId = d.profesional_id AND b.fecha_inicio <= d.fecha AND b.fecha_fin >= d.fecha
),
recortes AS (
  SELECT t.profesional_id, t.fecha, t.turno_id, GREATEST(r.ini, t.ini) AS ini, LEAST(r.fin, t.fin) AS fin
  FROM turnos t
  JOIN restricciones r ON r.profesional_id = t.profesional_id AND r.fecha = t.fecha
  WHERE GREATEST(r.ini, t.ini) < LEAST(r.fin, t.fin)
),
cubierto AS (
  SELECT profesional_id, fecha, turno_id,
         GREATEST(0, fin - GREATEST(ini, COALESCE(MAX(fin) OVER (PARTITION BY profesional_id, fecha, turno_id ORDER BY ini, fin
           ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), ini))) AS minutos
  FROM recortes
),
disponible AS (
  SELECT t.profesional_id, t.fecha, SUM(t.fin - t.ini - COALESCE(c.minutos, 0)) AS minutos
  FROM turnos t
  LEFT JOIN (SELECT profesional_id, fecha, turno_id, SUM(minutos) AS minutos FROM cubierto GROUP BY profesional_id, fecha, turno_id) c
    ON c.profesional_id = t.profesional_id AND c.fecha = t.fecha AND c.turno_id = t.turno_id
  GROUP BY t.profesional_id, t.fecha
),
citas_dia AS (
  SELECT profesional_id, fecha_programada AS fecha,
         SUM(IF(es_cancelada, 0, 1)) AS citas_activas, SUM(es_cancelada) AS citas_canceladas,
         SUM(es_atendida) AS citas_atendidas, SUM(es_no_asistencia) AS citas_no_asistidas,
         SUM(IF(es_cancelada, 0, minutos_reservados)) AS minutos_reservados,
         SUM(IF(es_atendida, minutos_reservados, 0)) AS minutos_atendidos,
         SUM(IF(es_no_asistencia, minutos_reservados, 0)) AS minutos_no_asistidos
  FROM bi_atenciones WHERE profesional_id IS NOT NULL GROUP BY profesional_id, fecha_programada
)
SELECT d.profesional_id, d.fecha, d.activo AS profesional_activo, d.dia_semana,
 COALESCE(di.minutos, 0) AS minutos_disponibles,
 COALESCE(cd.minutos_reservados, 0) AS minutos_reservados,
 COALESCE(cd.minutos_atendidos, 0) AS minutos_atendidos,
 COALESCE(cd.minutos_no_asistidos, 0) AS minutos_no_asistidos,
 COALESCE(cd.citas_activas, 0) AS citas_activas, COALESCE(cd.citas_atendidas, 0) AS citas_atendidas,
 COALESCE(cd.citas_no_asistidas, 0) AS citas_no_asistidas, COALESCE(cd.citas_canceladas, 0) AS citas_canceladas
FROM dias d
LEFT JOIN disponible di ON di.profesional_id = d.profesional_id AND di.fecha = d.fecha
LEFT JOIN citas_dia cd ON cd.profesional_id = d.profesional_id AND cd.fecha = d.fecha;
