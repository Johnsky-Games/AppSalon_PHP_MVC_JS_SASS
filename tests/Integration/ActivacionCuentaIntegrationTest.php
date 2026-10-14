<?php

namespace Tests\Integration;

use Classes\Idempotencia;
use Model\ActiveRecord;
use Model\Usuario;
use PHPUnit\Framework\TestCase;
use Repositories\UsuarioRepository;
use Services\AuthService;

/**
 * Activación de cuentas (toma de cuenta por pre-registro, ronda 3):
 *  - quien demuestra control del buzón elige la contraseña DEFINITIVA (la del registro es provisional y se descarta);
 *  - GET/HEAD solo validan el enlace, la activación es POST con CSRF;
 *  - atómica, de un solo uso, idempotente con la clave del formulario.
 * HTTP real (php -S :8110) + SMTP simulado (:2610) + worker real de la cola; concurrencia con procesos PHP reales.
 */
final class ActivacionCuentaIntegrationTest extends TestCase
{
    private const SUF = '@activ.test';
    private const A = 'Atacante-Pass-A1';
    private const B = 'Titular-Pass-B2';
    private const C = 'Definitiva-Pass-C3';
    private const PUERTO_SMTP = 2610;

    private static \mysqli $db;
    private static string $base = '';
    /** @var resource|null */
    private static $servidor = null;
    /** @var resource|null */
    private static $smtp = null;
    private static string $buzon = '';
    private static string $modo = '';
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        self::$db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        self::$db->set_charset('utf8mb4');
        ActiveRecord::setDB(self::$db);
        validar_base_datos_prueba(self::$db);

        self::$buzon = sys_get_temp_dir() . '/activ_buzon_' . self::PUERTO_SMTP . '.jsonl';
        self::$modo = sys_get_temp_dir() . '/activ_modo_' . self::PUERTO_SMTP . '.txt';
        file_put_contents(self::$buzon, '');
        file_put_contents(self::$modo, 'ok');
        self::$smtp = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/cola_smtp.php', (string)self::PUERTO_SMTP, self::$buzon, self::$modo],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1);
        self::esperarPuerto(self::PUERTO_SMTP);

