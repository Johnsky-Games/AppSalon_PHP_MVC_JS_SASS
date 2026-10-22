-- Modelo de lectura para BI, quinto corte: asistencia y comisiones del personal (acceso LABORAL, separado del comercial).
-- Definiciones, reconciliaciones y límites en docs/BI_RH_ASISTENCIA_COMISIONES.md. Mismas reglas que 057-060: solo lectura (la prueba verifica que ninguna
-- vista admite UPDATE ni DELETE), SQL SECURITY DEFINER y una fila por hecho. Todas las vistas de este corte llevan el prefijo bi_rh_: son datos laborales
-- (quién trabajó cuánto, cuánto ganó en comisiones) y se conceden aparte del grupo comercial (bi_venta_*, bi_libro_*, bi_flujo_*, ...).
-- Se identifica a la persona solo por colaborador_id (clave interna): ningún nombre, documento, contacto, sueldo, motivo ni nota libre se expone.
-- Las comisiones viven en dos libros (el heredado por profesional, anterior al PDV, y el ERP por colaborador); el ERP ya descuenta lo devengado en el heredado
-- (ver Services\ErpComisionService::sincronizar), así que sumar ambos no duplica. Cada liquidación entra por un solo canal (caja, obligación o nómina).

-- Las tres vistas de una sola tabla llevan SELECT DISTINCT: sin él MySQL las trata como actualizables y un UPDATE o DELETE llegaría a la tabla de origen
-- (la prueba lo comprobó con un DELETE real); no colapsa filas porque cada una lleva su id único.
-- Grano: un registro de asistencia (las anuladas se conservan, marcadas, y no suman minutos vigentes).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_rh_asistencia AS
SELECT DISTINCT a.id AS asistencia_id, a.colaborador_id, DATE(a.entrada) AS fecha, a.entrada, a.salida, a.descanso_minutos,
 a.anulado_en IS NOT NULL AS anulada,
 GREATEST(0, TIMESTAMPDIFF(MINUTE, a.entrada, a.salida) - a.descanso_minutos) AS minutos_trabajados,
 IF(a.anulado_en IS NULL, GREATEST(0, TIMESTAMPDIFF(MINUTE, a.entrada, a.salida) - a.descanso_minutos), 0) AS minutos_trabajados_vigentes
FROM erp_asistencia a;

-- Grano: una jornada semanal programada (colaborador y día ISO).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_rh_jornadas AS
SELECT DISTINCT j.id AS jornada_id, j.colaborador_id, j.dia_semana, j.inicio, j.fin,
 HOUR(j.fin) * 60 + MINUTE(j.fin) - HOUR(j.inicio) * 60 - MINUTE(j.inicio) AS minutos_programados
FROM erp_jornadas j;

-- Grano: una ausencia (rango de fechas); dias_calendario cuenta ambos extremos.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_rh_ausencias AS
SELECT DISTINCT a.id AS ausencia_id, a.colaborador_id, a.desde, a.hasta, a.tipo, a.estado,
 DATEDIFF(a.hasta, a.desde) + 1 AS dias_calendario, a.estado = 'aprobada' AS es_aprobada
FROM erp_ausencias a;

-- Grano: un movimiento de comisión devengada (positivo) o su ajuste (negativo), de cualquiera de los dos libros.
-- sistema 'erp': por línea de venta, con venta_id; 'anticipo_previo': libro heredado, con pago_id (los devengos anulados no entran).
-- liquidada = ya incluida en una liquidación (liquidacion_sistema y liquidacion_id la identifican); colaborador_id es nulo si el profesional heredado no tiene ficha.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_rh_comisiones_movimientos AS
SELECT 'erp' AS sistema, m.id AS movimiento_id, m.colaborador_id, DATE(m.creado_en) AS fecha, m.venta_id, CAST(NULL AS SIGNED) AS pago_id,
 m.monto_centavos, m.estado = 'asignada' AS liquidada, m.liquidacion_id
FROM erp_comision_movimientos m
UNION ALL
SELECT 'anticipo_previo', m.id, p.colaborador_id, DATE(m.registrado_en), NULL, m.pago_id,
 m.monto_centavos, m.estado = 'liquidada', m.liquidacion_id
FROM comisiones_movimientos m
LEFT JOIN profesionales p ON p.id = m.profesional_id
WHERE m.estado <> 'anulada';

-- Grano: una liquidación de comisiones, por exactamente un canal: 'caja_directa' (libro heredado, pagada al instante desde caja),
-- 'obligacion_directa' (ERP, queda como obligación por pagar) o 'nomina' (ERP, se paga dentro de la corrida de nómina).
-- pagado_centavos: lo ya pagado por su propio canal (caja: todo; obligación: lo pagado de la obligación; nómina: se paga con la corrida, no aquí).
-- en_corrida_activa y corrida_id: solo canal nómina, con el vínculo vigente (una comisión no entra en dos corridas activas ni pagada también directo).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_rh_comisiones_liquidaciones AS
SELECT 'erp' AS sistema, l.id AS liquidacion_id, l.colaborador_id, DATE(l.creado_en) AS fecha,
 IF(l.via = 'nomina', 'nomina', 'obligacion_directa') AS canal, l.monto_centavos,
 IF(l.via = 'directa', COALESCE(o.pagado_centavos, 0), 0) AS pagado_centavos,
 nc.id IS NOT NULL AS en_corrida_activa, ln.corrida_id
FROM erp_comision_liquidaciones l
LEFT JOIN erp_obligaciones o ON o.id = l.obligacion_id
LEFT JOIN erp_nomina_comisiones nc ON nc.liquidacion_id = l.id AND nc.activa = 1
LEFT JOIN erp_nomina_lineas ln ON ln.id = nc.linea_id
UNION ALL
SELECT 'anticipo_previo', l.id, p.colaborador_id, DATE(l.registrado_en), 'caja_directa', l.monto_centavos, l.monto_centavos, 0, NULL
FROM comisiones_liquidaciones l
LEFT JOIN profesionales p ON p.id = l.profesional_id;
