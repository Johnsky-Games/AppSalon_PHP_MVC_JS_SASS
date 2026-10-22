-- Modelo de lectura para BI, segundo corte: flujo de dinero, devoluciones, aplicaciones de saldo y notas de crédito.
-- Definiciones y reconciliaciones en docs/BI_COBRO_NETO.md. Mismas reglas que 057: solo lectura (la prueba verifica que ninguna vista admite
-- UPDATE ni DELETE; SELECT DISTINCT queda como defensa adicional, inocua porque cada fila lleva su identificador único), SQL SECURITY DEFINER
-- (el usuario de BI lee solo estas vistas) y sin datos personales (sin nombres, contactos, referencias ni motivos).
-- Pagos simulados (proveedor 'mock' o vacío, igual que los reportes existentes) y transferencias pendientes o rechazadas nunca cuentan como dinero.
-- Toda unión es 1 a 0..1 por clave única, así que ningún importe se multiplica.
-- cita_origen_id usa la referencia duradera de la cita (cita_referencia_id sobrevive al borrado de la cita; 0 = venta sin cita).

CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_flujo_dinero AS
SELECT DISTINCT c.id AS movimiento_id, DATE(c.registrado_en) AS fecha_movimiento,
 IF(c.tipo = 'ingreso', 'entrada', 'salida') AS sentido, c.metodo,
 c.monto_centavos AS importe_centavos,
 IF(c.tipo = 'ingreso', 1, -1) * c.monto_centavos AS importe_con_signo_centavos,
 CASE WHEN c.tipo = 'ingreso' AND c.pago_id IS NOT NULL THEN 'cobro'
      WHEN c.tipo = 'ingreso' AND pm.id IS NOT NULL THEN 'carga_prepago'
      WHEN c.tipo = 'egreso' AND c.pago_id IS NOT NULL THEN 'devolucion_de_cobro'
      WHEN c.tipo = 'egreso' AND pm.id IS NOT NULL THEN 'reembolso_prepago'
      WHEN c.tipo = 'ingreso' THEN 'otro_ingreso'
      ELSE 'otro_egreso' END AS tipo_flujo,
 c.pago_id, NULLIF(c.devolucion_id, 0) AS cobro_devolucion_id, NULLIF(COALESCE(p.cita_id, p.cita_referencia_id), 0) AS cita_origen_id,
 ap.venta_id AS venta_original_id, DATE(v.confirmado_en) AS fecha_venta_original, DATE(p.pagado_at) AS fecha_cobro_original,
 pm.prepago_id
FROM caja_movimientos c
LEFT JOIN pagos p ON p.id = c.pago_id
LEFT JOIN erp_pago_aplicaciones ap ON ap.pago_id = p.id
LEFT JOIN erp_ventas v ON v.id = ap.venta_id
LEFT JOIN erp_prepago_movimientos pm ON pm.caja_id = c.id
WHERE p.id IS NULL
   OR (COALESCE(NULLIF(TRIM(p.proveedor), ''), 'mock') <> 'mock'
       AND COALESCE(p.estado_verificacion, 'confirmado') NOT IN ('pendiente_verificacion', 'rechazado'));

CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_devoluciones_dinero AS
SELECT DISTINCT d.id AS cobro_devolucion_id, DATE(d.registrado_en) AS fecha_movimiento_devolucion, d.pago_id,
 NULLIF(COALESCE(d.cita_id, p.cita_id, p.cita_referencia_id), 0) AS cita_origen_id, ap.venta_id AS venta_original_id, DATE(v.confirmado_en) AS fecha_venta_original,
 DATE(p.pagado_at) AS fecha_cobro_original, d.metodo, d.monto_centavos AS importe_centavos,
 IF(d.metodo = 'prepago', 0, 1) AS sale_de_caja, IF(d.metodo = 'prepago', 1, 0) AS retorna_a_saldo_prepago, d.origen
FROM cobros_devoluciones d
LEFT JOIN pagos p ON p.id = d.pago_id
LEFT JOIN erp_pago_aplicaciones ap ON ap.pago_id = d.pago_id
LEFT JOIN erp_ventas v ON v.id = ap.venta_id
WHERE p.id IS NULL
   OR (COALESCE(NULLIF(TRIM(p.proveedor), ''), 'mock') <> 'mock'
       AND COALESCE(p.estado_verificacion, 'confirmado') NOT IN ('pendiente_verificacion', 'rechazado'));

CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_aplicaciones_saldo AS
SELECT DISTINCT m.id AS movimiento_id, DATE(m.creado_en) AS fecha_aplicacion, m.prepago_id,
 IF(pq.id IS NULL, 'saldo_o_regalo', 'paquete') AS origen_saldo, pq.id AS paquete_id, m.tipo AS tipo_movimiento,
 ABS(m.monto_centavos) AS importe_centavos,
 IF(m.tipo = 'aplicacion', 1, -1) * ABS(m.monto_centavos) AS consumo_neto_con_signo_centavos,
 m.venta_id AS venta_original_id, DATE(v.confirmado_en) AS fecha_venta_original, m.pago_id, m.devolucion_id AS cobro_devolucion_id,
 0 AS ingresa_dinero
FROM erp_prepago_movimientos m
LEFT JOIN erp_paquetes pq ON pq.prepago_id = m.prepago_id
LEFT JOIN erp_ventas v ON v.id = m.venta_id
WHERE m.tipo IN ('aplicacion', 'restitucion');

-- Una nota de crédito rechazada se sustituye por otra (sustituye_a_id, una sola sucesora por nota): las devoluciones heredadas siguen enlazadas a la
-- rechazada y solo las nuevas a la sustituta. nota_credito_vinculada_id es el enlace tal como está guardado; nota_credito_efectiva_id resuelve la cadena
-- hasta la nota vigente (la última sucesora), que es la que respalda la devolución.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_devoluciones_venta AS
WITH RECURSIVE sucesion (raiz_id, id) AS (
  SELECT f.id, f.id FROM erp_fiscal_documentos f WHERE f.tipo = '04'
  UNION ALL
  SELECT s.raiz_id, f.id FROM sucesion s JOIN erp_fiscal_documentos f ON f.sustituye_a_id = s.id
), efectiva AS (
  SELECT s.raiz_id, s.id AS efectiva_id FROM sucesion s
  WHERE NOT EXISTS (SELECT 1 FROM erp_fiscal_documentos x WHERE x.sustituye_a_id = s.id)
)
SELECT DISTINCT d.id AS devolucion_id, DATE(d.creado_en) AS fecha_devolucion, d.venta_id AS venta_original_id,
 DATE(v.confirmado_en) AS fecha_venta_original, d.linea_id, l.producto_id, l.servicio_id, d.cantidad_milesimas,
 d.monto_centavos AS monto_con_impuesto_centavos, d.reintegrar_stock,
 nd.documento_id AS nota_credito_vinculada_id, ef.efectiva_id AS nota_credito_efectiva_id
FROM erp_devoluciones d
JOIN erp_ventas v ON v.id = d.venta_id
JOIN erp_venta_lineas l ON l.id = d.linea_id
LEFT JOIN erp_fiscal_nc_devoluciones nd ON nd.devolucion_id = d.id
LEFT JOIN efectiva ef ON ef.raiz_id = nd.documento_id;

CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_notas_credito AS
SELECT DISTINCT d.id AS nota_credito_id, d.venta_id AS venta_original_id, DATE(d.creado_en) AS fecha_nota, d.estado,
 d.sustituye_a_id, r.id AS reemplazada_por_id, IF(r.id IS NULL, 1, 0) AS es_nota_vigente,
 COALESCE(t.base_centavos, 0) AS base_centavos, COALESCE(t.iva_centavos, 0) AS iva_centavos,
 COALESCE(t.total_centavos, 0) AS total_centavos, COALESCE(t.lineas, 0) AS lineas
FROM erp_fiscal_documentos d
LEFT JOIN erp_fiscal_documentos r ON r.sustituye_a_id = d.id
LEFT JOIN (SELECT documento_id, SUM(base_centavos) AS base_centavos, SUM(iva_centavos) AS iva_centavos,
                  SUM(total_centavos) AS total_centavos, COUNT(*) AS lineas
           FROM erp_fiscal_nc_lineas GROUP BY documento_id) t ON t.documento_id = d.id
WHERE d.tipo = '04';
