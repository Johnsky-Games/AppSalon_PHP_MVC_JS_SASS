-- Modelo de lectura para BI, control del calendario (063): detecta fechas de hechos que NO tienen su día en bi_dim_fecha.
-- bi_dim_fecha genera hasta 100.000 días desde la fecha más antigua de los hechos; un dato absurdo (p. ej. una ausencia en el año 2999) hace que el
-- rango supere ese tope y los días posteriores se pierdan SIN error. Ampliar el tope no elimina el riesgo: estas vistas lo hacen visible.
-- Una fila por (vista de hechos, columna de fecha) vigilada, SIEMPRE (con ceros si no hay datos): sin filas no se confunde «sin datos» con «todo bien».
-- `estado` = 'ok' si todas las fechas tienen su día; 'sin_correspondencia' si alguna no (filas_sin_dia > 0).
-- Se separan en dos vistas para respetar la separación de acceso: bi_control_calendario (comercial) y bi_rh_control_calendario (laboral; agregados sin personas).
-- Una vista de hechos nueva con fechas debe añadirse a bi_dim_fecha Y a estas listas: la prueba compara la lista con information_schema y falla si falta alguna.

CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_control_calendario AS
WITH hechos AS (
  SELECT 'bi_aplicaciones_saldo' AS vista, 'fecha_aplicacion' AS columna, fecha_aplicacion AS fecha FROM bi_aplicaciones_saldo
  UNION ALL
  SELECT 'bi_aplicaciones_saldo' AS vista, 'fecha_venta_original' AS columna, fecha_venta_original AS fecha FROM bi_aplicaciones_saldo
  UNION ALL
  SELECT 'bi_atenciones' AS vista, 'fecha_programada' AS columna, fecha_programada AS fecha FROM bi_atenciones
  UNION ALL
  SELECT 'bi_atenciones' AS vista, 'fecha_servicio_realizado' AS columna, fecha_servicio_realizado AS fecha FROM bi_atenciones
  UNION ALL
  SELECT 'bi_atenciones' AS vista, 'atencion_inicio_at' AS columna, DATE(atencion_inicio_at) AS fecha FROM bi_atenciones
  UNION ALL
  SELECT 'bi_atenciones' AS vista, 'atencion_fin_at' AS columna, DATE(atencion_fin_at) AS fecha FROM bi_atenciones
  UNION ALL
  SELECT 'bi_atenciones' AS vista, 'ausencia_at' AS columna, DATE(ausencia_at) AS fecha FROM bi_atenciones
  UNION ALL
  SELECT 'bi_cxc_movimientos' AS vista, 'fecha' AS columna, fecha AS fecha FROM bi_cxc_movimientos
  UNION ALL
  SELECT 'bi_devoluciones_dinero' AS vista, 'fecha_movimiento_devolucion' AS columna, fecha_movimiento_devolucion AS fecha FROM bi_devoluciones_dinero
  UNION ALL
  SELECT 'bi_devoluciones_dinero' AS vista, 'fecha_venta_original' AS columna, fecha_venta_original AS fecha FROM bi_devoluciones_dinero
  UNION ALL
  SELECT 'bi_devoluciones_dinero' AS vista, 'fecha_cobro_original' AS columna, fecha_cobro_original AS fecha FROM bi_devoluciones_dinero
  UNION ALL
  SELECT 'bi_devoluciones_venta' AS vista, 'fecha_devolucion' AS columna, fecha_devolucion AS fecha FROM bi_devoluciones_venta
  UNION ALL
  SELECT 'bi_devoluciones_venta' AS vista, 'fecha_venta_original' AS columna, fecha_venta_original AS fecha FROM bi_devoluciones_venta
  UNION ALL
  SELECT 'bi_flujo_dinero' AS vista, 'fecha_movimiento' AS columna, fecha_movimiento AS fecha FROM bi_flujo_dinero
  UNION ALL
  SELECT 'bi_flujo_dinero' AS vista, 'fecha_venta_original' AS columna, fecha_venta_original AS fecha FROM bi_flujo_dinero
  UNION ALL
  SELECT 'bi_flujo_dinero' AS vista, 'fecha_cobro_original' AS columna, fecha_cobro_original AS fecha FROM bi_flujo_dinero
  UNION ALL
  SELECT 'bi_inventario_movimientos' AS vista, 'fecha' AS columna, fecha AS fecha FROM bi_inventario_movimientos
  UNION ALL
  SELECT 'bi_libro_lineas' AS vista, 'fecha' AS columna, fecha AS fecha FROM bi_libro_lineas
  UNION ALL
  SELECT 'bi_notas_credito' AS vista, 'fecha_nota' AS columna, fecha_nota AS fecha FROM bi_notas_credito
  UNION ALL
  SELECT 'bi_saldo_por_cobrar_venta' AS vista, 'fecha_venta' AS columna, fecha_venta AS fecha FROM bi_saldo_por_cobrar_venta
  UNION ALL
  SELECT 'bi_venta_lineas' AS vista, 'fecha_venta' AS columna, fecha_venta AS fecha FROM bi_venta_lineas
),
calendario AS (SELECT fecha FROM bi_dim_fecha),
vigiladas AS (
  SELECT 'bi_aplicaciones_saldo' AS vista, 'fecha_aplicacion' AS columna
  UNION ALL
  SELECT 'bi_aplicaciones_saldo', 'fecha_venta_original'
  UNION ALL
  SELECT 'bi_atenciones', 'fecha_programada'
  UNION ALL
  SELECT 'bi_atenciones', 'fecha_servicio_realizado'
  UNION ALL
  SELECT 'bi_atenciones', 'atencion_inicio_at'
  UNION ALL
  SELECT 'bi_atenciones', 'atencion_fin_at'
  UNION ALL
  SELECT 'bi_atenciones', 'ausencia_at'
  UNION ALL
  SELECT 'bi_cxc_movimientos', 'fecha'
  UNION ALL
  SELECT 'bi_devoluciones_dinero', 'fecha_movimiento_devolucion'
  UNION ALL
  SELECT 'bi_devoluciones_dinero', 'fecha_venta_original'
  UNION ALL
  SELECT 'bi_devoluciones_dinero', 'fecha_cobro_original'
  UNION ALL
  SELECT 'bi_devoluciones_venta', 'fecha_devolucion'
  UNION ALL
  SELECT 'bi_devoluciones_venta', 'fecha_venta_original'
  UNION ALL
  SELECT 'bi_flujo_dinero', 'fecha_movimiento'
  UNION ALL
  SELECT 'bi_flujo_dinero', 'fecha_venta_original'
  UNION ALL
  SELECT 'bi_flujo_dinero', 'fecha_cobro_original'
  UNION ALL
  SELECT 'bi_inventario_movimientos', 'fecha'
  UNION ALL
  SELECT 'bi_libro_lineas', 'fecha'
  UNION ALL
  SELECT 'bi_notas_credito', 'fecha_nota'
  UNION ALL
  SELECT 'bi_saldo_por_cobrar_venta', 'fecha_venta'
  UNION ALL
  SELECT 'bi_venta_lineas', 'fecha_venta'
),
medidas AS (
  SELECT h.vista, h.columna, COUNT(h.fecha) AS filas_con_fecha, MIN(h.fecha) AS fecha_min, MAX(h.fecha) AS fecha_max,
   SUM(h.fecha IS NOT NULL AND c.fecha IS NULL) AS filas_sin_dia
  FROM hechos h
  LEFT JOIN calendario c ON c.fecha = h.fecha
  GROUP BY h.vista, h.columna
)
SELECT k.vista, k.columna, COALESCE(m.filas_con_fecha, 0) AS filas_con_fecha, m.fecha_min, m.fecha_max,
 COALESCE(m.filas_sin_dia, 0) AS filas_sin_dia,
 IF(COALESCE(m.filas_sin_dia, 0) = 0, 'ok', 'sin_correspondencia') AS estado
