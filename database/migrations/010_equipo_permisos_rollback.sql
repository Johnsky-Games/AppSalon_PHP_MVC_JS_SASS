DROP TABLE equipo_auditoria;
DROP TABLE equipo_mutex;
ALTER TABLE profesionales DROP FOREIGN KEY fk_profesional_cuenta, DROP INDEX uq_profesional_cuenta, DROP COLUMN usuarioId;
DROP TABLE usuario_accesos;
ALTER TABLE usuarios DROP COLUMN version_sesion;
