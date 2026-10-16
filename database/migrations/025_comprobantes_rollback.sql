-- Reversión SEGURA de 025. Los comprobantes emitidos son evidencia de negocio (numeración correlativa que ya se entregó a clientes):
-- la reversión se NIEGA (error 1242, sin cambiar nada) si existe alguno. Para revertir de todos modos: respalde (mysqldump) y vacíe
-- `comprobantes` con un usuario administrador (los disparadores de solo anexado no impiden TRUNCATE/DROP: decisión humana y respaldada).
DO (SELECT 'comprobantes tiene comprobantes emitidos: respáldelos antes de revertir' FROM comprobantes CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DO (SELECT 'comprobantes_secuencia ya avanzó: respáldela antes de revertir' FROM (SELECT 1 FROM comprobantes_secuencia WHERE ultimo > 0) AS x CROSS JOIN (SELECT 1 UNION ALL SELECT 2) AS dos);
DROP TABLE IF EXISTS comprobantes;
DROP TABLE IF EXISTS comprobantes_secuencia;
