-- Revierte 068: retira las vistas, los triggers y las tablas de fotos. La guarda avisa si existen fotos (son registros inmutables de lo que se informó).
DROP VIEW IF EXISTS bi_presupuesto_foto_lineas;
DROP VIEW IF EXISTS bi_presupuesto_fotos;
DROP TRIGGER IF EXISTS trg_presup_foto_linea_no_delete;
DROP TRIGGER IF EXISTS trg_presup_foto_linea_no_update;
DROP TRIGGER IF EXISTS trg_presup_foto_linea_insert;
DROP TRIGGER IF EXISTS trg_presup_foto_no_delete;
DROP TRIGGER IF EXISTS trg_presup_foto_no_update;
DROP TABLE IF EXISTS erp_presupuesto_foto_lineas;
DROP TABLE IF EXISTS erp_presupuesto_fotos;
