<?php

namespace Classes;

/**
 * Derivación de claves de la aplicación a partir de un único secreto maestro (APP_KEY).
 * Cada propósito obtiene una clave independiente (HKDF), de modo que la huella de idempotencia
 * y el cifrado de cargas pendientes no comparten material.
 */
final class Secretos
{
    private static bool $advertido = false;

    public static function clave(string $proposito): string
    {
        $maestro = $_ENV['APP_KEY'] ?? getenv('APP_KEY');
        $maestro = is_string($maestro) ? trim($maestro) : '';
        if (strlen($maestro) < 32) {
            // Falla cerrado: solo un entorno EXPLÍCITAMENTE de desarrollo o pruebas puede derivar la clave.
            // Un valor vacío, desconocido o con variaciones («staging», «Production », «TESTING», «testing\n») se trata como producción (Classes\Entorno, criterio estricto).
            if (!Entorno::esPruebasODesarrollo()) {
                throw new ServiceUnavailableException('APP_KEY (mínimo 32 caracteres) es obligatoria fuera de desarrollo y pruebas.');
            }
            // Solo desarrollo/pruebas: clave derivada de la configuración local, nunca de un valor publicado.
            $maestro = hash('sha256', 'appsalon-dev|' . ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '') . '|' . ($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ''));
            if (!self::$advertido) {
                self::$advertido = true;
                error_log('[AppSalon] APP_KEY no configurada: usando clave derivada solo válida para desarrollo y pruebas.');
            }
        }
        return hash_hkdf('sha256', $maestro, 32, 'appsalon|' . $proposito);
    }
}
