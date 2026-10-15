<?php
namespace Classes\Exportacion;

/**
 * Neutralización de fórmulas y normalización de texto para exportaciones tabulares (CWE-1236).
 * Una celda de TEXTO que empiece por = + - @ o por tabulador/retorno se prefija con una comilla simple en CSV; en XLSX el texto se
 * guarda siempre como cadena en línea (nunca como fórmula), por lo que no necesita prefijo. Los números tipados no se tocan.
 */
final class Neutralizacion
{
    /** Texto seguro para CSV: UTF-8 válido, sin bytes de control, sin disparadores de fórmula. */
    public static function textoCsv(mixed $valor): string
    {
        $t = self::limpio($valor);
        if ($t === '') { return $t; }
        // También el primer carácter visible tras espacios/tabuladores iniciales (algunos lectores recortan antes de evaluar).
        $visible = ltrim($t, " \t\r\n\x0B\f");
        if (in_array($t[0], ['=', '+', '-', '@', "\t", "\r", "\n", "\f", "\x0B"], true) || ($visible !== '' && in_array($visible[0], ['=', '+', '-', '@'], true))) { return "'" . $t; }
        return $t;
    }

    /** Texto para una celda XLSX: válido para XML 1.0 (se descartan los caracteres de control no permitidos). */
    public static function textoXml(mixed $valor): string
    {
        return self::limpio($valor);
    }

    private static function limpio(mixed $valor): string
    {
        if ($valor === null || is_array($valor) || is_object($valor) && !method_exists($valor, '__toString')) { return ''; }
        $t = (string)$valor;
        if (!mb_check_encoding($t, 'UTF-8')) { $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8'); }
        // Se conservan \t \n \r; se retiran NUL y el resto de controles C0 y DEL, inválidos en XML 1.0 y ruido en CSV.
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
    }

    /** Importe en centavos enteros → «12.34» sin pasar por coma flotante. */
    public static function dinero(int|string|null $centavos): string
    {
        if ($centavos === null || $centavos === '') { return ''; }
        $c = (int)$centavos;
        $signo = $c < 0 ? '-' : '';
        $c = abs($c);
        return sprintf('%s%d.%02d', $signo, intdiv($c, 100), $c % 100);
    }
}
