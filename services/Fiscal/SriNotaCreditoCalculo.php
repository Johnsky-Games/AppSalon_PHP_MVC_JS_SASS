<?php
namespace Services\Fiscal;

use Services\{ErpCalculo,ErpException};

/**
 * Importes de una nota de crédito sobre una línea de la factura de origen. Enteros: centavos y milésimas.
 *
 * Reparto ACUMULADO. Se calcula lo que debería llevar acreditado la línea tras acreditar X milésimas (bruto, descuento, base e IVA, cada uno
 * redondeado una sola vez sobre el acumulado) y cada nota acredita la diferencia entre el objetivo de después y lo ya acreditado. Así:
 *  - lo acreditado nunca supera lo facturado en cantidad, bruto, descuento, base, IVA ni total (las funciones acumuladas son crecientes);
 *  - al acreditar toda la línea la suma es EXACTAMENTE la de la factura, sin ajustar descuentos ni dejar remanentes;
 *  - el error de redondeo no se acumula entre notas.
 *
 * Restricción de representación (no eliminable): el redondeo no es aditivo, de modo que el IVA de una nota puede diferir en ±1 centavo de
 * round(base × tarifa) y su bruto en ±1 centavo de round(cantidad × precio). Esa desviación se mide en cada nota ('desviacion_iva',
 * 'desviacion_detalle'); el servicio fiscal solo la admite hasta la tolerancia registrada como parámetro (por omisión, ninguna).
 */
final class SriNotaCreditoCalculo
{
    /** Objetivo acumulado de la línea de origen tras acreditar $q milésimas. */
    public static function objetivo(array $linea,int $q):array
    {
        $Q=$linea['cantidad'];$p=$linea['precio'];$D=$linea['descuento'];$pb=$linea['impuesto_pb'];
        $bruto=intdiv($q*$p+500,1000);$descuento=min($D===0?0:intdiv(2*$D*$q+$Q,2*$Q),$bruto);
        $base=$bruto-$descuento;$iva=intdiv($base*$pb+5000,10000);
        return ['bruto'=>$bruto,'descuento'=>$descuento,'base'=>$base,'iva'=>$iva,'total'=>$base+$iva];
    }

    /**
     * @param array{cantidad:int,precio:int,descuento:int,impuesto_pb:int} $linea  valores de la factura de origen
     * @param int $previa  milésimas ya acreditadas por otras notas de la línea
     * @param int $ahora   milésimas que acredita esta nota
     * @param int $totalPrevio  total (base+IVA) ya acreditado de la línea
     * @param array{base:int,iva:int}|null $acreditado  base e IVA realmente acreditados por las notas previas; si se omite se supone el objetivo acumulado
     * @return array{cantidad:int,precio:int,descuento:int,bruto:int,base:int,iva:int,total:int,resto_centavos:int,desviacion_detalle:int,desviacion_iva:int}
     */
    public static function linea(array $linea,int $previa,int $ahora,int $totalPrevio,?array $acreditado=null):array
    {
        $Q=$linea['cantidad'];$p=$linea['precio'];$D=$linea['descuento'];$pb=$linea['impuesto_pb'];
        if($ahora<1||$previa<0||$previa+$ahora>$Q)throw new ErpException('La cantidad acreditada supera la cantidad facturada de la línea.',409);
        $original=ErpCalculo::linea($Q,$p,$D,$pb);$restante=$original['total']-$totalPrevio;
        if($restante<1)throw new ErpException('La línea ya fue acreditada por completo.',409);
        $antes=self::objetivo($linea,$previa);$despues=self::objetivo($linea,$previa+$ahora);
        $base=$despues['base']-($acreditado['base']??$antes['base']);$iva=$despues['iva']-($acreditado['iva']??$antes['iva']);$descuento=$despues['descuento']-$antes['descuento'];
        if($base<0||$iva<0||$descuento<0)throw new ErpException('No se puede repartir el redondeo fiscal de esta línea entre las notas ya emitidas. Revisa la línea (auditoría de notas de crédito) antes de acreditarla.',409);
        $total=$base+$iva;
        if($total<1)throw new ErpException('El importe de la nota de crédito debe ser positivo.',409);
        if($total>$restante)throw new ErpException('La nota superaría el total facturado de la línea.',409);
        $bruto=$base+$descuento;
        return [
            'cantidad'=>$ahora,'precio'=>$p,'descuento'=>$descuento,'bruto'=>$bruto,'base'=>$base,'iva'=>$iva,'total'=>$total,'resto_centavos'=>$restante-$total,
            // Diferencia entre lo que lleva el documento y lo que daría calcularlo aislado (detalle: cantidad × precio; impuesto: base × tarifa).
            'desviacion_detalle'=>$bruto-intdiv($ahora*$p+500,1000),'desviacion_iva'=>$iva-intdiv($base*$pb+5000,10000),
        ];
    }

    /**
     * Auditoría de una línea ya acreditada. $notas: [['cantidad'=>milésimas,'base'=>c,'iva'=>c], ...] en orden de emisión.
     * Estado frente al objetivo acumulado:
     *  - 'conforme': lo acreditado coincide con el objetivo (bajo el reparto actual no hay otra posibilidad);
     *  - 'excedida': alguna dimensión (base, IVA o total) supera lo facturado;
     *  - 'con_remanente': línea completa en cantidad que no suma lo facturado (importe fiscal no acreditable);
     *  - 'agotada': el total facturado se agotó sin completar la cantidad;
     *  - 'desviada': parcial cuyos acumulados no coinciden con el objetivo (las notas siguientes absorberían la diferencia).
     */
    public static function auditar(array $linea,array $notas):array
    {
        $q=0;$base=0;$iva=0;$maxDev=0;
        foreach($notas as $n){
            $q+=$n['cantidad'];$base+=$n['base'];$iva+=$n['iva'];
            $maxDev=max($maxDev,abs($n['iva']-intdiv($n['base']*$linea['impuesto_pb']+5000,10000)));
        }
        $orig=ErpCalculo::linea($linea['cantidad'],$linea['precio'],$linea['descuento'],$linea['impuesto_pb']);$origBase=$orig['subtotal']-$linea['descuento'];
        $o=self::objetivo($linea,min($q,$linea['cantidad']));$completa=$q===$linea['cantidad'];$total=$base+$iva;
        if($base>$origBase||$iva>$orig['impuesto']||$total>$orig['total'])$estado='excedida';
        elseif($base===$o['base']&&$iva===$o['iva'])$estado='conforme';
        elseif($completa)$estado='con_remanente';
        elseif($total===$orig['total'])$estado='agotada';
        else $estado='desviada';
        return ['estado'=>$estado,'completa'=>$completa,'cantidad'=>$q,'base'=>$base,'iva'=>$iva,'total'=>$total,'objetivo'=>$o,'dif_base'=>$base-$o['base'],'dif_iva'=>$iva-$o['iva'],'dif_total'=>$total-$o['total'],'desviacion_iva_maxima'=>$maxDev];
    }
}
