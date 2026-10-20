<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Services\ErpSuscripcionService;
final class ErpSuscripcionCalendarioTest extends TestCase
{
    /** @dataProvider periodos */
    public function testAnclaOriginalYFinExclusivo(string $inicio,string $hoy,string $desde,string $hasta):void
    {self::assertSame(['desde'=>$desde,'hasta'=>$hasta],ErpSuscripcionService::periodo($inicio,$hoy));}
    public static function periodos():array
    {
        return [
            ['2026-01-31','2026-02-27','2026-01-31','2026-02-28'],
            ['2026-01-31','2026-02-28','2026-02-28','2026-03-31'],
            ['2026-01-31','2026-03-30','2026-02-28','2026-03-31'],
            ['2026-01-31','2026-03-31','2026-03-31','2026-04-30'],
            ['2024-01-31','2024-02-29','2024-02-29','2024-03-31'],
            ['2024-02-29','2025-02-28','2025-02-28','2025-03-29'],
            ['2026-12-31','2027-01-31','2027-01-31','2027-02-28'],
            ['2026-03-15','2026-03-01','2026-03-15','2026-04-15'],
        ];
    }
}
