DO (SELECT 'Conserve saldos y evidencias de prepago antes de revertir' FROM erp_prepagos CROSS JOIN(SELECT 1 UNION ALL SELECT 2)x);
DROP TRIGGER IF EXISTS trg_prepago_mov_no_delete;
DROP TRIGGER IF EXISTS trg_prepago_mov_no_update;
DROP TABLE IF EXISTS erp_transferencia_referencias;
DROP TABLE IF EXISTS erp_prepago_movimientos;
DROP TABLE IF EXISTS erp_prepago_cargas;
DROP TABLE IF EXISTS erp_prepagos;
