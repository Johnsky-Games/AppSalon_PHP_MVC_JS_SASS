<?php

namespace Tests\Qa;

require_once __DIR__ . '/R3Case.php';

/**
 * Ronda 3, fases A y B: tres hallazgos del usuario reproducidos de forma independiente contra el código actual. Las pruebas `test_R3_*` están
 * ROJAS hasta que se integre la corrección y quedarán verdes con ella; las `test_R3_*` (sin «Defecto») son controles que ya pasan y deben seguir
 * pasando.
 *
 *  (1) Toma de cuenta por registro previo: quien registra primero el correo del titular fija la contraseña A; cuando el titular activa desde su buzón
 *      la cuenta queda confirmada CON LA CONTRASEÑA DEL ATACANTE. Contrato nuevo: «quien demuestra control del buzón establece la contraseña definitiva».
 *  (2) Confirmación por GET: un GET (escáner de enlaces, prefetch, bot) no debe activar la cuenta ni consumir el token; la activación exige POST con CSRF.
 *  (3) CORREOS_ENVIO_INMEDIATO=1 solo debe tener efecto con APP_ENV explícito de desarrollo/pruebas.
 *
 * La activación se realiza de forma GENÉRICA (válida antes y después de la corrección): se abre el enlace con GET; si la respuesta trae un formulario
 * con campos de contraseña se rellena con una contraseña nueva C y se envía por POST a su `action` (campos ocultos, CSRF y clave de operación
 * incluidos tal como vienen); si no hay formulario (diseño antiguo) se sigue con lo que haya.
 *
 * HTTP real (8150-8199, servidor con 8 procesos), SMTP simulado propio (2593) y el worker real `scripts/procesar_correos.php`.
 */
final class R3Test extends R3Case
{

    // ================================================================== (1) toma de cuenta por registro previo

    public function test_R3_01_la_contrasena_del_atacante_no_inicia_sesion_tras_activar_el_titular(): void
    {
        $email = self::correo('titular');
        $this->registrar($email, self::A);          // el atacante registra el correo ajeno con la contraseña A
        $this->registrar($email, self::B);          // el titular intenta registrarse: respuesta uniforme, no pisa A (DEF-13)
        $this->assertSame(0, (int)$this->fila($email)['confirmado']);
        $this->assertNoEntra($email, self::A, 'A del atacante (antes de activar)');
        $token = $this->ultimoToken($email);
        $this->assertNotNull($token, 'el titular debe recibir el enlace en su buzón');
        $act = $this->activar($token, self::C);
        $this->assertSame(1, (int)$this->fila($email)['confirmado'], 'tras la activación la cuenta debe quedar activa');
        $this->assertNoEntra($email, self::A, 'A del atacante, DESPUÉS de que el titular activó su cuenta');
        $this->assertNoEntra($email, self::B, 'B del titular (enviada en un registro, nunca elegida en el buzón)');
        $this->assertEntra($email, self::C, 'C elegida en el buzón');
        $this->assertNotNull($act['form'], 'DISEÑO NUEVO: abrir el enlace debe mostrar un formulario para ELEGIR la contraseña (el GET del diseño antiguo activa con la contraseña del registro previo). Respuesta GET: ' . $act['get']['status']);
    }

    public function test_R3_02_reregistro_y_reenvio_antes_y_despues_no_dejan_entrar_a_la_contrasena_del_atacante(): void
    {
        $email = self::correo('titular2');
        $this->registrar($email, self::A);
        $viejo = $this->ultimoToken($email);
        $this->assertNotNull($viejo);
        $this->reenviar($email);                     // el titular pide otro enlace
        $this->registrar($email, self::B);           // y vuelve a registrarse
        $this->reenviar($email);
        $nuevo = $this->ultimoToken($email);
        $this->assertNotNull($nuevo);
        $this->assertNotSame($viejo, $nuevo);
        // El enlace viejo ya no vale (ni activa ni muestra formulario).
        $v = $this->activar($viejo, 'Intruso-Clave-444');
        $this->assertNull($v['form'], 'el enlace viejo no debe ofrecer formulario');
        $this->assertSame(0, (int)$this->fila($email)['confirmado'], 'el enlace viejo no debe activar');
        $act = $this->activar($nuevo, self::C);
        $this->assertSame(1, (int)$this->fila($email)['confirmado']);
        $this->assertNoEntra($email, self::A, 'A');
        $this->assertNoEntra($email, self::B, 'B');
        $this->assertNoEntra($email, 'Intruso-Clave-444', 'del enlace viejo');
        $this->assertEntra($email, self::C, 'C');
        $this->assertNotNull($act['form'], 'el enlace vigente debe ofrecer elegir la contraseña');
    }

