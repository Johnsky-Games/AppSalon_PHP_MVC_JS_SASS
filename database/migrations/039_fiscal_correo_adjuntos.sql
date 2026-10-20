-- La carga fiscal conserva XML y PDF cifrados hasta su aceptación por SMTP.
ALTER TABLE correos_salida MODIFY carga_cifrada MEDIUMTEXT NULL;
