-- ADVERTENCIA: elimina la membresía de cliente. Al reaplicar la 016 las suspensiones explícitas de clientes se pierden
-- (solo se rederiva usuario_accesos.activo). Úsalo únicamente en copias de prueba; en producción restaura el respaldo.
DROP TABLE IF EXISTS clientes;
