<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5bCase.php';

/**
 * R5b-QA · Regularización y consistencia financiera (decisión 3 del usuario; docs/R5_REGULARIZACION.md §3): regularizar → cobrar lleva la fecha REAL del
 * cobro en pago, caja, comprobante y comisión (nunca la de la cita ni la efectiva); conciliación; liquidación cerrada intacta; reportes por fecha de servicio
 * (día original) vs fecha de cobro (día real); devolución posterior coherente.
 */
final class R5bQaFinanzasTest extends R5bCase
{
    private static string $base;
    private static array $e;
    private const SERVICIO = 'Servicio Regularizado Q5';

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorB('fin');
        self::$e = self::escenarioB();
    }

    public static function tearDownAfterClass(): void { self::pararServidorB('fin'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function n(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }

    private function viejaReg(int $dias = 3, string $precio = '20.00', ?int $pro = null): int
    {
        $c = self::citaVieja(self::$e, ['dias' => $dias, 'servicios' => [[self::SERVICIO, $precio]]] + ($pro ? ['pro' => $pro] : []));
        $this->aceptado($this->regularizar($this->n('dueno'), $c, ['efectiva' => self::efectiva($c, '11:30')]), 'regularizar');
        return $c;
    }

    /** Cobro por la pantalla de caja (formulario real). */
    private function cobrar(Navegador $n, int $cita, string $monto, string $metodo = 'efectivo', string $ref = ''): array
    {
        $r = $n->get("/caja/cita?id=$cita");
        $this->assertSame(200, $r['status'], "GET /caja/cita?id=$cita → {$r['status']}");
        $f = self::formulario($r['body'], '~/caja/cita/cobrar~');
        $this->assertNotNull($f, 'sin formulario de cobro tras regularizar');
        $c = [];
        $m = self::campo($f, '/monto|importe|valor|cantidad/i', '/recib|entreg|cambio|total|saldo|pendiente/i'); $c[$m] = $monto;
        if (($rc = self::campo($f, '/recib|entreg/i'))) { $c[$rc] = $monto; }
        if (($rf = self::campo($f, '/refer/i')) && $ref !== '') { $c[$rf] = $ref; }
        $me = self::campo($f, '/metodo|medio|forma/i');
        $c[$me] = $metodo;
        foreach ($f['selects'][$me] ?? [] as $o) { if (stripos($o, $metodo) !== false) { $c[$me] = $o; } }
        return self::enviar($n, $f, $c);
    }

    private function ahoraOk(?string $dt, string $que): void
    {
        $this->assertNotNull($dt, "$que: sin fecha");
        $this->assertLessThan(180, self::difSeg($dt), "$que lleva «{$dt}» y debe ser el momento REAL del cobro (ahora)");
    }

    // ------------------------------------------------------------------

    /** Req 3: regularizar y COBRAR en efectivo: pago, caja, comprobante y comisión llevan la fecha real; la cita conserva su día; nada se retrofecha; estado pagado; conciliación sin violaciones. */
    public function test_F_regularizar_y_cobrar_efectivo_no_retrodata(): void
    {
        $c = $this->viejaReg(4);
        $n = $this->n('dueno');
        $this->aceptado($this->cobrar($n, $c, '20.00'), 'cobro tras regularizar');
        $cita = self::cita($c);
        $this->assertSame('pagado', $cita['estado_pago']);
        $this->assertSame('completada', $cita['estado']);
        $this->assertSame(self::gye('-4 days')->format('Y-m-d'), $cita['fecha'], 'la fecha original no se toca');
        $p = self::q('SELECT * FROM pagos WHERE cita_referencia_id=?', [$c])[0];
        $this->ahoraOk($p['pagado_at'], 'pagos.pagado_at');
        $this->assertStringStartsNotWith($cita['fecha'], (string)$p['pagado_at'], 'no se retrodata al día de la cita');
        $this->assertStringNotContainsString(substr($cita['atencion_efectiva_at'], 0, 10) . ' 11:30', (string)$p['pagado_at'], 'tampoco a la fecha efectiva');
        $caja = self::q('SELECT * FROM caja_movimientos WHERE pago_id=?', [$p['id']])[0];
        $this->ahoraOk($caja['registrado_en'], 'caja_movimientos.registrado_en');
        $this->assertSame(self::$e['dueno']['id'], (int)$caja['actor_id']);
        $cmp = self::q('SELECT * FROM comprobantes WHERE cita_id=?', [$c])[0];
        $this->ahoraOk($cmp['emitido_en'], 'comprobantes.emitido_en');
        $com = self::q('SELECT * FROM comisiones_movimientos WHERE pago_id=?', [$p['id']]);
        $this->assertCount(1, $com);
        $this->ahoraOk($com[0]['registrado_en'], 'comisiones_movimientos.registrado_en');
        $this->assertSame(200, (int)$com[0]['monto_centavos']);
        // El comprobante impreso no presenta la cita como atendida "hoy" ni inventa fechas.
        $ruta = null;
        foreach (self::destinos($this->detalle($n, $c)['body']) as $d) { if (self::pathDe($d) === '/caja/comprobante') { $ruta = $d; } }
        $this->assertNotNull($ruta, 'el detalle de la cita regularizada debe enlazar al comprobante');
        $txt = self::texto($n->get($ruta)['body']);
        $this->assertStringContainsString($cita['fecha'], $txt, 'el comprobante conserva la fecha original de la cita');
        $this->sinFallos(self::violacionesFinancieras(), 'conciliación:');
    }

    /** Req 3: transferencia tras regularizar — nada suma ni devenga hasta verificar; al verificar, caja/comisión llevan el momento real de la verificación. */
    public function test_F_regularizar_y_cobrar_transferencia_verificada(): void
    {
        $c = $this->viejaReg(5);
        $n = $this->n('dueno');
        $this->aceptado($this->cobrar($n, $c, '20.00', 'transferencia', 'TRF-' . random_int(100000, 999999)), 'transferencia');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM caja_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=?", [$c]), 'sin verificar no entra a caja');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM comisiones_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=?", [$c]));
        $fin = $this->n('finanzas');
        $v = self::formulario($fin->get("/caja/cita?id=$c")['body'], '~/caja/pago/verificar~');
        $this->assertNotNull($v);
        $this->aceptado(self::enviar($fin, $v), 'verificar');
        $p = self::q('SELECT * FROM pagos WHERE cita_referencia_id=?', [$c])[0];
        $caja = self::q('SELECT * FROM caja_movimientos WHERE pago_id=?', [$p['id']])[0];
        $this->ahoraOk($caja['registrado_en'], 'caja al verificar');
        $com = self::q('SELECT * FROM comisiones_movimientos WHERE pago_id=?', [$p['id']])[0];
        $this->ahoraOk($com['registrado_en'], 'comisión al verificar');
        $this->assertSame('pagado', self::cita($c)['estado_pago']);
        $this->assertSame(self::gye('-5 days')->format('Y-m-d'), self::cita($c)['fecha']);
        $this->sinFallos(self::violacionesFinancieras(), 'conciliación:');
    }

    /** Req 3: devolución posterior a regularizar y cobrar: la caja registra el egreso con fecha real, el estado y la comisión neta son coherentes y la fecha de la cita no se mueve. */
    public function test_F_devolucion_posterior_es_coherente(): void
    {
        $c = $this->viejaReg(3, '30.00');
        $n = $this->n('dueno');
        $this->aceptado($this->cobrar($n, $c, '30.00'), 'cobro');
        $f = self::formulario($n->get("/caja/cita?id=$c")['body'], '~devol~i');
        $this->assertNotNull($f, 'sin formulario de devolución');
        $campos = [];
        if (($m = self::campo($f, '/monto|importe|valor|cantidad/i'))) { $campos[$m] = '10.00'; }
        if (($mo = self::campo($f, '/motivo|concepto|razon/i'))) { $campos[$mo] = 'Devolución posterior a regularizar'; }
        $this->aceptado(self::enviar($n, $f, $campos), 'devolución parcial');
        $eg = self::q("SELECT m.* FROM caja_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=? AND m.tipo='egreso'", [$c]);
        $this->assertCount(1, $eg);
        $this->ahoraOk($eg[0]['registrado_en'], 'egreso de caja');
        $this->assertSame(1000, (int)$eg[0]['monto_centavos']);
        $neta = (int)self::escalar("SELECT COALESCE(SUM(m.monto_centavos),0) FROM comisiones_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=? AND m.estado<>'anulada'", [$c]);
        $this->assertSame(200, $neta, 'comisión neta = 10 % de los $20.00 retenidos');
        $this->assertSame('parcial', self::cita($c)['estado_pago']);
        $this->assertSame(self::gye('-3 days')->format('Y-m-d'), self::cita($c)['fecha']);
        $this->sinFallos(self::violacionesFinancieras(), 'conciliación:');
    }

    /**
     * Req 3: una LIQUIDACIÓN CERRADA no cambia por regularizar y cobrar después: su monto y sus filas quedan; la comisión nueva nace con la fecha real, queda pendiente
     * y entra en la SIGUIENTE liquidación.
     */
    public function test_F_liquidacion_cerrada_intacta_y_comision_nueva_en_la_siguiente(): void
    {
        $n = $this->n('dueno');
        $pro = (int)self::$e['proB']['profesional_id'];   // profesional propio de esta prueba (sin comisiones de otras pruebas)
        self::q('INSERT INTO comisiones_config(profesional_id,porcentaje_pb,actor_id) VALUES (?,1000,?) ON DUPLICATE KEY UPDATE porcentaje_pb=1000', [$pro, self::$e['dueno']['id']]);
        // Una cita normal de hoy cobrada y liquidada.
        [$f, $h] = self::slotComenzado();
        $hoy = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => $pro, 'fecha' => $f, 'hora' => $h, 'estado' => 'completada', 'servicios' => [['Corte hoy', '50.00']]]);
        $this->aceptado($this->cobrar($n, $hoy, '50.00'), 'cobro del día');
        $fin = $n->get('/admin/finanzas');
        $form = null;
        foreach (self::formularios($fin['body']) as $fm) { if (($fm['campos']['accion'] ?? '') === 'liquidar_comision') { $form = $fm; } }
        $this->assertNotNull($form, 'no existe el formulario de liquidación de comisiones en /admin/finanzas');
        $cambios = ['profesional_id' => (string)$pro, 'hasta' => self::gye()->format('Y-m-d'), 'comprobante' => 'LIQ-Q5-1'];
        $me = self::campo($form, '/metodo/i'); if ($me) { $cambios[$me] = 'efectivo'; }
        $ik = self::campo($form, '/idem/i'); if ($ik) { $cambios[$ik] = bin2hex(random_bytes(12)); }
        $this->aceptado(self::enviar($n, $form, $cambios), 'liquidar');
        $liq = self::q('SELECT * FROM comisiones_liquidaciones')[0];
        $filasLiq = self::q("SELECT id,estado,liquidacion_id,monto_centavos,registrado_en FROM comisiones_movimientos WHERE liquidacion_id=? ORDER BY id", [$liq['id']]);
        $this->assertCount(1, $filasLiq);
        $this->assertSame(500, (int)$liq['monto_centavos']);
        // Ahora una cita vencida regularizada y cobrada.
        $c = $this->viejaReg(6, '40.00', $pro);
        $this->aceptado($this->cobrar($n, $c, '40.00'), 'cobro tras la liquidación');
        $liq2 = self::q('SELECT * FROM comisiones_liquidaciones WHERE id=?', [$liq['id']])[0];
        $this->assertEquals($liq, $liq2, 'la liquidación cerrada no cambia');
        $this->assertEquals($filasLiq, self::q("SELECT id,estado,liquidacion_id,monto_centavos,registrado_en FROM comisiones_movimientos WHERE liquidacion_id=? ORDER BY id", [$liq['id']]), 'sus filas liquidadas no cambian');
        $nueva = self::q("SELECT m.* FROM comisiones_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=?", [$c]);
        $this->assertCount(1, $nueva);
        $this->assertSame('pendiente', $nueva[0]['estado']);
        $this->assertNull($nueva[0]['liquidacion_id'], 'la comisión nueva no es absorbida por la liquidación cerrada');
        $this->ahoraOk($nueva[0]['registrado_en'], 'comisión nueva');
        // La siguiente liquidación la incluye.
        $form = null;
        foreach (self::formularios($n->get('/admin/finanzas')['body']) as $fm) { if (($fm['campos']['accion'] ?? '') === 'liquidar_comision') { $form = $fm; } }
        $cambios[$ik] = bin2hex(random_bytes(12)); $cambios['comprobante'] = 'LIQ-Q5-2';
        $this->aceptado(self::enviar($n, $form, $cambios), 'segunda liquidación');
        $this->assertCount(2, self::q('SELECT id FROM comisiones_liquidaciones'));
        $this->assertSame(400, (int)self::escalar('SELECT monto_centavos FROM comisiones_liquidaciones WHERE id<>? ORDER BY id DESC LIMIT 1', [$liq['id']]), 'la siguiente liquidación paga exactamente la comisión nueva ($4.00)');
        $this->assertEquals($liq['monto_centavos'], self::q('SELECT monto_centavos FROM comisiones_liquidaciones WHERE id=?', [$liq['id']])[0]['monto_centavos']);
        $this->sinFallos(self::violacionesFinancieras(), 'conciliación:');
    }

    private function csvReporte(Navegador $n, string $desde, string $hasta, string $criterio): array
    {
        $r = $n->get('/admin/reportes/exportar?' . http_build_query(['fecha_inicio' => $desde, 'fecha_fin' => $hasta, 'criterio_fecha' => $criterio, 'formato' => 'csv']));
        $this->assertSame(200, $r['status'], "reporte $criterio $desde..$hasta → {$r['status']}");
        return array_values(array_filter(self::csvFilas($r['body']), fn($f) => str_contains(implode('|', $f), self::SERVICIO)));
    }

    /** Req 3: reportes — por FECHA DE SERVICIO la cita regularizada figura en su día original (no en el del cobro); por FECHA DE COBRO figura el día real del cobro. */
    public function test_F_reportes_por_fecha_de_servicio_y_por_fecha_de_cobro(): void
    {
        $dias = 7;
        $c = $this->viejaReg($dias, '25.00');
        $n = $this->n('dueno');
        $this->aceptado($this->cobrar($n, $c, '25.00'), 'cobro');
        $orig = self::gye("-$dias days")->format('Y-m-d'); $hoy = self::gye()->format('Y-m-d');
        $ayer = self::gye('-1 day')->format('Y-m-d');
        $this->assertNotEmpty($this->csvReporte($n, $orig, $orig, 'servicio'), 'por fecha de servicio debe figurar el día ORIGINAL');
        $this->assertEmpty($this->csvReporte($n, $hoy, $hoy, 'servicio'), 'por fecha de servicio NO debe figurar el día del cobro');
        $this->assertNotEmpty($this->csvReporte($n, $hoy, $hoy, 'cobro'), 'por fecha de cobro debe figurar el día REAL del cobro');
        $this->assertEmpty($this->csvReporte($n, $orig, $ayer, 'cobro'), 'por fecha de cobro NO debe figurar el día original (no se retrodata)');
    }

    /**
     * Req 3 (contrato §3, precisado por el integrador tras este hallazgo): el efectivo del día REAL se concilia en Finanzas
     * (`caja_movimientos.registrado_en`); `/caja` es la lista de citas por cobrar y filtra por el día de la CITA (la pantalla lo dice).
     * Un cobro de hoy sobre una cita antigua figura hoy en Finanzas, no figura en Finanzas del día de la cita, y `/caja` lo muestra con la
     * fecha de la cita sin retrofechar el cobro.
     */
    public function test_F_caja_por_dia_real(): void
    {
        $c = $this->viejaReg(8, '15.00');
        $n = $this->n('dueno');
        $this->aceptado($this->cobrar($n, $c, '15.00'), 'cobro');
        $orig = self::gye('-8 days')->format('Y-m-d'); $hoy = self::gye()->format('Y-m-d');
        $finHoy = $n->get("/admin/finanzas?inicio=$hoy&fin=$hoy");
        $finOrig = $n->get("/admin/finanzas?inicio=$orig&fin=$orig");
        $this->assertSame(200, $finHoy['status']);
        $this->assertStringContainsString('Cobro de la cita #' . $c, $finHoy['body'], 'Finanzas de HOY lista el cobro (fecha real)');
        $this->assertStringNotContainsString('Cobro de la cita #' . $c, $finOrig['body'], 'Finanzas del día de la cita NO lista un cobro de hoy');
        $caja = $n->get("/caja?desde=$orig&hasta=$orig");
        $this->assertSame(200, $caja['status']);
        $this->assertStringContainsString('fecha programada de la cita', self::texto($caja['body']), '/caja avisa que filtra por la fecha programada de la cita');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM caja_movimientos WHERE pago_id IN (SELECT id FROM pagos WHERE cita_id=?) AND DATE(registrado_en)=?", [$c, $hoy]), 'el movimiento de caja lleva la fecha real');
    }
}
