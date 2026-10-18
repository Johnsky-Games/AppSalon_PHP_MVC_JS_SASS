DO (SELECT 'Conserve cotizaciones antes de revertir' FROM erp_cotizaciones CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DROP TABLE IF EXISTS erp_cotizaciones;
