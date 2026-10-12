<?php
namespace Services;
/** Conversión decimal exacta: nunca aceptar exponentes, negativos o redondeos silenciosos. */
final class ValoresExactos
{
    public static function decimal(mixed $valor, int $escala = 2, bool $cero = false): int
    {
        if (!is_scalar($valor) || !in_array($escala, [2, 3], true)) {
            throw new \InvalidArgumentException('Cantidad inválida.');
        }
        $texto = trim((string)$valor);
        if (!preg_match('/^(\d{1,7})(?:\.(\d{1,' . $escala . '}))?$/D', $texto, $m)) {
            throw new \InvalidArgumentException('Use números positivos con hasta ' . $escala . ' decimales.');
        }
        $cantidad = ((int)$m[1]) * (10 ** $escala) + (int)str_pad($m[2] ?? '', $escala, '0');
        if (!$cero && $cantidad === 0) {
            throw new \InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }
        return $cantidad;
    }
    public static function texto(mixed $valor, int $max): string
    {
        if (!is_string($valor) || trim($valor) === '' || mb_strlen(trim($valor)) > $max) {
            throw new \InvalidArgumentException('Complete los campos de texto sin exceder su longitud.');
        }
        return trim($valor);
    }
    public static function clave(mixed $valor, int $actor): string
    {
        if (!is_string($valor) || !preg_match('/^[a-zA-Z0-9_-]{16,90}$/D', $valor)) {
            throw new \InvalidArgumentException('La solicitud necesita un identificador válido.');
        }
        return $actor . ':' . $valor;
    }
}
