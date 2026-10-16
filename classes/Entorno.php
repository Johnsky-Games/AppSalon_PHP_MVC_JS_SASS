<?php

namespace Classes;

/**
 * Única definición del proyecto de «entorno de desarrollo o pruebas». Criterio ESTRICTO y fail-closed: solo el valor
 * EXACTO de APP_ENV, en minúsculas y sin espacios ni saltos de línea, de {development, dev, local, testing, test}.
 * No se recorta ni se normaliza: «TESTING», « testing », «Development», «DEV», «Local», «Test », «testing\n», «production»,
 * «prod», «staging», «stage», «qa», «live», «preprod», vacío y no definido NO son entornos de pruebas.
 *
 * Lo usan Classes\Secretos (derivación de clave de desarrollo), Services\CorreoSalidaService (envío en línea, variable
 * solo de pruebas) y scripts/instalar.php (APP_KEY obligatoria fuera de desarrollo).
 */
final class Entorno
{
    public const PRUEBAS_O_DESARROLLO = ['development', 'dev', 'local', 'testing', 'test'];

    public static function esPruebasODesarrollo(): bool
    {
        $v = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        return is_string($v) && in_array($v, self::PRUEBAS_O_DESARROLLO, true);
    }
}
