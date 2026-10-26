-- Revierte 070: retira triggers y tablas del conteo físico. La guarda avisa si existen conteos (son el registro de lo contado y de los ajustes que generaron).
DROP TRIGGER IF EXISTS trg_inv_conteo_ajuste_no_delete;
DROP TRIGGER IF EXISTS trg_inv_conteo_ajuste_no_update;
DROP TRIGGER IF EXISTS trg_inv_conteo_registro_no_delete;
DROP TRIGGER IF EXISTS trg_inv_conteo_registro_no_update;
DROP TRIGGER IF EXISTS trg_inv_conteo_linea_no_delete;
DROP TRIGGER IF EXISTS trg_inv_conteo_linea_update;
DROP TRIGGER IF EXISTS trg_inv_conteo_linea_insert;
DROP TRIGGER IF EXISTS trg_inv_conteo_no_delete;
DROP TRIGGER IF EXISTS trg_inv_conteo_update;
DROP TABLE IF EXISTS erp_inv_conteo_ajustes;
DROP TABLE IF EXISTS erp_inv_conteo_registros;
DROP TABLE IF EXISTS erp_inv_conteo_lineas;
DROP TABLE IF EXISTS erp_inv_conteos;
