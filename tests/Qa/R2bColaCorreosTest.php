<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * Ronda 2B, ítem 4: cola de correos de confirmación (`correos_salida`) atacada con SMTP caído / colgado / con rechazos 4xx-5xx,
 * el worker REAL (`scripts/procesar_correos.php`) en procesos aparte, reenvíos concurrentes, workers muertos y duplicados.
 * SMTP propio con fallos en 2591 (rango reservado 2590-2599); HTTP real en 8150-8199.
 */
final class R2bColaCorreosTest extends R2bCase
{
    private const SMTP = 2591;
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::iniciarSmtpFallo(self::SMTP, 'ok');
        self::$base = self::servidor(['EMAIL_PORT' => (string)self::SMTP], 'r2b_cola');
    }

    public static function tearDownAfterClass(): void
    {
        self::pararServidor('r2b_cola');
        self::detenerSmtpFallo(self::SMTP);
    }

    protected function setUp(): void
    {
        self::q('TRUNCATE intentos_login');
        self::q('DELETE FROM correos_salida');
        $this->smtp('ok');
    }

    /** (Re)arranca el SMTP con el modo pedido: 'cerrado' lo detiene (puerto cerrado). */
    private function smtp(string $modo, float $retardo = 0.0): void
    {
        if ($modo === 'cerrado') { self::detenerSmtpFallo(self::SMTP); return; }
        if (!@fsockopen('127.0.0.1', self::SMTP, $e, $s, 0.2) || $retardo > 0) {
            self::detenerSmtpFallo(self::SMTP);
            self::iniciarSmtpFallo(self::SMTP, $modo, $retardo);
            return;
        }
        self::modoSmtp(self::SMTP, $modo);
        self::vaciarBuzonFallo(self::SMTP);
    }

    private function envW(): array { return ['EMAIL_PORT' => (string)self::SMTP]; }

    private function evento(string $email, string $tipo = 'confirmacion_cuenta'): ?array
    {
        return self::q('SELECT * FROM correos_salida WHERE destinatario_email=? AND tipo=? ORDER BY id DESC LIMIT 1', [$email, $tipo])[0] ?? null;
    }

    private function tokenDelBuzon(string $email): ?string
    {
        $t = null;
        foreach (self::buzonFallo(self::SMTP) as $m) { if (in_array($email, $m['to'], true)) { $t = self::tokenEnCorreo($m, '/confirmar-cuenta') ?? $t; } }
        return $t;
    }

    public static function modosDeFallo(): array
    {
        return [['cerrado'], ['cuelga'], ['rcpt4xx'], ['rcpt5xx'], ['data5xx']];
    }

    // ------------------------------------------------------------------ alta y reenvío con SMTP roto

    /** @dataProvider modosDeFallo */
    public function test_el_alta_con_smtp_roto_responde_igual_no_pierde_el_correo_y_el_worker_real_lo_entrega(string $modo): void
    {
        $this->smtp($modo);
        $email = self::correo('alta-' . $modo);
        $t0 = microtime(true);
        $r = self::registrarHttp(new Navegador(self::$base), $email);
        $seg = microtime(true) - $t0;
        $this->assertSame(302, $r['status'], "SMTP $modo: el alta no debe fallar: " . substr(strip_tags($r['body']), 0, 200));
        $this->assertSame('/mensaje', $r['location']);
        // CAMBIO DE CONTRATO (fase 2): la petición NO intenta el envío, así que ni siquiera un SMTP colgado puede retrasarla (antes: 10,11 s
        // con `cuelga`). El evento nace `pendiente` con 0 intentos y sin error; el primer intento lo hace el worker.
        $this->assertLessThan(2.0, $seg, "SMTP $modo: la petición no debe esperar al SMTP (tardó $seg s)");
        $ev = $this->evento($email);
        $this->assertNotNull($ev, 'el correo debe quedar en la cola');
        $this->assertSame('pendiente', $ev['estado']);
        $this->assertSame(0, (int)$ev['intentos'], 'la petición no debe contar ningún intento');
        $this->assertEmpty($ev['ultimo_error']);
        $this->assertNotNull($ev['carga_cifrada']);
        // Primer intento a cargo del worker real, con el SMTP roto: se cuenta y se reprograma.
        $w1 = self::worker([], $this->envW());
        $this->assertSame(0, $w1['exit'], $w1['out'] . $w1['err']);
        $ev = $this->evento($email);
        $this->assertSame('pendiente', $ev['estado']);
        $this->assertSame(1, (int)$ev['intentos'], 'el intento del worker debe contarse');
        $this->assertNotEmpty($ev['ultimo_error']);
        $this->assertStringNotContainsString($email, (string)$ev['ultimo_error']);
        if ($modo !== 'cerrado') { $this->assertSame([], self::buzonFallo(self::SMTP), "SMTP $modo: no debe haberse entregado nada"); }
        // El token jamás en claro en la cola ni en el error.
        $hash = self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$email]);
        $this->assertNotEmpty($hash);
        $this->assertStringNotContainsString($email . '|', (string)$ev['clave_unica']);
        // Recuperación con el worker REAL.
        $this->smtp('ok');
        self::adelantarEventos();
        $w = self::worker([], $this->envW());
        $this->assertSame(0, $w['exit'], $w['out'] . $w['err']);
        $ev = $this->evento($email);
        $this->assertSame('enviado', $ev['estado'], json_encode($ev));
        $this->assertNull($ev['carga_cifrada'], 'al enviar, la carga con el token debe borrarse');
        $tok = $this->tokenDelBuzon($email);
        $this->assertNotNull($tok, 'el correo recuperado debe contener el enlace');
        $this->assertSame($hash, hash('sha256', $tok), 'el enlace entregado debe ser el vigente');
        // La cuenta se confirma con el enlace del correo capturado y se puede entrar con la contraseña original.
        $n = new Navegador(self::$base);
        $this->assertSame(200, $n->get('/confirmar-cuenta?token=' . $tok)['status']);
        $this->assertSame(1, (int)self::escalar('SELECT confirmado FROM usuarios WHERE email=?', [$email]));
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, (new Navegador(self::$base))->login('cliente', $email, 'Abcdef123456-x')['status']);
        $this->assertSame(410, (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $tok)['status'], 'el enlace es de un solo uso');
    }

    /** @dataProvider modosDeFallo */
    public function test_el_reenvio_con_smtp_roto_no_falla_responde_igual_y_se_recupera(string $modo): void
    {
        $email = self::cliente(['email' => self::correo('re-' . $modo), 'confirmado' => false])['email'];
        $this->smtp($modo);
        $n = new Navegador(self::$base);
        $t0 = microtime(true);
        $r = $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => $email]);
        $seg = microtime(true) - $t0;
        $inex = (new Navegador(self::$base));
        $r2 = $inex->post('/reenviar-confirmacion', ['csrf_token' => $inex->csrf('/reenviar-confirmacion'), 'email' => self::correo('nadie')]);
        $this->assertSame(200, $r['status'], "SMTP $modo");
        $this->assertSame(self::firma($r2)['body'] === self::firma($r)['body'], true, 'misma pantalla para existente e inexistente');
        $this->assertLessThan(2.0, $seg, "SMTP $modo: el reenvío no debe esperar al SMTP (fase 2: solo encola)");
        $ev = $this->evento($email);
        $this->assertNotNull($ev);
        $this->assertSame('pendiente', $ev['estado']);
        $this->assertSame(0, (int)$ev['intentos']);
        $hash = self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$email]);
        $this->smtp('ok');
        self::adelantarEventos();
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $tok = $this->tokenDelBuzon($email);
        $this->assertNotNull($tok);
        $this->assertSame($hash, hash('sha256', $tok));
    }

    // ------------------------------------------------------------------ agotamiento, backoff, --estado, --reintentar

    public function test_backoff_exacto_agotamiento_fallido_estado_sin_pii_y_reintentar_manual(): void
    {
        $this->smtp('rcpt5xx');
        $email = self::correo('agot');
        self::registrarHttp(new Navegador(self::$base), $email);
        $id = (int)$this->evento($email)['id'];
        $esperas = [60, 120, 240, 480, 960];
        // CAMBIO DE CONTRATO (fase 2): la petición ya no intenta el envío (0 intentos). El intento nº 1 lo hace el worker y programa 60 s.
        $this->assertSame(0, (int)$this->evento($email)['intentos']);
        $w0 = self::worker([], $this->envW());
        $this->assertSame(0, $w0['exit'], $w0['out'] . $w0['err']);
        $this->assertSame(1, (int)$this->evento($email)['intentos']);
        $this->assertEqualsWithDelta(60, (int)self::escalar('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), proximo_intento) FROM correos_salida WHERE id=?', [$id]), 4);
        for ($n = 2; $n <= 6; $n++) {
            self::adelantarEventos();
            $w = self::worker([], $this->envW());
            $this->assertSame(0, $w['exit'], $w['out'] . $w['err']);
            $e = self::q('SELECT estado, intentos, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), proximo_intento) espera FROM correos_salida WHERE id=?', [$id])[0];
            $this->assertSame($n, (int)$e['intentos']);
            if ($n < 6) {
                $this->assertSame('pendiente', $e['estado']);
                $this->assertEqualsWithDelta($esperas[$n - 1], (int)$e['espera'], 4, "espera tras el fallo $n");
            } else {
                $this->assertSame('fallido', $e['estado'], 'el 6.º fallo es terminal');
                $this->assertStringContainsString('ATENCIÓN', $w['err'], 'el worker debe avisar por STDERR de que hay fallidos');
            }
        }
        // El procesamiento normal ya no lo toca.
        self::q('UPDATE correos_salida SET proximo_intento = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id=?', [$id]);
        $this->smtp('ok');
        $w = self::worker([], $this->envW());
        $this->assertSame([], self::buzonFallo(self::SMTP), 'un evento fallido no se envía por el procesamiento normal');
        $this->assertSame('fallido', $this->evento($email)['estado']);
        // --estado: código 3, y SIN datos personales (ni destinatario, ni nombre, ni token).
        $est = self::worker(['--estado'], $this->envW());
        $estJ = self::worker(['--estado', '--json'], $this->envW());
        $this->assertSame(3, $est['exit']);
        $this->assertSame(3, $estJ['exit']);
        $tokenCifrado = (string)self::escalar('SELECT carga_cifrada FROM correos_salida WHERE id=?', [$id]);
        foreach ([$est['out'] . $est['err'], $estJ['out'] . $estJ['err']] as $salida) {
            $this->assertStringNotContainsString('Ana', $salida, '--estado imprime el nombre');
            $this->assertStringNotContainsString($tokenCifrado, $salida);
            $this->assertStringContainsString('FALLIDO', strtoupper($salida) . (str_contains($salida, 'fallidos') ? 'FALLIDO' : ''));
        }
        $this->assertJson(trim($estJ['out']));
        // --reintentar: id inexistente => 1; basura => 2; válido => pendiente con 0 intentos y se entrega.
        $this->assertSame(1, self::worker(['--reintentar=999999'], $this->envW())['exit']);
        $this->assertSame(2, self::worker(['--reintentar=abc'], $this->envW())['exit']);
        $this->assertSame(2, self::worker(['--reintentar=1;DROP'], $this->envW())['exit']);
        $this->assertSame(0, self::worker(['--reintentar=' . $id], $this->envW())['exit']);
        $e = $this->evento($email);
        $this->assertSame('pendiente', $e['estado']);
        $this->assertSame(0, (int)$e['intentos']);
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $this->assertSame('enviado', $this->evento($email)['estado']);
        $this->assertCount(1, self::buzonFallo(self::SMTP));
    }

    /**
     * Registra `$prefijo.<aleatorio>@example.test` con el SMTP en `rcpt5xx` (que repite la dirección en su rechazo), lleva el evento
     * hasta `fallido` con el worker real y devuelve dónde aparece la dirección o un fragmento de ella. null = la aplicación no admite
     * esa dirección. Busca en TODAS las columnas de correos_salida (salvo el destinatario), en `--estado` (texto y JSON), en la salida
     * de error del worker, en el log del servidor web y en el motivo de un evento cancelado.
     * @return string[]|null
     */
    private function fugasDeDireccion(string $prefijo): ?array
    {
        $this->smtp('rcpt5xx');
        self::q('DELETE FROM correos_salida');
        $email = self::correo($prefijo);
        self::q('TRUNCATE intentos_login');
        $r = self::registrarHttp(new Navegador(self::$base), $email);
        if ($r['status'] !== 302) { return null; }
        $stderr = self::worker([], $this->envW())['err'];
        $this->assertSame(1, (int)$this->evento($email)['intentos']);
        for ($i = 0; $i < 5; $i++) { self::adelantarEventos(); $stderr .= self::worker([], $this->envW())['err']; }
        $this->assertSame('fallido', $this->evento($email)['estado']);
        $local = strstr($email, '@', true);
        $buscar = [$email, $local, strstr($email, '@'), substr($local, 0, 3), $prefijo]; // fragmentos: un saneador parcial deja el comienzo
        $est = self::worker(['--estado'], $this->envW());
        $estJ = self::worker(['--estado', '--json'], $this->envW());
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => $email]);
        $sitios = [];
        foreach (self::q('SELECT * FROM correos_salida') as $fila) {
            foreach ($fila as $col => $val) { $sitios["correos_salida.$col (estado {$fila['estado']})"] = (string)$val; }
        }
        unset($sitios['correos_salida.destinatario_email (estado fallido)'], $sitios['correos_salida.destinatario_nombre (estado fallido)']);
        foreach (array_keys($sitios) as $k) { if (str_contains($k, '.destinatario_email') || str_contains($k, '.destinatario_nombre')) { unset($sitios[$k]); } }
        $sitios['--estado'] = $est['out'] . $est['err']; $sitios['--estado --json'] = $estJ['out'] . $estJ['err'];
        $sitios['stderr del worker'] = $stderr; $sitios['log del servidor web'] = self::logServidor('r2b_cola');
        $fugas = [];
        foreach ($sitios as $donde => $texto) {
            foreach ($buscar as $b) { if ($b !== '' && stripos($texto, $b) !== false) { $fugas[] = "«{$b}» en $donde: " . substr($texto, 0, 300); break; } }
        }
        return $fugas;
    }

    /** R2B-03 REVERIFICADO (antes `test_Defecto_R2B_03_…`): direcciones corrientes no se guardan ni se imprimen en ningún sitio. */
    public function test_R2B_03_la_direccion_del_destinatario_no_se_guarda_en_errores_ni_se_imprime(): void
    {
        foreach (['pii-err', 'pii+etiqueta', 'PII-Mayus', 'pii_guion.bajo'] as $prefijo) {
            $fugas = $this->fugasDeDireccion($prefijo);
            $this->assertNotNull($fugas, "la aplicación debería admitir la dirección «{$prefijo}…»");
            $this->assertSame([], $fugas, "dirección «{$prefijo}…»");
        }
    }

    /**
     * R2B-03b (RESIDUO, rojo): el saneador de `Email::sanearMensajeError` usa `[A-Za-z0-9._%+\-]+@…`; las direcciones válidas con otros
     * caracteres RFC 5322 en la parte local que la aplicación SÍ admite (`'`, `&`, `=`, `!`, `#`, `$`, `*`, `/`, `?`, `^`, `` ` ``, `{`,
     * `|`, `}`, `~`) quedan a medias: p. ej. `pii'ap.1.2.abc@example.test` se guarda como `pii'[correo]`, es decir el comienzo de la
     * dirección sigue en `ultimo_error` y se imprime en `--estado`.
     */
    public function test_Defecto_R2B_03b_el_saneador_deja_el_comienzo_de_las_direcciones_con_caracteres_especiales(): void
    {
        $admitidas = 0; $fugas = [];
        foreach (["pii'ap", 'pii&a=b', 'pii!x', 'pii#x', 'pii$x', 'pii*x', 'pii/x', 'pii?x', 'pii^x', 'pii`x', 'pii{x', 'pii|x', 'pii}x', 'pii~x'] as $prefijo) {
            $f = $this->fugasDeDireccion($prefijo);
            if ($f === null) { continue; }
            $admitidas++;
            if ($f) { $fugas[$prefijo] = $f[0]; }
        }
        $this->assertGreaterThan(0, $admitidas, 'la aplicación no admite ninguna dirección con caracteres especiales; la prueba no aplica');
        $this->assertSame([], $fugas, "$admitidas direcciones admitidas; el saneador deja fragmentos de: " . implode(', ', array_keys($fugas)));
    }

    public function test_el_worker_corta_la_tanda_tras_tres_fallos_seguidos_y_deja_intactos_los_demas(): void
    {
        $this->smtp('cerrado');
        $correos = [];
        for ($i = 0; $i < 6; $i++) { $correos[] = $c = self::correo('tanda'); self::q('TRUNCATE intentos_login'); self::registrarHttp(new Navegador(self::$base), $c); }
        self::adelantarEventos();
        $antes = self::q("SELECT id, intentos FROM correos_salida WHERE tipo='confirmacion_cuenta' ORDER BY id");
        $w = self::worker([], $this->envW());
        $this->assertSame(0, $w['exit']);
        $this->assertStringContainsString('Tanda interrumpida', $w['out']);
        $despues = self::q("SELECT id, intentos FROM correos_salida WHERE tipo='confirmacion_cuenta' ORDER BY id");
        $tocados = 0;
        foreach ($despues as $i => $f) { if ((int)$f['intentos'] > (int)$antes[$i]['intentos']) { $tocados++; } }
        $this->assertSame(3, $tocados, 'solo 3 intentos con el SMTP caído; el resto queda intacto para el siguiente ciclo');
    }

    // ------------------------------------------------------------------ concurrencia y vigencia del enlace

    public function test_reenvios_concurrentes_ocho_procesos_dejan_un_solo_token_valido_y_a_lo_sumo_un_evento_activo(): void
    {
        $email = self::cliente(['email' => self::correo('conc8'), 'confirmado' => false])['email'];
        $uid = (int)self::escalar('SELECT id FROM usuarios WHERE email=?', [$email]);
        putenv('EMAIL_PORT=' . self::SMTP);
        try {
            $t = [];
            for ($i = 0; $i < 8; $i++) { $t[] = ['accion' => 'reenviarCola', 'args' => ['email' => $email, 'ip' => '10.30.0.' . ($i + 1), 'smtp' => true, 'sinLimite' => true]]; }
            $res = self::concurrente($t, 1.5);
        } finally { putenv('EMAIL_PORT=2599'); }
        foreach ($res as $i => $x) { $this->assertTrue($x['ok'] ?? false, "proceso $i: " . json_encode($x)); $this->assertSame('ok', $x['resultado']['status']); }
        // Fase 2: hasta 8 eventos pendientes (uno por reenvío) antes del worker; los de enlaces reemplazados se cancelan al despachar.
        $this->assertSame([], self::buzonFallo(self::SMTP), 'las peticiones no envían correo');
        $hashVigente = (string)self::escalar('SELECT token_hash FROM usuarios WHERE id=?', [$uid]);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM usuarios WHERE id=? AND token_tipo='confirmacion' AND token_expira>NOW()", [$uid]), 'exactamente un token válido');
        // Cierra la cola con el worker real y revisa lo realmente entregado.
        self::adelantarEventos();
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $entregados = [];
        foreach (self::buzonFallo(self::SMTP) as $m) { if (in_array($email, $m['to'], true)) { $entregados[] = self::tokenEnCorreo($m, '/confirmar-cuenta'); } }
        $validos = array_filter($entregados, static fn($tk) => $tk !== null && hash('sha256', $tk) === $hashVigente);
        $obsoletos = count($entregados) - count($validos);
        $this->assertCount(1, $validos, 'el titular DEBE recibir el enlace vigente, una sola vez: ' . json_encode(['entregados' => count($entregados)]));
        // CAMBIO DE CONTRATO (fase 2): antes los reenvíos concurrentes entregaban hasta 7 enlaces ya invalidados (cada petición enviaba
        // el suyo en línea). Ahora solo se encola y el worker comprueba la vigencia al despachar: NUNCA se entrega un enlace obsoleto.
        $this->assertSame(0, $obsoletos, 'se entregaron enlaces ya invalidados: ' . count($entregados) . ' correos');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE usuario_id=? AND tipo='confirmacion_cuenta' AND estado IN ('pendiente','enviando')", [$uid]), 'tras el worker no queda ningún evento activo');
        $this->assertSame(200, (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . reset($validos))['status']);
    }

    public function test_reenviar_sucesivo_cancela_el_evento_anterior_y_jamas_envia_un_enlace_reemplazado(): void
    {
        $this->smtp('cerrado');
        $email = self::cliente(['email' => self::correo('reem'), 'confirmado' => false])['email'];
        foreach ([1, 2, 3] as $i) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => $email]);
        }
        $estados = array_column(self::q("SELECT estado, COUNT(*) n FROM correos_salida WHERE destinatario_email=? AND tipo='confirmacion_cuenta' GROUP BY estado", [$email]), 'n', 'estado');
        ksort($estados);
        $this->assertSame(['cancelado' => 2, 'pendiente' => 1], $estados);
        $this->assertSame(2, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE destinatario_email=? AND estado='cancelado' AND carga_cifrada IS NULL", [$email]), 'los cancelados deben borrar su carga');
        $this->smtp('ok');
        self::adelantarEventos();
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $msgs = array_values(array_filter(self::buzonFallo(self::SMTP), static fn($m) => in_array($email, $m['to'], true)));
        $this->assertCount(1, $msgs, 'solo el último enlace sale');
        $this->assertSame(hash('sha256', self::tokenEnCorreo($msgs[0], '/confirmar-cuenta')), self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$email]));
    }

    public function test_cuenta_confirmada_por_otra_via_cancela_el_evento_pendiente_sin_enviar(): void
    {
        $this->smtp('cerrado');
        $email = self::correo('porotra');
        self::registrarHttp(new Navegador(self::$base), $email);
        $this->assertSame('pendiente', $this->evento($email)['estado']);
        self::q('UPDATE usuarios SET confirmado=1, token_hash=NULL, token_tipo=NULL, token_expira=NULL WHERE email=?', [$email]);
        $this->smtp('ok');
        self::adelantarEventos();
        $w = self::worker([], $this->envW());
        $this->assertSame(0, $w['exit']);
        $this->assertSame('cancelado', $this->evento($email)['estado']);
        $this->assertSame([], self::buzonFallo(self::SMTP));
    }

    public function test_evento_vencido_24h_se_cancela_y_no_se_envia(): void
    {
        $this->smtp('cerrado');
        $email = self::correo('venc');
        self::registrarHttp(new Navegador(self::$base), $email);
        self::q("UPDATE usuarios SET token_expira = NOW() - INTERVAL 1 MINUTE WHERE email=?", [$email]);
        $this->smtp('ok');
        self::adelantarEventos();
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $this->assertSame('cancelado', $this->evento($email)['estado']);
        $this->assertSame([], self::buzonFallo(self::SMTP));
    }

    // ------------------------------------------------------------------ lease, workers muertos y dos workers

    public function test_lease_vencido_lo_retoma_otro_worker_y_lease_vigente_no(): void
    {
        $this->smtp('cerrado');
        $email = self::correo('lease');
        self::registrarHttp(new Navegador(self::$base), $email);
        $id = (int)$this->evento($email)['id'];
        $this->smtp('ok');
        // Simula un worker que murió con el evento tomado: lease vigente => nadie lo toca.
        self::q("UPDATE correos_salida SET estado='enviando', worker_token='muerto', lease_expires_at = UTC_TIMESTAMP() + INTERVAL 100 SECOND WHERE id=?", [$id]);
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $this->assertSame([], self::buzonFallo(self::SMTP), 'con lease vigente nadie debe tomar el evento');
        $this->assertSame('enviando', $this->evento($email)['estado']);
        // Lease vencido => lo retoma y lo envía.
        self::q("UPDATE correos_salida SET lease_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id=?", [$id]);
        $est = self::worker(['--estado'], $this->envW());
        $this->assertStringContainsString('lease vencido', $est['out']);
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $this->assertSame('enviado', $this->evento($email)['estado']);
        $this->assertCount(1, self::buzonFallo(self::SMTP));
    }

    public function test_worker_matado_a_mitad_del_envio_el_duplicado_es_posible_pero_con_el_mismo_enlace_vigente(): void
    {
        $this->smtp('cerrado');
        $email = self::correo('kill');
        self::registrarHttp(new Navegador(self::$base), $email);
        $id = (int)$this->evento($email)['id'];
        $hash = self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$email]);
        // SMTP que guarda el mensaje y tarda 6 s en confirmar: el worker muere después de que el servidor lo aceptó.
        $this->smtp('lento', 6.0);
        self::adelantarEventos();
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/procesar_correos.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
            array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::SMTP, 'EMAIL_FORCE_SMTP' => '1']));
        $t0 = microtime(true);
        while (count(self::buzonFallo(self::SMTP)) < 1 && microtime(true) - $t0 < 20) { usleep(100000); }
        $this->assertCount(1, self::buzonFallo(self::SMTP), 'el SMTP debe haber recibido el mensaje antes de matar al worker');
        $st = proc_get_status($p);
        posix_kill($st['pid'], 9);
        proc_close($p);
        $e = $this->evento($email);
        $this->assertSame('enviando', $e['estado'], 'el worker murió: el evento queda con lease, sin marcarse enviado');
        // Nadie puede retomarlo hasta que venza el lease (120 s)... (el buzón conserva la primera copia: no se vacía)
        self::modoSmtp(self::SMTP, 'ok');
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $this->assertSame('enviando', $this->evento($email)['estado']);
        // ...y al vencer, otro worker lo reenvía: DUPLICADO (límite documentado «al menos una vez»), con el mismo enlace vigente.
        self::q("UPDATE correos_salida SET lease_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id=?", [$id]);
        $this->assertSame(0, self::worker([], $this->envW())['exit']);
        $this->assertSame('enviado', $this->evento($email)['estado']);
        $recibidos = self::buzonFallo(self::SMTP);
        $this->assertCount(2, $recibidos, 'DUPLICADO: el servidor aceptó el 1.º, el worker murió antes de registrarlo y otro lo reenvió (al menos una vez, no exactamente una vez)');
        foreach ($recibidos as $m) { $this->assertSame($hash, hash('sha256', self::tokenEnCorreo($m, '/confirmar-cuenta')), 'ambas copias deben llevar el mismo enlace vigente'); }
    }

    public function test_dos_workers_simultaneos_envian_cada_evento_exactamente_una_vez_con_smtp_sano(): void
    {
        $this->smtp('cerrado');
        $emails = [];
        for ($i = 0; $i < 10; $i++) { $emails[] = $c = self::correo('dos'); self::q('TRUNCATE intentos_login'); self::registrarHttp(new Navegador(self::$base), $c); }
        $this->smtp('ok');
        self::adelantarEventos();
        $procs = [];
        for ($i = 0; $i < 2; $i++) {
            $procs[] = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/procesar_correos.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
                array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::SMTP, 'EMAIL_FORCE_SMTP' => '1']));
        }
        foreach ($procs as $p) { proc_close($p); }
        // Un tercer pase recoge lo que un lease pudiera haber dejado.
        self::worker([], $this->envW());
        $porDestino = [];
        foreach (self::buzonFallo(self::SMTP) as $m) { $porDestino[$m['to'][0]] = ($porDestino[$m['to'][0]] ?? 0) + 1; }
        foreach ($emails as $e) {
            $this->assertSame(1, $porDestino[$e] ?? 0, "$e: se esperaba exactamente una entrega con dos workers y SMTP sano");
            $this->assertSame('enviado', $this->evento($e)['estado']);
        }
    }

    // ------------------------------------------------------------------ el token jamás en claro

    public function test_el_token_no_aparece_en_claro_en_la_cola_los_logs_ni_los_errores(): void
    {
        $this->smtp('rcpt4xx');
        $email = self::correo('claro');
        self::registrarHttp(new Navegador(self::$base), $email);
        self::adelantarEventos();
        $w = self::worker([], $this->envW());
        $this->smtp('ok');
        self::adelantarEventos();
        $w2 = self::worker([], $this->envW());
        $tok = $this->tokenDelBuzon($email);
        $this->assertNotNull($tok);
        $filas = self::q('SELECT * FROM correos_salida');
        $this->assertNotEmpty($filas);
        foreach ($filas as $f) { $this->assertStringNotContainsString($tok, json_encode($f), 'token en claro dentro de correos_salida #' . $f['id']); $this->assertStringNotContainsString(hash('sha256', $tok), json_encode($f)); }
        foreach ([$w, $w2] as $x) { $this->assertStringNotContainsString($tok, $x['out'] . $x['err']); }
        $this->assertStringNotContainsString($tok, self::logServidor('r2b_cola'));
        // Tampoco en ninguna otra tabla de la base (búsqueda en todas las columnas de texto).
        foreach (self::q("SELECT table_name t, column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND data_type IN ('varchar','text','longtext','mediumtext','json','char') AND table_name NOT IN ('migraciones')") as $col) {
            if ($col['t'] === 'usuarios' && $col['c'] === 'token_hash') { continue; }
            $n = (int)self::escalar("SELECT COUNT(*) FROM `{$col['t']}` WHERE `{$col['c']}` LIKE ?", ['%' . $tok . '%']);
            $this->assertSame(0, $n, "token en claro en {$col['t']}.{$col['c']}");
        }
    }

    // ------------------------------------------------------------------ garantía documentada

    public function test_los_documentos_no_prometen_entrega_exactamente_una_vez(): void
    {
        $prohibidas = '/(entrega|env[ií]o|correo)[^.\n]{0,60}(exactamente una vez|exactly[- ]once)(?![^.\n]{0,40}no )/iu';
        foreach (glob(self::RAIZ . '/docs/*.md') as $doc) {
            if (str_contains($doc, 'QA_INDEPENDIENTE')) { continue; }
            foreach (preg_split('/\R/', (string)file_get_contents($doc)) as $n => $linea) {
                if (preg_match('/exactamente una vez|exactly[- ]once/i', $linea)) {
                    $this->assertMatchesRegularExpression('/no\b[^.]{0,80}(exactamente una vez|exactly[- ]once)|(exactamente una vez|exactly[- ]once)[^.]{0,60}(imposible|no se garantiza|no garantiza)|imposible[^.]{0,80}exactly|al menos una vez, no exactamente/iu', $linea, basename($doc) . ':' . ($n + 1) . ' parece prometer entrega exactamente-una-vez: ' . trim($linea));
                }
            }
        }
        $this->assertTrue(true);
    }

    public function test_la_linea_de_comandos_del_worker_resiste_argumentos_hostiles(): void
    {
        $email = self::correo('cli-cmd');
        $this->smtp('cerrado');
        self::registrarHttp(new Navegador(self::$base), $email);
        $this->smtp('ok');
        $antes = self::q('SELECT id, estado, intentos FROM correos_salida ORDER BY id');
        foreach ([['--nope'], ['basura'], ['--reintentar=0'], ['--reintentar=-3'], ['--reintentar=1e3'], ['--reintentar=todos'], ['--reintentar=9999999999999999999999'], ['--limite=1', '--zzz'], ["--arg\x01rojo"], ['--' . str_repeat('a', 5000)]] as $args) {
            $w = self::worker($args, $this->envW());
            $this->assertSame(2, $w['exit'], json_encode($args) . ' => ' . $w['out'] . $w['err']);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F]/', $w['err'], 'el mensaje de error refleja caracteres de control del argumento');
            $this->assertLessThan(400, strlen($w['err']), 'el mensaje de error refleja el argumento completo');
        }
        $this->assertSame($antes, self::q('SELECT id, estado, intentos FROM correos_salida ORDER BY id'), 'un argumento inválido modificó la cola');
        // Límites numéricos: se acotan, no fallan.
        foreach (['--limite=-5', '--limite=abc', '--limite=0', '--limite=99999999999999999999'] as $lim) {
            $w = self::worker([$lim], $this->envW());
            $this->assertSame(0, $w['exit'], "$lim => " . $w['out'] . $w['err']);
        }
        // --estado manda sobre --reintentar y no reactiva nada.
        self::q("UPDATE correos_salida SET estado='fallido', intentos=6 WHERE destinatario_email=?", [$email]);
        $w = self::worker(['--estado', '--reintentar=todos-fallidos'], $this->envW());
        $this->assertSame(3, $w['exit']);
        $this->assertSame('fallido', self::escalar('SELECT estado FROM correos_salida WHERE destinatario_email=?', [$email]));
        // Un id inexistente o ya entregado no reactiva nada (exit 1) y 'todos-fallidos' solo toca fallidos con carga.
        self::q("UPDATE correos_salida SET carga_cifrada=NULL WHERE destinatario_email=?", [$email]);
        $w = self::worker(['--reintentar=todos-fallidos'], $this->envW());
        $this->assertSame(0, $w['exit']);
        $this->assertStringContainsString('reactivados: 0', $w['out']);
        $this->assertSame('fallido', self::escalar('SELECT estado FROM correos_salida WHERE destinatario_email=?', [$email]));
    }
}
