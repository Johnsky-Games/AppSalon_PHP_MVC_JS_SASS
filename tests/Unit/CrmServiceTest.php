<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Repositories\CrmRepository;
use Services\CrmService;

/** Reglas puras del CRM: no consultan la base de datos (la conexión es un objeto mysqli sin conectar). */
final class CrmServiceTest extends TestCase
{
    private const FULL = ['ver' => true, 'analitica' => true, 'propios' => false, 'exportar' => true];
    private const RECEPCION = ['ver' => true, 'analitica' => false, 'propios' => false, 'exportar' => false];

    private function servicio(string $ahora = '2026-10-13 12:00:00'): CrmService
    {
        return new CrmService(new CrmRepository(mysqli_init()), new \DateTimeImmutable($ahora, new \DateTimeZone('America/Guayaquil')));
    }

    private function catalogos(): array
    {
        return ['servicios' => [['id' => 3, 'nombre' => 'Corte']], 'profesionales' => [['id' => 7, 'nombre' => 'Sofía', 'activo' => 1]]];
    }

    /** @return array<int,array{id:int,recencia:int,frecuencia:int,gasto:int}> */
    private function poblacion(int $n, callable $f): array
    {
        $r = [];
        for ($i = 1; $i <= $n; $i++) { $r[] = ['id' => $i] + $f($i); }
        return $r;
    }

    public function testHoyUsaZonaGuayaquilYNoUtc(): void
    {
        // 03:00 UTC del 14 de octubre es todavía el 13 a las 22:00 en Guayaquil (UTC-5).
        $s = new CrmService(new CrmRepository(mysqli_init()), new \DateTimeImmutable('2026-10-14 03:00:00', new \DateTimeZone('UTC')));
        $this->assertSame('2026-10-13', $s->hoy()->format('Y-m-d'));
        $this->assertSame('America/Guayaquil', $s->hoy()->getTimezone()->getName());
    }

    public function testRfmConMenosDeTreintaClientesNoCalculaNiInventa(): void
    {
        $r = CrmService::calcularRfm($this->poblacion(29, fn($i) => ['recencia' => $i, 'frecuencia' => $i, 'gasto' => $i * 100]), 90);
        $this->assertFalse($r['disponible']);
        $this->assertSame([], $r['vip_ids']);
        $this->assertSame([], $r['puntajes']);
        $this->assertNull($r['umbrales']);
        $this->assertSame(29, $r['poblacion']);
        $this->assertStringContainsString('29 clientes', $r['mensaje']);
        $this->assertStringContainsString('al menos 30', $r['mensaje']);
    }

    public function testRfmTercilesYVipCalculadosAMano(): void
    {
        // n=30. F=i, R=31-i días, M=i*1000 centavos. Cortes en las posiciones 10 y 20: ids 21..30 superan ambos cortes.
        $r = CrmService::calcularRfm($this->poblacion(30, fn($i) => ['recencia' => 31 - $i, 'frecuencia' => $i, 'gasto' => $i * 1000]), 90);
        $this->assertTrue($r['disponible']);
        $this->assertTrue($r['usa_gasto']);
        $this->assertSame(['r3' => 11, 'r2' => 21], $r['umbrales']['recencia']);
        $this->assertSame(['f3' => 20, 'f2' => 10], $r['umbrales']['frecuencia']);
        $this->assertSame(['m3' => 20000, 'm2' => 10000], $r['umbrales']['gasto']);
        $this->assertSame(range(21, 30), $r['vip_ids']);
        $this->assertSame([3, 3, 3], $r['puntajes'][30]);
        $this->assertSame([2, 2, 2], $r['puntajes'][15]);
        $this->assertSame([1, 1, 1], $r['puntajes'][1]);
        // Frontera exacta: el id 20 vale exactamente el corte y NO lo supera.
        $this->assertSame([2, 2, 2], $r['puntajes'][20]);
    }

    public function testRfmVipExigeEstarActivoAunConPuntajeMaximo(): void
    {
        $r = CrmService::calcularRfm($this->poblacion(30, fn($i) => ['recencia' => 31 - $i, 'frecuencia' => $i, 'gasto' => $i * 1000]), 5);
        $this->assertSame(range(26, 30), $r['vip_ids'], 'Solo quienes tienen recencia <= 5 días.');
    }