FROM vigiladas k
LEFT JOIN medidas m ON m.vista = k.vista AND m.columna = k.columna;

CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_rh_control_calendario AS
WITH hechos AS (
  SELECT 'bi_rh_asistencia' AS vista, 'fecha' AS columna, fecha AS fecha FROM bi_rh_asistencia
  UNION ALL
  SELECT 'bi_rh_asistencia' AS vista, 'entrada' AS columna, DATE(entrada) AS fecha FROM bi_rh_asistencia
  UNION ALL
  SELECT 'bi_rh_asistencia' AS vista, 'salida' AS columna, DATE(salida) AS fecha FROM bi_rh_asistencia
  UNION ALL
  SELECT 'bi_rh_ausencias' AS vista, 'desde' AS columna, desde AS fecha FROM bi_rh_ausencias
  UNION ALL
  SELECT 'bi_rh_ausencias' AS vista, 'hasta' AS columna, hasta AS fecha FROM bi_rh_ausencias
  UNION ALL
  SELECT 'bi_rh_comisiones_liquidaciones' AS vista, 'fecha' AS columna, fecha AS fecha FROM bi_rh_comisiones_liquidaciones
  UNION ALL
  SELECT 'bi_rh_comisiones_movimientos' AS vista, 'fecha' AS columna, fecha AS fecha FROM bi_rh_comisiones_movimientos
),
calendario AS (SELECT fecha FROM bi_dim_fecha),
vigiladas AS (
  SELECT 'bi_rh_asistencia' AS vista, 'fecha' AS columna
  UNION ALL
  SELECT 'bi_rh_asistencia', 'entrada'
  UNION ALL
  SELECT 'bi_rh_asistencia', 'salida'
  UNION ALL
  SELECT 'bi_rh_ausencias', 'desde'
  UNION ALL
  SELECT 'bi_rh_ausencias', 'hasta'
  UNION ALL
  SELECT 'bi_rh_comisiones_liquidaciones', 'fecha'
  UNION ALL
  SELECT 'bi_rh_comisiones_movimientos', 'fecha'
),
medidas AS (
  SELECT h.vista, h.columna, COUNT(h.fecha) AS filas_con_fecha, MIN(h.fecha) AS fecha_min, MAX(h.fecha) AS fecha_max,
   SUM(h.fecha IS NOT NULL AND c.fecha IS NULL) AS filas_sin_dia
  FROM hechos h
  LEFT JOIN calendario c ON c.fecha = h.fecha
  GROUP BY h.vista, h.columna
)
SELECT k.vista, k.columna, COALESCE(m.filas_con_fecha, 0) AS filas_con_fecha, m.fecha_min, m.fecha_max,
 COALESCE(m.filas_sin_dia, 0) AS filas_sin_dia,
 IF(COALESCE(m.filas_sin_dia, 0) = 0, 'ok', 'sin_correspondencia') AS estado
FROM vigiladas k
LEFT JOIN medidas m ON m.vista = k.vista AND m.columna = k.columna;
