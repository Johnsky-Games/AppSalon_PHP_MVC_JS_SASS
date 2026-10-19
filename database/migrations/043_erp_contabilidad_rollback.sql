-- Solo sin asientos (la guarda lo verifica). Descarta el plan base y los roles, que son datos de configuración.
DROP TABLE IF EXISTS erp_cont_lineas;
DROP TABLE IF EXISTS erp_cont_asientos;
DROP TABLE IF EXISTS erp_cont_periodos;
DROP TABLE IF EXISTS erp_cont_roles;
DROP TABLE IF EXISTS erp_cont_cuentas;
DROP TABLE IF EXISTS erp_cont_secuencia;
DROP TABLE IF EXISTS erp_cont_config;
