<?php
namespace Services\Fiscal;

use Services\{ErpCalculo,ErpException};

/**
 * Importes de una nota de crédito sobre una línea de la factura de origen. Enteros: centavos y milésimas.
 * El descuento se reparte con redondeo acumulado y la última porción absorbe el resto, de modo que la suma de las notas
 * de una línea nunca supere el total facturado de esa línea.
 */
final class SriNotaCreditoCalculo
{
    /**
     * @param array{cantidad:int,precio:int,descuento:int,impuesto_pb:int} $linea  valores de la factura de origen
     * @param int $previa  milésimas ya acreditadas por otras notas de la línea
     * @param int $ahora   milésimas que acredita esta nota
     * @param int $totalPrevio  total (base+IVA) ya acreditado de la línea
     * @return array{cantidad:int,precio:int,descuento:int,base:int,iva:int,total:int,resto_centavos:int}
     */
    public static function linea(array $linea,int $previa,int $ahora,int $totalPrevio):array
    {
        $Q=$linea['cantidad'];$p=$linea['precio'];$D=$linea['descuento'];$pb=$linea['impuesto_pb'];
        if($ahora<1||$previa<0||$previa+$ahora>$Q)throw new ErpException('La cantidad acreditada supera la cantidad facturada de la línea.',409);
        $original=ErpCalculo::linea($Q,$p,$D,$pb);$restante=$original['total']-$totalPrevio;
        if($restante<1)throw new ErpException('La línea ya fue acreditada por completo.',409);
        $acumulado=static fn(int $q):int=>intdiv(2*$D*$q+$Q,2*$Q);
        $reparto=$acumulado($previa+$ahora)-$acumulado($previa);$bruto=intdiv($ahora*$p+500,1000);$reparto=max(0,min($reparto,$bruto));
        $c=ErpCalculo::linea($ahora,$p,$reparto,$pb);
        if($previa+$ahora===$Q){
            // Última porción: ajusta el descuento (±5 centavos) para quedar lo más cerca posible por debajo del resto facturado.
            $mejor=null;
            for($delta=-5;$delta<=5;$delta++){
                $d=$reparto+$delta;if($d<0||$d>$bruto)continue;$t=ErpCalculo::linea($ahora,$p,$d,$pb);
                if($t['total']>$restante||$t['total']<1)continue;
                if($mejor===null||$t['total']>$mejor['total']||($t['total']===$mejor['total']&&abs($delta)<abs($mejor['delta'])))$mejor=$t+['delta'=>$delta,'d'=>$d];
            }
            if($mejor===null)throw new ErpException('No se pudo conciliar el redondeo fiscal de la última porción de la línea.',409);
            $c=$mejor;$reparto=$mejor['d'];
        }elseif($c['total']>$restante){
            throw new ErpException('La nota superaría el total facturado de la línea.',409);
        }
        if($c['total']<1)throw new ErpException('El importe de la nota de crédito debe ser positivo.',409);
        $base=$c['subtotal']-$reparto;
        return ['cantidad'=>$ahora,'precio'=>$p,'descuento'=>$reparto,'base'=>$base,'iva'=>$c['impuesto'],'total'=>$c['total'],'resto_centavos'=>$restante-$c['total']];
    }
}
