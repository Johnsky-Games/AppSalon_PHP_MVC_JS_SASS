<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5DevPortalesCase.php';

/**
 * Ronda 5 · n5 — portales, permisos y navegación, contra HTTP real (puertos 8170-8179).
 * Reproduce el fallo B de R5DiagnosticoTest («Volver a las citas» → /mis-citas con «Reservar una cita») y lo generaliza a toda la app:
 * guardias de portal, doble membresía, sesión expirada, rol cambiado, errores con «Volver» del mismo portal, menú por rol y
 * escáner de enlaces (ninguna pantalla del equipo enlaza a algo exclusivo del cliente y viceversa).
 */
final class R5DevPortalesTest extends R5DevPortalesCase
{
    private static string $base;
    private static array $dueno;
    private static array $roles = [];
    private static array $cliente;
    private static array $dual;      // recepción con membresía de cliente
    private static array $duenoDual; // propietario con membresía de cliente
    private static int $pid;

    private const INICIO = ['propietario' => '/admin', 'gerente' => '/admin', 'recepcion' => '/admin', 'profesional' => '/agenda', 'finanzas' => '/admin/finanzas'];
    private const EXCLUSIVAS_CLIENTE = ['/cita', '/mis-citas', '/mi-bienestar'];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorPropio();
        self::$dueno = self::propietario(self::correo('dueno'));
        foreach (['gerente', 'recepcion', 'profesional', 'finanzas'] as $rol) {
            self::$roles[$rol] = self::miembro(self::$dueno['id'], $rol, ['nombre' => ucfirst($rol)]);
        }
        self::$roles['propietario'] = self::$dueno;
        self::$cliente = self::cliente(['email' => self::correo('clienta'), 'nombre' => 'Clienta', 'apellido' => 'Cinco']);
        self::$dual = self::miembro(self::$dueno['id'], 'recepcion', ['nombre' => 'Dual']);
        self::conMembresiaCliente(self::$dual['id']);
        self::conMembresiaCliente(self::$dueno['id']);
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1)', ['Pro R5']);
        self::$pid = (int)self::db()->insert_id;
    }

    public static function tearDownAfterClass(): void { self::pararServidorPropio(); }

    private function cita(int $usuarioId, string $fecha = '2030-01-10', string $estado = 'confirmada'): int
    {
        self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?, '10:00:00','10:00:00','10:30:00',30,?,?,?, 'pendiente')", [$fecha, $usuarioId, self::$pid, $estado]);
        return (int)self::db()->insert_id;
    }

    private function json(array $r): array { return json_decode($r['body'], true) ?: []; }

    // ------------------------------------------------------------------ fallo B y guardias de portal

    /** @return array<string,array{0:string}> */
    public static function rolesEquipo(): array
    {
        return ['propietario' => ['propietario'], 'gerente' => ['gerente'], 'recepcion' => ['recepcion'], 'profesional' => ['profesional'], 'finanzas' => ['finanzas']];
    }

    /** @dataProvider rolesEquipo */
    public function test_B_sesion_de_equipo_no_llega_a_pantallas_personales_del_cliente(string $rol): void
    {
        $n = self::entrar(self::$base, 'equipo', self::$roles[$rol]);
        foreach (self::EXCLUSIVAS_CLIENTE as $ruta) {
            $r = $n->get($ruta);
            $this->assertSame(302, $r['status'], "$rol $ruta");
            $this->assertSame(self::INICIO[$rol], $r['location'], "$rol $ruta");
            $fin = $n->get($r['location']);
            $this->assertStringNotContainsString('Reservar una cita', $fin['body'], "$rol $ruta");
            $this->assertStringContainsString('portal de clientes', $fin['body'], "$rol $ruta: la pantalla final explica el aviso");
        }
        $n->get('/'); // el aviso se consume una sola vez
        $r = $n->get(self::INICIO[$rol] === '/admin' ? '/admin' : self::INICIO[$rol]);
        if ($r['status'] === 200) { $this->assertStringNotContainsString('portal de clientes', $r['body']); }
    }

    /** @dataProvider rolesEquipo */
    public function test_B_api_de_reserva_y_de_citas_propias_responde_403_json_a_una_sesion_de_equipo(string $rol): void
    {
        $n = self::entrar(self::$base, 'equipo', self::$roles[$rol]);
        $csrf = Navegador::csrfDe($n->get('/perfil')['body']);
        foreach (['/api/mis-citas', '/api/disponibilidad?profesionalId=1&fecha=2030-01-10&servicios=1', '/api/profesionales'] as $ruta) {
            $r = $n->get($ruta, ['Accept: application/json']);
            $this->assertSame(403, $r['status'], "$rol $ruta");
            $j = $this->json($r);
            $this->assertSame('portal_incorrecto', $j['codigo'] ?? null, "$rol $ruta");
            $this->assertSame(self::INICIO[$rol], $j['destino'], "$rol $ruta");
        }
        $r = $n->post('/api/citas', ['csrf_token' => $csrf, 'profesionalId' => 1, 'fecha' => '2030-01-10', 'hora' => '10:00', 'servicios' => '1', 'usuarioId' => self::$roles[$rol]['id']], ['Accept: application/json']);
        $this->assertSame(403, $r['status'], "$rol POST /api/citas");
        $this->assertSame('portal_incorrecto', $this->json($r)['codigo'] ?? null);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM citas WHERE usuarioId=?', [self::$roles[$rol]['id']]), 'no se creó ninguna cita personal desde el portal del equipo');
    }

    public function test_B_la_pagina_de_error_de_equipo_no_ofrece_enlaces_del_cliente(): void
    {
        $n = self::entrar(self::$base, 'equipo', self::$roles['profesional']);
        $r = $n->get('/admin/finanzas');
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('href="/agenda"', $r['body'], 'Volver al inicio del profesional');
        $this->assertStringContainsString('ui-error-card', $r['body']);
        $this->assertSame([], array_values(array_intersect(self::enlaces($r['body']), ['/cita', '/mis-citas', '/mi-bienestar'])));
    }

    public function test_cliente_en_pantallas_del_equipo_recibe_403_con_volver_a_su_portal(): void
    {
        $n = self::entrar(self::$base, 'cliente', self::$cliente);
        foreach (['/agenda', '/admin', '/admin/finanzas', '/servicios', '/profesionales', '/admin/equipo', '/admin/clientes', '/admin/inventario', '/admin/reportes', '/admin/crecimiento', '/admin/ajustes'] as $ruta) {
            $r = $n->get($ruta);
            $this->assertSame(403, $r['status'], $ruta);
            $this->assertStringContainsString('portal de clientes', $r['body'], $ruta);
            $this->assertMatchesRegularExpression('~<a class="boton" href="/cita"[^>]*>Volver a reservar</a>~', $r['body'], $ruta);
            foreach (self::enlaces($r['body']) as $href) { $this->assertFalse($this->esDelEquipo($href), "$ruta enlaza a $href"); }
        }
        $r = $n->post('/api/pagos/reembolsar', ['csrf_token' => Navegador::csrfDe($n->get('/perfil')['body'])], ['Accept: application/json']);
        $this->assertSame(403, $r['status']);
    }

    private function esDelEquipo(string $href): bool
    {
        return (bool)preg_match('~^/(admin|agenda|caja|servicios|profesionales|equipo/)(/|\?|$)~', $href);
    }

    // ------------------------------------------------------------------ doble membresía y cuentas suspendidas por portal

    public function test_doble_membresia_cada_portal_ve_solo_lo_suyo_y_cambiar_exige_iniciar_sesion_en_el_otro(): void
    {
        foreach ([self::$dueno, self::$dual] as $persona) {
            $n = self::entrar(self::$base, 'equipo', $persona);
            $this->assertSame(302, $n->get('/mis-citas')['status'], 'portal equipo: sin /mis-citas');
            $this->assertSame(200, $n->get('/agenda')['status']);
            $nav = $n->get('/perfil')['body'];
            $this->assertStringNotContainsString('href="/mis-citas"', $nav);
            $this->assertStringNotContainsString('href="/cita"', $nav);
            // Entrar por el otro portal REEMPLAZA la sesión: ya no hay permisos laborales.
            $this->assertSame(302, $n->login('cliente', $persona['email'], $persona['password'])['status']);
            $this->assertSame(403, $n->get('/agenda')['status'], 'sesión de cliente de una persona del equipo: sin agenda');
            $this->assertSame(403, $n->get('/admin')['status']);
            $this->assertSame(200, $n->get('/mis-citas')['status']);
            $perfil = $n->get('/perfil')['body'];
            $this->assertStringContainsString('href="/mis-citas"', $perfil);
            $this->assertStringNotContainsString('href="/agenda"', $perfil);
            $this->assertStringNotContainsString('href="/admin', $perfil);
            // …y de vuelta.
            $this->assertSame(302, $n->login('equipo', $persona['email'], $persona['password'])['status']);
            $this->assertSame(302, $n->get('/mis-citas')['status']);
        }
    }

    public function test_membresia_de_cliente_suspendida_no_afecta_al_equipo_y_acceso_laboral_suspendido_no_afecta_al_cliente(): void
    {
        $p = self::miembro(self::$dueno['id'], 'gerente', ['nombre' => 'Susp']);
        self::conMembresiaCliente($p['id'], 'suspendido');
        $n = self::entrar(self::$base, 'equipo', $p);
        $this->assertSame(200, $n->get('/admin')['status'], 'el equipo sigue operando con la membresía de cliente suspendida');
        self::q('TRUNCATE intentos_login');
        $c = new Navegador(self::$base);
        $r = $c->login('cliente', $p['email'], $p['password']);
        $this->assertSame(403, $r['status'], 'el portal de clientes rechaza la membresía suspendida');
        $this->assertStringContainsString('suspendida', $r['body']);
        // Al revés: colaborador suspendido, membresía de cliente activa.
        self::conMembresiaCliente($p['id'], 'activo');
        self::q("UPDATE colaboradores SET estado='suspendido' WHERE usuario_id=?", [$p['id']]);
        self::q('TRUNCATE intentos_login');
        $e = new Navegador(self::$base);
        $this->assertSame(403, $e->login('equipo', $p['email'], $p['password'])['status'], 'acceso laboral suspendido');
        self::q('TRUNCATE intentos_login');
        $r = $c->login('cliente', $p['email'], $p['password']);
        $this->assertSame(302, $r['status']);
        $this->assertSame('/cita', $r['location']);
        $this->assertSame(200, $c->get('/mis-citas')['status']);
        // Suspender la membresía de cliente a mitad de sesión del portal de clientes la invalida; la del equipo no existe ya.
        self::q("UPDATE clientes SET estado='suspendido' WHERE usuario_id=?", [$p['id']]);
        $r = $c->get('/mis-citas');
        $this->assertSame(302, $r['status']);
        $this->assertSame('/', $r['location']);
    }

    // ------------------------------------------------------------------ sesión expirada y rol cambiado

    public function test_sesion_expirada_vuelve_al_login_del_portal_y_conserva_la_pantalla_pedida_del_catalogo(): void
    {
        $e = self::miembro(self::$dueno['id'], 'gerente', ['nombre' => 'Exp']);
        $n = self::entrar(self::$base, 'equipo', $e);
        $this->assertSame(200, $n->get('/agenda?desde=2030-02-01&hasta=2030-02-03')['status']);
        self::invalidarSesiones($e['id']);
        $r = $n->get('/agenda?desde=2030-02-01&hasta=2030-02-03&evil=http://x');
        $this->assertSame([302, '/equipo/login'], [$r['status'], $r['location']]);
        $this->assertStringContainsString('Inicia sesión de nuevo', $n->get('/equipo/login')['body']);
        self::q('TRUNCATE intentos_login');
        $l = $n->login('equipo', $e['email'], $e['password']);
        $this->assertSame([302, '/agenda?desde=2030-02-01&hasta=2030-02-03'], [$l['status'], $l['location']], 'intención conservada, sin parámetros ajenos');
        $this->assertSame(200, $n->get($l['location'])['status']);

        $c = self::entrar(self::$base, 'cliente', self::$cliente);
        self::invalidarSesiones(self::$cliente['id']);
        $r = $c->get('/mis-citas');
        $this->assertSame([302, '/'], [$r['status'], $r['location']]);
        self::q('TRUNCATE intentos_login');
        $l = $c->login('cliente', self::$cliente['email'], self::$cliente['password']);
        $this->assertSame([302, '/mis-citas'], [$l['status'], $l['location']]);
        // API: 401 JSON, sin redirección.
        self::invalidarSesiones(self::$cliente['id']);
        $r = $c->get('/api/mis-citas', ['Accept: application/json']);
        $this->assertSame(401, $r['status']);
        self::q('TRUNCATE intentos_login');
        $c->login('cliente', self::$cliente['email'], self::$cliente['password']);
    }

    public function test_la_intencion_de_otro_portal_o_sin_permiso_no_se_aplica(): void
    {
        // Pidió una pantalla del equipo sin sesión y entra como cliente: va al inicio del cliente, nunca a la pantalla del equipo.
        $n = new Navegador(self::$base);
        $this->assertSame([302, '/equipo/login'], [($r = $n->get('/admin/finanzas'))['status'], $r['location']]);
        self::q('TRUNCATE intentos_login');
        $l = $n->login('cliente', self::$cliente['email'], self::$cliente['password']);
        $this->assertSame('/cita', $l['location']);
        // Pidió finanzas y entra como profesional (sin permiso): inicio del profesional.
        $n = new Navegador(self::$base);
        $n->get('/admin/finanzas');
        self::q('TRUNCATE intentos_login');
        $l = $n->login('equipo', self::$roles['profesional']['email'], self::$roles['profesional']['password']);
        $this->assertSame('/agenda', $l['location']);
        // Intención forjada en una sesión: solo cuenta lo que existe en el catálogo.
        foreach ([['clave' => 'http://evil.example', 'params' => []], ['clave' => '//evil', 'params' => []], ['clave' => 'agenda', 'params' => ['desde' => "2030-01-01\r\nSet-Cookie: x=1"]], ['clave' => ['x'], 'params' => []], ['clave' => 'login_equipo', 'params' => []]] as $i => $intencion) {
            $id = self::sesionForjada(['destino_tras_login' => $intencion]);
            $nav = new Navegador(self::$base);
            self::q('TRUNCATE intentos_login');
            $csrfPage = $nav->get('/equipo/login', ["Cookie: PHPSESSID=$id"]);
            $p = self::$roles['gerente'];
            $l = $nav->post('/equipo/login', ['csrf_token' => Navegador::csrfDe($csrfPage['body']), 'email' => $p['email'], 'password' => $p['password']], ["Cookie: PHPSESSID=$id"]);
            $this->assertSame(302, $l['status'], "caso $i");
            $this->assertContains($l['location'], ['/admin', '/agenda', '/equipo/login'], "caso $i: " . json_encode($l['location']));
            $this->assertStringNotContainsString('evil', (string)$l['location']);
            $this->assertStringNotContainsString("\n", (string)$l['location']);
        }
    }

    public function test_rol_cambiado_en_caliente_se_revalida_en_el_servidor(): void
    {
        $p = self::miembro(self::$dueno['id'], 'recepcion', ['nombre' => 'Caliente']);
        $n = self::entrar(self::$base, 'equipo', $p);
        $this->assertSame(200, $n->get('/admin')['status']);
        // Sin invalidar la sesión (solo cambia el rol): los permisos se reevalúan, no se cree al navegador ni a la sesión.
        self::q("UPDATE usuario_accesos SET rol='profesional' WHERE usuario_id=?", [$p['id']]);
        $r = $n->get('/admin');
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('href="/agenda"', $r['body'], 'el inicio del error es el del rol vigente');
        // Y si además se invalida la sesión (cambio de rol real): login del equipo.
        self::invalidarSesiones($p['id']);
        $r = $n->get('/agenda');
        $this->assertSame([302, '/equipo/login'], [$r['status'], $r['location']]);
        // Rol laboral retirado (queda solo como cliente): la sesión del equipo ya no vale ni llega al portal de clientes.
        $q = self::miembro(self::$dueno['id'], 'gerente', ['nombre' => 'Retirado']);
        self::conMembresiaCliente($q['id']);
        $n = self::entrar(self::$base, 'equipo', $q);
        self::q("UPDATE usuario_accesos SET rol='cliente' WHERE usuario_id=?", [$q['id']]);
        $r = $n->get('/admin');
        $this->assertSame([302, '/equipo/login'], [$r['status'], $r['location']]);
    }

    // ------------------------------------------------------------------ errores de operación con «Volver» del mismo portal

    public function test_errores_de_cancelacion_desde_el_equipo_y_desde_el_cliente_vuelven_a_su_portal(): void
    {
        $dueno = self::entrar(self::$base, 'equipo', self::$dueno);
        $csrf = Navegador::csrfDe($dueno->get('/admin')['body']);
        // Cita inexistente con la fecha conservada: vuelve a las citas del día y la pantalla final explica qué pasó
        $r = $dueno->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => '99999999', 'volver' => 'admin_citas', 'r_fecha' => '2030-03-04'], ['Accept: text/html', 'Referer: https://evil.example/x']);
        $this->assertSame([302, '/admin?fecha=2030-03-04'], [$r['status'], $r['location']]);
        $fin = $dueno->get($r['location']);
        $this->assertSame(200, $fin['status']);
        $this->assertStringContainsString('La cita ya no existe', $fin['body']);
        $this->assertStringNotContainsString('evil.example', $fin['body']);
        // Identificador inválido
        $r = $dueno->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => 'abc'], ['Accept: text/html']);
        $this->assertSame([302, '/admin'], [$r['status'], $r['location']]);
        $this->assertStringContainsString('Identificador de cita inválido', $dueno->get('/admin')['body']);
        // 403: profesional (equipo) no cancela por el portal del equipo; tampoco su cita personal
        $pro = self::$roles['profesional'];
        self::conMembresiaCliente($pro['id']);
        $propia = $this->cita($pro['id']);
        $np = self::entrar(self::$base, 'equipo', $pro);
        $r = $np->post('/api/eliminar', ['csrf_token' => Navegador::csrfDe($np->get('/perfil')['body']), 'id' => $propia], ['Accept: text/html']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$propia]));
        $this->assertSame([], array_values(array_intersect(self::enlaces($r['body']), self::EXCLUSIVAS_CLIENTE)));
        $r = $np->post('/api/eliminar', ['csrf_token' => Navegador::csrfDe($np->get('/perfil')['body']), 'id' => $propia], ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('portal_incorrecto', $this->json($r)['codigo'] ?? null);

        // Cliente: 404, 403 (cita ajena), éxito
        $c = self::entrar(self::$base, 'cliente', self::$cliente);
        $csrfC = Navegador::csrfDe($c->get('/perfil')['body']);
        $r = $c->post('/api/eliminar', ['csrf_token' => $csrfC, 'id' => '99999999'], ['Accept: text/html', 'Referer: https://evil.example/']);
        $this->assertSame([302, '/mis-citas'], [$r['status'], $r['location']]);
        $fin = $c->get('/mis-citas');
        $this->assertSame(200, $fin['status']);
        $this->assertStringContainsString('La cita ya no existe', $fin['body']);
        foreach (self::enlaces($fin['body']) as $href) { $this->assertFalse($this->esDelEquipo($href), $href); }
        $ajena = $this->cita(self::$dueno['id'], '2030-05-05');
        $r = $c->post('/api/eliminar', ['csrf_token' => $csrfC, 'id' => $ajena], ['Accept: text/html']);
        $this->assertSame(403, $r['status']);
        $this->assertMatchesRegularExpression('~href="/mis-citas"[^>]*>Volver a mis citas~', $r['body']);
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$ajena]));
        $mia = $this->cita(self::$cliente['id'], '2031-05-05');
        $r = $c->post('/api/eliminar', ['csrf_token' => $csrfC, 'id' => $mia], ['Accept: text/html', 'Referer: https://evil.example/']);
        $this->assertSame([302, '/mis-citas'], [$r['status'], $r['location']], 'el éxito vuelve a sus citas, sin Referer');
        $this->assertSame('cancelada', self::escalar('SELECT estado FROM citas WHERE id=?', [$mia]));
        // Equipo con permiso: éxito vuelve a las citas del día con la fecha
        $otra = $this->cita(self::$cliente['id'], '2031-06-06');
        $r = $dueno->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $otra, 'volver' => 'admin_citas', 'r_fecha' => '2031-06-06'], ['Accept: text/html']);
        $this->assertSame([302, '/admin?fecha=2031-06-06'], [$r['status'], $r['location']]);
        // Parámetros de retorno hostiles: se ignoran
        $otra2 = $this->cita(self::$cliente['id'], '2031-07-07');
        foreach (['volver' => ['//evil.example', 'http://evil.example', 'javascript:alert(1)', "agenda\r\nX: y", '../..', 'cliente_citas'], ] as $campo => $valores) {
            foreach ($valores as $v) {
                $id = $v === 'cliente_citas' ? $this->cita(self::$cliente['id'], '2031-08-08') : $this->cita(self::$cliente['id'], '2031-09-09');
                $r = $dueno->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $id, $campo => $v, 'r_desde' => '//evil'], ['Accept: text/html']);
                $this->assertSame(302, $r['status'], $v);
                $this->assertSame('/admin', $r['location'], "volver=$v desde el equipo cae en el inicio de su portal");
            }
        }
        $r = $dueno->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $otra2, 'volver' => ['agenda'], 'r_fecha' => ['2030-01-01']], ['Accept: text/html']);
        $this->assertSame('/admin', $r['location'], 'arreglos en volver/r_*');
    }

    public function test_503_con_la_base_caida_no_ofrece_enlaces_del_portal_equivocado(): void
    {
        $base = self::servidorPropio('n5_503', ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1']);
        foreach ([['equipo', 'gerente', '/admin'], ['cliente', 'cliente', '/cita']] as [$portal, $rol, $inicio]) {
            $id = self::sesionForjada(['login' => true, 'id' => 7, 'nombre' => 'X', 'apellido' => 'Y', 'email' => 'x@example.test', 'portal' => $portal, 'rol' => $rol, 'version_sesion' => 1], 'n5_503');
            $n = new Navegador($base);
            $r = $n->get($portal === 'equipo' ? '/agenda' : '/mis-citas', ["Cookie: PHPSESSID=$id"]);
            $this->assertSame(503, $r['status'], $portal);
            $enlaces = self::enlaces($r['body']);
            $this->assertContains($inicio, $enlaces, $portal);
            foreach ($enlaces as $h) {
                if ($portal === 'equipo') { $this->assertNotContains($h, self::EXCLUSIVAS_CLIENTE); } else { $this->assertFalse($this->esDelEquipo($h), $h); }
            }
        }
        self::pararServidorPropio('n5_503');
    }

    // ------------------------------------------------------------------ menú por rol y escáner de enlaces

    private function navHrefs(string $html): array
    {
        preg_match('~<nav class="ui-main-nav.*?</nav>~s', $html, $m);
        preg_match_all('~<a href="([^"]+)" class="ui-nav-link~', $m[0] ?? '', $h);
        return $h[1];
    }

    public function test_el_menu_muestra_solo_lo_que_el_servidor_permite_a_cada_rol_y_marca_la_seccion_actual(): void
    {
        $esperado = [
            'propietario' => ['/admin', '/agenda', '/servicios', '/servicios/crear', '/profesionales', '/admin/equipo', '/admin/finanzas', '/admin/inventario', '/admin/reportes', '/admin/crecimiento', '/admin/clientes', '/admin/ajustes', '/perfil'],
            'gerente' => ['/admin', '/agenda', '/servicios', '/servicios/crear', '/profesionales', '/admin/finanzas', '/admin/inventario', '/admin/reportes', '/admin/crecimiento', '/admin/clientes', '/admin/ajustes', '/perfil'],
            'recepcion' => ['/admin', '/agenda', '/admin/clientes', '/perfil'],
            'profesional' => ['/agenda', '/admin/clientes', '/perfil'],
            'finanzas' => ['/admin/finanzas', '/admin/inventario', '/admin/reportes', '/admin/clientes', '/perfil'],
        ];
        foreach ($esperado as $rol => $hrefs) {
            $n = self::entrar(self::$base, 'equipo', self::$roles[$rol]);
            $pag = $n->get('/perfil')['body'];
            $this->assertSame($hrefs, array_values(array_diff($this->navHrefs($pag), ['/caja'])), "menú de $rol");
            $this->assertSame(1, substr_count($pag, 'aria-current="page"'), "$rol: una sola sección marcada en /perfil");
            $this->assertStringContainsString('aria-label="Secciones del equipo"', $pag);
            foreach (self::EXCLUSIVAS_CLIENTE as $x) { $this->assertNotContains($x, $this->navHrefs($pag), "$rol no ve $x"); }
        }
        $n = self::entrar(self::$base, 'equipo', self::$dueno);
        foreach (['/servicios/crear' => '/servicios/crear', '/servicios' => '/servicios', '/admin/finanzas' => '/admin/finanzas', '/admin' => '/admin', '/profesionales/horarios?id=1' => '/profesionales'] as $ruta => $activo) {
            $pag = $n->get($ruta)['body'];
            preg_match_all('~<a href="([^"]+)" class="ui-nav-link is-active" aria-current="page"~', $pag, $m);
            $this->assertSame([$activo], $m[1], "sección activa en $ruta");
        }
        $c = self::entrar(self::$base, 'cliente', self::$cliente);
        $pag = $c->get('/mis-citas')['body'];
        $this->assertSame(['/cita', '/mis-citas', '/perfil', '/mi-bienestar'], $this->navHrefs($pag));
        $this->assertStringContainsString('aria-label="Secciones del cliente"', $pag);
    }

    public function test_escaner_de_enlaces_las_pantallas_de_cada_portal_no_enlazan_al_otro(): void
    {
        $equipo = ['/admin', '/agenda', '/admin/finanzas', '/admin/clientes', '/admin/reportes', '/servicios', '/servicios/crear', '/profesionales', '/profesionales/crear', '/admin/equipo', '/admin/inventario', '/admin/crecimiento', '/admin/ajustes', '/perfil', '/ruta-que-no-existe', '/admin/clientes/nuevo'];
        $revisadas = 0;
        foreach (self::INICIO as $rol => $_) {
            $n = self::entrar(self::$base, 'equipo', self::$roles[$rol]);
            foreach ($equipo as $ruta) {
                $r = $n->get($ruta);
                if (!in_array($r['status'], [200, 403, 404], true)) { continue; }
                $revisadas++;
                foreach (self::enlaces($r['body']) as $href) {
                    $path = explode('?', $href)[0];
                    $this->assertNotContains($path, self::EXCLUSIVAS_CLIENTE, "[$rol] $ruta enlaza a $href (ruta exclusiva del cliente)");
                    $this->assertStringStartsNotWith('/api/mis-citas', $href);
                }
            }
        }
        $this->assertGreaterThan(40, $revisadas);
        $c = self::entrar(self::$base, 'cliente', self::$cliente);
        foreach (['/cita', '/mis-citas', '/mi-bienestar', '/perfil', '/ruta-que-no-existe', '/agenda', '/admin', '/admin/finanzas'] as $ruta) {
            $r = $c->get($ruta);
            foreach (self::enlaces($r['body']) as $href) { $this->assertFalse($this->esDelEquipo($href), "[cliente] $ruta enlaza a $href (ruta del equipo)"); }
        }
    }
}
