<?php
namespace Tests\Unit;
use Classes\PeriodoFiltro;
use PHPUnit\Framework\TestCase;
final class PeriodoFiltroTest extends TestCase
{
    public function testSemanaCalendarioYMesBisiesto():void
    {
        $hoy=new \DateTimeImmutable('2024-02-29 10:00:00',new \DateTimeZone('America/Guayaquil'));
        $s=PeriodoFiltro::aplicar(['periodo'=>'semana'],ahora:$hoy);
        self::assertSame(['2024-02-26','2024-03-03'],[$s['desde'],$s['hasta']]);
        $m=PeriodoFiltro::aplicar(['periodo'=>'mes'],ahora:$hoy);
        self::assertSame(['2024-02-01','2024-02-29'],[$m['desde'],$m['hasta']]);
        $m=PeriodoFiltro::aplicar(['periodo'=>'mes'],historico:true,ahora:$hoy->modify('-10 days'));
        self::assertSame('2024-02-19',$m['hasta']);
    }
    public function testMesAnteriorEnEneroYDomingoYPersonalizado():void
    {
        $hoy=new \DateTimeImmutable('2023-01-01',new \DateTimeZone('America/Guayaquil'));
        $s=PeriodoFiltro::aplicar(['periodo'=>'mes_anterior'],'inicio','fin',ahora:$hoy);
        self::assertSame(['2022-12-01','2022-12-31'],[$s['inicio'],$s['fin']]);
        $s=PeriodoFiltro::aplicar(['periodo'=>'semana'],ahora:$hoy);
        self::assertSame(['2022-12-26','2023-01-01'],[$s['desde'],$s['hasta']]);
        $q=['periodo'=>'personalizado','desde'=>'2020-01-01','q'=>'Ana','pagina'=>'2'];
        self::assertSame($q,PeriodoFiltro::aplicar($q));
        self::assertSame(['periodo'=>['mes']],PeriodoFiltro::aplicar(['periodo'=>['mes']]));
    }
}
