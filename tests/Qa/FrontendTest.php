<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/** H. Frontend con Chromium real (puppeteer-core) contra el servidor PHP real. */
final class FrontendTest extends QaCase
{
    private static array $r = [];
    private static array $cfg = [];
    private const CHROME_RESPALDO = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
    private static ?string $motivoSinNavegador = null;

    /** Ejecutable de Chromium: CHROME_BIN (si existe y es ejecutable) y, solo como respaldo, la ruta local conocida. */
    private static function chromium(): ?string
    {
        $env = getenv('CHROME_BIN');
        if (is_string($env) && $env !== '') {
            // Si el entorno declara un binario, es el único válido: no se «rescata» silenciosamente con otro distinto.
            return is_file($env) && is_executable($env) ? $env : null;
        }
        return is_file(self::CHROME_RESPALDO) && is_executable(self::CHROME_RESPALDO) ? self::CHROME_RESPALDO : null;
    }

    public static function setUpBeforeClass(): void
    {
        // Chromium: CHROME_BIN manda; la ruta de /opt/pw-browsers es solo respaldo local.
        $chrome = self::chromium();
        if ($chrome === null || !is_dir(self::RAIZ . '/node_modules/puppeteer-core')) {
            $env = getenv('CHROME_BIN');
            $faltan = [];
            if ($chrome === null) { $faltan[] = (is_string($env) && $env !== '') ? "Chromium: CHROME_BIN='$env' no existe o no es ejecutable" : 'Chromium: CHROME_BIN sin definir y no existe el respaldo ' . self::CHROME_RESPALDO; }
            if (!is_dir(self::RAIZ . '/node_modules/puppeteer-core')) { $faltan[] = 'node_modules/puppeteer-core no está instalado (npm ci)'; }
            self::$motivoSinNavegador = implode('; ', $faltan);
            return;
        }
        self::limpiar();
        $base = self::servidor();
        $dueno = self::propietario('dueno@example.test');
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $inv = $svc->invitar($dueno['id'], ['nombre' => 'Nueva', 'apellido' => 'Persona', 'email' => 'nueva@example.test', 'telefono' => '', 'puesto' => 'Caja', 'rol' => 'recepcion', 'fecha_ingreso' => ''], Idem::clave());
        $token = self::tokenDeInvitacion((int)self::escalar('SELECT MAX(id) FROM colaborador_invitaciones'));
        self::cliente(['email' => 'cliente1@example.test', 'nombre' => 'Clara', 'apellido' => 'Cliente']);
        self::$cfg = ['base' => $base, 'owner' => ['email' => $dueno['email'], 'password' => $dueno['password']], 'token' => $token, 'colId' => (int)$inv['colaborador_id'],
            'emailDobleClic' => 'doble@example.test', 'emailLento' => 'lento@example.test', 'emailSinJs' => 'sinjs@example.test', 'captura' => sys_get_temp_dir() . '/qa_equipo_320.png'];
        self::q('TRUNCATE intentos_login');
        $p = proc_open(['node', self::RAIZ . '/tests/Qa/frontend_qa.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['QA_CONFIG' => json_encode(self::$cfg), 'CHROME_BIN' => $chrome]));
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        proc_close($p);
        self::$r = json_decode($out, true) ?: ['error' => 'sin salida JSON: ' . $err . substr($out, 0, 300)];
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); }

    protected function setUp(): void
    {
        if (!self::$cfg) {
            $motivo = self::$motivoSinNavegador ?? 'Chromium o puppeteer-core no disponibles';
            // En CI (QA_EXIGIR_NAVEGADOR=1) la ausencia del navegador es un fallo, nunca una omisión silenciosa.
            if (getenv('QA_EXIGIR_NAVEGADOR') === '1') { $this->fail('QA_EXIGIR_NAVEGADOR=1: ' . $motivo); }
            $this->markTestSkipped($motivo);
        }
        $this->assertArrayNotHasKey('error', self::$r, self::$r['error'] ?? '');
    }

    public function test_sin_desbordamiento_horizontal_en_320_390_768_1440(): void
    {
        $malos = array_values(array_filter(self::$r['desbordamiento'], fn($d) => $d['malo']));
        $this->assertGreaterThanOrEqual(24, count(self::$r['desbordamiento']));
        foreach (self::$r['desbordamiento'] as $d) { $this->assertSame(200, $d['status'], "{$d['ruta']} @{$d['ancho']}"); }
        $this->assertSame([], array_map(fn($d) => "{$d['ruta']} @{$d['ancho']}px: scroll={$d['scrollWidth']} client={$d['clientWidth']} " . implode(',', $d['sobresale']), $malos));
    }

    public function test_sin_errores_de_consola_ni_recursos_rotos(): void
    {
        $this->assertSame([], array_map(fn($e) => "{$e['tipo']}: {$e['texto']} ({$e['url']})", self::$r['eventosConsola']));
    }

    public function test_guardian_de_envio_unico_emite_una_sola_peticion_y_crea_una_ficha(): void
    {
        $this->assertSame(1, self::$r['guardian']['postsAdminEquipoCrear'], 'doble clic + Enter produjeron más de una petición');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM colaboradores WHERE email_norm='doble@example.test'"));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE destinatario_email='doble@example.test'"));
    }

    public function test_guardian_comunica_el_estado_mientras_envia(): void
    {
        $e = self::$r['guardian']['estadoDurante'];
        $this->assertSame('true', $e['ariaBusy'], json_encode($e));
        $this->assertSame('true', $e['ariaDisabled']);
        $this->assertNotSame('', trim($e['estado']), 'el estado de envío no se anuncia (aria-live vacío)');
        $this->assertSame(1, $e['submitsTrasTresClics'], 'tras el primer envío, los clics siguientes no deben volver a enviar el formulario');
    }

    public function test_selector_de_rol_muestra_el_bloque_de_profesional_solo_cuando_corresponde(): void
    {
        $this->assertTrue(self::$r['guardian']['bloqueProfesionalOcultoConJs']);
        $this->assertTrue(self::$r['guardian']['bloqueProfesionalVisibleAlElegir']);
    }

    public function test_teclado_orden_de_foco_foco_visible_y_envio_con_enter(): void
    {
        $this->assertTrue(self::$r['teclado']['camposAlcanzables'], json_encode(self::$r['teclado']['secuencia']));
        $this->assertTrue(self::$r['teclado']['enfoqueVisible'], 'algún control enfocable por teclado no muestra indicador de foco: ' . json_encode(array_values(array_filter(self::$r['teclado']['secuencia'], fn($s) => !$s['contorno'] && !$s['sombra']))));
        $this->assertSame('/admin', self::$r['teclado']['loginConEnter']);
        $ids = array_column(self::$r['teclado']['secuencia'], 'id');
        $this->assertLessThan(array_search('password', $ids), array_search('email', $ids), 'el correo debe recibir foco antes que la contraseña');
    }

    public function test_sin_javascript_el_formulario_de_invitacion_sigue_siendo_utilizable(): void
    {
        $s = self::$r['sinJs'];
        $this->assertTrue($s['bloqueProfesionalVisible'], 'sin JS el bloque de profesional queda oculto/visible de forma inconsistente');
        $this->assertTrue($s['campoRolVisible']);
        $this->assertSame('/admin/equipo', $s['urlTrasEnviar']);
        $this->assertStringContainsString('Invitación', $s['mensaje']);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM colaboradores WHERE email_norm='sinjs@example.test'"));
        $this->assertTrue($s['aceptarCamposVisibles']);
    }
}
