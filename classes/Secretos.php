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
            $entorno = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
            if (in_array($entorno, ['production', 'prod', 'produccion'], true)) {
                throw new ServiceUnavailableException('APP_KEY (mínimo 32 caracteres) es obligatoria en producción.');
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
