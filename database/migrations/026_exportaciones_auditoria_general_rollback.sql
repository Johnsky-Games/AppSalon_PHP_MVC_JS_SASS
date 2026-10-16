-- Reversión SEGURA de 026. La auditoría de exportaciones es evidencia de negocio: la reversión se NIEGA (error 1242, sin
-- cambiar nada) si la tabla contiene filas. Para revertir de todos modos, primero archive la tabla (mysqldump) y vacíela
-- con un usuario con privilegios de administración (los triggers de solo anexado no impiden TRUNCATE ni DROP: es una
-- decisión humana y respaldada). La guarda es una subconsulta escalar que devuelve más de una fila únicamente cuando hay datos.
DO (SELECT 'exportaciones_auditoria_general tiene registros: respáldela y vacíela antes de revertir' FROM exportaciones_auditoria_general CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);

DROP TABLE IF EXISTS exportaciones_auditoria_general;
