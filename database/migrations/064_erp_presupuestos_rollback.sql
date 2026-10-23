-- Revierte 064: devuelve la dimensión de fecha de 062 (sin años presupuestados), quita las vistas y luego las tablas (hijas primero).
-- La guarda impide hacerlo si ya hay presupuestos con cifras.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_dim_fecha AS
WITH fuentes AS (
  SELECT fecha_aplicacion AS fecha FROM bi_aplicaciones_saldo
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_aplicaciones_saldo
  UNION ALL SELECT fecha_programada AS fecha FROM bi_atenciones
  UNION ALL SELECT fecha_servicio_realizado AS fecha FROM bi_atenciones
  UNION ALL SELECT DATE(atencion_inicio_at) AS fecha FROM bi_atenciones
  UNION ALL SELECT DATE(atencion_fin_at) AS fecha FROM bi_atenciones
  UNION ALL SELECT DATE(ausencia_at) AS fecha FROM bi_atenciones
  UNION ALL SELECT fecha AS fecha FROM bi_cxc_movimientos
  UNION ALL SELECT fecha_movimiento_devolucion AS fecha FROM bi_devoluciones_dinero
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_devoluciones_dinero
  UNION ALL SELECT fecha_cobro_original AS fecha FROM bi_devoluciones_dinero
  UNION ALL SELECT fecha_devolucion AS fecha FROM bi_devoluciones_venta
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_devoluciones_venta
  UNION ALL SELECT fecha_movimiento AS fecha FROM bi_flujo_dinero
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_flujo_dinero
  UNION ALL SELECT fecha_cobro_original AS fecha FROM bi_flujo_dinero
  UNION ALL SELECT fecha AS fecha FROM bi_inventario_movimientos
  UNION ALL SELECT fecha AS fecha FROM bi_libro_lineas
  UNION ALL SELECT fecha_nota AS fecha FROM bi_notas_credito
  UNION ALL SELECT fecha AS fecha FROM bi_rh_asistencia
  UNION ALL SELECT DATE(entrada) AS fecha FROM bi_rh_asistencia
  UNION ALL SELECT DATE(salida) AS fecha FROM bi_rh_asistencia
  UNION ALL SELECT desde AS fecha FROM bi_rh_ausencias
  UNION ALL SELECT hasta AS fecha FROM bi_rh_ausencias
  UNION ALL SELECT fecha AS fecha FROM bi_rh_comisiones_liquidaciones
  UNION ALL SELECT fecha AS fecha FROM bi_rh_comisiones_movimientos
  UNION ALL SELECT fecha_venta AS fecha FROM bi_venta_lineas
),
rango AS (
  SELECT LEAST(COALESCE(MIN(fecha), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)) AS desde,
         GREATEST(COALESCE(MAX(fecha), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)) AS hasta FROM fuentes
),
digitos AS (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
),
dias AS (
  SELECT DATE_ADD(r.desde, INTERVAL d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n + 10000 * d4.n DAY) AS fecha
  FROM rango r
  JOIN digitos d0 JOIN digitos d1 JOIN digitos d2 JOIN digitos d3 JOIN digitos d4
  WHERE d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n + 10000 * d4.n <= DATEDIFF(r.hasta, r.desde)
)
SELECT fecha, YEAR(fecha) AS anio, QUARTER(fecha) AS trimestre, MONTH(fecha) AS mes, YEAR(fecha) * 100 + MONTH(fecha) AS anio_mes,
 ELT(MONTH(fecha), 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre') AS nombre_mes,
 DAY(fecha) AS dia_del_mes, WEEKDAY(fecha) + 1 AS dia_semana,
 ELT(WEEKDAY(fecha) + 1, 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo') AS nombre_dia,
 WEEKDAY(fecha) >= 5 AS es_fin_de_semana,
 YEARWEEK(fecha, 3) DIV 100 AS anio_iso, WEEK(fecha, 3) AS semana_iso
FROM dias;
DROP VIEW IF EXISTS bi_presupuesto_cobros_mes;
DROP VIEW IF EXISTS bi_presupuesto_anual;
DROP VIEW IF EXISTS bi_presupuesto_vs_real;
DROP VIEW IF EXISTS bi_presupuesto_lineas;
DROP VIEW IF EXISTS bi_presupuesto_real_mes;
DROP TABLE IF EXISTS erp_presupuesto_cargas;
DROP TABLE IF EXISTS erp_presupuesto_cambios;
DROP TABLE IF EXISTS erp_presupuesto_lineas;
DROP TABLE IF EXISTS erp_presupuesto_versiones;
DROP TABLE IF EXISTS erp_presupuestos;
