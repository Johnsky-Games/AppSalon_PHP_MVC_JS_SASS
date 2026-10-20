<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Services\Nomina\NominaUtilidades;

final class NominaUtilidadesTest extends TestCase
{
    public function testElRepartoEsExactoPorMayorRestoYDeterministaEnLosEmpates():void
    {
        $r=NominaUtilidades::repartir(100000,[1=>365,2=>184]);self::assertSame([1=>66485,2=>33515],$r);self::assertSame(100000,array_sum($r));
        $r=NominaUtilidades::repartir(100,[1=>1,2=>1,3=>1]);self::assertSame([1=>34,2=>33,3=>33],$r,'El centavo sobrante va al menor id en un empate');
        self::assertSame([1=>0,2=>0],NominaUtilidades::repartir(500,[1=>0,2=>0]),'Sin pesos no se reparte');
        self::assertSame([1=>0,2=>7],NominaUtilidades::repartir(7,[1=>0,2=>5]),'Peso cero no recibe nada');
        mt_srand(7);for($i=0;$i<200;$i++){$pesos=[];for($k=1;$k<=mt_rand(1,9);$k++)$pesos[$k]=mt_rand(0,400);$t=mt_rand(0,10000000);if(array_sum($pesos)>0)self::assertSame($t,array_sum(NominaUtilidades::repartir($t,$pesos)),'suma exacta');}
    }
    public function testDiezPorDiasYCincoPorCargasConLaFormulaDeEnsayo():void
    {
        $r=NominaUtilidades::calcular(1000000,1000,500,[1=>['dias'=>365,'cargas'=>2],2=>['dias'=>184,'cargas'=>0]],null);
        self::assertSame(100000,$r['pozo_trabajo']);self::assertSame(50000,$r['pozo_cargas']);self::assertFalse($r['cargas_a_trabajo']);
        self::assertSame(116485,$r['lineas'][1]['monto']);self::assertSame(33515,$r['lineas'][2]['monto']);self::assertSame(150000,$r['total']);self::assertSame(0,$r['excedente']);
    }
    public function testSinCargasLaParteDeCargasSeSumaALosDias():void
    {
        $r=NominaUtilidades::calcular(1000000,1000,500,[1=>['dias'=>100,'cargas'=>0],2=>['dias'=>100,'cargas'=>0]],null);
        self::assertTrue($r['cargas_a_trabajo']);self::assertSame(150000,$r['pozo_trabajo']);self::assertSame(0,$r['pozo_cargas']);self::assertSame(75000,$r['lineas'][1]['monto']);self::assertSame(150000,$r['total']);
    }
    public function testElTopeDejaUnExcedenteQueNoSePagaYLaSumaSigueCuadrando():void
    {
        $r=NominaUtilidades::calcular(1000000,1000,500,[1=>['dias'=>365,'cargas'=>2],2=>['dias'=>184,'cargas'=>0]],96400);
        self::assertSame(96400,$r['lineas'][1]['monto']);self::assertSame(20085,$r['lineas'][1]['excedente']);self::assertSame(33515,$r['lineas'][2]['monto']);
        self::assertSame(96400+33515,$r['total']);self::assertSame(20085,$r['excedente']);self::assertSame(150000,$r['total']+$r['excedente']);
    }
    /** @dataProvider invalidas */
    public function testEntradasInvalidas(int $base,int $pt,int $pc,array $trab):void
    {
        $this->expectException(\InvalidArgumentException::class);NominaUtilidades::calcular($base,$pt,$pc,$trab,null);
    }
    public static function invalidas():array
    {
        $t=[1=>['dias'=>10,'cargas'=>0]];
        return ['sin utilidad'=>[0,1000,500,$t],'pérdida'=>[-5,1000,500,$t],'porcentajes que superan 100'=>[100,9000,2000,$t],'sin trabajadores'=>[100,1000,500,[]]];
    }
}
