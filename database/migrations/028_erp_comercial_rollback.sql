DO (SELECT 'No se revierten eventos ERP sin conservarlos' FROM erp_eventos CROSS JOIN (SELECT 1 UNION ALL SELECT 2) x);
-- La reversión soportada pasa por el guard del migrador y exige respaldo ante datos de negocio.
DROP TABLE IF EXISTS erp_integraciones_outbox;
DROP TABLE IF EXISTS erp_recibos;
DROP TABLE IF EXISTS erp_stock_origenes;
DROP TABLE IF EXISTS erp_devoluciones;
DROP TABLE IF EXISTS erp_pago_aplicaciones;
DROP TABLE IF EXISTS erp_stock_reservas;
DROP TABLE IF EXISTS erp_venta_lineas;
DROP TABLE IF EXISTS erp_ventas;
DROP TABLE IF EXISTS erp_caja_movimientos;
DROP TABLE IF EXISTS erp_caja_sesiones;
DROP TABLE IF EXISTS erp_catalogo;
DROP TABLE IF EXISTS erp_contactos;
DROP TABLE IF EXISTS erp_eventos;
DROP TABLE IF EXISTS erp_empresa;
