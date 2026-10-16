<?php
namespace Tests\Integration;

require_once __DIR__ . '/R5CajaFixture.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\OperacionRepository;
use Repositories\ReporteRepository;
use Services\CobroCitaService;
use Services\RegularizacionAtencionService;
use Services\ReporteService;

/**
 * Las TRES fechas de una atención regularizada y dónde se usa cada una (docs/R5_REGULARIZACION.md §3). Pruebas del integrador (NO independientes):
 *   · fecha PROGRAMADA original  → reportes de programación (y la columna «Fecha programada»);
 *   · fecha EFECTIVA de atención → reportes de servicios realizados;
 *   · fecha REAL del movimiento  → reportes y pantallas financieras (cobros y devoluciones), Finanzas/caja_movimientos.
 * Ninguna se presenta como otra: la programada nunca se etiqueta como fecha real del servicio.
 */
final class R5TresFechasIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $gerente; private int $finanzas; private int $cliente; private int $prof;
    private int $cita; private string $programada; private string $efectiva; private string $hoy;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->gerente = R5CajaFixture::usuario($this->db, 'gerente');
        $this->finanzas = R5CajaFixture::usuario($this->db, 'finanzas');
        $this->cliente = R5CajaFixture::usuario($this->db, 'cliente');
        $this->prof = R5CajaFixture::profesional($this->db, 1000);
        $zona = new \DateTimeZone('America/Guayaquil');
        $this->hoy = (new \DateTimeImmutable('now', $zona))->format('Y-m-d');
        $this->programada = (new \DateTimeImmutable('-10 days', $zona))->format('Y-m-d');
        $this->efectiva = (new \DateTimeImmutable('-8 days', $zona))->format('Y-m-d');
        // Cita programada hace 10 días, atendida (según la regularización) hace 8, cobrada y parcialmente devuelta HOY.
        $this->cita = R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'confirmada', $this->programada);
        (new RegularizacionAtencionService(new OperacionRepository($this->db)))->regularizar($this->gerente, [
            'cita_id' => $this->cita, 'motivo' => 'El cliente se atendió dos días después de lo reservado', 'efectiva' => $this->efectiva . ' 12:00', 'idem_key' => R5CajaFixture::clave()]);
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE r FROM citas_regularizaciones r JOIN citas c ON c.id=r.cita_id WHERE c.usuarioId={$this->cliente}");
    }

    private function cobrarYDevolver(): array
    {
        $svc = new CobroCitaService(new OperacionRepository($this->db));
        $cobro = $svc->cobrar($this->finanzas, ['cita_id' => $this->cita, 'metodo' => 'efectivo', 'monto' => '40.00', 'recibido' => '40.00', 'idem_key' => R5CajaFixture::clave()]);
        $dev = $svc->devolver($this->finanzas, ['pago_id' => $cobro['pago_id'], 'monto' => '10.00', 'motivo' => 'Ajuste acordado con la clienta', 'idem_key' => R5CajaFixture::clave()]);
        return [$cobro, $dev];
    }

    /** @return list<int> */
    private function idsDeFilas(array $filas): array
    {
        return array_values(array_unique(array_map(static fn($f) => (int)$f['cita_id'], $filas)));
    }

    public function testLosReportesDeProgramacionUsanLaFechaOriginal(): void
    {
        $repo = new ReporteRepository();
        $this->assertContains($this->cita, $this->idsDeFilas($repo->obtenerFilasReservasEnRango($this->programada, $this->programada, null, null, 'programacion')));
        $this->assertNotContains($this->cita, $this->idsDeFilas($repo->obtenerFilasReservasEnRango($this->efectiva, $this->efectiva, null, null, 'programacion')), 'la efectiva no mueve la programación');
        // El valor histórico «servicio» sigue aceptándose pero NO se presenta como fecha del servicio: es la programada.
        $v = (new ReporteService($repo))->validarYNormalizarFiltros(['fecha_inicio' => $this->programada, 'fecha_fin' => $this->hoy, 'criterio_fecha' => 'servicio']);
        $this->assertSame('programacion', $v['criterio_fecha']);
        $v = (new ReporteService($repo))->validarYNormalizarFiltros(['fecha_inicio' => $this->programada, 'fecha_fin' => $this->hoy]);
        $this->assertSame('programacion', $v['criterio_fecha'], 'el valor por omisión es la fecha programada');
    }

    public function testLosReportesDeServiciosRealizadosUsanLaFechaEfectiva(): void
    {
        $repo = new ReporteRepository();
        $filas = $repo->obtenerFilasReservasEnRango($this->efectiva, $this->efectiva, null, null, 'realizado');
        $this->assertContains($this->cita, $this->idsDeFilas($filas), 'figura el día en que el servicio ocurrió');
        $fila = array_values(array_filter($filas, fn($f) => (int)$f['cita_id'] === $this->cita))[0];
        $this->assertSame($this->efectiva, $fila['fecha'], 'la fecha de agrupación es la efectiva');
        $this->assertSame($this->programada, $fila['fecha_programada'], 'la programada se conserva aparte');
        $this->assertSame($this->efectiva . ' 12:00:00', $fila['atencion_efectiva_at']);
        $this->assertNotContains($this->cita, $this->idsDeFilas($repo->obtenerFilasReservasEnRango($this->programada, $this->programada, null, null, 'realizado')), 'la programada no es la fecha del servicio realizado');
        // Solo citas ATENDIDAS: una confirmada o cancelada de esa fecha no es un servicio realizado.
        $pendiente = R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'confirmada', $this->efectiva);
        $cancelada = R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'cancelada', $this->efectiva);
        $atendidaNormal = R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'completada', $this->efectiva);
        $ids = $this->idsDeFilas($repo->obtenerFilasReservasEnRango($this->efectiva, $this->efectiva, null, null, 'realizado'));
        $this->assertNotContains($pendiente, $ids);
        $this->assertNotContains($cancelada, $ids);
        $this->assertContains($atendidaNormal, $ids, 'sin regularización, la fecha del servicio es la programada');
        $v = (new ReporteService($repo))->validarYNormalizarFiltros(['fecha_inicio' => $this->efectiva, 'fecha_fin' => $this->hoy, 'criterio_fecha' => 'realizado']);
        $this->assertSame('realizado', $v['criterio_fecha']);
        $this->assertFalse((new ReporteService($repo))->validarYNormalizarFiltros(['fecha_inicio' => $this->efectiva, 'fecha_fin' => $this->hoy, 'criterio_fecha' => 'inventado'])['valido']);
    }

    public function testElReporteCompletoYSuExportacionDistinguenLasTresFechas(): void
    {
        [$cobro] = $this->cobrarYDevolver();
        $svc = new ReporteService(new ReporteRepository());
        $reporte = $svc->consultarReporte(['fecha_inicio' => $this->efectiva, 'fecha_fin' => $this->efectiva, 'criterio_fecha' => 'realizado']);
        $this->assertSame('realizado', $reporte['filtros']['criterio_fecha'] ?? $reporte['criterio_fecha'] ?? 'realizado');
        $res = array_values(array_filter($reporte['reservas'] ?? [], fn($r) => (int)$r['id'] === $this->cita));
        $this->assertCount(1, $res, 'la cita figura en el reporte de servicios realizados del día efectivo');
        $this->assertSame('Atendida', $res[0]['etiqueta_estado_cita'], 'una cita completada no se presenta como «Confirmada»');
        $this->assertSame($this->programada, $res[0]['fecha_programada']);
        $this->assertSame($this->efectiva . ' 12:00:00', $res[0]['atencion_efectiva_at']);
        $tabla = $svc->tablaExportacion($reporte, 'reservas');
        $titulos = array_column($tabla['columnas'], 'titulo');
        $this->assertContains('Fecha programada', $titulos);
        $this->assertContains('Fecha efectiva de atencion', $titulos);
        $this->assertNotContains('Fecha', $titulos, 'ninguna columna llama «Fecha» a la programada sin decir cuál es');
        $fila = array_values(array_filter($tabla['filas'], fn($f) => (int)$f['id'] === $this->cita))[0];
        $this->assertSame($this->programada, $fila['fecha']);
        $this->assertSame($this->efectiva . ' 12:00', $fila['efectiva']);
        // Movimientos financieros del mismo reporte por fecha de COBRO (hoy): llevan la fecha real y, aparte, la programada y la efectiva.
        $rep = $svc->consultarReporte(['fecha_inicio' => $this->hoy, 'fecha_fin' => $this->hoy, 'criterio_fecha' => 'cobro']);
        $mov = array_values(array_filter($rep['movimientos_financieros'] ?? [], fn($m) => (int)($m['pago_id'] ?? 0) === $cobro['pago_id']));
        $this->assertNotEmpty($mov, 'el cobro figura por la fecha del movimiento (hoy)');
        $this->assertSame($this->hoy, substr((string)$mov[0]['fecha_cobro'], 0, 10));
        $this->assertSame($this->programada, $mov[0]['cita_fecha']);
        $this->assertSame($this->efectiva . ' 12:00:00', $mov[0]['cita_atencion_efectiva']);
        $tmov = $svc->tablaExportacion($rep, 'movimientos');
        $this->assertContains('Fecha programada de la cita', array_column($tmov['columnas'], 'titulo'));
        $this->assertContains('Fecha efectiva de atencion', array_column($tmov['columnas'], 'titulo'));
        $this->assertNotContains('Fecha Servicio', array_column($tmov['columnas'], 'titulo'), 'la programada ya no se llama «Fecha Servicio»');
    }

    public function testFinanzasMuestraPagosYDevolucionesPorSusFechasReales(): void
    {
        $repo = new OperacionRepository($this->db);
        $antesHoy = $repo->caja($this->hoy, $this->hoy)['resumen'];
        $antesProg = $repo->caja($this->programada, $this->programada)['resumen'];
        $antesEfec = $repo->caja($this->efectiva, $this->efectiva)['resumen'];
        [$cobro, $dev] = $this->cobrarYDevolver();
        $hoy = $repo->caja($this->hoy, $this->hoy);
        $this->assertSame((int)$antesHoy['ingresos'] + 4000, (int)$hoy['resumen']['ingresos'], 'el cobro figura HOY, el día en que ocurrió');
        $this->assertSame((int)$antesHoy['egresos'] + 1000, (int)$hoy['resumen']['egresos'], 'la devolución figura HOY, el día en que ocurrió');
        $conceptos = array_column($hoy['filas'], 'concepto');
        $this->assertContains('Cobro de la cita #' . $this->cita . ' (efectivo)', $conceptos);
        $this->assertNotEmpty(array_filter($conceptos, fn($c) => str_contains((string)$c, '#' . $this->cita) && str_contains((string)$c, 'evoluci')), 'la devolución tiene su movimiento: ' . json_encode($conceptos));
        // Ni la fecha programada ni la efectiva reciben dinero que se movió hoy.
        $this->assertSame((int)$antesProg['ingresos'], (int)$repo->caja($this->programada, $this->programada)['resumen']['ingresos']);
        $this->assertSame((int)$antesProg['egresos'], (int)$repo->caja($this->programada, $this->programada)['resumen']['egresos']);
        $this->assertSame((int)$antesEfec['ingresos'], (int)$repo->caja($this->efectiva, $this->efectiva)['resumen']['ingresos']);
        $this->assertSame((int)$antesEfec['egresos'], (int)$repo->caja($this->efectiva, $this->efectiva)['resumen']['egresos']);
        // Cada movimiento lleva la hora real de cuando ocurrió (no la de la cita ni la efectiva).
        foreach ($this->db->query("SELECT registrado_en FROM caja_movimientos WHERE pago_id={$cobro['pago_id']}")->fetch_all(MYSQLI_ASSOC) as $m) {
            $this->assertSame($this->hoy, substr($m['registrado_en'], 0, 10));
        }
        $this->assertSame($cobro['pago_id'], $dev['pago_id']);
    }
}
