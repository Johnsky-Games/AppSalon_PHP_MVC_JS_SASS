-- Vuelve a la definición de 064 (sin la columna ventas_bruta_base_centavos). No hay datos propios que perder.
-- LO REAL por año, mes y destino. UNA sola definición, usada por el comparativo de la pantalla y por BI.
--  · Ventas = venta devengada: líneas de ventas confirmadas, por mes de confirmación, NETAS de descuentos (el descuento ya está fuera del total de la línea;
--    descuentos_centavos es solo informativo). Las devoluciones restan en el mes en que se registran (erp_devoluciones.creado_en, fecha propia).
--    La base/impuesto de una devolución se reparte con la proporción de su línea sobre el ACUMULADO devuelto de la línea (varias devoluciones parciales suman exactamente el impuesto de la línea).
--  · Gastos = gasto devengado: neto deudor del libro (asientos, por su fecha) en cuentas de tipo gasto o costo; el reverso de un asiento ya resta.
--    El asiento de cierre del ejercicio (tipo 'cierre') y sus reversos se excluyen: trasladan el resultado, no son gasto del período.
--    La cuenta del rol gasto_comisiones va como tipo 'comisiones' (objetivo 0) y NO como 'gasto': el total de gastos la cuenta una sola vez.
--  · tipo 'sin_clasificar': líneas de venta sin servicio ni producto; existe para que el real de ventas cuadre con el total.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_real_mes AS
WITH dev1 AS (
  -- Impuesto de cada devolución por ACUMULADO de la línea (en orden de registro): cada devolución toma la diferencia del impuesto acumulado redondeado, de modo que
  -- varias devoluciones parciales suman exactamente el impuesto de la línea (con la línea devuelta por completo no queda impuesto residual ni base negativa).
  SELECT d.id, d.linea_id, d.creado_en, d.monto_centavos, l.servicio_id, l.producto_id,
   IF(l.total_centavos > 0, (SUM(d.monto_centavos) OVER (PARTITION BY d.linea_id ORDER BY d.creado_en, d.id) * l.impuesto_centavos * 2 + l.total_centavos) DIV (2 * l.total_centavos), 0) AS impuesto_acumulado
  FROM erp_devoluciones d JOIN erp_venta_lineas l ON l.id = d.linea_id JOIN erp_ventas v ON v.id = l.venta_id WHERE v.estado = 'confirmada'
), dev2 AS (
  SELECT creado_en, monto_centavos, servicio_id, producto_id,
   impuesto_acumulado - LAG(impuesto_acumulado, 1, 0) OVER (PARTITION BY linea_id ORDER BY creado_en, id) AS impuesto_parte
  FROM dev1
), mov AS (
  SELECT YEAR(v.confirmado_en) AS anio, MONTH(v.confirmado_en) AS mes,
   CASE WHEN l.servicio_id IS NOT NULL THEN 'servicio' WHEN l.producto_id IS NOT NULL THEN 'producto' ELSE 'sin_clasificar' END AS tipo,
   COALESCE(l.servicio_id, l.producto_id, 0) AS objetivo_id,
   (l.total_centavos - l.impuesto_centavos) AS ventas_base, l.impuesto_centavos AS ventas_impuesto, l.descuento_centavos AS descuentos, 0 AS devol_base, 0 AS devol_impuesto
  FROM erp_venta_lineas l JOIN erp_ventas v ON v.id = l.venta_id WHERE v.estado = 'confirmada'
  UNION ALL
  SELECT YEAR(creado_en), MONTH(creado_en),
   CASE WHEN servicio_id IS NOT NULL THEN 'servicio' WHEN producto_id IS NOT NULL THEN 'producto' ELSE 'sin_clasificar' END,
   COALESCE(servicio_id, producto_id, 0), 0, 0, 0, monto_centavos - impuesto_parte, impuesto_parte
  FROM dev2
), ventas AS (
  SELECT anio, mes, tipo, objetivo_id, SUM(ventas_base) AS ventas_base, SUM(ventas_impuesto) AS ventas_impuesto, SUM(descuentos) AS descuentos,
         SUM(devol_base) AS devol_base, SUM(devol_impuesto) AS devol_impuesto
  FROM mov GROUP BY anio, mes, tipo, objetivo_id
), gastos AS (
  SELECT YEAR(a.fecha) AS anio, MONTH(a.fecha) AS mes, IF(c.id = rc.cuenta_id, 'comisiones', 'gasto') AS tipo, IF(c.id = rc.cuenta_id, 0, c.id) AS objetivo_id,
         SUM(li.debe_centavos - li.haber_centavos) AS neto
  FROM erp_cont_lineas li JOIN erp_cont_asientos a ON a.id = li.asiento_id JOIN erp_cont_cuentas c ON c.id = li.cuenta_id
  LEFT JOIN erp_cont_roles rc ON rc.rol = 'gasto_comisiones'
  WHERE c.tipo IN ('gasto', 'costo') AND a.tipo <> 'cierre'
    AND NOT EXISTS (SELECT 1 FROM erp_cont_asientos o WHERE o.id = a.reversa_de_id AND o.tipo = 'cierre')
  GROUP BY YEAR(a.fecha), MONTH(a.fecha), IF(c.id = rc.cuenta_id, 'comisiones', 'gasto'), IF(c.id = rc.cuenta_id, 0, c.id)
)
SELECT anio, mes, (anio * 100 + mes) AS anio_mes, tipo, objetivo_id,
 ventas_base AS ventas_base_centavos, ventas_impuesto AS ventas_impuesto_centavos, descuentos AS descuentos_informativo_centavos,
 devol_base AS devoluciones_base_centavos, devol_impuesto AS devoluciones_impuesto_centavos,
 (ventas_base - devol_base) AS real_base_centavos, (ventas_impuesto - devol_impuesto) AS real_impuesto_centavos,
 (ventas_base - devol_base + ventas_impuesto - devol_impuesto) AS real_total_centavos
FROM ventas
UNION ALL
SELECT anio, mes, (anio * 100 + mes), tipo, objetivo_id, 0, 0, 0, 0, 0, neto, 0, neto
FROM gastos;
