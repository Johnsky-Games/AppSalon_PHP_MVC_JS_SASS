ALTER TABLE profesionales DROP FOREIGN KEY fk_profesional_colaborador, DROP INDEX uq_profesional_colaborador, DROP COLUMN colaborador_id;
DROP TABLE IF EXISTS correos_salida;
DROP TABLE IF EXISTS colaborador_invitaciones;
DROP TABLE IF EXISTS colaboradores;
