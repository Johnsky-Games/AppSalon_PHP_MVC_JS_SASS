<?php
namespace Services\Fiscal;

/** Ficha técnica SRI offline 2.34, tabla 1. Nunca convierte RUC o claves en float. */
final class SriClaveAcceso
{
    public static function crear(string $fecha,string $documento,string $ruc,string $ambiente,string $serie,string $secuencial,string $codigoNumerico):string
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
        if(!$d||$d->format('Y-m-d')!==$fecha)throw new \InvalidArgumentException('Fecha de emisión no válida.');
        if(!in_array($documento,['01','04'],true)||!in_array($ambiente,['1','2'],true))throw new \InvalidArgumentException('Documento o ambiente SRI no admitido.');
        foreach([[$ruc,13],[$serie,6],[$secuencial,9],[$codigoNumerico,8]] as [$v,$n])if(!preg_match('/^[0-9]{'.$n.'}$/D',$v))throw new \InvalidArgumentException('Longitud de clave SRI incorrecta.');
        if(substr($serie,0,3)==='000'||substr($serie,3,3)==='000'||$secuencial==='000000000')throw new \InvalidArgumentException('Serie y secuencia deben ser positivas.');
        $base=$d->format('dmY').$documento.$ruc.$ambiente.$serie.$secuencial.$codigoNumerico.'1';
        return $base.self::digito($base);
    }

    public static function digito(string $base):int
    {
        if(!preg_match('/^[0-9]+$/D',$base))throw new \InvalidArgumentException('La clave debe ser numérica.');
        $suma=0;$factor=2;
        for($i=strlen($base)-1;$i>=0;$i--){$suma+=(int)$base[$i]*$factor;$factor=$factor===7?2:$factor+1;}
        $n=11-($suma%11);return $n===11?0:($n===10?1:$n);
    }

    public static function validar(string $clave):void
    {
        if(!preg_match('/^[0-9]{49}$/D',$clave)||self::digito(substr($clave,0,48))!==(int)$clave[48])throw new \InvalidArgumentException('Clave de acceso SRI no válida.');
        $fecha=substr($clave,4,4).'-'.substr($clave,2,2).'-'.substr($clave,0,2);
        if(!hash_equals($clave,self::crear($fecha,substr($clave,8,2),substr($clave,10,13),$clave[23],substr($clave,24,6),substr($clave,30,9),substr($clave,39,8))))throw new \InvalidArgumentException('Clave de acceso SRI no válida.');
    }
}
