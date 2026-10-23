-- Revierte 066: restaura el control del calendario de 063 (sin la vista de eventos) y retira la vista nueva. No hay datos propios que perder.
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
DROP VIEW IF EXISTS bi_presupuesto_real_eventos;
