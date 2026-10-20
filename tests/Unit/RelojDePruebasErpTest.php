<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Los servicios ERP fechan con el reloj de la aplicación (America/Guayaquil, OperacionRepository::ahora()).
 * Una prueba que arme fechas con date() de PHP usa el reloj del servidor (UTC en CI): entre las 19:00 y las 24:00 locales
 * va un día adelantada y el servicio rechaza «fecha futura» o no encuentra lo que acaba de crear. Esta guarda impide reintroducirlo.
 */
final class RelojDePruebasErpTest extends TestCase
{
    public function testLasPruebasErpNoArmanFechasConElRelojDelServidor(): void
    {
        $raiz=dirname(__DIR__);$archivos=array_merge(glob($raiz.'/Integration/Erp*.php')?:[],glob($raiz.'/fixtures/sri/*.php')?:[]);
        self::assertNotEmpty($archivos);$infracciones=[];
        foreach($archivos as $f){
            foreach(file($f) as $n=>$linea){
                // date() de cualquier formato, con comillas simples o dobles y espacios, sin marca de tiempo o con time(): todas usan el reloj del servidor.
                if(preg_match('/(?<![\\w])(?<!->)(?<!::)date\\s*\\(\\s*(?:\'[^\']*\'|"[^"]*")\\s*(?:\\)|,\\s*time\\s*\\(\\s*\\)\\s*\\))/',$linea))$infracciones[]=basename($f).':'.($n+1);
            }
        }
        self::assertSame([],$infracciones,'Usa OperacionRepository::ahora() (hora de la aplicación) en lugar de date(): '.implode(', ',$infracciones));
    }

    public function testElDetectorReconoceLasVariantesDeDate(): void
    {
        $patron='/(?<![\\w])(?<!->)(?<!::)date\\s*\\(\\s*(?:\'[^\']*\'|"[^"]*")\\s*(?:\\)|,\\s*time\\s*\\(\\s*\\)\\s*\\))/';
        foreach(["date('Y-m-d')",'date("Y-m-d")',"date ('Y-m-d')","date( 'Y-m-d' )","date('Y-m-d', time())","['a'=>date('Y')]"] as $mala)self::assertSame(1,preg_match($patron,$mala),$mala);
        foreach(["date('Y-m-d H:i:s', strtotime(\$x.' -3 hours'))","\$o->date('Y')","A::date('Y')","update('x')"] as $buena)self::assertSame(0,preg_match($patron,$buena),$buena);
    }
}
