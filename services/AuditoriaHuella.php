<?php
namespace Services;

use Classes\Secretos;

/**
 * Huellas para registros de auditoría: permiten comparar y correlacionar sin guardar datos personales.
 *  - IP: se trunca (IPv4 a /24, IPv6 a /48) y luego se firma con HMAC; no se puede recuperar ni adivinar sin el secreto.
 *  - Texto: HMAC con clave propia (p. ej. búsquedas de clientes, que pueden contener nombres o correos).
 * Las claves se derivan de APP_KEY por propósito (Classes\Secretos), así que cambiar de propósito invalida la correlación.
 */
final class AuditoriaHuella
{
    /** IP truncada: «192.0.2.0/24» o «2001:db8:1::/48». null si no es una IP válida. */
    public static function ipTruncada(?string $ip): ?string
    {
        if ($ip === null || $ip === '') { return null; }
        $bin = @inet_pton($ip);
        if ($bin === false) { return null; }
        if (strlen($bin) === 16) {
            // IPv4 mapeada en IPv6 (::ffff:a.b.c.d): se trata como IPv4.
            if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") { $bin = substr($bin, 12); }
        }
        if (strlen($bin) === 4) {
            return inet_ntop(substr($bin, 0, 3) . "\0") . '/24';
        }
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** HMAC de la IP truncada (64 hex) o null si no hay IP utilizable. */
    public static function ip(?string $ip): ?string
    {
        $truncada = self::ipTruncada($ip);
        return $truncada === null ? null : hash_hmac('sha256', $truncada, Secretos::clave('auditoria-ip'));
    }

    /** IP del cliente tal como la ve el servidor (nunca cabeceras reenviadas, que el cliente controla). */
    public static function ipDeSolicitud(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) ? $ip : null;
    }

    public static function texto(string $texto): string
    {
        return hash_hmac('sha256', $texto, Secretos::clave('auditoria-texto'));
    }
}
