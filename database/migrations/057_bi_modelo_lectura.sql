-- Modelo de lectura para BI (Power BI u otra herramienta): vistas de SOLO LECTURA sobre hechos ya registrados.
-- No contienen datos personales (sin comprador, contacto ni identificaciones) y no cambian ninguna tabla existente.
-- Cada vista usa SELECT DISTINCT a propósito: sin él, MySQL la trataría como actualizable y un UPDATE/DELETE sobre la vista llegaría a la tabla de
-- origen. Con DISTINCT la vista no es actualizable (cada fila ya es única por su identificador, así que no cambia el resultado).
-- SQL SECURITY INVOKER: quien consulta necesita permiso sobre las tablas de origen, no se amplía el acceso.
-- Importes en centavos y cantidades en milésimas (enteros), igual que el resto del ERP; el glosario está en docs/BI_MODELO_LECTURA.md.

CREATE OR REPLACE SQL SECURITY INVOKER VIEW bi_venta_lineas AS
SELECT DISTINCT l.id AS linea_id, l.venta_id, DATE(v.confirmado_en) AS fecha_venta, l.producto_id, l.servicio_id, l.profesional_id,
 l.cantidad_milesimas, l.entregado_milesimas, l.devuelto_milesimas, l.precio_centavos, l.descuento_centavos,
 (l.total_centavos - l.impuesto_centavos) AS neto_sin_impuesto_centavos, l.impuesto_centavos, l.total_centavos,
 ((l.costo_centavos * l.cantidad_milesimas + 500) DIV 1000) AS costo_total_centavos
FROM erp_venta_lineas l JOIN erp_ventas v ON v.id = l.venta_id
WHERE v.estado = 'confirmada';

CREATE OR REPLACE SQL SECURITY INVOKER VIEW bi_libro_lineas AS
SELECT DISTINCT li.id AS linea_id, a.id AS asiento_id, a.numero AS asiento_numero, a.fecha, a.tipo AS asiento_tipo,
 (a.reversa_de_id IS NOT NULL) AS es_reverso, a.reversa_de_id, c.id AS cuenta_id, c.codigo AS cuenta_codigo,
 c.nombre AS cuenta_nombre, c.tipo AS cuenta_tipo, li.debe_centavos, li.haber_centavos,
 (li.debe_centavos - li.haber_centavos) AS neto_deudor_centavos
FROM erp_cont_lineas li JOIN erp_cont_asientos a ON a.id = li.asiento_id JOIN erp_cont_cuentas c ON c.id = li.cuenta_id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW bi_inventario_movimientos AS
SELECT DISTINCT m.id AS movimiento_id, DATE(m.registrado_en) AS fecha, m.producto_id, p.sku, m.tipo,
 m.cantidad_milesimas, IF(m.tipo = 'entrada', m.cantidad_milesimas, -m.cantidad_milesimas) AS cantidad_con_signo_milesimas,
 m.costo_centavos, IF(m.tipo = 'entrada', 1, -1) * ((m.costo_centavos * m.cantidad_milesimas + 500) DIV 1000) AS valor_con_signo_centavos
FROM inventario_movimientos m JOIN inventario_productos p ON p.id = m.producto_id;
