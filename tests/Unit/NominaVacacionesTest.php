<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Services\Nomina\NominaVacaciones;

/** Valores calculados aparte con parámetros de ENSAYO (15 días por año, base 365). */
final class NominaVacacionesTest extends TestCase
{
    public function testDevengadasEsProporcionalAlServicioYRedondeaAlCentesimo():void
    {
        self::assertSame(4126,NominaVacaciones::devengadas(15,1004,365));// 15 × 1004 ÷ 365 = 41,2602…
        self::assertSame(1500,NominaVacaciones::devengadas(15,365,365));
        self::assertSame(0,NominaVacaciones::devengadas(15,0,365));
        self::assertSame(547500,NominaVacaciones::devengadas(15,365,0),'base 0 se trata como 1 (no divide por cero)');
    }
    public function testDiasEnVentanaCuentaSoloLoQueCaeDentroYEsInclusivo():void
    {
        self::assertSame(10,NominaVacaciones::diasEnVentana('2025-03-03','2025-03-12','2024-01-01','2026-09-30'));
        self::assertSame(1,NominaVacaciones::diasEnVentana('2025-03-03','2025-03-03','2024-01-01','2026-09-30'));
        self::assertSame(3,NominaVacaciones::diasEnVentana('2026-09-28','2026-10-05','2024-01-01','2026-09-30'),'recorta lo posterior al corte');
        self::assertSame(2,NominaVacaciones::diasEnVentana('2023-12-30','2024-01-02','2024-01-01','2026-09-30'),'recorta lo anterior al ingreso');
        self::assertSame(0,NominaVacaciones::diasEnVentana('2026-10-01','2026-10-05','2024-01-01','2026-09-30'));
    }
    public function testSaldoRestaLoTomadoYMuestraElExcesoComoAnticipado():void
    {
        $s=NominaVacaciones::saldo('2024-01-01','2026-09-30',[['desde'=>'2025-03-03','hasta'=>'2025-03-12']],15,365);
        self::assertSame(['dias_servicio'=>1004,'devengadas_cent'=>4126,'tomadas_cent'=>1000,'pendientes_cent'=>3126,'anticipadas_cent'=>0],$s);
        $s=NominaVacaciones::saldo('2024-01-01','2026-09-30',[['desde'=>'2025-01-01','hasta'=>'2025-02-19']],15,365);// 50 días
        self::assertSame(5000,$s['tomadas_cent']);self::assertSame(0,$s['pendientes_cent']);self::assertSame(874,$s['anticipadas_cent']);
    }
    public function testElCorteAnteriorAlIngresoSeRechaza():void
    {
        $this->expectException(\InvalidArgumentException::class);
        NominaVacaciones::saldo('2026-10-16','2026-10-01',[],15,365);
    }
}
