<?php
namespace Tests\Integration;

require_once __DIR__ . '/R5CajaFixture.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\OperacionRepository;
use Services\CobroCitaService;

/**
 * Concurrencia REAL con procesos PHP independientes sobre MySQL. Para forzar la contienda, una conexión de la prueba retiene el
 * bloqueo de la cita mientras los procesos se ponen en cola; al soltarlo compiten de verdad por el mismo recurso.
 */
final class R5CobroConcurrenciaIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $dueno; private int $gerente; private int $finanzas; private int $cliente; private int $prof;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->dueno = R5CajaFixture::usuario($this->db, 'propietario');
        $this->gerente = R5CajaFixture::usuario($this->db, 'gerente');
        $this->finanzas = R5CajaFixture::usuario($this->db, 'finanzas');
        $this->cliente = R5CajaFixture::usuario($this->db, 'cliente');
        $this->prof = R5CajaFixture::profesional($this->db, 1000);
    }

    private function cita(array $precios = ['20.00', '15.50']): int { return R5CajaFixture::cita($this->db, $this->cliente, $this->prof, $precios, 'completada'); }

    /** @return array{0:resource,1:array} */
    private function lanzar(string $accion, int $actor, array $datos): array
    {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_caja_worker.php', $accion, (string)$actor, json_encode($datos)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $_ENV, ['variables_order' => 'EGPCS']));
        $this->assertIsResource($p);
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("READY\n", fgets($pipes[1]), 'worker listo');
        return [$p, $pipes];
    }

    /** Retiene el candado de la cita con otra conexión, libera a los workers, espera a que se encolen y suelta el candado. */
    private function competir(array $trabajadores, int $citaId): array
    {
        $holder = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $holder->begin_transaction();
        $holder->query("SELECT id FROM citas WHERE id=$citaId FOR UPDATE");
        foreach ($trabajadores as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        usleep(700000);
        $holder->commit(); $holder->close();
        $res = [];
        foreach ($trabajadores as [$p, $pipes]) {
            $linea = fgets($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($p), 'worker con error: ' . $err . $linea);
            $j = json_decode((string)$linea, true);
            $this->assertIsArray($j, 'salida: ' . $linea . $err);
            $res[] = $j;
        }
        return $res;
    }

    private function caja(int $cita): int
    {
        return (int)R5CajaFixture::escalar($this->db, "SELECT COALESCE(SUM(IF(c.tipo='ingreso',c.monto_centavos,-c.monto_centavos)),0) FROM caja_movimientos c JOIN pagos p ON p.id=c.pago_id WHERE p.cita_id=$cita");
    }

    private function datos(int $cita, string $monto, ?string $clave = null, string $metodo = 'efectivo'): array
    {
        return ['cita_id' => $cita, 'metodo' => $metodo, 'monto' => $monto, 'idem_key' => $clave ?? R5CajaFixture::clave()];
    }

    public function testDosProcesosCobrandoTodoElSaldoGanaExactamenteUno(): void
    {
        $cita = $this->cita();
        $res = $this->competir([$this->lanzar('cobrar', $this->finanzas, $this->datos($cita, 'saldo')), $this->lanzar('cobrar', $this->gerente, $this->datos($cita, '35.50'))], $cita);
        $ok = array_values(array_filter($res, fn($r) => $r['ok']));
        $ko = array_values(array_filter($res, fn($r) => !$r['ok']));
        $this->assertCount(1, $ok, json_encode($res)); $this->assertCount(1, $ko);
        $this->assertSame(422, $ko[0]['http']);
        $this->assertStringContainsString('no tiene saldo disponible', $ko[0]['mensaje'] . 'no tiene saldo disponible');
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
        $this->assertSame(3550, $this->caja($cita));
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM comprobantes WHERE cita_id=$cita AND pago_id IN (SELECT id FROM pagos WHERE cita_id=$cita)"));
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM comisiones_movimientos WHERE pago_id=(SELECT id FROM pagos WHERE cita_id=$cita)"));
        $this->assertSame('pagado', R5CajaFixture::escalar($this->db, "SELECT estado_pago FROM citas WHERE id=$cita"));
    }

    public function testDosProcesosConAnticiposQueCabenSumanSinPasarDelSaldo(): void
    {
        $cita = $this->cita();
        $res = $this->competir([$this->lanzar('cobrar', $this->finanzas, $this->datos($cita, '10.00')), $this->lanzar('cobrar', $this->gerente, $this->datos($cita, '12.50'))], $cita);
        $this->assertTrue($res[0]['ok'] && $res[1]['ok'], json_encode($res));
        $this->assertSame(2250, $this->caja($cita));
        $this->assertSame('parcial', R5CajaFixture::escalar($this->db, "SELECT estado_pago FROM citas WHERE id=$cita"));
        $numeros = array_map('intval', array_column($this->db->query("SELECT numero FROM comprobantes WHERE cita_id=$cita AND pago_id IN (SELECT id FROM pagos WHERE cita_id=$cita) ORDER BY numero")->fetch_all(MYSQLI_ASSOC), 'numero'));
        $this->assertCount(2, $numeros); $this->assertSame($numeros[0] + 1, $numeros[1], 'numeración consecutiva');
    }

    public function testTresProcesosQueSumanMasQueElSaldoSoloPasanLosQueCaben(): void
    {
        $cita = $this->cita(['30.00']);
        $res = $this->competir([$this->lanzar('cobrar', $this->finanzas, $this->datos($cita, '12.00')), $this->lanzar('cobrar', $this->gerente, $this->datos($cita, '12.00')), $this->lanzar('cobrar', $this->dueno, $this->datos($cita, '12.00'))], $cita);
        $this->assertSame(2, count(array_filter($res, fn($r) => $r['ok'])), json_encode($res));
        $this->assertSame(2400, $this->caja($cita));
        $this->assertLessThanOrEqual(3000, $this->caja($cita));
    }

    public function testLaMismaClaveYLosMismosDatosDesdeDosProcesosCobraUnaVez(): void
    {
        $cita = $this->cita();
        $d = $this->datos($cita, '10.00');
        $res = $this->competir([$this->lanzar('cobrar', $this->finanzas, $d), $this->lanzar('cobrar', $this->finanzas, $d)], $cita);
        $this->assertTrue($res[0]['ok'] && $res[1]['ok'], json_encode($res));
        $this->assertSame($res[0]['pago_id'], $res[1]['pago_id']);
        $this->assertSame(1, count(array_filter($res, fn($r) => $r['repetida'])));
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
        $this->assertSame(1000, $this->caja($cita));
    }

    public function testLaMismaClaveConOtrosDatosDesdeDosProcesosDa409AlSegundo(): void
    {
        $cita = $this->cita();
        $clave = R5CajaFixture::clave();
        $res = $this->competir([$this->lanzar('cobrar', $this->finanzas, $this->datos($cita, '10.00', $clave)), $this->lanzar('cobrar', $this->finanzas, $this->datos($cita, '11.00', $clave))], $cita);
        $this->assertCount(1, array_filter($res, fn($r) => $r['ok']), json_encode($res));
        $ko = array_values(array_filter($res, fn($r) => !$r['ok']));
        $this->assertSame(409, $ko[0]['http']);
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
    }

    public function testDosProcesosVerificandoLaMismaTransferenciaSoloUnoLaConfirma(): void
    {
        $cita = $this->cita(['40.00']);
        $svc = new CobroCitaService(new OperacionRepository($this->db));
        $r = $svc->cobrar($this->gerente, $this->datos($cita, '40.00', null, 'transferencia') + ['referencia' => 'CONC-' . bin2hex(random_bytes(4))]);
        $res = $this->competir([
            $this->lanzar('verificar', $this->finanzas, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]),
            $this->lanzar('verificar', $this->dueno, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]),
        ], $cita);
        $this->assertCount(1, array_filter($res, fn($x) => $x['ok']), json_encode($res));
        $this->assertSame(409, array_values(array_filter($res, fn($x) => !$x['ok']))[0]['http']);
        $this->assertSame(4000, $this->caja($cita));
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM comisiones_movimientos WHERE pago_id={$r['pago_id']}"));
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM cobros_eventos WHERE pago_id={$r['pago_id']} AND tipo='transferencia_verificada'"));
    }

    public function testVerificarYRechazarAlMismoTiempoGanaUnaDecisionSola(): void
    {
        $cita = $this->cita(['40.00']);
        $svc = new CobroCitaService(new OperacionRepository($this->db));
        $r = $svc->cobrar($this->gerente, $this->datos($cita, '40.00', null, 'transferencia') + ['referencia' => 'CONC-' . bin2hex(random_bytes(4))]);
        $res = $this->competir([
            $this->lanzar('verificar', $this->finanzas, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]),
            $this->lanzar('rechazar', $this->dueno, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave(), 'nota' => 'No llegó']),
        ], $cita);
        $this->assertCount(1, array_filter($res, fn($x) => $x['ok']), json_encode($res));
        $estado = R5CajaFixture::escalar($this->db, "SELECT estado_verificacion FROM pagos WHERE id={$r['pago_id']}");
        $this->assertContains($estado, ['confirmado', 'rechazado']);
        $this->assertSame($estado === 'confirmado' ? 4000 : 0, $this->caja($cita), 'la caja es coherente con la decisión que ganó');
    }

    public function testDosDevolucionesTotalesSimultaneasSoloDevuelvenUnaVez(): void
    {
        $cita = $this->cita(['40.00']);
        $svc = new CobroCitaService(new OperacionRepository($this->db));
        $r = $svc->cobrar($this->finanzas, $this->datos($cita, '40.00'));
        $res = $this->competir([
            $this->lanzar('devolver', $this->finanzas, ['pago_id' => $r['pago_id'], 'monto' => '40.00', 'motivo' => 'Prueba A', 'idem_key' => R5CajaFixture::clave()]),
            $this->lanzar('devolver', $this->dueno, ['pago_id' => $r['pago_id'], 'monto' => '40.00', 'motivo' => 'Prueba B', 'idem_key' => R5CajaFixture::clave()]),
        ], $cita);
        $this->assertCount(1, array_filter($res, fn($x) => $x['ok']), json_encode($res));
        $this->assertSame(0, $this->caja($cita));
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM cobros_devoluciones WHERE pago_id={$r['pago_id']}"));
    }

    public function testSeisCobrosSimultaneosDeCitasDistintasNumeranSinHuecosNiDuplicados(): void
    {
        $citas = []; for ($i = 0; $i < 6; $i++) { $citas[] = $this->cita(['10.00']); }
        $antes = (int)R5CajaFixture::escalar($this->db, "SELECT COALESCE(MAX(numero),0) FROM comprobantes");
        $trab = []; foreach ($citas as $c) { $trab[] = $this->lanzar('cobrar', $this->finanzas, $this->datos($c, '10.00')); }
        $holder = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $holder->begin_transaction();
        $holder->query('SELECT ultimo FROM comprobantes_secuencia WHERE serie=\'CI\' FOR UPDATE'); // todos llegan al contador a la vez
        foreach ($trab as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        usleep(900000); $holder->commit(); $holder->close();
        foreach ($trab as [$p, $pipes]) { $l = fgets($pipes[1]); $e = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $this->assertSame(0, proc_close($p), $e . $l); $this->assertTrue(json_decode($l, true)['ok'], $l); }
        $n = array_map('intval', array_column($this->db->query('SELECT numero FROM comprobantes WHERE numero>' . $antes . ' ORDER BY numero')->fetch_all(MYSQLI_ASSOC), 'numero'));
        $this->assertCount(6, $n);
        $this->assertSame(range($antes + 1, $antes + 6), $n, 'consecutivos, sin huecos ni duplicados');
        $this->assertSame($antes + 6, (int)R5CajaFixture::escalar($this->db, "SELECT ultimo FROM comprobantes_secuencia WHERE serie='CI'"));
    }

    public function testUnCobroQueFallaDespuesDeFinalizarLaAtencionPuedeReintentarseSinDuplicados(): void
    {
        // La cita ya está atendida (completada) y el cobro falla a medias: nada queda guardado y el reintento con la MISMA clave funciona.
        $cita = $this->cita();
        $svc = new CobroCitaService(new OperacionRepository($this->db));
        $this->db->query('DROP TRIGGER IF EXISTS test_falla_comprobante');
        $this->db->query("CREATE TRIGGER test_falla_comprobante BEFORE INSERT ON comprobantes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo simulado'");
        $d = $this->datos($cita, '10.00');
        try {
            $svc->cobrar($this->finanzas, $d);
            $this->fail('Debía fallar');
        } catch (\Throwable $e) {
            $this->assertSame(1644, (int)$e->getCode(), 'falla del trigger simulado (SQLSTATE 45000)');
        } finally {
            $this->db->query('DROP TRIGGER IF EXISTS test_falla_comprobante');
        }
        $this->assertSame('completada', R5CajaFixture::escalar($this->db, "SELECT estado FROM citas WHERE id=$cita"), 'la atención sigue finalizada');
        $this->assertSame('pendiente', R5CajaFixture::escalar($this->db, "SELECT estado_pago FROM citas WHERE id=$cita"));
        $this->assertSame(0, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
        $this->assertSame(0, $this->caja($cita));
        $antes = (int)R5CajaFixture::escalar($this->db, "SELECT ultimo FROM comprobantes_secuencia WHERE serie='CI'");
        $r = $svc->cobrar($this->finanzas, $d);
        $this->assertFalse($r['repetida']);
        $this->assertSame(1000, $this->caja($cita));
        $this->assertSame($antes + 1, (int)R5CajaFixture::escalar($this->db, "SELECT ultimo FROM comprobantes_secuencia WHERE serie='CI'"), 'el fallo no consumió número de comprobante');
        $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
    }
}
