<?php
namespace Services;

/**
 * Aritmética del cobro en CENTAVOS ENTEROS (sin float). Funciones puras: no tocan la base de datos.
 *
 *   saldo      = total − Σ pagos confirmados + Σ devoluciones
 *   disponible = max(0, saldo − Σ transferencias pendientes de verificación)    ← lo que aún se puede registrar
 *
 * Estado financiero de la cita (independiente del estado de la atención):
 *   pagado        neto ≥ total (> 0)
 *   por_verificar hay transferencias declaradas sin verificar (y la cita no está ya pagada)
 *   reembolsado   se cobró algo y todo fue devuelto
 *   parcial       neto > 0 pero menor que el total
 *   pendiente     nada cobrado
 * donde neto = Σ pagos confirmados − Σ devoluciones.
 */
final class CobroCalculo
{
    public const ESTADOS = ['pendiente', 'parcial', 'por_verificar', 'pagado', 'reembolsado'];
    public const METODOS = ['efectivo', 'transferencia'];
    public const VERIFICACIONES = ['pendiente_verificacion', 'confirmado', 'rechazado'];

    /** Decimal de base de datos («15.25», 15.25, «15») → centavos exactos. Rechaza lo que no sea un importe no negativo con ≤ 2 decimales. */
    public static function centavos(mixed $valor): int
    {
        if (is_int($valor)) { return $valor * 100; }
        if (is_float($valor)) { $valor = number_format($valor, 2, '.', ''); }
        if (!is_string($valor) || !preg_match('/^(\d{1,9})(?:\.(\d{1,2})0*)?$/D', trim($valor), $m)) {
            throw new \InvalidArgumentException('Importe inválido.');
        }
        return ((int)$m[1]) * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }

    /** Importe escrito por una persona («12.5», «12,50» no se acepta) → centavos > 0. */
    public static function centavosEntrada(mixed $valor): int
    {
        if (!is_string($valor) && !is_int($valor)) { throw new \InvalidArgumentException('Escriba un importe válido, por ejemplo 12.50.'); }
        $t = trim((string)$valor);
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $t)) { throw new \InvalidArgumentException('Escriba un importe válido en dólares, con punto y hasta dos decimales (por ejemplo 12.50).'); }
        $c = self::centavos($t);
        if ($c <= 0) { throw new \InvalidArgumentException('El importe debe ser mayor que cero.'); }
        return $c;
    }

    /** «1234.5» (centavos 123450) → «1,234.50». */
    public static function formato(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $c = abs($centavos);
        return $signo . number_format(intdiv($c, 100), 0, '.', ',') . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Sin separador de miles: para campos de formulario y exportaciones. */
    public static function plano(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $c = abs($centavos);
        return $signo . intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function saldo(int $total, int $cobrado, int $devuelto): int
    {
        return $total - $cobrado + $devuelto;
    }

    public static function disponible(int $total, int $cobrado, int $devuelto, int $pendienteVerificacion): int
    {
        return max(0, self::saldo($total, $cobrado, $devuelto) - $pendienteVerificacion);
    }

    public static function estado(int $total, int $cobrado, int $devuelto, int $pendienteVerificacion): string
    {
        $neto = $cobrado - $devuelto;
        if ($total > 0 && $neto >= $total) { return 'pagado'; }
        if ($pendienteVerificacion > 0) { return 'por_verificar'; }
        if ($cobrado > 0 && $neto <= 0) { return 'reembolsado'; }
        if ($neto > 0) { return 'parcial'; }
        return 'pendiente';
    }

    /** Efectivo: lo recibido nunca puede ser menor que lo aplicado; el cambio es la diferencia y NO es ingreso. */
    public static function cambio(int $recibido, int $aplicado): int
    {
        if ($aplicado <= 0) { throw new \InvalidArgumentException('El importe aplicado debe ser mayor que cero.'); }
        if ($recibido < $aplicado) { throw new \InvalidArgumentException('El efectivo recibido no alcanza para el importe a aplicar.'); }
        return $recibido - $aplicado;
    }

    /** Lo que todavía se puede devolver de un pago: lo cobrado menos lo ya devuelto. */
    public static function devolvible(int $montoPago, int $yaDevuelto): int
    {
        return max(0, $montoPago - $yaDevuelto);
    }

    /** base × parte / total redondeado half-up en enteros (comisión proporcional a una devolución parcial). */
    public static function proporcional(int $base, int $parte, int $total): int
    {
        if ($total <= 0 || $parte < 0 || $parte > $total || $base < 0) { throw new \InvalidArgumentException('Proporción fuera de rango.'); }
        return intdiv($base * $parte * 2 + $total, 2 * $total);
    }

    /** Referencia de transferencia → forma normalizada (única en base de datos) o excepción. */
    public static function referenciaNormalizada(mixed $valor): array
    {
        if (!is_string($valor)) { throw new \InvalidArgumentException('Indique la referencia de la transferencia.'); }
        $t = trim(preg_replace('/\s+/u', ' ', $valor) ?? '');
        if (!mb_check_encoding($t, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $t)) { throw new \InvalidArgumentException('La referencia contiene caracteres no válidos.'); }
        $len = mb_strlen($t);
        if ($len < 4 || $len > 80) { throw new \InvalidArgumentException('La referencia debe tener entre 4 y 80 caracteres (número de comprobante o de operación).'); }
        $clave = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $t));
        if (strlen($clave) < 4) { throw new \InvalidArgumentException('La referencia debe incluir al menos 4 letras o números.'); }
        return ['visible' => $t, 'unica' => substr($clave, 0, 80)];
    }
}
