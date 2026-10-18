<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * R5-QA · Flujos A y B (agenda → «Iniciar atención» → pantalla correcta, sin mezclar portales) y máquina de estados (matriz 1 y 3 del usuario).
 *
 * Requisitos del usuario que cubre cada prueba (se citan en su docblock con «Req»):
 *  A. «Iniciar atención» debe llevar a la pantalla correcta, conservar el período consultado y cambiar el estado una sola vez (con auditoría).
 *  B. Un error de la agenda nunca debe llevar a /mis-citas ni a «Reservar una cita» (mezcla de portales); el error se muestra dentro del layout.
 *  C. «Finalizar atención» y «Finalizar y cobrar» (sin teclear ID).
 *  E. Estados válidos/inválidos, doble clic, concurrencia real, historial (actor, fecha, anterior, nuevo), terminales inmutables, profesional propio vs ajeno.
 *
 * Todas son HTTP reales contra php -S (8 procesos). Un 302 nunca basta: se comprueba `Location` y la pantalla final.
 */
final class R5QaAtencionTest extends R5Case
{
    private static string $base;
    private static array $e;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorQ5('atencion');
        self::$e = self::escenario();
    }

    public static function tearDownAfterClass(): void { self::pararServidorQ5('atencion'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    // ------------------------------------------------------------------ ayudas

    private function equipo(string $rol): Navegador
    {
        return self::entrar(self::$base, self::$e[$rol]);
    }

    private function citaComenzada(string $estado = 'confirmada', ?array $pro = null, ?int $cliente = null, int $extra = 0): int
    {
        [$f, $h] = self::slotComenzado($extra);
        return self::crearCita(['cliente' => $cliente ?? self::$e['cliente']['id'], 'pro' => ($pro ?? self::$e['proA'])['profesional_id'], 'fecha' => $f, 'hora' => $h, 'estado' => $estado]);
    }

    private function citaFutura(): int
    {
        [$f, $h] = self::slotFuturo();
        return self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h]);
    }

    private function periodo(): array
    {
        return [self::gye('-2 days')->format('Y-m-d'), self::gye('+3 days')->format('Y-m-d')];
    }

    private function agenda(Navegador $n, int $cita): array
    {
        [$d, $h] = $this->periodo();
        $r = $this->agendaConCita($n, "/agenda?desde=$d&hasta=$h", $cita);
        $this->assertSame(200, $r['status'], 'GET /agenda ' . substr(self::texto($r['body']), 0, 200));
        return $r;
    }

    /** @return array{0:array,1:array} formulario y botón de una acción de la tarjeta de la cita */
    private function accionDe(array $pagina, int $cita, string $regex): array
    {
        $t = self::tarjeta($pagina['body'], $cita);
        $this->assertNotNull($t, "la agenda no muestra la cita #$cita");
        $a = self::accion($t, $regex);
        $this->assertNotNull($a, "la tarjeta de la cita #$cita no ofrece una acción habilitada que coincida con $regex. Controles: " . json_encode(array_map(fn($b) => $b['texto'] . ($b['disabled'] ? ' [deshabilitado]' : ''), self::botones($t)), JSON_UNESCAPED_UNICODE));
        return $a;
    }

    private function eventos(int $cita): array
    {
        return self::q('SELECT * FROM agenda_eventos WHERE citaId=? ORDER BY id', [$cita]);
    }

    private function estado(int $cita): string { return (string)self::escalar('SELECT estado FROM citas WHERE id=?', [$cita]); }

    /** Envía el formulario de "Iniciar" de una cita comenzada auxiliar, cambiando solo el id y el estado (POST forjado con todos los campos de retorno reales). */
    private function postForjado(Navegador $n, int $citaDonante, int $citaObjetivo, string $estado): array
    {
        $pag = $this->agenda($n, $citaDonante);
        [$f, $b] = $this->accionDe($pag, $citaDonante, '/Iniciar atenci[óo]n/iu');
        $cambios = ['id' => (string)$citaObjetivo, 'estado' => $estado];
        return self::enviar($n, $f, $cambios, [], $b);
    }

    private function sinEnlacesAlClienteNiRaw(array $r, string $contexto): void
    {
        $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'), "$contexto: enlaces al portal del cliente en una pantalla del equipo");
        $this->assertStringNotContainsString('Reservar una cita', $r['body'], "$contexto: aparece «Reservar una cita» (pantalla del cliente)");
    }

    // ================================================================== A · Iniciar atención

    /**
     * Req A: el clic en «Iniciar atención» de una cita ya comenzada llega a la pantalla correcta (agenda/detalle), CONSERVA el período, cambia el
     * estado y deja UN solo evento de auditoría con actor, estado anterior y nuevo.
     */
    public function test_A_clic_en_iniciar_atencion_llega_a_la_pantalla_correcta_conserva_periodo_y_audita_una_vez(): void
    {
        $n = $this->equipo('dueno');
        $c = $this->citaComenzada();
        [$desde, $hasta] = $this->periodo();
        $pag = $this->agenda($n, $c);
        [$f, $b] = $this->accionDe($pag, $c, '/Iniciar atenci[óo]n/iu');
        $r = self::enviar($n, $f, [], [], $b);
        $this->assertContains($r['status'], [302, 303], 'tras iniciar debe redirigir; recibió ' . $r['status'] . ': ' . substr(self::texto($r['body']), 0, 200));
        $loc = self::ubicacion($r);
        $this->assertNotSame('', $loc, 'sin cabecera Location');
        $path = self::pathDe($loc);
        $this->assertContains($path, ['/agenda', '/agenda/cita'], "destino inesperado tras iniciar: $loc");
        $this->assertSame($desde, self::param($loc, 'desde'), "el destino pierde «desde» del período consultado: $loc");
        $this->assertSame($hasta, self::param($loc, 'hasta'), "el destino pierde «hasta» del período consultado: $loc");
        if ($path === '/agenda/cita') { $this->assertSame((string)$c, self::param($loc, 'id')); }
        // Pantalla final real.
        $fin = $n->get($loc);
        $this->assertSame(200, $fin['status']);
        $this->assertTrue(self::esPaginaCompleta($fin['body']), 'la pantalla final debe ser una página completa con layout');
        $this->assertMatchesRegularExpression('/en[ _]servicio/iu', self::texto($fin['body']), 'la pantalla final no muestra el estado «en servicio»');
        if ($path === '/agenda') {
            $this->assertSame($desde, self::campoValor($fin['body'], 'desde'), 'el formulario de la agenda no conserva «Desde»');
            $this->assertSame($hasta, self::campoValor($fin['body'], 'hasta'), 'el formulario de la agenda no conserva «Hasta»');
            $this->assertNotNull(self::accion((string)self::tarjeta($fin['body'], $c), '/Finalizar atenci[óo]n/iu'), 'la cita en servicio debe ofrecer «Finalizar atención»');
        }
        $this->sinEnlacesAlClienteNiRaw($fin, 'pantalla tras iniciar');
        // Estado y auditoría.
        $this->assertSame('en_servicio', $this->estado($c));
        $ev = $this->eventos($c);
        $this->assertCount(1, $ev, 'debe existir exactamente UN evento de auditoría');
        $this->assertSame(self::$e['dueno']['id'], (int)$ev[0]['actorId']);
        $this->assertSame('confirmada', json_decode($ev[0]['antes_json'], true)['estado'] ?? null);
        $this->assertSame('en_servicio', json_decode($ev[0]['despues_json'], true)['estado'] ?? null);
    }

    private static function campoValor(string $html, string $nombre): ?string
    {
        foreach (self::formularios($html) as $f) { if (isset($f['campos'][$nombre]) && $f['method'] === 'GET') { return $f['campos'][$nombre]; } }
        return null;
    }

    /**
     * Req A: una cita FUTURA no ofrece «Iniciar atención» como acción habilitada: el botón está deshabilitado y la tarjeta explica el motivo
     * («Disponible desde las HH:MM»). Nunca un error de página al hacer clic.
     */
    public function test_A_cita_futura_muestra_boton_deshabilitado_con_motivo(): void
    {
        $n = $this->equipo('dueno');
        $c = $this->citaFutura();
        $pag = $this->agenda($n, $c);
        $t = self::tarjeta($pag['body'], $c);
        $this->assertNotNull($t, 'la agenda no muestra la cita futura');
        $this->assertTrue(self::accion($t, '/Iniciar atenci[óo]n/iu') === null, 'la cita futura NO debe ofrecer un «Iniciar atención» habilitado (provoca un error al hacer clic)');
        $ctl = self::control($t, '/Iniciar atenci[óo]n/iu');
        $this->assertNotNull($ctl, 'debe mostrarse el botón deshabilitado «Iniciar atención»');
        $this->assertTrue($ctl['disabled'], 'el botón de una cita futura debe estar deshabilitado');
        $txt = self::texto($t);
        $this->assertMatchesRegularExpression('/Disponible desde(?: las)? \d{1,2}:\d{2}/iu', $txt, 'falta el motivo «Disponible desde las HH:MM»: ' . $txt);
    }

    /**
     * Req A+B: un POST forjado a una cita futura responde con un error CONCRETO dentro del layout, con «Volver» a la agenda (conservando el período)
     * y SIN enlaces a /mis-citas ni a ninguna ruta del portal del cliente; el estado no cambia y no hay evento.
     */
    public function test_A_B_post_forjado_a_cita_futura_error_dentro_del_layout_con_volver_a_la_agenda(): void
    {
        $n = $this->equipo('dueno');
        $donante = $this->citaComenzada();
        $futura = $this->citaFutura();
        [$desde, $hasta] = $this->periodo();
        $r = $this->postForjado($n, $donante, $futura, 'en_servicio');
        $this->assertContains($r['status'], [409, 422], 'estado HTTP del rechazo: ' . $r['status']);
        $this->assertTrue(self::esPaginaCompleta($r['body']), 'el error debe mostrarse como página completa con layout (no un <h1> suelto)');
        $this->assertStringNotContainsString('<h1>No se pudo actualizar la cita</h1>', $r['body']);
        $this->assertSame([], array_filter(self::destinos($r['body']), fn($d) => in_array(self::pathDe($d), self::RUTAS_CLIENTE, true)), 'enlaces al portal del cliente en el error: ' . json_encode(self::destinos($r['body'])));
        $this->assertStringNotContainsString('Reservar una cita', $r['body']);
        $volver = array_values(array_filter(self::destinos($r['body']), fn($d) => self::pathDe($d) === '/agenda'));
        $this->assertNotEmpty($volver, 'el error debe ofrecer «Volver» a la agenda. Destinos: ' . json_encode(self::destinos($r['body'])));
        $conPeriodo = array_values(array_filter($volver, fn($d) => self::param($d, 'desde') === $desde && self::param($d, 'hasta') === $hasta));
        $this->assertNotEmpty($conPeriodo, 'el «Volver» a la agenda debe conservar el período consultado: ' . json_encode($volver));
        $this->assertMatchesRegularExpression('/todav[ií]a no|aún no|a[uú]n no|comenz/iu', self::texto($r['body']), 'mensaje poco concreto: ' . substr(self::texto($r['body']), 0, 300));
        $this->assertSame('confirmada', $this->estado($futura));
        $this->assertCount(0, $this->eventos($futura));
    }

    /**
     * Req B: ningún error de operación de la agenda (404, 409, 422, CSRF) lleva a rutas del portal del cliente; todos traen «Volver» al portal del equipo.
     */
    public function test_B_errores_de_acciones_de_agenda_no_mezclan_portales(): void
    {
        $n = $this->equipo('dueno');
        $donante = $this->citaComenzada();
        $terminal = $this->citaComenzada('completada');
        $casos = [
            '404 cita inexistente' => $this->postForjado($n, $donante, 99999999, 'en_servicio'),
            '409 transición inválida' => $this->postForjado($n, $donante, $terminal, 'en_servicio'),
            '409/422 estado desconocido' => $this->postForjado($n, $donante, $donante, 'pagada'),
            '404/422 id no numérico' => $this->postForjado($n, $donante, 0, 'en_servicio'),
        ];
        foreach ($casos as $nombre => $r) {
            $this->assertGreaterThanOrEqual(400, $r['status'], "$nombre: debe ser un error HTTP, fue {$r['status']}");
            $this->assertLessThan(500, $r['status'], "$nombre: no debe ser un 5xx");
            $this->assertTrue(self::esPaginaCompleta($r['body']), "$nombre: no es una página completa con layout");
            $this->assertSame([], array_filter(self::destinos($r['body']), fn($d) => in_array(self::pathDe($d), self::RUTAS_CLIENTE, true)), "$nombre: enlaza al portal del cliente");
            $this->assertStringNotContainsString('Reservar una cita', $r['body'], $nombre);
            $this->assertNotEmpty(array_filter(self::destinos($r['body']), fn($d) => self::pathDe($d) === '/agenda'), "$nombre: sin «Volver» a la agenda");
        }
        // CSRF ausente: nunca cambia nada.
        $pag = $this->agenda($n, $donante);
        [$f, $b] = $this->accionDe($pag, $donante, '/Iniciar atenci[óo]n/iu');
        $r = self::enviar($n, $f, [], ['csrf_token'], $b);
        $this->assertContains($r['status'], [403, 419], 'sin CSRF debe rechazarse: ' . $r['status']);
        $this->assertSame('confirmada', $this->estado($donante));
        $this->assertSame([], array_filter(self::destinos($r['body']), fn($d) => in_array(self::pathDe($d), self::RUTAS_CLIENTE, true)), 'el error de CSRF enlaza al portal del cliente');
    }

    // ================================================================== C · Finalizar

    /** Req C+E: «Finalizar atención» completa la cita, NO la marca pagada y deja un evento anterior→nuevo. */
    public function test_C_finalizar_atencion_completa_sin_marcar_pagado(): void
    {
        $n = $this->equipo('dueno');
        $c = $this->citaComenzada('en_servicio');
        $pag = $this->agenda($n, $c);
        [$f, $b] = $this->accionDe($pag, $c, '/^Finalizar atenci[óo]n$/iu');
        $r = self::enviar($n, $f, [], [], $b);
        $this->assertContains($r['status'], [302, 303], substr(self::texto($r['body']), 0, 200));
        $this->assertSame(200, $n->get(self::ubicacion($r))['status']);
        $this->assertSame('completada', $this->estado($c));
        $this->assertSame('pendiente', (string)self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$c]), 'finalizar NUNCA marca «pagado»');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [$c]));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM caja_movimientos'), 'finalizar no genera movimientos de caja');
        $ev = $this->eventos($c);
        $this->assertCount(1, $ev);
        $this->assertSame('en_servicio', json_decode($ev[0]['antes_json'], true)['estado'] ?? null);
        $this->assertSame('completada', json_decode($ev[0]['despues_json'], true)['estado'] ?? null);
    }

    /**
     * Req C: «Finalizar y cobrar» (propietario/gerente) finaliza la cita y llega al formulario de cobro de ESA cita sin teclear ningún ID:
     * Location = /caja/cita?id=N y la pantalla final muestra cliente, total y un formulario de cobro.
     */
    public function test_C_finalizar_y_cobrar_llega_al_cobro_de_esa_cita_sin_teclear_id(): void
    {
        foreach (['dueno', 'gerente'] as $rol) {
            $n = $this->equipo($rol);
            $c = $this->citaComenzada('en_servicio');
            $pag = $this->agenda($n, $c);
            [$f, $b] = $this->accionDe($pag, $c, '/Finalizar y cobrar/iu');
            $r = self::enviar($n, $f, [], [], $b);
            $this->assertContains($r['status'], [302, 303], "$rol: " . substr(self::texto($r['body']), 0, 200));
            $loc = self::ubicacion($r);
            $this->assertSame('/caja/cita', self::pathDe($loc), "$rol: destino inesperado «{$loc}»");
            $this->assertSame((string)$c, self::param($loc, 'id'), "$rol: el cobro debe ser de la cita finalizada");
            $this->assertSame('completada', $this->estado($c));
            $fin = $n->get($loc);
            $this->assertSame(200, $fin['status'], "$rol: GET $loc");
            $this->assertTrue(self::esPaginaCompleta($fin['body']));
            $txt = self::texto($fin['body']);
            $this->assertStringContainsString('Clienta', $txt, 'el cobro no muestra al cliente');
            $this->assertMatchesRegularExpression('/20\.00/', $txt, 'el cobro no muestra el total ($20.00)');
            $this->assertNotNull(self::formulario($fin['body'], '~/caja/cita/cobrar~'), 'no hay formulario de cobro');
            $this->sinEnlacesAlClienteNiRaw($fin, 'cobro de la cita');
            $this->assertCount(1, $this->eventos($c));
        }
    }

    /**
     * Req C+permisos: recepción NO ve «Finalizar y cobrar» (no tiene finanzas.gestionar) y un POST forjado de ese botón no finaliza ni abre el cobro.
     */
    public function test_C_recepcion_no_puede_finalizar_y_cobrar(): void
    {
        $dueno = $this->equipo('dueno');
        $c = $this->citaComenzada('en_servicio');
        $fDueno = $this->accionDe($this->agenda($dueno, $c), $c, '/Finalizar y cobrar/iu');
        $rec = $this->equipo('recepcion');
        $t = self::tarjeta($this->agenda($rec, $c)['body'], $c);
        $this->assertNotNull($t);
        $this->assertNull(self::control($t, '/Finalizar y cobrar/iu'), 'recepción no debe ver «Finalizar y cobrar»');
        $this->assertNotNull(self::accion($t, '/^Finalizar atenci[óo]n$/iu'), 'recepción sí puede «Finalizar atención»');
        $r = self::enviar($rec, $fDueno[0], [], [], $fDueno[1]);
        $loc = self::ubicacion($r);
        $this->assertNotSame('/caja/cita', self::pathDe($loc), 'recepción no debe llegar al cobro');
        $this->assertSame(403, $r['status'], 'el servidor debe exigir agenda.gestionar Y finanzas.gestionar (403); fue ' . $r['status']);
        $this->assertSame('en_servicio', $this->estado($c), 'una acción denegada no cambia nada');
        $this->assertCount(0, $this->eventos($c));
        $this->assertGreaterThan(0, count(self::destinos($r['body'])));
        $this->assertSame([], array_filter(self::destinos($r['body']), fn($d) => in_array(self::pathDe($d), self::RUTAS_CLIENTE, true)));
    }

    /** Req E: «Registrar ausencia» desde confirmada (ya iniciada la hora) → no_asistio con un evento; el estado financiero no se toca. */
    public function test_E_registrar_ausencia(): void
    {
        $n = $this->equipo('recepcion');
        $c = $this->citaComenzada();
        [$f, $b] = $this->accionDe($this->agenda($n, $c), $c, '/Registrar ausencia/iu');
        $r = self::enviar($n, $f, [], [], $b);
        $this->assertContains($r['status'], [302, 303]);
        $this->assertSame('no_asistio', $this->estado($c));
        $this->assertSame('pendiente', (string)self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$c]));
        $this->assertCount(1, $this->eventos($c));
    }

    // ================================================================== E · Detalle

    /**
     * Req C/E: GET /agenda/cita?id=N muestra cliente, profesional, servicios con precio histórico, estado, estado de pago y accesos; el profesional
     * ajeno y el cliente reciben 403/404 SIN datos personales; un id inexistente da 404 dentro del layout.
     */
    public function test_E_detalle_de_cita_para_el_equipo_y_su_alcance(): void
    {
        $c = $this->citaComenzada('completada');
        $dueno = $this->equipo('dueno');
        $r = $dueno->get("/agenda/cita?id=$c");
        $this->assertSame(200, $r['status'], 'GET /agenda/cita?id=' . $c . ' → ' . $r['status']);
        $txt = self::texto($r['body']);
        foreach (['Clienta', 'Quinta', 'ProA', 'Corte Q5', '20.00'] as $esperado) { $this->assertStringContainsString($esperado, $txt, "el detalle no muestra «{$esperado}»"); }
        $this->assertMatchesRegularExpression('/completad|atendid/iu', $txt, 'el detalle no muestra el estado de la atención');
        $this->assertMatchesRegularExpression('/pago|pendiente/iu', $txt, 'el detalle no muestra el estado de pago');
        $this->assertNotEmpty(array_filter(self::destinos($r['body']), fn($d) => str_starts_with(self::pathDe($d), '/caja')), 'sin acceso a cobro/pagos/comprobante para quien tiene finanzas.gestionar');
        $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'));
        // Profesional propio: sí; ajeno: no, y sin filtrar datos.
        $propio = self::entrar(self::$base, self::$e['proA'])->get("/agenda/cita?id=$c");
        $this->assertSame(200, $propio['status'], 'el profesional dueño de la cita debe verla');
        $ajeno = self::entrar(self::$base, self::$e['proB'])->get("/agenda/cita?id=$c");
        $this->assertContains($ajeno['status'], [403, 404], 'un profesional ajeno no debe ver el detalle');
        $this->assertStringNotContainsString('Clienta', self::texto($ajeno['body']), 'el rechazo filtra el nombre del cliente');
        // Cliente: 403 y «Volver» a su portal.
        $cli = self::entrar(self::$base, self::$e['cliente'], 'cliente')->get("/agenda/cita?id=$c");
        $this->assertSame(403, $cli['status']);
        $this->assertStringNotContainsString('Quinta', self::texto($cli['body']));
        // Inexistente.
        $no = $dueno->get('/agenda/cita?id=99999999');
        $this->assertSame(404, $no['status']);
        $this->assertTrue(self::esPaginaCompleta($no['body']));
        $this->assertSame([], self::enlacesCruzados($no['body'], 'equipo'));
        $malo = $dueno->get('/agenda/cita?id=abc');
        $this->assertContains($malo['status'], [404, 422]);
    }

    // ================================================================== E · Concurrencia y estados

    /**
     * Req E: DOBLE CLIC y DOS PROCESOS PHP REALES haciendo la misma transición a la vez ⇒ una sola transición y UN solo evento; ningún 5xx;
     * el perdedor recibe un resultado coherente («ya actualizado»), nunca un segundo evento. 6 rondas con citas nuevas.
     */
    public function test_E_misma_transicion_simultanea_desde_dos_sesiones_un_solo_evento(): void
    {
        $a = $this->equipo('dueno');
        $b = $this->equipo('gerente');
        for ($ronda = 1; $ronda <= 6; $ronda++) {
            $c = $this->citaComenzada('confirmada', null, null, $ronda);
            [$fa, $ba] = $this->accionDe($this->agenda($a, $c), $c, '/Iniciar atenci[óo]n/iu');
            [$fb, $bb] = $this->accionDe($this->agenda($b, $c), $c, '/Iniciar atenci[óo]n/iu');
            $cuerpoA = array_merge($fa['campos'], $ba['name'] ? [$ba['name'] => $ba['value']] : []);
            $cuerpoB = array_merge($fb['campos'], $bb['name'] ? [$bb['name'] => $bb['value']] : []);
            $res = self::rafaga(self::$base, [
                ['cookie' => $a->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fa['action'], 'body' => $cuerpoA],
                ['cookie' => $b->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fb['action'], 'body' => $cuerpoB],
            ]);
            foreach ($res as $i => $r) { $this->assertContains($r['status'], [200, 302, 303, 409], "ronda $ronda proceso $i: estado {$r['status']} " . substr(self::texto($r['body']), 0, 160)); }
            $this->assertSame('en_servicio', $this->estado($c), "ronda $ronda");
            $this->assertCount(1, $this->eventos($c), "ronda $ronda: debe quedar UN solo evento aunque dos procesos pidieran lo mismo");
        }
        $this->assertStringNotContainsString('Deadlock', self::logQ5('atencion'), 'abrazo mortal registrado en el servidor');
    }

    /** Req E: doble clic secuencial rápido (mismo formulario enviado dos veces) ⇒ segunda respuesta sin error 5xx y sin segundo evento. */
    public function test_E_doble_clic_secuencial_no_duplica_el_evento(): void
    {
        $n = $this->equipo('dueno');
        $c = $this->citaComenzada();
        [$f, $b] = $this->accionDe($this->agenda($n, $c), $c, '/Iniciar atenci[óo]n/iu');
        $r1 = self::enviar($n, $f, [], [], $b);
        $r2 = self::enviar($n, $f, [], [], $b);
        $this->assertContains($r1['status'], [302, 303]);
        $this->assertLessThan(500, $r2['status'], 'el segundo clic produjo un 5xx');
        $this->assertContains($r2['status'], [200, 302, 303, 409], 'el segundo clic debe ser «ya actualizado» o un conflicto claro');
        $this->assertSame('en_servicio', $this->estado($c));
        $this->assertCount(1, $this->eventos($c));
    }

    /** Req E: transiciones CONFLICTIVAS simultáneas (iniciar vs ausencia desde confirmada): gana una sola, la otra no deja evento; el evento coincide con el estado final. */
    public function test_E_transiciones_conflictivas_simultaneas_gana_una(): void
    {
        $a = $this->equipo('dueno');
        $b = $this->equipo('recepcion');
        for ($ronda = 1; $ronda <= 4; $ronda++) {
            $c = $this->citaComenzada('confirmada', null, null, 10 + $ronda);
            [$fa, $ba] = $this->accionDe($this->agenda($a, $c), $c, '/Iniciar atenci[óo]n/iu');
            [$fb, $bb] = $this->accionDe($this->agenda($b, $c), $c, '/Registrar ausencia/iu');
            $res = self::rafaga(self::$base, [
                ['cookie' => $a->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fa['action'], 'body' => array_merge($fa['campos'], $ba['name'] ? [$ba['name'] => $ba['value']] : [])],
                ['cookie' => $b->cookieSesion(), 'metodo' => 'POST', 'ruta' => $fb['action'], 'body' => array_merge($fb['campos'], $bb['name'] ? [$bb['name'] => $bb['value']] : [])],
            ]);
            foreach ($res as $r) { $this->assertLessThan(500, $r['status'], 'un 5xx en la carrera: ' . substr(self::texto($r['body']), 0, 200)); }
            $final = $this->estado($c);
            $this->assertContains($final, ['en_servicio', 'no_asistio'], "ronda $ronda");
            $ev = $this->eventos($c);
            $this->assertCount(1, $ev, "ronda $ronda: exactamente un evento (gana uno, el otro recibe el estado vigente)");
            $this->assertSame($final, json_decode($ev[0]['despues_json'], true)['estado'] ?? null, 'el evento no coincide con el estado final');
            $estados = array_column($res, 'status');
            $this->assertContains(409, $estados, 'el perdedor debe recibir un conflicto (409) y no un éxito falso: ' . json_encode($estados));
        }
    }

    /**
     * Req E: matriz completa de transiciones. Válidas: confirmada→en_servicio|no_asistio, en_servicio→completada. El mismo estado es idempotente (sin evento).
     * Todo lo demás (incluida salir de estados TERMINALES completada/no_asistio/cancelada) responde 409/422 sin cambiar nada ni auditar.
     */
    public function test_E_matriz_de_transiciones_validas_invalidas_y_terminales_inmutables(): void
    {
        $n = $this->equipo('dueno');
        $donante = $this->citaComenzada();
        $estados = ['confirmada', 'en_servicio', 'completada', 'no_asistio', 'cancelada'];
        $validas = ['confirmada>en_servicio', 'confirmada>no_asistio', 'en_servicio>completada'];
        $malas = [];
        foreach ($estados as $desde) {
            foreach (array_merge($estados, ['pagada', 'reembolsada', '', 'COMPLETADA', 'completada; DROP TABLE citas']) as $hacia) {
                $c = $this->citaComenzada($desde, null, null, 20);
                $r = $this->postForjado($n, $donante, $c, $hacia);
                $efecto = $this->estado($c) !== $desde;
                $eventos = count($this->eventos($c));
                $clave = "$desde>$hacia";
                if (in_array($clave, $validas, true)) {
                    if (!in_array($r['status'], [302, 303], true) || $this->estado($c) !== $hacia || $eventos !== 1) { $malas[] = "$clave: debió aplicarse (status {$r['status']}, estado " . $this->estado($c) . ", eventos $eventos)"; }
                } elseif ($desde === $hacia) {
                    if ($r['status'] >= 500 || $efecto || $eventos !== 0) { $malas[] = "$clave: mismo estado debió ser idempotente sin evento (status {$r['status']}, eventos $eventos)"; }
                } else {
                    if (!in_array($r['status'], [409, 422], true) || $efecto || $eventos !== 0) { $malas[] = "$clave: debió rechazarse con 409/422 sin cambios (status {$r['status']}, estado " . $this->estado($c) . ", eventos $eventos)"; }
                }
            }
        }
        $this->sinFallos($malas, "violaciones de la máquina de estados:");
    }

    /**
     * Req E (decisión del líder, ronda 5): una cita confirmada de DÍAS ANTERIORES que nadie inició se puede registrar CON RETRASO (si no, jamás
     * podría atenderse ni cobrarse). No es silencioso: el evento deja quién y CUÁNDO (fecha real de hoy), distinta de la fecha de la cita.
     */
    public function test_E_cita_de_dias_anteriores_no_se_inicia_de_forma_ordinaria(): void
    {
        // DECISIÓN DEL USUARIO (regularización, docs/R5_REGULARIZACION.md): sin inicio ordinario de una cita de un día anterior;
        // se reprograma o la regulariza el propietario/gerente. (Cambio de expectativa respecto de la versión «con retraso».)
        $n = $this->equipo('dueno');
        $donante = $this->citaComenzada();
        $ayer = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::gye('-1 day')->format('Y-m-d'), 'hora' => '10:00:00']);
        $pag = $this->agenda($n, $ayer);
        $t = self::tarjeta($pag['body'], $ayer);
        $this->assertNotNull($t);
        $this->assertNull(self::accion($t, '/Iniciar atenci[óo]n/iu'), 'una cita de un día anterior no ofrece un formulario de inicio');
        $this->assertMatchesRegularExpression('/repro?gr[aá]mala|reprogram/iu', self::texto($t), 'el botón deshabilitado explica qué hacer');
        $r = $this->postForjado($n, $donante, $ayer, 'en_servicio');
        $this->assertSame(422, $r['status'], 'el servidor también lo rechaza');
        $this->assertSame('confirmada', $this->estado($ayer));
        $this->assertCount(0, $this->eventos($ayer), 'el rechazo no escribe nada');
    }

    /**
     * Req A/E: margen de adelanto (60 min): una cita que empieza en ~30 min SÍ se puede iniciar; una que empieza en 2 h no.
     * (Solo evaluable antes de las 23:00 hora Guayaquil; después se marca incompleta de forma explícita, no omitida en silencio.)
     */
    public function test_E_margen_de_adelanto_de_60_minutos(): void
    {
        $slot = self::slotEnMargen();
        if ($slot === null) { $this->markTestIncomplete('Después de las 23:30 (America/Guayaquil) no existe un horario «dentro del margen» el mismo día; repetir a otra hora.'); }
        $n = $this->equipo('dueno');
        $c = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $slot[0], 'hora' => $slot[1]]);
        [$f, $b] = $this->accionDe($this->agenda($n, $c), $c, '/Iniciar atenci[óo]n/iu');
        $r = self::enviar($n, $f, [], [], $b);
        $this->assertContains($r['status'], [302, 303], 'dentro del margen de 60 min debe permitir iniciar: ' . substr(self::texto($r['body']), 0, 200));
        $this->assertSame('en_servicio', $this->estado($c));
        $dos = self::gye('+2 hours');
        if ($dos->format('Y-m-d') === self::gye()->format('Y-m-d')) {
            $lejos = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $dos->format('Y-m-d'), 'hora' => $dos->format('H:i:s')]);
            $t = self::tarjeta($this->agenda($n, $lejos)['body'], $lejos);
            $this->assertTrue(self::accion((string)$t, '/Iniciar atenci[óo]n/iu') === null, 'a 2 h del inicio no debe estar habilitado');
            $this->assertMatchesRegularExpression('/Disponible desde/iu', self::texto((string)$t));
        }
    }

    /** Req E: historial con actor, fecha (America/Guayaquil), estado anterior y nuevo, visible en el detalle con el NOMBRE de quien atendió. */
    public function test_E_historial_registra_actor_fecha_anterior_y_nuevo(): void
    {
        $c = $this->citaComenzada();
        $dueno = $this->equipo('dueno');
        $gerente = $this->equipo('gerente');
        [$f, $b] = $this->accionDe($this->agenda($dueno, $c), $c, '/Iniciar atenci[óo]n/iu');
        $this->assertContains(self::enviar($dueno, $f, [], [], $b)['status'], [302, 303]);
        [$f, $b] = $this->accionDe($this->agenda($gerente, $c), $c, '/^Finalizar atenci[óo]n$/iu');
        $this->assertContains(self::enviar($gerente, $f, [], [], $b)['status'], [302, 303]);
        $ev = $this->eventos($c);
        $this->assertCount(2, $ev);
        $this->assertSame([self::$e['dueno']['id'], self::$e['gerente']['id']], [(int)$ev[0]['actorId'], (int)$ev[1]['actorId']]);
        $this->assertSame(['confirmada', 'en_servicio'], [json_decode($ev[0]['antes_json'], true)['estado'], json_decode($ev[0]['despues_json'], true)['estado']]);
        $this->assertSame(['en_servicio', 'completada'], [json_decode($ev[1]['antes_json'], true)['estado'], json_decode($ev[1]['despues_json'], true)['estado']]);
        $ahoraGye = self::gye();
        foreach ($ev as $e) {
            $t = new \DateTimeImmutable($e['creado_en'], new \DateTimeZone(self::ZONA));
            $this->assertLessThan(180, abs($ahoraGye->getTimestamp() - $t->getTimestamp()), "la fecha del evento ({$e['creado_en']}) no está en hora de Guayaquil (ahora " . $ahoraGye->format('Y-m-d H:i:s') . ')');
        }
        $det = $dueno->get("/agenda/cita?id=$c");
        $this->assertSame(200, $det['status'], 'GET /agenda/cita?id=' . $c);
        $txt = self::texto($det['body']);
        $this->assertStringContainsString('Gera', $txt, 'el detalle debe mostrar quién finalizó la atención');
        $this->assertStringContainsString('Due', $txt, 'el detalle debe mostrar quién inició la atención');
    }

    /** Req E: el profesional solo opera su propia agenda: ve solo sus citas, cambia sus estados y NO los ajenos; tampoco accede a caja. */
    public function test_E_profesional_propio_vs_ajeno(): void
    {
        $propia = $this->citaComenzada('confirmada', self::$e['proA']);
        $ajena = $this->citaComenzada('confirmada', self::$e['proB']);
        $pa = self::entrar(self::$base, self::$e['proA']);
        $pag = $this->agenda($pa, $propia);
        $this->assertNotNull(self::tarjeta($pag['body'], $propia));
        $this->assertNull(self::tarjeta($pag['body'], $ajena), 'el profesional ve una cita ajena en su agenda');
        [$f, $b] = $this->accionDe($pag, $propia, '/Iniciar atenci[óo]n/iu');
        $this->assertContains(self::enviar($pa, $f, [], [], $b)['status'], [302, 303]);
        $this->assertSame('en_servicio', $this->estado($propia));
        $r = self::enviar($pa, $f, ['id' => (string)$ajena], [], $b);
        $this->assertSame(403, $r['status'], 'iniciar la cita de otro profesional debe ser 403');
        $this->assertSame('confirmada', $this->estado($ajena));
        $this->assertCount(0, $this->eventos($ajena));
        $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'));
        $this->assertSame(403, $pa->get('/caja')['status'], 'el profesional no accede a caja');
        $this->assertSame(403, $pa->get('/admin/finanzas')['status']);
        // «Finalizar y cobrar» forjado por un profesional: nunca.
        $enServ = $this->citaComenzada('en_servicio', self::$e['proA']);
        [$fy, $by] = $this->accionDe($this->agenda($this->equipo('dueno'), $enServ), $enServ, '/Finalizar y cobrar/iu');
        $propiaServ = (int)$fy['campos']['id'];
        $rr = self::enviar($pa, $fy, [], [], $by);
        $this->assertNotSame('/caja/cita', self::pathDe(self::ubicacion($rr)));
        $this->assertSame(403, $rr['status']);
        $this->assertSame('en_servicio', $this->estado($propiaServ));
    }

    /** Req E: el cliente (portal cliente) no puede cambiar estados de atención ni ver la agenda; el error vuelve a SU portal (/cita), no al del equipo. */
    public function test_E_cliente_no_cambia_estados_y_su_error_vuelve_a_su_portal(): void
    {
        $c = $this->citaComenzada('confirmada', null, self::$e['cliente']['id']);
        $cli = self::entrar(self::$base, self::$e['cliente'], 'cliente');
        $pagina = $cli->get('/cita');
        $csrf = Navegador::csrfDe($pagina['body']) ?? $cli->csrf('/cita');
        $r = $cli->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $c, 'estado' => 'en_servicio']);
        $this->assertSame(403, $r['status'], 'el cliente no puede iniciar atención: ' . $r['status']);
        $this->assertSame('confirmada', $this->estado($c));
        $this->assertCount(0, $this->eventos($c));
        $paths = array_map([self::class, 'pathDe'], self::destinos($r['body']));
        $this->assertContains('/cita', $paths, 'el error del cliente debe volver a /cita. Destinos: ' . json_encode($paths));
        $this->assertSame([], self::enlacesCruzados($r['body'], 'cliente'), 'el error del cliente enlaza a pantallas del equipo');
        foreach (['/agenda', '/agenda?desde=2020-01-01&hasta=2020-01-02', '/caja', '/admin/finanzas'] as $ruta) {
            $g = $cli->get($ruta);
            $this->assertSame(403, $g['status'], "cliente GET $ruta");
            $this->assertSame([], self::enlacesCruzados($g['body'], 'cliente'), "403 de $ruta enlaza a pantallas del equipo");
        }
    }

    /**
     * Req E/robustez: si la escritura de auditoría falla a mitad de la transición el estado NO queda a medias (rollback) y el error es una
     * página del layout con «Volver»; al restaurar, el reintento aplica la transición una sola vez.
     */
    public function test_E_fallo_a_mitad_de_transicion_deja_todo_intacto_y_el_reintento_funciona(): void
    {
        $n = $this->equipo('dueno');
        $c = $this->citaComenzada();
        [$f, $b] = $this->accionDe($this->agenda($n, $c), $c, '/Iniciar atenci[óo]n/iu');
        self::q('RENAME TABLE agenda_eventos TO agenda_eventos_q5_bak');
        try {
            $r = self::enviar($n, $f, [], [], $b);
        } finally {
            self::q('RENAME TABLE agenda_eventos_q5_bak TO agenda_eventos');
        }
        $this->assertContains($r['status'], [500, 503], 'estado ante el fallo: ' . $r['status']);
        $this->assertSame('confirmada', $this->estado($c), 'el estado cambió aunque la auditoría falló (no es atómico)');
        $this->assertTrue(self::esPaginaCompleta($r['body']), 'el error 5xx debe ser una página completa');
        $this->assertStringNotContainsString('agenda_eventos', $r['body'], 'el error filtra detalles internos');
        $this->assertStringNotContainsString('SQLSTATE', $r['body']);
        $this->assertSame([], array_filter(self::destinos($r['body']), fn($d) => in_array(self::pathDe($d), self::RUTAS_CLIENTE, true)), 'el error 5xx enlaza al portal del cliente');
        $r2 = self::enviar($n, $f, [], [], $b);
        $this->assertContains($r2['status'], [302, 303], 'el reintento tras restaurar debe funcionar');
        $this->assertSame('en_servicio', $this->estado($c));
        $this->assertCount(1, $this->eventos($c));
    }
}
