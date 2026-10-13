<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * Ronda 2B: reverificación de DEF-R2-02 (destino de la redirección) y DEF-R2-03 (Referer externo / open redirect, CWE-601) con
 * variantes adicionales: userinfo, esquemas peligrosos, `//`, barra invertida, CRLF codificado, puerto distinto, mayúsculas, Host
 * manipulado y X-Forwarded-Host. Invariante: `Location` SIEMPRE es una ruta local (empieza por una sola `/`), sin esquema, sin `\`
 * y sin saltos de línea; nunca depende de una cabecera del cliente más allá de la ruta del MISMO origen.
 */
final class R2bRedireccionTest extends R2bCase
{
    private static string $base;
    private static int $puerto;
    private static array $p = [];
    /** @var array<string,Navegador> */
    private static array $nav = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor([], 'r2b_redir');
        self::$puerto = (int)parse_url(self::$base, PHP_URL_PORT);
        $d = self::propietario(self::correo('dueno'));
        self::$p['dueno'] = $d + ['portal' => 'equipo', 'inicio' => '/admin'];
        self::$p['profesional'] = self::miembro($d['id'], 'profesional') + ['portal' => 'equipo', 'inicio' => '/agenda'];
        self::$p['finanzas'] = self::miembro($d['id'], 'finanzas') + ['portal' => 'equipo', 'inicio' => '/admin/finanzas'];
        self::$p['cliente'] = self::cliente(['email' => self::correo('cli')]) + ['portal' => 'cliente', 'inicio' => '/cita'];
    }

    public static function tearDownAfterClass(): void { self::pararServidor('r2b_redir'); }

    private function sesion(string $quien): Navegador
    {
        if (!isset(self::$nav[$quien])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $this->assertSame(302, $n->login(self::$p[$quien]['portal'], self::$p[$quien]['email'], self::$p[$quien]['password'])['status'], $quien);
            self::$nav[$quien] = $n;
        }
        return self::$nav[$quien];
    }

    /** POST /api/eliminar sobre una cita inexistente (no modifica nada) con la cabecera Referer dada. */
    private function cancelar(string $quien, ?string $referer, array $cabeceras = [], string $id = '99999999', string $accept = 'text/html'): array
    {
        $n = $this->sesion($quien);
        $csrf = $n->csrf('/perfil');
        $h = array_merge(['Accept: ' . $accept], $referer === null ? [] : ["Referer: $referer"], $cabeceras);
        return $n->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $id], $h);
    }

    private function localSeguro(?string $location): bool
    {
        return is_string($location) && $location !== '' && $location[0] === '/' && !str_starts_with($location, '//') && !str_contains($location, '\\') && !preg_match('/[\x00-\x1f\x7f]/', $location) && !preg_match('~^/[a-z][a-z0-9+.\-]*:~i', $location);
    }

    public static function referers(): array
    {
        $p = '{P}';
        return [
            'externo' => ['https://evil.example/phishing', 'inicio'],
            'externo con puerto' => ['http://evil.example:8080/x', 'inicio'],
            'userinfo = host propio, host real externo' => ["http://127.0.0.1:$p@evil.example/", 'inicio'],
            'userinfo localhost:8000@evil.example (variante del informe)' => ['http://localhost:8000@evil.example', 'inicio'],
            'userinfo externo, host propio real' => ["http://evil.example@127.0.0.1:$p/mis-citas", '/mis-citas'],
            'javascript:' => ['javascript:alert(document.cookie)', 'inicio'],
            'data:' => ['data:text/html,<script>alert(1)</script>', 'inicio'],
            'doble barra sin esquema' => ['//evil.example/x', 'inicio'],
            'barra invertida' => ['\\\\evil.example\\x', 'inicio'],
            'esquema con barra invertida' => ["http:\\\\127.0.0.1:$p\\\\x", 'inicio'],
            'barra y barra invertida' => ['/\\evil.example', 'inicio'],
            'mismo host con // en la ruta' => ["http://127.0.0.1:$p//evil.example/x", 'inicio'],
            'puerto distinto' => ['http://127.0.0.1:1/mis-citas', 'inicio'],
            'sin puerto' => ['http://127.0.0.1/mis-citas', 'inicio'],
            'otro host local' => ["http://localhost:$p/mis-citas", 'inicio'],
            'CRLF codificado' => ["http://127.0.0.1:$p/a%0d%0aSet-Cookie:%20x=1", 'cualquiera-seguro'],
            'ruta con arroba' => ["http://127.0.0.1:$p/@evil.example", '/@evil.example'],
            'ruta con barras codificadas' => ["http://127.0.0.1:$p/%2f%2fevil.example", '/%2f%2fevil.example'],
            'vacío' => ['', 'inicio'],
            'espacios' => ['   ', 'inicio'],
            'larguísimo' => ["http://127.0.0.1:$p/" . str_repeat('a', 3000), 'inicio'],
            'mismo origen válido' => ["http://127.0.0.1:$p/mis-citas?x=1", '/mis-citas?x=1'],
            'mismo origen, host en mayúsculas' => ["HTTP://127.0.0.1:$p/mis-citas", '/mis-citas'],
            'APP_URL válido' => ['http://localhost:3000/mis-citas', '/mis-citas'],
            'APP_URL con host en mayúsculas' => ['http://LOCALHOST:3000/mis-citas', '/mis-citas'],
            'APP_URL con puerto distinto' => ['http://localhost:3001/mis-citas', 'inicio'],
            'IPv6' => ['http://[::1]:' . '{P}' . '/x', 'inicio'],
            'sin esquema ni host (ruta)' => ['/mis-citas', 'inicio'],
        ];
    }

    /** @dataProvider referersProveedor */
    public function test_la_cancelacion_por_formulario_nunca_redirige_fuera_del_origen(string $caso, string $referer, string $esperado): void
    {
        $referer = str_replace('{P}', (string)self::$puerto, $referer);
        $r = $this->cancelar('cliente', $referer);
        $this->assertSame(302, $r['status'], $caso);
        $loc = $r['location'];
        $this->assertTrue($this->localSeguro($loc), "«{$caso}»: Location no es una ruta local segura: " . json_encode($loc));
        $this->assertArrayNotHasKey('set-cookie-inyectada', $r['headers']);
        foreach ($r['headers']['set-cookie'] ?? [] as $c) { $this->assertStringNotContainsString('x=1', $c, 'inyección de cabeceras mediante CRLF'); }
        if ($esperado === 'inicio') { $this->assertSame(self::$p['cliente']['inicio'], $loc, "«{$caso}»"); }
        elseif ($esperado !== 'cualquiera-seguro') { $this->assertSame($esperado, $loc, "«{$caso}»"); }
    }

    public static function referersProveedor(): array
    {
        $o = [];
        foreach (self::referers() as $k => [$ref, $esp]) { $o[$k] = [$k, $ref, $esp]; }
        return $o;
    }

    public function test_host_manipulado_o_reenviado_no_amplia_los_origenes_permitidos_a_algo_fuera_de_ruta_local(): void
    {
        // Un cliente puede elegir su cabecera Host (es suya): lo único aceptable es que la redirección siga siendo una ruta local.
        $r = $this->cancelar('cliente', 'http://evil.example/steal', ['Host: evil.example']);
        $this->assertContains($r['status'], [302, 403], 'con Host manipulado se esperaba 302 local o rechazo 403');
        if ($r['status'] === 302) { $this->assertTrue($this->localSeguro($r['location']), 'Host manipulado produjo un Location no local: ' . json_encode($r['location'])); }
        // X-Forwarded-Host / Forwarded / X-Original-URL: no son de fiar y no deben permitir un origen externo.
        foreach (['X-Forwarded-Host: evil.example', 'Forwarded: host=evil.example', 'X-Host: evil.example', 'X-Original-Url: http://evil.example/x'] as $h) {
            $r = $this->cancelar('cliente', 'http://evil.example/steal', [$h]);
            $this->assertSame(302, $r['status']);
            $this->assertSame(self::$p['cliente']['inicio'], $r['location'], "$h permitió un Referer externo");
        }
        // Host con userinfo o con salto de línea codificado en el puerto.
        foreach (['Host: 127.0.0.1:' . self::$puerto . '@evil.example', 'Host: evil.example:' . self::$puerto . '/../'] as $h) {
            $r = $this->cancelar('cliente', 'http://evil.example/steal', [$h]);
            $this->assertContains($r["status"], [302, 400, 403, 404]);
            if ($r['status'] === 302) { $this->assertTrue($this->localSeguro($r['location']), $h . ' => ' . json_encode($r['location'])); }
        }
    }

    public function test_sin_referer_el_destino_es_el_inicio_del_portal_de_cada_rol_y_nunca_un_callejon(): void
    {
        foreach (['dueno', 'profesional', 'finanzas', 'cliente'] as $quien) {
            foreach (['99999999', 'no-es-un-id', '', '0', '-5', '1e3'] as $id) {
                $r = $this->cancelar($quien, null, [], $id);
                $this->assertSame(302, $r['status'], "$quien / id «{$id}»");
                $this->assertSame(self::$p[$quien]['inicio'], $r['location'], "$quien / id «{$id}»");
                $this->assertSame(200, $this->sesion($quien)->get($r['location'])['status'], "$quien: el destino {$r['location']} no es accesible para quien lo recibe (callejón)");
            }
        }
        // El anónimo no llega a la lógica de cancelación (CSRF/autenticación primero).
        $an = new Navegador(self::$base);
        $r = $an->post('/api/eliminar', ['csrf_token' => 'x', 'id' => '1'], ['Accept: text/html', 'Referer: https://evil.example']);
        $this->assertNotSame('https://evil.example', $r['location']);
        $this->assertTrue(in_array($r['status'], [302, 403, 401], true), (string)$r['status']);
    }

    public function test_la_respuesta_json_no_usa_el_referer(): void
    {
        $r = $this->cancelar('cliente', 'https://evil.example/x', [], '99999999', 'application/json');
        $this->assertContains($r['status'], [404, 403, 422]);
        $this->assertNull($r['location']);
        $this->assertStringNotContainsString('evil.example', $r['body']);
    }
}
