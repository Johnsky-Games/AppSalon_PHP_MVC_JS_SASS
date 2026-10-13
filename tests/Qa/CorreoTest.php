<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/** D/G. Contenido real de los correos (SMTP simulado propio): enlaces, cabecera Host, inyección y comportamiento ante SMTP caído. */
final class CorreoTest extends QaCase
{
    private static string $ok;
    private static string $caido;
    private static array $dueno;
    private static Navegador $owner;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::iniciarSmtp(2596);
        self::$ok = self::servidor(['EMAIL_PORT' => '2596', 'APP_URL' => 'https://salon.example.test'], 'ok');
        self::$caido = self::servidor(['EMAIL_PORT' => '2599'], 'caido');
        self::$dueno = self::propietario('dueno@example.test');
        self::$owner = new Navegador(self::$ok);
        self::$owner->login('equipo', self::$dueno['email'], self::$dueno['password']);
    }

    public static function tearDownAfterClass(): void { self::pararServidor('ok'); self::pararServidor('caido'); self::detenerSmtp(2596); }

    private function invitarPorHttp(string $email, array $cab = [], array $extra = []): array
    {
        $csrf = Navegador::csrfDe(self::$owner->get('/admin/equipo')['body']);
        $r = self::$owner->post('/admin/equipo/crear', $extra + ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Ana', 'apellido' => 'Lopez', 'email' => $email, 'telefono' => '', 'puesto' => 'Caja', 'rol' => 'recepcion', 'fecha_ingreso' => ''], $cab);
        usleep(300000);
        return $r;
    }

    private function correosPara(string $email): array
    {
        return array_values(array_filter(self::buzon(2596), fn($m) => in_array($email, $m['to'], true)));
    }

    public function test_el_enlace_de_invitacion_usa_app_url_y_no_la_cabecera_host(): void
    {
        $email = self::correo('host');
        // libcurl selecciona las cookies según la cabecera Host manipulada: se envía la sesión explícitamente.
        $this->invitarPorHttp($email, ['Host: evil.example.test', 'X-Forwarded-Host: evil.example.test', 'Cookie: PHPSESSID=' . self::$owner->cookieSesion()]);
        $m = $this->correosPara($email);
        $this->assertCount(1, $m);
        $this->assertStringContainsString('https://salon.example.test/equipo/aceptar?token=', $m[0]['cuerpo']);
        $this->assertStringNotContainsString('evil.example.test', $m[0]['cuerpo'] . $m[0]['cabeceras']);
    }

    public function test_correo_contiene_un_solo_token_valido_sin_contrasenas_ni_datos_de_terceros(): void
    {
        $email = self::correo('contenido');
        $this->invitarPorHttp($email, [], ['puesto' => '<b>Caja</b>"x']);
        $m = $this->correosPara($email)[0];
        preg_match_all('/token=([a-f0-9]{64})/', $m['cuerpo'], $t);
        $this->assertCount(1, array_unique($t[1]), 'debe haber un único token distinto');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaborador_invitaciones WHERE token_hash=? AND estado=\'pendiente\'', [hash('sha256', $t[1][0])]));
        $this->assertStringContainsString('&lt;b&gt;Caja&lt;/b&gt;', $m['cuerpo'], 'el puesto debe escaparse en la parte HTML del correo');
        $this->assertSame(1, substr_count($m['cuerpo'], '<b>Caja</b>'), 'la etiqueta literal solo puede aparecer en la parte de texto plano');
        $this->assertStringNotContainsString(self::$dueno['email'], $m['cuerpo'], 'el correo no debe revelar el correo del propietario');
        $this->assertStringNotContainsString(self::PASS, $m['cuerpo']);
        // Tras el envío correcto la carga cifrada con el token ya no existe en la base.
        $this->assertNull(self::escalar("SELECT carga_cifrada FROM correos_salida WHERE destinatario_email=?", [$email]));
        $this->assertSame('enviado', self::escalar("SELECT estado FROM correos_salida WHERE destinatario_email=?", [$email]));
        $this->assertSame('', self::escalar("SELECT IFNULL(ultimo_error,'') FROM correos_salida WHERE destinatario_email=?", [$email]));
    }

    public function test_enlace_del_correo_acepta_la_invitacion_de_extremo_a_extremo(): void
    {
        $email = self::correo('e2e');
        $this->invitarPorHttp($email);
        $tok = self::tokenEnCorreo($this->correosPara($email)[0], '/equipo/aceptar');
        $this->assertNotNull($tok);
        $n = new Navegador(self::$ok);
        $csrf = Navegador::csrfDe($n->get('/equipo/aceptar?token=' . $tok)['body']);
        $r = $n->post('/equipo/aceptar?token=' . $tok, ['csrf_token' => $csrf, 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123', 'acepto' => '1']);
        $this->assertSame(302, $r['status']);
        $this->assertSame('/equipo/login?invitacion=aceptada', $r['location']);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('equipo', $email, 'Nueva-Pass-123')['status']);
        $this->assertSame(200, $n->get('/admin')['status']);
        $this->assertSame(403, $n->get('/admin/equipo')['status']);
    }

    public function test_un_envio_doble_con_la_misma_clave_manda_un_solo_correo(): void
    {
        $email = self::correo('doble');
        $csrf = Navegador::csrfDe(self::$owner->get('/admin/equipo')['body']);
        $campos = ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Ana', 'apellido' => 'Lopez', 'email' => $email, 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => ''];
        self::$owner->post('/admin/equipo/crear', $campos);
        self::$owner->post('/admin/equipo/crear', $campos);
        usleep(300000);
        $this->assertCount(1, $this->correosPara($email));
    }

    /**
     * CAMBIO DE CONTRATO (ronda 2, ítem 4): el correo de confirmación del registro YA usa el outbox (`correos_salida`). Con el SMTP caído
     * el alta no falla, el evento queda `pendiente` (1 intento fallido) y el worker lo entrega cuando el SMTP vuelve. La garantía original
     * se mantiene y se refuerza: no se pierde la cuenta ni el correo, y la pantalla sigue ofreciendo «Reenviar» como vía manual.
     * (La cobertura profunda de SMTP caído/colgado/4xx/5xx y del worker real está en R2bColaCorreosTest.)
     */
    public function test_registro_con_smtp_caido_informa_la_via_de_recuperacion_y_no_pierde_la_cuenta(): void
    {
        $email = self::correo('regcaido');
        $n = new Navegador(self::$caido);
        $csrf = $n->csrf('/cliente/registro');
        $r = $n->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'A', 'apellido' => 'B', 'email' => $email, 'telefono' => '0991112222', 'password' => 'Abcdef123456']);
        $this->assertSame(302, $r['status']);
        $this->assertSame(0, (int)self::escalar('SELECT confirmado FROM usuarios WHERE email=?', [$email]));
        $ev = self::q("SELECT estado, intentos, ultimo_error FROM correos_salida WHERE destinatario_email=? AND tipo='confirmacion_cuenta'", [$email]);
        $this->assertCount(1, $ev, 'registro: el correo de confirmación debe quedar en la cola (outbox), exactamente un evento');
        $this->assertSame('pendiente', $ev[0]['estado'], 'con el SMTP caído el evento queda pendiente para el worker');
        $this->assertSame(1, (int)$ev[0]['intentos']);
        $this->assertNotEmpty($ev[0]['ultimo_error']);
        $pagina = $n->get('/mensaje');
        $this->assertStringContainsString('/reenviar-confirmacion', $pagina['body'], 'la pantalla debe ofrecer reenviar el correo');
        // El reenvío por la vía oficial funciona cuando el SMTP vuelve, y deja UN solo evento activo (el anterior queda cancelado).
        $n2 = new Navegador(self::$ok);
        $csrf = $n2->csrf('/reenviar-confirmacion');
        $antes = count(self::buzon(2596));
        $n2->post('/reenviar-confirmacion', ['csrf_token' => $csrf, 'email' => $email]);
        usleep(400000);
        $this->assertGreaterThan($antes, count(self::buzon(2596)));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE destinatario_email=? AND tipo='confirmacion_cuenta' AND estado IN ('pendiente','enviando','enviado')", [$email]), 'tras el reenvío solo un evento no cancelado');
        $this->assertSame('cancelado', self::escalar("SELECT estado FROM correos_salida WHERE id=?", [self::escalar("SELECT MIN(id) FROM correos_salida WHERE destinatario_email=? AND tipo='confirmacion_cuenta'", [$email])]));
    }

    public function test_Defecto_el_outbox_de_correos_no_tiene_proceso_programado_ni_documentacion_operativa(): void
    {
        $compose = (string)file_get_contents(self::RAIZ . '/docker-compose.yml');
        $docs = '';
        foreach (glob(self::RAIZ . '/docs/*.md') ?: [] as $f) { if (basename($f) === 'QA_INDEPENDIENTE_IDENTIDADES.md') { continue; } $docs .= file_get_contents($f); }
        $docs .= (string)file_get_contents(self::RAIZ . '/database/README.md');
        $cableado = str_contains($compose, 'procesar_correos') || str_contains($docs . (string)file_get_contents(self::RAIZ . '/GUIA_ENTREGA_1.md'), 'procesar_correos');
        $this->assertTrue($cableado, 'scripts/procesar_correos.php (reintento de invitaciones) no figura en docker-compose, ni cron, ni en la documentación: los correos fallidos solo se reintentan cuando alguien abre GET /admin/equipo');
    }
}
