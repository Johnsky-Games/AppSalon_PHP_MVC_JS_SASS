<?php
namespace Services\Contabilidad;

/**
 * Depreciación lineal por meses completos, en centavos enteros y sin flotantes. POLÍTICA CONTABLE: la vida útil, el valor residual y el mes de inicio son
 * decisiones de la empresa (con su fuente), no reglas tributarias.
 *
 * Método de reparto acumulado: el acumulado objetivo tras k meses es round(base × k / vida) con redondeo comercial (mitad hacia arriba); la cuota del mes k es
 * objetivo(k) − objetivo(k−1). Así ninguna cuota excede lo que falta, la suma de las cuotas es EXACTAMENTE la base y no hay centavos huérfanos al final.
 * Se deprecia por meses completos: el activo no se deprecia en el mes de su baja.
 */
final class DepreciacionLineal
{
    /** Acumulado de la parte depreciable tras $k meses (0 ≤ k; con k ≥ vida es la base completa). */
    public static function objetivo(int $base,int $vida,int $k):int
    {
        if($base<0||$vida<1||$k<0)throw new \InvalidArgumentException('Parámetros de depreciación no válidos.');
        if($k>=$vida)return $base;
        return intdiv($base*$k*2+$vida,2*$vida);
    }
    /** Cuota del mes k-ésimo (1 ≤ k ≤ vida). */
    public static function cuota(int $base,int $vida,int $k):int
    {
        if($k<1||$k>$vida)throw new \InvalidArgumentException('El mes de depreciación queda fuera de la vida útil.');
        return self::objetivo($base,$vida,$k)-self::objetivo($base,$vida,$k-1);
    }
    /** Número de mes (1 = el mes de inicio) del período AAAA-MM respecto del inicio AAAA-MM-01; ≤ 0 si el período es anterior al inicio. */
    public static function numeroDeMes(string $inicio,int $anio,int $mes):int
    {
        if(!preg_match('/^(\d{4})-(\d{2})-01$/D',$inicio,$m))throw new \InvalidArgumentException('El inicio de depreciación es el primer día de un mes.');
        return ($anio*12+$mes)-((int)$m[1]*12+(int)$m[2])+1;
    }
    /** Último día del mes (AAAA-MM-DD). */
    public static function finDeMes(int $anio,int $mes):string
    {
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01',$anio,$mes)))->modify('last day of this month')->format('Y-m-d');
    }
    /** Valor residual en centavos para un porcentaje en puntos básicos (1 pb = 0,01 %), con redondeo comercial. */
    public static function residual(int $costo,int $bp):int
    {
        if($costo<0||$bp<0||$bp>9999)throw new \InvalidArgumentException('Valor residual no válido.');
        return intdiv($costo*$bp*2+10000,20000);
    }
    /** Puntos básicos de un porcentaje decimal «12.34» (hasta 2 decimales) o null si no es válido. */
    public static function puntosBasicos(string $pct):?int
    {
        return preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?$/D',trim($pct),$m)?(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0'):null;
    }
}
