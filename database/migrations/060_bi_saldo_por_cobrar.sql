-- Modelo de lectura para BI, cuarto corte: saldo por cobrar de las ventas confirmadas, al corte que se elija.
-- Definiciones y reconciliaciones en docs/BI_SALDO_POR_COBRAR.md. Mismas reglas que 057-059: solo lectura (la prueba verifica que ninguna vista
-- admite UPDATE ni DELETE), SQL SECURITY DEFINER (el usuario de BI lee solo estas vistas) y sin datos personales (sin comprador, contacto, referencias ni notas).
-- Las reglas son las de Services\ErpVentaService::resumen (cobrado = pagos completados o reembolsados aplicados a la venta; devuelto = devoluciones
-- de cobro, o el total del pago si está reembolsado; saldo = máx(0, total − devolución comercial − cobrado + devuelto)). Pagos simulados (proveedor
-- 'mock' o vacío) y transferencias sin verificar nunca cuentan como cobro. Ninguna unión multiplica importes: cada hecho entra una sola vez.

-- Grano: un hecho que cambia lo que el cliente debe, identificado por (origen_hecho, hecho_id): venta, devolucion_comercial, pago (el cobro), anulacion,
-- devolucion_de_cobro y reembolso_total (remanente de un pago reembolsado sin todas sus filas de devolución). Sin llave de texto concatenada:
-- la nulabilidad de CONCAT en una UNION cambia con el sql_mode de quien crea la vista y el esquema restaurado no coincidiría.
-- El saldo de una venta al corte es SUM(importe_con_signo_centavos) con fecha <= corte
-- (con piso en 0 por venta: un cobro de más es saldo a favor del cliente, no una deuda negativa; ver docs/BI_SALDO_POR_COBRAR.md).
-- anulacion: una venta confirmada y luego anulada (solo es posible sin cobros netos ni pendientes) conserva toda su historia y se compensa con una fila fechada
-- el día de la anulación (evento venta_anulada; sin evento, el día de la confirmación), de modo que los cortes anteriores todavía ven la deuda.
-- venta: aumenta la deuda; devolucion_comercial: la reduce; cobro: la reduce (la aplicación de un prepago o paquete también: no es dinero nuevo, ver
-- es_aplicacion_de_saldo); devolucion_de_cobro: devolver dinero (o restituir a saldo) la aumenta de nuevo.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_cxc_movimientos AS
SELECT 'venta' AS origen_hecho, v.id AS hecho_id, 'venta' AS tipo_movimiento, DATE(v.confirmado_en) AS fecha, v.id AS venta_id,
 v.total_centavos AS importe_centavos, v.total_centavos AS importe_con_signo_centavos, CAST(NULL AS CHAR(30) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS metodo, 0 AS es_aplicacion_de_saldo
FROM erp_ventas v WHERE v.confirmado_en IS NOT NULL AND v.estado IN ('confirmada', 'anulada')
UNION ALL
SELECT 'devolucion_comercial', d.id, 'devolucion_comercial', DATE(d.creado_en), d.venta_id, d.monto_centavos, -d.monto_centavos, NULL, 0
FROM erp_devoluciones d JOIN erp_ventas v ON v.id = d.venta_id AND v.confirmado_en IS NOT NULL AND v.estado IN ('confirmada', 'anulada')
UNION ALL
SELECT 'pago', p.id, 'cobro', DATE(COALESCE(p.pagado_at, p.created_at)), a.venta_id, a.importe_centavos, -a.importe_centavos, p.metodo_pago COLLATE utf8mb4_unicode_ci, p.metodo_pago = 'prepago'
FROM erp_pago_aplicaciones a JOIN pagos p ON p.id = a.pago_id JOIN erp_ventas v ON v.id = a.venta_id AND v.confirmado_en IS NOT NULL AND v.estado IN ('confirmada', 'anulada')
WHERE p.estado IN ('completado', 'reembolsado') AND COALESCE(NULLIF(TRIM(p.proveedor), ''), 'mock') <> 'mock'
  AND COALESCE(p.estado_verificacion, 'confirmado') NOT IN ('pendiente_verificacion', 'rechazado')
UNION ALL
SELECT 'devolucion_de_cobro', cd.id, 'devolucion_de_cobro', DATE(cd.registrado_en), a.venta_id, cd.monto_centavos, cd.monto_centavos, cd.metodo COLLATE utf8mb4_unicode_ci, cd.metodo = 'prepago'
FROM cobros_devoluciones cd JOIN pagos p ON p.id = cd.pago_id JOIN erp_pago_aplicaciones a ON a.pago_id = p.id JOIN erp_ventas v ON v.id = a.venta_id AND v.confirmado_en IS NOT NULL AND v.estado IN ('confirmada', 'anulada')
WHERE p.estado IN ('completado', 'reembolsado') AND COALESCE(NULLIF(TRIM(p.proveedor), ''), 'mock') <> 'mock'
  AND COALESCE(p.estado_verificacion, 'confirmado') NOT IN ('pendiente_verificacion', 'rechazado')
UNION ALL
SELECT 'reembolso_total', p.id, 'devolucion_de_cobro', DATE(COALESCE(p.reembolsado_at, p.pagado_at, p.created_at)), a.venta_id,
 a.importe_centavos - COALESCE(s.devuelto, 0), a.importe_centavos - COALESCE(s.devuelto, 0), p.metodo_pago COLLATE utf8mb4_unicode_ci, p.metodo_pago = 'prepago'
FROM erp_pago_aplicaciones a JOIN pagos p ON p.id = a.pago_id JOIN erp_ventas v ON v.id = a.venta_id AND v.confirmado_en IS NOT NULL AND v.estado IN ('confirmada', 'anulada')
LEFT JOIN (SELECT pago_id, SUM(monto_centavos) AS devuelto FROM cobros_devoluciones GROUP BY pago_id) s ON s.pago_id = p.id
WHERE p.estado = 'reembolsado' AND a.importe_centavos > COALESCE(s.devuelto, 0) AND COALESCE(NULLIF(TRIM(p.proveedor), ''), 'mock') <> 'mock'
  AND COALESCE(p.estado_verificacion, 'confirmado') NOT IN ('pendiente_verificacion', 'rechazado')
UNION ALL
SELECT 'anulacion', v.id, 'anulacion', DATE(COALESCE(e.anulada_en, v.confirmado_en)), v.id,
 v.total_centavos - COALESCE(d.devuelto, 0), -(v.total_centavos - COALESCE(d.devuelto, 0)), NULL, 0
FROM erp_ventas v
LEFT JOIN (SELECT entidad_id, MAX(creado_en) AS anulada_en FROM erp_eventos WHERE entidad = 'venta' AND tipo = 'venta_anulada' GROUP BY entidad_id) e ON e.entidad_id = v.id
LEFT JOIN (SELECT venta_id, SUM(monto_centavos) AS devuelto FROM erp_devoluciones GROUP BY venta_id) d ON d.venta_id = v.id
WHERE v.estado = 'anulada' AND v.confirmado_en IS NOT NULL AND v.total_centavos - COALESCE(d.devuelto, 0) > 0;

-- Grano: una fila por venta confirmada, con su saldo ACTUAL. Para un corte anterior usa bi_cxc_movimientos.
-- por_verificar_centavos: transferencias declaradas y aún sin verificar; no son dinero y no reducen el saldo.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_saldo_por_cobrar_venta AS
SELECT v.id AS venta_id, DATE(v.confirmado_en) AS fecha_venta, v.total_centavos AS total_original_centavos,
 COALESCE(m.devolucion_comercial, 0) AS devolucion_comercial_centavos,
 v.total_centavos - COALESCE(m.devolucion_comercial, 0) AS total_neto_centavos,
 COALESCE(m.cobrado, 0) AS cobrado_centavos, COALESCE(m.devuelto, 0) AS devuelto_centavos,
 GREATEST(0, v.total_centavos - COALESCE(m.devolucion_comercial, 0) - COALESCE(m.cobrado, 0) + COALESCE(m.devuelto, 0)) AS saldo_por_cobrar_centavos,
 GREATEST(0, COALESCE(m.cobrado, 0) - COALESCE(m.devuelto, 0) - (v.total_centavos - COALESCE(m.devolucion_comercial, 0))) AS saldo_a_favor_cliente_centavos,
 COALESCE(pv.por_verificar, 0) AS por_verificar_centavos
FROM erp_ventas v
LEFT JOIN (
  SELECT venta_id,
         SUM(IF(tipo_movimiento = 'devolucion_comercial', importe_centavos, 0)) AS devolucion_comercial,
         SUM(IF(tipo_movimiento = 'cobro', importe_centavos, 0)) AS cobrado,
         SUM(IF(tipo_movimiento = 'devolucion_de_cobro', importe_centavos, 0)) AS devuelto
  FROM bi_cxc_movimientos GROUP BY venta_id
) m ON m.venta_id = v.id
LEFT JOIN (
  SELECT a.venta_id, SUM(a.importe_centavos) AS por_verificar
  FROM erp_pago_aplicaciones a JOIN pagos p ON p.id = a.pago_id
  WHERE p.estado = 'pendiente' AND p.estado_verificacion = 'pendiente_verificacion' GROUP BY a.venta_id
) pv ON pv.venta_id = v.id
WHERE v.estado = 'confirmada';
