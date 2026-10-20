<?php
namespace Services\Nomina;

/**
 * Participación de trabajadores en las utilidades — cálculo puro en centavos. Los porcentajes y el tope vienen de parámetros registrados con su fuente.
 * Una parte se reparte según los días trabajados en el ejercicio y otra según días × cargas familiares (el factor de cada trabajador es el producto); el reparto es exacto (mayor resto, desempate por menor id).
 * Supuestos que valida el contador: sin cargas declaradas, la parte de cargas se suma a la de días; el tope se aplica al total de cada trabajador y su excedente
 * no se paga: queda registrado para que el contador decida su destino.
 */
final class NominaUtilidades
{
    public const FORMULA='utilidades-v1';

    /** @param array<int,int> $pesos id=>peso (≥0) @return array<int,int> id=>monto; la suma es exactamente $total (0 si no hay pesos) */
    public static function repartir(int $total,array $pesos):array
    {
        $suma=array_sum($pesos);$r=array_fill_keys(array_keys($pesos),0);
        if($total<=0||$suma<=0)return $r;
        $resto=[];$dado=0;
        foreach($pesos as $id=>$p){$r[$id]=intdiv($total*$p,$suma);$resto[$id]=($total*$p)%$suma;$dado+=$r[$id];}
        $falta=$total-$dado;
        uksort($resto,fn($a,$b)=>$resto[$b]<=>$resto[$a]?:$a<=>$b);
        foreach($resto as $id=>$_){if($falta<=0)break;if($pesos[$id]>0){$r[$id]++;$falta--;}}
        return $r;
    }

    /**
     * @param array<int,array{dias:int,cargas:int}> $trab id=>días trabajados y cargas
     * @return array{pozo_trabajo:int,pozo_cargas:int,cargas_a_trabajo:bool,lineas:array<int,array{dias:int,cargas:int,parte_trabajo:int,parte_cargas:int,excedente:int,monto:int}>,total:int,excedente:int}
     */
    public static function calcular(int $base,int $pbTrabajadores,int $pbCargas,array $trab,?int $tope):array
    {
        if($base<=0)throw new \InvalidArgumentException('No hay utilidad que repartir.');
        if($pbTrabajadores<0||$pbCargas<0||$pbTrabajadores+$pbCargas>10000)throw new \InvalidArgumentException('Los porcentajes de reparto no son válidos.');
        if(!$trab)throw new \InvalidArgumentException('No hay trabajadores con relación de dependencia en el ejercicio.');
        $pozoT=intdiv($base*$pbTrabajadores+5000,10000);$pozoC=intdiv($base*$pbCargas+5000,10000);
        $cargas=array_sum(array_column($trab,'cargas'));$aTrabajo=$cargas===0;
        if($aTrabajo){$pozoT+=$pozoC;$pozoC=0;}
        $porDias=self::repartir($pozoT,array_map(fn($x)=>$x['dias'],$trab));
        $porCargas=self::repartir($pozoC,array_map(fn($x)=>$x['dias']*$x['cargas'],$trab));// factor = días trabajados × cargas acreditadas
        $lineas=[];$total=0;$exc=0;
        foreach($trab as $id=>$x){
            $bruto=$porDias[$id]+$porCargas[$id];$monto=$tope===null?$bruto:min($bruto,$tope);$e=$bruto-$monto;
            $lineas[$id]=['dias'=>$x['dias'],'cargas'=>$x['cargas'],'parte_trabajo'=>$porDias[$id],'parte_cargas'=>$porCargas[$id],'excedente'=>$e,'monto'=>$monto];$total+=$monto;$exc+=$e;
        }
        return ['pozo_trabajo'=>$pozoT,'pozo_cargas'=>$pozoC,'cargas_a_trabajo'=>$aTrabajo,'lineas'=>$lineas,'total'=>$total,'excedente'=>$exc];
    }
}
