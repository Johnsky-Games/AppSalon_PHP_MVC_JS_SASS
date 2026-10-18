DO (SELECT 'Conserve tarifas pactadas antes de revertir' FROM erp_cita_tarifas CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DROP TABLE IF EXISTS erp_cita_tarifas;
