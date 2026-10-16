<?php
namespace Tests\Integration;

require_once __DIR__ . '/R5CajaFixture.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\CitaProtegidaException;
use Repositories\CitaRepository;
use Repositories\OperacionRepository;
use Services\CitaService;
use Services\CobroCitaService;
use Services\RegularizacionAtencionService;

/**
 * Decisión del usuario: una cita con pagos, devoluciones, comprobantes, eventos de cobro o regularizaciones NO se elimina físicamente
 * (se cancela o se corrige con la devolución/regularización auditadas). Pruebas del integrador (NO independientes) de TODAS las vías:
 * repositorio (guarda atómica con la cita bloqueada), servicio (409), carrera real contra un cobro y el borrado genérico heredado.
 */
final class R5EliminacionProtegidaIntegrationTest extends TestCase
{
    private \mysqli $db;
    private ?\mysqli $previa = null;
    private int $gerente; private int $finanzas; private int $cliente; private int $prof;
    /** @var list<int> */ private array $citas = [];

    protected function setUp(): void
    {
        $this->previa = ActiveRecord::getDB();
        $this->db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->gerente = R5CajaFixture::usuario($this->db, 'gerente');
        $this->finanzas = R5CajaFixture::usuario($this->db, 'finanzas');
        $this->cliente = R5CajaFixture::usuario($this->db, 'cliente');
        $this->prof = R5CajaFixture::profesional($this->db, 1000);
    }

    protected function tearDown(): void
    {
        foreach ($this->citas as $id) {
            foreach (['cobros_eventos', 'comprobantes', 'cobros_devoluciones'] as $t) { $this->db->query("DELETE FROM {$t} WHERE cita_id={$id}"); }
            $this->db->query("DELETE FROM caja_movimientos WHERE pago_id IN (SELECT id FROM pagos WHERE cita_id={$id} OR cita_referencia_id={$id})");
            $this->db->query("DELETE FROM pagos WHERE cita_id={$id} OR cita_referencia_id={$id}");
            $this->db->query("DELETE FROM citas_regularizaciones WHERE cita_id={$id}");
        }
        ActiveRecord::setDB($this->previa);
        $this->db->close();
    }

