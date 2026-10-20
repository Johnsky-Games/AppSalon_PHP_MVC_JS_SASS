<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Services\Nomina\NominaRenta;

/** Mecánica de la retención de renta con una tabla de ENSAYO (no oficial): 0 % hasta 1 000; 10 % hasta 2 000; 100 + 20 % en adelante (USD). */
final class NominaRentaTest extends TestCase
{
    private function tabla():array {return NominaRenta::validarTramos([['desde'=>0,'hasta'=>100000,'impuesto_base'=>0,'excedente_pb'=>0],['desde'=>100000,'hasta'=>200000,'impuesto_base'=>0,'excedente_pb'=>1000],['desde'=>200000,'hasta'=>null,'impuesto_base'=>10000,'excedente_pb'=>2000]]);}
    private function entrada(array $x=[]):array {return $x+['anio_mes_restantes'=>12,'gravado_ytd'=>0,'gravado_mes'=>120000,'iess_ytd'=>0,'iess_mes'=>11340,'retenido_ytd'=>0,'otros_ingresos'=>0,'retenido_otros'=>0,'gastos'=>0,'tope_gastos'=>null,'otras_deducciones'=>0,'rebaja'=>0];}

    public function testElImpuestoRespetaLosLimitesDeCadaTramoYRedondeaMedioCentavoHaciaArriba():void
    {
        $t=$this->tabla();
        foreach([[0,0],[-5,0],[100000,0],[100001,0],[150000,5000],[200000,10000],[200001,10000],[300000,30000]] as [$base,$esperado])self::assertSame($esperado,NominaRenta::impuestoAnual($base,$t),'base '.$base);
        self::assertSame(1,NominaRenta::impuestoAnual(100005,$t),'0,5 centavos se redondea hacia arriba');
    }
    public function testLaProyeccionAnualYLaRetencionDelMesSonExactas():void
    {
        $r=NominaRenta::retencion($this->entrada(),$this->tabla());
        self::assertSame(1440000,$r['ingresos_proyectados']);self::assertSame(136080,$r['iess_proyectado']);self::assertSame(1303920,$r['base_anual']);
        self::assertSame(230784,$r['impuesto_tabla']);self::assertSame(230784,$r['pendiente']);self::assertSame(19232,$r['retencion_mes']);
    }
    public function testLasRetencionesDelAnioSumanExactamenteElImpuestoCausadoYElUltimoMesCierraLaDiferencia():void
    {
        $g=0;$i=0;$ret=0;$t=$this->tabla();
        for($mes=1;$mes<=12;$mes++){
            $r=NominaRenta::retencion($this->entrada(['anio_mes_restantes'=>13-$mes,'gravado_ytd'=>$g,'iess_ytd'=>$i,'retenido_ytd'=>$ret]),$t);
            $ret+=$r['retencion_mes'];$g+=120000;$i+=11340;
        }
        self::assertSame(230784,$ret,'Con ingresos constantes, la suma de las doce retenciones es el impuesto del año');
    }
    public function testUnCambioDeIngresoSeAbsorbeEnLosMesesQueQuedanSinRetenerDeMas():void
    {
        $t=$this->tabla();$g=0;$i=0;$ret=0;
        for($mes=1;$mes<=12;$mes++){
            $mensual=$mes>=7?200000:120000;$iess=intdiv($mensual*945+5000,10000);
            $r=NominaRenta::retencion($this->entrada(['anio_mes_restantes'=>13-$mes,'gravado_ytd'=>$g,'gravado_mes'=>$mensual,'iess_ytd'=>$i,'iess_mes'=>$iess,'retenido_ytd'=>$ret]),$t);
            $ret+=$r['retencion_mes'];$g+=$mensual;$i+=$iess;
        }
        $base=$g-$i;self::assertSame(NominaRenta::impuestoAnual($base,$t),$ret,'El año cierra exactamente en el impuesto de lo realmente percibido');
    }
    public function testLosGastosSeLimitanAlTopeRegistradoYSinTopeNoSeAplican():void
    {
        $t=$this->tabla();
        $r=NominaRenta::retencion($this->entrada(['gastos'=>300000,'tope_gastos'=>250000]),$t);
        self::assertSame(250000,$r['gastos_aplicados']);self::assertTrue($r['gastos_limitados_al_tope']);self::assertSame(1303920-250000,$r['base_anual']);
        $r=NominaRenta::retencion($this->entrada(['gastos'=>200000,'tope_gastos'=>250000]),$t);self::assertSame(200000,$r['gastos_aplicados']);self::assertFalse($r['gastos_limitados_al_tope']);
        $this->expectException(\InvalidArgumentException::class);NominaRenta::retencion($this->entrada(['gastos'=>1]),$t);
    }
    public function testLaRebajaYLoYaRetenidoNuncaDanRetencionNegativa():void
    {
        $t=$this->tabla();
        self::assertSame(0,NominaRenta::retencion($this->entrada(['rebaja'=>99999999]),$t)['retencion_mes']);
        self::assertSame(0,NominaRenta::retencion($this->entrada(['retenido_ytd'=>999999]),$t)['pendiente']);
        $r=NominaRenta::retencion($this->entrada(['rebaja'=>30784]),$t);self::assertSame(200000,$r['impuesto_causado']);
        $r=NominaRenta::retencion($this->entrada(['retenido_otros'=>100000]),$t);self::assertSame(130784,$r['pendiente'],'Lo retenido por otros empleadores se descuenta');
    }
    /** @dataProvider tablasInvalidas */
    public function testUnaTablaMalFormadaSeRechaza(array $tramos,string $texto):void
    {
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessageMatches('/'.preg_quote($texto,'/').'/u');NominaRenta::validarTramos($tramos);
    }
    public static function tablasInvalidas():array
    {
        $a=['desde'=>0,'hasta'=>100,'impuesto_base'=>0,'excedente_pb'=>0];$b=['desde'=>100,'hasta'=>null,'impuesto_base'=>0,'excedente_pb'=>500];
        return [
            'vacía'=>[[], 'de 1 a 30'],
            'no empieza en cero'=>[[['desde'=>5]+$a,$b],'primero en 0'],
            'hueco entre tramos'=>[[$a,['desde'=>150]+$b],'donde termina el anterior'],
            'último con límite'=>[[$a,['hasta'=>500]+$b],'último tramo'],
            'intermedio sin límite'=>[[['hasta'=>null]+$a,$b],'límite superior'],
            'porcentaje mayor a 100'=>[[$a,['excedente_pb'=>10001]+$b],'porcentaje'],
            'impuesto que baja'=>[[['impuesto_base'=>50,'hasta'=>100]+$a,['impuesto_base'=>10]+$b],'no puede bajar'],
            'no entero'=>[[['desde'=>'0']+$a,$b],'enteros'],
        ];
    }
}
