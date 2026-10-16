<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5bCase.php';

/**
 * R5b-QA · Regularización en NAVEGADOR real (Chromium, `tests/Qa/r5b_qa_navegador.cjs`): modal/panel accesible (foco, trampa, Escape, retorno al disparador),
 * botón «procesando» con un solo POST (conteo en red), mensaje concreto y persistente, sin desbordes 320–1440 px, foco visible y solicitud del profesional.
 * QA_EXIGIR_NAVEGADOR=1 convierte la ausencia de Chromium en fallo.
 */
final class R5bQaNavegadorTest extends R5bCase
{
    private static array $r = [];
    private static array $cfg = [];
    private static ?string $motivoSin = null;

    public static function setUpBeforeClass(): void
    {
        $chrome = getenv('CHROME_BIN') ?: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
        if (!is_file($chrome) || !is_dir(self::RAIZ . '/node_modules/puppeteer-core')) { self::$motivoSin = 'Chromium o puppeteer-core no disponibles'; return; }
        self::limpiar();
        $base = self::servidorB('nav');
        $e = self::escenarioB();
        $v = fn(int $d) => self::citaVieja($e, ['dias' => $d]);
        $c1 = $v(2); $c2 = $v(3); $c3 = $v(4); $c4 = $v(5);
        self::$cfg = ['base' => $base, 'dueno' => ['email' => $e['dueno']['email'], 'password' => $e['dueno']['password']], 'pro' => ['email' => $e['proA']['email'], 'password' => $e['proA']['password']],
            'citaModal' => $c1, 'citaEnvio' => $c2, 'citaInvalida' => $c3, 'citaSolicitud' => $c4,
            'efectivaEnvio' => self::efectiva($c2, '11:00'), 'efectivaFutura' => self::gye('+2 days')->format('Y-m-d\TH:i'), 'efectivaSolicitud' => self::efectiva($c4, '12:00'),
            'desde' => self::gye('-6 days')->format('Y-m-d'), 'hasta' => self::gye()->format('Y-m-d')];
        self::q('TRUNCATE intentos_login');
        $p = proc_open(['node', self::RAIZ . '/tests/Qa/r5b_qa_navegador.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['QA_CONFIG' => json_encode(self::$cfg), 'CHROME_BIN' => $chrome]));
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        proc_close($p);
        self::$r = json_decode($out, true) ?: ['error' => 'sin salida JSON: ' . $err . substr($out, 0, 400)];
    }

    public static function tearDownAfterClass(): void { self::pararServidorB('nav'); }

    protected function setUp(): void
    {
        if (!self::$cfg) {
            if (getenv('QA_EXIGIR_NAVEGADOR') === '1') { $this->fail('QA_EXIGIR_NAVEGADOR=1: ' . self::$motivoSin); }
            $this->markTestSkipped(self::$motivoSin ?? 'Chromium no disponible');
        }
        $this->assertArrayNotHasKey('error', self::$r, self::$r['error'] ?? '');
    }

    private function paso(string $n): array
    {
        $p = self::$r['pasos'][$n] ?? null;
        $this->assertNotNull($p, "el recorrido no ejecutó $n");
        $this->assertTrue($p['ok'], "paso $n: " . ($p['error'] ?? ''));
        return $p;
    }

    /** Req UX/a11y: «Regularizar» (si abre modal) lleva el foco dentro, tiene nombre accesible, atrapa el foco, Escape cierra y devuelve el foco al disparador; si es un panel en línea, el formulario es alcanzable. */
    public function test_M_modal_regularizar_accesible(): void
    {
        $p = $this->paso('M_modal_regularizar');
        if (!empty($p['sinDisparador'])) { $this->fail('no existe un control «Regularizar» en el detalle de la cita; controles: ' . json_encode($p['botones'])); }
        if (!empty($p['sinModal'])) { $this->assertTrue($p['formVisible'], 'ni modal ni formulario visible tras activar «Regularizar»'); return; }
        $this->assertTrue($p['dentro'], 'el foco no entra al diálogo');
        $this->assertTrue($p['nombre'], 'el diálogo no tiene nombre accesible');
        $this->assertTrue($p['modal'], 'falta aria-modal/dialog nativo');
        $this->assertContains('motivo', $p['campos']);
        $this->assertTrue($p['atrapaFoco'], 'el foco escapa del diálogo con Tab');
        $this->assertTrue($p['cierraConEscape'], 'Escape no cierra el diálogo');
        $this->assertTrue($p['devuelveFoco'], 'al cerrar, el foco no vuelve al disparador');
    }

    /** Req UX: el botón de «Regularizar» pasa a estado de proceso y varios clics/envíos producen UN solo POST de red; la cita queda completada una sola vez. */
    public function test_N_regularizar_estado_de_proceso_y_un_solo_post(): void
    {
        $p = $this->paso('N_regularizar_un_solo_post');
        $this->assertTrue($p['enProceso'], 'el botón no indica estado de proceso tras el clic: ' . json_encode($p['estado']));
        $this->assertSame(1, $p['posts'], 'POSTs a /agenda/regularizar: ' . $p['posts']);
        $c = self::cita(self::$cfg['citaEnvio']);
        $this->assertSame('completada', $c['estado'], 'la regularización no se aplicó: ' . $p['texto']);
        $this->assertCount(1, array_filter(self::regs(self::$cfg['citaEnvio']), fn($r) => $r['estado'] === 'aprobada'));
        $this->assertCount(1, array_filter(self::evs(self::$cfg['citaEnvio']), fn($v) => $v['tipo'] === 'regularizar'));
    }

    /** Req UX: una fecha efectiva futura se rechaza con un mensaje CONCRETO en la página (no solo un toast) que persiste, con «Volver» a la agenda; la cita no cambia. */
    public function test_N_mensaje_concreto_y_persistente_ante_efectiva_futura(): void
    {
        $p = $this->paso('N_mensaje_efectiva_invalida');
        $this->assertMatchesRegularExpression('/futur|posterior|no puede ser|ahora/iu', $p['texto'], 'mensaje poco concreto: ' . $p['texto']);
        $this->assertTrue($p['persiste'], 'el mensaje desapareció a los 5 s');
        $this->assertNotEmpty($p['volver'], 'sin «Volver» a la agenda');
        $this->assertSame('confirmada', self::cita(self::$cfg['citaInvalida'])['estado']);
    }

    /** Req UX: pendientes, detalle y agenda sin desbordes horizontales de 320 a 1440 px. */
    public function test_R_sin_desbordes_y_sin_errores_de_consola(): void
    {
        $this->sinFallos($this->paso('R_sin_desbordes')['malos']);
        $this->sinFallos(array_map(fn($e) => "{$e['texto']} ({$e['url']})", array_filter(self::$r['consola'], fn($e) => !str_contains($e['texto'], 'status of 4'))), 'errores de consola:');
    }

    /** Req UX/a11y: todo control alcanzable con Tab en /agenda/pendientes muestra indicador de foco. */
    public function test_K_foco_visible_en_pendientes(): void
    {
        $this->assertSame([], $this->paso('K_teclado_pendientes')['sinIndicador']);
    }

    /** Req 4 (navegador): el profesional NO ve «Regularizar», sí el formulario de solicitud, y al enviarla recibe confirmación; queda una solicitud abierta y la cita sigue confirmada. */
    public function test_S_profesional_solicita_desde_el_navegador(): void
    {
        $p = $this->paso('S_profesional_solicita');
        $this->assertFalse($p['veRegularizar'], 'el profesional ve el formulario de regularizar');
        $this->assertTrue($p['aviso'], 'sin confirmación de la solicitud: ' . $p['destino']);
        $c = self::$cfg['citaSolicitud'];
        $this->assertSame('confirmada', self::cita($c)['estado']);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=? AND estado='solicitada'", [$c]));
    }
}
