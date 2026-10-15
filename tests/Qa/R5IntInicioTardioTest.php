<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * Ronda 5 — DECISIÓN PENDIENTE DEL USUARIO: iniciar tarde la atención de una cita de días anteriores.
 * Mientras se permita, debe cumplirse que iniciar/finalizar/cobrar tarde NO cambia la fecha original de la cita, NO retrodata la atención ni
 * los pagos, y conserva el actor y la hora real. Escrita por el integrador (no es revisión independiente).
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

    public function test_iniciar_finalizar_y_cobrar_tarde_no_retrodata_ni_cambia_la_cita_y_conserva_actor_y_hora_real(): void
    {
        $n = self::entrar(self::$base, self::$e['dueno']);
        $fecha = self::gye('-3 days')->format('Y-m-d');
        $cita = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $fecha, 'hora' => '10:00:00']);
        $antes = self::q('SELECT fecha,hora,hora_inicio,hora_fin FROM citas WHERE id=?', [$cita])[0];

        // 1) Iniciar tarde (formulario real de la agenda del período)
        $pag = $n->get("/agenda?desde=$fecha&hasta=$fecha");
        $this->assertSame(200, $pag['status']);
        $csrf = Navegador::csrfDe($pag['body']);
        $r = $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $cita, 'estado' => 'en_servicio', 'volver' => 'agenda', 'r_desde' => $fecha, 'r_hasta' => $fecha]);
        $this->assertSame(302, $r['status']);
        $this->assertSame("/agenda?desde=$fecha&hasta=$fecha", $r['location']);
        $c = self::q('SELECT * FROM citas WHERE id=?', [$cita])[0];
        $this->assertSame('en_servicio', $c['estado']);
        $this->assertSame($antes, ['fecha' => $c['fecha'], 'hora' => $c['hora'], 'hora_inicio' => $c['hora_inicio'], 'hora_fin' => $c['hora_fin']], 'la fecha y hora originales de la cita no cambian');
        $this->assertSame((int)self::$e['dueno']['id'], (int)$c['atencion_inicio_por'], 'actor real');
        $this->assertLessThan(120, self::enSegundos((string)$c['atencion_inicio_at']), 'la hora de inicio es la REAL (ahora), no la de la cita: ' . $c['atencion_inicio_at']);
        $this->assertStringStartsNotWith($fecha, (string)$c['atencion_inicio_at'], 'no se retrodata al día de la cita');
        $ev = self::q('SELECT * FROM agenda_eventos WHERE citaId=? ORDER BY id', [$cita]);
        $this->assertCount(1, $ev);
        $this->assertSame((int)self::$e['dueno']['id'], (int)$ev[0]['actorId']);
        $this->assertLessThan(120, self::enSegundos((string)$ev[0]['creado_en']), 'el evento lleva la hora real');

        // 2) Finalizar
        $r = $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $cita, 'estado' => 'completada', 'volver' => 'agenda', 'r_desde' => $fecha, 'r_hasta' => $fecha]);
        $this->assertSame(302, $r['status']);
        $c = self::q('SELECT * FROM citas WHERE id=?', [$cita])[0];
        $this->assertSame('completada', $c['estado']);
        $this->assertSame($fecha, $c['fecha']);
        $this->assertLessThan(120, self::enSegundos((string)$c['atencion_fin_at']));
        $this->assertSame('pendiente', $c['estado_pago'], 'finalizar no marca pagado');

        // 3) Cobrar tarde: el pago, la caja y el comprobante llevan la hora real
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

    /** Ausencia registrada tarde, finalización por otro actor y fecha original conservada en la bitácora. */
    public function test_ausencia_tarde_y_fin_por_otro_actor_conservan_actor_hora_real_y_fecha_original(): void
    {
        $fecha = self::gye('-400 days')->format('Y-m-d');
        $n = self::entrar(self::$base, self::$e['dueno']);
        $g = self::entrar(self::$base, self::$e['gerente']);
        $pro = self::$e['proA']['profesional_id'];

        // Cita muy antigua: ausencia tardía
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

        // Inicia el propietario, finaliza otra persona (gerente): cada campo conserva su actor
        $b = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => $pro, 'fecha' => $fecha, 'hora' => '11:00:00']);
        $this->assertSame(302, $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $b, 'estado' => 'en_servicio', 'volver' => 'agenda', 'r_desde' => $fecha, 'r_hasta' => $fecha])['status']);
        $csrfG = Navegador::csrfDe($g->get("/agenda?desde=$fecha&hasta=$fecha")['body']);
        $this->assertSame(302, $g->post('/agenda/estado', ['csrf_token' => $csrfG, 'id' => $b, 'estado' => 'completada', 'volver' => 'agenda', 'r_desde' => $fecha, 'r_hasta' => $fecha])['status']);
        $c = self::q('SELECT * FROM citas WHERE id=?', [$b])[0];
        $this->assertSame((int)self::$e['dueno']['id'], (int)$c['atencion_inicio_por']);
        $this->assertSame((int)self::$e['gerente']['id'], (int)$c['atencion_fin_por'], 'quien finaliza es otra persona y queda registrada');
        $this->assertSame($fecha, $c['fecha']);
        $ev = self::q('SELECT * FROM agenda_eventos WHERE citaId=? ORDER BY id', [$b]);
        $this->assertCount(2, $ev);
        $this->assertSame((int)self::$e['gerente']['id'], (int)$ev[1]['actorId']);
        foreach ($ev as $e) { $this->assertStringStartsNotWith($fecha, (string)$e['creado_en'], 'el evento no se retrodata'); }
        $this->assertStringContainsString($fecha, (string)$ev[0]['antes_json'] . (string)$ev[0]['despues_json'], 'la bitácora conserva la fecha original de la cita');
    }
}
