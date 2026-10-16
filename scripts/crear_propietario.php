<?php
/**
 * Compatibilidad: configura al primer propietario con un secreto de instalación.
 * Uso: APPSALON_OWNER_PASSWORD='contraseña segura' php scripts/crear_propietario.php --email=... --nombre=... --apellido=...
 *
 * Delega en scripts/instalar.php (misma lógica idempotente, con candado y auditoría): no sustituye
 * contraseñas existentes ni crea un segundo propietario. Para evitar contraseñas en variables de entorno
 * prefiere `php scripts/instalar.php` con OWNER_EMAIL, que emite un enlace de activación de un solo uso.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opciones = getopt('', ['email:', 'nombre:', 'apellido:']);
$password = getenv('APPSALON_OWNER_PASSWORD') ?: '';
if (!isset($opciones['email']) || !is_string($opciones['email']) || strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Indica --email válido y APPSALON_OWNER_PASSWORD de 12 a 72 bytes. No existe contraseña predeterminada.\n");
    exit(1);
}
$entorno = array_merge(getenv(), [
    'OWNER_EMAIL' => $opciones['email'],
    'OWNER_NOMBRE' => is_string($opciones['nombre'] ?? null) ? $opciones['nombre'] : 'Propietario',
    'OWNER_APELLIDO' => is_string($opciones['apellido'] ?? null) ? $opciones['apellido'] : 'Salón',
]);
$proceso = proc_open([PHP_BINARY, __DIR__ . '/instalar.php'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $entorno);
exit(is_resource($proceso) ? proc_close($proceso) : 1);
