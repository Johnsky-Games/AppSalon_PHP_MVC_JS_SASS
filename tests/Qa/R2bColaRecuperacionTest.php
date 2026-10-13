<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * Ronda 2B, FASE 2: reverificación del diseño «ningún correo de cuenta sale dentro de la petición HTTP». Registro, `/reenviar-confirmacion` y
 * `/olvide` solo ENCOLAN en `correos_salida` y los entrega el worker real (`scripts/procesar_correos.php`, en un proceso aparte). La
 * recuperación de contraseña pasó a la cola con el tipo `recuperacion_cuenta`. `CORREOS_ENVIO_INMEDIATO=1` reactiva el envío en línea y
 * solo es para pruebas: es el único interruptor.
 *
 * Se ataca: que ninguna ruta pública envíe en línea (con un SMTP lento y SIN esa variable), que el interruptor sea estricto, que la
 * recuperación entregue SIEMPRE el enlace vigente y nunca uno reemplazado, las carreras entre solicitudes simultáneas, un worker en vuelo,
 * la expiración del token, el bucle del supervisor `notificaciones_worker.php` y la ausencia de PII en los errores de la cola.
 * HTTP real (8150-8199), SMTP simulado propio (2595), procesos PHP concurrentes reales.
 */
final class R2bColaRecuperacionTest extends R2bCase
{
    private const SMTP = 2595;
    private static string $base;
    private static array $dueno;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::iniciarSmtpFallo(self::SMTP, 'ok');
        self::$base = self::servidor(['EMAIL_PORT' => (string)self::SMTP], 'r2b_rec');
        self::$dueno = self::propietario(self::correo('dueno'));
    }

    public static function tearDownAfterClass(): void
    {
        self::pararServidor('r2b_rec');
        self::detenerSmtpFallo(self::SMTP);
    }

    private static bool $lento = false;

    protected function setUp(): void
    {
        self::q('TRUNCATE intentos_login');
        self::q('DELETE FROM correos_salida');
        self::modoSmtp(self::SMTP, 'ok');
        self::vaciarBuzonFallo(self::SMTP);
    }

    protected function tearDown(): void
    {
        if (self::$lento) { self::$lento = false; self::detenerSmtpFallo(self::SMTP); self::iniciarSmtpFallo(self::SMTP, 'ok'); }
        putenv('EMAIL_PORT=2599');
    }

    // ------------------------------------------------------------------ utilidades

    private function olvide(string $email, ?Navegador $n = null): array
    {
        $n ??= new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        return $n->post('/olvide', ['csrf_token' => $n->csrf('/olvide'), 'email' => $email]);
    }

    /** @return array<int,array{to:array,cuerpo:string,cabeceras:string}> */
    private function mailsPara(string $email): array
    {
        return array_values(array_filter(self::buzonFallo(self::SMTP), static fn(array $m): bool => in_array($email, $m['to'], true)));
    }

    private function tokenRecuperacion(array $mail): ?string { return self::tokenEnCorreo($mail, '/recuperar'); }

    private function eventos(string $email, string $tipo = 'recuperacion_cuenta'): array
    {
        return self::q('SELECT * FROM correos_salida WHERE destinatario_email=? AND tipo=? ORDER BY id', [$email, $tipo]);
    }

    private function lento(float $seg): void
    {
        self::detenerSmtpFallo(self::SMTP);
        self::iniciarSmtpFallo(self::SMTP, 'lento', $seg);
        self::$lento = true;
    }

    private function clienteConfirmado(string $pref = 'rc'): array { return self::cliente(['email' => self::correo($pref)]); }

    // ------------------------------------------------------------------ 1. ninguna ruta pública envía en línea

    public function test_ninguna_ruta_publica_envia_correo_dentro_de_la_peticion_con_un_smtp_lento_y_sin_la_variable_de_envio_inmediato(): void
    {
        $this->lento(0.4);
        $conf = $this->clienteConfirmado('p-conf')['email'];
        $pend = self::cliente(['email' => self::correo('p-pend'), 'confirmado' => false])['email'];
        $staff = self::miembro(self::$dueno['id'], 'recepcion')['email'];
        self::q("DELETE FROM correos_salida"); // el alta del miembro encola su invitación: no es objeto de esta prueba
        $tiempos = [];
        $pasos = [
            'registro nuevo' => fn() => self::registrarHttp(new Navegador(self::$base), self::correo('p-nuevo')),
            'registro confirmado' => fn() => self::registrarHttp(new Navegador(self::$base), $conf),
            'registro pendiente' => fn() => self::registrarHttp(new Navegador(self::$base), $pend),
            'registro staff' => fn() => self::registrarHttp(new Navegador(self::$base), $staff),
            'alias crear-cuenta nuevo' => fn() => self::registrarHttp(new Navegador(self::$base), self::correo('p-alias'), [], '/crear-cuenta'),
            'olvide confirmado' => fn() => $this->olvide($conf),
            'olvide pendiente' => fn() => $this->olvide($pend),
            'olvide staff' => fn() => $this->olvide($staff),
            'olvide inexistente' => fn() => $this->olvide(self::correo('p-nadie')),
            'reenviar pendiente' => function () use ($pend) { $n = new Navegador(self::$base); self::q('TRUNCATE intentos_login'); return $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => $pend]); },
            'reenviar confirmado' => function () use ($conf) { $n = new Navegador(self::$base); self::q('TRUNCATE intentos_login'); return $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => $conf]); },
            'reenviar inexistente' => function () { $n = new Navegador(self::$base); self::q('TRUNCATE intentos_login'); return $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => self::correo('p-nadie2')]); },
        ];
        foreach ($pasos as $nombre => $f) {
            $t0 = microtime(true);
            $r = $f();
            $tiempos[$nombre] = (microtime(true) - $t0) * 1000;
            $this->assertContains($r['status'], [200, 302], "$nombre: " . substr(strip_tags($r['body']), 0, 160));
            $this->assertSame([], self::buzonFallo(self::SMTP), "$nombre: la petición envió un correo en línea");
            $this->assertLessThan(300, $tiempos[$nombre], sprintf('%s: %.0f ms; con un SMTP de 400 ms eso indica envío en línea', $nombre, $tiempos[$nombre]));
        }
        $this->assertGreaterThan(0.4 * 5, self::entregarCola(self::SMTP)['seg'], 'el SMTP lento debe estar activo: el worker paga la latencia por correo');
        // 3 altas/avisos que sobreviven + 2 recuperaciones + 1 confirmación reenviada (la del registro pendiente se cancela por el reenvío) = 7.
        $this->assertCount(7, self::buzonFallo(self::SMTP));
    }

    /** Interruptor ESTRICTO: solo 1/true/si/sí/on (sin importar mayúsculas ni espacios) activan el envío en línea; todo lo demás lo deja desactivado. */
    public function test_el_envio_inmediato_solo_se_activa_con_valores_explicitos_y_los_demas_no_filtran(): void
    {
        $sinEnvio = ['0', 'false', 'no', 'off', '', '2', 'yes', 'enabled', '1.0', '01', '-1', ' ', 'null'];
        foreach ($sinEnvio as $i => $valor) {
            $clave = 'r2b_inm_' . $i;
            $base = self::servidor(['EMAIL_PORT' => (string)self::SMTP, 'CORREOS_ENVIO_INMEDIATO' => $valor], $clave);
            try {
                self::vaciarBuzonFallo(self::SMTP);
                self::q('TRUNCATE intentos_login');
                $email = self::correo('inm');
                $r = self::registrarHttp(new Navegador($base), $email);
                $this->assertSame(302, $r['status']);
                $this->assertSame([], self::buzonFallo(self::SMTP), 'CORREOS_ENVIO_INMEDIATO=«' . $valor . '» no debería activar el envío en línea');
            } finally { self::pararServidor($clave); }
        }
        // Control positivo (demuestra que la prueba SÍ detecta el envío en línea): valores explícitos, con mayúsculas y espacios.
        foreach (['1', 'true', 'TRUE', ' on ', 'sí', 'Si'] as $i => $valor) {
            $clave = 'r2b_inmsi_' . $i;
            $base = self::servidor(['EMAIL_PORT' => (string)self::SMTP, 'CORREOS_ENVIO_INMEDIATO' => $valor], $clave);
            try {
                self::vaciarBuzonFallo(self::SMTP);
                self::q('TRUNCATE intentos_login');
                $email = self::correo('inm1');
                self::registrarHttp(new Navegador($base), $email);
                $this->assertCount(1, $this->mailsPara($email), 'CORREOS_ENVIO_INMEDIATO=«' . $valor . '» debería enviar en línea (control positivo)');
            } finally { self::pararServidor($clave); }
        }
    }

    public function test_con_el_envio_inmediato_activo_tambien_olvide_y_reenvio_envian_en_linea_y_dejan_el_evento_enviado(): void
    {
        $base = self::servidor(['EMAIL_PORT' => (string)self::SMTP, 'CORREOS_ENVIO_INMEDIATO' => '1'], 'r2b_rec_inm');
        try {
            $c = $this->clienteConfirmado('inm-rec')['email'];
            $this->olvide($c, new Navegador($base));
            $m = $this->mailsPara($c);
            $this->assertCount(1, $m, 'con la variable activa /olvide envía en línea');
            $ev = $this->eventos($c);
            $this->assertCount(1, $ev);
            $this->assertSame('enviado', $ev[0]['estado']);
            $this->assertNull($ev[0]['carga_cifrada'], 'al enviarse la carga (con el token) debe borrarse');
        } finally { self::pararServidor('r2b_rec_inm'); }
    }

    // ------------------------------------------------------------------ 2. recuperación por la cola

    public function test_olvide_encola_un_evento_cifrado_responde_igual_para_todas_las_cuentas_y_el_worker_entrega_el_enlace_que_funciona(): void
    {
        $conf = $this->clienteConfirmado('rec')['email'];
        $pend = self::cliente(['email' => self::correo('rec-pend'), 'confirmado' => false])['email'];
        $nadie = self::correo('rec-nadie');
        $firmas = [];
        foreach (['confirmado' => $conf, 'pendiente' => $pend, 'inexistente' => $nadie] as $rama => $e) {
            $r = $this->olvide($e);
            $f = self::firma($r);
            $f['body'] = str_replace($e, '<correo>', $f['body']);
            $f['headers']['content-length'] = null;
            $firmas[$rama] = $f;
        }
        $this->assertSame($firmas['inexistente'], $firmas['confirmado'], 'la respuesta distingue una cuenta existente de una inexistente');
        $this->assertSame($firmas['inexistente'], $firmas['pendiente'], 'la respuesta distingue una cuenta sin confirmar');
        $this->assertSame([], self::buzonFallo(self::SMTP));
        $this->assertCount(1, $this->eventos($conf), 'una cuenta confirmada deja su evento de recuperación');
        $this->assertSame([], $this->eventos($pend), 'una cuenta SIN confirmar no recibe recuperación');
        $this->assertSame([], $this->eventos($nadie), 'una cuenta inexistente no deja evento');
        $ev = $this->eventos($conf)[0];
        $this->assertSame('pendiente', $ev['estado']);
        $this->assertSame(0, (int)$ev['intentos']);
        $this->assertNotNull($ev['carga_cifrada']);
        // El token NUNCA en claro en la cola.
        $hash = (string)self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$conf]);
        $this->assertNotEmpty($hash);
        $this->assertMatchesRegularExpression('/^recuperacion:\d+:[a-f0-9]{24}$/', $ev['clave_unica'], 'la clave única no debe contener el token ni el correo');
        foreach ($ev as $col => $val) { $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/i', (string)$val, "correos_salida.$col contiene algo con forma de token"); }
        self::entregarCola(self::SMTP);
        $m = $this->mailsPara($conf);
        $this->assertCount(1, $m);
        $this->assertSame([], $this->mailsPara($pend));
        $tok = $this->tokenRecuperacion($m[0]);
        $this->assertNotNull($tok, substr($m[0]['cuerpo'], 0, 400));
        $this->assertSame($hash, hash('sha256', $tok), 'el enlace entregado es el vigente');
        $ev = $this->eventos($conf)[0];
        $this->assertSame('enviado', $ev['estado']);
        $this->assertNull($ev['carga_cifrada']);
        // El enlace sirve una vez y permite entrar con la contraseña nueva.
        $n = new Navegador(self::$base);
        $this->assertSame(200, $n->get('/recuperar?token=' . $tok)['status']);
        $csrf = Navegador::csrfDe($n->get('/recuperar?token=' . $tok)['body']);
        $r = $n->post('/recuperar?token=' . $tok, ['csrf_token' => $csrf, 'password' => 'Nueva-Clave-9999']);
        $this->assertSame(302, $r['status'], substr(strip_tags($r['body']), 0, 300));
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, (new Navegador(self::$base))->login('cliente', $conf, 'Nueva-Clave-9999')['status']);
        $this->assertSame(410, (new Navegador(self::$base))->get('/recuperar?token=' . $tok)['status'], 'el enlace es de un solo uso');
    }

    public function test_el_correo_de_recuperacion_de_personal_lleva_el_portal_del_equipo(): void
    {
        $staff = self::miembro(self::$dueno['id'], 'recepcion')['email'];
        self::q('DELETE FROM correos_salida');
        $this->olvide($staff);
        self::entregarCola(self::SMTP);
        $m = $this->mailsPara($staff);
        $this->assertCount(1, $m);
        $this->assertStringContainsString('portal=equipo', html_entity_decode($m[0]['cuerpo']), 'el enlace de recuperación del personal debe ir al portal del equipo');
    }

    // ------------------------------------------------------------------ 3. enlaces reemplazados: jamás se envía el viejo

    public function test_dos_olvide_antes_del_worker_cancelan_el_primero_y_solo_sale_el_enlace_vigente(): void
    {
        $c = $this->clienteConfirmado('dos')['email'];
        $this->olvide($c);
        $this->olvide($c);
        $this->olvide($c);
        $estados = array_column($this->eventos($c), 'estado');
        $this->assertSame(['cancelado', 'cancelado', 'pendiente'], $estados);
        foreach ($this->eventos($c) as $e) { if ($e['estado'] === 'cancelado') { $this->assertNull($e['carga_cifrada'], 'un evento cancelado debe borrar su carga'); } }
        $hash = (string)self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$c]);
        self::entregarCola(self::SMTP);
        $m = $this->mailsPara($c);
        $this->assertCount(1, $m, 'solo el último enlace sale');
        $this->assertSame($hash, hash('sha256', (string)$this->tokenRecuperacion($m[0])));
    }

    public function test_un_enlace_ya_entregado_queda_invalido_al_pedir_otro_y_el_nuevo_funciona(): void
    {
        $c = $this->clienteConfirmado('viejo')['email'];
        $this->olvide($c);
        self::entregarCola(self::SMTP);
        $a = (string)$this->tokenRecuperacion($this->mailsPara($c)[0]);
        $this->olvide($c);
        self::entregarCola(self::SMTP);
        $m = $this->mailsPara($c);
        $this->assertCount(2, $m);
        $b = (string)$this->tokenRecuperacion($m[1]);
        $this->assertNotSame($a, $b);
        $this->assertSame(410, (new Navegador(self::$base))->get('/recuperar?token=' . $a)['status'], 'el enlace anterior debe quedar invalidado');
        $this->assertSame(200, (new Navegador(self::$base))->get('/recuperar?token=' . $b)['status']);
    }

    public function test_el_token_de_recuperacion_vencido_o_la_cuenta_modificada_cancelan_el_evento_sin_enviar(): void
    {
        // 1) Token vencido antes de que corra el worker.
        $c = $this->clienteConfirmado('venc')['email'];
        $this->olvide($c);
        self::q("UPDATE usuarios SET token_expira = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE email=?", [$c]);
        self::entregarCola(self::SMTP);
        $this->assertSame([], $this->mailsPara($c), 'no debe enviarse un enlace vencido');
        $this->assertSame('cancelado', $this->eventos($c)[0]['estado']);
        $this->assertNull($this->eventos($c)[0]['carga_cifrada']);
        // 2) Contraseña cambiada por otra vía (token borrado) antes del envío.
        $d = $this->clienteConfirmado('cambio')['email'];
        $this->olvide($d);
        self::q("UPDATE usuarios SET token_hash=NULL, token_tipo=NULL, token_expira=NULL WHERE email=?", [$d]);
        self::entregarCola(self::SMTP);
        $this->assertSame([], $this->mailsPara($d));
        $this->assertSame('cancelado', $this->eventos($d)[0]['estado']);
        // 3) El token pasó a ser de OTRO propósito (p. ej. la cuenta se marcó sin confirmar y recibió un token de confirmación): no se envía la recuperación.
        $e = $this->clienteConfirmado('otro-tipo')['email'];
        $this->olvide($e);
        self::q("UPDATE usuarios SET token_tipo='confirmacion' WHERE email=?", [$e]);
        self::entregarCola(self::SMTP);
        $this->assertSame([], $this->mailsPara($e));
        // 4) La cuenta deja de estar confirmada: la recuperación solo existe para cuentas confirmadas.
        $f = $this->clienteConfirmado('desconf')['email'];
        $this->olvide($f);
        self::q("UPDATE usuarios SET confirmado=0 WHERE email=?", [$f]);
        self::entregarCola(self::SMTP);
        $this->assertSame([], $this->mailsPara($f));
        $this->assertSame('cancelado', $this->eventos($f)[0]['estado']);
        // (Un evento no se descarta por su edad sino por la vigencia del token: el de recuperación caduca a las 2 h, el de confirmación a las 24 h.)
    }

    public function test_un_enlace_de_confirmacion_y_uno_de_recuperacion_no_se_confunden_entre_si(): void
    {
        // Cuenta sin confirmar con evento de confirmación pendiente; si el token pasara a ser de recuperación, el worker no debe enviar la confirmación.
        $email = self::correo('mezcla');
        self::registrarHttp(new Navegador(self::$base), $email);
        self::q("UPDATE usuarios SET token_tipo='recuperacion' WHERE email=?", [$email]);
        self::entregarCola(self::SMTP);
        $this->assertSame([], $this->mailsPara($email), 'una confirmación cuyo token ya es de recuperación no debe enviarse');
    }

    // ------------------------------------------------------------------ 4. carreras

    public function test_ocho_olvide_simultaneos_dejan_un_solo_token_valido_y_el_worker_entrega_solo_ese_enlace(): void
    {
        $c = $this->clienteConfirmado('conc8')['email'];
        $uid = (int)self::escalar('SELECT id FROM usuarios WHERE email=?', [$c]);
        $t = [];
        for ($i = 0; $i < 8; $i++) { $t[] = ['accion' => 'olvideCola', 'args' => ['email' => $c, 'ip' => '10.40.0.' . ($i + 1), 'sinLimite' => true]]; }
        $res = self::concurrente($t, 1.5);
        foreach ($res as $i => $x) { $this->assertTrue($x['ok'] ?? false, "proceso $i: " . json_encode($x)); $this->assertSame('ok', $x['resultado']['status']); }
        $this->assertSame([], self::buzonFallo(self::SMTP), 'las peticiones no envían correo');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM usuarios WHERE id=? AND token_tipo='recuperacion' AND token_expira>UTC_TIMESTAMP()", [$uid]), 'exactamente un token vigente');
        $hash = (string)self::escalar('SELECT token_hash FROM usuarios WHERE id=?', [$uid]);
        $this->assertLessThanOrEqual(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE usuario_id=? AND tipo='recuperacion_cuenta' AND estado='pendiente'", [$uid]), 'a lo sumo UN evento pendiente: los demás deben haberse cancelado en la misma transacción');
        self::entregarCola(self::SMTP);
        $m = $this->mailsPara($c);
        $validos = array_filter($m, fn($x) => hash('sha256', (string)$this->tokenRecuperacion($x)) === $hash);
        $this->assertCount(1, $validos, 'el titular DEBE recibir el enlace vigente, una vez');
        $this->assertCount(1, $m, 'NO se debe entregar ningún enlace obsoleto: ' . count($m) . ' correos');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE usuario_id=? AND estado IN ('pendiente','enviando')", [$uid]));
    }

    /**
     * Ventana residual (at-least-once): un envío YA EN VUELO cuando llega una solicitud nueva sale con el enlace viejo. Se cuantifica con un SMTP
     * de 1,5 s por correo: el worker toma el evento A (arrendamiento), llega /olvide (token B) y el correo A termina entregándose. Lo que
     * SÍ debe cumplirse: el enlace vigente (B) también se entrega y el viejo (A) queda inválido; nunca más de un enlace válido.
     */
    public function test_un_envio_en_vuelo_puede_entregar_el_enlace_viejo_pero_solo_uno_sigue_vigente_y_se_entrega_tambien(): void
    {
        $this->lento(1.5);
        $c = $this->clienteConfirmado('vuelo')['email'];
        $this->olvide($c);
        $hashA = (string)self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$c]);
        self::adelantarEventos();
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/procesar_correos.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
            array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::SMTP, 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1']));
        // Espera a que el worker tome el evento (estado `enviando`).
        for ($i = 0; $i < 100 && (string)($this->eventos($c)[0]['estado'] ?? '') !== 'enviando'; $i++) { usleep(30000); }
        $this->assertSame('enviando', $this->eventos($c)[0]['estado'], 'el worker debía estar enviando');
        $this->olvide($c); // token B mientras A está en vuelo
        $hashB = (string)self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$c]);
        $this->assertNotSame($hashA, $hashB);
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        self::entregarCola(self::SMTP);
        $m = $this->mailsPara($c);
        $hashes = array_map(fn($x) => hash('sha256', (string)$this->tokenRecuperacion($x)), $m);
        $this->assertContains($hashB, $hashes, 'el enlace vigente (B) debe entregarse');
        $this->assertLessThanOrEqual(2, count($m), 'a lo sumo el enlace en vuelo (A) y el vigente (B)');
        $validos = array_filter($hashes, fn($h) => $h === $hashB);
        $this->assertCount(1, $validos, 'B se entrega una sola vez');
        $n = new Navegador(self::$base);
        foreach ($m as $x) {
            $tk = (string)$this->tokenRecuperacion($x);
            $esperado = hash('sha256', $tk) === $hashB ? 200 : 410;
            $this->assertSame($esperado, $n->get('/recuperar?token=' . $tk)['status'], 'solo el enlace vigente debe funcionar');
        }
    }

    /**
     * Atomicidad: emitir el token, encolar el correo y cancelar los eventos anteriores ocurren en UNA transacción. Si la cola falla (trigger que
     * rechaza el INSERT, o el UPDATE de cancelación), el token vigente NO se reemplaza (el enlace que el titular ya tenía sigue valiendo),
     * no queda evento huérfano y la respuesta no filtra el detalle SQL.
     */
    public function test_si_la_cola_falla_la_emision_del_token_se_revierte_completa_y_el_enlace_anterior_sigue_valido(): void
    {
        $c = $this->clienteConfirmado('atom')['email'];
        $this->olvide($c);
        self::entregarCola(self::SMTP);
        $a = (string)$this->tokenRecuperacion($this->mailsPara($c)[0]);
        $db = self::db();
        // Caso 1: el INSERT del evento falla. Caso 2: el INSERT funciona pero falla el UPDATE que cancela el evento pendiente anterior.
        foreach (['INSERT', 'UPDATE'] as $que) {
            if ($que === 'UPDATE') { $this->olvide($c); } // deja un evento PENDIENTE anterior (con su token vigente) que la cancelación tocaría
            $hashVigente = (string)self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$c]);
            $eventosAntes = count($this->eventos($c));
            $ddl = $que === 'INSERT'
                ? "CREATE TRIGGER trg_qa_cola_ins BEFORE INSERT ON correos_salida FOR EACH ROW SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT='cola rota a proposito'"
                : "CREATE TRIGGER trg_qa_cola_upd BEFORE UPDATE ON correos_salida FOR EACH ROW SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT='cola rota a proposito'";
            $this->assertTrue($db->query($ddl) === true, $db->error);
            try {
                $r = $this->olvide($c);
            } finally { $db->query('DROP TRIGGER IF EXISTS trg_qa_cola_ins'); $db->query('DROP TRIGGER IF EXISTS trg_qa_cola_upd'); }
            // Ante una falla real de la base de datos la aplicación responde 500 con un mensaje genérico (comportamiento previo de /olvide); lo que
            // importa es que no filtre el detalle y que la operación sea atómica.
            $this->assertContains($r['status'], [200, 500], "falla de la cola ($que)");
            $this->assertStringNotContainsString('cola rota', $r['body'], "falla de la cola ($que): se filtra el detalle SQL");
            $this->assertSame($hashVigente, (string)self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$c]), "falla de la cola ($que): el token vigente fue reemplazado pese al fallo");
            $this->assertSame($eventosAntes, count($this->eventos($c)), "falla de la cola ($que): cambió el número de eventos");
        }
        $this->assertSame(410, (new Navegador(self::$base))->get('/recuperar?token=' . $a)['status'], 'el primer enlace fue reemplazado legítimamente por el segundo /olvide (el exitoso)');
        // Tras reparar la cola todo vuelve a funcionar.
        $n0 = count($this->eventos($c));
        $this->olvide($c);
        $this->assertCount($n0 + 1, $this->eventos($c));
    }

    // ------------------------------------------------------------------ 5. errores de SMTP en la recuperación

    public function test_smtp_roto_en_la_recuperacion_reintenta_con_backoff_y_no_guarda_la_direccion(): void
    {
        $c = $this->clienteConfirmado('rec-err')['email'];
        $this->olvide($c);
        self::modoSmtp(self::SMTP, 'rcpt5xx');
        $w = self::worker([], ['EMAIL_PORT' => (string)self::SMTP]);
        $this->assertSame(0, $w['exit'], $w['out'] . $w['err']);
        $ev = $this->eventos($c)[0];
        $this->assertSame('pendiente', $ev['estado']);
        $this->assertSame(1, (int)$ev['intentos']);
        $this->assertNotEmpty($ev['ultimo_error']);
        $this->assertStringNotContainsString($c, (string)$ev['ultimo_error']);
        $this->assertStringNotContainsString(strstr($c, '@', true), (string)$ev['ultimo_error']);
        $this->assertEqualsWithDelta(60, (int)self::escalar('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), proximo_intento) FROM correos_salida WHERE id=?', [$ev['id']]), 4);
        // Cuando el SMTP vuelve, el MISMO enlace vigente se entrega.
        self::modoSmtp(self::SMTP, 'ok');
        $hash = (string)self::escalar('SELECT token_hash FROM usuarios WHERE email=?', [$c]);
        self::entregarCola(self::SMTP);
        $m = $this->mailsPara($c);
        $this->assertCount(1, $m);
        $this->assertSame($hash, hash('sha256', (string)$this->tokenRecuperacion($m[0])));
    }

    // ------------------------------------------------------------------ 6. supervisor notificaciones_worker.php

    public function test_el_supervisor_procesa_la_cola_de_correos_en_su_intervalo_y_rechaza_valores_invalidos(): void
    {
        $env = fn(array $extra): array => array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::SMTP, 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1', 'NOTIFICACIONES_INTERVALO_SEGUNDOS' => '600'], $extra);
        // Valores inválidos: salida 1 sin quedarse en bucle.
        foreach (['CORREOS_INTERVALO_SEGUNDOS' => ['1', '601', 'abc', '-3', '2.5']] as $var => $valores) {
            foreach ($valores as $v) {
                $p = proc_open([PHP_BINARY, self::RAIZ . '/scripts/notificaciones_worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $env([$var => $v]));
                $t0 = microtime(true);
                while (($st = proc_get_status($p))['running'] && microtime(true) - $t0 < 8) { usleep(50000); }
                $corre = $st['running'];
                if ($corre) { proc_terminate($p, 9); }
                stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                $codigo = proc_close($p);
                $this->assertFalse($corre, "$var=$v: el supervisor debía salir por valor inválido");
                $this->assertSame(1, $st['exitcode'], "$var=$v: $err");
            }
        }
        // Funcionamiento: la cola se procesa sin que nadie ejecute el worker a mano, en el intervalo pedido (2 s).
        $c = $this->clienteConfirmado('super')['email'];
        $this->olvide($c);
        $this->assertSame([], self::buzonFallo(self::SMTP));
        $p = proc_open([PHP_BINARY, self::RAIZ . '/scripts/notificaciones_worker.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $env(['CORREOS_INTERVALO_SEGUNDOS' => '2']));
        $t0 = microtime(true);
        try {
            while (count($this->mailsPara($c)) === 0 && microtime(true) - $t0 < 20) { usleep(200000); }
            $this->assertCount(1, $this->mailsPara($c), 'el supervisor no entregó la recuperación en 20 s con un intervalo de 2 s');
            $this->assertLessThan(10, microtime(true) - $t0, 'tardó demasiado para un intervalo de 2 s');
            // Un correo nuevo durante la ejecución también se atiende (el bucle sigue vivo).
            $d = $this->clienteConfirmado('super2')['email'];
            $this->olvide($d);
            $t1 = microtime(true);
            while (count($this->mailsPara($d)) === 0 && microtime(true) - $t1 < 20) { usleep(200000); }
            $this->assertCount(1, $this->mailsPara($d));
        } finally {
            // Parada ordenada por SIGTERM.
            proc_terminate($p, 15);
            $t2 = microtime(true);
            while (proc_get_status($p)['running'] && microtime(true) - $t2 < 10) { usleep(100000); }
            $sigue = proc_get_status($p)['running'];
            if ($sigue) { proc_terminate($p, 9); }
            fclose($pipes[2]);
            proc_close($p);
        }
        $this->assertFalse($sigue, 'el supervisor no terminó con SIGTERM en 10 s');
    }
}
