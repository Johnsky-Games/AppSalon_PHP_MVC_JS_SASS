<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5bCase.php';

/**
 * R5b-QA · Regularización: auditoría (quién regularizó ≠ cuándo ocurrió el servicio, fecha original intacta, sin inventar inicio/fin),
 * reprogramación de citas vencidas y CONCURRENCIA REAL con procesos PHP independientes (docs/R5_REGULARIZACION.md §2, §4, §5).
 */
final class R5bQaAuditoriaTest extends R5bCase
{
    private static string $base;
    private static array $e;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorB('aud');
        self::$e = self::escenarioB();
    }

    public static function tearDownAfterClass(): void { self::pararServidorB('aud'); }
    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function n(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }
    private function vieja(array $o = []): int { return self::citaVieja(self::$e, $o); }

    // ================================================================== auditoría

    /**
     * Req 2/3: regularizar registra POR SEPARADO cuándo ocurrió el servicio (atencion_efectiva_at) y quién/cuándo lo regularizó (regularizada_por/_at, momento real);
     * la fecha/hora originales no cambian; atencion_inicio/fin quedan vacíos; el estado de pago NO cambia; hay fila aprobada y evento con actor y hora real.
     */
    public function test_A_regularizar_registra_efectiva_y_regularizacion_por_separado(): void
    {
        $c = $this->vieja();
        $antes = self::cita($c);
        $n = $this->n('gerente');
        $this->aceptado($this->regularizar($n, $c, ['efectiva' => self::efectiva($c, '14:25'), 'motivo' => 'La clienta fue atendida y se olvidó registrarlo']), 'regularizar');
        $d = self::cita($c);
        $this->assertSame('completada', $d['estado']);
        foreach (['fecha', 'hora', 'hora_inicio', 'hora_fin', 'duracion_total_minutos', 'usuarioId', 'profesionalId'] as $k) { $this->assertSame($antes[$k], $d[$k], "«{$k}» de la cita no debe cambiar"); }
        $this->assertSame($antes['fecha'] . ' 14:25:00', $d['atencion_efectiva_at'], 'cuándo ocurrió el servicio');
        $this->assertSame(self::$e['gerente']['id'], (int)$d['regularizada_por'], 'quién regularizó');
        $this->assertLessThan(120, self::difSeg($d['regularizada_at']), 'regularizada_at es el momento REAL (hora de Guayaquil): ' . $d['regularizada_at']);
        $this->assertNotSame(substr($d['atencion_efectiva_at'], 0, 10), substr($d['regularizada_at'], 0, 10), 'cuándo se registró ≠ cuándo ocurrió');
        foreach (['atencion_inicio_at', 'atencion_inicio_por', 'atencion_fin_at', 'atencion_fin_por', 'ausencia_at', 'ausencia_por'] as $k) { $this->assertNull($d[$k], "«{$k}» no se inventa"); }
        $this->assertSame('pendiente', $d['estado_pago'], 'regularizar no marca pagado');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [$c]));
        $reg = self::regs($c);
        $this->assertCount(1, $reg);
        $this->assertSame('aprobada', $reg[0]['estado']);
        $this->assertNull($reg[0]['solicitada_por'], 'regularización directa: sin solicitante');
        $this->assertSame(self::$e['gerente']['id'], (int)$reg[0]['resuelta_por']);
        $this->assertSame($d['atencion_efectiva_at'], $reg[0]['atencion_efectiva_at']);
        $this->assertSame('La clienta fue atendida y se olvidó registrarlo', $reg[0]['motivo_resolucion']);
        $this->assertLessThanOrEqual(strtotime($reg[0]['resuelta_at']), strtotime($reg[0]['atencion_efectiva_at']), 'efectiva ≤ momento de la decisión');
        $ev = array_values(array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizar'));
        $this->assertCount(1, $ev);
        $this->assertSame(self::$e['gerente']['id'], (int)$ev[0]['actorId']);
        $this->assertLessThan(120, self::difSeg($ev[0]['creado_en']));
        $this->assertSame('confirmada', json_decode($ev[0]['antes_json'], true)['estado'] ?? null);
        $this->assertSame('completada', json_decode($ev[0]['despues_json'], true)['estado'] ?? null);
        // El detalle muestra ambos datos distintos y quién.
        $txt = self::texto($this->detalle($n, $c)['body']);
        $this->assertStringContainsString('14:25', $txt, 'el detalle debe mostrar la hora efectiva');
        $this->assertStringContainsString('Gera', $txt, 'el detalle debe mostrar quién regularizó');
        $this->assertMatchesRegularExpression('/regulariz/iu', $txt);
    }

    /** Req 2/3: una cita EN SERVICIO vieja (con inicio real registrado) se regulariza sin tocar atencion_inicio_*, y sin inventar el fin. */
    public function test_A_en_servicio_vieja_conserva_su_inicio_real_y_no_inventa_fin(): void
    {
        $c = $this->vieja(['estado' => 'en_servicio']);
        self::q('UPDATE citas SET atencion_inicio_at=?, atencion_inicio_por=? WHERE id=?', [self::gye('-3 days')->format('Y-m-d') . ' 10:05:00', self::$e['recepcion']['id'], $c]);
        $this->aceptado($this->regularizar($this->n('dueno'), $c), 'regularizar en servicio');
        $d = self::cita($c);
        $this->assertSame('completada', $d['estado']);
        $this->assertSame(self::gye('-3 days')->format('Y-m-d') . ' 10:05:00', $d['atencion_inicio_at'], 'el inicio real registrado se conserva');
        $this->assertSame(self::$e['recepcion']['id'], (int)$d['atencion_inicio_por']);
        $this->assertNull($d['atencion_fin_at'], 'no se inventa el fin');
        $this->assertNull($d['atencion_fin_por']);
    }

    /** Req 4: flujo completo solicitud → aprobación: la solicitud conserva solicitante/fecha propuesta y se resuelve (aprobada) por el gerente; eventos de ambos pasos. */
    public function test_A_solicitud_del_profesional_aprobada_por_el_gerente(): void
    {
        $c = $this->vieja();
        $pa = $this->n('proA');
        $this->aceptado($this->enviarB($pa, $this->formDe($pa, $c, 'solicitar'), ['efectiva' => self::efectiva($c, '12:10'), 'motivo' => 'Terminé el servicio y no alcancé a registrarlo']), 'solicitud');
        $s = self::regs($c)[0];
        $this->assertSame(self::$e['proA']['id'], (int)$s['solicitada_por']);
        $this->assertLessThan(120, self::difSeg($s['solicitada_at']));
        $this->assertSame(self::cita($c)['fecha'] . ' 12:10:00', $s['efectiva_propuesta_at']);
        $g = $this->n('gerente');
        $this->assertStringContainsString('Terminé el servicio', self::texto($this->detalle($g, $c)['body']), 'el gerente ve el motivo de la solicitud');
        $this->aceptado($this->regularizar($g, $c, ['efectiva' => self::efectiva($c, '12:15'), 'motivo' => 'Confirmado con la agenda del profesional']), 'aprobación');
        $regs = self::regs($c);
        $this->assertCount(1, $regs, 'la aprobación resuelve la solicitud abierta (no crea otra fila)');
        $this->assertSame('aprobada', $regs[0]['estado']);
        $this->assertSame(self::$e['proA']['id'], (int)$regs[0]['solicitada_por']);
        $this->assertSame(self::$e['gerente']['id'], (int)$regs[0]['resuelta_por']);
        $this->assertSame(self::cita($c)['fecha'] . ' 12:15:00', $regs[0]['atencion_efectiva_at'], 'manda la fecha efectiva que decide el gerente');
        $tipos = array_column(self::evs($c), 'tipo');
        $this->assertContains('regularizacion_solicitud', $tipos);
        $this->assertContains('regularizar', $tipos);
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='solicitada'", [$c]), 'no queda solicitud abierta');
    }

    // ================================================================== reprogramar cita vencida (decisión 1)

    private function slotLibre(int $dias = 2): array { return [self::gye("+$dias days")->format('Y-m-d'), '15:00']; }

    /** Req 1: la gestión reprograma una cita vencida con las validaciones habituales; agenda_eventos conserva el horario original, el nuevo, quién y cuándo, e indica que venía de «pendiente de revisión». */
    public function test_B_reprogramar_cita_vencida_conserva_historial(): void
    {
        $c = $this->vieja();
        $orig = self::cita($c);
        $n = $this->n('recepcion');
        [$f, $h] = $this->slotLibre();
        $r = $this->enviarB($n, $this->formDe($n, $c, 'reprogramar'), ['fecha' => $f, 'hora' => $h]);
        $this->aceptado($r, 'reprogramar vencida');
        $d = self::cita($c);
        $this->assertSame([$f, $h . ':00'], [$d['fecha'], $d['hora_inicio']]);
        $this->assertSame('confirmada', $d['estado']);
        $ev = array_values(array_filter(self::evs($c), fn($v) => $v['tipo'] === 'reprogramar'));
        $this->assertCount(1, $ev);
        $antes = json_decode($ev[0]['antes_json'], true); $desp = json_decode($ev[0]['despues_json'], true);
        $this->assertSame($orig['fecha'], $antes['fecha'], 'el historial conserva la fecha original');
        $this->assertStringStartsWith('10:00', (string)$antes['hora']);
        $this->assertSame($f, $desp['fecha']);
        $this->assertArrayHasKey('desde_pendiente_revision', $desp);
        $this->assertTrue((bool)$desp['desde_pendiente_revision']);
        $this->assertSame(self::$e['recepcion']['id'], (int)$ev[0]['actorId']);
        $this->assertLessThan(120, self::difSeg($ev[0]['creado_en']));
        // Ya no es pendiente de revisión (fecha futura).
        $p = self::texto($n->get('/agenda/pendientes')['body']);
        $this->assertStringNotContainsString("#$c", $p);
    }

    /** Req 1: validaciones de reprogramar una vencida — choque, fuera de horario, fecha pasada u hoy, formatos raros ⇒ rechazo sin cambios; cliente y profesional no pueden. */
    public function test_B_reprogramar_vencida_validaciones_y_permisos(): void
    {
        $c = $this->vieja();
        $orig = self::cita($c);
        [$f, $h] = $this->slotLibre(3);
        self::crearCita(['cliente' => self::$e['cliente2']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h . ':00', 'minutos' => 30]);
        $n = $this->n('recepcion');
        $form = $this->formDe($n, $c, 'reprogramar');
        $fallos = [];
        $casos = ['choque con otra cita' => [$f, $h, [409]], 'fuera de horario (20:00)' => [$f, '20:00', [409, 422]], 'antes del horario (06:00)' => [$f, '06:00', [409, 422]], 'ayer' => [self::gye('-1 day')->format('Y-m-d'), '12:00', [409, 422]],
            'hoy' => [self::gye()->format('Y-m-d'), '23:30', [409, 422]], 'la misma fecha vencida' => [$orig['fecha'], '12:00', [409, 422]], 'fecha basura' => ['2026-02-30', '12:00', [409, 422]], 'hora basura' => [$f, '25:99', [409, 422]],
            'hora con letras' => [$f, 'abc', [409, 422]], 'vacía' => ['', '', [409, 422]], 'sql' => ["$f'; DROP TABLE citas;--", '12:00', [409, 422]]];
        foreach ($casos as $nombre => [$fe, $ho, $cods]) {
            $r = $this->enviarB($n, $form, ['fecha' => $fe, 'hora' => $ho]);
            if (!in_array($r['status'], $cods, true)) { $fallos[] = "reprogramar a «{$nombre}» → {$r['status']} (esperado " . implode('/', $cods) . ')'; }
            if (self::cita($c)['fecha'] !== $orig['fecha']) { $fallos[] = "«{$nombre}»: MODIFICÓ la cita"; self::q('UPDATE citas SET fecha=?, hora=?, hora_inicio=?, hora_fin=? WHERE id=?', [$orig['fecha'], $orig['hora'], $orig['hora_inicio'], $orig['hora_fin'], $c]); }
        }
        $this->sinFallos($fallos);
        $this->assertCount(0, array_filter(self::evs($c), fn($v) => $v['tipo'] === 'reprogramar'), 'rechazos no dejan evento');
        // Cliente (dueño de la cita) y profesional no reprograman una vencida.
        $cli = self::entrar(self::$base, self::$e['cliente'], 'cliente');
        $csrf = Navegador::csrfDe($cli->get('/cita')['body']) ?? '';
        $r = $cli->post('/agenda/reprogramar', ['csrf_token' => $csrf, 'id' => $c, 'fecha' => self::gye('+5 days')->format('Y-m-d'), 'hora' => '11:00', 'dia' => 1]);
        $this->assertContains($r['status'], [403, 409, 422], 'cliente reprogramando una vencida');
        $pa = $this->n('proA');
        $csrf = Navegador::csrfDe($pa->get('/perfil')['body']) ?? '';
        $r = $pa->post('/agenda/reprogramar', ['csrf_token' => $csrf, 'id' => $c, 'fecha' => self::gye('+5 days')->format('Y-m-d'), 'hora' => '11:00', 'dia' => 1]);
        $this->assertSame(403, $r['status'], 'el profesional no reprograma (solo la gestión)');
        $this->assertSame($orig['fecha'], self::cita($c)['fecha']);
    }

    // ================================================================== concurrencia real

    /** @return array{0:Navegador,1:array} navegador + cuerpo POST del formulario, listo para la ráfaga */
    private function preparar(string $rol, int $c, string $accion, array $cambios = []): array
    {
        $n = $this->n($rol);
        $f = $this->formDe($n, $c, $accion);
        if ($accion === 'regularizar') { $cambios += ['efectiva' => self::efectiva($c), 'motivo' => 'Regularización concurrente de prueba']; }
        return [$n, ['cookie' => $n->cookieSesion(), 'metodo' => 'POST', 'ruta' => $f['action'], 'body' => $this->cuerpoB($f, $cambios)]];
    }

    private function sin5xx(array $res, string $ctx): void
    {
        foreach ($res as $i => $r) { $this->assertLessThan(500, $r['status'], "$ctx: proceso $i devolvió {$r['status']}: " . substr(self::texto($r['body']), 0, 160)); }
        $this->assertStringNotContainsString('Deadlock', self::logB('aud'), "$ctx: interbloqueo");
    }

    /** Req 5: DOS regularizaciones simultáneas (propietario y gerente, claves distintas): una gana, la otra 409; UNA fila aprobada y UN evento. 6 rondas. */
    public function test_C_dos_regularizaciones_simultaneas_gana_una(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $c = $this->vieja(['dias' => 2 + $i]);
            [, $a] = $this->preparar('dueno', $c, 'regularizar', ['efectiva' => self::efectiva($c, '11:00'), 'motivo' => 'Regularización del propietario']);
            [, $b] = $this->preparar('gerente', $c, 'regularizar', ['efectiva' => self::efectiva($c, '12:00'), 'motivo' => 'Regularización del gerente']);
            $res = self::rafaga(self::$base, [$a, $b]);
            $this->sin5xx($res, "ronda $i");
            $est = array_column($res, 'status');
            $this->assertSame(1, count(array_filter($est, fn($s) => in_array($s, [302, 303, 200], true))), "ronda $i: exactamente una debe ganar: " . json_encode($est));
            $this->assertContains(409, $est, "ronda $i: la perdedora recibe 409");
            $this->assertCount(1, array_filter(self::regs($c), fn($r) => $r['estado'] === 'aprobada'));
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizar'));
            $ganador = (int)self::cita($c)['regularizada_por'];
            $this->assertContains($ganador, [self::$e['dueno']['id'], self::$e['gerente']['id']]);
            $this->assertSame($ganador, (int)self::regs($c)[0]['resuelta_por'], 'la cita y la auditoría deben nombrar al MISMO ganador');
        }
    }

    /** Req 5: regularizar vs registrar ausencia, simultáneos: gana una, la otra 409 y no escribe (un solo evento terminal). */
    public function test_C_regularizar_contra_ausencia(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $c = $this->vieja(['dias' => 2 + $i]);
            [, $a] = $this->preparar('dueno', $c, 'regularizar');
            [, $b] = $this->preparar('recepcion', $c, 'estado', ['estado' => 'no_asistio']);
            $res = self::rafaga(self::$base, [$a, $b]);
            $this->sin5xx($res, "ronda $i");
            $final = self::cita($c)['estado'];
            $this->assertContains($final, ['completada', 'no_asistio'], "ronda $i");
            $terminales = array_filter(self::evs($c), fn($v) => in_array($v['tipo'], ['regularizar', 'estado'], true));
            $this->assertCount(1, $terminales, "ronda $i: un solo evento terminal");
            $this->assertContains(409, array_column($res, 'status'), "ronda $i: la perdedora recibe 409; respuestas " . json_encode(array_map(fn($r) => [$r['status'], substr(self::texto($r["body"]), 560, 200)], $res)) . " final $final");
            if ($final === 'completada') { $this->assertNull(self::cita($c)['ausencia_at']); $this->assertCount(1, self::regs($c)); }
            else { $this->assertNull(self::cita($c)['regularizada_por']); $this->assertCount(0, array_filter(self::regs($c), fn($r) => $r['estado'] === 'aprobada')); $this->assertNotNull(self::cita($c)['ausencia_at']); }
        }
    }

    /** Req 5: regularizar vs finalizar (cita en servicio vieja), simultáneos: un solo desenlace coherente. */
    public function test_C_regularizar_contra_finalizar(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $c = $this->vieja(['dias' => 2 + $i, 'estado' => 'en_servicio']);
            [, $a] = $this->preparar('dueno', $c, 'regularizar');
            [, $b] = $this->preparar('recepcion', $c, 'estado', ['estado' => 'completada']);
            $res = self::rafaga(self::$base, [$a, $b]);
            $this->sin5xx($res, "ronda $i");
            $d = self::cita($c);
            $this->assertSame('completada', $d['estado'], "ronda $i");
            $porRegularizar = $d['regularizada_por'] !== null; $porFinalizar = $d['atencion_fin_por'] !== null;
            $this->assertNotSame($porRegularizar, $porFinalizar, "ronda $i: debe haber UN solo camino (regularización XOR finalización): " . json_encode($d));
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => in_array($v['tipo'], ['regularizar', 'estado'], true)), "ronda $i");
        }
    }

    /** Req 5: regularizar vs reprogramar, simultáneos: gana una; la perdedora 409 sin escribir; nunca una cita completada con fecha movida. */
    public function test_C_regularizar_contra_reprogramar(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $c = $this->vieja(['dias' => 2 + $i]);
            $orig = self::cita($c)['fecha'];
            [, $a] = $this->preparar('dueno', $c, 'regularizar');
            [, $b] = $this->preparar('recepcion', $c, 'reprogramar', ['fecha' => self::gye('+' . (3 + $i) . ' days')->format('Y-m-d'), 'hora' => '16:00']);
            $res = self::rafaga(self::$base, [$a, $b]);
            $this->sin5xx($res, "ronda $i");
            $d = self::cita($c);
            if ($d['estado'] === 'completada') { $this->assertSame($orig, $d['fecha'], "ronda $i: cita regularizada con la fecha movida"); }
            else { $this->assertSame('confirmada', $d['estado']); $this->assertNull($d['regularizada_por']); $this->assertNotSame($orig, $d['fecha']); }
            $this->assertTrue(count(array_intersect([409, 422], array_column($res, 'status'))) >= 1, "ronda $i: la perdedora recibe 409/422: " . json_encode(array_column($res, 'status')));
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => in_array($v['tipo'], ['regularizar', 'reprogramar'], true)));
        }
    }

    /** Req 5: doble clic con la MISMA clave desde dos procesos: un solo efecto (una fila, un evento), sin 5xx. */
    public function test_C_doble_clic_misma_clave(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $c = $this->vieja(['dias' => 2 + $i]);
            $n1 = $this->n('dueno'); $n2 = $this->n('dueno');
            $f1 = $this->formDe($n1, $c, 'regularizar'); $f2 = $this->formDe($n2, $c, 'regularizar');
            $k = self::campo($f1, '/idem/i'); $clave = bin2hex(random_bytes(12));
            $cuerpo = [$k => $clave, 'efectiva' => self::efectiva($c), 'motivo' => 'Doble clic con la misma clave de operación'];
            $res = self::rafaga(self::$base, [
                ['cookie' => $n1->cookieSesion(), 'metodo' => 'POST', 'ruta' => $f1['action'], 'body' => array_merge($f1['campos'], $cuerpo)],
                ['cookie' => $n2->cookieSesion(), 'metodo' => 'POST', 'ruta' => $f2['action'], 'body' => array_merge($f2['campos'], $cuerpo)],
            ]);
            $this->sin5xx($res, "ronda $i");
            $this->assertCount(1, self::regs($c), "ronda $i: una fila");
            $this->assertCount(1, array_filter(self::evs($c), fn($v) => $v['tipo'] === 'regularizar'), "ronda $i: un evento");
            $this->assertSame('completada', self::cita($c)['estado']);
        }
    }

    /** Req 4/5: solicitud del profesional vs regularización directa simultáneas: la cita queda completada y NO queda una solicitud «solicitada» huérfana; o se rechaza la solicitud tardía. */
    public function test_C_solicitud_contra_regularizacion_directa(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $c = $this->vieja(['dias' => 2 + $i]);
            [, $a] = $this->preparar('dueno', $c, 'regularizar');
            [, $b] = $this->preparar('proA', $c, 'solicitar', ['efectiva' => self::efectiva($c, '10:40'), 'motivo' => 'Solicitud simultánea del profesional']);
            $res = self::rafaga(self::$base, [$a, $b]);
            $this->sin5xx($res, "ronda $i");
            $this->assertSame('completada', self::cita($c)['estado'], "ronda $i: la regularización directa siempre procede; respuestas " . json_encode(array_map(fn($r) => [$r['status'], substr(self::texto($r["body"]), 560, 200)], $res)));
            $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='solicitada'", [$c]), "ronda $i: solicitud huérfana abierta sobre una cita ya completada");
            $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='aprobada'", [$c]));
        }
    }
}
