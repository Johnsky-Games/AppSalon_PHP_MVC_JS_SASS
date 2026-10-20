<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Services\Contabilidad\DepreciacionLineal as D;

/** Línea recta por meses completos con reparto acumulado. Todos los valores esperados están calculados a mano. */
final class DepreciacionLinealTest extends TestCase
{
    public function testCuotasCalculadasAManoParaNoventaMilEnSieteMeses():void
    {
        // Objetivos acumulados: 12857, 25714, 38571, 51429, 64286, 77143, 90000 (redondeo de 90000·k/7, mitad hacia arriba).
        $cuotas=[];for($k=1;$k<=7;$k++)$cuotas[]=D::cuota(90000,7,$k);
        self::assertSame([12857,12857,12857,12858,12857,12857,12857],$cuotas);
        self::assertSame(90000,array_sum($cuotas),'La suma de las cuotas es exactamente la base');
    }
    public function testLaSumaSiempreEsLaBaseYNingunaCuotaExcedeLoQueFalta():void
    {
        foreach([[1,1],[1,3],[2,3],[100,7],[99999,12],[123457,60],[1000001,600],[5,600]] as [$base,$vida]){
            $suma=0;for($k=1;$k<=$vida;$k++){$c=D::cuota($base,$vida,$k);self::assertGreaterThanOrEqual(0,$c);$suma+=$c;self::assertLessThanOrEqual($base,$suma);}
            self::assertSame($base,$suma,"base=$base vida=$vida");
        }
    }
    public function testMitadSeRedondeaHaciaArribaYFueraDeLaVidaSeRechaza():void
    {
        self::assertSame(1,D::objetivo(1,2,1),'0,5 → 1');self::assertSame(0,D::objetivo(1,3,1),'0,33 → 0');self::assertSame(1,D::objetivo(1,3,2),'0,67 → 1');
        self::assertSame(90000,D::objetivo(90000,7,99));
        $this->expectException(\InvalidArgumentException::class);D::cuota(90000,7,8);
    }
    public function testMesesFechasYResidual():void
    {
        self::assertSame(1,D::numeroDeMes('2026-01-01',2026,1));self::assertSame(13,D::numeroDeMes('2026-01-01',2027,1));self::assertSame(0,D::numeroDeMes('2026-02-01',2026,1));
        self::assertSame('2028-02-29',D::finDeMes(2028,2));self::assertSame('2026-02-28',D::finDeMes(2026,2));
        self::assertSame(10000,D::residual(100000,1000),'10 % de 1 000,00');self::assertSame(1,D::residual(5,1000),'0,5 centavo → 1');
        self::assertSame(1250,D::puntosBasicos('12.5'));self::assertSame(0,D::puntosBasicos('0'));self::assertNull(D::puntosBasicos('12.345'));self::assertNull(D::puntosBasicos('abc'));
    }
}
