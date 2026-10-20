<?php
namespace Services\Nomina;

/**
 * Validación de forma de la identificación del trabajador. Es una comprobación de estructura, no una consulta al Registro Civil ni al SRI:
 *   · cédula ecuatoriana: 10 dígitos, provincia 01–24 o 30, tercer dígito menor que 6 y dígito verificador del algoritmo público de módulo 10;
 *   · pasaporte: 5 a 20 caracteres alfanuméricos.
 */
final class IdentificacionTributaria
{
    public static function normalizar(string $tipo,string $numero):string
    {
        $n=trim($numero);
        return $tipo==='pasaporte'?strtoupper($n):$n;
    }

    public static function cedulaValida(string $c):bool
    {
        if(!preg_match('/^\d{10}$/D',$c))return false;
        $prov=(int)substr($c,0,2);if(!(($prov>=1&&$prov<=24)||$prov===30)||(int)$c[2]>5)return false;
        $suma=0;foreach([2,1,2,1,2,1,2,1,2] as $i=>$coef){$p=(int)$c[$i]*$coef;$suma+=$p>9?$p-9:$p;}
        return ((10-($suma%10))%10)===(int)$c[9];
    }

    /** Devuelve el mensaje de error o null si la identificación tiene una forma válida. */
    public static function error(string $tipo,string $numero):?string
    {
        if($tipo==='cedula')return self::cedulaValida($numero)?null:'La cédula no es válida (10 dígitos, provincia, tercer dígito y dígito verificador).';
        if($tipo==='pasaporte')return preg_match('/^[A-Z0-9]{5,20}$/D',$numero)?null:'El pasaporte debe tener de 5 a 20 letras o números, sin espacios.';
        return 'Tipo de identificación no válido.';
    }

    /** Muestra solo los últimos 4 caracteres. */
    public static function enmascarar(string $numero):string
    {
        $n=strlen($numero);return $n<=4?str_repeat('•',$n):str_repeat('•',$n-4).substr($numero,-4);
    }
}
