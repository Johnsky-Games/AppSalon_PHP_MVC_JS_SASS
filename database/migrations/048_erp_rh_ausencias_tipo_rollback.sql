-- Solo si ninguna ausencia fue clasificada distinto de «permiso» (la guarda lo verifica).
ALTER TABLE erp_ausencias DROP COLUMN tipo;
