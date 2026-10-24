-- Presupuestos: lo real por eventos con fecha de corte (066). Vista nueva bi_presupuesto_real_eventos y control del calendario ampliado con su fecha.
-- Ver el encabezado de la vista para los dos criterios (estado actual vs por eventos).

-- Presupuestos: lo real POR EVENTOS (066). Complementa bi_presupuesto_real_mes (064/065), que es el comparativo recalculado con el ESTADO ACTUAL.
--
-- DOS CRITERIOS, no intercambiables:
--  · ESTADO ACTUAL (bi_presupuesto_real_mes): una venta anulada deja de contar en el mes en que se confirmó y sus devoluciones dejan de contar en sus meses; es decir,
--    anular hoy reescribe meses pasados. Es lo que ve el comparativo por defecto.
--  · POR EVENTOS (esta vista): cada hecho cuenta en el día en que ocurrió y NO se reescribe. Una venta cuenta (+) el día que se confirmó; cada devolución resta (−) el día
--    en que se registró; una anulación posterior resta (−) el neto que quedaba EN ESE MOMENTO (venta − devoluciones hasta la anulación) el día en que se anuló. Así, con una fecha
--    de corte, el comparativo muestra lo que se sabía a esa fecha. Sumados todos los eventos, el resultado coincide con el estado actual (la venta anulada neta 0): no se duplica nada.
--  · Fecha de la anulación: la del evento 'venta_anulada' de erp_eventos (la venta no guarda fecha de anulación). Si una venta anulada no tiene ese evento, se trata como anulada en el
--    mismo instante de su confirmación (neto 0 desde el inicio, igual que el estado actual) y la fila lo marca en anulaciones_sin_evento.
--  · Las devoluciones registradas DESPUÉS de la anulación no existen (no se permiten) y, si hubiera, se ignoran. Las ventas nunca confirmadas (borrador) no cuentan.
--  · Gasto devengado: neto deudor del libro por la fecha del asiento (un reverso resta en su propia fecha); se excluye el asiento de cierre del ejercicio y su reverso. La fecha es la contable
--    del asiento, no la de su registro: un asiento con fecha anterior registrado después aparece en su fecha contable.
-- Grano: día + tipo + destino. bi_dim_fecha cubre estas fechas y bi_control_calendario las vigila.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_real_eventos AS
WITH base AS (
  SELECT l.id AS linea_id, v.id AS venta_id, v.estado, v.confirmado_en, DATE(v.confirmado_en) AS fecha_venta,
   CASE WHEN l.servicio_id IS NOT NULL THEN 'servicio' WHEN l.producto_id IS NOT NULL THEN 'producto' ELSE 'sin_clasificar' END AS tipo,
   COALESCE(l.servicio_id, l.producto_id, 0) AS objetivo_id,
   (l.total_centavos - l.impuesto_centavos) AS v_base, l.impuesto_centavos AS v_imp, l.descuento_centavos AS v_desc, l.total_centavos AS v_total
  FROM erp_venta_lineas l JOIN erp_ventas v ON v.id = l.venta_id
  WHERE v.confirmado_en IS NOT NULL AND v.estado IN ('confirmada', 'anulada')
), anul AS (
  SELECT entidad_id AS venta_id, MIN(creado_en) AS anulado_en FROM erp_eventos WHERE tipo = 'venta_anulada' AND entidad = 'venta' GROUP BY entidad_id
), lin AS (
  SELECT b.*, CASE WHEN b.estado = 'anulada' THEN COALESCE(a.anulado_en, b.confirmado_en) END AS anulado_ts,
   (b.estado = 'anulada' AND a.anulado_en IS NULL) AS sin_evento
  FROM base b LEFT JOIN anul a ON a.venta_id = b.venta_id
), dev1 AS (
  SELECT d.id, d.linea_id, d.creado_en, d.monto_centavos,
   IF(l.v_total > 0, (SUM(d.monto_centavos) OVER (PARTITION BY d.linea_id ORDER BY d.creado_en, d.id) * l.v_imp * 2 + l.v_total) DIV (2 * l.v_total), 0) AS imp_acum
  FROM erp_devoluciones d JOIN lin l ON l.linea_id = d.linea_id
), dev2 AS (
  SELECT linea_id, creado_en, monto_centavos, imp_acum - LAG(imp_acum, 1, 0) OVER (PARTITION BY linea_id ORDER BY creado_en, id) AS imp_parte FROM dev1
), mov AS (
  SELECT fecha_venta AS fecha, tipo, objetivo_id, v_base AS ventas_base, v_imp AS ventas_imp, v_desc AS descuentos, 0 AS dev_base, 0 AS dev_imp, 0 AS anul_base, 0 AS anul_imp, 0 AS sin_evento
  FROM lin
  UNION ALL
  SELECT DATE(d.creado_en), l.tipo, l.objetivo_id, 0, 0, 0, d.monto_centavos - d.imp_parte, d.imp_parte, 0, 0, 0
  FROM dev2 d JOIN lin l ON l.linea_id = d.linea_id WHERE l.anulado_ts IS NULL OR d.creado_en <= l.anulado_ts
  UNION ALL
  SELECT DATE(l.anulado_ts), l.tipo, l.objetivo_id, 0, 0, 0, 0, 0, l.v_base - COALESCE(r.dev_base, 0), l.v_imp - COALESCE(r.dev_imp, 0), l.sin_evento
  FROM lin l
  LEFT JOIN (SELECT d.linea_id, SUM(d.monto_centavos - d.imp_parte) AS dev_base, SUM(d.imp_parte) AS dev_imp
             FROM dev2 d JOIN lin l2 ON l2.linea_id = d.linea_id WHERE d.creado_en <= l2.anulado_ts GROUP BY d.linea_id) r ON r.linea_id = l.linea_id
  WHERE l.estado = 'anulada'
), ventas AS (
  SELECT fecha, tipo, objetivo_id, SUM(ventas_base) AS ventas_base, SUM(ventas_imp) AS ventas_imp, SUM(descuentos) AS descuentos, SUM(dev_base) AS dev_base, SUM(dev_imp) AS dev_imp,
         SUM(anul_base) AS anul_base, SUM(anul_imp) AS anul_imp, SUM(sin_evento) AS sin_evento
  FROM mov GROUP BY fecha, tipo, objetivo_id
), gastos AS (
  SELECT a.fecha AS fecha, IF(c.id = rc.cuenta_id, 'comisiones', 'gasto') AS tipo, IF(c.id = rc.cuenta_id, 0, c.id) AS objetivo_id, SUM(li.debe_centavos - li.haber_centavos) AS neto
  FROM erp_cont_lineas li JOIN erp_cont_asientos a ON a.id = li.asiento_id JOIN erp_cont_cuentas c ON c.id = li.cuenta_id
  LEFT JOIN erp_cont_roles rc ON rc.rol = 'gasto_comisiones'
  WHERE c.tipo IN ('gasto', 'costo') AND a.tipo <> 'cierre'
    AND NOT EXISTS (SELECT 1 FROM erp_cont_asientos o WHERE o.id = a.reversa_de_id AND o.tipo = 'cierre')
  GROUP BY a.fecha, IF(c.id = rc.cuenta_id, 'comisiones', 'gasto'), IF(c.id = rc.cuenta_id, 0, c.id)
)
SELECT fecha AS fecha_evento, YEAR(fecha) AS anio, MONTH(fecha) AS mes, (YEAR(fecha) * 100 + MONTH(fecha)) AS anio_mes, tipo, objetivo_id,
 ventas_base AS ventas_base_centavos, ventas_imp AS ventas_impuesto_centavos, descuentos AS descuentos_informativo_centavos, (ventas_base + descuentos) AS ventas_bruta_base_centavos,
 dev_base AS devoluciones_base_centavos, dev_imp AS devoluciones_impuesto_centavos, anul_base AS anulaciones_base_centavos, anul_imp AS anulaciones_impuesto_centavos,
 sin_evento AS anulaciones_sin_evento,
 (ventas_base - dev_base - anul_base) AS real_base_centavos, (ventas_imp - dev_imp - anul_imp) AS real_impuesto_centavos,
 (ventas_base - dev_base - anul_base + ventas_imp - dev_imp - anul_imp) AS real_total_centavos
FROM ventas
UNION ALL
SELECT fecha, YEAR(fecha), MONTH(fecha), (YEAR(fecha) * 100 + MONTH(fecha)), tipo, objetivo_id, 0, 0, 0, 0, 0, 0, 0, 0, 0, neto, 0, neto
FROM gastos;

-- El control del calendario (063) vigila también la fecha de la vista nueva: una fecha de hecho sin día en bi_dim_fecha no pasa en silencio.
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
  UNION ALL
  SELECT 'bi_presupuesto_real_eventos' AS vista, 'fecha_evento' AS columna, fecha_evento AS fecha FROM bi_presupuesto_real_eventos
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
  UNION ALL
  SELECT 'bi_presupuesto_real_eventos', 'fecha_evento'
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
