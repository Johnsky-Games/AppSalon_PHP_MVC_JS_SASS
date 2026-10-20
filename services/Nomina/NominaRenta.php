<?php
namespace Services\Nomina;

/**
 * Retención mensual del impuesto a la renta de relación de dependencia (cálculo puro, en centavos, sin cifras legales).
 * La tabla progresiva de cada año y el tope de gastos personales los registra una persona autorizada con su fuente oficial; aquí solo se aplica la mecánica:
 *   base anual proyectada = ingresos gravados del año (ya pagados + mes × meses restantes + otros empleadores) − aportes personales al IESS − gastos − otras deducciones
 *   impuesto causado      = impuesto de la tabla sobre esa base − rebaja declarada
 *   retención del mes     = (causado − retenido en el año) / meses restantes, redondeada; el último mes cierra exactamente lo pendiente.
 * Supuesto a validar con el contador: los décimos y los fondos de reserva no forman parte de la base (no se pasan a este cálculo).
 */
final class NominaRenta
{
    public const FORMULA='renta-v1';

    /** @param array<int,array{desde:int,hasta:?int,impuesto_base:int,excedente_pb:int}> $t @return list<array{desde:int,hasta:?int,impuesto_base:int,excedente_pb:int}> */
    public static function validarTramos(array $t):array
    {
        if(!array_is_list($t)||count($t)<1||count($t)>30)throw new \InvalidArgumentException('La tabla lleva de 1 a 30 tramos.');
        $r=[];$previoHasta=0;$previoImpuesto=-1;
        foreach($t as $i=>$x){
            foreach(['desde','impuesto_base','excedente_pb'] as $k)if(!isset($x[$k])||!is_int($x[$k]))throw new \InvalidArgumentException('Tramo '.($i+1).': valores enteros obligatorios.');
            $hasta=$x['hasta']??null;if($hasta!==null&&!is_int($hasta))throw new \InvalidArgumentException('Tramo '.($i+1).': el límite superior debe ser entero o vacío.');
            if($x['desde']!==$previoHasta)throw new \InvalidArgumentException('Tramo '.($i+1).': debe comenzar donde termina el anterior ('.$previoHasta.') y el primero en 0.');
            if($x['desde']<0||$x['impuesto_base']<0||$x['excedente_pb']<0||$x['excedente_pb']>10000)throw new \InvalidArgumentException('Tramo '.($i+1).': importes negativos o porcentaje fuera de 0-100.');
            if($x['impuesto_base']<$previoImpuesto)throw new \InvalidArgumentException('Tramo '.($i+1).': el impuesto de la fracción no puede bajar respecto del tramo anterior.');
            $ultimo=$i===count($t)-1;
            if($ultimo&&$hasta!==null)throw new \InvalidArgumentException('El último tramo no lleva límite superior ("en adelante").');
            if(!$ultimo&&($hasta===null||$hasta<=$x['desde']))throw new \InvalidArgumentException('Tramo '.($i+1).': el límite superior debe ser mayor que el inferior.');
            $r[]=['desde'=>$x['desde'],'hasta'=>$hasta,'impuesto_base'=>$x['impuesto_base'],'excedente_pb'=>$x['excedente_pb']];
            $previoHasta=(int)$hasta;$previoImpuesto=$x['impuesto_base'];
        }
        return $r;
    }

    /** Impuesto anual sobre la base: fracción básica + porcentaje sobre el exceso del tramo en que cae (base en (desde, hasta]). */
    public static function impuestoAnual(int $base,array $tramos):int
    {
        if($base<=0)return 0;$t=$tramos[0];
        foreach($tramos as $x){if($base>$x['desde'])$t=$x;}
        return $t['impuesto_base']+intdiv(($base-$t['desde'])*$t['excedente_pb']+5000,10000);
    }

    /**
     * @param array{anio_mes_restantes:int,gravado_ytd:int,gravado_mes:int,iess_ytd:int,iess_mes:int,retenido_ytd:int,otros_ingresos:int,retenido_otros:int,gastos:int,tope_gastos:?int,otras_deducciones:int,rebaja:int} $in
     * @return array<string,int|bool>
     */
    public static function retencion(array $in,array $tramos):array
    {
        $n=max(1,$in['anio_mes_restantes']);
        $gastosDeclarados=$in['gastos'];$gastos=$gastosDeclarados;$limitado=false;
        if($gastosDeclarados>0){
            if($in['tope_gastos']===null)throw new \InvalidArgumentException('Registra el tope de gastos personales de la tabla vigente para aplicar los gastos declarados.');
            if($gastosDeclarados>$in['tope_gastos']){$gastos=$in['tope_gastos'];$limitado=true;}
        }
        $ingresos=$in['gravado_ytd']+$in['gravado_mes']*$n+$in['otros_ingresos'];
        $iess=$in['iess_ytd']+$in['iess_mes']*$n;
        $base=max(0,$ingresos-$iess-$gastos-$in['otras_deducciones']);
        $impuesto=self::impuestoAnual($base,$tramos);
        $causado=max(0,$impuesto-$in['rebaja']);
        $pendiente=max(0,$causado-$in['retenido_ytd']-$in['retenido_otros']);
        $mes=$n===1?$pendiente:intdiv($pendiente+intdiv($n,2),$n);
        return ['ingresos_proyectados'=>$ingresos,'iess_proyectado'=>$iess,'gastos_aplicados'=>$gastos,'gastos_limitados_al_tope'=>$limitado,'base_anual'=>$base,'impuesto_tabla'=>$impuesto,'impuesto_causado'=>$causado,'pendiente'=>$pendiente,'meses_restantes'=>$n,'retencion_mes'=>$mes];
    }
}
