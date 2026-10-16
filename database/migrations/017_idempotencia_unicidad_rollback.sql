-- ADVERTENCIA (DEF-16): este rollback ELIMINA datos de negocio: claves de operación vigentes (idempotencia de altas) y el UNIQUE de correo normalizado.
-- `php database/migrador.php down` se niega a ejecutarlo si hay datos, salvo `--aceptar-perdida=017_idempotencia_unicidad` y un respaldo verificable
-- (--respaldo=<archivo.sql.gz>); ver 017_idempotencia_unicidad.guard.php y docs/GUIA_ACTUALIZACION_IDENTIDADES.md §7.
-- Ejecutar este archivo a mano se salta esos controles: úsalo solo en copias de prueba.
ALTER TABLE usuarios DROP INDEX uq_usuarios_email_normalizado;
DROP TABLE IF EXISTS operaciones_idempotentes;