        $puerto = 8110;
        $log = sys_get_temp_dir() . '/activ_web_' . $puerto . '.log';
        $sesiones = sys_get_temp_dir() . '/activ_sess_' . $puerto;
        @mkdir($sesiones, 0777, true);
        self::$servidor = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sesiones, '-S', "127.0.0.1:$puerto", '-t', __DIR__ . '/../../public', __DIR__ . '/../fixtures/router_http.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $p2, __DIR__ . '/../..',
            array_merge(getenv(), self::entornoCorreo(), ['APP_ENV' => 'testing'])
        );
        self::esperarPuerto($puerto);
        self::$base = "http://127.0.0.1:$puerto";
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$servidor, self::$smtp] as $p) {
            if (is_resource($p)) { @proc_terminate($p); @proc_close($p); }
        }
        self::limpiar();
        @unlink(self::$buzon);
        @unlink(self::$modo);
    }

    protected function setUp(): void
    {
        ActiveRecord::setDB(self::$db);
        self::limpiar();
        file_put_contents(self::$buzon, '');
    }

    protected function tearDown(): void
    {
        self::limpiar();
    }

    private static function entornoCorreo(): array
    {
        return ['EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::PUERTO_SMTP, 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1'];
    }

    private static function esperarPuerto(int $puerto): void
    {
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', $puerto, $en, $es, 0.2);
            if ($c) { fclose($c); return; }
            usleep(100000);
        }
        throw new \RuntimeException("No arrancó el servicio auxiliar en el puerto $puerto");
    }

    private static function limpiar(): void
    {
        $s = self::SUF;
        self::$db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%$s'");
        self::$db->query("DELETE FROM colaboradores WHERE email_norm LIKE '%$s'");
        self::$db->query("DELETE FROM usuarios WHERE email LIKE '%$s'");
        self::$db->query("DELETE FROM operaciones_idempotentes WHERE scope IN ('cliente.registro','cliente.activacion')");
        self::$db->query('DELETE FROM intentos_login');
    }

    // ------------------------------------------------------------------ utilidades

    private function correo(string $pref = 'u'): string
    {
        return $pref . '.' . getmypid() . '.' . (++self::$seq) . '.' . bin2hex(random_bytes(2)) . self::SUF;
    }

    /** Cliente sin confirmar con contraseña provisional y token de confirmación vigente. @return array{id:int,email:string,token:string} */
    private function pendiente(string $pass = self::A, string $origen = 'registro_publico'): array
    {
        $email = $this->correo('pend');
        $u = new Usuario(['nombre' => 'Pen', 'apellido' => 'Diente', 'email' => $email, 'password' => password_hash($pass, PASSWORD_BCRYPT), 'telefono' => '0991234567']);
        $repo = new UsuarioRepository(self::$db);
        $id = $repo->createCliente($u, $origen);
        $usuario = $repo->findById($id);
        $token = $repo->issueConfirmationToken($usuario, 24);
        $this->assertNotNull($token);
        return ['id' => $id, 'email' => $email, 'token' => $token];
    }

    private function fila(string $email): array
    {
        $e = self::$db->real_escape_string($email);
        return self::$db->query("SELECT id,password,confirmado,token_hash,token_tipo,token_expira,version_sesion FROM usuarios WHERE email='$e'")->fetch_assoc() ?: [];
    }

    private function http(): ClienteHttpActivacion { return new ClienteHttpActivacion(self::$base); }

    /** Abre el enlace (GET) y devuelve la página, el CSRF, la clave de operación y el cliente. */
    private function abrirEnlace(string $token, ?ClienteHttpActivacion $c = null): array
    {
        $c ??= $this->http();
        $pagina = $c->peticion('GET', '/confirmar-cuenta?token=' . rawurlencode($token));
        return ['c' => $c, 'pagina' => $pagina, 'csrf' => ClienteHttpActivacion::campo($pagina['body'], 'csrf_token'), 'clave' => ClienteHttpActivacion::campo($pagina['body'], 'idem_key')];
    }

    private function activar(string $token, string $pass, ?string $pass2 = null, ?ClienteHttpActivacion $c = null, array $cambios = []): array
    {
        $a = $this->abrirEnlace($token, $c);
        $this->assertSame(200, $a['pagina']['status'], 'el enlace vigente debe mostrar el formulario');
        $post = $a['c']->peticion('POST', '/confirmar-cuenta', $cambios + ['csrf_token' => $a['csrf'], 'idem_key' => $a['clave'], 'token' => $token, 'password' => $pass, 'password2' => $pass2 ?? $pass]);
        return $a + ['post' => $post];
    }

    private function login(string $email, string $pass, string $portal = 'cliente'): array
    {
        self::$db->query('DELETE FROM intentos_login');
        $c = $this->http();
        $ruta = $portal === 'equipo' ? '/equipo/login' : '/cliente/login';
        $pg = $c->peticion('GET', $ruta);
        $r = $c->peticion('POST', $ruta, ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'), 'email' => $email, 'password' => $pass]);
        $r['cliente'] = $c;
        return $r;
    }

    private function correrWorker(): void
    {
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', __DIR__ . '/../../scripts/procesar_correos.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../..',
            array_merge(getenv(), self::entornoCorreo(), ['APP_ENV' => 'testing']));
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        proc_close($p);
    }

    /** @return list<array<string,mixed>> */
    private static function mensajesPara(string $email): array
    {
        $r = [];
        foreach (file(self::$buzon, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $m = json_decode($l, true);
            if (in_array($email, $m['to'], true)) { $r[] = $m; }
        }
        return $r;
    }

    private static function tokenDe(array $mensaje): string
    {
        self::assertSame(1, preg_match('~/confirmar-cuenta\?token=([a-f0-9]{64})~', $mensaje['cuerpo'], $m));
        return $m[1];
    }

    private function registrar(string $email, string $pass): array
    {
        self::$db->query('DELETE FROM intentos_login');
        $c = $this->http();
        $pg = $c->peticion('GET', '/cliente/registro');
        return $c->peticion('POST', '/cliente/registro', ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'), 'idem_key' => ClienteHttpActivacion::campo($pg['body'], 'idem_key'),
            'nombre' => 'Pre', 'apellido' => 'Registro', 'telefono' => '0991112222', 'email' => $email, 'password' => $pass]);
    }

    private function intentoDeAcceso(array $loginResp, string $quien): void
    {
        $this->assertNotSame('/cita', $loginResp['location'], "$quien no debe entrar al portal de clientes");
        $this->assertNotSame(302, $loginResp['status'], "$quien no debe iniciar sesión");
        $cita = $loginResp['cliente']->peticion('GET', '/cita');
        $this->assertNotSame(200, $cita['status'], "$quien no debe tener sesión en /cita");
    }

    // ================================================================== 1. toma de cuenta por pre-registro

    public function test_el_atacante_que_pre_registro_el_correo_no_entra_nunca_aunque_el_titular_active_la_cuenta(): void
    {
        $email = $this->correo('titular');
        // 1) El atacante registra el correo del titular con SU contraseña A.
        $this->assertSame(302, $this->registrar($email, self::A)['status']);
        // 2) El titular intenta registrarse con la suya (B): respuesta uniforme, la provisional A NO se sustituye, se reemite el token.
        $r = $this->registrar($email, self::B);
        $this->assertSame(302, $r['status']);
        $this->assertSame('/mensaje', $r['location']);
        $antes = $this->fila($email);
        $this->assertTrue(password_verify(self::A, $antes['password']), 'una petición pública no sustituye la contraseña provisional');
        $this->assertSame('0', (string)$antes['confirmado']);
        // Cuenta sin confirmar: ni A ni B entran, ni siquiera con sesión.
        $this->intentoDeAcceso($this->login($email, self::A), 'A antes de activar');
        $this->intentoDeAcceso($this->login($email, self::B), 'B antes de activar');
        // 3) El titular recibe el correo (SMTP simulado + worker real) con el enlace /confirmar-cuenta?token=… (ruta sin cambios).
        $this->correrWorker();
        $mensajes = self::mensajesPara($email);
        $this->assertNotEmpty($mensajes, 'el worker debió entregar la confirmación');
        $token = self::tokenDe(end($mensajes));
        $this->assertStringContainsString('contraseña definitiva', end($mensajes)['cuerpo']);
        // 4) Abrir el enlace NO activa nada.
        $a = $this->abrirEnlace($token);
        $this->assertSame(200, $a['pagina']['status']);
        $this->assertSame('0', (string)$this->fila($email)['confirmado'], 'GET no activa');
        // 5) El titular activa eligiendo C.
        $post = $a['c']->peticion('POST', '/confirmar-cuenta', ['csrf_token' => $a['csrf'], 'idem_key' => $a['clave'], 'token' => $token, 'password' => self::C, 'password2' => self::C]);
        $this->assertSame(200, $post['status']);
        $this->assertStringContainsString('Tu cuenta quedó activada', $post['body']);
        $despues = $this->fila($email);
        $this->assertSame('1', (string)$despues['confirmado']);
        $this->assertNull($despues['token_hash']);
        // 6) La contraseña provisional del atacante JAMÁS da acceso (login HTTP real a ambos portales); tampoco B; sí C.
        $this->intentoDeAcceso($this->login($email, self::A), 'A (atacante) tras activar');
        $this->assertNotSame(302, $this->login($email, self::A, 'equipo')['status']);
        $this->intentoDeAcceso($this->login($email, self::B), 'B (no elegida al activar)');
        $ok = $this->login($email, self::C);
        $this->assertSame(302, $ok['status']);
        $this->assertSame('/cita', $ok['location']);
        $this->assertSame(200, $ok['cliente']->peticion('GET', '/cita')['status']);
    }

    public function test_el_formulario_del_registro_y_el_correo_explican_que_la_contrasena_es_provisional(): void
    {
        $c = $this->http();
        $this->assertStringContainsString('provisional', $c->peticion('GET', '/cliente/registro')['body']);
        $email = $this->correo('texto');
        $this->registrar($email, self::A);
        $this->correrWorker();
        $m = self::mensajesPara($email);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('provisional', $m[0]['cuerpo']);
        $a = $this->abrirEnlace(self::tokenDe($m[0]));
        $this->assertStringContainsString('definitiva', $a['pagina']['body']);
        $this->assertStringContainsString('name="password2"', $a['pagina']['body']);
    }

    public function test_una_cuenta_sin_confirmar_no_puede_tener_sesion_ni_con_la_contrasena_correcta_ni_con_una_sesion_forjada(): void
    {
        $p = $this->pendiente(self::A);
        $this->intentoDeAcceso($this->login($p['email'], self::A), 'contraseña provisional correcta, cuenta sin confirmar');
        // Sesión forjada en el almacén del servidor: el servidor revalida confirmado en cada petición.
        $dir = sys_get_temp_dir() . '/activ_sess_8110';
        $sid = bin2hex(random_bytes(16));
        file_put_contents("$dir/sess_$sid", 'id|' . serialize($p['id']) . 'login|' . serialize(true) . 'portal|' . serialize('cliente') . 'rol|' . serialize('cliente'));
        $ch = curl_init(self::$base . '/cita');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Cookie: PHPSESSID=$sid"], CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $this->assertSame(302, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'la sesión forjada de una cuenta sin confirmar no da acceso');
        curl_close($ch);
    }

    // ================================================================== 2. GET/HEAD solo validan

    public function test_un_escaner_de_enlaces_no_activa_ni_consume_el_token(): void
    {
        $p = $this->pendiente();
        $antes = $this->fila($p['email']);
        $c = $this->http();
        for ($i = 0; $i < 20; $i++) {
            $r = $c->peticion('GET', '/confirmar-cuenta?token=' . $p['token']);
            $this->assertSame(200, $r['status']);
        }
        for ($i = 0; $i < 5; $i++) {
            $r = $c->peticion('HEAD', '/confirmar-cuenta?token=' . $p['token']);
            $this->assertSame(200, $r['status']);
            $this->assertSame('', $r['body']);
        }
        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'Slackbot-LinkExpanding 1.0', 'Mozilla/5.0 (compatible; Microsoft Outlook SafeLinks)'] as $ua) {
            $this->assertSame(200, $this->http()->peticion('GET', '/confirmar-cuenta?token=' . $p['token'], null, ['User-Agent: ' . $ua, 'Purpose: prefetch', 'Sec-Purpose: prefetch'])['status']);
        }
        $this->assertSame($antes, $this->fila($p['email']), 'ninguna petición GET/HEAD cambió la cuenta ni consumió el token');
        $this->assertSame('0', (string)$antes['confirmado']);
        // Y el enlace sigue sirviendo para activar.
        $this->assertSame(200, $this->activar($p['token'], self::C)['post']['status']);
    }

    public function test_el_formulario_de_activacion_lleva_las_cabeceras_de_privacidad_y_no_refleja_el_token_sin_escapar(): void
    {
        $p = $this->pendiente();
        $a = $this->abrirEnlace($p['token']);
        $h = $a['pagina']['headers'];
        $this->assertSame('no-store', $h['cache-control'][0] ?? null);
        $this->assertSame('no-referrer', $h['referrer-policy'][0] ?? null);
        $this->assertNotNull($a['csrf']);
        $this->assertNotNull($a['clave']);
        $this->assertSame($p['token'], ClienteHttpActivacion::campo($a['pagina']['body'], 'token'));
        $this->assertStringContainsString('action="/confirmar-cuenta"', $a['pagina']['body'], 'el token no viaja en la query del POST');
        // Un valor hostil en la query no se refleja.
        $hostil = '"><script>alert(1)</script>';
        $r = $this->http()->peticion('GET', '/confirmar-cuenta?token=' . rawurlencode($hostil));
        $this->assertSame(410, $r['status']);
        $this->assertStringNotContainsString('<script>alert', $r['body']);
        $this->assertStringNotContainsString($hostil, $r['body']);
        $this->assertSame('no-store', $r['headers']['cache-control'][0] ?? null);
        $this->assertSame('no-referrer', $r['headers']['referrer-policy'][0] ?? null);
    }

    public function test_token_vencido_en_get_muestra_vencido_y_en_post_se_rechaza_sin_cambios(): void
    {
        $p = $this->pendiente();
        $a = $this->abrirEnlace($p['token']);   // formulario abierto mientras el enlace aún valía
        self::$db->query("UPDATE usuarios SET token_expira=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id={$p['id']}");
        $antes = $this->fila($p['email']);
        $get = $this->http()->peticion('GET', '/confirmar-cuenta?token=' . $p['token']);
        $this->assertSame(410, $get['status']);
        $this->assertStringContainsString('venció', $get['body']);
        $this->assertSame(410, $this->http()->peticion('HEAD', '/confirmar-cuenta?token=' . $p['token'])['status']);
        $post = $a['c']->peticion('POST', '/confirmar-cuenta', ['csrf_token' => $a['csrf'], 'idem_key' => $a['clave'], 'token' => $p['token'], 'password' => self::C, 'password2' => self::C]);
        $this->assertSame(410, $post['status']);
        $this->assertStringContainsString('venció', $post['body']);
        $this->assertSame($antes, $this->fila($p['email']), 'un token vencido no cambia contraseña ni estado');
    }

    public function test_los_enlaces_vencido_usado_inexistente_y_de_cuenta_ya_confirmada_dan_la_misma_respuesta(): void
    {
        $firma = static fn(array $r): array => ['status' => $r['status'], 'body' => preg_replace('/[a-f0-9]{32,64}/', 'H', $r['body'])];
        $vencido = $this->pendiente(); self::$db->query("UPDATE usuarios SET token_expira=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id={$vencido['id']}");
        $usado = $this->pendiente(); $this->activar($usado['token'], self::C);
        $residual = $this->pendiente(); self::$db->query("UPDATE usuarios SET confirmado='1' WHERE id={$residual['id']}");
        $inexistente = bin2hex(random_bytes(32));
        $respuestas = [];
        foreach (['vencido' => $vencido['token'], 'usado' => $usado['token'], 'residual' => $residual['token'], 'inexistente' => $inexistente] as $k => $tok) {
            $respuestas["GET $k"] = $firma($this->http()->peticion('GET', '/confirmar-cuenta?token=' . $tok));
            self::$db->query('DELETE FROM intentos_login');
            $c = $this->http();
            $pg = $c->peticion('GET', '/cliente/registro');   // sesión con CSRF y clave válidos
            $respuestas["POST $k"] = $firma($c->peticion('POST', '/confirmar-cuenta', ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'),
                'idem_key' => ClienteHttpActivacion::campo($pg['body'], 'idem_key'), 'token' => $tok, 'password' => self::C, 'password2' => self::C]));
        }
        $this->assertSame(410, $respuestas['GET vencido']['status']);
        foreach (['GET', 'POST'] as $m) {
            foreach (['usado', 'residual', 'inexistente'] as $k) {
                $this->assertSame($respuestas["$m vencido"], $respuestas["$m $k"], "$m $k distinguible de vencido");
            }
        }
        $this->assertSame(410, $respuestas['POST vencido']['status']);
    }

    // ================================================================== 3. POST protegido

    public function test_el_post_sin_csrf_con_csrf_de_otra_sesion_o_con_metodo_incorrecto_se_rechaza_sin_efectos(): void
    {
        $p = $this->pendiente();
        $antes = $this->fila($p['email']);
        $a = $this->abrirEnlace($p['token']);
        $cuerpo = ['idem_key' => $a['clave'], 'token' => $p['token'], 'password' => self::C, 'password2' => self::C];
        // sin CSRF
        $this->assertSame(403, $a['c']->peticion('POST', '/confirmar-cuenta', $cuerpo)['status']);
        // CSRF de otra sesión
        $otro = $this->abrirEnlace($p['token']);
        $this->assertSame(403, $a['c']->peticion('POST', '/confirmar-cuenta', $cuerpo + ['csrf_token' => $otro['csrf']])['status']);
        // CSRF vacío / en forma de arreglo
        $this->assertSame(403, $a['c']->peticion('POST', '/confirmar-cuenta', $cuerpo + ['csrf_token' => ''])['status']);
        $this->assertSame(403, $a['c']->peticion('POST', '/confirmar-cuenta', $cuerpo + ['csrf_token' => [$a['csrf']]])['status']);
        // Métodos incorrectos
        foreach (['PUT', 'DELETE', 'PATCH'] as $m) {
            $r = $a['c']->peticion($m, '/confirmar-cuenta', $cuerpo + ['csrf_token' => $a['csrf']]);
            $this->assertContains($r['status'], [404, 405], $m);
        }
        // Token solo en la query (el POST lo exige en el cuerpo) y parámetros de activación en un GET
        $r = $a['c']->peticion('POST', '/confirmar-cuenta?token=' . $p['token'], ['csrf_token' => $a['csrf'], 'idem_key' => $a['clave'], 'password' => self::C, 'password2' => self::C]);
        $this->assertNotSame(200, $r['status']);
        $r = $this->http()->peticion('GET', '/confirmar-cuenta?token=' . $p['token'] . '&password=' . self::C . '&password2=' . self::C . '&csrf_token=x');
        $this->assertSame(200, $r['status']);
        $this->assertSame($antes, $this->fila($p['email']), 'ninguno de esos intentos cambió la cuenta');
    }

    public function test_contrasenas_invalidas_no_consumen_el_token_y_vuelven_a_mostrar_el_formulario(): void
    {
        $p = $this->pendiente();
        $antes = $this->fila($p['email']);
        foreach ([['corta', 'corta', 'al menos 8'], [self::C, self::C . 'x', 'no coinciden'], [str_repeat('x', 73), str_repeat('x', 73), '72']] as [$a1, $a2, $texto]) {
            $r = $this->activar($p['token'], $a1, $a2);
            $this->assertSame(422, $r['post']['status'], $texto);
            $this->assertStringContainsString($texto, $r['post']['body']);
            $this->assertStringContainsString('name="password2"', $r['post']['body'], 'el formulario vuelve a mostrarse');
        }
        // Sin clave de operación del formulario no se admite la activación.
        $a = $this->abrirEnlace($p['token']);
        $r = $a['c']->peticion('POST', '/confirmar-cuenta', ['csrf_token' => $a['csrf'], 'token' => $p['token'], 'password' => self::C, 'password2' => self::C]);
        $this->assertSame(422, $r['status']);
        $this->assertSame($antes, $this->fila($p['email']));
        // Con datos correctos, el mismo enlace activa.
        $this->assertSame(200, $this->activar($p['token'], self::C)['post']['status']);
    }

    public function test_el_limite_de_intentos_por_token_y_por_ip_responde_429(): void
    {
        // Por huella del token: 5 intentos admitidos y el 6.º bloqueado (token inexistente).
        $falso = bin2hex(random_bytes(32));
        $estados = [];
        for ($i = 0; $i < 6; $i++) {
            $c = $this->http();
            $pg = $c->peticion('GET', '/cliente/registro');
            $r = $c->peticion('POST', '/confirmar-cuenta', ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'), 'idem_key' => Idempotencia::nuevaClave(), 'token' => $falso, 'password' => self::C, 'password2' => self::C]);
            $estados[] = $r['status'];
        }
        $this->assertSame([410, 410, 410, 410, 410, 429], $estados);
        // Por IP: tokens distintos agotan el presupuesto de la IP (15).
        self::$db->query('DELETE FROM intentos_login');
        $estados = [];
        for ($i = 0; $i < 16; $i++) {
            $c = $this->http();
            $pg = $c->peticion('GET', '/cliente/registro');
            $estados[] = $c->peticion('POST', '/confirmar-cuenta', ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'), 'idem_key' => Idempotencia::nuevaClave(), 'token' => bin2hex(random_bytes(32)), 'password' => self::C, 'password2' => self::C])['status'];
        }
        $this->assertSame(429, array_pop($estados));
        $this->assertSame(array_fill(0, 15, 410), $estados);
    }

    // ================================================================== 4. efectos de la activación

    public function test_la_activacion_reemplaza_la_contrasena_invalida_sesiones_reinicia_contadores_y_regenera_la_sesion_del_navegador(): void
    {
        $p = $this->pendiente(self::A);
        $antes = $this->fila($p['email']);
        self::$db->query("INSERT INTO intentos_login(identificador,tipo,intentos,primera_peticion,ultimo_intento) VALUES ('{$p['email']}','email_login',4,NOW(),NOW()),('{$p['email']}','email_recovery',2,NOW(),NOW()),('{$p['email']}','email_reconfirm',2,NOW(),NOW())");
        // Evento de confirmación aún en cola (enlace que dejará de existir)
        $svc = new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::$db));
        $evento = $svc->encolarConfirmacionCuenta($p['id'], $p['email'], 'Pen', $p['token']);
        $a = $this->abrirEnlace($p['token']);
        $sidAntes = $a['c']->cookie('PHPSESSID');
        $post = $a['c']->peticion('POST', '/confirmar-cuenta', ['csrf_token' => $a['csrf'], 'idem_key' => $a['clave'], 'token' => $p['token'], 'password' => self::C, 'password2' => self::C]);
        $this->assertSame(200, $post['status']);
        $despues = $this->fila($p['email']);
        $this->assertTrue(password_verify(self::C, $despues['password']));
        $this->assertFalse(password_verify(self::A, $despues['password']), 'la provisional se descarta');
        $this->assertSame((int)$antes['version_sesion'] + 1, (int)$despues['version_sesion'], 'se invalidan las sesiones previas');
        $this->assertNull($despues['token_hash']);
        $this->assertNull($despues['token_tipo']);
        $this->assertSame(0, (int)self::$db->query("SELECT COUNT(*) n FROM intentos_login WHERE identificador='{$p['email']}'")->fetch_assoc()['n'], 'contadores de la cuenta reiniciados');
        $this->assertSame('cancelado', self::$db->query("SELECT estado FROM correos_salida WHERE id=$evento")->fetch_assoc()['estado'], 'el correo de confirmación pendiente se cancela');
        $this->assertNotSame($sidAntes, $a['c']->cookie('PHPSESSID'), 'session_regenerate_id en el navegador que activa');
        $this->assertNull($a['c']->cookie('login_activo'));
        // Sin auto-login: la persona no queda con sesión de cliente.
        $this->assertNotSame(200, $a['c']->peticion('GET', '/cita')['status']);
        // Un token de recuperación posterior sigue siendo independiente: la cuenta confirmada puede pedir uno nuevo.
        $this->assertSame(302, $this->login($p['email'], self::C)['status']);
    }

    public function test_una_sesion_anterior_de_la_cuenta_queda_invalidada_al_activar(): void
    {
        $p = $this->pendiente(self::A);
        // Sesión forjada previa con la versión actual (la cuenta no puede tener sesión legítima sin confirmar).
        $dir = sys_get_temp_dir() . '/activ_sess_8110';
        $sid = bin2hex(random_bytes(16));
        $v = (int)$this->fila($p['email'])['version_sesion'];
        file_put_contents("$dir/sess_$sid", 'id|' . serialize($p['id']) . 'login|' . serialize(true) . 'portal|' . serialize('cliente') . 'rol|' . serialize('cliente') . 'version_sesion|' . serialize($v));
        $this->activar($p['token'], self::C);
        $ch = curl_init(self::$base . '/cita');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Cookie: PHPSESSID=$sid"], CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $this->assertSame(302, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'la sesión anterior (versión vieja) ya no vale');
        curl_close($ch);
    }

    public function test_una_sesion_guardada_sin_version_no_adopta_la_cuenta_cuando_pasa_de_sin_confirmar_a_activada(): void
    {
        // Hallazgo R3_05: isAuth() «adoptaba» una sesión sin marca de versión en cuanto la cuenta pasaba a confirmada.
        $p = $this->pendiente(self::A);
        $dir = sys_get_temp_dir() . '/activ_sess_8110';
        $sid = bin2hex(random_bytes(16));
        file_put_contents("$dir/sess_$sid", 'id|' . serialize($p['id']) . 'login|' . serialize(true) . 'portal|' . serialize('cliente') . 'rol|' . serialize('cliente'));   // sin version_sesion
        $this->assertSame(200, $this->activar($p['token'], self::C)['post']['status']);
        $this->assertGreaterThanOrEqual(1, (int)$this->fila($p['email'])['version_sesion']);
        $ch = curl_init(self::$base . '/cita');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Cookie: PHPSESSID=$sid"], CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $this->assertSame(302, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'una sesión sin versión ya no se adopta para una cuenta recién activada');
        curl_close($ch);
        // Control: una cuenta heredada que nunca invalidó sesiones (versión inicial 1) conserva su sesión sin marca.
        $e = $this->correo('heredada');
        $hash = password_hash(self::C, PASSWORD_BCRYPT);
        self::$db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('He','Redada','$e','$hash','0991234567',0,1)");
        $id = (int)self::$db->insert_id;
        self::$db->query("INSERT INTO clientes(usuario_id) VALUES ($id)");
        $sid2 = bin2hex(random_bytes(16));
        file_put_contents("$dir/sess_$sid2", 'id|' . serialize($id) . 'login|' . serialize(true) . 'portal|' . serialize('cliente') . 'rol|' . serialize('cliente'));
        $ch = curl_init(self::$base . '/cita');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Cookie: PHPSESSID=$sid2"], CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $this->assertSame(200, curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
        curl_close($ch);
    }

    public function test_el_restablecimiento_de_contrasena_exige_cuenta_confirmada(): void
    {
        $p = $this->pendiente(self::A);
        self::$db->query("UPDATE usuarios SET token_tipo='recuperacion' WHERE id={$p['id']}");   // token vigente con propósito de recuperación en una cuenta sin confirmar
        $antes = $this->fila($p['email']);
        $this->assertFalse((new UsuarioRepository(self::$db))->resetPasswordByToken($p['token'], password_hash('Intruso-Pass-1', PASSWORD_BCRYPT)));
        $this->assertSame($antes, $this->fila($p['email']));
    }

    public function test_cuarenta_posts_seguidos_con_tokens_inventados_desde_la_misma_ip_topan_con_429_pero_los_errores_de_contrasena_no_cuentan(): void
    {
        // Equivocarse varias veces de contraseña no gasta el presupuesto (se valida antes de admitir el intento).
        $p = $this->pendiente();
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(422, $this->activar($p['token'], self::C, self::C . 'x')['post']['status']);
        }
        $this->assertSame(200, $this->activar($p['token'], self::C)['post']['status']);
        // Con tokens inventados: 429 antes del intento 40.
        self::$db->query('DELETE FROM intentos_login');
        $estados = [];
        for ($i = 0; $i < 40; $i++) {
            $c = $this->http();
            $pg = $c->peticion('GET', '/cliente/registro');
            $estados[] = $c->peticion('POST', '/confirmar-cuenta', ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'), 'idem_key' => Idempotencia::nuevaClave(), 'token' => bin2hex(random_bytes(32)), 'password' => self::C, 'password2' => self::C])['status'];
        }
        $this->assertContains(429, $estados);
        $this->assertSame(0, array_search(429, $estados) - 15, 'el bloqueo llega con el intento 16 (15 admitidos por IP)');
    }

    public function test_un_token_de_confirmacion_residual_no_activa_ni_cambia_la_contrasena_de_una_cuenta_confirmada(): void
    {
        $p = $this->pendiente(self::A);
        $hash = password_hash('Original-Pass-77', PASSWORD_BCRYPT);
        self::$db->query("UPDATE usuarios SET confirmado='1', password='$hash' WHERE id={$p['id']}");   // token_hash residual intacto
        $this->assertNotNull($this->fila($p['email'])['token_hash']);
        $antes = $this->fila($p['email']);
        $this->assertSame(410, $this->http()->peticion('GET', '/confirmar-cuenta?token=' . $p['token'])['status']);
        $this->assertSame(410, $this->http()->peticion('HEAD', '/confirmar-cuenta?token=' . $p['token'])['status']);
        $c = $this->http();
        $pg = $c->peticion('GET', '/cliente/registro');
        $r = $c->peticion('POST', '/confirmar-cuenta', ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'), 'idem_key' => ClienteHttpActivacion::campo($pg['body'], 'idem_key'), 'token' => $p['token'], 'password' => self::C, 'password2' => self::C]);
        $this->assertSame(410, $r['status']);
        $this->assertSame($antes, $this->fila($p['email']));
        $this->assertSame(302, $this->login($p['email'], 'Original-Pass-77')['status'], 'la contraseña original sigue valiendo');
    }

    public function test_otros_tokens_no_sirven_como_enlace_de_activacion_y_el_reenvio_invalida_el_enlace_anterior(): void
    {
        $p = $this->pendiente();
        // Token de recuperación (otro propósito) sobre una cuenta sin confirmar.
        $repo = new UsuarioRepository(self::$db);
        self::$db->query("UPDATE usuarios SET token_tipo='recuperacion' WHERE id={$p['id']}");
        $this->assertSame(410, $this->http()->peticion('GET', '/confirmar-cuenta?token=' . $p['token'])['status'], 'un token de recuperación no abre la activación');
        self::$db->query("UPDATE usuarios SET token_tipo='confirmacion' WHERE id={$p['id']}");
        // Token de invitación al equipo (otra tabla): no existe como token de cuenta.
        $this->assertSame(410, $this->http()->peticion('GET', '/confirmar-cuenta?token=' . bin2hex(random_bytes(32)))['status']);
        // Reenvío: el enlace viejo deja de valer.
        $viejo = $p['token'];
        self::$db->query('DELETE FROM intentos_login');
        $c = $this->http();
        $pg = $c->peticion('GET', '/reenviar-confirmacion');
        $this->assertSame(200, $c->peticion('POST', '/reenviar-confirmacion', ['csrf_token' => ClienteHttpActivacion::campo($pg['body'], 'csrf_token'), 'email' => $p['email']])['status']);
        $this->correrWorker();
        $m = self::mensajesPara($p['email']);
        $this->assertNotEmpty($m);
        $nuevo = self::tokenDe(end($m));
        $this->assertNotSame($viejo, $nuevo);
        $this->assertSame(410, $this->http()->peticion('GET', '/confirmar-cuenta?token=' . $viejo)['status'], 'el enlace anterior ya no es válido');
        $this->assertSame(200, $this->activar($nuevo, self::C)['post']['status']);
    }

    public function test_una_cuenta_creada_por_recepcion_activa_con_la_contrasena_elegida_por_el_titular(): void
    {
        $p = $this->pendiente(bin2hex(random_bytes(12)), 'recepcion');   // contraseña aleatoria que nadie conoce
        $this->assertSame('recepcion', self::$db->query("SELECT origen FROM clientes WHERE usuario_id={$p['id']}")->fetch_assoc()['origen']);
        $this->intentoDeAcceso($this->login($p['email'], 'cualquiera-123'), 'cuenta de recepción sin activar');
        $this->assertSame(200, $this->activar($p['token'], self::C)['post']['status']);
        $this->assertSame(302, $this->login($p['email'], self::C)['status']);
    }

    // ================================================================== 5. idempotencia y concurrencia

    public function test_doble_clic_con_la_misma_clave_devuelve_el_mismo_resultado_sin_repetir_efectos(): void
    {
        $p = $this->pendiente();
        $a = $this->abrirEnlace($p['token']);
        $cuerpo = ['csrf_token' => $a['csrf'], 'idem_key' => $a['clave'], 'token' => $p['token'], 'password' => self::C, 'password2' => self::C];
        $r1 = $a['c']->peticion('POST', '/confirmar-cuenta', $cuerpo);
        $hash1 = $this->fila($p['email'])['password'];
        $v1 = $this->fila($p['email'])['version_sesion'];
        $r2 = $a['c']->peticion('POST', '/confirmar-cuenta', $cuerpo);
        $r3 = $a['c']->peticion('POST', '/confirmar-cuenta', $cuerpo);
        $norm = static fn(array $r): array => ['s' => $r['status'], 'b' => preg_replace('/[a-f0-9]{64}/', 'H', $r['body'])];
        $this->assertSame(200, $r1['status']);
        $this->assertSame($norm($r1), $norm($r2));
        $this->assertSame($norm($r1), $norm($r3));
        $this->assertSame($hash1, $this->fila($p['email'])['password'], 'el doble clic no reescribe la contraseña');
        $this->assertSame($v1, $this->fila($p['email'])['version_sesion']);
        // Misma clave con otra contraseña: conflicto, sin efectos.
        $r4 = $a['c']->peticion('POST', '/confirmar-cuenta', ['password' => self::A . 'x', 'password2' => self::A . 'x'] + $cuerpo);
        $this->assertSame(409, $r4['status']);
        $this->assertSame($hash1, $this->fila($p['email'])['password']);
    }

    private function lanzar(string $token, string $pass, string $clave, string $actor, bool $conIp = false): array
    {
        $proceso = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', __DIR__ . '/../fixtures/activacion_worker.php', $token, $pass, $clave, $actor, $conIp ? '1' : '0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../..', array_merge(getenv(), ['APP_ENV' => 'testing']));
        $this->assertTrue(is_resource($proceso));
        $this->assertSame('READY', trim((string)fgets($pipes[1])));
        return [$proceso, $pipes];
    }

    private function liberar(array $ws): array
    {
        foreach ($ws as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        $res = [];
        foreach ($ws as [$proceso, $pipes]) {
            $linea = fgets($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($proceso), 'Worker con error: ' . $err);
            $json = json_decode((string)$linea, true);
            $this->assertIsArray($json, $linea . $err);
            $res[] = $json;
        }
        return $res;
    }

    public function test_seis_activaciones_simultaneas_con_distintas_claves_y_contrasenas_dejan_una_sola_ganadora(): void
    {
        $p = $this->pendiente(self::A);
        $passes = array_map(fn($i) => "Ganadora-Pass-$i-x", range(0, 5));
        $ws = [];
        foreach ($passes as $i => $pw) { $ws[] = $this->lanzar($p['token'], $pw, Idempotencia::nuevaClave(), 'anonimo:activ-d' . $i); }
        $res = $this->liberar($ws);
        $ok = array_keys(array_filter($res, fn($r) => $r['status'] === AuthService::STATUS_OK));
        $this->assertCount(1, $ok, 'exactamente una activación: ' . json_encode($res));
        foreach ($res as $i => $r) {
            if ($i !== $ok[0]) { $this->assertSame(AuthService::STATUS_INVALID, $r['status'], 'la perdedora recibe «enlace no válido o ya utilizado»: ' . json_encode($r)); }
        }
        $fila = $this->fila($p['email']);
        $this->assertSame('1', (string)$fila['confirmado']);
        $this->assertTrue(password_verify($passes[$ok[0]], $fila['password']), 'la contraseña final es la de la ganadora');
        foreach ($passes as $i => $pw) { if ($i !== $ok[0]) { $this->assertFalse(password_verify($pw, $fila['password'])); } }
        $this->assertSame(1, (int)self::$db->query("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE scope='cliente.activacion'")->fetch_assoc()['n'], 'las perdedoras no dejan reserva');
    }

    public function test_seis_activaciones_con_la_misma_clave_y_la_misma_contrasena_son_una_sola(): void
    {
        $p = $this->pendiente(self::A);
        $clave = Idempotencia::nuevaClave();
        $res = $this->liberar(array_map(fn() => $this->lanzar($p['token'], self::C, $clave, 'anonimo:activ-misma'), range(0, 5)));
        $this->assertSame(array_fill(0, 6, AuthService::STATUS_OK), array_column($res, 'status'), json_encode($res));
        $this->assertCount(1, array_filter(array_column($res, 'repetida'), fn($r) => $r === false), 'exactamente una ejecutó la activación');
        $this->assertTrue(password_verify(self::C, $this->fila($p['email'])['password']));
    }

    public function test_seis_activaciones_con_la_misma_clave_y_distintas_contrasenas_dejan_la_de_la_ganadora(): void
    {
        $p = $this->pendiente(self::A);
        $clave = Idempotencia::nuevaClave();
        $passes = array_map(fn($i) => "Misma-Clave-Pass-$i-x", range(0, 5));
        $res = $this->liberar(array_map(fn($i) => $this->lanzar($p['token'], $passes[$i], $clave, 'anonimo:activ-mc'), range(0, 5)));
        $ok = array_keys(array_filter($res, fn($r) => $r['status'] === AuthService::STATUS_OK));
        $this->assertCount(1, $ok, json_encode($res));
        foreach ($res as $i => $r) {
            if ($i !== $ok[0]) { $this->assertContains($r['status'], [AuthService::STATUS_CONFLICT_CLAVE, AuthService::STATUS_EN_CURSO], json_encode($r)); }
        }
        $this->assertTrue(password_verify($passes[$ok[0]], $this->fila($p['email'])['password']));
        $this->assertSame('1', (string)$this->fila($p['email'])['confirmado']);
    }

    public function test_registro_y_activacion_siguen_siendo_uniformes_con_dos_pre_registros_concurrentes(): void
    {
        // Pre-registro del atacante y del titular a la vez: una sola cuenta; la contraseña la fija quien activa.
        $email = $this->correo('carrera');
        $this->registrar($email, self::A);
        $this->registrar($email, self::B);
        $this->assertSame(1, (int)self::$db->query("SELECT COUNT(*) n FROM usuarios WHERE email='$email'")->fetch_assoc()['n']);
        $this->correrWorker();
        $m = self::mensajesPara($email);
        $this->assertNotEmpty($m);
        $this->assertSame(200, $this->activar(self::tokenDe(end($m)), self::C)['post']['status']);
        $this->intentoDeAcceso($this->login($email, self::A), 'A');
        $this->intentoDeAcceso($this->login($email, self::B), 'B');
        $this->assertSame(302, $this->login($email, self::C)['status']);
    }
}

/** Cliente HTTP mínimo con su propio jar de cookies. */
final class ClienteHttpActivacion
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'activ_jar_');
    }

    public function __destruct() { @unlink($this->jar); }

    /** @return array{status:int,headers:array<string,array<int,string>>,body:string,location:?string} */
    public function peticion(string $metodo, string $ruta, ?array $campos = null, array $cabeceras = []): array
    {
        $ch = curl_init($this->base . $ruta);
        $h = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_HEADERFUNCTION => static function ($c, $linea) use (&$h) {
                if (str_contains($linea, ':')) { [$k, $v] = explode(':', $linea, 2); $h[strtolower(trim($k))][] = trim($v); }
                return strlen($linea);
            },
        ]);
        if ($metodo === 'HEAD') { curl_setopt($ch, CURLOPT_NOBODY, true); }
        if ($campos !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos)); }
        $cuerpo = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $h, 'body' => (string)$cuerpo, 'location' => $h['location'][0] ?? null];
    }

    public function cookie(string $nombre): ?string
    {
        foreach (file($this->jar) ?: [] as $l) {
            $p = explode("\t", trim($l));
            if (count($p) >= 7 && $p[5] === $nombre) { return $p[6]; }
        }
        return null;
    }

    public static function campo(string $html, string $nombre): ?string
    {
        return preg_match('/name="' . preg_quote($nombre, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : null;
    }
}
