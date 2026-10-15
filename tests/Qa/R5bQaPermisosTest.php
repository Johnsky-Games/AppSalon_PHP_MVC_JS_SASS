<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5bCase.php';

/**
 * R5b-QA · Regularización de citas de días anteriores: permisos, POST forjados y entradas hostiles (decisiones 1, 2 y 4 del usuario).
 * Contrato: docs/R5_REGULARIZACION.md. HTTP real; la verdad se lee de la BD.
 */
final class R5bQaPermisosTest extends R5bCase
{
    private static string $base;
    private static array $e;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorB('perm');
        self::$e = self::escenarioB();
        // Doble pertenencia: el propietario también es cliente.
        self::q("INSERT INTO clientes(usuario_id,estado,origen) VALUES (?, 'activo', 'recepcion') ON DUPLICATE KEY UPDATE estado='activo'", [self::$e['dueno']['id']]);
    }

    public static function tearDownAfterClass(): void { self::pararServidorB('perm'); }
    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function n(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }
    private function vieja(array $o = []): int { return self::citaVieja(self::$e, $o); }

    private function sinEfecto(int $c, string $estado = 'confirmada', string $ctx = ''): void
    {
        $f = self::cita($c);
        $this->assertSame($estado, $f['estado'], "$ctx: el estado cambió");
        $this->assertNull($f['atencion_efectiva_at'], "$ctx: se registró atención efectiva");
        $this->assertNull($f['regularizada_por'], "$ctx: se registró regularizador");
        $this->assertCount(0, array_filter(self::regs($c), fn($r) => $r['estado'] === 'aprobada'), "$ctx: hay una aprobación");
        $this->assertCount(0, array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizar'), "$ctx: hay evento regularizar");
    }

    // ================================================================== inicio ordinario cerrado (decisión 1)

    /** Req 1: una cita de un día anterior NO se inicia de forma ordinaria: botón deshabilitado con motivo y POST forjado ⇒ 422, sin cambios ni eventos. */
    public function test_R1_inicio_ordinario_de_cita_vencida_es_422_y_el_boton_esta_deshabilitado_con_motivo(): void
    {
        $c = $this->vieja();
        $n = $this->n('dueno');
        $d1 = self::gye('-5 days')->format('Y-m-d'); $d2 = self::gye()->format('Y-m-d');
        $pag = $n->get("/agenda?desde=$d1&hasta=$d2");
        $t = (string)self::tarjeta($pag['body'], $c);
        $this->assertNotSame('', $t, 'la agenda no muestra la cita vencida');
        $ctl = self::control($t, '/Iniciar atenci[óo]n/iu');
        $this->assertTrue($ctl !== null && $ctl['disabled'], 'el botón «Iniciar atención» debe verse DESHABILITADO');
        $this->assertTrue(self::accion($t, '/Iniciar atenci[óo]n/iu') === null, 'no debe existir un «Iniciar atención» habilitado');
        $this->assertMatchesRegularExpression('/reprogram|regulariz|era del|d[ií]a anterior/iu', self::texto($t), 'falta el motivo del botón deshabilitado: ' . self::texto($t));
        $f = $this->formDe($n, $c, 'estado');
        $r = self::enviar($n, $f, ['estado' => 'en_servicio']);
        $this->rechazo($r, [422], 'inicio ordinario forjado');
        $this->assertMatchesRegularExpression('/reprogram|regulariz/iu', self::texto($r['body']), 'el 422 debe indicar las salidas (reprogramar/regularizar)');
        $this->assertSame('confirmada', self::cita($c)['estado']);
        $this->assertCount(0, self::evs($c));
    }

    // ================================================================== quién ve y quién puede (decisiones 2 y 4)

    /** Req 2/4: formularios del detalle por rol — regularizar (propietario, gerente), solicitar (profesional propio), reprogramar (gestión); ajeno 404; cliente y finanzas 403. */
    public function test_R2_formularios_visibles_por_rol(): void
    {
        $c = $this->vieja();
        $esperado = [   // rol => [regularizar, solicitar, reprogramar]
            'dueno' => [true, false, true], 'gerente' => [true, false, true], 'recepcion' => [false, false, true], 'proA' => [false, true, false],
        ];
        $fallos = [];
        foreach ($esperado as $rol => [$reg, $sol, $rep]) {
            $r = $this->n($rol)->get("/agenda/cita?id=$c");
            if ($r['status'] !== 200) { $fallos[] = "$rol detalle → {$r['status']}"; continue; }
            $tiene = fn(string $re) => self::formulario($r['body'], $re) !== null;
            if ($tiene('~/agenda/regularizar$~') !== $reg) { $fallos[] = "$rol: formulario regularizar " . ($reg ? 'ausente' : 'presente (no debe)'); }
            if ($tiene('~/agenda/regularizar/solicitar$~') !== $sol) { $fallos[] = "$rol: formulario solicitar " . ($sol ? 'ausente' : 'presente (no debe)'); }
            if ($tiene('~/agenda/reprogramar$~') !== $rep) { $fallos[] = "$rol: formulario reprogramar " . ($rep ? 'ausente' : 'presente (no debe)'); }
        }
        $aj = $this->n('proB')->get("/agenda/cita?id=$c");
        if (!in_array($aj['status'], [403, 404], true) || str_contains($aj['body'], 'Clienta')) { $fallos[] = "profesional ajeno → {$aj['status']} (filtra datos o no rechaza)"; }
        foreach (['finanzas' => $this->n('finanzas'), 'cliente' => self::entrar(self::$base, self::$e['cliente'], 'cliente'), 'dueno-como-cliente' => self::entrar(self::$base, self::$e['dueno'], 'cliente')] as $q => $nav) {
            $r = $nav->get("/agenda/cita?id=$c");
            if ($r['status'] !== 403) { $fallos[] = "$q detalle → {$r['status']} (esperado 403)"; }
            if (self::formulario($r['body'], '~regularizar~')) { $fallos[] = "$q ve formularios de regularización"; }
        }
        $this->sinFallos($fallos);
    }

    /** Req 2: «Regularizar» solo propietario y gerente: ambos pueden; recepción, finanzas, profesional (propio y ajeno), cliente y doble membresía en portal cliente reciben 403 con POST forjado y NADA cambia. */
    public function test_R2_solo_propietario_y_gerente_regularizan(): void
    {
        $cD = $this->vieja(); $cG = $this->vieja();
        $donante = $this->vieja();
        $form = $this->formDe($this->n('dueno'), $donante, 'regularizar');
        $this->aceptado($this->regularizar($this->n('dueno'), $cD), 'propietario regulariza');
        $this->aceptado($this->regularizar($this->n('gerente'), $cG), 'gerente regulariza');
        $this->assertSame(['completada', 'completada'], [self::cita($cD)['estado'], self::cita($cG)['estado']]);
        $fallos = [];
        $quienes = ['recepcion' => $this->n('recepcion'), 'finanzas' => $this->n('finanzas'), 'proA' => $this->n('proA'), 'proB' => $this->n('proB'),
            'cliente' => self::entrar(self::$base, self::$e['cliente'], 'cliente'), 'dueno-en-portal-cliente' => self::entrar(self::$base, self::$e['dueno'], 'cliente')];
        foreach ($quienes as $q => $nav) {
            $csrf = Navegador::csrfDe($nav->get('/perfil')['body']) ?? '';
            $cuerpo = array_merge($form['campos'], ['csrf_token' => $csrf, 'id' => (string)$donante, 'efectiva' => self::efectiva($donante), 'motivo' => 'Intento sin permiso de regularizar', self::campo($form, '/idem/i') => bin2hex(random_bytes(12))]);
            $r = $nav->post('/agenda/regularizar', $cuerpo);
            if ($r['status'] !== 403) { $fallos[] = "$q POST /agenda/regularizar → {$r['status']} (esperado 403)"; }
            if (str_contains($r['body'], 'Clienta') || self::enlacesCruzados($r['body'], $q === 'cliente' || $q === 'dueno-en-portal-cliente' ? 'cliente' : 'equipo')) { $fallos[] = "$q: el 403 filtra datos o enlaza al otro portal"; }
        }
        $this->sinFallos($fallos);
        $this->sinEfecto($donante, 'confirmada', 'tras intentos sin permiso');
    }

    /** Req 4: el profesional SOLICITA solo sus citas (la ajena es 404), una sola solicitud abierta (409), recepción/finanzas/cliente no solicitan; nadie que no sea propietario/gerente rechaza. */
    public function test_R4_solicitud_del_profesional_y_limites(): void
    {
        $propia = $this->vieja(); $ajena = $this->vieja(['pro' => self::$e['proB']['profesional_id']]);
        $pa = $this->n('proA');
        $f = $this->formDe($pa, $propia, 'solicitar');
        $this->aceptado($this->enviarB($pa, $f, ['efectiva' => self::efectiva($propia), 'motivo' => 'Atendí a la clienta y no lo registré']), 'solicitud propia');
        $reg = self::regs($propia);
        $this->assertCount(1, $reg);
        $this->assertSame('solicitada', $reg[0]['estado']);
        $this->assertSame(self::$e['proA']['id'], (int)$reg[0]['solicitada_por']);
        $this->assertSame('confirmada', self::cita($propia)['estado'], 'solicitar nunca regulariza');
        $this->assertCount(1, array_filter(self::evs($propia), fn($v) => $v['tipo'] === 'regularizacion_solicitud'));
        // Segunda solicitud abierta.
        $r = $this->enviarB($pa, $f, ['efectiva' => self::efectiva($propia), 'motivo' => 'Segunda solicitud de la misma cita']);
        $this->rechazo($r, [409], 'solicitud duplicada');
        $this->assertCount(1, self::regs($propia));
        // Ajena: se comporta como inexistente.
        $r = self::enviar($pa, $f, ['id' => (string)$ajena, 'efectiva' => self::efectiva($ajena), 'motivo' => 'Solicitud sobre la cita de otro profesional', self::campo($f, '/idem/i') => bin2hex(random_bytes(12))]);
        $this->rechazo($r, [404], 'solicitud de cita ajena');
        $this->assertCount(0, self::regs($ajena));
        // Otros roles: 403.
        foreach (['recepcion', 'finanzas', 'proB'] as $rol) {
            $nav = $this->n($rol);
            $csrf = Navegador::csrfDe($nav->get('/perfil')['body']) ?? '';
            $r = $nav->post('/agenda/regularizar/solicitar', ['csrf_token' => $csrf, 'id' => $propia, 'efectiva' => self::efectiva($propia), 'motivo' => 'Solicitud de alguien sin el permiso', 'idem_key' => bin2hex(random_bytes(12))]);
            $this->assertContains($r['status'], [403, 404, 409], "$rol solicitando: {$r['status']}");
            $this->assertNotContains($r['status'], [200, 302, 303], "$rol solicitó");
        }
        $this->assertCount(1, self::regs($propia));
        // Rechazar: solo propietario/gerente.
        $rid = (int)$reg[0]['id'];
        foreach (['proA', 'recepcion', 'finanzas'] as $rol) {
            $nav = $this->n($rol);
            $csrf = Navegador::csrfDe($nav->get('/perfil')['body']) ?? '';
            $r = $nav->post('/agenda/regularizar/rechazar', ['csrf_token' => $csrf, 'regularizacion_id' => $rid, 'motivo' => 'Rechazo sin autoridad para hacerlo', 'idem_key' => bin2hex(random_bytes(12))]);
            $this->assertSame(403, $r['status'], "$rol rechazando");
        }
        $this->assertSame('solicitada', self::regs($propia)[0]['estado']);
        // El cliente no puede nada.
        $cli = self::entrar(self::$base, self::$e['cliente'], 'cliente');
        $csrf = Navegador::csrfDe($cli->get('/cita')['body']) ?? '';
        foreach (['/agenda/regularizar/solicitar', '/agenda/regularizar', '/agenda/regularizar/rechazar'] as $ruta) {
            $r = $cli->post($ruta, ['csrf_token' => $csrf, 'id' => $propia, 'regularizacion_id' => $rid, 'efectiva' => self::efectiva($propia), 'motivo' => 'El cliente intenta regularizar', 'idem_key' => bin2hex(random_bytes(12))]);
            $this->assertSame(403, $r['status'], "cliente POST $ruta");
        }
        $this->assertSame('confirmada', self::cita($propia)['estado']);
    }

    /** Req 4: rechazar exige motivo, deja la cita pendiente (sigue confirmada) y permite una nueva solicitud; el rechazo queda auditado en citas_regularizaciones y agenda_eventos. */
    public function test_R4_rechazo_con_motivo_y_nueva_solicitud(): void
    {
        $c = $this->vieja();
        $pa = $this->n('proA');
        $this->aceptado($this->enviarB($pa, $this->formDe($pa, $c, 'solicitar'), ['efectiva' => self::efectiva($c), 'motivo' => 'Primera solicitud para esta cita']), 'solicitud');
        $g = $this->n('gerente');
        $fr = $this->formDe($g, $c, 'rechazar');
        foreach (['', 'corto', str_repeat('x', 501), "con\x00control", '   '] as $malo) {
            $r = $this->enviarB($g, $fr, ['motivo' => $malo]);
            $this->rechazo($r, [400, 422], 'rechazo con motivo «' . addcslashes(substr($malo, 0, 12), "\0") . '»');
        }
        $this->assertSame('solicitada', self::regs($c)[0]['estado']);
        $this->aceptado($this->enviarB($g, $fr, ['motivo' => 'No coincide con la agenda del profesional']), 'rechazo válido');
        $reg = self::regs($c)[0];
        $this->assertSame('rechazada', $reg['estado']);
        $this->assertSame(self::$e['gerente']['id'], (int)$reg['resuelta_por']);
        $this->assertLessThan(120, self::difSeg($reg['resuelta_at']));
        $this->assertSame('confirmada', self::cita($c)['estado'], 'rechazar deja la cita pendiente');
        $this->assertCount(1, array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizacion_rechazo'));
        $this->aceptado($this->enviarB($pa, $this->formDe($pa, $c, 'solicitar'), ['efectiva' => self::efectiva($c), 'motivo' => 'Nueva solicitud con más detalle']), 'nueva solicitud tras rechazo');
        $this->assertCount(2, self::regs($c));
        // Rechazo repetido sobre una decisión ya tomada.
        $r = self::enviar($g, $fr, ['motivo' => 'Rechazo repetido de la misma solicitud', self::campo($fr, '/idem/i') => bin2hex(random_bytes(12))]);
        $this->rechazo($r, [404, 409, 422], 'rechazar dos veces la misma solicitud');
    }

    // ================================================================== validaciones (POST forjados / hostiles)

    /** Req 2: efectiva futura, anterior al día de la cita o con formato raro ⇒ 422 (nunca 5xx) y la cita no cambia. */
    public function test_R2_fecha_efectiva_invalida(): void
    {
        $c = $this->vieja();
        $n = $this->n('dueno');
        $f = $this->formDe($n, $c, 'regularizar');
        $dia = self::cita($c)['fecha'];
        $malos = ['mañana' => self::gye('+1 day')->format('Y-m-d\TH:i'), 'dentro de una hora' => self::gye('+1 hour')->format('Y-m-d\TH:i'), 'día anterior a la cita' => self::gye('-4 days')->format('Y-m-d') . 'T23:59',
            'año 1900' => '1900-01-01T10:00', 'vacío' => '', 'texto' => 'ayer', 'mes 13' => '2026-13-45T99:99', 'solo fecha' => $dia, 'cero' => '0000-00-00T00:00', 'sql' => "$dia'T11:00; DROP TABLE citas;--", 'nul' => "{$dia}T11:00\0", 'espacios' => '   ', 'segundos raros' => "{$dia}T11:00:99"];
        $fallos = [];
        foreach ($malos as $nombre => $v) {
            $r = $this->regularizar($n, $c, ['efectiva' => $v], $f);
            if (!in_array($r['status'], [400, 422], true)) { $fallos[] = "efectiva «{$nombre}» → {$r['status']} (esperado 422)"; }
            if (preg_match('/SQLSTATE|Stack trace|Warning:|Fatal/', $r['body'])) { $fallos[] = "efectiva «{$nombre}» filtra detalles internos"; }
        }
        $r = self::enviar($n, $f, ['efectiva' => ['x' => 'y'], 'motivo' => 'Efectiva enviada como arreglo', self::campo($f, '/idem/i') => bin2hex(random_bytes(12))]);
        if ($r['status'] >= 500 || $r['status'] < 400) { $fallos[] = "efectiva[] → {$r['status']}"; }
        $this->sinFallos($fallos);
        $this->sinEfecto($c, 'confirmada', 'tras efectivas inválidas');
        // Justo en el límite válido: el mismo día de la cita a las 00:00 y el instante actual menos un minuto (si el día de la cita no es hoy, ya es pasado).
        $this->aceptado($this->regularizar($n, $c, ['efectiva' => $dia . 'T00:00']), 'efectiva a las 00:00 del día de la cita');
    }

    /** Req 2: motivo corto (< 10), largo (> 500), solo espacios, con caracteres de control ⇒ 422; límites exactos 10 y 500 válidos; el motivo hostil se guarda y se muestra ESCAPADO. */
    public function test_R2_motivo_y_clave_idempotencia_hostiles(): void
    {
        $n = $this->n('dueno');
        $c = $this->vieja();
        $f = $this->formDe($n, $c, 'regularizar');
        $fallos = [];
        foreach (['nueve ch.' => str_repeat('a', 9), 'vacío' => '', 'espacios' => str_repeat(' ', 20), '501' => str_repeat('a', 501), 'NUL' => "motivo con nul\0 dentro", 'BEL' => "motivo con \x07 control", 'utf8 inválido' => "motivo \xC3\x28 roto largo"] as $nombre => $m) {
            $r = $this->regularizar($n, $c, ['motivo' => $m], $f);
            if (!in_array($r['status'], [400, 422], true)) { $fallos[] = "motivo «{$nombre}» → {$r['status']} (esperado 422)"; }
        }
        $k = self::campo($f, '/idem/i');
        foreach (['vacía' => '', 'larga 300' => str_repeat('k', 300), 'con espacios' => 'clave con espacios y ñ', 'array' => ['a']] as $nombre => $v) {
            $r = self::enviar($n, $f, [$k => $v, 'efectiva' => self::efectiva($c), 'motivo' => 'Motivo correcto para probar la clave']);
            if ($r['status'] >= 500) { $fallos[] = "clave idem «{$nombre}» → {$r['status']} (5xx)"; }
            if (in_array($r['status'], [200, 302, 303], true)) { $fallos[] = "clave idem «{$nombre}» aceptada (debe rechazarse con 422)"; self::q("UPDATE citas SET estado='confirmada',atencion_efectiva_at=NULL,regularizada_por=NULL,regularizada_at=NULL WHERE id=?", [$c]); self::q('DELETE FROM citas_regularizaciones WHERE cita_id=?', [$c]); }
        }
        $this->sinFallos($fallos);
        $this->sinEfecto($c, 'confirmada', 'tras motivos/claves inválidos');
        // Quitar la clave del formulario.
        $r = self::enviar($n, $f, ['efectiva' => self::efectiva($c), 'motivo' => 'Motivo correcto sin clave de idempotencia'], [$k]);
        $this->assertGreaterThanOrEqual(400, $r['status'], 'sin clave de idempotencia debe rechazarse (contrato: las POST llevan idem_key)');
        $this->assertLessThan(500, $r['status']);
        // Límites exactos válidos + XSS escapado.
        $xss = '<script>alert(1)</script>' . str_repeat('x', 5);
        $this->aceptado($this->regularizar($n, $c, ['motivo' => $xss]), 'motivo con HTML');
        $html = $this->detalle($n, $c)['body'];
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'el motivo hostil se refleja sin escapar');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $c2 = $this->vieja();
        $this->aceptado($this->regularizar($n, $c2, ['motivo' => str_repeat('b', 10)]), 'motivo de 10');
        $c3 = $this->vieja();
        $this->aceptado($this->regularizar($n, $c3, ['motivo' => str_repeat('c', 500)]), 'motivo de 500');
    }

    /** Req 2: POST forjados — sin CSRF, GET en lugar de POST, id inexistente/no numérico/de otra cita, campos extra hostiles (estado, usuario, fechas internas) ignorados. */
    public function test_R2_posts_forjados(): void
    {
        $c = $this->vieja(); $otra = $this->vieja(['dias' => 4]);
        $n = $this->n('dueno');
        $f = $this->formDe($n, $c, 'regularizar');
        $r = $this->regularizar($n, $c, [], array_merge($f, ['campos' => array_diff_key($f['campos'], ['csrf_token' => 1])]));
        $this->assertContains($r['status'], [403, 419], 'sin CSRF');
        $g = $n->get('/agenda/regularizar?id=' . $c . '&efectiva=' . urlencode(self::efectiva($c)) . '&motivo=abcdefghijk');
        $this->assertContains($g['status'], [404, 405], 'GET /agenda/regularizar → ' . $g['status']);
        $this->sinEfecto($c, 'confirmada', 'CSRF/GET');
        foreach (['99999999' => [404], 'abc' => [404, 422], '0' => [404, 422], '-5' => [404, 422], '1 OR 1=1' => [404, 422], '' => [404, 422]] as $id => $codigos) {
            $r = $this->regularizar($n, $c, ['id' => (string)$id], $f);
            $this->assertContains($r['status'], $codigos, "id «{$id}» → {$r['status']}");
        }
        // Campos extra: no deben alterar lo que se registra.
        $extra = ['estado' => 'no_asistio', 'usuario_id' => (string)self::$e['cliente2']['id'], 'regularizada_por' => (string)self::$e['cliente']['id'], 'atencion_efectiva_at' => '2000-01-01 00:00:00', 'regularizada_at' => '2000-01-01 00:00:00',
            'atencion_inicio_at' => '2000-01-01 00:00:00', 'fecha' => '2000-01-01', 'cita_id' => (string)$otra, 'actor' => '1'];
        $fecha0 = self::cita($c)['fecha'];
        $this->aceptado($this->regularizar($n, $c, $extra, $f), 'regularizar con campos extra');
        $r = self::cita($c);
        $this->assertSame('completada', $r['estado']);
        $this->assertSame($fecha0, $r['fecha'], 'la fecha original no cambia');
        $this->assertSame(self::$e['dueno']['id'], (int)$r['regularizada_por'], 'el regularizador lo decide la sesión, no el formulario');
        $this->assertNotSame('2000-01-01 00:00:00', $r['regularizada_at']);
        $this->assertSame($fecha0 . ' 11:00:00', $r['atencion_efectiva_at']);
        $this->assertNull($r['atencion_inicio_at']);
        $this->sinEfecto($otra, 'confirmada', 'otra cita (cita_id forjado)');
    }

    /** Req 2: solo citas confirmadas o en servicio de un día ANTERIOR; hoy y las históricas (completada, cancelada, ausencia) ⇒ 409 sin cambios; en servicio vieja sí. */
    public function test_R2_estados_admitidos(): void
    {
        $n = $this->n('dueno');
        $donante = $this->vieja();
        $f = $this->formDe($n, $donante, 'regularizar');
        $fallos = [];
        foreach (['completada' => 409, 'cancelada' => 409, 'no_asistio' => 409] as $estado => $cod) {
            $c = $this->vieja(['estado' => $estado]);
            $r = $this->regularizar($n, $c, ['id' => (string)$c], $f);
            if (!in_array($r['status'], [$cod, 422], true)) { $fallos[] = "$estado vieja → {$r['status']} (esperado $cod)"; }
            if (self::cita($c)['estado'] !== $estado || self::cita($c)['atencion_efectiva_at'] !== null) { $fallos[] = "$estado vieja: cambió"; }
        }
        [$h, $hora] = [self::gye()->format('Y-m-d'), '00:30:00'];
        $hoy = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $h, 'hora' => $hora]);
        $r = $this->regularizar($n, $hoy, ['id' => (string)$hoy, 'efectiva' => $h . 'T00:10'], $f);
        if (!in_array($r['status'], [409, 422], true)) { $fallos[] = "cita de HOY → {$r['status']} (sigue el flujo ordinario)"; }
        if (self::cita($hoy)['estado'] !== 'confirmada') { $fallos[] = 'la cita de hoy cambió'; }
        $fut = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::gye('+2 days')->format('Y-m-d'), 'hora' => '10:00:00']);
        $r = $this->regularizar($n, $fut, ['id' => (string)$fut, 'efectiva' => self::gye('-1 hour')->format('Y-m-d\TH:i')], $f);
        if (!in_array($r['status'], [409, 422], true)) { $fallos[] = "cita FUTURA → {$r['status']}"; }
        $sv = $this->vieja(['estado' => 'en_servicio']);
        $this->aceptado($this->regularizar($n, $sv, ['id' => (string)$sv], $f), 'en servicio vieja');
        if (self::cita($sv)['estado'] !== 'completada') { $fallos[] = 'en_servicio vieja no quedó completada'; }
        $this->sinFallos($fallos);
        // Ya regularizada: segunda vez ⇒ 409, un solo registro aprobado.
        $r = $this->regularizar($n, $sv, ['id' => (string)$sv], $f);
        $this->rechazo($r, [409], 'regularizar dos veces');
        $this->assertCount(1, array_filter(self::regs($sv), fn($x) => $x['estado'] === 'aprobada'));
    }

    // ================================================================== sesión y rol en caliente

    /** Req 2/4: sesión caducada, rol cambiado en caliente (gerente → recepción → profesional) y acceso laboral suspendido aplican en la SIGUIENTE petición; sin efecto sobre la cita. */
    public function test_R2_sesion_caducada_y_rol_en_caliente(): void
    {
        $c = $this->vieja();
        // Sesión caducada.
        $n = $this->n('dueno');
        $f = $this->formDe($n, $c, 'regularizar');
        @unlink(self::sesionB((string)$n->cookieSesion(), 'perm'));
        $r = $this->regularizar($n, $c, [], $f);
        $this->assertContains($r['status'], [302, 303, 401, 403, 419], 'sesión caducada: ' . $r['status']);
        $this->assertNotContains($r['status'], [200]);
        $this->sinEfecto($c, 'confirmada', 'sesión caducada');
        // Rol en caliente.
        $g = self::miembro(self::$e['dueno']['id'], 'gerente', ['nombre' => 'Caliente']);
        $ng = self::entrar(self::$base, $g);
        $fg = $this->formDe($ng, $c, 'regularizar');
        self::q("UPDATE usuario_accesos SET rol='recepcion' WHERE usuario_id=?", [$g['id']]);
        $r = $this->regularizar($ng, $c, [], $fg);
        $this->assertSame(403, $r['status'], 'gerente degradado a recepción: ' . $r['status']);
        $this->sinEfecto($c, 'confirmada', 'rol degradado');
        self::q("UPDATE usuario_accesos SET rol='profesional' WHERE usuario_id=?", [$g['id']]);
        $r = $ng->get('/agenda/pendientes');
        $this->assertContains($r['status'], [200, 403]);
        $this->assertStringNotContainsString((string)self::$e['cliente']['email'], $r['body']);
        self::q("UPDATE usuario_accesos SET rol='gerente' WHERE usuario_id=?", [$g['id']]);
        self::q('UPDATE usuario_accesos SET activo=0 WHERE usuario_id=?', [$g['id']]);
        $r = $this->regularizar($ng, $c, [], $fg);
        $this->assertContains($r['status'], [302, 303, 401, 403], 'acceso laboral suspendido: ' . $r['status']);
        $this->sinEfecto($c, 'confirmada', 'acceso suspendido');
        // Ascenso en caliente: una recepcionista promovida a gerente puede.
        $rcp = self::miembro(self::$e['dueno']['id'], 'recepcion', ['nombre' => 'Ascenso']);
        $nr = self::entrar(self::$base, $rcp);
        $this->assertNull(self::formulario($this->detalle($nr, $c)['body'], '~/agenda/regularizar$~'));
        self::q("UPDATE usuario_accesos SET rol='gerente' WHERE usuario_id=?", [$rcp['id']]);
        $this->aceptado($this->regularizar($nr, $c), 'regularizar tras el ascenso');
    }

    /** Req 2: la matriz de pendientes por rol — /agenda/pendientes: equipo con agenda 200; finanzas y cliente 403; anónimo redirige. */
    public function test_R2_pendientes_por_rol(): void
    {
        $fallos = [];
        foreach (['dueno' => 200, 'gerente' => 200, 'recepcion' => 200, 'proA' => 200, 'proB' => 200, 'finanzas' => 403] as $rol => $esperado) {
            $r = $this->n($rol)->get('/agenda/pendientes');
            if ($r['status'] !== $esperado) { $fallos[] = "$rol /agenda/pendientes → {$r['status']} (esperado $esperado)"; }
            if ($esperado === 403 && self::enlacesCruzados($r['body'], 'equipo')) { $fallos[] = "$rol: 403 con enlaces cruzados"; }
        }
        $r = self::entrar(self::$base, self::$e['cliente'], 'cliente')->get('/agenda/pendientes');
        if ($r['status'] !== 403 || self::enlacesCruzados($r['body'], 'cliente')) { $fallos[] = "cliente → {$r['status']}"; }
        $r = (new Navegador(self::$base))->get('/agenda/pendientes');
        if (!in_array($r['status'], [302, 303, 401], true)) { $fallos[] = "anónimo → {$r['status']}"; }
        $this->sinFallos($fallos);
    }
}
