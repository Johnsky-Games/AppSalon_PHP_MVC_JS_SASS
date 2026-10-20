<?php
namespace Services\Nomina;

/**
 * Saldo de vacaciones — cálculo puro en centésimas de día. Los días por año y la base del año vienen de parámetros registrados con su fuente (sin cifra de fábrica).
 * Supuestos a validar con el abogado laboral: los días de vacaciones se cuentan en días calendario (incluyen fines de semana y feriados dentro del rango aprobado)
 * y se devengan en proporción a los días de servicio; el exceso tomado por anticipado no se descuenta solo (queda visible como tomado por anticipado).
 */
final class NominaVacaciones
{
    /** Días devengados (centésimas) proporcionales al servicio; es la misma regla que usa el finiquito. */
    public static function devengadas(int $diasAnuales,int $diasServicio,int $baseAnio):int
    {
        $baseAnio=max(1,$baseAnio);
        return intdiv($diasAnuales*100*max(0,$diasServicio)+intdiv($baseAnio,2),$baseAnio);
    }

    /** Días calendario de un rango inclusive (YYYY-MM-DD) que caen dentro de la ventana [ini, fin]; 0 si no se cruzan. */
    public static function diasEnVentana(string $desde,string $hasta,string $ini,string $fin):int
    {
        $a=max($desde,$ini);$b=min($hasta,$fin);
        if($b<$a)return 0;
        return (int)(new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->days+1;
    }

    /**
     * @param list<array{desde:string,hasta:string}> $aprobadas vacaciones aprobadas del colaborador
     * @return array{dias_servicio:int,devengadas_cent:int,tomadas_cent:int,pendientes_cent:int,anticipadas_cent:int}
     */
    public static function saldo(string $ingreso,string $corte,array $aprobadas,int $diasAnuales,int $baseAnio):array
    {
        if($corte<$ingreso)throw new \InvalidArgumentException('La fecha de corte es anterior al ingreso.');
        $dias=(int)(new \DateTimeImmutable($ingreso))->diff(new \DateTimeImmutable($corte))->days+1;
        $dev=self::devengadas($diasAnuales,$dias,$baseAnio);
        $tom=0;foreach($aprobadas as $a)$tom+=self::diasEnVentana($a['desde'],$a['hasta'],$ingreso,$corte)*100;
        return ['dias_servicio'=>$dias,'devengadas_cent'=>$dev,'tomadas_cent'=>$tom,'pendientes_cent'=>max(0,$dev-$tom),'anticipadas_cent'=>max(0,$tom-$dev)];
    }
}
