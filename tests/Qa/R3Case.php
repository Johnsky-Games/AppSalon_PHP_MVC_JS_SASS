<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * Arnés común de la ronda 3 (activación de cuenta por enlace de correo): servidor HTTP real con 8 procesos, SMTP simulado propio (2593),
 * worker real de correos, formulario de activación genérico y sondas de inicio de sesión. No contiene pruebas.
 */
abstract class R3Case extends R2bCase
{
    protected const SMTP = 2593;
    protected const A = 'Atacante-Clave-111';
    protected const B = 'Titular-Clave-222';
    protected const C = 'Elegida-Buzon-333';
    protected static string $base;

    protected function setUp(): void
    {
        self::q('TRUNCATE intentos_login');
        self::q('DELETE FROM correos_salida');
        self::modoSmtp(self::SMTP, 'ok');
        self::vaciarBuzonFallo(self::SMTP);
    }

    protected function tearDown(): void
    {
        if (self::$smtpLento) { self::$smtpLento = false; self::detenerSmtpFallo(self::SMTP); self::iniciarSmtpFallo(self::SMTP, 'ok'); }
    }

    protected static bool $smtpLento = false;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::iniciarSmtpFallo(self::SMTP, 'ok');
        self::$base = self::servidor(['EMAIL_PORT' => (string)self::SMTP, 'PHP_CLI_SERVER_WORKERS' => '8'], 'r3');
    }

    public static function tearDownAfterClass(): void
    {
        self::pararServidor('r3');
        self::detenerSmtpFallo(self::SMTP);
    }

    // ------------------------------------------------------------------ utilidades

    protected function registrar(string $email, string $clave): array
    {
        self::q('TRUNCATE intentos_login');
        $r = self::registrarHttp(new Navegador(self::$base), $email, ['password' => $clave]);
        $this->assertSame(302, $r['status'], 'el registro debe responder 302: ' . substr(strip_tags($r['body']), 0, 200));
        return $r;
    }

    protected function reenviar(string $email): void
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => $email]);
    }

    /** Ejecuta el worker real y devuelve el token de confirmación del ÚLTIMO correo recibido por `$email` (null si no hay). */
    protected function ultimoToken(string $email): ?string
    {
        self::entregarCola(self::SMTP);
        $tok = null;
        foreach (self::buzonFallo(self::SMTP) as $m) { if (in_array($email, $m['to'], true)) { $tok = self::tokenEnCorreo($m, '/confirmar-cuenta') ?? $tok; } }
        return $tok;
    }

    protected function fila(string $email): array
    {
        return self::q('SELECT id, nombre, apellido, telefono, password, confirmado, token_hash, token_tipo, token_expira, version_sesion FROM usuarios WHERE email=?', [$email])[0];
    }

    /** Activación genérica con la base de este servidor (ver R2bCase::activarEnlace). */
    protected function activar(string $token, string $clave, ?Navegador $n = null): array
    {
        return $this->activarEnlace(self::$base, $token, $clave, $n);
    }

    /** ¿Se puede iniciar sesión con esa contraseña? Por los tres accesos web reales. @return array<string,bool> */
    protected function entra(string $email, string $clave): array
    {
        $r = [];
        self::q('TRUNCATE intentos_login');
        $r['/cliente/login'] = (new Navegador(self::$base))->login('cliente', $email, $clave)['status'] === 302;
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $r['/'] = $n->post('/', ['csrf_token' => $n->csrf('/'), 'email' => $email, 'password' => $clave])['status'] === 302;
        self::q('TRUNCATE intentos_login');
        $r['/equipo/login'] = (new Navegador(self::$base))->login('equipo', $email, $clave)['status'] === 302;
        $n = new Navegador(self::$base);
        $r['/login'] = in_array($n->post('/login', ['csrf_token' => 'x', 'email' => $email, 'password' => $clave])['status'], [302, 200], true);
        return $r;
    }

    protected function assertNoEntra(string $email, string $clave, string $quien): void
    {
        $r = $this->entra($email, $clave);
        $this->assertSame([], array_keys(array_filter($r)), "la contraseña $quien ({$clave}) no debe iniciar sesión por ningún acceso: " . json_encode($r));
    }

    protected function assertEntra(string $email, string $clave, string $quien): void
    {
        $r = $this->entra($email, $clave);
        $this->assertTrue($r['/cliente/login'], "la contraseña $quien ({$clave}) debe iniciar sesión en /cliente/login: " . json_encode($r));
    }

    /**
     * Arranca un servidor real con `$env`, un SMTP de 400 ms y CORREOS_ENVIO_INMEDIATO=1 y mide registro, /olvide y /reenviar-confirmacion.
     * @return array<string,array{ms:float,correos:int}>
     */
    protected function medirEnvio(string $clave, array $env): array
    {
        self::detenerSmtpFallo(self::SMTP);
        self::iniciarSmtpFallo(self::SMTP, 'lento', 0.4);
        self::$smtpLento = true;
        $url = self::servidor($env + ['EMAIL_PORT' => (string)self::SMTP, 'CORREOS_ENVIO_INMEDIATO' => '1', 'APP_KEY' => str_repeat('k', 40)], $clave);
        $res = [];
        try {
            $conf = self::cliente(['email' => self::correo('e-conf')])['email'];
            $pend = self::cliente(['email' => self::correo('e-pend'), 'confirmado' => false])['email'];
            $nuevo = self::correo('e-nuevo');
            $pasos = [
                'registro' => [$nuevo, function (string $e) use ($url) { return self::registrarHttp(new Navegador($url), $e); }],
                'olvide' => [$conf, function (string $e) use ($url) { $n = new Navegador($url); return $n->post('/olvide', ['csrf_token' => $n->csrf('/olvide'), 'email' => $e]); }],
                'reenviar' => [$pend, function (string $e) use ($url) { $n = new Navegador($url); return $n->post('/reenviar-confirmacion', ['csrf_token' => $n->csrf('/reenviar-confirmacion'), 'email' => $e]); }],
            ];
            foreach ($pasos as $nombre => [$email, $f]) {
                self::q('TRUNCATE intentos_login');
                self::vaciarBuzonFallo(self::SMTP);
                $t0 = microtime(true);
                $r = $f($email);
                $ms = (microtime(true) - $t0) * 1000;
                $correos = count(array_filter(self::buzonFallo(self::SMTP), static fn($m) => in_array($email, $m['to'], true)));
                $res[$nombre] = ['ms' => round($ms), 'correos' => $correos, 'status' => $r['status']];
            }
        } finally { self::pararServidor($clave); }
        return $res;
    }

    /**
     * Lanza todas las peticiones A LA VEZ (curl_multi). @param array<int,array{cookie?:?string,ruta:string,body?:?array,cab?:string[]}> $reqs
     * @return array<int,array{status:int,body:string}>
     */
    protected function rafagaHttp(array $reqs): array
    {
        $mh = curl_multi_init(); $hs = [];
        foreach ($reqs as $i => $r) {
            $h = curl_init(self::$base . $r['ruta']);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $r['cab'] ?? []];
            if (!empty($r['cookie'])) { $o[CURLOPT_COOKIE] = 'PHPSESSID=' . $r['cookie']; }
            if (($r['body'] ?? null) !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($r['body']); }
            curl_setopt_array($h, $o);
            curl_multi_add_handle($mh, $h); $hs[$i] = $h;
        }
        do { curl_multi_exec($mh, $act); curl_multi_select($mh, 0.05); } while ($act > 0);
        $res = [];
        foreach ($hs as $i => $h) { $res[$i] = ['status' => (int)curl_getinfo($h, CURLINFO_HTTP_CODE), 'body' => (string)curl_multi_getcontent($h)]; curl_multi_remove_handle($mh, $h); curl_close($h); }
        curl_multi_close($mh);
        return $res;
    }

    /** GET del formulario de activación con un navegador nuevo: devuelve [navegador, formulario|null]. */
    protected function abrirFormulario(string $token): array
    {
        $n = new Navegador(self::$base);
        $url = '/confirmar-cuenta?token=' . $token;
        return [$n, $this->formulario($n->get($url)['body'], $url)];
    }

    /** Cuerpo POST de un formulario de activación con la contraseña `$clave` (y las sustituciones `$cambios`). */
    protected function cuerpo(array $f, string $clave, array $cambios = []): array
    {
        return array_merge($f['campos'], array_fill_keys($f['password'], $clave), $cambios);
    }

    /** ¿Responde 200 a una sesión con ese PHPSESSID (acceso real a /cita)? */
    protected function accesoConSesion(string $sid): int
    {
        return (new Navegador(self::$base))->get('/cita', ['Cookie: PHPSESSID=' . $sid, 'Accept: text/html'])['status'];
    }
}
