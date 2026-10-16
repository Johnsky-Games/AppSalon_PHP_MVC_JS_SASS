<?php

namespace Classes;

/**
 * Cifrado autenticado (XSalsa20-Poly1305) de cargas pendientes de envío, como el token de una invitación.
 * La clave deriva de APP_KEY con un propósito propio; si cambia, las cargas pendientes ya no se descifran
 * y el evento se reintenta con una invitación nueva (reenvío explícito).
 */
final class CargaCifrada
{
    public static function cifrar(array $datos): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = sodium_crypto_secretbox(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $nonce, Secretos::clave('carga-correo'));
        return base64_encode($nonce . $cifrado);
    }

    public static function descifrar(string $carga): ?array
    {
        $bin = base64_decode($carga, true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plano = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), Secretos::clave('carga-correo'));
        if ($plano === false) {
            return null;
        }
        $datos = json_decode($plano, true);
        return is_array($datos) ? $datos : null;
    }
}