    public function test_R3_03_la_cuenta_de_recepcion_la_activa_quien_demuestra_el_buzon_eligiendo_la_contrasena(): void
    {
        $email = self::correo('recep');
        $cuenta = self::cliente(['email' => $email, 'confirmado' => false]);
        // Cuenta de recepción: sin confirmar y con una contraseña aleatoria que nadie conoce.
        self::q('UPDATE usuarios SET password=? WHERE id=?', [password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), $cuenta['id']]);
        $this->registrar($email, self::A);           // un tercero intenta registrarse sobre ella
        $this->assertNoEntra($email, self::A, 'A del tercero (cuenta sin confirmar)');
        $token = $this->ultimoToken($email);
        $this->assertNotNull($token, 'el titular del correo recibe el enlace');
        $act = $this->activar($token, self::C);
        $this->assertNoEntra($email, self::A, 'A');
        $this->assertEntra($email, self::C, 'C elegida por quien activa (en el diseño antiguo la cuenta queda activa con una contraseña aleatoria que nadie conoce y hay que ir a /olvide)');
        $this->assertNotNull($act['form'], 'quien activa debe poder ELEGIR la contraseña en el formulario');
    }

    public function test_R3_04_dos_activaciones_simultaneas_con_contrasenas_distintas_gana_una_y_solo_su_contrasena_vale(): void
    {
        $email = self::correo('carrera');
        $this->registrar($email, self::A);
        $token = $this->ultimoToken($email);
        $this->assertNotNull($token);
        $claves = []; $conts = [];
        for ($i = 0; $i < 6; $i++) {
            $n = new Navegador(self::$base);
            $url = '/confirmar-cuenta?token=' . $token;
            $g = $n->get($url);
            $f = $this->formulario($g['body'], $url);
            $claves[$i] = "Carrera-Clave-$i-" . bin2hex(random_bytes(3));
            $conts[$i] = ['nav' => $n, 'form' => $f];
        }
        $this->assertCount(6, array_filter($conts, fn($c) => $c['form'] !== null), 'cada uno de los 6 contendientes debe recibir su formulario de activación');
        $mh = curl_multi_init(); $hs = [];
        foreach ($conts as $i => $c) {
            $campos = $c['form']['campos'];
            foreach ($c['form']['password'] as $nombre) { $campos[$nombre] = $claves[$i]; }
            $h = curl_init(self::$base . $c['form']['accion']);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($campos), CURLOPT_COOKIE => 'PHPSESSID=' . $c['nav']->cookieSesion(), CURLOPT_TIMEOUT => 60]);
            curl_multi_add_handle($mh, $h); $hs[$i] = $h;
        }
        do { curl_multi_exec($mh, $act); curl_multi_select($mh, 0.05); } while ($act > 0);
        $estados = [];
        foreach ($hs as $i => $h) { $estados[$i] = (int)curl_getinfo($h, CURLINFO_HTTP_CODE); curl_multi_remove_handle($mh, $h); curl_close($h); }
        curl_multi_close($mh);
        $this->assertSame(1, (int)$this->fila($email)['confirmado']);
        $ganan = [];
        foreach ($claves as $i => $clave) { if ($this->entra($email, $clave)['/cliente/login']) { $ganan[] = $i; } }
        $this->assertCount(1, $ganan, 'exactamente UNA contraseña debe valer; estados HTTP ' . json_encode($estados) . '; válidas: ' . json_encode($ganan));
        $this->assertNoEntra($email, self::A, 'A');
        $exitos = array_keys(array_filter($estados, fn($s) => in_array($s, [200, 302, 303], true) && true));
        $this->assertNotEmpty($exitos);
    }

    public function test_R3_05_una_sesion_previa_de_la_cuenta_no_da_acceso_sin_confirmar_ni_sobrevive_a_la_activacion(): void
    {
        $email = self::correo('sesion');
        $this->registrar($email, self::A);
        $id = (int)$this->fila($email)['id'];
        $datos = fn(?int $version): array => ['csrf_token' => bin2hex(random_bytes(32)), 'id' => (string)$id, 'nombre' => 'N', 'apellido' => 'A', 'email' => $email, 'login' => true, 'portal' => 'cliente', 'rol' => 'cliente']
            + ($version === null ? [] : ['version_sesion' => $version]);
        $v = (int)$this->fila($email)['version_sesion'];
        // Cuatro sesiones previas de ese usuario: dos se usan ANTES de activar (el servidor las vacía al verlas sin confirmar) y dos se guardan
        // sin tocar (un atacante que conservó una sesión forjada/capturada) para usarlas DESPUÉS de la activación.
        $usadaSin = self::forjarSesion($datos(null), 'r3');   $usadaCon = self::forjarSesion($datos($v), 'r3');
        $guardadaSin = self::forjarSesion($datos(null), 'r3'); $guardadaCon = self::forjarSesion($datos($v), 'r3');
        $acceso = fn(string $sid): int => (new Navegador(self::$base))->get('/cita', ['Cookie: PHPSESSID=' . $sid, 'Accept: text/html'])['status'];
        $this->assertNotSame(200, $acceso($usadaSin), 'sesión previa SIN versión: no debe dar acceso mientras la cuenta no está confirmada');
        $this->assertNotSame(200, $acceso($usadaCon), 'sesión previa CON versión: no debe dar acceso mientras la cuenta no está confirmada');
        $token = $this->ultimoToken($email);
        $this->assertNotNull($token);
        $act = $this->activar($token, self::C);
        $this->assertSame(1, (int)$this->fila($email)['confirmado'], 'la activación debe completarse (formulario: ' . ($act['form'] ? 'sí' : 'no') . ')');
        $this->assertNotSame(200, $acceso($guardadaSin), 'una sesión previa guardada (sin versión) se «adopta» al quedar confirmada la cuenta: da acceso con la identidad del usuario sin conocer ninguna contraseña');
        $this->assertNotSame(200, $acceso($guardadaCon), 'una sesión previa guardada (con la versión anterior) sobrevive a la activación: la activación no incrementa version_sesion');
        $this->assertNotSame(200, $acceso($usadaSin));
        $this->assertNotSame(200, $acceso($usadaCon));
    }

    public function test_R3_06_el_doble_clic_de_la_activacion_con_la_misma_clave_activa_una_sola_vez_y_no_cambia_la_contrasena(): void
    {
        $email = self::correo('doble');
        $this->registrar($email, self::A);
        $token = $this->ultimoToken($email);
        $this->assertNotNull($token);
        $n = new Navegador(self::$base);
        $url = '/confirmar-cuenta?token=' . $token;
        $f = $this->formulario($n->get($url)['body'], $url);
        $this->assertNotNull($f, 'DISEÑO NUEVO: el enlace debe mostrar el formulario de activación');
        $con = fn(string $clave): array => array_merge($f['campos'], array_fill_keys($f['password'], $clave));
        $p1 = $n->post($f['accion'], $con(self::C));
        $hash1 = $this->fila($email)['password'];
        $p2 = $n->post($f['accion'], $con(self::C));              // doble clic: mismo cuerpo, misma clave de operación
        $p3 = $n->post($f['accion'], $con('Otra-Clave-Distinta-9')); // misma clave de operación con otra contraseña: no puede cambiarla
        $this->assertSame($p1['status'], $p2['status'], 'el doble clic debe devolver lo mismo que la primera vez');
        $this->assertSame(1, (int)$this->fila($email)['confirmado']);
        $this->assertSame($hash1, $this->fila($email)['password'], 'la repetición modificó la contraseña');
        $this->assertEntra($email, self::C, 'C');
        $this->assertNoEntra($email, 'Otra-Clave-Distinta-9', 'de la repetición con la misma clave');
        $this->assertNoEntra($email, self::A, 'A');
        $this->assertContains($p3['status'], [200, 302, 303, 409, 410, 422], 'respuesta inesperada a la repetición con otra contraseña');
    }

    // ------------------------------------------------------------------ controles de (1) que ya pasan y deben seguir pasando

    public function test_R3_07_el_reenvio_invalida_el_enlace_anterior_y_este_da_no_valido(): void
    {
        $email = self::correo('reenv');
        $this->registrar($email, self::A);
        $viejo = $this->ultimoToken($email);
        $this->reenviar($email);
        $nuevo = $this->ultimoToken($email);
        $this->assertNotSame($viejo, $nuevo);
        $g = (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $viejo);
        $this->assertSame(410, $g['status'], 'el enlace viejo debe dar «no válido»');
        $this->assertNull($this->formulario($g['body'], '/confirmar-cuenta'));
        $this->assertSame(0, (int)$this->fila($email)['confirmado']);
    }

    public function test_R3_08_registrar_sobre_una_cuenta_confirmada_no_cambia_la_contrasena_ni_confirma_ni_abre_acceso(): void
    {
        $c = self::cliente(['email' => self::correo('conf')]);
        $antes = $this->fila($c['email']);
        $this->registrar($c['email'], self::A);
        self::entregarCola(self::SMTP);
        $despues = $this->fila($c['email']);
        $this->assertSame($antes['password'], $despues['password']);
        $this->assertSame('1', (string)$despues['confirmado']);
        $this->assertNull($despues['token_hash'], 'el aviso a una cuenta confirmada no debe dejar un token');
        $this->assertNoEntra($c['email'], self::A, 'A');
        $this->assertEntra($c['email'], $c['password'], 'original');
    }

    public function test_R3_09_un_token_vencido_no_activa_ni_ofrece_formulario(): void
    {
        $email = self::correo('venc');
        $this->registrar($email, self::A);
        $token = $this->ultimoToken($email);
        self::q('UPDATE usuarios SET token_expira = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE email=?', [$email]);
        $a = $this->activar($token, self::C);
        $this->assertSame(410, $a['get']['status']);
        $this->assertNull($a['form']);
        $this->assertSame(0, (int)$this->fila($email)['confirmado']);
        $this->assertNoEntra($email, self::C, 'C');
    }

    public function test_R3_13_los_tokens_de_confirmacion_y_recuperacion_no_se_pueden_usar_cruzados(): void
    {
        // Recuperación usada como confirmación: cuenta confirmada con token de recuperación.
        $c = self::cliente(['email' => self::correo('cruz')]);
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $n->post('/olvide', ['csrf_token' => $n->csrf('/olvide'), 'email' => $c['email']]);
        self::entregarCola(self::SMTP);
        $rec = null;
        foreach (self::buzonFallo(self::SMTP) as $m) { if (in_array($c['email'], $m['to'], true)) { $rec = self::tokenEnCorreo($m, '/recuperar') ?? $rec; } }
        $this->assertNotNull($rec);
        $antes = $this->fila($c['email']);
        $a = $this->activar($rec, self::C);
        $this->assertSame(410, $a['get']['status'], 'un token de recuperación no es un enlace de confirmación');
        $this->assertNull($a['form']);
        $despues = $this->fila($c['email']);
        $this->assertSame($antes['password'], $despues['password']);
        $this->assertSame($antes['token_hash'], $despues['token_hash'], 'no debe consumirse el token de recuperación');
        // Confirmación usada como recuperación: cuenta sin confirmar con token de confirmación.
        $email = self::correo('cruz2');
        $this->registrar($email, self::A);
        $conf = $this->ultimoToken($email);
        $pass = $this->fila($email)['password'];
        $g = (new Navegador(self::$base))->get('/recuperar?token=' . $conf);
        $this->assertSame(410, $g['status']);
        $n2 = new Navegador(self::$base);
        $n2->post('/recuperar?token=' . $conf, ['csrf_token' => $n2->csrf('/olvide'), 'password' => self::C]);
        $f = $this->fila($email);
        $this->assertSame($pass, $f['password'], 'un token de confirmación no debe servir para restablecer la contraseña');
        $this->assertSame(0, (int)$f['confirmado']);
        $this->assertNotNull($f['token_hash'], 'no debe consumirse el token de confirmación');
    }

    public function test_R3_14_un_token_de_confirmacion_residual_en_una_cuenta_ya_confirmada_no_cambia_su_contrasena_ni_reactiva_nada(): void
    {
        $c = self::cliente(['email' => self::correo('resid'), 'estado' => 'suspendido']);
        $token = bin2hex(random_bytes(32));
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='confirmacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $token), $c['id']]);
        $antes = $this->fila($c['email']);
        $a = $this->activar($token, self::C);   // si hay formulario se envía: no debe cambiar nada
        $despues = $this->fila($c['email']);
        $this->assertSame($antes['password'], $despues['password'], 'el token residual cambió la contraseña de una cuenta ya confirmada');
        $this->assertSame('suspendido', self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$c['id']]), 'no debe reactivar una cuenta suspendida');
        $this->assertSame((int)$antes['version_sesion'] >= 0, true);
        $this->assertNoEntra($c['email'], self::C, 'C');
        $this->assertNoEntra($c['email'], $c['password'], 'original de una cuenta suspendida');
    }

    public function test_R3_15_un_token_invalido_vencido_o_de_otro_tipo_responde_igual_y_no_consume_nada(): void
    {
        $email = self::correo('enum');
        $this->registrar($email, self::A);
        $token = $this->ultimoToken($email);
        $firma = function (string $t): array {
            $r = (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $t);
            $r['body'] = preg_replace('/[a-f0-9]{32,}/', '<h>', $r['body']);
            $f = self::firma($r); $f['headers']['content-length'] = null;
            return $f;
        };
        // inexistente
        $inexistente = $firma(bin2hex(random_bytes(32)));
        // vencido (se guarda y restaura el token real para no consumirlo)
        $c = $this->fila($email);
        $venc = bin2hex(random_bytes(32));
        $otro = self::cliente(['email' => self::correo('enum2'), 'confirmado' => false]);
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='confirmacion', token_expira=UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id=?", [hash('sha256', $venc), $otro['id']]);
        $vencido = $firma($venc);
        // de otro tipo
        $rec = bin2hex(random_bytes(32));
        $conf = self::cliente(['email' => self::correo('enum3')]);
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='recuperacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $rec), $conf['id']]);
        $otroTipo = $firma($rec);
        $this->assertSame($inexistente, $vencido, 'un token vencido se distingue de uno inexistente');
        $this->assertSame($inexistente, $otroTipo, 'un token de otro tipo se distingue de uno inexistente');
        $this->assertSame($c['token_hash'], $this->fila($email)['token_hash'], 'las peticiones con tokens ajenos consumieron el token real');
    }

    // ================================================================== (2) confirmación por GET

    public function test_R3_10_get_head_y_prefetch_no_activan_la_cuenta_ni_consumen_el_token(): void
    {
        $email = self::correo('get');
        $this->registrar($email, self::A);
        $token = $this->ultimoToken($email);
        $this->assertNotNull($token);
        $antes = $this->fila($email);
        $url = '/confirmar-cuenta?token=' . $token;
        $uas = ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.1; Pro)',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36 SafeLinks-Protection', 'Slackbot-LinkExpanding 1.0', 'curl/8.5.0'];
        $cabs = [['Purpose: prefetch'], ['Sec-Purpose: prefetch;prerender'], ['X-Moz: prefetch'], ['Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document']];
        $n = new Navegador(self::$base);
        $resp = [];
        $resp[] = $n->peticion('HEAD', $url);
        for ($i = 0; $i < 20; $i++) { $resp[] = (new Navegador(self::$base))->get($url); }
        foreach ($uas as $ua) { $resp[] = (new Navegador(self::$base))->get($url, ['User-Agent: ' . $ua]); }
        foreach ($cabs as $h) { $resp[] = (new Navegador(self::$base))->get($url, $h); $resp[] = (new Navegador(self::$base))->peticion('HEAD', $url, null, $h); }
        $despues = $this->fila($email);
        $this->assertSame(0, (int)$despues['confirmado'], 'un GET/HEAD/prefetch activó la cuenta (confirmado=1)');
        $this->assertSame($antes['token_hash'], $despues['token_hash'], 'un GET/HEAD/prefetch consumió el token');
        $this->assertSame($antes['token_expira'], $despues['token_expira']);
        $this->assertSame($antes['password'], $despues['password']);
        // y el titular aún puede activar después del bombardeo de escáneres
        $act = $this->activar($token, self::C);
        $this->assertNotNull($act['form'], 'tras los GET de los escáneres el enlace debe seguir mostrando el formulario');
    }

    public function test_R3_11_la_activacion_exige_post_con_csrf_y_los_demas_casos_no_tienen_efectos(): void
    {
        $email = self::correo('post');
        $this->registrar($email, self::A);
        $token = $this->ultimoToken($email);
        $url = '/confirmar-cuenta?token=' . $token;
        $n = new Navegador(self::$base);
        $f = $this->formulario($n->get($url)['body'], $url);
        $this->assertNotNull($f, 'DISEÑO NUEVO: el enlace debe mostrar un formulario de activación (POST)');
        $this->assertSame('POST', $f['metodo'], 'la activación debe ser POST');
        $claveCsrf = null; $claveToken = null;
        foreach ($f['campos'] as $k => $v) { if (preg_match('/csrf/i', $k)) { $claveCsrf = $k; } if ($v === $token) { $claveToken = $k; } }
        $this->assertNotNull($claveCsrf, 'el formulario debe llevar token CSRF');
        $base = array_merge($f['campos'], array_fill_keys($f['password'], self::C));
        $otra = new Navegador(self::$base); $csrfAjeno = $otra->csrf('/cliente/login');
        $casos = [
            'sin CSRF' => fn() => $n->post($f['accion'], array_diff_key($base, [$claveCsrf => 1])),
            'CSRF vacío' => fn() => $n->post($f['accion'], [$claveCsrf => ''] + $base),
            'CSRF de otra sesión' => fn() => $n->post($f['accion'], [$claveCsrf => $csrfAjeno] + $base),
            'CSRF inventado' => fn() => $n->post($f['accion'], [$claveCsrf => str_repeat('a', 64)] + $base),
            'método GET con los campos en la URL' => fn() => $n->get($f['accion'] . (str_contains($f['accion'], '?') ? '&' : '?') . http_build_query($base)),
            'método PUT' => fn() => $n->peticion('PUT', $f['accion'], $base),
            'sin sesión (otro navegador, mismo CSRF)' => fn() => (new Navegador(self::$base))->post($f['accion'], $base),
            'contraseña vacía' => fn() => $n->post($f['accion'], array_merge($base, array_fill_keys($f['password'], ''))),
            'contraseña de 3 caracteres' => fn() => $n->post($f['accion'], array_merge($base, array_fill_keys($f['password'], 'abc'))),
        ];
        if ($claveToken !== null) {
            $casos['token solo en la query y no en el cuerpo'] = fn() => $n->post($f['accion'] . (str_contains($f['accion'], '?') ? '&' : '?') . 'token=' . $token, array_diff_key($base, [$claveToken => 1]));
            $casos['token distinto en el cuerpo'] = fn() => $n->post($f['accion'], [$claveToken => bin2hex(random_bytes(32))] + $base);
        }
        if (count($f['password']) > 1) {
            $dist = $base; $dist[$f['password'][1]] = 'Distinta-Clave-555';
            $casos['contraseñas que no coinciden'] = fn() => $n->post($f['accion'], $dist);
        }
        $antes = $this->fila($email);
        foreach ($casos as $nombre => $fn) {
            self::q('TRUNCATE intentos_login');
            $r = $fn();
            $d = $this->fila($email);
            $this->assertSame(0, (int)$d['confirmado'], "«{$nombre}»: activó la cuenta (HTTP {$r['status']})");
            $this->assertSame($antes['password'], $d['password'], "«{$nombre}»: cambió la contraseña");
            $this->assertSame($antes['token_hash'], $d['token_hash'], "«{$nombre}»: consumió el token");
        }
        // El envío legítimo, después de todos los intentos fallidos, sí funciona.
        self::q('TRUNCATE intentos_login');
        $ok = $n->post($f['accion'], $base);
        $this->assertContains($ok['status'], [200, 302, 303], substr(strip_tags($ok['body']), 0, 200));
        $this->assertSame(1, (int)$this->fila($email)['confirmado']);
        $this->assertEntra($email, self::C, 'C');
    }

    public function test_R3_12_la_activacion_tiene_limite_de_intentos(): void
    {
        $email = self::correo('limite');
        $this->registrar($email, self::A);
        $token = $this->ultimoToken($email);
        $url = '/confirmar-cuenta?token=' . $token;
        $n = new Navegador(self::$base);
        $f = $this->formulario($n->get($url)['body'], $url);
        $this->assertNotNull($f, 'DISEÑO NUEVO: el enlace debe mostrar un formulario de activación');
        $claveToken = null;
        foreach ($f['campos'] as $k => $v) { if ($v === $token) { $claveToken = $k; } }
        self::q('TRUNCATE intentos_login');
        $estados = [];
        for ($i = 0; $i < 40; $i++) {
            $campos = array_merge($f['campos'], array_fill_keys($f['password'], self::C));
            if ($claveToken !== null) { $campos[$claveToken] = bin2hex(random_bytes(32)); } // tokens inventados: fuerza bruta del enlace
            $estados[] = $n->post($f['accion'], $campos)['status'];
        }
        $this->assertContains(429, $estados, 'ningún intento fue limitado en 40 POST con tokens inventados: ' . json_encode(array_count_values($estados)));
        $this->assertSame(0, (int)$this->fila($email)['confirmado']);
    }

    // ================================================================== (3) envío inmediato solo en desarrollo/pruebas

    public function test_R3_20_el_envio_inmediato_no_tiene_efecto_fuera_de_desarrollo_y_pruebas_y_si_lo_tiene_en_ellos(): void
    {
        $sinEfecto = ['production' => 'production', 'Production' => 'Production', ' production ' => ' production ', 'PRODUCTION' => 'PRODUCTION', 'prod' => 'prod',
                      'produccion' => 'produccion', 'staging' => 'staging', 'stage' => 'stage', 'qa' => 'qa', 'live' => 'live', 'preprod' => 'preprod', 'vacío' => '', 'no definido' => null];
        $fallos = [];
        foreach ($sinEfecto as $etiqueta => $valor) {
            $m = $this->medirEnvio('r3_env_' . md5((string)$etiqueta), ['APP_ENV' => $valor]);
            foreach ($m as $paso => $x) {
                $this->assertContains($x['status'], [200, 302], "APP_ENV=«{$etiqueta}», $paso: el servidor no respondió bien (HTTP {$x['status']})");
                // Registro calcula un hash de contraseña: su duración depende de la CPU, no solo de SMTP.
                // El contrato es transporte inactivo y exactamente un evento pendiente sin intentos.
                if ($x['correos'] !== 0 || $x['smtp_saludos'] !== 0 || $x['cola'] !== [['estado'=>'pendiente','intentos'=>0,'enviado'=>false]]) {
                    $fallos[] = "APP_ENV=«{$etiqueta}» $paso: " . json_encode($x, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $this->assertSame([], $fallos, "con APP_ENV fuera de desarrollo/pruebas CORREOS_ENVIO_INMEDIATO=1 NO debe enviar dentro de la petición (SMTP de 400 ms):\n" . implode("\n", $fallos));
        // Control positivo: en los entornos explícitos de desarrollo/pruebas SÍ hay envío en línea (la prueba detecta el canal).
        foreach (['testing', 'test', 'development', 'dev', 'local'] as $env) {
            $m = $this->medirEnvio('r3_ctl_' . $env, ['APP_ENV' => $env]);
            foreach ($m as $paso => $x) {
                $this->assertGreaterThan(0, $x['smtp_saludos'], "control positivo APP_ENV=$env, $paso: debía abrir una sesión SMTP");
                $this->assertSame([['estado'=>'enviado','intentos'=>1,'enviado'=>true]], $x['cola'], "control positivo APP_ENV=$env, $paso: evento entregado una vez");
                $this->assertSame(1, $x['correos'], "control positivo APP_ENV=$env, $paso: debía enviar en línea");
                $this->assertGreaterThan(350, $x['ms'], "control positivo APP_ENV=$env, $paso: debía pagar la latencia del SMTP");
            }
        }
    }

    /**
     * CRITERIO DE SEGURIDAD (decisión del QA, documentada en el doc): «ante la duda, NO habilitar». Solo el valor EXACTO en minúsculas y sin espacios
     * de los cinco permitidos habilita el envío en línea; «TESTING», « testing » o «Development» no. (`Secretos::clave` es más permisiva: recorta y
     * pasa a minúsculas; si el líder decide igualarla, esta prueba debe revisarse de forma explícita, no relajarse en silencio.)
     */
    public function test_R3_21_las_variantes_de_mayusculas_y_espacios_de_los_entornos_de_pruebas_no_habilitan_el_envio_inmediato(): void
    {
        $fallos = [];
        foreach (['TESTING', ' testing ', 'Development', 'DEV', 'Local', 'Test ', "testing\n"] as $valor) {
            $m = $this->medirEnvio('r3_var_' . md5($valor), ['APP_ENV' => $valor]);
            foreach ($m as $paso => $x) { if ($x['correos'] !== 0 || $x['smtp_saludos'] !== 0 || $x['cola'] !== [['estado'=>'pendiente','intentos'=>0,'enviado'=>false]]) { $fallos[] = 'APP_ENV=«' . addcslashes($valor, "\n") . "» $paso: " . json_encode($x, JSON_UNESCAPED_UNICODE); } }
        }
        $this->assertSame([], $fallos, implode("\n", $fallos));
    }
}
