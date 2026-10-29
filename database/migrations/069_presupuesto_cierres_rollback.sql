-- Revierte 069: retira la vista, los triggers y la tabla de cierres mensuales. La guarda avisa si existen cierres (son la referencia oficial de lo informado).
DROP VIEW IF EXISTS bi_presupuesto_cierres;
DROP TRIGGER IF EXISTS trg_presup_cierre_no_delete;
DROP TRIGGER IF EXISTS trg_presup_cierre_no_update;
DROP TRIGGER IF EXISTS trg_presup_cierre_insert;
DROP TABLE IF EXISTS erp_presupuesto_cierres;
