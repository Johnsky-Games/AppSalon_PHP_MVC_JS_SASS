<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5bCase.php';

/**
 * R5b-QA fase 2 · (B) Finanzas muestra pagos y devoluciones por su fecha REAL; (C) las TRES fechas en reportes y exportaciones y ninguna etiqueta engañosa;
 * (D) concurrencia regularizar vs finalizar / cobrar / rechazar sin duplicar nada; (E) integridad referencial y filas huérfanas.
 * Contrato: docs/R5_REGULARIZACION.md §3, §5, §7.
 */
final class R5bQaFase2Test extends R5bCase
{
    private static string $base;
    private static array $e;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorB('f2');
        self::$e = self::escenarioB();
    }

    public static function tearDownAfterClass(): void { self::pararServidorB('f2'); }

    protected function setUp(): void
    {
        self::q('TRUNCATE intentos_login');
        $db = self::db();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::q("SELECT table_name t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name REGEXP 'pago|caja_|comision|comprobante|cobro|devol' AND table_name NOT LIKE '%config%'") as $t) { $db->query('TRUNCATE TABLE `' . $t['t'] . '`'); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private function n(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }
    private function d(string $mod): string { return self::gye($mod)->format('Y-m-d'); }

    private function cobrar(Navegador $n, int $cita, string $monto, string $metodo = 'efectivo'): array
    {
        $r = $n->get("/caja/cita?id=$cita");
        $this->assertSame(200, $r['status'], "GET /caja/cita?id=$cita → {$r['status']}");
        $f = self::formulario($r['body'], '~/caja/cita/cobrar~');
        $this->assertNotNull($f, 'sin formulario de cobro');
        $c = [self::campo($f, '/monto|importe|valor|cantidad/i', '/recib|entreg|cambio|total|saldo|pendiente/i') => $monto, self::campo($f, '/metodo|medio|forma/i') => $metodo];
        if (($rc = self::campo($f, '/recib|entreg/i'))) { $c[$rc] = $monto; }
        return self::enviar($n, $f, $c);
    }

    private function devolver(Navegador $n, int $cita, string $monto): array
    {
        $f = self::formulario($n->get("/caja/cita?id=$cita")['body'], '~devol~i');
        $this->assertNotNull($f, 'sin formulario de devolución');
        $c = [];
        if (($m = self::campo($f, '/monto|importe|valor|cantidad/i'))) { $c[$m] = $monto; }
        if (($mo = self::campo($f, '/motivo|concepto|razon/i'))) { $c[$mo] = 'Devolución de prueba fase 2'; }
        return self::enviar($n, $f, $c);
    }

    /** Cifras «Ingresos del período» y «Egresos del período» de /admin/finanzas para un rango (en centavos) y las fechas de las filas listadas. */
    private function finanzas(Navegador $n, string $ini, string $fin): array
    {
        $r = $n->get("/admin/finanzas?inicio=$ini&fin=$fin");
        $this->assertSame(200, $r['status'], "finanzas $ini..$fin → {$r['status']}");
        $t = self::texto($r['body']);
        $this->assertSame(1, preg_match('/Ingresos del per[ií]odo\s*\$\s*([\d,]+\.\d\d)/u', $t, $i), 'no se pudo leer «Ingresos del período»');
        $this->assertSame(1, preg_match('/Egresos del per[ií]odo\s*\$\s*([\d,]+\.\d\d)/u', $t, $e), 'no se pudo leer «Egresos del período»');
        $fechas = [];
        $x = self::xp($r['body']);
        foreach ($x->query('//table[caption[contains(.,"Movimientos")]]//tbody//td[contains(@class,"ui-table-date")]') as $td) { $fechas[] = substr(trim($td->textContent), 0, 10); }
        return ['ing' => self::centavos(str_replace(',', '', $i[1])), 'egr' => self::centavos(str_replace(',', '', $e[1])), 'fechas' => array_values(array_unique($fechas))];
    }

    private function sql(string $tipo, string $dia): int
    {
        return (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM caja_movimientos WHERE tipo=? AND DATE(registrado_en)=?", [$tipo, $dia]);
    }

    // ================================================================== B · Finanzas por fechas reales

    /**
     * Req B: cita programada P (hace 6 días), atención efectiva E (hace 5), COBRO ayer (fecha del movimiento ajustada en BD para simular otro día) y DEVOLUCIÓN parcial hoy.
     * Finanzas muestra el ingreso en AYER y el egreso en HOY; ni P ni E reciben nada; las cifras cuadran con caja_movimientos y con pagos/devoluciones.
     */
    public function test_B_finanzas_muestra_cobro_y_devolucion_por_sus_fechas_reales(): void
    {
        $c = self::citaVieja(self::$e, ['dias' => 6, 'servicios' => [['Servicio tres fechas', '30.00']]]);
        $P = $this->d('-6 days'); $E = $this->d('-5 days'); $ayer = $this->d('-1 day'); $hoy = $this->d('now');
        $n = $this->n('dueno');
        $this->aceptado($this->regularizar($n, $c, ['efectiva' => "{$E}T11:00"]), 'regularizar');
        $this->aceptado($this->cobrar($n, $c, '30.00'), 'cobro');
        // Simula legítimamente que el cobro se hizo AYER (mismo hecho, otra fecha del movimiento).
        $ayerHora = $ayer . ' 15:30:00';
        self::q('UPDATE pagos SET pagado_at=? WHERE cita_referencia_id=?', [$ayerHora, $c]);
        self::q('UPDATE caja_movimientos m JOIN pagos p ON p.id=m.pago_id SET m.registrado_en=? WHERE p.cita_referencia_id=? AND m.tipo=\'ingreso\'', [$ayerHora, $c]);
        $this->aceptado($this->devolver($n, $c, '10.00'), 'devolución parcial hoy');
        $fila = self::q("SELECT m.* FROM caja_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=? AND m.tipo='egreso'", [$c]);
        $this->assertCount(1, $fila);
        $this->assertSame($hoy, substr($fila[0]['registrado_en'], 0, 10), 'la devolución lleva la fecha real (hoy)');
        $esperado = [$P => [0, 0], $E => [0, 0], $ayer => [3000, 0], $hoy => [0, 1000]];
        foreach ($esperado as $dia => [$ing, $egr]) {
            $f = $this->finanzas($n, $dia, $dia);
            $this->assertSame([$ing, $egr], [$f['ing'], $f['egr']], "Finanzas del $dia: ingresos/egresos; programada=$P efectiva=$E ayer=$ayer hoy=$hoy");
            $this->assertSame([$ing, $egr], [$this->sql('ingreso', $dia), $this->sql('egreso', $dia)], "Finanzas ≠ caja_movimientos del $dia");
            foreach ($f['fechas'] as $fecha) { $this->assertSame($dia, $fecha, "Finanzas del $dia lista un movimiento del $fecha"); }
        }
        // Rango completo: suma exacta de pagos y devoluciones.
        $todo = $this->finanzas($n, $P, $hoy);
        $pagos = 0;
        foreach (self::q("SELECT monto FROM pagos WHERE cita_referencia_id=? AND estado IN ('completado','reembolsado','reembolsado_parcial')", [$c]) as $p) { $pagos += self::centavos((string)$p['monto']); }
        $this->assertSame([$pagos, 1000], [$todo['ing'], $todo['egr']], 'ingresos = Σ pagos; egresos = Σ devoluciones');
        $this->sinFallos(self::violacionesFinancieras(), 'conciliación:');
    }

    /** Req B: cobro y devolución el MISMO día real (hoy) de una cita vencida: Finanzas de hoy los muestra; la fecha programada y la efectiva siguen en 0. */
    public function test_B_cobro_y_devolucion_el_mismo_dia_real(): void
    {
        $c = self::citaVieja(self::$e, ['dias' => 4, 'servicios' => [['Servicio mismo dia', '20.00']]]);
        $n = $this->n('gerente');
        $this->aceptado($this->regularizar($n, $c, ['efectiva' => $this->d('-3 days') . 'T09:30']), 'regularizar');
        $this->aceptado($this->cobrar($n, $c, '20.00'), 'cobro');
        $this->aceptado($this->devolver($n, $c, '5.00'), 'devolución');
        $hoy = $this->d('now');
        $h = $this->finanzas($n, $hoy, $hoy);
        $this->assertSame([2000, 500], [$h['ing'], $h['egr']]);
        foreach ([$this->d('-4 days'), $this->d('-3 days')] as $dia) { $f = $this->finanzas($n, $dia, $dia); $this->assertSame([0, 0], [$f['ing'], $f['egr']], "el $dia (programada/efectiva) no recibe movimientos"); }
        $this->sinFallos(self::violacionesFinancieras(), 'conciliación:');
    }

    // ================================================================== C · las tres fechas

    private const S = ['X' => 'Svc Regularizada Q5', 'Y' => 'Svc Pendiente Q5', 'Z' => 'Svc Ausencia Q5', 'W' => 'Svc Ordinaria Q5'];

    /** Lee filas de un CSV de reportes que contienen la marca de servicio. */
    private function filasReporte(Navegador $n, string $criterio, string $desde, string $hasta, string $fmt = 'csv', string $bloque = ''): array
    {
        $r = $n->get('/admin/reportes/exportar?' . http_build_query(['fecha_inicio' => $desde, 'fecha_fin' => $hasta, 'criterio_fecha' => $criterio, 'formato' => $fmt] + ($bloque !== '' ? ['bloque' => $bloque] : [])));
        $this->assertSame(200, $r['status'], "reporte $criterio $desde..$hasta → {$r['status']}");
        $filas = $fmt === 'csv' ? self::csvFilas($r['body']) : array_map(fn($f) => array_map(fn($c) => (string)($c['v'] ?? ''), $f), self::xlsxOpenpyxl($r['body'])['filas']);
        return $filas;
    }

    private function conMarca(array $filas, string $marca): array { return array_values(array_filter($filas, fn($f) => str_contains(implode('|', $f), $marca))); }

    /** Siembra X (regularizada), Y (confirmada vieja), Z (ausencia) y W (atendida ordinariamente) programadas hace 6 días; X con atención efectiva hace 5 y cobro hoy. */
    private function sembrarTresFechas(): array
    {
        $e = self::$e; $n = $this->n('dueno');
        $mk = fn(string $k, string $estado) => self::citaVieja($e, ['dias' => 6, 'estado' => $estado, 'servicios' => [[self::S[$k], '20.00']]]);
        $x = $mk('X', 'confirmada'); $y = $mk('Y', 'confirmada'); $z = $mk('Z', 'no_asistio'); $w = $mk('W', 'completada');
        self::q('UPDATE citas SET atencion_fin_at=?, atencion_fin_por=? WHERE id=?', [$this->d('-6 days') . ' 10:40:00', $e['recepcion']['id'], $w]);
        $this->aceptado($this->regularizar($n, $x, ['efectiva' => $this->d('-5 days') . 'T11:00']), 'regularizar X');
        $this->aceptado($this->cobrar($n, $x, '20.00'), 'cobrar X');
        return compact('x', 'y', 'z', 'w');
    }

    /** Req C: criterios de fecha de /admin/reportes — programacion (original), realizado (efectiva; solo ATENDIDAS) y cobro (movimiento); `servicio` es alias histórico de programacion. */
    public function test_C_criterios_programacion_realizado_y_cobro(): void
    {
        $this->sembrarTresFechas();
        $n = $this->n('dueno');
        $P = $this->d('-6 days'); $E = $this->d('-5 days'); $hoy = $this->d('now');
        $aparece = fn(string $crit, string $dia, string $k) => count($this->conMarca($this->filasReporte($n, $crit, $dia, $dia), self::S[$k])) > 0;
        $fallos = [];
        $quiero = [
            // criterio, día, clave ⇒ debe aparecer?
            ['programacion', $P, 'X', true], ['programacion', $E, 'X', false], ['programacion', $hoy, 'X', false], ['programacion', $P, 'W', true],
            ['realizado', $E, 'X', true], ['realizado', $P, 'X', false], ['realizado', $hoy, 'X', false], ['realizado', $P, 'W', true],
            ['realizado', $P, 'Y', false], ['realizado', $P, 'Z', false], ['realizado', $E, 'Y', false], ['realizado', $hoy, 'Y', false],
            ['cobro', $hoy, 'X', true], ['cobro', $P, 'X', false], ['cobro', $E, 'X', false],
            ['servicio', $P, 'X', true], ['servicio', $E, 'X', false],
        ];
        foreach ($quiero as [$crit, $dia, $k, $debe]) {
            if ($aparece($crit, $dia, $k) !== $debe) { $fallos[] = "criterio «{$crit}» día $dia: la cita {$k} (" . self::S[$k] . ') ' . ($debe ? 'NO aparece' : 'aparece (no debe)'); }
        }
        $a = $this->filasReporte($n, 'servicio', $P, $P); $b = $this->filasReporte($n, 'programacion', $P, $P);
        if ($a !== $b) { $fallos[] = 'el alias «servicio» no da el mismo resultado que «programacion»'; }
        $this->sinFallos($fallos);
    }

    /** Req C: las exportaciones (CSV y XLSX) traen columnas separadas «Fecha programada» y «Fecha efectiva de atención» con los valores correctos para una cita regularizada. */
    public function test_C_columnas_de_fecha_programada_y_efectiva(): void
    {
        $c = $this->sembrarTresFechas();
        $n = $this->n('dueno');
        $P = $this->d('-6 days'); $E = $this->d('-5 days');
        $fallos = [];
        foreach (['csv', 'xlsx'] as $fmt) {
            $filas = $this->filasReporte($n, 'programacion', $P, $P, $fmt, 'reservas');
            $cab = array_map(fn($x) => mb_strtolower(strtr((string)$x, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u'])), $filas[0] ?? []);
            $iProg = null; $iEf = null;
            foreach ($cab as $i => $h) { if (str_contains($h, 'fecha programada') && $iProg === null) { $iProg = $i; } if (str_contains($h, 'fecha efectiva') && $iEf === null) { $iEf = $i; } }
            if ($iProg === null) { $fallos[] = "$fmt: sin columna «Fecha programada». Cabecera: " . implode(' | ', $filas[0] ?? []); }
            if ($iEf === null) { $fallos[] = "$fmt: sin columna «Fecha efectiva de atención». Cabecera: " . implode(' | ', $filas[0] ?? []); }
            if ($iProg === null || $iEf === null) { continue; }
            $x = $this->conMarca($filas, self::S['X'])[0] ?? null;
            if (!$x) { $fallos[] = "$fmt: la cita regularizada no está en el listado"; continue; }
            if (!str_starts_with((string)$x[$iProg], $P)) { $fallos[] = "$fmt X: Fecha programada «{$x[$iProg]}» ≠ $P"; }
            if (!str_starts_with((string)$x[$iEf], $E)) { $fallos[] = "$fmt X: Fecha efectiva «{$x[$iEf]}» ≠ $E"; }
            $y = $this->conMarca($filas, self::S['Y'])[0] ?? null;
            if ($y && trim((string)$y[$iEf]) !== '' && !str_contains(strtolower((string)$y[$iEf]), 'pendiente') && !str_contains(strtolower((string)$y[$iEf]), 'sin ')) { $fallos[] = "$fmt Y (no atendida): Fecha efectiva «{$y[$iEf]}» debería estar vacía"; }
        }
        // Movimientos financieros: programada de la cita + efectiva junto a la fecha del cobro/devolución.
        $hoy = $this->d('now');
        foreach (['csv', 'xlsx'] as $fmt) {
            $filas = $this->filasReporte($n, 'cobro', $hoy, $hoy, $fmt, 'movimientos');
            $txt = mb_strtolower(strtr(implode('|', $filas[0] ?? []), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']));
            foreach (['fecha programada de la cita', 'fecha efectiva de atencion', 'fecha cobro'] as $col) { if (!str_contains($txt, $col)) { $fallos[] = "movimientos $fmt: falta la columna «{$col}». Cabecera: $txt"; } }
            $x = $this->conMarca($filas, 'Svc Regularizada')[0] ?? null;
            if (!$x) { $fallos[] = "movimientos $fmt: sin la fila del cobro de X"; }
        }
        $this->sinFallos($fallos);
    }

    /** Req C: en NINGÚN lugar la fecha programada se etiqueta como «fecha real» o «del servicio»: pantalla de reportes, opciones del criterio, /caja y cabeceras de todas las exportaciones. */
    public function test_C_ninguna_etiqueta_presenta_la_programada_como_real(): void
    {
        $this->sembrarTresFechas();
        $n = $this->n('dueno');
        $P = $this->d('-6 days'); $hoy = $this->d('now');
        $malos = '/fecha (?:real )?(?:de|del) servicio(?! realizado)|fecha real (?:de la cita|del servicio|de atenci[oó]n|programada)|fecha programada[^.]{0,20}(?:real\b|del servicio)/iu';
        $textos = [
            'reportes' => self::texto($n->get("/admin/reportes?fecha_inicio=$P&fecha_fin=$hoy")['body']),
            'caja' => self::texto($n->get("/caja?desde=$P&hasta=$hoy")['body']),
            'detalle' => self::texto($n->get('/agenda/pendientes')['body']),
        ];
        $csv = $n->get("/admin/reportes/exportar?fecha_inicio=$P&fecha_fin=$hoy&criterio_fecha=programacion&formato=csv");
        $textos['reportes.csv'] = $csv['body'];
        $csv = $n->get("/caja/exportar?desde=$P&hasta=$hoy&formato=csv");
        $textos['caja.csv'] = $csv['body'];
        $fallos = [];
        foreach ($textos as $donde => $t) { if (preg_match($malos, $t, $m)) { $fallos[] = "$donde usa la etiqueta engañosa «{$m[0]}»"; } }
        $pagina = $n->get("/admin/reportes?fecha_inicio=$P&fecha_fin=$hoy")['body'];
        $f = self::formularios($pagina);
        $opts = [];
        foreach ($f as $form) { foreach ($form['etiquetas']['criterio_fecha'] ?? [] as $v => $et) { $opts[$v] = $et; } }
        foreach (['programacion' => '/program/iu', 'realizado' => '/realizad|efectiv/iu', 'cobro' => '/cobro|devoluci/iu'] as $v => $re) {
            if (!isset($opts[$v])) { $fallos[] = "el selector de criterio no tiene la opción «{$v}» (tiene " . json_encode(array_keys($opts)) . ')'; }
            elseif (!preg_match($re, $opts[$v])) { $fallos[] = "la opción «{$v}» se etiqueta «{$opts[$v]}»"; }
        }
        $this->sinFallos($fallos);
    }

    // ================================================================== D · concurrencia

    private function sin5xx(array $res, string $ctx): void
    {
        foreach ($res as $i => $r) { $this->assertLessThan(500, $r['status'], "$ctx: proceso $i → {$r['status']}: " . substr(self::texto($r['body']), 0, 200)); }
        $this->assertStringNotContainsString('Deadlock', self::logB('f2'), "$ctx: interbloqueo");
    }

    private function cuerpo(string $rol, int $c, string $accion, array $cambios = []): array
    {
        $n = $this->n($rol);
        $f = $this->formDe($n, $c, $accion);
        if ($accion === 'regularizar') { $cambios += ['efectiva' => self::efectiva($c), 'motivo' => 'Regularización concurrente de prueba']; }
        return ['cookie' => $n->cookieSesion(), 'metodo' => 'POST', 'ruta' => $f['action'], 'body' => $this->cuerpoB($f, $cambios)];
    }

    private function contarNotif(int $c): int
    {
        $t = 0;
        foreach (['notificaciones_citas' => 'cita_id'] as $tabla => $col) { $t += (int)self::escalar("SELECT COUNT(*) FROM `$tabla` WHERE `$col`=?", [$c]); }
        return $t;
    }

    /** Req D1: regularizar vs finalizar sobre una cita EN SERVICIO vieja: exactamente un desenlace; si gana la regularización, «finalizar» no escribe (sin atencion_fin_*); sin eventos ni notificaciones duplicados. 10 rondas. */
    public function test_D_regularizar_contra_finalizar_no_duplica_nada(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $c = self::citaVieja(self::$e, ['dias' => 2 + $i, 'estado' => 'en_servicio']);
            $notifAntes = $this->contarNotif($c);
            $res = self::rafaga(self::$base, [$this->cuerpo('dueno', $c, 'regularizar'), $this->cuerpo('recepcion', $c, 'estado', ['estado' => 'completada'])]);
            $this->sin5xx($res, "ronda $i");
            $d = self::cita($c);
            $this->assertSame('completada', $d['estado'], "ronda $i");
            $porReg = $d['regularizada_por'] !== null;
            $this->assertNotSame($porReg, $d['atencion_fin_por'] !== null, "ronda $i: XOR regularización/finalización: " . json_encode($d));
            if ($porReg) { $this->assertNull($d['atencion_fin_at'], "ronda $i: gana la regularización → sin atencion_fin_*"); $this->assertCount(1, array_filter(self::regs($c), fn($r) => $r['estado'] === 'aprobada')); }
            else { $this->assertCount(0, self::regs($c), "ronda $i: gana finalizar → sin filas de regularización"); }
            $terminales = array_filter(self::evs($c), fn($v) => in_array($v['tipo'], ['regularizar', 'estado'], true) && (json_decode($v['despues_json'], true)['estado'] ?? '') === 'completada');
            $this->assertCount(1, $terminales, "ronda $i: una sola transición a completada auditada");
            $this->assertLessThanOrEqual($notifAntes + 1, $this->contarNotif($c), "ronda $i: notificaciones duplicadas");
        }
    }

    /**
     * Req D2: regularizar vs COBRAR sobre una cita confirmada antigua: ambos pueden terminar bien, pero exactamente 1 transición, 1 aprobación, y como máximo 1 pago / 1 caja /
     * 1 comprobante / 1 comisión (consistentes entre sí); conciliación intacta. 10 rondas. Se informa cuántas veces ganó cada orden.
     */
    public function test_D_regularizar_contra_cobrar(): void
    {
        $ordenes = ['cobro_antes' => 0, 'regulariza_antes' => 0];
        for ($i = 1; $i <= 10; $i++) {
            $c = self::citaVieja(self::$e, ['dias' => 2 + $i, 'servicios' => [['Cobro concurrente', '20.00']]]);
            $n = $this->n('dueno');
            $r = $n->get("/caja/cita?id=$c");
            $fc = $r['status'] === 200 ? self::formulario($r['body'], '~/caja/cita/cobrar~') : null;
            if ($fc === null) { $this->markTestIncomplete("ronda $i: /caja/cita no ofrece cobro para una cita confirmada vencida (HTTP {$r['status']}); el caso «ambos ok» no es ejercitable."); }
            $campo = [self::campo($fc, '/monto|importe|valor|cantidad/i', '/recib|entreg|cambio|total|saldo|pendiente/i') => '20.00', self::campo($fc, '/metodo|medio|forma/i') => 'efectivo'];
            if (($rc = self::campo($fc, '/recib|entreg/i'))) { $campo[$rc] = '20.00'; }
            $ik = self::campo($fc, '/idem/i'); if ($ik) { $campo[$ik] = bin2hex(random_bytes(12)); }
            $cob = ['cookie' => $n->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fc['action'], 'body' => array_merge($fc['campos'], $campo)];
            $res = self::rafaga(self::$base, [$this->cuerpo('gerente', $c, 'regularizar'), $cob]);
            $this->sin5xx($res, "ronda $i");
            $this->assertSame('completada', self::cita($c)['estado'] === 'completada' ? 'completada' : self::cita($c)['estado'], "ronda $i: la cita debe terminar completada (regularización ok); respuestas " . json_encode(array_column($res, 'status')));
            $pagos = (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [$c]);
            $caja = (int)self::escalar("SELECT COUNT(*) FROM caja_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=? AND m.tipo='ingreso'", [$c]);
            $cmp = (int)self::escalar('SELECT COUNT(*) FROM comprobantes WHERE cita_id=?', [$c]);
            $com = (int)self::escalar("SELECT COUNT(*) FROM comisiones_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=? AND m.tipo='devengo'", [$c]);
            $this->assertLessThanOrEqual(1, $pagos, "ronda $i: pagos duplicados");
            $this->assertSame([$pagos, $pagos, $pagos], [$caja, $cmp, $com], "ronda $i: pago/caja/comprobante/comisión inconsistentes");
            $this->assertCount(1, array_filter(self::regs($c), fn($r) => $r['estado'] === 'aprobada'), "ronda $i: una aprobación");
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizar'), "ronda $i: un evento regularizar");
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => (json_decode($v['despues_json'], true)['estado'] ?? '') === 'completada'), "ronda $i: una transición a completada");
            $ordenes[$pagos === 1 ? 'cobro_antes' : 'regulariza_antes']++;
            $this->sinFallos(self::violacionesFinancieras(), "ronda $i conciliación:");
        }
        fwrite(STDERR, "\n[D2] pagos registrados en " . $ordenes['cobro_antes'] . " de 10 rondas (el resto: el cobro perdió la carrera o fue rechazado)\n");
    }

    /** Req D3: regularización directa vs rechazo de una solicitud abierta, simultáneos: nunca queda solicitud abierta; 1 aprobación; el rechazo, si ocurrió, está auditado una vez. 10 rondas. */
    public function test_D_regularizar_directa_contra_rechazar_solicitud(): void
    {
        $vistos = ['rechazo_gana_y_luego_regulariza' => 0, 'regulariza_y_rechazo_409' => 0];
        for ($i = 1; $i <= 10; $i++) {
            $c = self::citaVieja(self::$e, ['dias' => 2 + $i]);
            $pa = $this->n('proA');
            $this->aceptado($this->enviarB($pa, $this->formDe($pa, $c, 'solicitar'), ['efectiva' => self::efectiva($c, '10:30'), 'motivo' => 'Solicitud abierta para la carrera']), 'solicitud');
            $res = self::rafaga(self::$base, [$this->cuerpo('dueno', $c, 'regularizar'), $this->cuerpo('gerente', $c, 'rechazar', ['motivo' => 'Rechazo concurrente de la solicitud'])]);
            $this->sin5xx($res, "ronda $i");
            $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='solicitada'", [$c]), "ronda $i: solicitud abierta residual");
            $this->assertSame('completada', self::cita($c)['estado'], "ronda $i: la regularización directa procede siempre; respuestas " . json_encode(array_column($res, 'status')));
            $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='aprobada'", [$c]), "ronda $i: una sola aprobación");
            $rech = (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='rechazada'", [$c]);
            $this->assertLessThanOrEqual(1, $rech);
            $this->assertSame($rech, count(array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizacion_rechazo')), "ronda $i: evento de rechazo ↔ fila rechazada");
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizar'));
            $vistos[$rech ? 'rechazo_gana_y_luego_regulariza' : 'regulariza_y_rechazo_409']++;
        }
        fwrite(STDERR, "\n[D3] desenlaces: " . json_encode($vistos) . "\n");
    }

    /** Req D4: tres procesos a la vez sobre una cita en servicio (dos regularizaciones con claves distintas + finalizar): un solo desenlace, sin duplicados. 10 rondas. */
    public function test_D_tres_procesos_a_la_vez(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $c = self::citaVieja(self::$e, ['dias' => 2 + $i, 'estado' => 'en_servicio']);
            $res = self::rafaga(self::$base, [$this->cuerpo('dueno', $c, 'regularizar'), $this->cuerpo('gerente', $c, 'regularizar'), $this->cuerpo('recepcion', $c, 'estado', ['estado' => 'completada'])], 1.5);
            $this->sin5xx($res, "ronda $i");
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => (json_decode($v['despues_json'], true)['estado'] ?? '') === 'completada'), "ronda $i: una sola transición a completada");
            $this->assertLessThanOrEqual(1, (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='aprobada'", [$c]));
            $d = self::cita($c);
            $this->assertNotSame($d['regularizada_por'] !== null, $d['atencion_fin_por'] !== null, "ronda $i: " . json_encode($d));
        }
    }

    // ================================================================== E · integridad referencial

    private function huerfanas(): array
    {
        $q = ['citas_regularizaciones' => 'SELECT COUNT(*) FROM citas_regularizaciones x LEFT JOIN citas c ON c.id=x.cita_id WHERE c.id IS NULL',
            'agenda_eventos' => 'SELECT COUNT(*) FROM agenda_eventos x LEFT JOIN citas c ON c.id=x.citaId WHERE c.id IS NULL'];
        $r = [];
        foreach ($q as $t => $sql) { $r[$t] = (int)self::escalar($sql); }
        return $r;
    }

    /** Req E: con regularización la cita no se puede borrar: DELETE directo ⇒ error 1451 y la fila sigue; borrar la cita SIN regularización sí funciona y arrastra sus eventos; sin huérfanos tras flujos de la aplicación. */
    public function test_E_clave_foranea_restrict_y_sin_huerfanas(): void
    {
        $c = self::citaVieja(self::$e, ['dias' => 3]);
        $this->aceptado($this->regularizar($this->n('dueno'), $c), 'regularizar');
        $sol = self::citaVieja(self::$e, ['dias' => 4]);
        $pa = $this->n('proA');
        $this->aceptado($this->enviarB($pa, $this->formDe($pa, $sol, 'solicitar'), ['efectiva' => self::efectiva($sol), 'motivo' => 'Solicitud abierta para borrar']), 'solicitud');
        $rech = self::citaVieja(self::$e, ['dias' => 5]);
        $this->aceptado($this->enviarB($pa, $this->formDe($pa, $rech, 'solicitar'), ['efectiva' => self::efectiva($rech), 'motivo' => 'Solicitud que se rechaza']), 'solicitud 2');
        $g = $this->n('gerente');
        $this->aceptado($this->enviarB($g, $this->formDe($g, $rech, 'rechazar'), ['motivo' => 'Rechazada para la prueba de FK']), 'rechazo');
        $db = self::db();
        foreach (['regularizada' => $c, 'solicitud abierta' => $sol, 'solicitud rechazada' => $rech] as $que => $id) {
            $r = $db->query("DELETE FROM citas WHERE id=$id");
            $this->assertFalse($r, "DELETE directo de una cita con $que debe fallar");
            $this->assertSame(1451, $db->errno, "errno del DELETE ($que): {$db->errno} {$db->error}");
            $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM citas WHERE id=?', [$id]));
        }
        // La misma cita vía la capa de dominio: 409.
        $svc = new \Services\CitaService();
        foreach ([$c, $sol, $rech] as $id) {
            $r = $svc->eliminar($id, self::$e['dueno']['id'], true);
            $this->assertSame(409, (int)($r['httpCode'] ?? 0), "CitaService::eliminar({$id}): " . json_encode($r));
        }
        // Sin regularización: se elimina y sus eventos se van con ella (CASCADE), sin huérfanos.
        $libre = self::citaVieja(self::$e, ['dias' => 6]);
        $this->assertTrue($db->query("DELETE FROM citas WHERE id=$libre") !== false);
        $this->assertSame(['citas_regularizaciones' => 0, 'agenda_eventos' => 0], $this->huerfanas());
    }

    /** Req E: por HTTP, /api/eliminar sobre una cita regularizada o con solicitud abierta NO la borra (es cancelación lógica y una cita completada es terminal); y no deja una solicitud «abierta» sobre una cita cancelada. */
    public function test_E_api_eliminar_no_borra_ni_deja_solicitud_abierta_en_cita_cancelada(): void
    {
        $c = self::citaVieja(self::$e, ['dias' => 3]);
        $n = $this->n('dueno');
        $this->aceptado($this->regularizar($n, $c), 'regularizar');
        $csrf = Navegador::csrfDe($n->get('/agenda')['body']) ?? '';
        $r = $n->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $c], ['Accept: text/html']);
        $this->assertContains($r['status'], [403, 404, 409, 422], '/api/eliminar sobre una regularizada → ' . $r['status']);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM citas WHERE id=?', [$c]));
        $this->assertSame('completada', self::cita($c)['estado']);
        // Cita con solicitud abierta: si se cancela, la solicitud no puede quedar «solicitada».
        $s = self::citaVieja(self::$e, ['dias' => 4]);
        $pa = $this->n('proA');
        $this->aceptado($this->enviarB($pa, $this->formDe($pa, $s, 'solicitar'), ['efectiva' => self::efectiva($s), 'motivo' => 'Solicitud antes de cancelar']), 'solicitud');
        $r = $n->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $s], ['Accept: text/html']);
        $abierta = (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='solicitada'", [$s]);
        $this->assertTrue(self::cita($s)['estado'] !== 'cancelada' || $abierta === 0, "la cita #$s quedó cancelada con una solicitud de regularización ABIERTA (respuesta {$r['status']}): residuo inconsistente");
        $this->assertSame(['citas_regularizaciones' => 0, 'agenda_eventos' => 0], $this->huerfanas());
    }

    /**
     * Req E (criterio QA): la eliminación física de una cita con cobros dejaría pagos/comprobantes apuntando a una cita inexistente (sin FK por diseño). Se mide la capa de dominio:
     * el comportamiento recomendado es 409; si hoy se permite, la prueba lo dice (decisión pendiente en §7).
     */
    public function test_E_eliminar_cita_con_cobros_criterio_QA(): void
    {
        $c = self::citaVieja(self::$e, ['dias' => 3, 'estado' => 'completada']);
        $n = $this->n('dueno');
        $this->aceptado($this->cobrar($n, $c, '20.00'), 'cobro');
        $r = (new \Services\CitaService())->eliminar($c, self::$e['dueno']['id'], true);
        $existe = (int)self::escalar('SELECT COUNT(*) FROM citas WHERE id=?', [$c]);
        $huerf = (int)self::escalar('SELECT COUNT(*) FROM comprobantes x LEFT JOIN citas c ON c.id=x.cita_id WHERE c.id IS NULL');
        $this->assertTrue($existe === 1 && $huerf === 0, "eliminar una cita con cobros devolvió " . ($r['httpCode'] ?? '?') . " y dejó $huerf comprobante(s) apuntando a una cita inexistente (sin FK por diseño; §7: decisión pendiente)");
    }
}
