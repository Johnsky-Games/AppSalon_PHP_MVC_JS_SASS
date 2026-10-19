<?php
namespace Classes;

/** El dump no ejecuta triggers arbitrarios: se reponen solo definiciones del código versionado. */
final class ProteccionesRestauradas
{
    public static function aplicar(\mysqli $db):int
    {
        $tablas=$db->query("SHOW TABLES LIKE 'migraciones'");
        if(!$tablas||!$tablas->num_rows)return 0;
        $archivos=[];
        foreach(glob(__DIR__.'/../database/migrations/*.sql')?:[] as $archivo){
            $nombre=basename($archivo,'.sql');if(str_ends_with($nombre,'_rollback'))continue;
            $archivos[$nombre]=$archivo;
        }
        $aplicadas=$db->query('SELECT migracion FROM migraciones ORDER BY migracion')->fetch_all(MYSQLI_ASSOC);
        $total=0;
        foreach($aplicadas as $fila){
            $nombre=$fila['migracion'];
            if(!isset($archivos[$nombre]))throw new \RuntimeException('El código no contiene todas las migraciones del respaldo. Restaura con una versión compatible.');
            $sql=(string)file_get_contents($archivos[$nombre]);
            $esperados=preg_match_all('/^CREATE\s+TRIGGER\s+/mi',$sql);
            if(!$esperados)continue;
            // Las protecciones actuales son SIGNAL simple o IF ... END IF.
            // Una forma nueva obliga a ampliar este lector; nunca se omite en silencio.
            preg_match_all('/^(CREATE\s+TRIGGER\s+([a-z0-9_]+)\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+[a-z0-9_]+\s+FOR\s+EACH\s+ROW\s+(?:IF\b.*?END\s+IF|SIGNAL\b[^\r\n]*?)\s*;)/mis',$sql,$triggers,PREG_SET_ORDER);
            if(count($triggers)!==$esperados)throw new \RuntimeException('Una protección de la migración '.$nombre.' requiere un lector de restauración actualizado.');
            foreach($triggers as $trigger){
                $ident=$trigger[2];
                if(!$db->query('DROP TRIGGER IF EXISTS `'.$ident.'`')||!$db->query($trigger[1]))throw new \RuntimeException('No se pudo reponer la protección '.$ident.': '.$db->error);
                $total++;
            }
        }
        return $total;
    }
}
