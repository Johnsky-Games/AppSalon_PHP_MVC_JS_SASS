<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Services\ValoresExactos;
final class ValoresExactosTest extends TestCase
{
    public function testConversionExactaSinRedondeos(): void
    {
        self::assertSame(101,ValoresExactos::decimal('1.01'));
        self::assertSame(1001,ValoresExactos::decimal('1.001',3));
        self::assertSame(0,ValoresExactos::decimal('0',2,true));
        self::assertSame(1230,ValoresExactos::decimal('1.23',3));
    }
    public function testRechazaPrecisionExcesiva(): void
    {
        $this->expectException(\InvalidArgumentException::class); ValoresExactos::decimal('1.001');
    }
    public function testRechazaNotacionExponencial(): void
    {
        $this->expectException(\InvalidArgumentException::class); ValoresExactos::decimal('1e4');
    }
    public function testRechazaNegativos(): void
    {
        $this->expectException(\InvalidArgumentException::class); ValoresExactos::decimal('-0.01');
    }
    public function testComisionRedondeaCentavosConEnteros(): void
    {
        self::assertSame(101,\Services\ComisionService::calcular(403,2500));
        self::assertSame(1,\Services\ComisionService::calcular(1,5000));
        self::assertSame(0,\Services\ComisionService::calcular(100,0));
    }

}