    public function testRfmEmpatesRecibenElMismoPuntajeYSinVariacionNadieEsVip(): void
    {
        $r = CrmService::calcularRfm($this->poblacion(40, fn($i) => ['recencia' => 10, 'frecuencia' => 2, 'gasto' => 500]), 90);
        $this->assertTrue($r['disponible']);
        $this->assertSame([], $r['vip_ids']);
        $this->assertSame(1, count(array_unique(array_map('json_encode', $r['puntajes']))), 'Todos los empatados reciben el mismo puntaje.');
    }

    public function testRfmSinNingunGastoOmiteLaDimensionDeGasto(): void
    {
        $r = CrmService::calcularRfm($this->poblacion(30, fn($i) => ['recencia' => 31 - $i, 'frecuencia' => $i, 'gasto' => 0]), 90);
        $this->assertFalse($r['usa_gasto']);
        $this->assertSame(range(21, 30), $r['vip_ids'], 'Sin gasto en nadie, VIP se decide por R y F.');
    }

    public function testRfmConGastoRequiereTambienM3(): void
    {
        // Mismo patrón R/F, pero el gasto de los ids 21..30 es bajo salvo el 30: solo él conserva M=3.
        $r = CrmService::calcularRfm($this->poblacion(30, fn($i) => ['recencia' => 31 - $i, 'frecuencia' => $i, 'gasto' => $i === 30 ? 90000 : 100]), 90);
        $this->assertTrue($r['usa_gasto']);
        $this->assertSame([30], $r['vip_ids']);
    }

    public function testFiltrosPorDefectoYValidosSeNormalizan(): void
    {
        $v = $this->servicio()->normalizarFiltros([], self::FULL, $this->catalogos());
        $this->assertSame([], $v['errores']);
        $f = $v['filtros'];
        $this->assertSame('2025-11-01', $f['desde'], 'Doce meses calendario terminando en el mes actual.');
        $this->assertSame('2026-10-13', $f['hasta']);
        $this->assertSame(90, $f['dias_activo']);
        $this->assertSame(25, $f['por_pagina']);
        $this->assertSame(1, $f['pagina']);
        $this->assertSame('nombre', $f['orden']);
        $v = $this->servicio()->normalizarFiltros(['q' => '  Ana  ', 'profesional_id' => '7', 'servicio_id' => '3', 'segmento' => 'vip', 'dias_activo' => '30', 'por_pagina' => '50', 'pagina' => '4', 'orden' => 'gasto', 'dir' => 'desc'], self::FULL, $this->catalogos());
        $this->assertSame([], $v['errores']);
        $this->assertSame(['Ana', 7, 3, 'vip', 30, 50, 4, 'gasto', 'desc'], [$v['filtros']['q'], $v['filtros']['profesional_id'], $v['filtros']['servicio_id'], $v['filtros']['segmento'], $v['filtros']['dias_activo'], $v['filtros']['por_pagina'], $v['filtros']['pagina'], $v['filtros']['orden'], $v['filtros']['dir']]);
    }

    /** @dataProvider entradasInvalidas */
    public function testEntradasInvalidasGeneranErrorYVuelvenAlValorSeguro(array $query, string $campo, mixed $esperado, string $fragmentoError): void
    {
        $v = $this->servicio()->normalizarFiltros($query, self::FULL, $this->catalogos());
        $this->assertNotEmpty($v['errores']);
        $this->assertStringContainsString($fragmentoError, implode(' | ', $v['errores']));
        $this->assertSame($esperado, $v['filtros'][$campo]);
    }

