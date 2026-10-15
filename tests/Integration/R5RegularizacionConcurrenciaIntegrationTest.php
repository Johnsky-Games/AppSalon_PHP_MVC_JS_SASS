<?php
namespace Tests\Integration;

require_once __DIR__ . '/R5CajaFixture.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;

/**
 * Concurrencia REAL (procesos PHP independientes) sobre la regularización de la atención. Una conexión de la prueba retiene el candado
 * de la cita mientras los procesos se ponen en cola; al soltarlo compiten por el mismo recurso. Pruebas del integrador (no independientes).
 */
final class R5RegularizacionConcurrenciaIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $dueno; private int $gerente; private int $cliente; private int $profUsuario; private int $prof;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        // Otras pruebas vacían `citas` con las claves foráneas desactivadas y dejan regularizaciones huérfanas cuyo cita_id se reutiliza.
        $this->db->query('DELETE FROM citas_regularizaciones WHERE cita_id NOT IN (SELECT id FROM citas)');
        $this->dueno = R5CajaFixture::usuario($this->db, 'propietario');
        $this->gerente = R5CajaFixture::usuario($this->db, 'gerente');
        $this->cliente = R5CajaFixture::usuario($this->db, 'cliente');
        $this->profUsuario = R5CajaFixture::usuario($this->db, 'profesional');
        $this->prof = R5CajaFixture::profesional($this->db, 1000);
        $this->db->query("UPDATE profesionales SET usuarioId={$this->profUsuario} WHERE id={$this->prof}");
    }

    private function cita(string $estado = 'confirmada'): int
    {
        return R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], $estado, (new \DateTimeImmutable('-5 days', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d'));
    }

    private function datos(int $cita, ?string $clave = null): array
    {
        $dia = (new \DateTimeImmutable('-5 days', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        return ['cita_id' => $cita, 'motivo' => 'El servicio se prestó y no se registró', 'efectiva' => $dia . ' 11:30', 'idem_key' => $clave ?? R5CajaFixture::clave()];
    }

    /** @return array{0:resource,1:array} */
    private function lanzar(string $accion, int $actor, array $datos): array
    {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_regularizacion_worker.php', $accion, (string)$actor, json_encode($datos)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $_ENV, ['variables_order' => 'EGPCS']));
        $this->assertIsResource($p);
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("READY\n", fgets($pipes[1]), 'worker listo');
        return [$p, $pipes];
    }

    /** @return list<array<string,mixed>> */
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
            $this->assertIsArray($j, (string)$linea);
            $res[] = $j;
        }
        return $res;
    }

    private function n(string $sql): int { return (int)R5CajaFixture::escalar($this->db, $sql); }
    private function estado(int $cita): string { return (string)R5CajaFixture::escalar($this->db, "SELECT estado FROM citas WHERE id=$cita"); }

    public function testDosPersonasRegularizandoLaMismaCitaGanaExactamenteUna(): void
    {
        $cita = $this->cita();
        $res = $this->competir([$this->lanzar('regularizar', $this->gerente, $this->datos($cita)), $this->lanzar('regularizar', $this->dueno, $this->datos($cita))], $cita);
        $this->assertCount(1, array_filter($res, fn($r) => $r['ok']), json_encode($res));
        $perdedora = array_values(array_filter($res, fn($r) => !$r['ok']))[0];
        $this->assertSame(409, $perdedora['http']);
        $this->assertSame('completada', $this->estado($cita));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita AND estado='aprobada'"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"));
        $ganadora = (int)R5CajaFixture::escalar($this->db, "SELECT regularizada_por FROM citas WHERE id=$cita");
        $this->assertSame($ganadora, (int)R5CajaFixture::escalar($this->db, "SELECT resuelta_por FROM citas_regularizaciones WHERE cita_id=$cita"), 'la fila y la cita coinciden en quién regularizó');
    }

    public function testElMismoFormularioEnviadoDosVecesTieneUnSoloEfecto(): void
    {
        $cita = $this->cita();
        $clave = R5CajaFixture::clave();
        $res = $this->competir([$this->lanzar('regularizar', $this->gerente, $this->datos($cita, $clave)), $this->lanzar('regularizar', $this->gerente, $this->datos($cita, $clave))], $cita);
        $this->assertCount(2, array_filter($res, fn($r) => $r['ok']), json_encode($res));
        $this->assertCount(1, array_filter($res, fn($r) => $r['repetida']), 'la segunda recibe el mismo resultado');
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"));
    }

    public function testRegularizarContraRegistrarLaAusenciaDejaUnSoloResultadoCoherente(): void
    {
        $cita = $this->cita();
        $res = $this->competir([$this->lanzar('regularizar', $this->gerente, $this->datos($cita)), $this->lanzar('ausencia', $this->dueno, ['cita_id' => $cita])], $cita);
        $this->assertCount(1, array_filter($res, fn($r) => $r['ok']), json_encode($res));
        $this->assertSame(409, array_values(array_filter($res, fn($r) => !$r['ok']))[0]['http']);
        $estado = $this->estado($cita);
        $this->assertContains($estado, ['completada', 'no_asistio']);
        if ($estado === 'completada') {
            $this->assertNull(R5CajaFixture::escalar($this->db, "SELECT ausencia_at FROM citas WHERE id=$cita"));
            $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita AND estado='aprobada'"));
        } else {
            $this->assertNull(R5CajaFixture::escalar($this->db, "SELECT regularizada_at FROM citas WHERE id=$cita"));
            $this->assertSame(0, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita"), 'la regularización perdedora no deja rastro');
        }
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"));
    }

    public function testRegularizarContraFinalizarUnaCitaEnServicioEscribeUnaSolaVez(): void
    {
        $cita = $this->cita('en_servicio');
        [$reg, $fin] = $this->competir([$this->lanzar('regularizar', $this->gerente, $this->datos($cita)), $this->lanzar('finalizar', $this->dueno, ['cita_id' => $cita])], $cita);
        $this->assertSame('completada', $this->estado($cita));
        // Una sola escritura: o ganó la regularización (y «finalizar» encontró la cita ya completada y es idempotente, sin escribir),
        // o ganó «finalizar» (y la regularización recibe 409 «ya está atendida»). Nunca las dos.
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"), json_encode([$reg, $fin]));
        $regularizada = $this->n("SELECT COUNT(*) FROM citas WHERE id=$cita AND regularizada_at IS NOT NULL");
        $this->assertSame($regularizada, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita AND estado='aprobada'"), 'la fila de regularización existe si y solo si la cita quedó regularizada');
        if ($regularizada === 1) {
            $this->assertTrue($reg['ok']);
            $this->assertTrue($fin['ok'] && ($fin['idempotente'] ?? false), 'finalizar sobre una cita ya completada no escribe');
            $this->assertNull(R5CajaFixture::escalar($this->db, "SELECT atencion_fin_at FROM citas WHERE id=$cita"), 'no se inventa una hora de fin');
        } else {
            $this->assertTrue($fin['ok']);
            $this->assertFalse($reg['ok']);
            $this->assertSame(409, $reg['http']);
        }
    }

    public function testSolicitudDelProfesionalContraRegularizacionDirectaQuedaCoherente(): void
    {
        $cita = $this->cita();
        $res = $this->competir([$this->lanzar('solicitar', $this->profUsuario, $this->datos($cita)), $this->lanzar('regularizar', $this->gerente, $this->datos($cita))], $cita);
        $this->assertSame('completada', $this->estado($cita), 'la regularización directa siempre queda');
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita AND estado='aprobada'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita AND estado='solicitada'"), 'ninguna solicitud queda abierta sobre una cita ya atendida');
        $this->assertLessThanOrEqual(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita AND solicitada_por IS NOT NULL"));
    }

    public function testDosRechazosSimultaneosDeLaMismaSolicitudResuelvenUnaVez(): void
    {
        $cita = $this->cita();
        $sol = (new \Services\RegularizacionAtencionService(new \Repositories\OperacionRepository($this->db)))->solicitar($this->profUsuario, $this->datos($cita));
        $d = fn() => ['regularizacion_id' => $sol['regularizacion_id'], 'motivo' => 'Sin constancia de la atención', 'idem_key' => R5CajaFixture::clave()];
        $res = $this->competir([$this->lanzar('rechazar', $this->gerente, $d()), $this->lanzar('rechazar', $this->dueno, $d())], $cita);
        $this->assertCount(1, array_filter($res, fn($r) => $r['ok']), json_encode($res));
        $this->assertSame(409, array_values(array_filter($res, fn($r) => !$r['ok']))[0]['http']);
        $this->assertSame('rechazada', R5CajaFixture::escalar($this->db, "SELECT estado FROM citas_regularizaciones WHERE id={$sol['regularizacion_id']}"));
        $this->assertSame('confirmada', $this->estado($cita));
        $this->assertSame(2, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"), 'solicitud + un solo rechazo');
    }
}
