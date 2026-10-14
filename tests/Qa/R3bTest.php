<?php

namespace Tests\Qa;

require_once __DIR__ . '/R3Case.php';

/**
 * Ronda 3, fase B: ataques NUEVOS contra la corrección real de la toma de cuenta (activación por POST con contraseña elegida en el buzón).
 * Carreras (re-registro / reenvío / recuperación frente a la activación), abuso del endpoint, cabeceras y cookies del formulario, reflejo
 * del token, `idem_key` hostil, fijación de sesión, sesiones legadas, texto del correo y advertencia de CORREOS_ENVIO_INMEDIATO.
 * HTTP real (8150-8199, 8 procesos), SMTP simulado 2593 y el worker real de correos. Ningún correo sale de la máquina.
 */
final class R3bTest extends R3Case
{
    private const DEBIL = 'abc';
    private static array $dueno;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$dueno = self::propietario(self::correo('dueno'));
    }

    /** Registro público preparado (GET hecho) para poder dispararlo a la vez que otras peticiones. @return array{cookie:?string,body:array} */
    private function registroPreparado(string $email, string $clave): array
    {
        $n = new Navegador(self::$base);
        $pag = $n->get('/cliente/registro');
        return ['cookie' => $n->cookieSesion(), 'body' => ['csrf_token' => Navegador::csrfDe($pag['body']), 'idem_key' => Navegador::campoDe($pag['body'], 'idem_key'),
            'nombre' => 'Ana', 'apellido' => 'Prueba', 'email' => $email, 'telefono' => '0991112222', 'password' => $clave]];
    }

    // ------------------------------------------------------------------ carreras

    public function test_R3b_01_carrera_entre_el_reregistro_o_el_reenvio_y_la_activacion_deja_un_estado_coherente(): void
    {
        $activadas = 0; $reemitidas = 0;
        for ($ronda = 1; $ronda <= 8; $ronda++) {
            $email = self::correo('car' . $ronda);
            $this->registrar($email, self::A);
            $t1 = $this->ultimoToken($email);
            [$nav, $f] = $this->abrirFormulario($t1);
            $this->assertNotNull($f, "ronda $ronda: formulario");
            $reg = $this->registroPreparado($email, self::B);
            $rn = new Navegador(self::$base);
            $reenvio = ['csrf_token' => $rn->csrf('/reenviar-confirmacion'), 'email' => $email];
            self::q('TRUNCATE intentos_login');
            $res = $this->rafagaHttp([
                ['cookie' => $nav->cookieSesion(), 'ruta' => $f['accion'], 'body' => $this->cuerpo($f, self::C)],
                ['cookie' => $reg['cookie'], 'ruta' => '/cliente/registro', 'body' => $reg['body']],
                ['cookie' => $rn->cookieSesion(), 'ruta' => '/reenviar-confirmacion', 'body' => $reenvio],
            ]);
            foreach ($res as $i => $r) { $this->assertLessThan(500, $r['status'], "ronda $ronda, petición $i: " . substr(strip_tags($r['body']), 0, 200)); }
            $u = $this->fila($email);
            if ((int)$u['confirmado'] === 1) {
                $activadas++;
                $this->assertNull($u['token_hash'], "ronda $ronda: cuenta activa con un token de confirmación vivo (la reemisión pisó la activación)");
                $this->assertTrue(password_verify(self::C, $u['password']), "ronda $ronda: la cuenta activa no tiene la contraseña elegida");
                $this->assertFalse(password_verify(self::A, $u['password']), "ronda $ronda: sobrevive la contraseña provisional");
                $this->assertFalse(password_verify(self::B, $u['password']), "ronda $ronda: B se coló como contraseña");
            } else {
                $reemitidas++;
                $this->assertSame('confirmacion', $u['token_tipo'], "ronda $ronda");
                $this->assertNotNull($u['token_hash'], "ronda $ronda: ni activada ni con enlace vigente");
                $this->assertFalse(password_verify(self::C, $u['password']), "ronda $ronda: sin activar pero con la contraseña C");
                // El enlace que quedó vigente es el último entregado y el titular puede completar la activación.
                $t = $this->ultimoToken($email);
                $this->assertNotNull($t, "ronda $ronda: ningún correo con el enlace vigente");
                $this->assertSame($u['token_hash'], hash('sha256', $t));
                $this->activar($t, self::C);
            }
            $this->assertSame(1, (int)$this->fila($email)['confirmado'], "ronda $ronda: debe poder activarse");
            $this->assertNoEntra($email, self::A, "A (ronda $ronda)");
            $this->assertNoEntra($email, self::B, "B (ronda $ronda)");
            $this->assertEntra($email, self::C, "C (ronda $ronda)");
        }
        $this->addToAssertionCount(1);
        fwrite(STDERR, "\n[R3b_01] activación ganó en $activadas rondas, reemisión en $reemitidas\n");
    }

    public function test_R3b_02_un_enlace_de_reenvio_posterior_sirve_y_el_anterior_no_ni_siquiera_dentro_del_mismo_formulario(): void
    {
        $email = self::correo('reenv2');
        $this->registrar($email, self::A);
        $t1 = $this->ultimoToken($email);
        [$n1, $f1] = $this->abrirFormulario($t1);
        $this->assertNotNull($f1);
        $this->reenviar($email);
        $t2 = $this->ultimoToken($email);
        $this->assertNotSame($t1, $t2);
        // POST con el token viejo (el formulario sigue abierto en el navegador): 410 y sin efectos.
        $claveToken = array_search($t1, $f1['campos'], true);
        $this->assertNotFalse($claveToken, 'el formulario debe llevar el token en un campo oculto');
        $r = $n1->post($f1['accion'], $this->cuerpo($f1, self::C));
        $this->assertSame(410, $r['status'], 'el enlace reemplazado debe dar 410 también por POST');
        $this->assertSame(0, (int)$this->fila($email)['confirmado']);
        $this->assertNull($this->formulario($r['body'], '/confirmar-cuenta'), 'el 410 no debe volver a ofrecer el formulario');
        // El token NUEVO enviado dentro del formulario viejo (misma sesión y mismo CSRF): es el enlace del titular y sí activa.
        $r2 = $n1->post($f1['accion'], $this->cuerpo($f1, self::C, [$claveToken => $t2, 'idem_key' => Idem::clave()]));
        $this->assertSame(200, $r2['status'], substr(strip_tags($r2['body']), 0, 300));
        $this->assertNoEntra($email, self::A, 'A');
        $this->assertEntra($email, self::C, 'C');
        $this->assertSame(410, (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $t1)['status']);
        $this->assertSame(410, (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $t2)['status'], 'el enlace usado ya no es válido');
    }

    public function test_R3b_03_activacion_y_recuperacion_simultaneas_no_dejan_tokens_ni_contrasenas_incoherentes(): void
    {
        $perdidas = 0;
        for ($ronda = 1; $ronda <= 8; $ronda++) {
            $email = self::correo('rec' . $ronda);
            $this->registrar($email, self::A);
            $t = $this->ultimoToken($email);
            [$nav, $f] = $this->abrirFormulario($t);
            $this->assertNotNull($f);
            $on = new Navegador(self::$base);
            $olvide = ['csrf_token' => $on->csrf('/olvide'), 'email' => $email];
            self::q('TRUNCATE intentos_login');
            $res = $this->rafagaHttp([
                ['cookie' => $nav->cookieSesion(), 'ruta' => $f['accion'], 'body' => $this->cuerpo($f, self::C)],
                ['cookie' => $on->cookieSesion(), 'ruta' => '/olvide', 'body' => $olvide],
            ]);
            $this->assertLessThan(500, max($res[0]['status'], $res[1]['status']), "ronda $ronda");
            $this->assertSame(200, $res[0]['status'], "ronda $ronda: la activación debe completarse: " . substr(strip_tags($res[0]['body']), 0, 200));
            $u = $this->fila($email);
            $this->assertSame(1, (int)$u['confirmado']);
            $this->assertNotSame('confirmacion', $u['token_tipo'], "ronda $ronda: queda un token de confirmación vivo en una cuenta activa");
            $this->assertTrue(password_verify(self::C, $u['password']));
            // Si /olvide llegó después de activar, hay un token de recuperación vivo: su correo debe poder llegar (no quedar cancelado por la activación).
            self::entregarCola(self::SMTP);
            if ($u['token_tipo'] === 'recuperacion') {
                $recibido = false;
                foreach (self::buzonFallo(self::SMTP) as $m) {
                    if (in_array($email, $m['to'], true) && ($tk = self::tokenEnCorreo($m, '/recuperar')) !== null && hash('sha256', $tk) === $u['token_hash']) { $recibido = true; }
                }
                if (!$recibido) { $perdidas++; }
            }
            $this->assertNoEntra($email, self::A, "A (ronda $ronda)");
            $this->assertEntra($email, self::C, "C (ronda $ronda)");
        }
        fwrite(STDERR, "\n[R3b_03] solicitudes de recuperación con token vivo y correo cancelado por la activación: $perdidas de 8 rondas\n");
        $this->assertSame(0, $perdidas, 'la activación canceló el correo de una recuperación pedida DESPUÉS de activar: el titular quedó con un token vivo sin recibir el enlace');
    }

    // ------------------------------------------------------------------ abuso del endpoint

    public function test_R3b_04_los_errores_de_contrasena_no_consumen_el_token_ni_cuentan_para_el_limite(): void
    {
        $email = self::correo('debil');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        [$n, $f] = $this->abrirFormulario($t);
        $this->assertNotNull($f);
        $antes = $this->fila($email);
        $estados = [];
        for ($i = 0; $i < 40; $i++) {
            $debil = $i % 3 === 0 ? self::DEBIL : ($i % 3 === 1 ? '' : str_repeat('a', 7));
            $cuerpo = $this->cuerpo($f, $debil);
            if ($i % 4 === 0 && count($f['password']) > 1) { $cuerpo[$f['password'][1]] = 'Distinta-' . $i; $cuerpo[$f['password'][0]] = self::C; } // discordante
            $r = $n->post($f['accion'], $cuerpo);
            $estados[] = $r['status'];
            $d = $this->fila($email);
            $this->assertSame(0, (int)$d['confirmado'], "intento $i activó la cuenta");
            $this->assertSame($antes['token_hash'], $d['token_hash'], "intento $i consumió el token");
            $this->assertSame($antes['password'], $d['password'], "intento $i cambió la contraseña");
        }
        $this->assertSame([422], array_values(array_unique($estados)), 'los errores de contraseña deben ser 422 y NO 429: ' . json_encode(array_count_values($estados)));
        // El formulario se vuelve a mostrar con el enlace vigente.
        $r = $n->post($f['accion'], $this->cuerpo($f, self::DEBIL));
        $this->assertNotNull($this->formulario($r['body'], '/confirmar-cuenta'), 'tras un error de contraseña debe volver a mostrarse el formulario');
        // y la activación válida sigue funcionando, sin haber gastado el límite.
        $ok = $n->post($f['accion'], $this->cuerpo($f, self::C));
        $this->assertSame(200, $ok['status'], substr(strip_tags($ok['body']), 0, 300));
        $this->assertEntra($email, self::C, 'C');
    }

    public function test_R3b_05_el_abuso_con_tokens_inventados_da_429_con_retry_after_y_no_cuenta_los_errores_de_contrasena(): void
    {
        $email = self::correo('abuso');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        [$n, $f] = $this->abrirFormulario($t);
        $claveToken = array_search($t, $f['campos'], true);
        self::q('TRUNCATE intentos_login');
        $estados = []; $retry = null;
        for ($i = 0; $i < 30; $i++) {
            $r = $n->post($f['accion'], $this->cuerpo($f, self::C, [$claveToken => bin2hex(random_bytes(32))]));
            $estados[] = $r['status'];
            if ($r['status'] === 429) { $retry ??= $r['headers']['retry-after'][0] ?? 'ausente'; }
        }
        $primer429 = array_search(429, $estados, true);
        $this->assertNotFalse($primer429, 'ningún 429 en 30 tokens inventados: ' . json_encode(array_count_values($estados)));
        $this->assertLessThanOrEqual(20, $primer429, 'el límite por IP debe saltar en pocos intentos');
        $this->assertMatchesRegularExpression('/^\d+$/', (string)$retry, 'el 429 debe llevar Retry-After numérico');
        // OBSERVACIÓN medida: el presupuesto por IP es compartido, así que el abuso desde una IP también bloquea la activación legítima de OTROS usuarios de esa IP.
        $legit = $n->post($f['accion'], $this->cuerpo($f, self::C));
        $this->assertSame(429, $legit['status'], 'caracterización: con la IP agotada la activación legítima también recibe 429 (NAT compartido)');
        $this->assertSame(0, (int)$this->fila($email)['confirmado']);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(200, $n->post($f['accion'], $this->cuerpo($f, self::C))['status'], 'al liberarse el presupuesto el mismo enlace sigue sirviendo');
    }

    // ------------------------------------------------------------------ cabeceras, cookies, reflejo

    public function test_R3b_06_el_formulario_no_se_cachea_no_filtra_referer_y_la_activacion_regenera_la_sesion_sin_autologin(): void
    {
        $email = self::correo('cab');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        $url = '/confirmar-cuenta?token=' . $t;
        $n = new Navegador(self::$base);
        $g = $n->get($url);
        $h = $g['headers'];
        $this->assertSame(200, $g['status']);
        $this->assertStringContainsString('no-store', strtolower($h['cache-control'][0] ?? ''), 'GET del formulario: Cache-Control');
        $this->assertSame('no-referrer', $h['referrer-policy'][0] ?? null, 'GET del formulario: Referrer-Policy');
        $this->assertSame('nosniff', $h['x-content-type-options'][0] ?? null);
        $head = $n->peticion('HEAD', $url);
        $this->assertSame(200, $head['status']);
        $this->assertSame('', $head['body']);
        $this->assertSame('no-referrer', $head['headers']['referrer-policy'][0] ?? null, 'HEAD: Referrer-Policy');
        $this->assertStringContainsString('no-store', strtolower($head['headers']['cache-control'][0] ?? ''), 'HEAD: Cache-Control');
        $inv = $n->get('/confirmar-cuenta?token=' . bin2hex(random_bytes(32)));
        $this->assertSame(410, $inv['status']);
        $this->assertStringContainsString('no-store', strtolower($inv['headers']['cache-control'][0] ?? ''), '410: Cache-Control');
        $this->assertSame('no-referrer', $inv['headers']['referrer-policy'][0] ?? null, '410: Referrer-Policy');
        // El token no debe salir hacia terceros: ningún enlace/recurso externo en la página con el token.
        $this->assertDoesNotMatchRegularExpression('~(src|href)\s*=\s*["\']https?://(?!localhost|127\.0\.0\.1)~i', $g['body'], 'la página del token carga recursos externos');
        // Activación: sesión nueva, sin auto-login, sin Location, sin eco de contraseña ni de token.
        $f = $this->formulario($g['body'], $url);
        $this->assertNotNull($f);
        $antes = $n->cookieSesion();
        $this->assertNotNull($antes);
        $r = $n->post($f['accion'], $this->cuerpo($f, self::C));
        $this->assertSame(200, $r['status']);
        $this->assertNull($r['location'], 'la activación no debe redirigir ni iniciar sesión');
        $cookies = $r['headers']['set-cookie'] ?? [];
        $nueva = null;
        foreach ($cookies as $c) { if (preg_match('/^PHPSESSID=([^;]+)/', $c, $m)) { $nueva = $m[1]; $this->assertMatchesRegularExpression('/httponly/i', $c); $this->assertMatchesRegularExpression('/samesite=lax/i', $c); } }
        $this->assertNotNull($nueva, 'la activación debe emitir un identificador de sesión nuevo');
        $this->assertNotSame($antes, $nueva, 'el identificador de sesión no cambió al activar');
        $this->assertNotSame(200, $this->accesoConSesion($nueva), 'la sesión posterior a la activación no debe estar autenticada (sin auto-login)');
        $this->assertNotSame(200, $this->accesoConSesion($antes), 'el identificador anterior no debe dar acceso');
        $this->assertStringNotContainsString(self::C, $r['body']);
        $this->assertStringNotContainsString($t, $r['body'], 'la pantalla de éxito refleja el token');
        $this->assertStringNotContainsString(self::A, $r['body']);
        $this->assertStringContainsString('no-store', strtolower($r['headers']['cache-control'][0] ?? ''));
    }

    public function test_R3b_07_el_token_reflejado_va_escapado_y_no_inyecta_atributos_ni_scripts(): void
    {
        $payloads = ['"><script>alert(1)</script>', "' onfocus='alert(1)' autofocus x='", '</form><img src=x onerror=alert(1)>', '{{7*7}}${7*7}<%= 7*7 %>'];
        $n = new Navegador(self::$base);
        foreach ($payloads as $p) {
            // GET con un token hostil: página genérica 410, sin reflejarlo.
            $g = $n->get('/confirmar-cuenta?token=' . rawurlencode($p));
            $this->assertSame(410, $g['status']);
            $this->assertStringNotContainsString($p, $g['body'], 'el GET refleja el token sin escapar');
            // POST con contraseña débil y token hostil: se vuelve a mostrar el formulario (422) con el token escapado.
            $email = self::correo('xss');
            $this->registrar($email, self::A);
            $t = $this->ultimoToken($email);
            [$nav, $f] = $this->abrirFormulario($t);
            $claveToken = array_search($t, $f['campos'], true);
            $r = $nav->post($f['accion'], $this->cuerpo($f, self::DEBIL, [$claveToken => $p]));
            $this->assertContains($r['status'], [410, 422], substr(strip_tags($r['body']), 0, 200));
            $this->assertStringNotContainsString('<script>alert(1)', $r['body']);
            $this->assertStringNotContainsString('<img src=x', $r['body']);
            $dom = new \DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $r['body']);
            foreach ($dom->getElementsByTagName('input') as $in) {
                if ($in->getAttribute('name') === 'token') {
                    $this->assertSame($p, $in->getAttribute('value'), 'el valor del campo oculto debe ser el texto exacto, sin inyectar atributos');
                    foreach (['onfocus', 'autofocus', 'onerror', 'x'] as $a) { $this->assertFalse($in->hasAttribute($a), "atributo inyectado «{$a}»"); }
                }
            }
        }
    }

    public function test_R3b_08_idem_key_hostil_no_activa_ni_da_500_y_la_misma_clave_en_otra_cuenta_no_interfiere(): void
    {
        $email = self::correo('idem');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        [$n, $f] = $this->abrirFormulario($t);
        $this->assertNotNull($f);
        $antes = $this->fila($email);
        $hostiles = ['vacía' => '', 'comilla' => "' OR '1'='1' --", 'punto y coma' => "x'; DROP TABLE usuarios;--", 'larga' => str_repeat('a', 10000), 'nula' => "abc\0def", 'ruta' => '../../etc/passwd',
                     'unicode' => str_repeat('ñ', 40), 'saltos' => "abc\r\ndef", 'corta' => 'ab', 'espacios' => str_repeat(' ', 40), 'json' => '{"a":1}'];
        foreach ($hostiles as $nombre => $k) {
            $r = $n->post($f['accion'], $this->cuerpo($f, self::C, ['idem_key' => $k]));
            $this->assertLessThan(500, $r['status'], "idem_key «{$nombre}»: " . substr(strip_tags($r['body']), 0, 200));
            $d = $this->fila($email);
            $this->assertSame($antes['password'], $d['password'], "idem_key «{$nombre}» cambió la contraseña");
            $this->assertSame(0, (int)$d['confirmado'], "idem_key «{$nombre}» activó la cuenta con una clave inválida");
        }
        // idem_key como arreglo y ausente.
        $crudo = http_build_query(array_diff_key($this->cuerpo($f, self::C), ['idem_key' => 1])) . '&idem_key[]=x&idem_key[a][b]=y';
        $r = $n->post($f['accion'], $crudo);
        $this->assertLessThan(500, $r['status']);
        $r = $n->post($f['accion'], array_diff_key($this->cuerpo($f, self::C), ['idem_key' => 1]));
        $this->assertLessThan(500, $r['status']);
        $this->assertSame(0, (int)$this->fila($email)['confirmado'], 'sin clave de operación válida no debe activarse');
        // Una clave válida y NUEVA (no la del formulario) también es aceptable; la del formulario sigue valiendo.
        $ok = $n->post($f['accion'], $this->cuerpo($f, self::C));
        $this->assertSame(200, $ok['status']);
        // La MISMA clave de operación en otra cuenta (otro enlace) no es un conflicto ni arrastra la contraseña de la primera.
        $email2 = self::correo('idem2');
        $this->registrar($email2, self::A);
        $t2 = $this->ultimoToken($email2);
        [$n2, $f2] = $this->abrirFormulario($t2);
        $clave = $f['campos']['idem_key'] ?? null;
        $this->assertNotNull($clave);
        $ok2 = $n2->post($f2['accion'], $this->cuerpo($f2, 'Segunda-Cuenta-777', ['idem_key' => $clave]));
        $this->assertSame(200, $ok2['status'], 'la clave de operación de otra cuenta no debe bloquear esta activación: ' . substr(strip_tags($ok2['body']), 0, 200));
        $this->assertEntra($email2, 'Segunda-Cuenta-777', 'propia');
        $this->assertNoEntra($email2, self::C, 'de la otra cuenta');
        $this->assertNoEntra($email, 'Segunda-Cuenta-777', 'de la otra cuenta');
    }

    public function test_R3b_09_fijacion_de_sesion_un_identificador_impuesto_no_sobrevive_a_la_activacion_ni_al_inicio_de_sesion(): void
    {
        $fijo = bin2hex(random_bytes(16));
        $email = self::correo('fij');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        $url = '/confirmar-cuenta?token=' . $t;
        $n = new Navegador(self::$base);
        $cab = ['Cookie: PHPSESSID=' . $fijo];
        $g = $n->get($url, $cab);
        $f = $this->formulario($g['body'], $url);
        $this->assertNotNull($f);
        $r = $n->post($f['accion'], $this->cuerpo($f, self::C), $cab);
        $this->assertSame(200, $r['status']);
        $nuevo = null;
        foreach ($r['headers']['set-cookie'] ?? [] as $c) { if (preg_match('/^PHPSESSID=([^;]+)/', $c, $m)) { $nuevo = $m[1]; } }
        $this->assertNotNull($nuevo);
        $this->assertNotSame($fijo, $nuevo, 'tras activar sigue vigente el identificador de sesión impuesto');
        // Inicio de sesión con un identificador impuesto: el identificador cambia y el impuesto no da acceso.
        self::q('TRUNCATE intentos_login');
        $ln = new Navegador(self::$base);
        $fijo2 = bin2hex(random_bytes(16));
        $cab2 = ['Cookie: PHPSESSID=' . $fijo2];
        $csrf = Navegador::csrfDe($ln->get('/cliente/login', $cab2)['body']);
        $login = $ln->post('/cliente/login', ['csrf_token' => $csrf, 'email' => $email, 'password' => self::C], $cab2);
        $this->assertSame(302, $login['status']);
        $nuevoLogin = null;
        foreach ($login['headers']['set-cookie'] ?? [] as $c) { if (preg_match('/^PHPSESSID=([^;]+)/', $c, $m)) { $nuevoLogin = $m[1]; } }
        $this->assertNotNull($nuevoLogin, 'el inicio de sesión debe emitir un identificador nuevo');
        $this->assertNotSame($fijo2, $nuevoLogin);
        $this->assertNotSame(200, $this->accesoConSesion($fijo2), 'fijación de sesión: el identificador impuesto da acceso tras el login de la víctima');
        $this->assertSame(200, $this->accesoConSesion($nuevoLogin), 'la sesión nueva sí debe dar acceso');
    }

    public function test_R3b_10_el_correo_de_activacion_explica_que_la_contrasena_es_provisional_y_no_la_contiene(): void
    {
        $email = self::correo('mail');
        $this->registrar($email, self::A);
        $this->ultimoToken($email);
        $m = array_values(array_filter(self::buzonFallo(self::SMTP), fn($x) => in_array($email, $x['to'], true)));
        $this->assertCount(1, $m);
        $txt = html_entity_decode($m[0]['cuerpo']);
        $this->assertMatchesRegularExpression('/provisional/i', $txt, 'el correo debe decir que la contraseña del registro es provisional');
        $this->assertMatchesRegularExpression('/Elegir mi contrase.{1,3}a y activar/u', $txt);
        $this->assertStringContainsString('/confirmar-cuenta?token=', $txt);
        $this->assertStringNotContainsString(self::A, $m[0]['cuerpo'] . $m[0]['cabeceras'], 'el correo no debe contener la contraseña enviada en el registro');
        $this->assertDoesNotMatchRegularExpression('/Confirmar mi cuenta/u', $txt, 'queda el texto antiguo del botón');
    }

    // ------------------------------------------------------------------ sesiones legadas y flujos legítimos

    public function test_R3b_11_la_regla_de_sesiones_legadas_solo_afecta_a_cuentas_que_invalidaron_versiones_y_no_rompe_flujos_legitimos(): void
    {
        // (a) Cuenta que nunca invalidó sesiones (versión 1): una sesión legada SIN marca sigue siendo válida (instalaciones anteriores a la marca).
        $c = self::cliente(['email' => self::correo('leg')]);
        $this->assertSame(1, (int)self::escalar('SELECT version_sesion FROM usuarios WHERE id=?', [$c['id']]));
        $datos = ['csrf_token' => bin2hex(random_bytes(32)), 'id' => (string)$c['id'], 'nombre' => 'N', 'apellido' => 'A', 'email' => $c['email'], 'login' => true, 'portal' => 'cliente', 'rol' => 'cliente'];
        $this->assertSame(200, $this->accesoConSesion(self::forjarSesion($datos, 'r3')), 'sesión legada sin marca de una cuenta de versión 1: debe seguir funcionando');
        // (b) Login normal: la sesión nueva lleva la marca y funciona en cliente (/cita, /perfil, /mis-citas) y deja de valer al subir la versión.
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('cliente', $c['email'], $c['password'])['status']);
        foreach (['/cita', '/perfil', '/mis-citas'] as $ruta) { $this->assertSame(200, $n->get($ruta)['status'], $ruta); }
        self::q('UPDATE usuarios SET version_sesion=version_sesion+1 WHERE id=?', [$c['id']]);
        $this->assertNotSame(200, $n->get('/cita')['status'], 'tras subir la versión la sesión con marca debe invalidarse');
        // (c) Tras la activación, un login normal en la nueva versión funciona de extremo a extremo.
        $email = self::correo('act');
        $this->registrar($email, self::A);
        $this->activar($this->ultimoToken($email), self::C);
        self::q('TRUNCATE intentos_login');
        $n2 = new Navegador(self::$base);
        $this->assertSame(302, $n2->login('cliente', $email, self::C)['status']);
        foreach (['/cita', '/perfil', '/mis-citas'] as $ruta) { $this->assertSame(200, $n2->get($ruta)['status'], "cuenta recién activada: $ruta"); }
        // (d) Recuperación de contraseña de una cuenta activa (sube la versión): el nuevo login funciona y el anterior queda invalidado.
        $n2->post('/olvide', ['csrf_token' => $n2->csrf('/olvide'), 'email' => $email]);
        self::entregarCola(self::SMTP);
        $rec = null;
        foreach (self::buzonFallo(self::SMTP) as $m) { if (in_array($email, $m['to'], true)) { $rec = self::tokenEnCorreo($m, '/recuperar') ?? $rec; } }
        $this->assertNotNull($rec);
        $nr = new Navegador(self::$base);
        $this->assertSame(302, $nr->post('/recuperar?token=' . $rec, ['csrf_token' => $nr->csrf('/recuperar?token=' . $rec), 'password' => 'Nueva-Clave-Rec-1'])['status']);
        $this->assertNotSame(200, $n2->get('/cita')['status'], 'la recuperación debe invalidar la sesión anterior');
        self::q('TRUNCATE intentos_login');
        $n3 = new Navegador(self::$base);
        $this->assertSame(302, $n3->login('cliente', $email, 'Nueva-Clave-Rec-1')['status']);
        $this->assertSame(200, $n3->get('/cita')['status']);
    }

    public function test_R3b_12_el_personal_con_sesion_normal_sigue_entrando_a_su_panel_y_el_cambio_de_rol_invalida_su_sesion(): void
    {
        $dueno = self::$dueno;
        $rec = self::miembro($dueno['id'], 'recepcion');
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('equipo', $rec['email'], $rec['password'])['status']);
        $this->assertSame(200, $n->get('/admin')['status'], 'recepción entra a su panel');
        $o = new Navegador(self::$base);
        $this->assertSame(302, $o->login('equipo', $dueno['email'], $dueno['password'])['status']);
        $this->assertSame(200, $o->get('/admin')['status']);
        // Cambio de rol hecho por el propietario (servicio real): la sesión previa se invalida y un nuevo login funciona.
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $this->assertSame('ok', $svc->cambiarAcceso($dueno['id'], $rec['colaborador_id'], 'finanzas', 'activo')['status']);
        $this->assertNotSame(200, $n->get('/admin')['status'], 'la sesión previa del colaborador debe invalidarse tras el cambio de rol');
        self::q('TRUNCATE intentos_login');
        $n2 = new Navegador(self::$base);
        $this->assertSame(302, $n2->login('equipo', $rec['email'], $rec['password'])['status']);
        $this->assertSame(200, $n2->get('/admin/finanzas')['status'], 'con el rol nuevo entra a finanzas');
    }

    // ------------------------------------------------------------------ otros caminos que fijan contraseñas

    public function test_R3b_13_un_token_de_recuperacion_residual_en_una_cuenta_sin_confirmar_no_fija_contrasena_ni_confirma(): void
    {
        $email = self::correo('resrec');
        $this->registrar($email, self::A);
        $antes = $this->fila($email);
        $tok = bin2hex(random_bytes(32));
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='recuperacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $tok), $antes['id']]);
        $n = new Navegador(self::$base);
        $g = $n->get('/recuperar?token=' . $tok);
        $r = $n->post('/recuperar?token=' . $tok, ['csrf_token' => $n->csrf('/olvide'), 'password' => self::C]);
        $d = $this->fila($email);
        $this->assertSame(0, (int)$d['confirmado'], 'una recuperación no debe confirmar una cuenta');
        $this->assertSame($antes['password'], $d['password'], 'una recuperación residual fijó la contraseña de una cuenta sin confirmar (GET ' . $g['status'] . ', POST ' . $r['status'] . ')');
        $this->assertNoEntra($email, self::C, 'C');
    }

    public function test_R3b_14_el_alta_de_recepcion_no_envia_correo_ni_deja_una_contrasena_utilizable_y_la_invitacion_reemplaza_la_provisional(): void
    {
        // Invitación del equipo a un correo que un tercero pre-registró como cliente sin confirmar: quien acepta ELIGE la contraseña y A queda descartada.
        $dueno = self::$dueno;
        $email = self::correo('invprev');
        $this->registrar($email, self::A);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $inv = $repo->crearInvitacion($dueno['id'], ['nombre' => 'In', 'apellido' => 'Vi', 'email' => $email, 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($inv['invitacion_id']), self::C, '');
        $this->assertSame(1, (int)$this->fila($email)['confirmado']);
        $r = $this->entra($email, self::A);
        $this->assertSame([], array_keys(array_filter($r)), 'la contraseña provisional del pre-registro sobrevivió a la aceptación de la invitación: ' . json_encode($r));
        $this->assertTrue($this->entra($email, self::C)['/equipo/login'], 'la contraseña elegida al aceptar debe entrar al portal del equipo');
    }

    // ------------------------------------------------------------------ envío inmediato: HTTP real y advertencia

    public function test_R3b_15_la_advertencia_de_envio_inmediato_ignorado_sale_una_vez_por_peticion_y_solo_fuera_de_pruebas(): void
    {
        $contar = function (array $env, string $clave): int {
            self::detenerSmtpFallo(self::SMTP);
            self::iniciarSmtpFallo(self::SMTP, 'ok');
            $url = self::servidor($env + ['EMAIL_PORT' => (string)self::SMTP, 'APP_KEY' => str_repeat('k', 40)], $clave);
            try {
                for ($i = 0; $i < 3; $i++) { self::q('TRUNCATE intentos_login'); self::registrarHttp(new Navegador($url), self::correo('adv')); }
                $n = new Navegador($url);
                self::q('TRUNCATE intentos_login');
                $n->post('/olvide', ['csrf_token' => $n->csrf('/olvide'), 'email' => self::cliente(['email' => self::correo('advc')])['email']]);
                return substr_count(self::logServidor($clave), 'CORREOS_ENVIO_INMEDIATO ignorado');
            } finally { self::pararServidor($clave); }
        };
        $this->assertSame(0, $contar(['APP_ENV' => 'production'], 'r3b_adv_sin'), 'sin la variable no debe haber advertencia');
        $this->assertSame(0, $contar(['APP_ENV' => 'testing', 'CORREOS_ENVIO_INMEDIATO' => '1'], 'r3b_adv_test'), 'en pruebas la variable se respeta y no hay advertencia');
        $n = $contar(['APP_ENV' => 'production', 'CORREOS_ENVIO_INMEDIATO' => '1'], 'r3b_adv_prod');
        $this->assertGreaterThanOrEqual(1, $n, 'en producción con la variable activa debe quedar una advertencia en el log');
        // 4 peticiones que encolan correo: la advertencia es por petición (el indicador estático no persiste entre peticiones PHP), nunca más de una por petición.
        $this->assertLessThanOrEqual(4, $n, "más de una advertencia por petición: $n líneas para 4 peticiones");
        fwrite(STDERR, "\n[R3b_15] advertencias en el log del servidor para 4 peticiones: $n\n");
    }

    public function test_R3b_16_entradas_deformes_en_la_activacion_no_dan_500_ni_activan_con_una_contrasena_truncada(): void
    {
        $email = self::correo('deforme');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        [$n, $f] = $this->abrirFormulario($t);
        $this->assertNotNull($f);
        $claveToken = array_search($t, $f['campos'], true);
        $antes = $this->fila($email);
        $p1 = $f['password'][0]; $p2 = $f['password'][1] ?? $f['password'][0];
        $base = $f['campos'];
        $largo = 'Aa1!' . str_repeat('x', 96);   // 100 caracteres: bcrypt solo mira los primeros 72 bytes
        $casos = [
            'token como arreglo' => http_build_query(array_diff_key($base, [$claveToken => 1])) . '&' . $claveToken . '[]=x',
            'password como arreglo' => http_build_query(array_diff_key($base, [$p1 => 1])) . '&' . $p1 . '[]=x',
            'password2 como arreglo' => http_build_query(array_diff_key($base, [$p2 => 1, $p1 => 1])) . '&' . $p1 . '=' . self::C . '&' . $p2 . '[a]=x',
            'solo token' => http_build_query([$claveToken => $t]),
            'cuerpo vacío' => '',
            'contraseña de 100 caracteres' => http_build_query(array_merge($base, [$p1 => $largo, $p2 => $largo])),
            'contraseña de 10000 caracteres' => http_build_query(array_merge($base, [$p1 => str_repeat('a', 10000), $p2 => str_repeat('a', 10000)])),
        ];
        foreach ($casos as $nombre => $crudo) {
            $r = $n->post($f['accion'], $crudo);
            $this->assertLessThan(500, $r['status'], "«{$nombre}»: " . substr(strip_tags($r['body']), 0, 200));
            $d = $this->fila($email);
            if ($nombre === 'contraseña de 100 caracteres' && (int)$d['confirmado'] === 1) {
                // Si se acepta, el prefijo de 72 caracteres NO debe valer por sí solo (truncado silencioso de bcrypt).
                $this->assertFalse($this->entra($email, substr($largo, 0, 72))['/cliente/login'], 'la contraseña de 100 caracteres se acepta y su prefijo de 72 también inicia sesión (truncado silencioso de bcrypt)');
                return;
            }
            $this->assertSame(0, (int)$d['confirmado'], "«{$nombre}» activó la cuenta");
            $this->assertSame($antes['password'], $d['password'], "«{$nombre}» cambió la contraseña");
        }
        $this->assertSame(200, $n->post($f['accion'], $this->cuerpo($f, self::C))['status'], 'tras los intentos deformes el enlace sigue sirviendo');
    }

    public function test_R3b_17_activar_una_cuenta_suspendida_no_la_reactiva_ni_le_da_acceso(): void
    {
        $email = self::correo('susp');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        $id = (int)$this->fila($email)['id'];
        self::q("UPDATE clientes SET estado='suspendido', suspendido_motivo='Impagos' WHERE usuario_id=?", [$id]);
        $this->activar($t, self::C);
        $this->assertSame('suspendido', self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$id]), 'activar el correo no debe reactivar una cuenta suspendida');
        $this->assertNoEntra($email, self::C, 'C de una cuenta suspendida');
        $this->assertNoEntra($email, self::A, 'A');
    }

    public function test_R3b_18_el_tiempo_de_la_activacion_no_distingue_un_enlace_valido_de_uno_inexistente(): void
    {
        $validos = []; $invalidos = [];
        for ($i = 0; $i < 6; $i++) {
            $email = self::correo('tim' . $i);
            $this->registrar($email, self::A);
            [$n, $f] = $this->abrirFormulario($this->ultimoToken($email));
            self::q('TRUNCATE intentos_login');
            $t0 = microtime(true); $n->post($f['accion'], $this->cuerpo($f, self::C)); $validos[] = (microtime(true) - $t0) * 1000;
        }
        $this->assertNotEmpty($validos);
        // Enlaces inexistentes: el mismo formulario con un token inventado (se necesita un formulario real para el CSRF y la clave de operación).
        $email = self::correo('timf');
        $this->registrar($email, self::A);
        [$nf, $ff] = $this->abrirFormulario($this->ultimoToken($email));
        $claveTok = null; foreach ($ff['campos'] as $k => $v) { if (strlen($v) === 64 && ctype_xdigit($v)) { $claveTok = $k; } }
        for ($i = 0; $i < 6; $i++) {
            self::q('TRUNCATE intentos_login');
            $t0 = microtime(true); $nf->post($ff['accion'], $this->cuerpo($ff, self::C, [$claveTok => bin2hex(random_bytes(32)), 'idem_key' => Idem::clave()])); $invalidos[] = (microtime(true) - $t0) * 1000;
        }
        sort($validos); sort($invalidos);
        $mv = $validos[intdiv(count($validos), 2)]; $mi = $invalidos[intdiv(count($invalidos), 2)];
        $this->assertLessThan(2.5, max($mv, $mi) / max(1.0, min($mv, $mi)), sprintf('mediana de POST con enlace válido %.0f ms frente a inexistente %.0f ms', $mv, $mi));
    }

    /**
     * R3b-19 (rojo, Baja): una contraseña con un byte NUL (`%00`) provoca un 500 en TODOS los puntos donde se fija una contraseña con bcrypt. PHP 8.3
     * lanza `ValueError: Argument #1 ($password) must not contain any null bytes` en `password_hash()` y la aplicación no lo valida antes: debía
     * responder 422 como con cualquier otra contraseña inválida. Reproducción mínima: `POST /cliente/registro` con `password=Clave%00Nula1`.
     * Sin efecto sobre datos (la transacción no llega a escribir), pero es un 500 provocable por cualquiera y deja trazas de error.
     */
    public function test_Defecto_R3b_19_una_contrasena_con_byte_nulo_da_500_en_registro_activacion_y_recuperacion(): void
    {
        $nul = "Clave\0-Nula-1";
        $fallos = [];
        // Registro
        self::q('TRUNCATE intentos_login');
        $r = self::registrarHttp(new Navegador(self::$base), self::correo('nulreg'), ['password' => $nul]);
        if ($r['status'] >= 500) { $fallos[] = "registro => {$r['status']}"; }
        // Activación
        $email = self::correo('nulact');
        $this->registrar($email, self::A);
        [$n, $f] = $this->abrirFormulario($this->ultimoToken($email));
        $a = $n->post($f['accion'], $this->cuerpo($f, $nul));
        if ($a['status'] >= 500) { $fallos[] = "activación => {$a['status']}"; }
        $this->assertSame(0, (int)$this->fila($email)['confirmado']);
        // Recuperación
        $c = self::cliente(['email' => self::correo('nulrec')]);
        $on = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $on->post('/olvide', ['csrf_token' => $on->csrf('/olvide'), 'email' => $c['email']]);
        self::entregarCola(self::SMTP);
        $tok = null;
        foreach (self::buzonFallo(self::SMTP) as $m) { if (in_array($c['email'], $m['to'], true)) { $tok = self::tokenEnCorreo($m, '/recuperar') ?? $tok; } }
        $this->assertNotNull($tok);
        $rn = new Navegador(self::$base);
        $rr = $rn->post('/recuperar?token=' . $tok, ['csrf_token' => $rn->csrf('/olvide'), 'password' => $nul]);
        if ($rr['status'] >= 500) { $fallos[] = "recuperación => {$rr['status']}"; }
        $this->assertSame([], $fallos, 'una contraseña con byte NUL provoca 500 en: ' . implode(', ', $fallos));
    }

    public function test_R3b_20_el_limite_por_huella_de_token_salta_antes_que_el_de_ip_y_no_afecta_a_otros_enlaces(): void
    {
        $email = self::correo('lt');
        $this->registrar($email, self::A);
        $t = $this->ultimoToken($email);
        [$n, $f] = $this->abrirFormulario($t);
        $claveToken = array_search($t, $f['campos'], true);
        self::q('TRUNCATE intentos_login');
        $inventado = bin2hex(random_bytes(32));
        $estados = [];
        for ($i = 0; $i < 10; $i++) { $estados[] = $n->post($f['accion'], $this->cuerpo($f, self::C, [$claveToken => $inventado]))['status']; }
        $primero = array_search(429, $estados, true);
        $this->assertNotFalse($primero, 'el mismo token inventado repetido 10 veces debe limitarse: ' . json_encode($estados));
        $this->assertLessThanOrEqual(7, $primero, 'el límite por huella de token debe saltar antes que el de IP (documentado: 5 por token)');
        // Otro token distinto (el real) sigue sirviendo: el límite por huella no es un bloqueo global.
        $this->assertSame(200, $n->post($f['accion'], $this->cuerpo($f, self::C))['status'], 'el enlace real no debe verse afectado por el abuso de OTRO token: ' . json_encode($estados));
        $this->assertEntra($email, self::C, 'C');
    }

    public function test_R3b_21_la_activacion_reinicia_los_contadores_de_la_cuenta_bloqueada_por_intentos_de_login_ajenos(): void
    {
        $email = self::correo('bloq');
        $this->registrar($email, self::A);
        // Un tercero agota los intentos de inicio de sesión sobre el correo del titular (bloqueo por correo).
        $bloqueado = false;
        for ($i = 0; $i < 12 && !$bloqueado; $i++) {
            $n = new Navegador(self::$base);
            $r = $n->login('cliente', $email, 'Incorrecta-' . $i);
            $bloqueado = $r['status'] === 429 || stripos($r['body'], 'demasiados') !== false;
        }
        $this->assertTrue($bloqueado, 'la cuenta debía quedar bloqueada por intentos de login (precondición)');
        // El titular activa (la activación usa sus propios contadores, no los de login) y debe poder entrar de inmediato con su contraseña.
        $act = $this->activar($this->ultimoToken($email), self::C);
        $this->assertSame(200, $act['post']['status'] ?? 0, 'la activación no debe estar bloqueada por el límite de login de la cuenta');
        $n2 = new Navegador(self::$base);
        $this->assertSame(302, $n2->login('cliente', $email, self::C)['status'], 'tras activar, los contadores de la cuenta se reinician y el titular puede entrar sin esperar el bloqueo');
    }

    public function test_R3b_22_un_post_directo_con_un_token_residual_de_una_cuenta_ya_confirmada_no_cambia_su_contrasena(): void
    {
        // Se salta el GET (que ya no ofrece formulario para esa cuenta) y se fabrica el POST con un CSRF y una clave de operación válidos.
        foreach (['activa' => [], 'suspendida' => ['estado' => 'suspendido']] as $caso => $extra) {
            $c = self::cliente(['email' => self::correo('res-' . $caso)] + $extra);
            $token = bin2hex(random_bytes(32));
            self::q("UPDATE usuarios SET token_hash=?, token_tipo='confirmacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $token), $c['id']]);
            $antes = $this->fila($c['email']);
            $n = new Navegador(self::$base);
            $csrf = $n->csrf('/olvide');
            self::q('TRUNCATE intentos_login');
            // RONDA 4: el POST de activación exige además nombre, apellido y teléfono (si faltan es 422 sin tocar nada); aquí se envían válidos para llegar al token.
            $r = $n->post('/confirmar-cuenta', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'token' => $token, 'password' => self::C, 'password2' => self::C,
                'nombre' => 'Intruso', 'apellido' => 'Residual', 'telefono' => '0990001111']);
            $this->assertSame(410, $r['status'], "$caso: el token residual de una cuenta confirmada debe dar el 410 genérico");
            $this->assertSame($antes['nombre'], $this->fila($c['email'])['nombre'], "$caso: el POST con token residual cambió el nombre");
            $d = $this->fila($c['email']);
            $this->assertSame($antes['password'], $d['password'], "$caso: el POST con token residual cambió la contraseña");
            $this->assertSame($antes['version_sesion'], $d['version_sesion'], "$caso: subió version_sesion");
            $this->assertNoEntra($c['email'], self::C, "C ($caso)");
        }
    }
}