    private function cita(string $estado = 'completada', ?string $fecha = null): int
    {
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        return $this->citas[] = R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['30.00'], $estado, $fecha ?? $hoy);
    }

    private function existe(int $id): bool
    {
        return (bool)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM citas WHERE id={$id}");
    }

    private function cobrar(int $cita): array
    {
        return (new CobroCitaService(new OperacionRepository($this->db)))->cobrar($this->finanzas, ['cita_id' => $cita, 'metodo' => 'efectivo', 'monto' => '30.00', 'recibido' => '30.00', 'idem_key' => R5CajaFixture::clave()]);
    }

    public function testUnaCitaSinHistorialSiSePuedeEliminar(): void
    {
        $id = $this->cita('confirmada');
        $r = (new CitaService())->eliminar($id, $this->gerente, true);
        $this->assertTrue($r['resultado'], 'sin cobros ni regularizaciones la eliminación física sigue disponible para el dominio');
        $this->assertFalse($this->existe($id));
    }

    public function testConCobroNoSeEliminaPorServicioNiPorRepositorio(): void
    {
        $id = $this->cita();
        $this->cobrar($id);
        $r = (new CitaService())->eliminar($id, $this->gerente, true);
        $this->assertSame(409, $r['httpCode']);
        $this->assertStringContainsString('Cancélala', $r['error']);
        $this->assertStringContainsString('devolución', $r['error'], 'ofrece la corrección auditada, no solo el rechazo');
        $this->expectException(CitaProtegidaException::class);
        try {
            (new CitaRepository($this->db))->eliminarCitaAtomica($id);
        } finally {
            $this->assertTrue($this->existe($id), 'la cita y su historial siguen intactos');
            $this->assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM citasservicios WHERE citaId={$id}"), 'sus servicios no se borraron');
        }
    }

    /** @return array<string, array{string}> */
    public static function evidencias(): array
    {
        return [
            'comprobante' => ['comprobantes'],
            'evento de cobro' => ['cobros_eventos'],
            'devolución' => ['cobros_devoluciones'],
        ];
    }

    /** @dataProvider evidencias */
    public function testCadaTipoDeEvidenciaFinancieraBloquea(string $tabla): void
    {
        $id = $this->cita();
        $pago = $this->cobrar($id)['pago_id'];
        if ($tabla === 'cobros_devoluciones') {
            (new CobroCitaService(new OperacionRepository($this->db)))->devolver($this->finanzas, ['pago_id' => $pago, 'monto' => '5.00', 'motivo' => 'Ajuste acordado con la clienta', 'idem_key' => R5CajaFixture::clave()]);
        }
        // Se retira el resto de la evidencia para comprobar que ESA sola tabla ya protege a la cita.
        foreach (['cobros_eventos', 'comprobantes', 'cobros_devoluciones'] as $t) { if ($t !== $tabla) { $this->db->query("DELETE FROM {$t} WHERE cita_id={$id}"); } }
        $this->db->query("DELETE FROM caja_movimientos WHERE pago_id={$pago}");
        $this->db->query("UPDATE pagos SET cita_id=NULL, cita_referencia_id=NULL WHERE id={$pago}");
        $this->assertGreaterThan(0, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM {$tabla} WHERE cita_id={$id}"), "la prueba necesita evidencia en {$tabla}");
        $this->assertSame('cobros', (new CitaRepository($this->db))->motivoProteccionEliminacion($id));
        $this->assertSame(409, (new CitaService())->eliminar($id, $this->gerente, true)['httpCode']);
        $this->assertTrue($this->existe($id));
    }

    public function testConRegularizacionNoSeEliminaYOfreceCancelarOAusencia(): void
    {
        $dia = (new \DateTimeImmutable('-3 days', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $id = $this->cita('confirmada', $dia);
        (new RegularizacionAtencionService(new OperacionRepository($this->db)))->regularizar($this->gerente, ['cita_id' => $id, 'motivo' => 'Se atendió y faltó registrarlo', 'efectiva' => $dia . ' 10:00', 'idem_key' => R5CajaFixture::clave()]);
        $r = (new CitaService())->eliminar($id, $this->gerente, true);
        $this->assertSame(409, $r['httpCode']);
        $this->assertStringContainsString('cancélala', $r['error']);
        $this->assertSame('regularizacion', (new CitaRepository($this->db))->motivoProteccionEliminacion($id));
        $this->assertTrue($this->existe($id));
    }

    /**
     * La guarda NO puede quedar solo en el servicio: un cobro que se confirma entre la comprobación y el DELETE debe frenar la
     * eliminación. Se simula con la cita bloqueada por otra conexión mientras un proceso intenta eliminarla; al confirmar el cobro,
     * el proceso (que esperaba el candado) debe encontrar la evidencia y rechazar.
     */
    public function testUnCobroConfirmadoMientrasSeEliminaFrenaLaEliminacion(): void
    {
        $id = $this->cita();
        $otra = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $otra->begin_transaction();
        $otra->query("SELECT id FROM citas WHERE id={$id} FOR UPDATE");
        $p = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_eliminar_cita_worker.php', (string)$id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $_ENV, ['variables_order' => 'EGPCS']));
        $this->assertIsResource($p);
        $this->assertSame('READY', trim((string)fgets($pipes[1])));
        usleep(700000); // el proceso ya está esperando el candado de la cita
        // El «cobro» ocurre dentro de la transacción que tiene la cita bloqueada (como lo hace CobroCitaService) y se confirma al final.
        $otra->query("INSERT INTO pagos(usuario_id,cita_id,cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at) VALUES ({$this->cliente},{$id},{$id},'carrera-{$id}','manual',30.00,'USD','completado','efectivo','carrera-{$id}-" . bin2hex(random_bytes(6)) . "',UTC_TIMESTAMP())");
        $err = $otra->error;
        $otra->commit();
        $salida = trim((string)stream_get_contents($pipes[1]));
        $stderr = trim((string)stream_get_contents($pipes[2]));
        foreach ($pipes as $pp) { fclose($pp); }
        proc_close($p);
        $otra->close();
        $this->assertSame('', $err, 'el INSERT de apoyo del cobro debe poder ejecutarse: ' . $err);
        $this->assertSame('', $stderr);
        $this->assertSame('protegida', json_decode($salida, true)['resultado'] ?? $salida, 'el proceso vio el cobro tras esperar el candado y no eliminó');
        $this->assertTrue($this->existe($id));
    }

    public function testElBorradoGenericoHeredadoRechazaTablasConHistorial(): void
    {
        foreach (['citas', 'usuarios', 'pagos', 'comprobantes', 'citas_regularizaciones', 'cobros_eventos', 'clientes'] as $tabla) {
            $modelo = new class extends ActiveRecord { public static $tabla = ''; };
            $modelo::$tabla = $tabla;
            $modelo->id = PHP_INT_MAX; // inexistente: de no estar protegida, solo ejecutaría un DELETE sin filas
            $log = ini_set('error_log', '/dev/null');
            $this->assertFalse($modelo->eliminar(), "ActiveRecord::eliminar debe rechazar la tabla {$tabla}");
            ini_set('error_log', (string)$log);
        }
    }

    /** Ninguna sentencia de la aplicación elimina filas de estas tablas, salvo la guarda atómica de citas. */
    public function testNingunCodigoDeLaAplicacionBorraCitasUsuariosOEvidencia(): void
    {
        $raiz = dirname(__DIR__, 2);
        $protegidas = 'citas|citasservicios|citas_regularizaciones|usuarios|clientes|pagos|comprobantes|cobros_eventos|cobros_devoluciones|caja_movimientos|comisiones_movimientos|agenda_eventos|equipo_auditoria|colaboradores|usuario_accesos';
        $permitidos = ['repositories/CitaRepository.php' => ['citas', 'citasservicios']];
        $hallazgos = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $ruta = str_replace('\\', '/', substr((string)$f, strlen($raiz) + 1));
            if (!str_ends_with($ruta, '.php') || preg_match('#^(vendor|tests|node_modules|database/migrations)/#', $ruta)) { continue; }
            $codigo = (string)file_get_contents((string)$f);
            if (preg_match_all('/\bDELETE\s+(?:\w+\s+)?FROM\s+`?(' . $protegidas . ')`?\b|\bTRUNCATE\s+(?:TABLE\s+)?`?(' . $protegidas . ')`?\b/i', $codigo, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $tabla = strtolower($x[1] !== '' ? $x[1] : $x[2]);
                    if (!in_array($tabla, $permitidos[$ruta] ?? [], true)) { $hallazgos[] = "{$ruta}: {$tabla}"; }
                }
            }
        }
        $this->assertSame([], $hallazgos, 'borrado de tablas con historial fuera de la guarda atómica');
    }
}
