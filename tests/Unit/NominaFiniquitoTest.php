<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Services\Nomina\NominaFiniquito;

/** Mecánica del finiquito con parámetros de ENSAYO (no oficiales): 15 días/año sobre 365; desahucio 25 %; indemnización 3 meses hasta 3 años, luego 1 por año con máximo 25. */
final class NominaFiniquitoTest extends TestCase
{
    private function p():array {return ['vacaciones.dias_anuales'=>15,'vacaciones.dias_base_anio'=>365,'desahucio.pb'=>2500,'despido.meses_hasta_umbral'=>3,'despido.umbral_anios'=>3,'despido.meses_por_anio'=>1,'despido.max_meses'=>25];}
    private function e(array $x=[]):array {return $x+['base'=>60000,'dias_servicio'=>730,'causa'=>'renuncia','desahucio'=>false,'vacaciones_tomadas_cent'=>0,'d13'=>0,'d14'=>0,'otros_ingresos'=>0,'descuentos'=>0];}

    public function testVacacionesSonProporcionalesAlServicioYSeValoranABaseEntreTreinta():void
    {
        $r=NominaFiniquito::calcular($this->e(['vacaciones_tomadas_cent'=>1000]),$this->p());
        self::assertSame(3000,$r['vacaciones_devengadas_cent'],'15 días × 2 años');self::assertSame(2000,$r['vacaciones_pendientes_cent']);self::assertSame(40000,$r['vacaciones_centavos'],'20 días × 20,00');
        $r=NominaFiniquito::calcular($this->e(['dias_servicio'=>1004,'vacaciones_tomadas_cent'=>1500]),$this->p());self::assertSame(4126,$r['vacaciones_devengadas_cent']);self::assertSame(52520,$r['vacaciones_centavos']);
        self::assertSame(0,NominaFiniquito::calcular($this->e(['vacaciones_tomadas_cent'=>999999]),$this->p())['vacaciones_centavos'],'Tomar más de lo devengado no genera valor negativo');
    }
    public function testElDesahuciosSumaUnPorcentajePorAnioConLaFraccionComoAnioCompleto():void
    {
        self::assertSame(30000,NominaFiniquito::calcular($this->e(['desahucio'=>true]),$this->p())['desahucio_centavos'],'25 % × 600,00 × 2 años');
        $r=NominaFiniquito::calcular($this->e(['desahucio'=>true,'dias_servicio'=>731]),$this->p());self::assertSame(3,$r['anios_computados']);self::assertSame(45000,$r['desahucio_centavos']);
        self::assertSame(0,NominaFiniquito::calcular($this->e(),$this->p())['desahucio_centavos']);
    }
    public function testLaIndemnizacionPorDespidoRespetaElUmbralYElMaximo():void
    {
        $r=fn(int $dias)=>NominaFiniquito::calcular($this->e(['causa'=>'despido_intempestivo','dias_servicio'=>$dias]),$this->p());
        self::assertSame(3,$r(730)['meses_indemnizacion']);self::assertSame(180000,$r(730)['indemnizacion_centavos']);
        self::assertSame(3,$r(1095)['meses_indemnizacion'],'Exactamente tres años sigue en el mínimo');
        self::assertSame(4,$r(1100)['meses_indemnizacion'],'Pasado el umbral: un mes por año (la fracción cuenta como año)');self::assertSame(240000,$r(1100)['indemnizacion_centavos']);
        self::assertSame(25,$r(365*40)['meses_indemnizacion'],'Tope');
        self::assertSame(0,NominaFiniquito::calcular($this->e(['causa'=>'renuncia']),$this->p())['indemnizacion_centavos']);
    }
    public function testElTotalSumaLosComponentesYNuncaEsNegativo():void
    {
        $r=NominaFiniquito::calcular($this->e(['causa'=>'despido_intempestivo','desahucio'=>true,'d13'=>10000,'d14'=>8034,'otros_ingresos'=>1000,'descuentos'=>2500,'dias_servicio'=>1004,'vacaciones_tomadas_cent'=>1500]),$this->p());
        self::assertSame(10000+8034+52520+45000+180000+1000-2500,$r['total_centavos']);
        $this->expectException(\InvalidArgumentException::class);NominaFiniquito::calcular($this->e(['descuentos'=>99999999]),$this->p());
    }
    /** @dataProvider entradasInvalidas */
    public function testEntradasInvalidasSeRechazan(array $cambio):void
    {
        $this->expectException(\InvalidArgumentException::class);NominaFiniquito::calcular($this->e($cambio),$this->p());
    }
    public static function entradasInvalidas():array
    {
        return ['sin servicio'=>[['dias_servicio'=>0]],'causa inventada'=>[['causa'=>'otra_cosa']],'desahucio con fin de plazo'=>[['causa'=>'fin_plazo','desahucio'=>true]],'desahucio con visto bueno'=>[['causa'=>'visto_bueno','desahucio'=>true]]];
    }
}
