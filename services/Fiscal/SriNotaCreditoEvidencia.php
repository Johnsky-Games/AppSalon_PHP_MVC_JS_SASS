<?php
namespace Services\Fiscal;

/**
 * Mide, sobre el XML firmado de una nota de crédito (solo lectura), cuánto se aparta cada línea de la aritmética exacta:
 *   · IVA frente a round(baseImponible × tarifa) y
 *   · bruto (precioTotalSinImpuesto + descuento) frente a round(cantidad × precioUnitario).
 * Sirve para saber, con documentos que el SRI ya procesó, si una desviación de 1 centavo fue aceptada o rechazada. No interpreta ninguna regla del SRI.
 */
final class SriNotaCreditoEvidencia
{
    /** Centavos de un decimal «12.34» (exactamente 2 decimales) o null si no lo es. */
    private static function centavos(?string $v):?int
    {
        return $v!==null&&preg_match('/^(\d{1,9})\.(\d{2})$/D',trim($v),$m)?(int)$m[1]*100+(int)$m[2]:null;
    }
    /** Unidades con exactamente $dec decimales (cantidad 3 en esta aplicación, hasta 6 en el esquema) como entero escalado, o null. */
    private static function escalado(?string $v,int $dec):?int
    {
        if($v===null||!preg_match('/^(\d{1,9})\.(\d{1,6})$/D',trim($v),$m))return null;
        $f=str_pad($m[2],$dec,'0');return strlen($m[2])>$dec&&rtrim(substr($m[2],$dec),'0')!==''?null:(int)$m[1]*(10**$dec)+(int)substr($f,0,$dec);
    }

    /**
     * @return array{lineas:int,desviacion_iva:int,desviacion_detalle:int,no_evaluables:int}  desviaciones máximas en valor absoluto, en centavos
     */
    public static function medir(string $xml):array
    {
        $doc=new \DOMDocument();
        if(!@$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new \InvalidArgumentException('El XML de la nota no es legible.');
        $x=new \DOMXPath($doc);$val=fn(\DOMNode $n,string $ruta)=>($r=$x->query($ruta,$n))&&$r->length?trim($r->item(0)->textContent):null;
        $res=['lineas'=>0,'desviacion_iva'=>0,'desviacion_detalle'=>0,'no_evaluables'=>0];
        foreach($x->query('//detalle') as $d){
            $res['lineas']++;
            $base=self::centavos($val($d,'precioTotalSinImpuesto'));$desc=self::centavos($val($d,'descuento'));$precio=self::centavos($val($d,'precioUnitario'));$q=self::escalado($val($d,'cantidad'),3);
            $imp=$x->query('impuestos/impuesto',$d);$ok=true;
            foreach($imp as $i){
                $b=self::centavos($val($i,'baseImponible'));$iva=self::centavos($val($i,'valor'));$t=$val($i,'tarifa');
                if($b===null||$iva===null||$t===null||!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D',$t,$m)){$ok=false;continue;}
                $pb=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
                $res['desviacion_iva']=max($res['desviacion_iva'],abs($iva-intdiv($b*$pb+5000,10000)));
            }
            if($base!==null&&$desc!==null&&$precio!==null&&$q!==null){
                $prod=$q*$precio;
                if(is_float($prod))$ok=false;else $res['desviacion_detalle']=max($res['desviacion_detalle'],abs(($base+$desc)-intdiv($prod+500,1000)));
            }else $ok=false;
            if(!$ok)$res['no_evaluables']++;
        }
        return $res;
    }
}
