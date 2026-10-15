<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * DECISIÓN DEL USUARIO sobre citas de días anteriores (docs/R5_REGULARIZACION.md): no hay inicio ordinario; se reprograma o la regulariza el
 * propietario/gerente. Estas pruebas (del integrador, NO independientes) verifican por HTTP real que ni el rechazo del inicio, ni la
 * regularización, ni el cobro posterior cambian la fecha original de la cita ni retrodatan atención, pagos, caja o comprobantes, y que se
 * conservan por separado el actor, la hora real del registro y la fecha efectiva del servicio.
 */
final class R5IntInicioTardioTest extends R5Case
{
    private static string $base;
    private static array $e;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorQ5('tardio');
        self::$e = self::escenario();
    }

    public static function tearDownAfterClass(): void { self::pararServidorQ5('tardio'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private static function enSegundos(string $datetime): int { return (int)abs(time() - strtotime($datetime . ' ' . self::TZ_NEGOCIO)); }
    private const TZ_NEGOCIO = 'America/Guayaquil';

    private function regularizar(Navegador $n, int $cita, string $efectiva, string $motivo = 'El servicio se prestó y no se registró en su momento'): array
    {
        $pag = $n->get("/agenda/cita?id=$cita");
        $this->assertSame(200, $pag['status']);
        return $n->post('/agenda/regularizar', ['csrf_token' => Navegador::csrfDe($pag['body']), 'idem_key' => bin2hex(random_bytes(16)), 'id' => $cita, 'efectiva' => $efectiva, 'motivo' => $motivo,
            'volver' => 'agenda_cita', 'r_id' => $cita]);
    }

    public function test_iniciar_tarde_se_rechaza_y_regularizar_mas_cobrar_no_retrodata_ni_cambia_la_cita(): void
    {
        $n = self::entrar(self::$base, self::$e['dueno']);
        $fecha = self::gye('-3 days')->format('Y-m-d');
        $cita = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $fecha, 'hora' => '10:00:00']);
        $antes = self::q('SELECT fecha,hora,hora_inicio,hora_fin FROM citas WHERE id=?', [$cita])[0];

        // 1) El inicio ordinario de una cita de un día anterior se rechaza y no escribe nada.
        $pag = $n->get("/agenda?desde=$fecha&hasta=$fecha");
        $csrf = Navegador::csrfDe($pag['body']);
        $r = $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $cita, 'estado' => 'en_servicio', 'volver' => 'agenda', 'r_desde' => $fecha, 'r_hasta' => $fecha]);
        $this->assertSame(422, $r['status']);
        $this->assertSame('confirmada', self::q('SELECT estado FROM citas WHERE id=?', [$cita])[0]['estado']);
        $this->assertCount(0, self::q('SELECT id FROM agenda_eventos WHERE citaId=?', [$cita]));

        // 2) Regularizar (propietario): la cita queda atendida SIN tocar su fecha; quién/cuándo se registra aparte de cuándo ocurrió el servicio.
        $efectiva = self::gye('-2 days')->format('Y-m-d') . 'T15:30';
        $r = $this->regularizar($n, $cita, $efectiva);
        $this->assertSame(302, $r['status'], substr(self::texto($r['body'] ?? ''), 0, 200));
        $c = self::q('SELECT * FROM citas WHERE id=?', [$cita])[0];
        $this->assertSame('completada', $c['estado']);
        $this->assertSame($antes, ['fecha' => $c['fecha'], 'hora' => $c['hora'], 'hora_inicio' => $c['hora_inicio'], 'hora_fin' => $c['hora_fin']], 'la fecha y hora originales de la cita no cambian');
        $this->assertSame(str_replace('T', ' ', $efectiva) . ':00', $c['atencion_efectiva_at']);
        $this->assertSame((int)self::$e['dueno']['id'], (int)$c['regularizada_por']);
        $this->assertLessThan(120, self::enSegundos((string)$c['regularizada_at']), 'regularizada_at es la hora REAL del registro');
        $this->assertNull($c['atencion_inicio_at'], 'no se inventa un inicio');
        $this->assertNull($c['atencion_fin_at']);
        $ev = self::q('SELECT * FROM agenda_eventos WHERE citaId=? ORDER BY id', [$cita]);
        $this->assertCount(1, $ev);
        $this->assertSame('regularizar', $ev[0]['tipo']);
        $this->assertSame((int)self::$e['dueno']['id'], (int)$ev[0]['actorId']);
        $this->assertLessThan(120, self::enSegundos((string)$ev[0]['creado_en']));

        // 3) Cobrar después: el pago, la caja y el comprobante llevan la hora real, no la de la cita ni la efectiva.
        $pant = $n->get("/caja/cita?id=$cita");
        $this->assertSame(200, $pant['status']);
        $form = self::formulario($pant['body'], '~/caja/cita/cobrar~');
        $this->assertNotNull($form);
        $r = self::enviar($n, $form, ['monto' => '20.00', 'recibido' => '20.00', 'metodo' => 'efectivo']);
        $this->assertContains($r['status'], [200, 302, 303], substr(self::texto($r['body']), 0, 200));
        $pago = self::q('SELECT * FROM pagos WHERE cita_id=? ORDER BY id DESC LIMIT 1', [$cita])[0];
        $this->assertLessThan(120, self::enSegundos((string)$pago['pagado_at']), 'pagado_at es la hora real del cobro');
        $this->assertStringStartsNotWith($fecha, (string)$pago['pagado_at']);
        $caja = self::q('SELECT * FROM caja_movimientos WHERE pago_id=?', [(int)$pago['id']])[0];
        $this->assertLessThan(120, self::enSegundos((string)$caja['registrado_en']), 'la caja registra el momento real');
        $this->assertSame((int)self::$e['dueno']['id'], (int)$caja['actor_id']);
        $cmp = self::q('SELECT * FROM comprobantes WHERE cita_id=? ORDER BY id DESC LIMIT 1', [$cita])[0];
        $this->assertLessThan(120, self::enSegundos((string)$cmp['emitido_en']), 'el comprobante se emite con la hora real');
        $this->assertSame((int)self::$e['dueno']['id'], (int)$cmp['emitido_por']);
        $this->assertSame($fecha, self::q('SELECT fecha FROM citas WHERE id=?', [$cita])[0]['fecha'], 'la fecha original de la cita sigue intacta tras cobrar');
    }

    public function test_ausencia_tarde_y_fin_por_otro_actor_conservan_actor_hora_real_y_fecha_original(): void
    {
        $fecha = self::gye('-400 days')->format('Y-m-d');
        $n = self::entrar(self::$base, self::$e['dueno']);
        $g = self::entrar(self::$base, self::$e['gerente']);
        $pro = self::$e['proA']['profesional_id'];

        // Cita muy antigua: la ausencia (acción ordinaria) sigue disponible y registra SU fecha real.
        $a = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => $pro, 'fecha' => $fecha, 'hora' => '09:00:00']);
        $csrf = Navegador::csrfDe($n->get("/agenda?desde=$fecha&hasta=$fecha")['body']);
        $r = $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $a, 'estado' => 'no_asistio', 'volver' => 'agenda', 'r_desde' => $fecha, 'r_hasta' => $fecha]);
        $this->assertSame(302, $r['status']);
        $c = self::q('SELECT * FROM citas WHERE id=?', [$a])[0];
        $this->assertSame('no_asistio', $c['estado']);
        $this->assertSame($fecha, $c['fecha'], 'la fecha original no cambia');
        $this->assertSame((int)self::$e['dueno']['id'], (int)$c['ausencia_por']);
        $this->assertLessThan(120, self::enSegundos((string)$c['ausencia_at']));
        $this->assertStringStartsNotWith($fecha, (string)$c['ausencia_at']);

        // Una cita que quedó «en servicio» hace días: la inició el propietario entonces y la finaliza otra persona hoy (hora real, sin retrodatar).
        $b = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => $pro, 'fecha' => $fecha, 'hora' => '11:00:00', 'estado' => 'en_servicio']);
        self::q('UPDATE citas SET atencion_inicio_at=?, atencion_inicio_por=? WHERE id=?', [$fecha . ' 11:05:00', (int)self::$e['dueno']['id'], $b]);
        $csrfG = Navegador::csrfDe($g->get("/agenda?desde=$fecha&hasta=$fecha")['body']);
        $this->assertSame(302, $g->post('/agenda/estado', ['csrf_token' => $csrfG, 'id' => $b, 'estado' => 'completada', 'volver' => 'agenda', 'r_desde' => $fecha, 'r_hasta' => $fecha])['status']);
        $c = self::q('SELECT * FROM citas WHERE id=?', [$b])[0];
        $this->assertSame($fecha . ' 11:05:00', $c['atencion_inicio_at'], 'el inicio real de entonces no se toca');
        $this->assertSame((int)self::$e['dueno']['id'], (int)$c['atencion_inicio_por']);
        $this->assertSame((int)self::$e['gerente']['id'], (int)$c['atencion_fin_por'], 'quien finaliza es otra persona y queda registrada');
        $this->assertLessThan(120, self::enSegundos((string)$c['atencion_fin_at']), 'la finalización lleva la hora real de hoy');
        $this->assertSame($fecha, $c['fecha']);
        $ev = self::q('SELECT * FROM agenda_eventos WHERE citaId=? ORDER BY id', [$b]);
        $this->assertCount(1, $ev);
        $this->assertSame((int)self::$e['gerente']['id'], (int)$ev[0]['actorId']);
        $this->assertStringStartsNotWith($fecha, (string)$ev[0]['creado_en'], 'el evento no se retrodata');
    }
}
