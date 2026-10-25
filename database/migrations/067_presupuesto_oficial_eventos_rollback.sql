-- Revierte 067: devuelve bi_presupuesto_vs_real y bi_presupuesto_anual a su definición de 064 (estado actual) y retira la vista nueva. No hay datos propios que perder.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_vs_real AS
WITH vigente AS (
  SELECT p.id AS presupuesto_id, p.anio, p.escenario, v.id AS version_id, v.numero AS version_numero
  FROM erp_presupuestos p JOIN erp_presupuesto_versiones v ON v.presupuesto_id = p.id AND v.estado = 'aprobada'
), claves AS (
  SELECT g.presupuesto_id, l.mes, l.tipo, l.objetivo_id FROM vigente g JOIN erp_presupuesto_lineas l ON l.version_id = g.version_id
  UNION
  SELECT g.presupuesto_id, r.mes, r.tipo, r.objetivo_id FROM vigente g JOIN bi_presupuesto_real_mes r ON r.anio = g.anio
)
SELECT DISTINCT k.presupuesto_id, g.anio, g.escenario, g.version_id, g.version_numero, k.mes, (g.anio * 100 + k.mes) AS anio_mes, k.tipo, k.objetivo_id,
 IF(k.tipo = 'servicio', k.objetivo_id, NULL) AS servicio_id, IF(k.tipo = 'producto', k.objetivo_id, NULL) AS producto_id, IF(k.tipo = 'gasto', k.objetivo_id, NULL) AS cuenta_id,
 c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre, IF(k.tipo IN ('gasto', 'comisiones'), 'gasto', 'ingreso') AS sentido,
 CASE WHEN l.id IS NULL THEN 'sin_presupuesto' WHEN l.base_centavos + l.impuesto_centavos = 0 THEN 'presupuesto_cero' ELSE 'con_presupuesto' END AS estado_presupuesto,
 l.base_centavos AS presupuesto_base_centavos, l.impuesto_centavos AS presupuesto_impuesto_centavos, (l.base_centavos + l.impuesto_centavos) AS presupuesto_total_centavos,
 COALESCE(r.real_base_centavos, 0) AS real_base_centavos, COALESCE(r.real_impuesto_centavos, 0) AS real_impuesto_centavos, COALESCE(r.real_total_centavos, 0) AS real_total_centavos,
 (COALESCE(r.real_base_centavos, 0) - l.base_centavos) AS desviacion_base_centavos,
 (COALESCE(r.real_total_centavos, 0) - (l.base_centavos + l.impuesto_centavos)) AS desviacion_total_centavos,
 IF(l.base_centavos > 0, ROUND((COALESCE(r.real_base_centavos, 0) - l.base_centavos) * 100 / l.base_centavos, 2), NULL) AS desviacion_base_pct,
 IF(l.base_centavos + l.impuesto_centavos > 0, ROUND((COALESCE(r.real_total_centavos, 0) - (l.base_centavos + l.impuesto_centavos)) * 100 / (l.base_centavos + l.impuesto_centavos), 2), NULL) AS desviacion_total_pct
FROM claves k
JOIN vigente g ON g.presupuesto_id = k.presupuesto_id
LEFT JOIN erp_presupuesto_lineas l ON l.version_id = g.version_id AND l.mes = k.mes AND l.tipo = k.tipo AND l.objetivo_id = k.objetivo_id
LEFT JOIN bi_presupuesto_real_mes r ON r.anio = g.anio AND r.mes = k.mes AND r.tipo = k.tipo AND r.objetivo_id = k.objetivo_id
LEFT JOIN erp_cont_cuentas c ON c.id = IF(k.tipo = 'gasto', k.objetivo_id, NULL);


-- Consolidado anual por destino. El presupuesto suma los meses que lo tienen; la desviación compara SOLO esos meses (real_*_meses_presupuestados), para no
-- mezclar un año completo de real con un presupuesto parcial. meses_con_presupuesto dice cuántos meses lo tienen.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_anual AS
SELECT presupuesto_id, anio, escenario, version_id, version_numero, tipo, objetivo_id, servicio_id, producto_id, cuenta_id, cuenta_codigo, cuenta_nombre, sentido,
 COUNT(presupuesto_base_centavos) AS meses_con_presupuesto,
 SUM(presupuesto_base_centavos) AS presupuesto_base_centavos, SUM(presupuesto_impuesto_centavos) AS presupuesto_impuesto_centavos, SUM(presupuesto_total_centavos) AS presupuesto_total_centavos,
 SUM(real_base_centavos) AS real_base_centavos, SUM(real_total_centavos) AS real_total_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_base_centavos)) AS real_base_meses_presupuestados_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_total_centavos)) AS real_total_meses_presupuestados_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_base_centavos)) - SUM(presupuesto_base_centavos) AS desviacion_base_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_total_centavos)) - SUM(presupuesto_total_centavos) AS desviacion_total_centavos,
 IF(SUM(presupuesto_base_centavos) > 0, ROUND((SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_base_centavos)) - SUM(presupuesto_base_centavos)) * 100 / SUM(presupuesto_base_centavos), 2), NULL) AS desviacion_base_pct,
 IF(SUM(presupuesto_total_centavos) > 0, ROUND((SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_total_centavos)) - SUM(presupuesto_total_centavos)) * 100 / SUM(presupuesto_total_centavos), 2), NULL) AS desviacion_total_pct
FROM bi_presupuesto_vs_real
GROUP BY presupuesto_id, anio, escenario, version_id, version_numero, tipo, objetivo_id, servicio_id, producto_id, cuenta_id, cuenta_codigo, cuenta_nombre, sentido;

DROP VIEW IF EXISTS bi_presupuesto_real_mes_eventos;