    public static function entradasInvalidas(): array
    {
        return [
            'fecha imposible' => [['desde' => '2026-02-31'], 'desde', '2025-11-01', 'fecha inicial'],
            'fecha con formato libre' => [['hasta' => '13/10/2026'], 'hasta', '2026-10-13', 'fecha final'],
            'periodo futuro' => [['hasta' => '2026-10-14'], 'hasta', '2026-10-13', 'posterior a hoy'],
            'desde mayor que hasta' => [['desde' => '2026-10-10', 'hasta' => '2026-09-01'], 'hasta', '2026-09-01', 'no puede ser posterior'],
            'periodo demasiado largo' => [['desde' => '2020-01-01', 'hasta' => '2026-10-13'], 'desde', '2023-10-13', 'no puede superar'],
            'dias activo bajo' => [['dias_activo' => '29'], 'dias_activo', 90, 'entre 30 y 365'],
            'dias activo alto' => [['dias_activo' => '366'], 'dias_activo', 90, 'entre 30 y 365'],
            'dias activo texto' => [['dias_activo' => 'abc'], 'dias_activo', 90, 'ventana de clientes activos'],
            'profesional inexistente' => [['profesional_id' => '99'], 'profesional_id', null, 'profesional'],
            'servicio inexistente' => [['servicio_id' => '99'], 'servicio_id', null, 'servicio'],
            'segmento libre' => [['segmento' => "x' OR 1=1 --"], 'segmento', null, 'segmento'],
            'orden fuera de lista blanca' => [['orden' => 'u.password'], 'orden', 'nombre', 'orden'],
            'tamano de pagina no permitido' => [['por_pagina' => '7'], 'por_pagina', 25, 'tamaño de página'],
            'tamano de pagina enorme' => [['por_pagina' => '100000'], 'por_pagina', 25, 'tamaño de página'],
            'pagina cero' => [['pagina' => '0'], 'pagina', 1, 'página'],
            'parametro en arreglo' => [['q' => ['a', 'b']], 'q', '', 'no es válido'],
            'busqueda demasiado larga' => [['q' => str_repeat('a', 81)], 'q', str_repeat('a', 80), 'hasta 80'],
            'consentimiento libre' => [['consentimiento' => 'tal vez'], 'consentimiento', null, 'consentimiento'],
            'membresia libre' => [['membresia' => 'vip'], 'membresia', null, 'membresía'],
        ];
    }

    public function testSinAnaliticaNoSePuedeFiltrarNiOrdenarPorVipNiGasto(): void
    {
        $v = $this->servicio()->normalizarFiltros(['segmento' => 'vip', 'orden' => 'gasto'], self::RECEPCION, $this->catalogos());
        $this->assertNull($v['filtros']['segmento']);
        $this->assertSame('nombre', $v['filtros']['orden']);
        $this->assertCount(2, $v['errores']);
        $v = $this->servicio()->normalizarFiltros(['segmento' => 'activo'], self::RECEPCION, $this->catalogos());
        $this->assertSame('activo', $v['filtros']['segmento']);
    }

    public function testControlesYCaracteresInvisiblesSeEliminanDeLaBusqueda(): void
    {
        $v = $this->servicio()->normalizarFiltros(['q' => "Ana\x00\n\tPérez"], self::FULL, $this->catalogos());
        $this->assertSame('Ana Pérez', $v['filtros']['q']);
    }

    public function testPaginacionTotalPaginasYLimites(): void
    {
        $p = CrmService::paginar(23, 3, 10);
        $this->assertSame([3, 3, 20, 21, 23], [$p['pagina'], $p['paginas'], $p['offset'], $p['primero'], $p['ultimo']]);
        $p = CrmService::paginar(23, 99, 10);
        $this->assertSame(3, $p['pagina'], 'Una página fuera de rango se recorta a la última.');
        $p = CrmService::paginar(0, 5, 25);
        $this->assertSame([1, 1, 0, 0, 0], [$p['pagina'], $p['paginas'], $p['offset'], $p['primero'], $p['ultimo']]);
        $p = CrmService::paginar(20, 2, 10);
        $this->assertSame(2, $p['paginas']);
        $this->assertSame(25, CrmService::paginar(5, 1, 7)['por_pagina'], 'Tamaño fuera de la lista blanca vuelve al predeterminado.');
    }

    public function testPorcentajeSinDenominadorEsNuloYNoCero(): void
    {
        $this->assertNull(CrmService::porcentaje(0, 0));
        $this->assertSame(0.0, CrmService::porcentaje(0, 5));
        $this->assertSame(33.3, CrmService::porcentaje(1, 3));
    }

    public function testDineroFormateaCentavosYNegativos(): void
    {
        $this->assertSame('$12.50', CrmService::dinero(1250));
        $this->assertSame('-$5.00', CrmService::dinero(-500));
        $this->assertSame('$0.00', CrmService::dinero(0));
    }

    public function testModoSegunCapacidades(): void
    {
        $this->assertSame('completo', CrmService::modo(self::RECEPCION));
        $this->assertSame('completo', CrmService::modo(self::FULL));
        $this->assertSame('analitica', CrmService::modo(['ver' => false, 'analitica' => true, 'propios' => false, 'exportar' => false]));
        $this->assertSame('propios', CrmService::modo(['ver' => false, 'analitica' => false, 'propios' => true, 'exportar' => false]));
        $this->assertNull(CrmService::modo(['ver' => false, 'analitica' => false, 'propios' => false, 'exportar' => false]));
    }
}
