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
                if(preg_match("/(?<![\\w])(?<!->)(?<!::)date\\(\\s*'(Y-m-d|Y-m-01|n|Y)'\\s*\\)/",$linea))$infracciones[]=basename($f).':'.($n+1);
            }
        }
        self::assertSame([],$infracciones,'Usa OperacionRepository::ahora() (hora de la aplicación) en lugar de date(): '.implode(', ',$infracciones));
    }
}
