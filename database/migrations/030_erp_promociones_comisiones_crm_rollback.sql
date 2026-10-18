DO (SELECT 'Conserve reglas antes de revertir' FROM erp_comision_reglas CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DO (SELECT 'Conserve liquidaciones antes de revertir' FROM erp_comision_liquidaciones CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DO (SELECT 'Conserve promociones antes de revertir' FROM erp_promociones CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DO (SELECT 'Conserve comisiones antes de revertir' FROM erp_comision_movimientos CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DO (SELECT 'Conserve oportunidades antes de revertir' FROM erp_oportunidades CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DO (SELECT 'Conserve interacciones antes de revertir' FROM erp_interacciones CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DROP TABLE IF EXISTS erp_interacciones;
DROP TABLE IF EXISTS erp_oportunidades;
DROP TABLE IF EXISTS erp_comision_movimientos;
DROP TABLE IF EXISTS erp_comision_liquidaciones;
DROP TABLE IF EXISTS erp_comision_reglas;
DROP TABLE IF EXISTS erp_promocion_usos;
DROP TABLE IF EXISTS erp_promociones;
ALTER TABLE erp_venta_lineas DROP COLUMN vendedor_colaborador_id;

ALTER TABLE erp_ventas DROP COLUMN atencion_sync_hash;
