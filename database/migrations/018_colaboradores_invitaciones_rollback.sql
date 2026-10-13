-- ADVERTENCIA (DEF-16): este rollback ELIMINA datos de negocio: colaboradores, invitaciones, cola de correos y el vínculo profesional-colaborador.
-- `php database/migrador.php down` se niega a ejecutarlo si hay datos, salvo `--aceptar-perdida=018_colaboradores_invitaciones` y un respaldo verificable
-- (--respaldo=<archivo.sql.gz>); ver 018_colaboradores_invitaciones.guard.php y docs/GUIA_ACTUALIZACION_IDENTIDADES.md §7.
-- Ejecutar este archivo a mano se salta esos controles: úsalo solo en copias de prueba.
ALTER TABLE profesionales DROP FOREIGN KEY fk_profesional_colaborador, DROP INDEX uq_profesional_colaborador, DROP COLUMN colaborador_id;
DROP TABLE IF EXISTS correos_salida;
DROP TABLE IF EXISTS colaborador_invitaciones;
DROP TABLE IF EXISTS colaboradores;
