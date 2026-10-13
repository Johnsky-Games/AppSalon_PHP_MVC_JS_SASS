<?php
namespace Services;

/**
 * Reglas PURAS (sin base de datos) para decidir si dos personas son «posibles homónimas».
 *
 * Una coincidencia solo es un AVISO para el operador: jamás fusiona ni bloquea. Dos personas distintas pueden llamarse igual;
 * el único impedimento real de alta es el correo (índice único).
 *
 * Criterios (acumulables):
 *  - nombre_exacto:   mismo nombre y apellido tras normalizar (minúsculas, sin tildes ni signos, espacios simples), también
 *                     con los mismos términos en otro orden o repartidos distinto entre nombre y apellido.
 *  - nombre_parecido: distancia de edición pequeña entre los nombres completos (errores de tecleo, 1 transposición o letra),
 *                     o mismo apellido y mismo primer nombre (p. ej. «Ana» y «Ana María»).
 *  - telefono:        mismo teléfono (se comparan los últimos 9 dígitos cuando ambos tienen al menos 9).
 */
final class HomonimosService
{
    public const NOMBRE_EXACTO = 'nombre_exacto';
    public const NOMBRE_PARECIDO = 'nombre_parecido';
    public const TELEFONO = 'telefono';
    /** Longitud mínima del nombre completo normalizado para aceptar «parecido» por distancia (evita falsos avisos con nombres cortos). */
    private const MIN_LARGO_PARECIDO = 6;
    private const MAX_DISTANCIA = 2;
    private const MAX_RATIO = 0.16;

    /** Minúsculas, sin tildes ni diéresis (la ñ cuenta como n), solo letras y dígitos, espacios simples. */
    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower($texto, 'UTF-8');
        $t = strtr($t, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
        ]);
        $t = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    /** @return string[] términos ordenados de «nombre apellido» normalizados */
    private static function terminos(string $nombre, string $apellido): array
    {
        $t = array_values(array_filter(explode(' ', self::normalizar($nombre . ' ' . $apellido)), static fn(string $x): bool => $x !== ''));
        sort($t);
        return $t;
    }

    /** Solo dígitos. */
    public static function digitos(?string $telefono): string
    {
        return (string)preg_replace('/\D+/', '', (string)$telefono);
    }

    public static function mismoTelefono(?string $a, ?string $b): bool
    {
        $x = self::digitos($a); $y = self::digitos($b);
        if ($x === '' || $y === '') { return false; }
        if (strlen($x) >= 9 && strlen($y) >= 9) { return substr($x, -9) === substr($y, -9); }
        return $x === $y;
    }

    /** Distancia de Damerau-Levenshtein restringida (una transposición de letras contiguas cuenta 1). */
    public static function distancia(string $a, string $b): int
    {
        $la = strlen($a); $lb = strlen($b);
        if ($a === $b) { return 0; }
        if ($la === 0 || $lb === 0) { return max($la, $lb); }
        $d = [];
        for ($i = 0; $i <= $la; $i++) { $d[$i][0] = $i; }
        for ($j = 0; $j <= $lb; $j++) { $d[0][$j] = $j; }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $c = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $c);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$la][$lb];
    }

    /**
     * Criterios por los que `$otro` es posible homónimo de `$nuevo` (vacío = no lo es).
     * @param array{nombre:string,apellido:string,telefono?:?string} $nuevo
     * @param array{nombre:string,apellido:string,telefono?:?string} $otro
     * @return string[]
     */
    public static function criterios(array $nuevo, array $otro): array
    {
        $c = [];
        $tn = self::terminos($nuevo['nombre'], $nuevo['apellido']);
        $to = self::terminos((string)$otro['nombre'], (string)$otro['apellido']);
        if ($tn && $to) {
            if ($tn === $to) {
                $c[] = self::NOMBRE_EXACTO;
            } else {
                $fn = implode(' ', $tn); $fo = implode(' ', $to);
                $largo = min(strlen($fn), strlen($fo));
                $dist = self::distancia($fn, $fo);
                $parecido = $largo >= self::MIN_LARGO_PARECIDO && $dist <= self::MAX_DISTANCIA && $dist / max(strlen($fn), strlen($fo)) <= self::MAX_RATIO;
                if (!$parecido) {
                    // Mismo apellido y mismo primer nombre («Ana Pérez» / «Ana María Pérez»).
                    $apN = self::normalizar($nuevo['apellido']); $apO = self::normalizar((string)$otro['apellido']);
                    $pnN = explode(' ', self::normalizar($nuevo['nombre']))[0] ?? ''; $pnO = explode(' ', self::normalizar((string)$otro['nombre']))[0] ?? '';
                    $parecido = $apN !== '' && $apN === $apO && $pnN !== '' && $pnN === $pnO;
                }
                if ($parecido) { $c[] = self::NOMBRE_PARECIDO; }
            }
        }
        if (self::mismoTelefono($nuevo['telefono'] ?? null, $otro['telefono'] ?? null)) { $c[] = self::TELEFONO; }
        return $c;
    }

    /** «a***@g***.com». Nunca devuelve el correo completo. */
    public static function enmascararCorreo(string $correo): string
    {
        $correo = trim($correo);
        $arroba = strrpos($correo, '@');
        if ($arroba === false || $arroba === 0) { return '***'; }
        $local = substr($correo, 0, $arroba); $dominio = substr($correo, $arroba + 1);
        $punto = strrpos($dominio, '.');
        $d = $punto === false ? mb_substr($dominio, 0, 1) . '***' : mb_substr($dominio, 0, 1) . '***' . substr($dominio, $punto);
        return mb_substr($local, 0, 1) . '***@' . $d;
    }

    /** «••••4567»: solo los últimos cuatro dígitos. */
    public static function telefonoParcial(?string $telefono): string
    {
        $d = self::digitos($telefono);
        return $d === '' ? '—' : '••••' . substr($d, -4);
    }
}
