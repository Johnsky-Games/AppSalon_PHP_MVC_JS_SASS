<?php
namespace Services;
/** Precios antes de impuesto; redondeo acumulado por línea en enteros. */
final class ErpCalculo
{
    public static function linea(int $cantidad,int $precio,int $descuento,int $impuesto):array
    {
        if($cantidad<1||$cantidad>999999999||$precio<0||$precio>99999999||$descuento<0||$impuesto<0||$impuesto>10000)throw new ErpException('Cantidad, precio o impuesto fuera de rango.');
        $bruto=intdiv($cantidad*$precio+500,1000);
        if($bruto>99999999||$descuento>$bruto)throw new ErpException('El descuento o total está fuera de rango.');
        $base=$bruto-$descuento;$iva=intdiv($base*$impuesto+5000,10000);
        if($base+$iva>99999999)throw new ErpException('El total supera el límite permitido.');
        return ['subtotal'=>$bruto,'descuento'=>$descuento,'impuesto'=>$iva,'total'=>$base+$iva];
    }
}
