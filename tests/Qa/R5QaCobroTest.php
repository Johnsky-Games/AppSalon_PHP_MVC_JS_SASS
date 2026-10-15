<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * R5-QA · Cobro, caja, comprobante y conciliación (matriz 4 del usuario). HTTP real; la verdad se lee de la BASE DE DATOS, no del HTML.
 *
 * Requisitos: C1 efectivo con cambio · C2 transferencia pendiente vs verificada · C3 anticipos + saldo + exceso · C4 devoluciones ·
 * C5 manipulación de cita_id/monto/cliente · C6 doble clic e idempotencia (misma clave con otros datos ⇒ 409) · C7 DOS PROCESOS cobrando la misma cita ·
 * C8 cobro fallido tras finalizar (cita atendida con saldo y reintento sin duplicados) y atomicidad pago+caja+comisión · C9 recepción sin finanzas.gestionar ·
 * C10 comprobante (único, correlativo bajo concurrencia, importes coherentes, leyenda de comprobante interno) · C11 conciliación pagos↔caja↔comisiones↔estado_pago.
 *
 * Los formularios se leen de /caja/cita?id=N tal como los vería un navegador (ver R5Case); si no existen, el fallo lo dice.
 */
final class R5QaCobroTest extends R5Case
{
    private static string $base;
    private static array $e;
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorQ5('cobro');
        self::$e = self::escenario();
        // Comisión del 10 % para ProA (porcentaje en puntos básicos).
        self::q('INSERT INTO comisiones_config(profesional_id,porcentaje_pb,actor_id) VALUES (?,1000,?) ON DUPLICATE KEY UPDATE porcentaje_pb=1000', [self::$e['proA']['profesional_id'], self::$e['dueno']['id']]);
    }

    public static function tearDownAfterClass(): void { self::pararServidorQ5('cobro'); }

    protected function setUp(): void
    {
        self::q('TRUNCATE intentos_login');
        // Aislamiento: cada prueba parte de finanzas vacías (los invariantes y los conteos son globales).
        $db = self::db();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::q("SELECT table_name t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND (table_name REGEXP 'pago|caja_|comision|comprobante|cobro|devol|idempot|exportaciones' ) AND table_name NOT LIKE '%resguardo%' AND table_name NOT LIKE '%config%'") as $t) {
            $nombre = $t['t'];
            if (str_contains($nombre, 'secuencia') || str_contains($nombre, 'contador')) { continue; }
            $db->query('TRUNCATE TABLE `' . $nombre . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        foreach (['caja_movimientos', 'comisiones_movimientos', 'pagos'] as $t) { self::q("DROP TRIGGER IF EXISTS q5_falla_$t"); }
    }

    // ------------------------------------------------------------------ ayudas

    private function eq(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }

    /** @param array<int,array{0:string,1:string}> $servicios */
    private function cita(array $servicios = [['Corte Q5', '20.00']], string $estado = 'completada', ?int $cliente = null): int
    {
        [$f, $h] = self::slotComenzado(++self::$seq);
        return self::crearCita(['cliente' => $cliente ?? self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h, 'estado' => $estado, 'servicios' => $servicios]);
    }

    /** Pantalla de cobro de la cita. Falla con un mensaje preciso si no existe. */
    private function pantalla(Navegador $n, int $cita): array
    {
        $r = $n->get("/caja/cita?id=$cita");
        $this->assertSame(200, $r['status'], "GET /caja/cita?id=$cita → {$r['status']} " . substr(self::texto($r['body']), 0, 160));
        return $r;
    }

    private function formCobro(array $pantalla): array
    {
        $f = self::formulario($pantalla['body'], '~/caja/cita/cobrar~');
        $this->assertNotNull($f, 'la pantalla de cobro no tiene un formulario hacia /caja/cita/cobrar. Formularios: ' . json_encode(array_column(self::formularios($pantalla['body']), 'action')));
        return $f;
    }

    /**
     * Traduce datos lógicos (monto, metodo, recibido, referencia, clave) a los campos reales del formulario y lo envía.
     * @param array<string,mixed> $d  monto (centavos|string) · metodo · recibido (centavos|string) · referencia · clave ('' = quitar) · crudo (campos tal cual)
     */
    private function cobrar(Navegador $n, int $cita, array $d, ?array $form = null): array
    {
        $form ??= $this->formCobro($this->pantalla($n, $cita));
        $c = [];
        $map = ['monto' => ['/monto|importe|valor|cantidad/i', '/recib|entreg|cambio|total|saldo|pendiente/i'], 'recibido' => ['/recib|entreg/i', null], 'referencia' => ['/refer|operaci|comprobante_ext|nro|numero_/i', null],
            'clave' => ['/idem|clave_op|operacion_id|token_op/i', null]];
        foreach (['monto', 'recibido', 'referencia', 'clave'] as $k) {
            if (!array_key_exists($k, $d)) { continue; }
            $campo = self::campo($form, $map[$k][0], $map[$k][1]);
            $this->assertNotNull($campo, "el formulario de cobro no tiene un campo para «{$k}». Campos: " . json_encode(array_keys($form['campos'] + $form['selects'])));
            $v = $d[$k];
            $c[$campo] = is_int($v) ? self::dolares($v) : (string)$v;
        }
        if (isset($d['metodo'])) {
            $campo = self::campo($form, '/metodo|medio|forma/i');
            $this->assertNotNull($campo, 'el formulario no tiene campo de método de pago. Campos: ' . json_encode(array_keys($form['campos'] + $form['selects'])));
            $opts = $form['selects'][$campo] ?? [];
            $v = $d['metodo'];
            foreach ($opts as $o) { if (stripos($o, $d['metodo']) !== false) { $v = $o; break; } }
            $c[$campo] = $v;
        }
        foreach ($d['crudo'] ?? [] as $k => $v) { $c[$k] = $v; }
        $quitar = [];
        if (array_key_exists('clave', $d) && $d['clave'] === '') {
            $campo = self::campo($form, $map['clave'][0]);
            if ($campo) { $quitar[] = $campo; unset($c[$campo]); }
        }
        return self::enviar($n, $form, $c, $quitar);
    }

    private function pagos(int $cita): array { return self::q('SELECT * FROM pagos WHERE cita_referencia_id=? ORDER BY id', [$cita]); }

    private function confirmados(int $cita): int
    {
        return (int)self::escalar("SELECT COUNT(*) FROM pagos p JOIN caja_movimientos m ON m.pago_id=p.id AND m.tipo='ingreso' WHERE p.cita_referencia_id=?", [$cita]);
    }

    private function neto(int $cita): int { return self::resumenFinanciero($cita)['neto']; }

    private function conciliado(string $contexto = ''): void
    {
        $this->sinFallos(self::violacionesFinancieras(), "conciliación rota $contexto:");
    }

    private function aceptado(array $r, string $ctx): void
    {
        $this->assertContains($r['status'], [200, 302, 303], "$ctx: la operación debió aceptarse; respuesta {$r['status']}: " . substr(self::texto($r['body']), 0, 220));
    }

    private function rechazado(array $r, string $ctx, array $codigos = [400, 403, 404, 409, 422]): void
    {
        $this->assertContains($r['status'], $codigos, "$ctx: debió rechazarse con " . implode('/', $codigos) . ", respuesta {$r['status']}: " . substr(self::texto($r['body']), 0, 220));
        $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'), "$ctx: el error enlaza al portal del cliente");
    }

    private function claveNueva(): string { return 'q5-' . bin2hex(random_bytes(10)); }

    // ================================================================== C1 efectivo

    /** Req C1: efectivo con cambio — se cobra el IMPORTE APLICADO, el cambio no es ingreso; la caja y la comisión usan el importe aplicado. */
    public function test_C1_efectivo_con_cambio_no_cuenta_el_cambio_como_ingreso(): void
    {
        $c = $this->cita([['Corte Q5', '18.50'], ['Brushing', '5.00']]);
        $n = $this->eq('dueno');
        $r = $this->cobrar($n, $c, ['monto' => 2350, 'metodo' => 'efectivo', 'recibido' => 5000]);
        $this->aceptado($r, 'cobro en efectivo con cambio');
        $f = self::resumenFinanciero($c);
        $this->assertSame(2350, $f['ingresos'], 'la caja debe registrar $23.50 (no los $50.00 entregados)');
        $this->assertSame('pagado', $f['estado_pago']);
        $this->assertCount(1, $f['pagos']);
        $this->assertSame('completado', $f['pagos'][0]['estado']);
        $this->assertSame(2350, self::centavos($f['pagos'][0]['monto']));
        $this->assertSame(235, (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos WHERE tipo='devengo'"), 'la comisión (10 %) se calcula sobre $23.50 → $2.35');
        $loc = self::ubicacion($r);
        $fin = $n->get($loc !== '' ? $loc : "/caja/cita?id=$c");
        $txt = self::texto($fin['body']);
        $this->assertStringContainsString('26.50', $txt, 'la pantalla posterior al cobro debe mostrar el cambio a devolver ($26.50)');
        $this->conciliado();
    }

    /** Req C1: efectivo recibido MENOR que el importe a aplicar ⇒ rechazo y nada registrado. */
    public function test_C1_efectivo_recibido_insuficiente_se_rechaza(): void
    {
        $c = $this->cita();
        $r = $this->cobrar($this->eq('dueno'), $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 1500]);
        $this->rechazado($r, 'efectivo recibido $15.00 para cobrar $20.00', [400, 409, 422]);
        $this->assertCount(0, $this->pagos($c));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM caja_movimientos'));
    }

    // ================================================================== C2 transferencia

    /**
     * Req C2: una TRANSFERENCIA declarada queda «por verificar»: NO suma a caja, NO genera comisión y la cita pasa a por_verificar; al verificarla
     * quien tiene finanzas.gestionar, recién entonces entra a caja y devenga comisión (quién y cuándo verificó queda auditado).
     */
    public function test_C2_transferencia_pendiente_no_suma_a_caja_ni_comision_hasta_verificar(): void
    {
        $c = $this->cita();
        $n = $this->eq('dueno');
        $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => 'TRF-' . random_int(100000, 999999)]);
        $this->aceptado($r, 'registro de transferencia');
        $f = self::resumenFinanciero($c);
        $this->assertCount(1, $f['pagos']);
        $this->assertNotSame('completado', $f['pagos'][0]['estado'], 'una transferencia sin verificar no puede estar «completado»');
        $this->assertSame(0, $f['ingresos'], 'una transferencia sin verificar NO suma a caja');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM comisiones_movimientos"), 'una transferencia sin verificar NO devenga comisión');
        $this->assertSame('por_verificar', $f['estado_pago']);
        $this->assertNotSame('pagado', $f['estado_pago']);
        $this->assertSame(2000, $f['saldo'], 'el saldo no baja hasta verificar');
        $this->conciliado('con transferencia pendiente');
        // Recepción no puede verificar (ni siquiera ver la pantalla).
        $pantalla = $this->pantalla($n, $c);
        $v = self::formulario($pantalla['body'], '~/caja/pago/verificar~');
        $this->assertNotNull($v, 'la pantalla no ofrece verificar la transferencia a quien tiene finanzas.gestionar');
        $rec = $this->eq('recepcion');
        $x = self::enviar($rec, $v);
        $this->assertSame(403, $x['status'], 'recepción no puede verificar transferencias');
        $this->assertSame(0, $this->confirmados($c));
        // Finanzas verifica.
        $fin = $this->eq('finanzas');
        $v2 = self::formulario($this->pantalla($fin, $c)['body'], '~/caja/pago/verificar~');
        $this->assertNotNull($v2);
        $this->aceptado(self::enviar($fin, $v2), 'verificación por finanzas');
        $f = self::resumenFinanciero($c);
        $this->assertSame(2000, $f['ingresos']);
        $this->assertSame('pagado', $f['estado_pago']);
        $this->assertSame('completado', $f['pagos'][0]['estado']);
        $this->assertSame(200, (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos WHERE tipo='devengo'"), 'la comisión se devenga al verificar ($20.00 × 10 %)');
        // Auditoría de quién y cuándo verificó (columnas añadidas por 024; se descubren por nombre).
        $cols = array_column(self::q('SHOW COLUMNS FROM pagos'), 'Field');
        $quien = array_values(array_filter($cols, fn($k) => preg_match('/verific.*(por|actor|usuario)|(actor|usuario).*verific/i', $k)));
        $cuando = array_values(array_filter($cols, fn($k) => preg_match('/verific.*(_at|_en|fecha)|(_at|_en|fecha).*verific/i', $k)));
        $this->assertNotEmpty($quien, 'pagos no guarda QUIÉN verificó (columnas: ' . implode(',', $cols) . ')');
        $this->assertNotEmpty($cuando, 'pagos no guarda CUÁNDO se verificó');
        $fila = self::q('SELECT * FROM pagos WHERE id=?', [$f['pagos'][0]['id']])[0];
        $this->assertSame((string)self::$e['finanzas']['id'], (string)$fila[$quien[0]], 'el verificador registrado no es quien verificó');
        $this->assertNotNull($fila[$cuando[0]]);
        // Segunda verificación (doble clic): sin efecto.
        $dos = self::enviar($fin, $v2);
        $this->assertLessThan(500, $dos['status']);
        $this->assertSame(1, $this->confirmados($c), 'verificar dos veces no duplica el ingreso');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM comisiones_movimientos WHERE tipo='devengo'"));
        $this->conciliado('tras verificar');
    }

    /** Req C2: rechazar una transferencia no suma nada, deja la cita sin pagar y NO se puede verificar después. */
    public function test_C2_transferencia_rechazada_no_suma_y_no_se_puede_verificar_despues(): void
    {
        $c = $this->cita();
        $n = $this->eq('gerente');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => 'TRF-' . random_int(100000, 999999)]), 'registro de transferencia');
        // Separación de funciones (decisión documentada de p5): quien registró la transferencia no la verifica ni la rechaza.
        $propia = $this->pantalla($n, $c);
        $this->assertNull(self::formulario($propia['body'], '~/caja/pago/verificar~'), 'el registrante no debe poder verificar su propia transferencia');
        $n = $this->eq('finanzas');
        $p = $this->pantalla($n, $c);
        $rech = self::formulario($p['body'], '~/caja/pago/rechazar~');
        $ver = self::formulario($p['body'], '~/caja/pago/verificar~');
        $this->assertNotNull($rech, 'sin acción «Rechazar»');
        $this->assertNotNull($ver);
        $this->aceptado(self::enviar($n, $rech, ['nota' => 'No aparece en el extracto bancario']), 'rechazo');
        $this->assertSame(0, $this->confirmados($c));
        $this->assertContains((string)self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$c]), ['pendiente']);
        $x = self::enviar($n, $ver);
        $this->rechazado($x, 'verificar un pago ya rechazado', [409, 422, 404]);
        $this->assertSame(0, $this->confirmados($c));
        $this->conciliado();
    }

    /** Req C2 (criterio QA): la misma referencia bancaria no puede respaldar dos cobros distintos (fraude clásico). */
    public function test_C2_referencia_de_transferencia_duplicada_se_rechaza(): void
    {
        $a = $this->cita(); $b = $this->cita();
        $n = $this->eq('dueno');
        $ref = 'DUP-' . random_int(100000, 999999);
        $this->aceptado($this->cobrar($n, $a, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => $ref]), 'primera transferencia');
        $r = $this->cobrar($n, $b, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => $ref]);
        $this->rechazado($r, 'misma referencia en otra cita', [409, 422]);
        $this->assertCount(0, $this->pagos($b));
    }

    /** Req C2: transferencia sin referencia, con referencia vacía/hostil o demasiado larga ⇒ 422 y nada registrado; la hostil se muestra escapada. */
    public function test_C2_referencia_invalida_o_hostil(): void
    {
        $n = $this->eq('dueno');
        $c = $this->cita();
        foreach (['', '   ', str_repeat('X', 300)] as $malo) {
            $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => $malo]);
            $this->rechazado($r, 'referencia «' . substr($malo, 0, 12) . '…»', [400, 422]);
        }
        $this->assertCount(0, $this->pagos($c), 'no debe registrarse nada con referencias inválidas');
        $xss = '<img src=x onerror=alert(1)>';
        $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => $xss]);
        if (in_array($r['status'], [200, 302, 303], true)) {
            $p = $this->pantalla($n, $c);
            $this->assertStringNotContainsString($xss, $p['body'], 'la referencia hostil se refleja sin escapar');
            $this->assertStringContainsString(htmlspecialchars($xss, ENT_QUOTES), $p['body']);
        } else {
            $this->assertContains($r['status'], [400, 422]);
        }
    }

    // ================================================================== C3 anticipos / saldo

    /** Req C3: anticipo + saldo. $40.00 → anticipo $15.00 ⇒ parcial (saldo $25.00) → saldo $25.00 ⇒ pagado; la pantalla muestra anticipos, pagos y saldo. */
    public function test_C3_anticipo_mas_saldo(): void
    {
        $c = $this->cita([['Tinte Q5', '40.00']]);
        $n = $this->eq('dueno');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 1500, 'metodo' => 'efectivo', 'recibido' => 1500]), 'anticipo');
        $f = self::resumenFinanciero($c);
        $this->assertSame(1500, $f['neto']);
        $this->assertSame('parcial', $f['estado_pago']);
        $this->assertSame(2500, $f['saldo']);
        $txt = self::texto($this->pantalla($n, $c)['body']);
        foreach (['15.00', '25.00', '40.00'] as $esperado) { $this->assertStringContainsString($esperado, $txt, "la pantalla de cobro no muestra $esperado (anticipo/saldo/total)"); }
        $formularioViejo = $this->formCobro($this->pantalla($n, $c));   // pantalla abierta ANTES de pagar el saldo (otra pestaña)
        $this->aceptado($this->cobrar($n, $c, ['monto' => 2500, 'metodo' => 'efectivo', 'recibido' => 3000]), 'saldo');
        $f = self::resumenFinanciero($c);
        $this->assertSame(4000, $f['neto']);
        $this->assertSame('pagado', $f['estado_pago']);
        $this->assertSame(0, $f['saldo']);
        $this->assertSame(400, (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos WHERE tipo='devengo'"), 'comisión acumulada del 10 % sobre $40.00');
        $this->conciliado();
        // Ya pagada: otro cobro ⇒ rechazo.
        $x = $this->cobrar($n, $c, ['monto' => 100, 'metodo' => 'efectivo', 'recibido' => 100, 'clave' => bin2hex(random_bytes(16))], $formularioViejo);
        $this->rechazado($x, 'cobrar una cita ya pagada', [409, 422]);
        $this->assertSame(4000, $this->neto($c));
    }

    /** Req C3: EXCESO de saldo y montos inválidos (cero, negativo, no numérico, notación científica, 3 decimales, coma) ⇒ rechazo sin registrar nada. */
    public function test_C3_exceso_y_montos_invalidos(): void
    {
        $c = $this->cita([['Tinte Q5', '40.00']]);
        $n = $this->eq('dueno');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 1500, 'metodo' => 'efectivo', 'recibido' => 1500]), 'anticipo');
        $malos = ['25.01' => 'excede el saldo en un centavo', '9999999.99' => 'excede muy por encima', '0' => 'cero', '0.00' => 'cero', '-5.00' => 'negativo', 'abc' => 'no numérico',
            '1e3' => 'notación científica', '10.005' => 'tres decimales', '10,00' => 'coma decimal', '' => 'vacío', '  ' => 'espacios', '１０.００' => 'dígitos de ancho completo', '0x10' => 'hexadecimal'];
        $fallos = [];
        foreach ($malos as $monto => $porque) {
            $recibido = in_array($monto, ['25.01', '9999999.99'], true) ? $monto : '100.00';
            $r = $this->cobrar($n, $c, ['monto' => (string)$monto, 'metodo' => 'efectivo', 'recibido' => $recibido]);
            if (!in_array($r['status'], [400, 409, 422], true)) { $fallos[] = "monto «" . addcslashes((string)$monto, "\n") . "» ($porque): respuesta {$r['status']} (esperado 400/409/422)"; }
            if ($this->neto($c) !== 1500) { $fallos[] = "monto «" . addcslashes((string)$monto, "\n") . "» ($porque): MODIFICÓ la caja neta a " . $this->neto($c); self::q('DELETE FROM comisiones_movimientos'); }
        }
        $this->sinFallos($fallos);
        $this->assertCount(1, $this->pagos($c), 'solo el anticipo debe existir');
        $this->conciliado();
    }

    /** Req C3 (dinero exacto): 0.10 + 0.20 = 0.30 y 19.99 × 3 = 59.97 sin errores de coma flotante; pagos fraccionados que suman exacto. */
    public function test_C3_dinero_exacto_en_centavos(): void
    {
        $n = $this->eq('dueno');
        $a = $this->cita([['Servicio A', '0.10'], ['Servicio B', '0.20']]);
        $this->assertSame(30, self::totalCita($a));
        $this->aceptado($this->cobrar($n, $a, ['monto' => 30, 'metodo' => 'efectivo', 'recibido' => 100]), '0.10+0.20');
        $this->assertSame('pagado', self::resumenFinanciero($a)['estado_pago'], '0.10 + 0.20 debe cobrarse exactamente como 0.30');
        $b = $this->cita([['S1', '19.99'], ['S2', '19.99'], ['S3', '19.99']]);
        $this->aceptado($this->cobrar($n, $b, ['monto' => 3333, 'metodo' => 'efectivo', 'recibido' => 3333]), 'parcial 33.33');
        $this->aceptado($this->cobrar($n, $b, ['monto' => 2664, 'metodo' => 'efectivo', 'recibido' => 2664]), 'resto 26.64');
        $f = self::resumenFinanciero($b);
        $this->assertSame([5997, 'pagado', 0], [$f['neto'], $f['estado_pago'], $f['saldo']]);
        $this->conciliado();
    }

    /** Req C3: los precios son los HISTÓRICOS de la cita; cambiar el catálogo después no altera el total a cobrar. */
    public function test_C3_total_usa_precios_historicos_no_el_catalogo(): void
    {
        $c = $this->cita([['Corte Q5', '20.00']]);
        self::q('UPDATE servicios SET precio=99.00');
        $n = $this->eq('dueno');
        $txt = self::texto($this->pantalla($n, $c)['body']);
        $this->assertStringContainsString('20.00', $txt);
        $this->assertStringNotContainsString('99.00', $txt, 'el cobro usa el precio actual del catálogo en vez del histórico');
        $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000]);
        $this->aceptado($r, 'cobro al precio histórico');
        $this->assertSame('pagado', self::resumenFinanciero($c)['estado_pago']);
        self::q('UPDATE servicios SET precio=20.00');
    }

    // ================================================================== C4 devoluciones

    private function formDevolucion(Navegador $n, int $cita): ?array
    {
        return self::formulario($this->pantalla($n, $cita)['body'], '~devol|reembols|refund~i');
    }

    private function devolver(Navegador $n, int $cita, int $centavos, string $motivo = 'Devolución QA'): array
    {
        $f = $this->formDevolucion($n, $cita);
        $this->assertNotNull($f, 'la pantalla de cobro no ofrece devoluciones (no hay formulario cuya acción contenga devol/reembols)');
        $cambios = [];
        $m = self::campo($f, '/monto|importe|valor|cantidad/i');
        if ($m) { $cambios[$m] = self::dolares($centavos); }
        $mot = self::campo($f, '/motivo|concepto|razon|razón/i');
        if ($mot) { $cambios[$mot] = $motivo; }
        return self::enviar($n, $f, $cambios);
    }

    /** Req C4: devolución PARCIAL y total: la caja registra egresos, la cita pasa a parcial/reembolsado y la comisión se revierte proporcionalmente. */
    public function test_C4_devolucion_parcial_y_total(): void
    {
        $c = $this->cita([['Tinte Q5', '40.00']]);
        $n = $this->eq('dueno');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 4000, 'metodo' => 'efectivo', 'recibido' => 4000]), 'cobro completo');
        $this->aceptado($this->devolver($n, $c, 1000), 'devolución parcial $10.00');
        $f = self::resumenFinanciero($c);
        $this->assertSame([4000, 1000, 3000], [$f['ingresos'], $f['egresos'], $f['neto']]);
        $this->assertContains($f['estado_pago'], ['parcial'], 'tras devolver parte, la cita queda con saldo (parcial)');
        $this->assertSame(1000, $f['saldo']);
        $dev = (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos WHERE tipo='devengo'");
        $rev = (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos WHERE tipo='reversa'");
        $this->assertSame(300, $dev + $rev, 'la comisión neta debe ser el 10 % de lo realmente retenido ($30.00 → $3.00)');
        $this->conciliado('devolución parcial');
        $this->aceptado($this->devolver($n, $c, 3000), 'devolución del resto');
        $f = self::resumenFinanciero($c);
        $this->assertSame([4000, 4000, 0], [$f['ingresos'], $f['egresos'], $f['neto']]);
        $this->assertSame('reembolsado', $f['estado_pago']);
        $this->assertSame(0, (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos"));
        $this->conciliado('devolución total');
    }

    /** Req C4: devolver MÁS de lo cobrado (en una vez o acumulado) ⇒ rechazo; montos inválidos de devolución ⇒ rechazo. */
    public function test_C4_devolucion_mayor_a_lo_cobrado_se_rechaza(): void
    {
        $c = $this->cita([['Tinte Q5', '40.00']]);
        $n = $this->eq('dueno');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 2500, 'metodo' => 'efectivo', 'recibido' => 2500]), 'anticipo $25.00');
        $r = $this->devolver($n, $c, 2501);
        $this->rechazado($r, 'devolver $25.01 de $25.00 cobrados', [409, 422]);
        $r = $this->devolver($n, $c, 4000);
        $this->rechazado($r, 'devolver el total de la cita ($40) habiendo cobrado $25', [409, 422]);
        $this->aceptado($this->devolver($n, $c, 2000), 'devolver $20.00');
        $r = $this->devolver($n, $c, 501);
        $this->rechazado($r, 'devolver $5.01 cuando solo quedan $5.00 retenidos', [409, 422]);
        foreach (['0', '-1.00', 'abc', '1e2'] as $malo) {
            $f = $this->formDevolucion($n, $c);
            $m = self::campo($f, '/monto|importe|valor|cantidad/i');
            if ($m) { $this->rechazado(self::enviar($n, $f, [$m => $malo]), "devolución de «{$malo}»", [400, 409, 422]); }
        }
        $f = self::resumenFinanciero($c);
        $this->assertSame([2500, 2000, 500], [$f['ingresos'], $f['egresos'], $f['neto']]);
        $this->conciliado();
    }

    /** Req C4: una devolución de un cobro SIN confirmar (transferencia pendiente) no existe: nada que devolver. Y recepción no puede devolver. */
    public function test_C4_devolver_requiere_cobro_confirmado_y_permiso(): void
    {
        $c = $this->cita();
        $n = $this->eq('dueno');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => 'TRF-' . random_int(100000, 999999)]), 'transferencia');
        $f = $this->formDevolucion($n, $c);
        if ($f !== null) {
            $m = self::campo($f, '/monto|importe|valor|cantidad/i');
            $r = self::enviar($n, $f, $m ? [$m => '5.00'] : []);
            $this->rechazado($r, 'devolver dinero que nunca se confirmó', [409, 422]);
        }
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM caja_movimientos WHERE tipo='egreso'"));
        $cc = $this->cita();
        $this->aceptado($this->cobrar($n, $cc, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000]), 'cobro');
        $form = $this->formDevolucion($n, $cc);
        $this->assertNotNull($form);
        $x = self::enviar($this->eq('recepcion'), $form);
        $this->assertSame(403, $x['status'], 'recepción no puede devolver');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM caja_movimientos WHERE tipo='egreso'"));
    }

    // ================================================================== C5 manipulación

    /** Req C5: manipular cita_id/ids del formulario NO imputa el pago a otra cita ni a otro cliente: el pago queda coherente con una sola cita y su cliente. */
    public function test_C5_manipular_cita_y_cliente_no_imputa_a_otra_cita_ni_cliente(): void
    {
        $a = $this->cita([['Corte Q5', '20.00']]);
        $b = $this->cita([['Tinte Q5', '40.00']], 'completada', self::$e['cliente2']['id']);
        $n = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($n, $a));
        $cambios = [];
        foreach ($form['campos'] as $k => $v) {
            if ($v === (string)$a && !preg_match('/csrf/i', $k)) { $cambios[$k] = (string)$b; }
            if (preg_match('/usuario|cliente/i', $k) && ctype_digit((string)$v)) { $cambios[$k] = (string)self::$e['cliente2']['id']; }
        }
        $r = $this->cobrar($n, $a, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000, 'crudo' => $cambios], $form);
        $pa = $this->pagos($a); $pb = $this->pagos($b);
        $this->assertLessThanOrEqual(1, count($pa) + count($pb), 'un envío manipulado generó más de un pago');
        foreach (array_merge($pa, $pb) as $p) {
            $cita = (int)$p['cita_referencia_id'];
            $this->assertSame((int)self::escalar('SELECT usuarioId FROM citas WHERE id=?', [$cita]), (int)$p['usuario_id'], 'el pago quedó imputado a un cliente distinto del dueño de la cita');
            $this->assertSame($cita, (int)$p['cita_id']);
        }
        if ($cambios === []) { $this->markTestIncomplete('el formulario de cobro no trae ids ocultos manipulables (nada que alterar).'); }
        $this->conciliado();
    }

    /** Req C5: campos de TOTAL/SALDO/CLIENTE enviados por el navegador se ignoran: el servidor recalcula; un total manipulado nunca marca «pagado». */
    public function test_C5_total_y_saldo_manipulados_se_ignoran(): void
    {
        $c = $this->cita([['Tinte Q5', '40.00']]);
        $n = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($n, $c));
        $cambios = [];
        foreach ($form['campos'] as $k => $v) {
            if (preg_match('/total|saldo|pendiente|adeudado|estado/i', $k) && !preg_match('/csrf/i', $k)) { $cambios[$k] = '0.01'; }
        }
        $r = $this->cobrar($n, $c, ['monto' => 100, 'metodo' => 'efectivo', 'recibido' => 100, 'crudo' => $cambios + ['total' => '0.01', 'saldo' => '0.00', 'estado_pago' => 'pagado', 'usuario_id' => (string)self::$e['cliente2']['id'], 'cita_id' => (string)$c]], $form);
        $f = self::resumenFinanciero($c);
        $this->assertNotSame('pagado', $f['estado_pago'], 'un total manipulado en el navegador marcó la cita como pagada con $' . self::dolares($f['neto']));
        $this->assertLessThanOrEqual(100, $f['neto']);
        foreach ($f['pagos'] as $p) { $this->assertSame(self::$e['cliente']['id'], (int)$p['usuario_id'], 'el cliente del pago lo decide el servidor, no el formulario'); }
        $this->conciliado();
    }

    /** Req C5: método inexistente ('cheque', 'pasarela', 'tarjeta', vacío) ⇒ 422 sin pago; citas canceladas / ajenas inexistentes ⇒ rechazo. */
    public function test_C5_metodo_invalido_y_citas_no_cobrables(): void
    {
        $n = $this->eq('dueno');
        $c = $this->cita();
        $form = $this->formCobro($this->pantalla($n, $c));
        $campo = self::campo($form, '/metodo|medio|forma/i');
        $this->assertNotNull($campo);
        foreach (['cheque', 'pasarela', 'tarjeta', '', 'EFECTIVO; DROP TABLE pagos', 'efectivo%00'] as $m) {
            $r = self::enviar($n, $form, [$campo => $m, ($this->campoMonto($form) ?? 'monto') => '20.00']);
            $this->assertContains($r['status'], [400, 422], "método «{$m}» → {$r['status']}");
        }
        $this->assertCount(0, $this->pagos($c));
        $cancelada = $this->cita([['Corte Q5', '20.00']], 'cancelada');
        $r = $n->get("/caja/cita?id=$cancelada");
        if ($r['status'] === 200) {
            $f = self::formulario($r['body'], '~/caja/cita/cobrar~');
            $this->assertNull($f, 'una cita cancelada no debe ofrecer cobro');
        }
        $post = self::enviar($n, $form, ['id' => (string)$cancelada, 'cita_id' => (string)$cancelada, $campo => 'efectivo']);
        $this->assertContains($post['status'], [400, 403, 404, 409, 422]);
        $this->assertCount(0, $this->pagos($cancelada), 'se cobró una cita cancelada');
        foreach (['/caja/cita?id=99999999', '/caja/cita?id=0', '/caja/cita?id=-1', '/caja/cita?id=abc', '/caja/cita', '/caja/cita?id=1%27%20OR%201=1--'] as $ruta) {
            $r = $n->get($ruta);
            $this->assertContains($r['status'], [404, 422], "GET $ruta → {$r['status']}");
            $this->assertStringNotContainsString('SQLSTATE', $r['body']);
        }
    }

    private function campoMonto(array $form): ?string { return self::campo($form, '/monto|importe|valor|cantidad/i', '/recib|entreg|cambio|total|saldo|pendiente/i'); }

    /** Req C5: sin CSRF no se cobra (403/419), y un GET no puede cobrar. */
    public function test_C5_cobro_exige_csrf_y_metodo_post(): void
    {
        $c = $this->cita();
        $n = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($n, $c));
        $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000], array_merge($form, ['campos' => array_diff_key($form['campos'], ['csrf_token' => 1])]));
        $this->assertContains($r['status'], [403, 419], 'sin CSRF: ' . $r['status']);
        $g = $n->get('/caja/cita/cobrar?id=' . $c . '&monto=20.00&metodo=efectivo');
        $this->assertContains($g['status'], [404, 405], 'GET /caja/cita/cobrar: ' . $g['status']);
        $this->assertCount(0, $this->pagos($c));
    }

    // ================================================================== C6 doble clic / idempotencia

    /** Req C6: doble clic con la MISMA clave ⇒ un solo pago e ingreso; la segunda respuesta no es un error 5xx. */
    public function test_C6_doble_clic_misma_clave_un_solo_pago(): void
    {
        $c = $this->cita();
        $n = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($n, $c));
        $d = ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000];
        $r1 = $this->cobrar($n, $c, $d, $form);
        $r2 = $this->cobrar($n, $c, $d, $form);
        $this->aceptado($r1, 'primer clic');
        $this->assertLessThan(500, $r2['status']);
        $this->assertCount(1, $this->pagos($c), 'el doble clic duplicó el pago');
        $this->assertSame(1, $this->confirmados($c));
        $this->assertSame(2000, $this->neto($c));
        $this->conciliado();
    }

    /** Req C6: MISMA clave con OTROS datos (monto distinto) ⇒ 409 y no se registra nada nuevo. */
    public function test_C6_misma_clave_con_otros_datos_es_409(): void
    {
        $c = $this->cita([['Tinte Q5', '40.00']]);
        $n = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($n, $c));
        $this->aceptado($this->cobrar($n, $c, ['monto' => 1000, 'metodo' => 'efectivo', 'recibido' => 1000], $form), 'primer cobro');
        $r = $this->cobrar($n, $c, ['monto' => 1500, 'metodo' => 'efectivo', 'recibido' => 1500], $form);
        $this->assertSame(409, $r['status'], 'reutilizar la clave con otro monto debe ser 409; fue ' . $r['status'] . ' ' . substr(self::texto($r['body']), 0, 160));
        $this->assertSame(1000, $this->neto($c));
        $this->assertCount(1, $this->pagos($c));
        $r = $this->cobrar($n, $c, ['monto' => 1000, 'metodo' => 'transferencia', 'referencia' => 'TRF-999'], $form);
        $this->assertSame(409, $r['status'], 'reutilizar la clave con otro método debe ser 409');
        $this->assertCount(1, $this->pagos($c));
        $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'));
    }

    /** Req C6: SIN clave (campo removido) dos envíos seguidos no pueden cobrar más que el saldo: el bloqueo de la cita lo impide aunque no haya idempotencia. */
    public function test_C6_sin_clave_no_se_puede_cobrar_dos_veces_el_saldo(): void
    {
        $c = $this->cita();
        $n = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($n, $c));
        $d = ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000, 'clave' => ''];
        $this->cobrar($n, $c, $d, $form);
        $this->cobrar($n, $c, $d, $form);
        $this->assertLessThanOrEqual(1, $this->confirmados($c), 'sin clave, el segundo envío cobró otra vez');
        $this->assertLessThanOrEqual(2000, $this->neto($c));
        $this->conciliado();
    }

    /** Req C6: la clave de operación de UNA cita no puede reutilizarse en OTRA cita (ni cobrarla): 409 y la segunda cita queda sin pagos. */
    public function test_C6_misma_clave_en_otra_cita_es_409(): void
    {
        $a = $this->cita(); $b = $this->cita();
        $n = $this->eq('dueno');
        $fa = $this->formCobro($this->pantalla($n, $a));
        $fb = $this->formCobro($this->pantalla($n, $b));
        $clave = self::campo($fa, '/idem|clave_op|operacion_id|token_op/i');
        $this->assertNotNull($clave, 'el formulario de cobro no lleva clave de idempotencia');
        $this->aceptado($this->cobrar($n, $a, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000], $fa), 'cobro de A');
        $r = $this->cobrar($n, $b, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000, 'crudo' => [$clave => $fa['campos'][$clave]]], $fb);
        $this->assertSame(409, $r['status'], 'la clave de A usada en B debe ser 409; fue ' . $r['status']);
        $this->assertCount(0, $this->pagos($b), 'se cobró la cita B con la clave de A');
        $this->assertCount(1, $this->pagos($a));
        $this->conciliado();
    }

    /** Req C3 (revisión independiente de comisiones): un cambio de tarifa entre pagos NO reescribe el pasado ni mezcla tarifas: cada pago devenga a la tarifa vigente. */
    public function test_C3_cambio_de_tarifa_entre_pagos_parciales_no_mezcla_tarifas(): void
    {
        $c = $this->cita([['Servicio tarifa', '20.00']]);
        $n = $this->eq('dueno');
        $pro = self::$e['proA']['profesional_id'];
        $this->aceptado($this->cobrar($n, $c, ['monto' => 1000, 'metodo' => 'efectivo', 'recibido' => 1000]), 'primer pago $10 al 10 %');
        self::q('UPDATE comisiones_config SET porcentaje_pb=2000 WHERE profesional_id=?', [$pro]);
        try {
            $this->aceptado($this->cobrar($n, $c, ['monto' => 1000, 'metodo' => 'efectivo', 'recibido' => 1000]), 'segundo pago $10 al 20 %');
        } finally { self::q('UPDATE comisiones_config SET porcentaje_pb=1000 WHERE profesional_id=?', [$pro]); }
        $com = (int)self::escalar("SELECT COALESCE(SUM(m.monto_centavos),0) FROM comisiones_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE m.tipo='devengo' AND p.cita_id=?", [$c]);
        $this->assertSame(100 + 200, $com, 'primer pago al 10 % ($1.00) + segundo al 20 % ($2.00)');
        $this->conciliado();
    }

    /** Req C4 (revisión independiente): cobrar, devolver parte y volver a cobrar el saldo: la comisión neta es el % de lo realmente retenido, sin deriva. */
    public function test_C4_pago_despues_de_devolucion_parcial_mantiene_la_comision_neta_exacta(): void
    {
        $c = $this->cita([['Servicio devol', '30.00']]);
        $n = $this->eq('dueno');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 3000, 'metodo' => 'efectivo', 'recibido' => 3000]), 'cobro total $30');
        $this->aceptado($this->devolver($n, $c, 1000), 'devolución $10');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 1000, 'metodo' => 'efectivo', 'recibido' => 1000, 'clave' => bin2hex(random_bytes(16))]), 'nuevo cobro del saldo $10');
        $f = self::resumenFinanciero($c);
        $this->assertSame(3000, $f['neto']);
        $this->assertSame('pagado', $f['estado_pago']);
        $neta = (int)self::escalar("SELECT COALESCE(SUM(m.monto_centavos),0) FROM comisiones_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_id=? AND m.estado<>'anulada'", [$c]);
        $this->assertSame(300, $neta, 'comisión neta = 10 % de los $30.00 retenidos');
        $this->conciliado();
    }

    /** Req C3 (criterio QA, comisiones): 10 pagos parciales de $0.15 sobre $1.50 con comisión del 10 % no pierden céntimos por redondeo: Σ comisión = 10 % del cobrado ±1 centavo. */
    public function test_C3_redondeo_de_comision_no_deriva_con_pagos_parciales(): void
    {
        $c = $this->cita([['Servicio barato', '1.50']]);
        $n = $this->eq('dueno');
        for ($i = 0; $i < 10; $i++) { $this->aceptado($this->cobrar($n, $c, ['monto' => 15, 'metodo' => 'efectivo', 'recibido' => 15]), "pago parcial $i"); }
        $f = self::resumenFinanciero($c);
        $this->assertSame([150, 'pagado'], [$f['neto'], $f['estado_pago']]);
        $com = (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos WHERE tipo='devengo'");
        $this->assertLessThanOrEqual(1, abs($com - 15), "la comisión acumulada ($com centavos) se desvía de 15 (10 % de \$1.50) por redondear cada pago por separado");
        $this->conciliado();
    }

    // ================================================================== C7 concurrencia real

    /** Cuerpo POST completo de un formulario (campos + cambios), para enviarlo desde otro proceso. */
    private function cuerpo(array $form, array $cambios = []): array { return array_merge($form['campos'], $cambios); }

    /**
     * Req C7: DOS PROCESOS PHP reales cobrando la MISMA cita a la vez con la MISMA clave (doble clic en dos pestañas) ⇒ un pago, un ingreso, una comisión.
     * 6 rondas con citas nuevas.
     */
    public function test_C7_dos_procesos_misma_clave_cobran_una_sola_vez(): void
    {
        $a = $this->eq('dueno'); $b = $this->eq('dueno');
        for ($i = 1; $i <= 6; $i++) {
            $c = $this->cita();
            $fa = $this->formCobro($this->pantalla($a, $c));
            $fb = $this->formCobro($this->pantalla($b, $c));
            $cmA = self::campo($fa, '/metodo|medio|forma/i'); $cmB = self::campo($fb, '/metodo|medio|forma/i');
            $clA = self::campo($fa, '/idem|clave_op|operacion_id|token_op/i');
            $monto = $this->campoMonto($fa); $recib = self::campo($fa, '/recib|entreg/i');
            $cuerpoA = $this->cuerpo($fa, array_filter([$cmA => $this->opcion($fa, $cmA, 'efectivo'), $monto => '20.00', $recib => '20.00']));
            $cuerpoB = $this->cuerpo($fb, array_filter([$cmB => $this->opcion($fb, $cmB, 'efectivo'), $monto => '20.00', $recib => '20.00']));
            if ($clA) { $cuerpoB[$clA] = $cuerpoA[$clA]; }
            $res = self::rafaga(self::$base, [
                ['cookie' => $a->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fa['action'], 'body' => $cuerpoA],
                ['cookie' => $b->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fb['action'], 'body' => $cuerpoB],
            ]);
            foreach ($res as $r) { $this->assertLessThan(500, $r['status'], "ronda $i: 5xx en la carrera: " . substr(self::texto($r['body']), 0, 200)); }
            $this->assertCount(1, $this->pagos($c), "ronda $i: dos procesos con la misma clave deben dejar UN pago");
            $this->assertSame(1, $this->confirmados($c));
            $this->assertSame(2000, $this->neto($c));
        }
        $this->assertSame(6, (int)self::escalar("SELECT COUNT(*) FROM comisiones_movimientos WHERE tipo='devengo'"), 'una comisión por cita');
        $this->assertStringNotContainsString('Deadlock', self::logQ5('cobro'));
        $this->conciliado('tras 6 carreras con misma clave');
    }

    /** Req C7: DOS PROCESOS con claves DISTINTAS pagando el saldo completo de la misma cita ⇒ solo uno cobra; el otro recibe 409/422 y la caja nunca supera el total. */
    public function test_C7_dos_procesos_claves_distintas_solo_uno_cobra_el_saldo(): void
    {
        $a = $this->eq('dueno'); $b = $this->eq('gerente');
        for ($i = 1; $i <= 6; $i++) {
            $c = $this->cita();
            $fa = $this->formCobro($this->pantalla($a, $c));
            $fb = $this->formCobro($this->pantalla($b, $c));
            $cuerpos = [];
            foreach ([[$a, $fa], [$b, $fb]] as $k => [$nav, $f]) {
                $cm = self::campo($f, '/metodo|medio|forma/i'); $cl = self::campo($f, '/idem|clave_op|operacion_id|token_op/i');
                $cu = $this->cuerpo($f, array_filter([$cm => $this->opcion($f, $cm, 'efectivo'), $this->campoMonto($f) => '20.00', self::campo($f, '/recib|entreg/i') => '20.00']));
                if ($cl) { $cu[$cl] = $this->claveNueva(); }
                $cuerpos[$k] = $cu;
            }
            $res = self::rafaga(self::$base, [
                ['cookie' => $a->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fa['action'], 'body' => $cuerpos[0]],
                ['cookie' => $b->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fb['action'], 'body' => $cuerpos[1]],
            ]);
            foreach ($res as $r) { $this->assertLessThan(500, $r['status'], "ronda $i: 5xx " . substr(self::texto($r['body']), 0, 160)); }
            $this->assertSame(2000, $this->neto($c), "ronda $i: la caja debe quedar exactamente en el total (dos cobros completos = doble cobro)");
            $this->assertSame(1, $this->confirmados($c), "ronda $i");
            $estados = array_column($res, 'status');
            $this->assertTrue(count(array_filter($estados, fn($s) => $s >= 400)) >= 1 || $this->confirmados($c) === 1, 'el perdedor debe recibir un rechazo claro: ' . json_encode($estados));
        }
        $this->conciliado('tras carreras de saldo');
    }

    /** Req C7: CINCO procesos a la vez, cada uno con $10.00 y claves distintas sobre una cita de $40.00 ⇒ se aceptan exactamente 4; la caja queda en $40.00. */
    public function test_C7_cinco_procesos_pagos_parciales_no_superan_el_total(): void
    {
        $navs = [$this->eq('dueno'), $this->eq('dueno'), $this->eq('gerente'), $this->eq('gerente'), $this->eq('finanzas')];
        for ($i = 1; $i <= 3; $i++) {
            $c = $this->cita([['Tinte Q5', '40.00']]);
            $reqs = [];
            foreach ($navs as $k => $nav) {
                $f = $this->formCobro($this->pantalla($nav, $c));
                $cm = self::campo($f, '/metodo|medio|forma/i'); $cl = self::campo($f, '/idem|clave_op|operacion_id|token_op/i');
                $cu = $this->cuerpo($f, array_filter([$cm => $this->opcion($f, $cm, 'efectivo'), $this->campoMonto($f) => '10.00', self::campo($f, '/recib|entreg/i') => '10.00']));
                if ($cl) { $cu[$cl] = $this->claveNueva(); }
                $reqs[] = ['cookie' => $nav->cookieSesion(), 'metodo' => 'POST', 'ruta' => $f['action'], 'body' => $cu];
            }
            $res = self::rafaga(self::$base, $reqs, 1.5);
            foreach ($res as $r) { $this->assertLessThan(500, $r['status'], "ronda $i: 5xx " . substr(self::texto($r['body']), 0, 160)); }
            $f = self::resumenFinanciero($c);
            $this->assertSame(4000, $f['neto'], "ronda $i: 5 cobros de \$10 sobre \$40 deben sumar exactamente \$40 (4 aceptados), hubo " . self::dolares($f['neto']));
            $this->assertSame(4, $this->confirmados($c));
            $this->assertSame('pagado', $f['estado_pago']);
        }
        $this->conciliado('tras 5 procesos');
    }

    /** Req C7: verificar la MISMA transferencia desde dos procesos a la vez ⇒ un ingreso y una comisión. */
    public function test_C7_verificar_la_misma_transferencia_en_paralelo(): void
    {
        $a = $this->eq('dueno'); $b = $this->eq('finanzas');
        for ($i = 1; $i <= 3; $i++) {
            $c = $this->cita();
            $this->aceptado($this->cobrar($a, $c, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => 'TRF-' . random_int(1000000, 9999999)]), 'transferencia');
            $va = self::formulario($this->pantalla($a, $c)['body'], '~/caja/pago/verificar~');
            $vb = self::formulario($this->pantalla($b, $c)['body'], '~/caja/pago/verificar~');
            $this->assertNotNull($va);
            $res = self::rafaga(self::$base, [
                ['cookie' => $a->cookieSesion(), 'metodo' => 'POST', 'ruta' => $va['action'], 'body' => $va['campos']],
                ['cookie' => $b->cookieSesion(), 'metodo' => 'POST', 'ruta' => $vb['action'], 'body' => $vb['campos']],
            ]);
            foreach ($res as $r) { $this->assertLessThan(500, $r['status']); }
            $this->assertSame(1, $this->confirmados($c), "ronda $i: un solo ingreso al verificar en paralelo");
            $this->assertSame(2000, $this->neto($c));
        }
        $this->conciliado();
    }

    private function opcion(array $form, ?string $campo, string $quiere): string
    {
        foreach ($form['selects'][$campo] ?? [] as $o) { if (stripos($o, $quiere) !== false) { return $o; } }
        return $quiere;
    }

    // ================================================================== C8 fallos

    /**
     * Req C8: «Finalizar y cobrar» y el cobro FALLA (la caja no acepta el insert): la cita queda ATENDIDA con saldo pendiente, ningún pago/comisión
     * a medias (misma transacción) y el REINTENTO con el mismo formulario cobra una sola vez.
     */
    public function test_C8_cobro_fallido_tras_finalizar_deja_la_cita_atendida_y_el_reintento_no_duplica(): void
    {
        [$f, $h] = self::slotComenzado(5);
        $c = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h, 'estado' => 'en_servicio']);
        $n = $this->eq('dueno');
        $pag = $n->get("/agenda?desde=$f&hasta=$f");
        $t = self::tarjeta($pag['body'], $c);
        $a = self::accion((string)$t, '/Finalizar y cobrar/iu');
        $this->assertNotNull($a, 'la agenda no ofrece «Finalizar y cobrar»');
        $r = self::enviar($n, $a[0], [], [], $a[1]);
        $this->assertContains($r['status'], [302, 303]);
        $loc = self::ubicacion($r);
        $this->assertSame('/caja/cita', self::pathDe($loc));
        $form = $this->formCobro($n->get($loc));
        self::q("CREATE TRIGGER q5_falla_caja_movimientos BEFORE INSERT ON caja_movimientos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='q5 fallo inyectado'");
        try {
            $fallo = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000], $form);
        } finally {
            self::q('DROP TRIGGER IF EXISTS q5_falla_caja_movimientos');
        }
        $this->assertContains($fallo['status'], [500, 503], 'el fallo de caja debe verse como error de servidor claro: ' . $fallo['status']);
        $this->assertTrue(self::esPaginaCompleta($fallo['body']), 'el error 5xx del cobro debe ser una página completa');
        $this->assertStringNotContainsString('q5 fallo inyectado', $fallo['body'], 'el error filtra el mensaje interno');
        $this->assertSame([], self::enlacesCruzados($fallo['body'], 'equipo'));
        $this->assertNotEmpty(array_filter(self::destinos($fallo['body']), fn($d) => str_starts_with(self::pathDe($d), '/caja') || self::pathDe($d) === '/agenda'), 'el error debe permitir volver al cobro o a la agenda');
        $this->assertSame('completada', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [$c]), 'la cita debe seguir ATENDIDA');
        $this->assertSame('pendiente', (string)self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$c]));
        $this->assertCount(0, $this->pagos($c), 'no puede quedar un pago a medias');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM comisiones_movimientos'));
        $this->assertSame(2000, self::resumenFinanciero($c)['saldo']);
        // Reintento con el mismo formulario (misma clave): cobra una vez; repetirlo no duplica.
        $ok = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000], $form);
        $this->aceptado($ok, 'reintento');
        $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000], $form);
        $this->assertCount(1, $this->pagos($c));
        $this->assertSame(2000, $this->neto($c));
        $this->conciliado();
    }

    /** Req C8 (atomicidad): si la COMISIÓN no puede registrarse, el cobro completo (pago + caja + estado) se revierte: misma transacción. */
    public function test_C8_pago_caja_y_comision_son_atomicos(): void
    {
        $c = $this->cita();
        $n = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($n, $c));
        self::q("CREATE TRIGGER q5_falla_comisiones_movimientos BEFORE INSERT ON comisiones_movimientos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='q5 fallo comision'");
        try {
            $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000], $form);
        } finally {
            self::q('DROP TRIGGER IF EXISTS q5_falla_comisiones_movimientos');
        }
        $this->assertContains($r['status'], [500, 503]);
        $this->assertSame([0, 0, 'pendiente'], [count($this->pagos($c)), (int)self::escalar('SELECT COUNT(*) FROM caja_movimientos'), (string)self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$c])],
            'pago, caja y estado deben quedar intactos si la comisión falla');
        $this->aceptado($this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000], $form), 'reintento');
        $this->assertSame(1, $this->confirmados($c));
        $this->conciliado();
    }

    // ================================================================== C9 permisos

    /** Req C9: recepción, profesional, cliente y anónimo NO cobran, ni ven el cobro, ni verifican, ni exportan caja, ni ven el comprobante: 403/redirección y nada registrado. */
    public function test_C9_sin_finanzas_gestionar_no_hay_acceso_a_ninguna_operacion_de_caja(): void
    {
        $c = $this->cita();
        $dueno = $this->eq('dueno');
        $form = $this->formCobro($this->pantalla($dueno, $c));
        $this->aceptado($this->cobrar($dueno, $c, ['monto' => 1000, 'metodo' => 'transferencia', 'referencia' => 'TRF-' . random_int(1000000, 9999999)], $form), 'preparación');
        $fallos = [];
        $cuerpo = $this->cuerpo($form, [($this->campoMonto($form) ?? 'monto') => '5.00']);
        foreach (['recepcion' => $this->eq('recepcion'), 'proA' => $this->eq('proA'), 'cliente' => self::entrar(self::$base, self::$e['cliente'], 'cliente')] as $quien => $nav) {
            foreach (['GET /caja', "GET /caja/cita?id=$c", "GET /caja/comprobante?id=$c", 'GET /caja/exportar?formato=csv', 'GET /caja/exportar?formato=xlsx', 'GET /admin/finanzas'] as $peticion) {
                [$m, $ruta] = explode(' ', $peticion, 2);
                $r = $nav->get($ruta);
                if ($r['status'] !== 403) { $fallos[] = "$quien $peticion → {$r['status']} (esperado 403)"; }
                if (preg_match('~Clienta|TRF-|\$\d+\.\d\d~', self::texto($r['body']))) { $fallos[] = "$quien $peticion filtra datos de caja en el 403"; }
                if (self::enlacesCruzados($r['body'], $quien === 'cliente' ? 'cliente' : 'equipo')) { $fallos[] = "$quien $peticion → 403 con enlaces del otro portal"; }
            }
            foreach (['/caja/cita/cobrar', '/caja/pago/verificar', '/caja/pago/rechazar'] as $ruta) {
                $b = $nav->post($ruta, $cuerpo + ['csrf_token' => Navegador::csrfDe($nav->get('/perfil')['body']) ?? '']);
                if ($b['status'] !== 403) { $fallos[] = "$quien POST $ruta → {$b['status']} (esperado 403)"; }
            }
        }
        foreach (['GET /caja', "GET /caja/cita?id=$c", "GET /caja/comprobante?id=$c", 'GET /caja/exportar?formato=csv'] as $peticion) {
            $r = (new Navegador(self::$base))->get(explode(' ', $peticion, 2)[1]);
            if (!in_array($r['status'], [302, 303, 401], true)) { $fallos[] = "anónimo $peticion → {$r['status']}"; }
        }
        $this->assertCount(1, $this->pagos($c), 'una operación sin permiso modificó pagos');
        $this->assertSame(0, $this->confirmados($c));
        $this->sinFallos($fallos);
    }

    // ================================================================== C10 comprobante

    /** Ruta del comprobante de una cita: el enlace de /caja/cita o la redirección tras cobrar. */
    private function rutaComprobante(Navegador $n, int $cita, ?array $respuestaCobro = null): ?string
    {
        $cand = $respuestaCobro ? [self::ubicacion($respuestaCobro)] : [];
        foreach (self::destinos($this->pantalla($n, $cita)['body']) as $d) { $cand[] = $d; }
        foreach ($cand as $d) { if (self::pathDe($d) === '/caja/comprobante') { return $d; } }
        return null;
    }

    /**
     * Req C10: el comprobante de un cobro confirmado es una página imprimible con la leyenda «Comprobante interno — no es factura autorizada por el SRI»,
     * número, fecha, cliente, servicios con precios históricos, método, importe, cambio, total y saldo COHERENTES con pagos y caja; no se presenta como factura.
     */
    public function test_C10_comprobante_contenido_leyenda_e_importes_coherentes(): void
    {
        $c = $this->cita([['Corte Q5', '18.50'], ['Brushing', '5.00']]);
        $n = $this->eq('dueno');
        $r = $this->cobrar($n, $c, ['monto' => 2350, 'metodo' => 'efectivo', 'recibido' => 5000]);
        $this->aceptado($r, 'cobro');
        $ruta = $this->rutaComprobante($n, $c, $r);
        $this->assertNotNull($ruta, 'tras cobrar no hay enlace/redirección a /caja/comprobante');
        $p = $n->get($ruta);
        $this->assertSame(200, $p['status'], "GET $ruta");
        $txt = self::texto($p['body']);
        $this->assertMatchesRegularExpression('/Comprobante interno\s*[—–-]\s*no es factura autorizada por el SRI/iu', $txt, 'falta la leyenda obligatoria');
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Factura\b/iu', $p['body'], 'el comprobante se presenta como factura');
        foreach (['Clienta', 'Corte Q5', 'Brushing', '18.50', '5.00', '23.50', '50.00', '26.50'] as $esperado) { $this->assertStringContainsString($esperado, $txt, "el comprobante no muestra «{$esperado}»"); }
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}/', $txt, 'sin fecha');
        $this->assertMatchesRegularExpression('/N[°º.o]*\s*[:#]?\s*[A-Z0-9-]*\d{3,}|No\.?\s*[A-Z0-9-]*\d+|#\s*[A-Z0-9-]*\d{3,}/u', $txt, 'sin número de comprobante');
        $this->assertMatchesRegularExpression('/imprimir|window\.print|@media print|print/i', $p['body'], 'el comprobante debe ser imprimible (botón/hoja de estilos de impresión)');
        $this->assertSame([], self::enlacesCruzados($p['body'], 'equipo'));
        $this->assertStringNotContainsString('<script>alert', $p['body']);
        $this->conciliado();
    }

    private function numeroDeComprobante(string $texto): ?string
    {
        if (preg_match('/(?:Comprobante|Recibo)(?:\s+(?:interno|de cobro))?\s*(?:N[°º.o]*|No\.?|#|:)?\s*([A-Z]{0,6}-?\d{3,})/u', $texto, $m)) { return $m[1]; }
        return preg_match('/\b([A-Z]{1,6}-\d{3,})\b/u', $texto, $m) ? $m[1] : null;
    }

    /** Req C10: números de comprobante ÚNICOS y CORRELATIVOS (sin huecos) con 8 cobros simultáneos en citas distintas. */
    public function test_C10_comprobantes_unicos_y_correlativos_bajo_concurrencia(): void
    {
        $navs = []; $citas = []; $reqs = [];
        for ($k = 0; $k < 8; $k++) {
            $nav = $this->eq($k % 2 ? 'dueno' : 'gerente');
            $c = $this->cita();
            $f = $this->formCobro($this->pantalla($nav, $c));
            $cm = self::campo($f, '/metodo|medio|forma/i'); $cl = self::campo($f, '/idem|clave_op|operacion_id|token_op/i');
            $cu = $this->cuerpo($f, array_filter([$cm => $this->opcion($f, $cm, 'efectivo'), $this->campoMonto($f) => '20.00', self::campo($f, '/recib|entreg/i') => '20.00']));
            if ($cl) { $cu[$cl] = $this->claveNueva(); }
            $navs[$k] = $nav; $citas[$k] = $c;
            $reqs[] = ['cookie' => $nav->cookieSesion(), 'metodo' => 'POST', 'ruta' => $f['action'], 'body' => $cu];
        }
        $antes = (int)self::escalar('SELECT COUNT(*) FROM comprobantes');
        $res = self::rafaga(self::$base, $reqs, 1.5);
        foreach ($res as $r) { $this->assertLessThan(500, $r['status'], '5xx: ' . substr(self::texto($r['body']), 0, 160)); }
        $this->assertSame(8, (int)self::escalar('SELECT COUNT(*) FROM comprobantes') - $antes, 'cada cobro confirmado emite exactamente un comprobante');
        $cols = array_column(self::q('SHOW COLUMNS FROM comprobantes'), 'Field');
        $colNum = null;
        foreach (['secuencial', 'numero', 'correlativo', 'consecutivo'] as $k) { if (in_array($k, $cols, true)) { $colNum = $k; break; } }
        $this->assertNotNull($colNum, 'la tabla comprobantes no tiene columna de numeración (' . implode(',', $cols) . ')');
        $nums = array_map(fn($f) => $f[$colNum], self::q("SELECT `$colNum` FROM comprobantes ORDER BY id"));
        $this->assertSame(count($nums), count(array_unique($nums)), 'números duplicados: ' . implode(',', $nums));
        $enteros = array_filter(array_map(fn($x) => preg_match('/(\d+)$/', (string)$x, $m) ? (int)$m[1] : null, $nums), fn($x) => $x !== null);
        sort($enteros);
        $this->assertSame(range(min($enteros), min($enteros) + count($enteros) - 1), array_values($enteros), 'la numeración tiene huecos: ' . implode(',', $enteros));
        // Cada página muestra su propio número y coincide con la base.
        $vistos = [];
        foreach ($citas as $k => $c) {
            $ruta = $this->rutaComprobante($navs[$k], $c);
            $this->assertNotNull($ruta, "cita $c sin enlace a comprobante");
            $num = $this->numeroDeComprobante(self::texto($navs[$k]->get($ruta)['body']));
            $this->assertNotNull($num, 'no se pudo leer el número impreso');
            $vistos[] = $num;
        }
        $this->assertSame(8, count(array_unique($vistos)), 'números impresos repetidos: ' . implode(',', $vistos));
        $this->conciliado('comprobantes concurrentes');
    }

    /** Req C10: un cobro por transferencia SIN verificar no genera un comprobante «pagado»; tras verificar sí. Un segundo pago de la misma cita genera otro comprobante coherente. */
    public function test_C10_comprobante_de_transferencia_solo_tras_verificar(): void
    {
        $c = $this->cita();
        $n = $this->eq('dueno');
        $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => 'TRF-' . random_int(1000000, 9999999)]);
        $this->aceptado($r, 'transferencia');
        $ruta = $this->rutaComprobante($n, $c, $r);
        if ($ruta !== null) {
            $p = $n->get($ruta);
            $txt = self::texto($p['body']);
            $this->assertTrue($p['status'] !== 200 || preg_match('/pendiente|por verificar|sin verificar/iu', $txt) === 1, 'un comprobante de transferencia sin verificar no puede presentarse como pagado: ' . substr($txt, 0, 300));
        }
        $v = self::formulario($this->pantalla($n, $c)['body'], '~/caja/pago/verificar~');
        $this->assertNotNull($v);
        $this->aceptado(self::enviar($n, $v), 'verificación');
        $ruta = $this->rutaComprobante($n, $c);
        $this->assertNotNull($ruta, 'tras verificar debe existir el comprobante');
        $this->assertSame(200, $n->get($ruta)['status']);
        $this->conciliado();
    }

    /** Req C10: acceso al comprobante — id inexistente 404, cita ajena sin permiso 403, anónimo redirige; los datos hostiles del cliente salen escapados. */
    public function test_C10_acceso_y_escape_del_comprobante(): void
    {
        $hostil = self::cliente(['email' => self::correo('hostil'), 'nombre' => '<script>alert(1)</script>', 'apellido' => '=HYPERLINK("http://x","y")']);
        $c = $this->cita([['Corte Q5', '20.00']], 'completada', $hostil['id']);
        $n = $this->eq('dueno');
        $r = $this->cobrar($n, $c, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000]);
        $this->aceptado($r, 'cobro');
        $ruta = $this->rutaComprobante($n, $c, $r);
        $this->assertNotNull($ruta);
        $p = $n->get($ruta);
        $this->assertSame(200, $p['status']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $p['body'], 'nombre hostil sin escapar en el comprobante');
        $this->assertStringContainsString('&lt;script&gt;', $p['body']);
        foreach (['/caja/comprobante?id=99999999', '/caja/comprobante?id=abc', '/caja/comprobante'] as $x) {
            $this->assertContains($n->get($x)['status'], [404, 422], $x);
        }
        $this->assertSame(403, $this->eq('recepcion')->get($ruta)['status']);
        $this->assertContains((new Navegador(self::$base))->get($ruta)['status'], [302, 303, 401]);
        $cliente = self::entrar(self::$base, $hostil, 'cliente');
        $this->assertSame(403, $cliente->get($ruta)['status'], 'el cliente no accede al comprobante interno por esta ruta');
    }

    // ================================================================== C11 conciliación

    /**
     * Req C11: tras una batería mezclada (efectivo, transferencia verificada y rechazada, anticipos, devoluciones) los totales de /caja y /admin/finanzas
     * coinciden con la suma en SQL y se cumplen TODOS los invariantes pagos↔caja↔comisiones↔estado_pago. Se ejecuta al final (depende de los datos previos).
     */
    public function test_C11_conciliacion_total_al_final(): void
    {
        $n = $this->eq('dueno');
        $x = $this->cita([['Corte Q5', '20.00']]);
        $this->aceptado($this->cobrar($n, $x, ['monto' => 2000, 'metodo' => 'efectivo', 'recibido' => 2000]), 'efectivo');
        $y = $this->cita([['Tinte Q5', '40.00']]);
        $this->aceptado($this->cobrar($n, $y, ['monto' => 1500, 'metodo' => 'efectivo', 'recibido' => 2000]), 'anticipo');
        $z = $this->cita([['Corte Q5', '20.00']]);
        $this->aceptado($this->cobrar($n, $z, ['monto' => 2000, 'metodo' => 'transferencia', 'referencia' => 'TRF-' . random_int(1000000, 9999999)]), 'transferencia');
        $this->conciliado('batería final');
        $ing = (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM caja_movimientos WHERE tipo='ingreso'");
        $egr = (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM caja_movimientos WHERE tipo='egreso'");
        $pag = 0;
        foreach (self::q("SELECT p.monto FROM pagos p WHERE EXISTS (SELECT 1 FROM caja_movimientos m WHERE m.pago_id=p.id AND m.tipo='ingreso')") as $p) { $pag += self::centavos((string)$p['monto']); }
        $this->assertSame($ing, $pag, "Σ ingresos de caja ({$ing}) ≠ Σ pagos con ingreso ({$pag})");
        $neto = self::dolares($ing - $egr);
        $hoy = self::gye()->format('Y-m-d');
        $caja = $n->get("/caja?desde=$hoy&hasta=$hoy");
        $this->assertSame(200, $caja['status'], 'GET /caja');
        $this->assertStringContainsString($neto, self::texto($caja['body']), "/caja no muestra el neto de caja del día (\$$neto)");
        $fin = $n->get("/admin/finanzas?inicio=$hoy&fin=$hoy");
        $this->assertSame(200, $fin['status']);
        $this->assertStringContainsString($neto, self::texto($fin['body']), "/admin/finanzas no muestra el neto de caja del día (\$$neto)");
    }
}
