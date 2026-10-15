<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5DevAgendaCase.php';

/**
 * Ronda 5 · agente a5 — agenda → atención → finalización, contra HTTP real (servidor embebido en 8130-8139).
 *
 * Reproduce y fija como regresión los fallos A y B observados por el usuario (ver tests/Qa/R5DiagnosticoTest.php, que es del líder y
 * documenta el comportamiento previo), y comprueba la máquina de estados, los permisos por rol, el detalle de la cita y el gancho al cobro.
 *
 * Las pruebas marcadas «contrato n5» dependen de que classes/ErrorResponse.php + views/errores/layout.php (agente n5) pinten el «Volver»
 * recibido en `responder_error_operacion()`; el resto es independiente de ese cambio.
 */
final class R5DevAgendaTest extends R5DevAgendaCase
{
    private static string $base;
    private static array $dueno;
    private static array $gerente;
    private static array $recepcion;
    private static array $finanzas;
    private static array $proA;
    private static array $proB;
    private static array $cliente;
    private static int $svc;
    private static int $profLibre;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorA5();
        self::$dueno = self::propietario(self::correo('dueno'));
        self::$gerente = self::miembro(self::$dueno['id'], 'gerente');
        self::$recepcion = self::miembro(self::$dueno['id'], 'recepcion');
        self::$finanzas = self::miembro(self::$dueno['id'], 'finanzas');
        self::$proA = self::miembro(self::$dueno['id'], 'profesional');
        self::$proB = self::miembro(self::$dueno['id'], 'profesional');
        self::$cliente = self::cliente(['email' => self::correo('clienta'), 'nombre' => 'Clara', 'apellido' => 'Cliente']);
        self::$svc = self::servicio('Corte R5', 20.0, 30);
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1)', ['Pro sin cuenta']);
        self::$profLibre = (int)self::db()->insert_id;
    }

    public static function tearDownAfterClass(): void { self::pararServidorA5(); }

    private function cita(string $fecha, string $hora, string $estado = 'confirmada', ?int $profesional = null, string $pago = 'pendiente'): int
    {
        return self::crearCita(self::$cliente['id'], $profesional ?? self::$proA['profesional_id'], self::$svc, $fecha, $hora, $estado, '20.00', $pago);
    }

    /** Cita de hoy cuyo inicio fue hace unos minutos (ya comenzada) sin cruzar la medianoche. */
    private function citaYaComenzada(string $estado = 'confirmada', ?int $profesional = null, string $pago = 'pendiente'): int
    {
        $ahora = self::ahoraNegocio();
        $inicio = $ahora->modify('-10 minutes');
        if ($inicio->format('Y-m-d') !== $ahora->format('Y-m-d')) { $inicio = $ahora->setTime(0, 0); }
        return $this->cita($ahora->format('Y-m-d'), $inicio->format('H:i:s'), $estado, $profesional, $pago);
    }

    private function dueno(): Navegador { return self::entrar(self::$base, 'equipo', self::$dueno); }

    /** Formulario con acción /agenda/estado de una cita y un estado destino dentro del HTML de la agenda (null si no se ofrece). */
    private static function formulario(string $html, int $cita, string $estado): ?string
    {
        preg_match_all('~<form method="POST" action="/agenda/estado".*?</form>~s', $html, $m);
        foreach ($m[0] as $f) {
            if (str_contains($f, 'name="id" value="' . $cita . '"') && str_contains($f, 'name="estado" value="' . $estado . '"')) { return $f; }
        }
        return null;
    }

    /** Envía el formulario tal cual lo renderiza la agenda (todos sus campos ocultos, incluido el retorno). */
    private function enviarFormulario(Navegador $n, string $form): array
    {
        preg_match_all('~<input type="hidden" name="([^"]+)" value="([^"]*)"~', $form, $c, PREG_SET_ORDER);
        $campos = [];
        foreach ($c as $x) { $campos[html_entity_decode($x[1])] = html_entity_decode($x[2]); }
        return $n->post('/agenda/estado', $campos);
    }

    // ------------------------------------------------------------------ A: «Iniciar atención»

    public function test_A_cita_ya_comenzada_inicia_vuelve_a_la_agenda_con_el_periodo_y_confirma_con_un_mensaje(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada();
        $desde = self::diaNegocio(-2); $hasta = self::diaNegocio(2);
        $pag = $n->get("/agenda?desde=$desde&hasta=$hasta");
        $this->assertSame(200, $pag['status']);
        $form = self::formulario($pag['body'], $c, 'en_servicio');
        $this->assertNotNull($form, 'una cita ya comenzada ofrece «Iniciar atención»');
        $r = $this->enviarFormulario($n, $form);
        $this->assertSame(302, $r['status']);
        // El período consultado se conserva (antes de la ronda 5 volvía a «/agenda» a secas).
        $this->assertSame("/agenda?desde=$desde&hasta=$hasta", $r['location']);
        $this->assertSame('en_servicio', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        // Pantalla final: la agenda del mismo período, con el resultado y la siguiente acción.
        $fin = $n->get($r['location']);
        $this->assertSame(200, $fin['status']);
        $this->assertStringContainsString('Atención iniciada.', $fin['body']);
        $this->assertNotNull(self::formulario($fin['body'], $c, 'completada'), 'ahora se ofrece «Finalizar atención»');
        $this->assertNull(self::formulario($fin['body'], $c, 'en_servicio'));
        $this->assertStringContainsString('value="' . $desde . '"', $fin['body']);
        // Trazabilidad: actor, hora del negocio, estado anterior y nuevo.
        $fila = self::q('SELECT atencion_inicio_at, atencion_inicio_por FROM citas WHERE id=?', [$c])[0];
        $this->assertSame(self::$dueno['id'], (int)$fila['atencion_inicio_por']);
        $this->assertEqualsWithDelta(self::ahoraNegocio()->getTimestamp(), (new \DateTimeImmutable($fila['atencion_inicio_at'], new \DateTimeZone('America/Guayaquil')))->getTimestamp(), 30);
        $ev = self::q("SELECT actorId, antes_json, despues_json FROM agenda_eventos WHERE citaId=? AND tipo='estado'", [$c]);
        $this->assertCount(1, $ev);
        $this->assertSame(self::$dueno['id'], (int)$ev[0]['actorId']);
        $this->assertSame('confirmada', json_decode($ev[0]['antes_json'], true)['estado']);
        $this->assertSame('en_servicio', json_decode($ev[0]['despues_json'], true)['estado']);
    }

    public function test_A_dentro_del_margen_de_60_minutos_se_ofrece_y_se_acepta(): void
    {
        $inicio = self::ahoraNegocio()->modify('+45 minutes');
        $c = $this->cita($inicio->format('Y-m-d'), $inicio->format('H:i:s'));
        $n = $this->dueno();
        $pag = $n->get('/agenda?desde=' . $inicio->format('Y-m-d') . '&hasta=' . $inicio->format('Y-m-d'));
        $form = self::formulario($pag['body'], $c, 'en_servicio');
        $this->assertNotNull($form, 'a 45 minutos de la hora ya se puede iniciar');
        $this->assertSame(302, $this->enviarFormulario($n, $form)['status']);
        $this->assertSame('en_servicio', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
    }

    public function test_A_fuera_del_margen_el_boton_esta_deshabilitado_con_el_motivo_y_sin_formulario(): void
    {
        $n = $this->dueno();
        $c = $this->cita(self::diaNegocio(1), '15:00:00');
        $pag = $n->get('/agenda?desde=' . self::diaNegocio(0) . '&hasta=' . self::diaNegocio(1));
        $this->assertSame(200, $pag['status']);
        $this->assertNull(self::formulario($pag['body'], $c, 'en_servicio'), 'no se ofrece un envío que el servidor rechazará');
        $this->assertNull(self::formulario($pag['body'], $c, 'no_asistio'), 'tampoco la ausencia antes de la hora');
        $this->assertMatchesRegularExpression('~<button class="boton" type="button" disabled aria-describedby="agenda-motivo-' . $c . '-iniciar">Iniciar atención</button>~', $pag['body']);
        $this->assertMatchesRegularExpression('~id="agenda-motivo-' . $c . '-iniciar">Disponible desde las 14:00</~', $pag['body']);
        $this->assertMatchesRegularExpression('~id="agenda-motivo-' . $c . '-ausencia">Disponible desde las 15:00</~', $pag['body']);
    }

    public function test_A_B_post_forjado_de_iniciar_en_cita_futura_responde_422_dentro_del_layout_sin_enlaces_al_portal_cliente(): void
    {
        $n = $this->dueno();
        $c = $this->cita(self::diaNegocio(1), '15:00:00');
        $antes = self::eventos($c);
        $r = self::postEstado($n, $c, 'en_servicio', ['volver' => 'agenda', 'r_desde' => self::diaNegocio(0), 'r_hasta' => self::diaNegocio(1)]);
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('text/html', $r['headers']['content-type'][0] ?? '');
        $this->assertStringContainsString('<html', strtolower($r['body']), 'la respuesta es una página completa, no HTML suelto');
        $this->assertStringContainsString('ui-error-card', $r['body']);   // tarjeta de error dentro del layout del equipo
        $this->assertStringContainsString('No se pudo actualizar la cita.', $r['body']);
        $this->assertStringContainsString('disponible desde las 14:00', $r['body']);
        foreach (self::enlaces($r['body']) as $href) {
            $this->assertStringNotContainsString('/mis-citas', $href, 'una pantalla del equipo nunca enlaza al portal de clientes');
        }
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        $this->assertSame($antes, self::eventos($c), 'un rechazo no deja evento');
    }

    /** contrato n5: el «Volver» de la página de error lleva a la agenda del mismo período. */
    public function test_A_B_pagina_de_error_ofrece_volver_a_la_agenda_conservando_el_periodo(): void
    {
        $n = $this->dueno();
        $c = $this->cita(self::diaNegocio(1), '16:00:00');
        $desde = self::diaNegocio(0); $hasta = self::diaNegocio(1);
        $r = self::postEstado($n, $c, 'en_servicio', ['volver' => 'agenda', 'r_desde' => $desde, 'r_hasta' => $hasta]);
        $this->assertSame(422, $r['status']);
        $this->assertContains("/agenda?desde=$desde&hasta=$hasta", self::enlaces($r['body']), 'Volver a la agenda con el período');
        $this->assertStringContainsString('Volver a la agenda', $r['body']);
        foreach (self::enlaces($r['body']) as $href) { $this->assertStringNotContainsString('/mis-citas', $href); }
    }

    public function test_cita_de_un_dia_anterior_sin_iniciar_se_puede_registrar_con_retraso_y_tambien_la_ausencia(): void
    {
        $n = $this->dueno();
        $c = $this->cita(self::diaNegocio(-1), '10:00:00');
        $d = $this->cita(self::diaNegocio(-1), '11:00:00');
        $pag = $n->get('/agenda?desde=' . self::diaNegocio(-1) . '&hasta=' . self::diaNegocio(0));
        $this->assertNotNull(self::formulario($pag['body'], $c, 'en_servicio'), 'sin cierre de ventana: se puede iniciar con retraso');
        $this->assertNotNull($ausencia = self::formulario($pag['body'], $d, 'no_asistio'), 'una cita pasada también admite registrar la ausencia');
        $r = self::postEstado($n, $c, 'en_servicio');
        $this->assertSame(302, $r['status']);
        $this->assertSame('en_servicio', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        $this->assertSame(302, $this->enviarFormulario($n, $ausencia)['status']);
        $this->assertSame('no_asistio', self::escalar('SELECT estado FROM citas WHERE id=?', [$d]));
    }

    // ------------------------------------------------------------------ finalización y estados terminales

    public function test_cita_completada_muestra_atendida_quien_finalizo_y_el_pago_y_es_inmutable(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada();
        $this->assertSame(302, self::postEstado($n, $c, 'en_servicio')['status']);
        $this->assertSame(302, self::postEstado($n, $c, 'completada')['status']);
        $pag = $n->get('/agenda?desde=' . self::diaNegocio(0) . '&hasta=' . self::diaNegocio(0));
        $card = $this->tarjeta($pag['body'], $c);
        $this->assertStringContainsString('Atendida', $card);
        $this->assertStringContainsString('Finalizó: Due Ño', $card);
        $this->assertStringContainsString('Pago Pendiente', $card);
        $this->assertNull(self::formulario($pag['body'], $c, 'en_servicio'));
        $this->assertNull(self::formulario($pag['body'], $c, 'completada'));
        $this->assertNull(self::formulario($pag['body'], $c, 'no_asistio'));
        // Terminales: un POST forjado no la reabre ni la cambia (409 con el estado vigente), y no deja evento.
        $eventos = self::eventos($c);
        foreach (['en_servicio', 'no_asistio'] as $destino) {
            $r = self::postEstado($n, $c, $destino);
            $this->assertSame(409, $r['status'], $destino);
            $this->assertStringContainsString('atendida', mb_strtolower($r['body']));
        }
        $this->assertSame('completada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        $this->assertSame($eventos, self::eventos($c));
        // Finalizar nunca marca «pagado».
        $this->assertSame('pendiente', self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$c]));
    }

    public function test_doble_clic_en_finalizar_hace_una_sola_transicion_y_un_solo_evento(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada('en_servicio');
        self::q('UPDATE citas SET atencion_inicio_at=NULL WHERE id=?', [$c]);
        $pag = $n->get('/agenda');
        $form = self::formulario($pag['body'], $c, 'completada');
        $this->assertNotNull($form);
        $r1 = $this->enviarFormulario($n, $form);
        $fin1 = self::escalar('SELECT atencion_fin_at FROM citas WHERE id=?', [$c]);
        usleep(1_200_000); // un segundo completo: si hubiera una segunda escritura, la hora cambiaría
        $r2 = $this->enviarFormulario($n, $form);
        $this->assertSame(302, $r1['status']);
        $this->assertSame(302, $r2['status'], 'el segundo clic no es un error: la acción ya estaba hecha');
        $this->assertSame(1, self::eventos($c));
        $this->assertSame($fin1, self::escalar('SELECT atencion_fin_at FROM citas WHERE id=?', [$c]));
        $this->assertStringContainsString('Estado ya actualizado.', $n->get($r2['location'])['body']);
    }

    public function test_finalizar_y_cobrar_finaliza_y_continua_a_caja_sin_marcar_pagado(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada('en_servicio');
        $desde = self::diaNegocio(0); $hasta = self::diaNegocio(1);
        $pag = $n->get("/agenda?desde=$desde&hasta=$hasta");
        $form = self::formulario($pag['body'], $c, 'completada');
        $formCobrar = null;
        preg_match_all('~<form method="POST" action="/agenda/estado".*?</form>~s', $pag['body'], $m);
        foreach ($m[0] as $f) { if (str_contains($f, 'name="id" value="' . $c . '"') && str_contains($f, 'name="cobrar" value="1"')) { $formCobrar = $f; } }
        $this->assertNotNull($formCobrar, 'el propietario ve «Finalizar y cobrar»');
        $this->assertStringContainsString('Finalizar y cobrar', $pag['body']);
        $r = $this->enviarFormulario($n, $formCobrar);
        $this->assertSame(302, $r['status']);
        $this->assertSame("/caja/cita?id=$c&desde=$desde&hasta=$hasta", $r['location']);
        $this->assertSame('completada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        $this->assertSame('pendiente', self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$c]));
        // Reintento (cobro fallido y vuelta atrás): la cita sigue atendida y el reintento no duplica la transición.
        $r2 = $this->enviarFormulario($n, $formCobrar);
        $this->assertSame($r['location'], $r2['location']);
        $this->assertSame(1, self::eventos($c));
        // La tarjeta de una cita atendida con saldo pendiente ofrece cobrar, no «Finalizar y cobrar».
        $pag2 = $n->get("/agenda?desde=$desde&hasta=$hasta");
        $this->assertStringContainsString('href="/caja/cita?id=' . $c . '&amp;desde=' . $desde . '&amp;hasta=' . $hasta . '"', $pag2['body']);
        $this->assertStringContainsString('Cobrar saldo', $this->tarjeta($pag2['body'], $c));
        $this->assertStringNotContainsString('Finalizar y cobrar', $this->tarjeta($pag2['body'], $c));
    }

    public function test_finalizar_y_cobrar_solo_lo_ofrece_quien_tiene_agenda_y_finanzas(): void
    {
        $gerente = self::entrar(self::$base, 'equipo', self::$gerente);
        $recepcion = self::entrar(self::$base, 'equipo', self::$recepcion);
        $c = $this->citaYaComenzada('en_servicio');
        $this->assertStringContainsString('Finalizar y cobrar', $gerente->get('/agenda')['body']);
        $rec = $recepcion->get('/agenda')['body'];
        $this->assertStringNotContainsString('Finalizar y cobrar', $rec);
        $this->assertNotNull(self::formulario($rec, $c, 'completada'));
        // Un `cobrar=1` forjado por quien no tiene finanzas finaliza, pero no redirige a caja.
        $r = self::postEstado($recepcion, $c, 'completada', ['cobrar' => '1']);
        $this->assertSame(302, $r['status']);
        $this->assertSame('/agenda', $r['location']);
    }

    public function test_registrar_ausencia_pide_confirmacion_accesible_y_se_registra_con_quien_y_cuando(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada();
        $pag = $n->get('/agenda');
        $form = self::formulario($pag['body'], $c, 'no_asistio');
        $this->assertNotNull($form);
        $this->assertStringContainsString('data-confirmar-titulo="¿Registrar la ausencia de Clara Cliente?"', $form);
        $this->assertStringContainsString('no se puede deshacer', $form);
        $this->assertStringContainsString('src="/build/js/agenda-atencion.js"', $pag['body']);
        $this->assertSame(302, $this->enviarFormulario($n, $form)['status']);
        $fila = self::q('SELECT estado, ausencia_at, ausencia_por, atencion_inicio_at FROM citas WHERE id=?', [$c])[0];
        $this->assertSame('no_asistio', $fila['estado']);
        $this->assertSame(self::$dueno['id'], (int)$fila['ausencia_por']);
        $this->assertNotNull($fila['ausencia_at']);
        $this->assertNull($fila['atencion_inicio_at']);
        $this->assertStringContainsString('Ausencia registrada por: Due Ño', $this->tarjeta($n->get('/agenda')['body'], $c));
        // Es terminal: ya no puede iniciarse ni finalizarse.
        $this->assertSame(409, self::postEstado($n, $c, 'en_servicio')['status']);
        $this->assertSame(409, self::postEstado($n, $c, 'completada')['status']);
        $this->assertSame('no_asistio', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
    }

    public function test_no_se_puede_finalizar_una_cita_sin_haberla_iniciado(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada();
        $r = self::postEstado($n, $c, 'completada');
        $this->assertSame(409, $r['status']);
        $this->assertStringContainsString('primero hay que iniciar la atención', $r['body']);
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
    }

    // ------------------------------------------------------------------ permisos por rol

    public function test_profesional_opera_solo_su_propia_agenda(): void
    {
        $propia = $this->citaYaComenzada('confirmada', self::$proA['profesional_id']);
        $ajena = $this->citaYaComenzada('confirmada', self::$proB['profesional_id']);
        $a = self::entrar(self::$base, 'equipo', self::$proA);
        $pag = $a->get('/agenda');
        $this->assertSame(200, $pag['status']);
        $this->assertNotNull(self::formulario($pag['body'], $propia, 'en_servicio'));
        $this->assertStringNotContainsString('cita-' . $ajena . '"', $pag['body'], 'la agenda del profesional no incluye citas ajenas');
        $this->assertStringNotContainsString('Finalizar y cobrar', $pag['body']);
        $this->assertStringNotContainsString('Pago ', $pag['body'], 'sin finanzas no se muestran datos de pago');
        // POST forjado sobre la cita de otro profesional: prohibido y sin cambios.
        $r = self::postEstado($a, $ajena, 'en_servicio');
        $this->assertSame(403, $r['status']);
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$ajena]));
        $this->assertSame(0, self::eventos($ajena));
        // Sobre la propia, sí.
        $this->assertSame(302, self::postEstado($a, $propia, 'en_servicio')['status']);
        $this->assertSame('en_servicio', self::escalar('SELECT estado FROM citas WHERE id=?', [$propia]));
        $this->assertSame(self::$proA['id'], (int)self::escalar('SELECT atencion_inicio_por FROM citas WHERE id=?', [$propia]));
        // Un profesional no puede reprogramar desde la agenda (es gestión).
        $this->assertStringNotContainsString('Reprogramar visita', $pag['body']);
    }

    public function test_recepcion_y_gerente_gestionan_cualquier_cita_finanzas_sin_agenda_no(): void
    {
        $c = $this->citaYaComenzada('confirmada', self::$proB['profesional_id']);
        $c2 = $this->citaYaComenzada('confirmada', self::$proB['profesional_id']);
        $this->assertSame(302, self::postEstado(self::entrar(self::$base, 'equipo', self::$recepcion), $c, 'en_servicio')['status']);
        $this->assertSame(302, self::postEstado(self::entrar(self::$base, 'equipo', self::$gerente), $c2, 'en_servicio')['status']);
        $fin = self::entrar(self::$base, 'equipo', self::$finanzas);
        $c3 = $this->citaYaComenzada();
        $this->assertSame(403, $fin->get('/agenda')['status']);
        $r = self::postEstado($fin, $c3, 'en_servicio');
        $this->assertSame(403, $r['status']);
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c3]));
        $this->assertSame(0, self::eventos($c3));
    }

    public function test_el_cliente_no_cambia_estados_de_atencion(): void
    {
        $c = $this->citaYaComenzada();
        $cli = self::entrar(self::$base, 'cliente', self::$cliente);
        $csrf = $cli->csrf('/perfil');
        $r = $cli->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $c, 'estado' => 'en_servicio']);
        $this->assertSame(403, $r['status']);
        foreach (self::enlaces($r['body']) as $href) { $this->assertStringNotContainsString('/agenda', $href, 'el cliente no es enviado a pantallas del equipo'); }
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        $this->assertSame(0, self::eventos($c));
        $this->assertSame(403, $cli->get('/agenda')['status']);
        $this->assertSame(403, $cli->get("/agenda/cita?id=$c")['status']);
    }

    public function test_sesion_de_cliente_de_una_persona_con_doble_membresia_no_opera_la_agenda(): void
    {
        self::conMembresiaCliente(self::$proA['id']);
        $propia = $this->citaYaComenzada('confirmada', self::$proA['profesional_id']);
        $comoCliente = self::entrar(self::$base, 'cliente', self::$proA);
        $csrf = $comoCliente->csrf('/perfil');
        $r = $comoCliente->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $propia, 'estado' => 'en_servicio']);
        $this->assertSame(403, $r['status'], 'el portal del cliente no porta privilegios laborales aunque la persona sea profesional');
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$propia]));
        $this->assertSame(0, self::eventos($propia));
        $this->assertSame(403, $comoCliente->get("/agenda/cita?id=$propia")['status']);
        // La misma persona, en el portal del equipo, sí opera su agenda.
        $comoEquipo = self::entrar(self::$base, 'equipo', self::$proA);
        $this->assertSame(302, self::postEstado($comoEquipo, $propia, 'en_servicio')['status']);
    }

    // ------------------------------------------------------------------ detalle de la cita

    private function tarjeta(string $html, int $cita): string
    {
        preg_match('~<article class="agenda-tarjeta" id="cita-' . $cita . '".*?</article>~s', $html, $m);
        $this->assertNotEmpty($m, "la agenda no incluye la cita $cita");
        return $m[0];
    }

    public function test_detalle_muestra_cliente_profesional_servicios_con_precio_historico_estado_y_accesos_a_caja(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada('confirmada', self::$proA['profesional_id'], 'parcial');
        self::q("UPDATE servicios SET precio=99 WHERE id=?", [self::$svc]); // el catálogo cambia: el detalle usa el precio histórico
        $this->assertSame(302, self::postEstado($n, $c, 'en_servicio')['status']);
        $this->assertSame(302, self::postEstado($n, $c, 'completada')['status']);
        $desde = self::diaNegocio(-1); $hasta = self::diaNegocio(1);
        $r = $n->get("/agenda/cita?id=$c&desde=$desde&hasta=$hasta");
        $this->assertSame(200, $r['status']);
        $b = $r['body'];
        $this->assertStringContainsString('Cita #' . $c, $b);
        $this->assertStringContainsString('Clara Cliente', $b);
        $this->assertStringContainsString('Corte histórico', $b);
        $this->assertStringContainsString('$20.00', $b);
        $this->assertStringNotContainsString('$99.00', $b);
        $this->assertStringContainsString('Total de la visita: <strong>$20.00</strong>', $b);
        $this->assertStringContainsString('Atendida', $b);
        $this->assertStringContainsString('Inició: Due Ño', $b);
        $this->assertStringContainsString('Finalizó: Due Ño', $b);
        $this->assertStringContainsString('Parcial', $b);
        $this->assertStringContainsString('no marca la visita como pagada', $b);
        $this->assertStringContainsString('Historial de la cita', $b);
        $enlaces = self::enlaces($b);
        $this->assertContains("/agenda?desde=$desde&hasta=$hasta", $enlaces, 'volver a la agenda conserva el período');
        $this->assertContains("/caja/cita?id=$c&desde=$desde&hasta=$hasta", $enlaces, 'Cobrar saldo');
        $this->assertContains("/caja/comprobante?id=$c", $enlaces, 'Ver comprobante');
        $this->assertStringContainsString('Cobrar saldo', $b);
        $this->assertStringContainsString('Ver comprobante', $b);
        foreach ($enlaces as $href) { $this->assertStringNotContainsString('/mis-citas', $href); }
        self::q("UPDATE servicios SET precio=10 WHERE id=?", [self::$svc]);
    }

    public function test_detalle_sin_finanzas_no_muestra_precios_ni_pago_ni_enlaces_a_caja(): void
    {
        $c = $this->citaYaComenzada('confirmada', self::$proA['profesional_id'], 'pagado');
        foreach ([[self::$recepcion, 'recepcion'], [self::$proA, 'profesional propio']] as [$persona, $rol]) {
            $r = self::entrar(self::$base, 'equipo', $persona)->get("/agenda/cita?id=$c");
            $this->assertSame(200, $r['status'], $rol);
            $this->assertStringContainsString('Clara Cliente', $r['body'], $rol);
            $this->assertStringContainsString('Corte histórico', $r['body'], $rol);
            foreach (['$20.00', 'Total de la visita', 'Estado de pago', 'Pagado', 'Cobrar', 'comprobante', '/caja'] as $prohibido) {
                $this->assertStringNotContainsStringIgnoringCase($prohibido, $r['body'], "$rol no ve «{$prohibido}»");
            }
        }
    }

    public function test_detalle_del_profesional_ajeno_es_404_igual_que_una_cita_inexistente(): void
    {
        $ajena = $this->citaYaComenzada('confirmada', self::$proB['profesional_id']);
        $a = self::entrar(self::$base, 'equipo', self::$proA);
        $r1 = $a->get("/agenda/cita?id=$ajena");
        $r2 = $a->get('/agenda/cita?id=987654321');
        $this->assertSame(404, $r1['status']);
        $this->assertSame(404, $r2['status']);
        $this->assertStringNotContainsString('Clara', $r1['body'], 'no revela datos de la cita ajena');
        $norm = static fn(string $h): string => preg_replace(['~\d{4}-\d{2}-\d{2}~', '~name="csrf_token" value="[a-f0-9]+"~', '~\d{9}~', '~\s+~'], ['D', '', 'N', ' '], $h);
        $this->assertSame($norm($r2['body']), $norm($r1['body']), 'cita ajena e inexistente son indistinguibles');
        foreach (['', 'abc', '0', '-1', '1e3', '1.5', str_repeat('9', 30)] as $malo) {
            $this->assertSame(404, $a->get('/agenda/cita?id=' . urlencode($malo))['status'], "id «{$malo}»");
        }
        $this->assertSame(404, $a->get('/agenda/cita?id[]=1')['status']);
    }

    public function test_detalle_exige_sesion_y_permiso_de_agenda(): void
    {
        $c = $this->citaYaComenzada();
        $anonimo = new Navegador(self::$base);
        $r = $anonimo->get("/agenda/cita?id=$c");
        $this->assertContains($r['status'], [302, 401, 403]);
        $this->assertStringNotContainsString('Clara', $r['body']);
        $this->assertSame(403, self::entrar(self::$base, 'equipo', self::$finanzas)->get("/agenda/cita?id=$c")['status'], 'finanzas sin agenda');
    }

    public function test_acciones_del_detalle_vuelven_al_detalle_con_el_periodo(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada();
        $desde = self::diaNegocio(0); $hasta = self::diaNegocio(3);
        $pag = $n->get("/agenda/cita?id=$c&desde=$desde&hasta=$hasta");
        $form = self::formulario($pag['body'], $c, 'en_servicio');
        $this->assertNotNull($form);
        $r = $this->enviarFormulario($n, $form);
        $this->assertSame("/agenda/cita?id=$c&desde=$desde&hasta=$hasta", $r['location']);
        $fin = $n->get($r['location']);
        $this->assertSame(200, $fin['status']);
        $this->assertStringContainsString('Atención iniciada.', $fin['body']);
        $this->assertStringContainsString('En servicio', $fin['body']);
    }

    // ------------------------------------------------------------------ filtro y retorno de otras acciones

    public function test_filtro_por_estado_y_valores_invalidos_se_ignoran(): void
    {
        $n = $this->dueno();
        $conf = $this->cita(self::diaNegocio(2), '09:00:00');
        $comp = $this->cita(self::diaNegocio(2), '10:00:00', 'completada');
        $per = 'desde=' . self::diaNegocio(2) . '&hasta=' . self::diaNegocio(2);
        $solo = $n->get("/agenda?$per&estado=completada")['body'];
        $this->assertStringContainsString('id="cita-' . $comp . '"', $solo);
        $this->assertStringNotContainsString('id="cita-' . $conf . '"', $solo);
        $todas = $n->get("/agenda?$per&estado=" . urlencode('<script>'))['body'];
        $this->assertStringContainsString('id="cita-' . $conf . '"', $todas);
        $this->assertStringContainsString('id="cita-' . $comp . '"', $todas);
        $this->assertStringNotContainsString('<script>', str_replace('<script src=', '', $todas));
        $vacia = $n->get("/agenda?$per&estado=cancelada")['body'];
        $this->assertStringContainsString('No hay citas en estado «Cancelada» en este período.', $vacia);
    }

    public function test_reprogramar_no_se_ofrece_cuando_la_cita_ya_comenzo_y_vuelve_a_la_agenda_con_el_periodo(): void
    {
        $n = $this->dueno();
        $ya = $this->citaYaComenzada();
        $futura = $this->cita(self::diaNegocio(3), '11:00:00', 'confirmada', self::$proA['profesional_id']);
        $per = 'desde=' . self::diaNegocio(0) . '&hasta=' . self::diaNegocio(4);
        $pag = $n->get("/agenda?$per");
        $this->assertStringNotContainsString('data-modal-open="agenda-reprogramar-' . $ya . '"', $pag['body'], 'la cita ya comenzó: el servidor la rechazaría');
        $this->assertStringContainsString('data-modal-open="agenda-reprogramar-' . $futura . '"', $pag['body']);
        $this->assertMatchesRegularExpression('~<dialog id="agenda-reprogramar-' . $futura . '".*?name="volver" value="agenda".*?</dialog>~s', $pag['body']);
    }

    public function test_peticiones_con_retorno_manipulado_no_redirigen_fuera_del_portal(): void
    {
        $n = $this->dueno();
        $c = $this->citaYaComenzada();
        foreach ([['volver' => 'http://evil.example/'], ['volver' => 'cliente_citas'], ['volver' => 'agenda', 'r_desde' => '2030-02-31', 'r_hasta' => '"><x>'], ['volver' => ['a']]] as $extra) {
            $c = $this->citaYaComenzada();
            $r = self::postEstado($n, $c, 'en_servicio', $extra);
            $this->assertSame(302, $r['status']);
            $this->assertMatchesRegularExpression('~^/agenda(\?|$)~', $r['location'] ?? '', 'redirección siempre a la agenda: ' . json_encode($extra));
            $this->assertStringNotContainsString('evil', $r['location']);
            $this->assertStringNotContainsString('mis-citas', $r['location']);
        }
    }

    public function test_el_servidor_no_registra_errores_php(): void
    {
        $log = self::logA5() . (string)@file_get_contents(sys_get_temp_dir() . '/a5_web_' . parse_url(self::$base, PHP_URL_PORT) . '.log.err');
        $this->assertDoesNotMatchRegularExpression('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $log);
    }
}
