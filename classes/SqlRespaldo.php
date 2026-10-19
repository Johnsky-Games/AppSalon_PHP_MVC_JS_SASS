<?php
namespace Classes;

/** Inspección lineal de estructura SQL: evita límites de PCRE al restaurar cargas cifradas grandes. */
final class SqlRespaldo
{
    public static function estructura(string $sql):string
    {
        $salida='';$comilla=null;$identificador=false;$longitud=strlen($sql);
        for($i=0;$i<$longitud;$i++){
            $c=$sql[$i];$siguiente=$i+1<$longitud?$sql[$i+1]:'';
            if($comilla!==null){
                if($c==='\\'){if($i+1<$longitud)$i++;continue;}
                if($c===$comilla){if($siguiente===$comilla){$i++;continue;}$comilla=null;}
                continue;
            }
            if($identificador){
                $salida.=$c;
                if($c==='`'){if($siguiente==='`'){$salida.=$sql[++$i];}else $identificador=false;}
                continue;
            }
            if($c==='`'){$identificador=true;$salida.=$c;continue;}
            if($c==="'"||$c==='"'){$comilla=$c;$salida.="''";continue;}
            $salida.=$c;
        }
        if($comilla!==null||$identificador)throw new \RuntimeException('Sentencia no admitida: literal o identificador sin cerrar.');
        return $salida;
    }
}
