<?php
namespace Tests\Integration;

require_once __DIR__ . '/R5CajaFixture.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\OperacionRepository;
use Services\CobroCitaService;
use Services\CobroRechazado;

/** Cobro desde la cita sobre MySQL real: saldo, métodos, verificación, devoluciones, caja, comisiones y comprobantes (servicio directo). */
final class R5CobroCitaIntegrationTest extends TestCase
{
    private \mysqli $db;
    private CobroCitaService $svc;
    private int $dueno; private int $gerente; private int $finanzas; private int $recepcion; private int $cliente; private int $prof;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->svc = new CobroCitaService(new OperacionRepository($this->db));
        $this->dueno = R5CajaFixture::usuario($this->db, 'propietario');
        $this->gerente = R5CajaFixture::usuario($this->db, 'gerente');
        $this->finanzas = R5CajaFixture::usuario($this->db, 'finanzas');
        $this->recepcion = R5CajaFixture::usuario($this->db, 'recepcion');
        $this->cliente = R5CajaFixture::usuario($this->db, 'cliente');
        $this->prof = R5CajaFixture::profesional($this->db, 2500); // 25 %
    }

    private function cita(array $precios = ['20.00', '15.50'], string $estado = 'completada'): int
    {
        return R5CajaFixture::cita($this->db, $this->cliente, $this->prof, $precios, $estado);
    }

    private function cobrar(int $actor, int $cita, string $metodo, string $monto, array $extra = []): array
    {
        return $this->svc->cobrar($actor, ['cita_id' => $cita, 'metodo' => $metodo, 'monto' => $monto, 'idem_key' => R5CajaFixture::clave()] + $extra);
    }

    private function intenta(callable $f, int $http): CobroRechazado
    {
        try { $f(); } catch (CobroRechazado $e) { self::assertSame($http, $e->http(), $e->getMessage()); return $e; }
        self::fail('Debía rechazarse con ' . $http);
    }

    private function caja(int $cita): int
    {
        return (int)R5CajaFixture::escalar($this->db, "SELECT COALESCE(SUM(IF(c.tipo='ingreso',c.monto_centavos,-c.monto_centavos)),0) FROM caja_movimientos c JOIN pagos p ON p.id=c.pago_id WHERE p.cita_id=$cita");
    }

    public function testTotalSaleDeLosPreciosHistoricosEnCentavos(): void
    {
        $cita = $this->cita(['20.10', '15.55']);
        $d = $this->svc->detalle($cita);
        self::assertSame(3565, $d['total_centavos']);
        self::assertSame(3565, $d['saldo_centavos']);
        self::assertSame('pendiente', $d['estado']);
        self::assertSame(['Servicio histórico 1', 'Servicio histórico 2'], array_column($d['servicios'], 'nombre'));
    }

    public function testEfectivoConCambioAnticipoYSaldoHastaPagado(): void
    {
        $cita = $this->cita(['20.00', '15.50']); // 35.50
        $r = $this->cobrar($this->finanzas, $cita, 'efectivo', '10.00', ['recibido' => '20.00']);
        self::assertSame(1000, $r['monto_centavos']); self::assertSame(2000, $r['recibido_centavos']); self::assertSame(1000, $r['cambio_centavos']);
        self::assertSame('parcial', $r['estado_pago']);
        self::assertSame(1000, $this->caja($cita), 'caja registra solo el importe aplicado, no el cambio');
        self::assertSame('parcial', R5CajaFixture::escalar($this->db, "SELECT estado_pago FROM citas WHERE id=$cita"));
        self::assertSame(250, (int)R5CajaFixture::escalar($this->db, "SELECT monto_centavos FROM comisiones_movimientos WHERE pago_id={$r['pago_id']}"));
        self::assertNotNull($r['comprobante_id']);
        $d = $this->svc->detalle($cita);
        self::assertSame(2550, $d['saldo_centavos']);
        $r2 = $this->cobrar($this->dueno, $cita, 'efectivo', 'saldo');
        self::assertSame('pagado', $r2['estado_pago']); self::assertSame(2550, $r2['monto_centavos']);
        self::assertSame(3550, $this->caja($cita));
        self::assertSame(0, $this->svc->detalle($cita)['saldo_centavos']);
        self::assertSame(2, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM comprobantes WHERE cita_id=$cita"));
    }

    public function testNoSePuedeSuperarElSaldoNiCobrarImportesInvalidos(): void
    {
        $cita = $this->cita(['20.00']);
        $this->cobrar($this->finanzas, $cita, 'efectivo', '15.00');
        $this->intenta(fn() => $this->cobrar($this->finanzas, $cita, 'efectivo', '5.01'), 422);
        foreach (['0', '0.00', '-1', '1e2', '1,5', '2.555', 'abc', ''] as $malo) {
            $this->intenta(fn() => $this->cobrar($this->finanzas, $cita, 'efectivo', $malo), 422);
        }
        $this->intenta(fn() => $this->cobrar($this->finanzas, $cita, 'efectivo', '5.00', ['recibido' => '4.99']), 422);
        self::assertSame(1500, $this->caja($cita));
        $this->cobrar($this->finanzas, $cita, 'efectivo', '5.00');
        $this->intenta(fn() => $this->cobrar($this->finanzas, $cita, 'efectivo', '0.01'), 422);
        self::assertSame('pagado', $this->svc->detalle($cita)['estado']);
    }

    public function testCitaCanceladaOAusenteNoSeCobraYUnaInexistenteEs404(): void
    {
        foreach (['cancelada', 'no_asistio'] as $estado) {
            $this->intenta(fn() => $this->cobrar($this->finanzas, $this->cita(['10.00'], $estado), 'efectivo', '10.00'), 409);
        }
        $this->intenta(fn() => $this->cobrar($this->finanzas, 987654321, 'efectivo', '10.00'), 404);
    }

    public function testSoloFinanzasOperaLaCaja(): void
    {
        $cita = $this->cita();
        foreach ([$this->recepcion, $this->cliente] as $actor) {
            $this->intenta(fn() => $this->cobrar($actor, $cita, 'efectivo', '1.00'), 403);
        }
        self::assertSame(0, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
        foreach ([$this->dueno, $this->gerente, $this->finanzas] as $actor) {
            self::assertSame('parcial', $this->cobrar($actor, $cita, 'efectivo', '1.00')['estado_pago']);
        }
    }

    public function testTransferenciaDeclaradaNoEsDineroHastaVerificar(): void
    {
        $cita = $this->cita(['40.00']);
        $ref = 'TRX-' . bin2hex(random_bytes(4));
        $r = $this->cobrar($this->gerente, $cita, 'transferencia', '40.00', ['referencia' => $ref]);
        self::assertSame('pendiente_verificacion', $r['estado_verificacion']); self::assertSame('por_verificar', $r['estado_pago']); self::assertNull($r['comprobante_id']);
        self::assertSame(0, $this->caja($cita));
        self::assertSame(0, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM comisiones_movimientos WHERE pago_id={$r['pago_id']}"));
        $d = $this->svc->detalle($cita);
        self::assertSame(0, $d['cobrado_centavos']); self::assertSame(4000, $d['pendiente_verificacion_centavos']); self::assertSame(0, $d['disponible_centavos']);
        // lo declarado reserva el saldo: no se puede declarar otra vez ni cobrar en efectivo encima
        $this->intenta(fn() => $this->cobrar($this->gerente, $cita, 'efectivo', '1.00'), 422);
        // quien registró (gerente) no puede verificarla; recepción tampoco tiene permiso
        $this->intenta(fn() => $this->svc->verificar($this->gerente, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]), 403);
        $this->intenta(fn() => $this->svc->verificar($this->recepcion, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]), 403);
        self::assertSame(0, $this->caja($cita));
        $clave = R5CajaFixture::clave();
        $v = $this->svc->verificar($this->finanzas, ['pago_id' => $r['pago_id'], 'idem_key' => $clave, 'nota' => 'Visto en el banco']);
        self::assertSame('pagado', $v['estado_pago']); self::assertNotNull($v['comprobante_id']);
        self::assertSame(4000, $this->caja($cita));
        self::assertSame(1000, (int)R5CajaFixture::escalar($this->db, "SELECT monto_centavos FROM comisiones_movimientos WHERE pago_id={$r['pago_id']}"));
        $p = $this->db->query("SELECT estado,estado_verificacion,verificado_por,verificado_en,registrado_por FROM pagos WHERE id={$r['pago_id']}")->fetch_assoc();
        self::assertSame(['completado', 'confirmado', (string)$this->finanzas, (string)$this->gerente], [$p['estado'], $p['estado_verificacion'], $p['verificado_por'], $p['registrado_por']]);
        self::assertNotNull($p['verificado_en']);
        // doble clic con la misma clave: mismo resultado, sin segundo ingreso
        $otra = $this->svc->verificar($this->finanzas, ['pago_id' => $r['pago_id'], 'idem_key' => $clave, 'nota' => 'Visto en el banco']);
        self::assertTrue($otra['repetida']); self::assertSame(4000, $this->caja($cita));
        // otra persona, otra clave: ya verificada
        $e = $this->intenta(fn() => $this->svc->verificar($this->dueno, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]), 409);
        self::assertStringContainsString('ya fue verificada', $e->getMessage());
        self::assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM cobros_eventos WHERE pago_id={$r['pago_id']} AND tipo='transferencia_verificada'"));
    }

    public function testElPropietarioPuedeVerificarSuPropiaTransferenciaYQuedaAuditado(): void
    {
        $cita = $this->cita(['10.00']);
        $r = $this->cobrar($this->dueno, $cita, 'transferencia', '10.00', ['referencia' => 'OWN-' . bin2hex(random_bytes(3))]);
        $this->svc->verificar($this->dueno, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]);
        $despues = json_decode((string)R5CajaFixture::escalar($this->db, "SELECT despues_json FROM cobros_eventos WHERE pago_id={$r['pago_id']} AND tipo='transferencia_verificada'"), true);
        self::assertTrue($despues['autoverificada']);
        self::assertSame(1000, $this->caja($cita));
    }

    public function testRechazarLiberaLaReferenciaYNoMueveCaja(): void
    {
        $cita = $this->cita(['10.00']);
        $ref = 'REJ-' . bin2hex(random_bytes(3));
        $r = $this->cobrar($this->finanzas, $cita, 'transferencia', '10.00', ['referencia' => $ref]);
        $this->intenta(fn() => $this->svc->rechazar($this->dueno, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]), 422); // sin motivo
        $x = $this->svc->rechazar($this->dueno, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave(), 'nota' => 'No aparece en el banco']);
        self::assertSame('pendiente', $x['estado_pago']);
        self::assertSame(0, $this->caja($cita));
        self::assertNull(R5CajaFixture::escalar($this->db, "SELECT referencia_unica FROM pagos WHERE id={$r['pago_id']}"));
        $this->intenta(fn() => $this->svc->verificar($this->finanzas, ['pago_id' => $r['pago_id'], 'idem_key' => R5CajaFixture::clave()]), 409);
        $this->cobrar($this->finanzas, $cita, 'transferencia', '10.00', ['referencia' => $ref]); // la referencia se puede volver a declarar
    }

    public function testUnaReferenciaDeTransferenciaSeUsaUnaSolaVez(): void
    {
        $a = $this->cita(['10.00']); $b = $this->cita(['10.00']);
        $ref = 'DUP-' . bin2hex(random_bytes(3));
        $this->cobrar($this->finanzas, $a, 'transferencia', '10.00', ['referencia' => $ref]);
        $this->intenta(fn() => $this->cobrar($this->finanzas, $b, 'transferencia', '10.00', ['referencia' => '  ' . strtolower($ref) . ' ']), 409);
        $this->intenta(fn() => $this->cobrar($this->finanzas, $b, 'transferencia', '10.00', ['referencia' => '']), 422);
        $this->intenta(fn() => $this->cobrar($this->finanzas, $b, 'transferencia', '10.00', ['referencia' => "ab\x00c"]), 422);
        self::assertSame(0, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$b"));
    }

    public function testIdempotenciaMismaClaveMismosDatosUnSoloCobroYOtrosDatos409(): void
    {
        $cita = $this->cita(['30.00']);
        $clave = R5CajaFixture::clave();
        $datos = ['cita_id' => $cita, 'metodo' => 'efectivo', 'monto' => '10.00', 'idem_key' => $clave];
        $a = $this->svc->cobrar($this->finanzas, $datos);
        $b = $this->svc->cobrar($this->finanzas, $datos);
        self::assertFalse($a['repetida']); self::assertTrue($b['repetida']); self::assertSame($a['pago_id'], $b['pago_id']);
        self::assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
        self::assertSame(1000, $this->caja($cita));
        $e = $this->intenta(fn() => $this->svc->cobrar($this->finanzas, ['monto' => '11.00'] + $datos), 409);
        self::assertStringContainsString('otros datos', $e->getMessage());
        $this->intenta(fn() => $this->svc->cobrar($this->finanzas, ['metodo' => 'transferencia', 'referencia' => 'ABCD1234'] + $datos), 409);
        self::assertSame(1000, $this->caja($cita));
        $this->intenta(fn() => $this->svc->cobrar($this->finanzas, ['idem_key' => 'corta'] + $datos), 422);
    }

    public function testDevolucionNuncaSuperaLoCobradoYRevierteComisionEnProporcion(): void
    {
        $cita = $this->cita(['40.00']);
        $r = $this->cobrar($this->finanzas, $cita, 'efectivo', '40.00'); // comisión 25 % = 10.00
        $p = $r['pago_id'];
        $dev = fn(string $monto, string $motivo = 'Cliente inconforme') => $this->svc->devolver($this->finanzas, ['pago_id' => $p, 'monto' => $monto, 'motivo' => $motivo, 'idem_key' => R5CajaFixture::clave()]);
        $this->intenta(fn() => $dev('40.01'), 422);
        $this->intenta(fn() => $this->svc->devolver($this->finanzas, ['pago_id' => $p, 'monto' => '5.00', 'motivo' => '', 'idem_key' => R5CajaFixture::clave()]), 422);
        $d1 = $dev('10.00');
        self::assertSame('parcial', $d1['estado_pago']);
        self::assertSame(3000, $this->caja($cita));
        self::assertSame(-250, (int)R5CajaFixture::escalar($this->db, "SELECT monto_centavos FROM comisiones_movimientos WHERE pago_id=$p AND tipo='reversa'"));
        $this->intenta(fn() => $dev('30.01'), 422);
        $d2 = $dev('30.00');
        self::assertSame('reembolsado', $d2['estado_pago']);
        self::assertSame(0, $this->caja($cita));
        self::assertSame('reembolsado', R5CajaFixture::escalar($this->db, "SELECT estado FROM pagos WHERE id=$p"));
        self::assertSame(0, (int)R5CajaFixture::escalar($this->db, "SELECT SUM(monto_centavos) FROM comisiones_movimientos WHERE pago_id=$p"), 'devengo + reversas netean a cero');
        $this->intenta(fn() => $dev('0.01'), 409);
        // y se puede volver a cobrar: el saldo vuelve a ser el total
        self::assertSame(4000, $this->svc->detalle($cita)['saldo_centavos']);
        self::assertSame('pagado', $this->cobrar($this->finanzas, $cita, 'efectivo', 'saldo')['estado_pago']);
    }

    public function testDevolucionTotalDeUnaVezAnulaLaComisionPendiente(): void
    {
        $cita = $this->cita(['40.00']);
        $r = $this->cobrar($this->finanzas, $cita, 'efectivo', '40.00');
        $this->svc->devolver($this->finanzas, ['pago_id' => $r['pago_id'], 'monto' => 'saldo', 'motivo' => 'Duplicado', 'idem_key' => R5CajaFixture::clave()]);
        self::assertSame('anulada', R5CajaFixture::escalar($this->db, "SELECT estado FROM comisiones_movimientos WHERE pago_id={$r['pago_id']} AND tipo='devengo'"));
    }

    public function testElPagoNoSeImputaAOtraCitaManipulandoElFormulario(): void
    {
        $a = $this->cita(['10.00']); $b = $this->cita(['10.00']);
        $r = $this->cobrar($this->finanzas, $a, 'transferencia', '10.00', ['referencia' => 'MAN-' . bin2hex(random_bytes(3))]);
        $this->intenta(fn() => $this->svc->verificar($this->finanzas, ['pago_id' => $r['pago_id'], 'cita_id' => $b, 'idem_key' => R5CajaFixture::clave()]), 422);
        $this->intenta(fn() => $this->svc->rechazar($this->dueno, ['pago_id' => $r['pago_id'], 'cita_id' => $b, 'nota' => 'x y z', 'idem_key' => R5CajaFixture::clave()]), 422);
        self::assertSame(0, $this->caja($a)); self::assertSame(0, $this->caja($b));
        // datos del cliente en el formulario se ignoran: el pago pertenece al cliente de la cita bloqueada
        $otroCliente = R5CajaFixture::usuario($this->db, 'cliente');
        $x = $this->cobrar($this->finanzas, $b, 'efectivo', '10.00', ['usuario_id' => $otroCliente, 'cliente_id' => $otroCliente]);
        self::assertSame($this->cliente, (int)R5CajaFixture::escalar($this->db, "SELECT usuario_id FROM pagos WHERE id={$x['pago_id']}"));
    }

    public function testElPagoDeLaPasarelaSimuladaCuentaPeroNoEntraACajaNiSeDevuelveDesdeCaja(): void
    {
        $cita = $this->cita(['25.00']);
        $ref = 'SIM-' . bin2hex(random_bytes(4));
        $this->db->query("INSERT INTO pagos (cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,pagado_at) VALUES ($cita,$cita,{$this->cliente},'$ref','mock',25.00,'completado','tarjeta_mock','k-$ref',NOW())");
        $d = $this->svc->detalle($cita);
        self::assertSame(2500, $d['cobrado_simulado_centavos']); self::assertSame(0, $d['cobrado_real_centavos']); self::assertSame(0, $d['saldo_centavos']);
        $this->intenta(fn() => $this->cobrar($this->finanzas, $cita, 'efectivo', '1.00'), 422);
        $pid = (int)R5CajaFixture::escalar($this->db, "SELECT id FROM pagos WHERE referencia_pago='$ref'");
        $this->intenta(fn() => $this->svc->devolver($this->finanzas, ['pago_id' => $pid, 'monto' => '1.00', 'motivo' => 'prueba', 'idem_key' => R5CajaFixture::clave()]), 409);
    }

    public function testPagoEnLineaEnCursoBloqueaElCobroEnCaja(): void
    {
        $cita = $this->cita(['25.00']);
        $ref = 'SIM-' . bin2hex(random_bytes(4));
        $this->db->query("INSERT INTO pagos (cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key) VALUES ($cita,$cita,{$this->cliente},'$ref','mock',25.00,'pendiente','tarjeta_mock','k-$ref')");
        $this->intenta(fn() => $this->cobrar($this->finanzas, $cita, 'efectivo', '5.00'), 409);
    }

    public function testAnticipoEnCitaCanceladaSeDevuelveYElEstadoLoRefleja(): void
    {
        $cita = $this->cita(['30.00'], 'confirmada');
        $r = $this->cobrar($this->finanzas, $cita, 'efectivo', '10.00');
        $this->db->query("UPDATE citas SET estado='cancelada' WHERE id=$cita");
        $d = $this->svc->detalle($cita);
        self::assertTrue($d['anticipo_sin_resolver']); self::assertFalse($d['cobrable']);
        $this->svc->devolver($this->finanzas, ['pago_id' => $r['pago_id'], 'monto' => 'saldo', 'motivo' => 'Cita cancelada', 'idem_key' => R5CajaFixture::clave()]);
        $d = $this->svc->detalle($cita);
        self::assertSame('reembolsado', $d['estado']); self::assertFalse($d['anticipo_sin_resolver']);
        self::assertSame(0, $this->caja($cita));
    }

    public function testCadaMutacionDejaAuditoriaConAntesYDespues(): void
    {
        $cita = $this->cita(['10.00']);
        $r = $this->cobrar($this->finanzas, $cita, 'efectivo', '10.00');
        $this->svc->devolver($this->dueno, ['pago_id' => $r['pago_id'], 'monto' => '4.00', 'motivo' => 'Ajuste', 'idem_key' => R5CajaFixture::clave()]);
        $ev = $this->db->query("SELECT tipo,actor_id,actor_rol,antes_json,despues_json FROM cobros_eventos WHERE cita_id=$cita ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        self::assertSame(['cobro_efectivo', 'devolucion'], array_column($ev, 'tipo'));
        self::assertSame([(string)$this->finanzas, (string)$this->dueno], array_column($ev, 'actor_id'));
        self::assertSame(['finanzas', 'propietario'], array_column($ev, 'actor_rol'));
        self::assertSame('pendiente', json_decode($ev[0]['antes_json'], true)['estado_pago']);
        self::assertSame('pagado', json_decode($ev[0]['despues_json'], true)['estado_pago']);
        self::assertSame('parcial', json_decode($ev[1]['despues_json'], true)['estado_pago']);
        $this->expectException(\mysqli_sql_exception::class);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try { $this->db->query("DELETE FROM cobros_eventos WHERE cita_id=$cita"); } finally { mysqli_report(MYSQLI_REPORT_OFF); }
    }

    public function testLaListaSqlYElDetallePhpCalculanLoMismoEnEscenariosMixtos(): void
    {
        $repo = new \Repositories\CajaRepository(new OperacionRepository($this->db));
        $escenarios = [];
        $a = $this->cita(['20.00', '15.50']); $this->cobrar($this->finanzas, $a, 'efectivo', '10.00'); $escenarios[] = $a;
        $b = $this->cita(['40.00']); $x = $this->cobrar($this->finanzas, $b, 'transferencia', '25.00', ['referencia' => 'LST-' . bin2hex(random_bytes(3))]); $escenarios[] = $b;
        $c = $this->cita(['40.00']); $y = $this->cobrar($this->finanzas, $c, 'efectivo', '40.00'); $this->svc->devolver($this->dueno, ['pago_id' => $y['pago_id'], 'monto' => '15.00', 'motivo' => 'Ajuste', 'idem_key' => R5CajaFixture::clave()]); $escenarios[] = $c;
        $d = $this->cita(['12.00']); $z = $this->cobrar($this->finanzas, $d, 'efectivo', '12.00'); $this->svc->devolver($this->dueno, ['pago_id' => $z['pago_id'], 'monto' => 'saldo', 'motivo' => 'Total', 'idem_key' => R5CajaFixture::clave()]); $escenarios[] = $d;
        $e = $this->cita(['25.00']); $ref = 'SIML-' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO pagos (cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,pagado_at) VALUES ($e,$e,{$this->cliente},'$ref','mock',25.00,'completado','tarjeta_mock','k-$ref',NOW())"); $escenarios[] = $e;
        $f = $this->cita(['30.00'], 'confirmada'); $w = $this->cobrar($this->finanzas, $f, 'efectivo', '10.00'); $this->db->query("UPDATE citas SET estado='cancelada' WHERE id=$f"); $escenarios[] = $f;
        $filtros = ['desde' => '2030-01-01', 'hasta' => '2030-01-31', 'estado' => '', 'metodo' => '', 'profesional' => $this->prof, 'q' => '', 'pagina' => 1];
        $filas = []; foreach ($repo->todas($filtros) as $fila) { $filas[$fila['id']] = $fila; }
        $suma = ['total' => 0, 'cobrado' => 0, 'devuelto' => 0, 'pendiente' => 0, 'por_cobrar' => 0, 'anticipo_sin_resolver' => 0];
        foreach ($escenarios as $id) {
            $det = $this->svc->detalle($id); $fila = $filas[$id] ?? null;
            self::assertNotNull($fila, "cita $id en el universo");
            self::assertSame([$det['total_centavos'], $det['cobrado_centavos'], $det['devuelto_centavos'], $det['pendiente_verificacion_centavos'], $det['estado'], $det['por_cobrar_centavos'], $det['anticipo_sin_resolver_centavos']],
                [$fila['total'], $fila['cobrado'], $fila['devuelto'], $fila['pendiente'], $fila['estado_pago'], $fila['por_cobrar'], $fila['anticipo_sin_resolver']], "cita $id");
            if ($id !== $e) { self::assertSame($det['estado'], R5CajaFixture::escalar($this->db, "SELECT estado_pago FROM citas WHERE id=$id"), "estado persistido de $id"); } // $e: pago simulado insertado a mano, sin pasar por la pasarela
        }
        $tot = $repo->totales($filtros);
        foreach ($filas as $fila) { foreach ($suma as $k => $_) { $suma[$k] += $fila[$k]; } }
        self::assertSame($suma, array_intersect_key($tot, $suma), 'los indicadores son la suma exacta de las filas del mismo universo');
        self::assertSame(count($filas), $tot['citas']);
        // y los filtros reducen lista, filas y totales por igual
        $solo = $filtros; $solo['estado'] = 'reembolsado';
        self::assertSame([$d], array_column(iterator_to_array($repo->todas($solo), false), 'id'));
        self::assertSame(1, $repo->totales($solo)['citas']);
    }

    public function testLaPasarelaNoCobraElTotalSiLaCitaTieneCobrosEnCaja(): void
    {
        $cita = $this->cita(['25.00'], 'confirmada');
        $this->cobrar($this->finanzas, $cita, 'efectivo', '5.00');
        $res = (new \Services\PagoService(new \Repositories\PagoRepository($this->db)))->iniciarPago($this->cliente, ['cita_id' => $cita]);
        self::assertSame(409, $res['http_code']);
        self::assertSame(1, (int)R5CajaFixture::escalar($this->db, "SELECT COUNT(*) FROM pagos WHERE cita_id=$cita"));
    }
}
