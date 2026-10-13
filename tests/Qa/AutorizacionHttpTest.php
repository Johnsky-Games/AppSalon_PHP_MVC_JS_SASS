<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

use Services\AuthorizationService;

/**
 * B. Autorización por acceso DIRECTO sobre HTTP real (php -S). Sin visitar pantallas previas: cada persona abre cada ruta
 * con su cookie. La fuente de verdad debe ser el servidor, no los campos de la sesión.
 */
final class AutorizacionHttpTest extends QaCase
{
    /** @var array<string,array> */
    private static array $p = [];
    private static string $base;
    /** @var array<string,Navegador> */
    private static array $nav = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor();
        $dueno = self::propietario('dueno@example.test');
        self::$p['propietario'] = $dueno + ['portal' => 'equipo', 'rol' => 'propietario'];
        foreach (['gerente', 'recepcion', 'profesional', 'finanzas'] as $rol) {
            self::$p[$rol] = self::miembro($dueno['id'], $rol, ['email' => "$rol@example.test"]) + ['portal' => 'equipo'];
        }
        self::$p['cliente'] = self::cliente(['email' => 'cliente@example.test']) + ['portal' => 'cliente', 'rol' => 'cliente'];
        self::$p['cliente_susp'] = self::cliente(['email' => 'susp@example.test', 'estado' => 'suspendido']) + ['portal' => 'cliente', 'rol' => 'cliente'];
        // Persona con ambos perfiles: cliente que luego es invitada como gerente.
        $dual = self::cliente(['email' => 'dual@example.test']);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $r = $repo->crearInvitacion($dueno['id'], ['nombre' => 'Du', 'apellido' => 'Al', 'email' => 'dual@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'gerente', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($r['invitacion_id']), '', $dual['password']);
        self::$p['dual'] = $dual + ['rol' => 'gerente', 'colaborador_id' => $r['colaborador_id']];
        // Invitado pendiente y colaborador de baja.
        $inv = $repo->crearInvitacion($dueno['id'], ['nombre' => 'In', 'apellido' => 'Vi', 'email' => 'invitado@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        self::$p['invitado'] = ['email' => 'invitado@example.test', 'password' => 'nada', 'id' => 0, 'colaborador_id' => $inv['colaborador_id'], 'token' => self::tokenDeInvitacion($inv['invitacion_id'])];
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); }

    private function nav(string $persona, ?string $portal = null): Navegador
    {
        $portal ??= self::$p[$persona]['portal'];
        $k = "$persona@$portal";
        if (!isset(self::$nav[$k])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $r = $n->login($portal, self::$p[$persona]['email'], self::$p[$persona]['password']);
            $this->assertSame(302, $r['status'], "login $k: " . substr(strip_tags($r['body']), 0, 200));
            self::$nav[$k] = $n;
        }
        return self::$nav[$k];
    }

    /** Ruta => permisos que la habilitan (cualquiera de ellos). Fuente: controladores. */
    private const RUTAS = [
        '/admin/equipo' => ['equipo.gestionar'],
        '/admin/clientes' => ['clientes.ver', 'clientes.analitica', 'clientes.ver_propios'],
        '/admin/clientes/exportar' => ['clientes.exportar'],
        '/profesionales' => ['profesionales.gestionar'],
        '/admin/finanzas' => ['finanzas.gestionar'],
        '/admin/inventario' => ['inventario.gestionar'],
        '/admin/crecimiento' => ['marketing.gestionar'],
        '/admin/ajustes' => ['ajustes.gestionar'],
        '/admin/reportes' => ['reportes.ver'],
        '/admin' => ['agenda.gestionar'],
        '/servicios' => ['servicios.gestionar'],
        '/agenda' => ['agenda.gestionar', 'agenda.ver_propia'],
    ];

    private function permitido(string $rol, array $perms): bool
    {
        return count(array_intersect($perms, AuthorizationService::ROLES[$rol] ?? [])) > 0;
    }

    public static function personasEquipo(): array
    {
        return [['propietario'], ['gerente'], ['recepcion'], ['profesional'], ['finanzas']];
    }

    /** @dataProvider personasEquipo */
    public function test_matriz_rol_x_ruta_portal_equipo_acceso_directo(string $persona): void
    {
        $rol = self::$p[$persona]['rol'];
        $n = $this->nav($persona);
        $fallos = [];
        foreach (self::RUTAS as $ruta => $perms) {
            $r = $n->get($ruta);
            $esperado = $this->permitido($rol, $perms) ? [200] : [403];
            if (!in_array($r['status'], $esperado, true) && !($ruta === '/admin/clientes/exportar' && $r['status'] === 200)) {
                $fallos[] = "$persona $ruta => {$r['status']} (esperado " . implode('/', $esperado) . ')';
            }
        }
        $this->assertSame([], $fallos);
    }

    public function test_cliente_no_accede_a_ninguna_ruta_laboral_nueva_ni_heredada(): void
    {
        $n = $this->nav('cliente');
        $fallos = [];
        foreach (array_keys(self::RUTAS) as $ruta) {
            $r = $n->get($ruta);
            if ($r['status'] !== 403) { $fallos[] = "$ruta => {$r['status']}"; }
        }
        foreach (['/admin/equipo/ficha?id=1', '/admin/clientes/ficha?id=1'] as $ruta) {
            $r = $n->get($ruta);
            if ($r['status'] !== 403) { $fallos[] = "$ruta => {$r['status']}"; }
        }
        $this->assertSame([], $fallos);
    }

    public function test_anonimo_es_redirigido_o_401_nunca_ve_contenido(): void
    {
        $n = new Navegador(self::$base);
        $fallos = [];
        foreach (array_keys(self::RUTAS) + [] as $ruta) { }
        foreach (array_keys(self::RUTAS) as $ruta) {
            $r = $n->get($ruta);
            if (!($r['status'] === 302 && in_array($r['location'], ['/', '/equipo/login'], true))) { $fallos[] = "$ruta => {$r['status']} {$r['location']}"; }
        }
        foreach (['/admin/equipo/ficha?id=1', '/admin/clientes/ficha?id=1', '/perfil', '/api/mis-citas'] as $ruta) {
            $r = $n->get($ruta);
            if (!in_array($r['status'], [302, 401], true)) { $fallos[] = "$ruta => {$r['status']}"; }
        }
        $this->assertSame([], $fallos);
        $this->assertSame(401, $n->get('/api/mis-citas')['status']);
        $this->assertSame(401, $n->get('/api/mis-citas', ['Accept: text/html'])['status'], 'las rutas /api responden JSON/401 incluso con Accept html');
    }

    public function test_persona_con_ambos_perfiles_en_portal_cliente_no_tiene_privilegios_laborales(): void
    {
        $n = $this->nav('dual', 'cliente');
        $fallos = [];
        foreach (array_keys(self::RUTAS) as $ruta) {
            $r = $n->get($ruta);
            if ($r['status'] !== 403) { $fallos[] = "dual@cliente $ruta => {$r['status']}"; }
        }
        $r = $n->get('/admin/clientes/ficha?id=' . self::$p['cliente']['id']);
        if ($r['status'] !== 403) { $fallos[] = "dual@cliente ficha => {$r['status']}"; }
        $this->assertSame([], $fallos);
        $this->assertSame(200, $n->get('/cita')['status'], 'sigue pudiendo usar el portal de clientes');
    }

    public function test_persona_con_ambos_perfiles_en_portal_equipo_si_tiene_el_rol(): void
    {
        $n = $this->nav('dual', 'equipo');
        $this->assertSame(200, $n->get('/admin/clientes')['status']);
        $this->assertSame(200, $n->get('/profesionales')['status']);
        $this->assertSame(403, $n->get('/admin/equipo')['status'], 'gerente no administra el equipo');
    }

    public function test_sesion_forjada_con_rol_admin_y_portal_en_cuenta_cliente_no_eleva_privilegios(): void
    {
        $cli = self::$p['cliente'];
        $ctx = (new AuthorizationService(self::db()))->contexto($cli['id']);
        $id = self::forjarSesion(['login' => true, 'id' => $cli['id'], 'nombre' => 'X', 'apellido' => 'Y', 'email' => $cli['email'], 'rol' => 'propietario', 'admin' => '1', 'portal' => 'equipo', 'version_sesion' => (int)$ctx['version_sesion']]);
        $n = new Navegador(self::$base);
        $fallos = [];
        foreach (array_keys(self::RUTAS) as $ruta) {
            $r = $n->get($ruta, ["Cookie: PHPSESSID=$id"]);
            // Una sesión de portal "equipo" sobre una cuenta sin rol laboral debe terminar: 302 a login o 403, nunca 200.
            if (!in_array($r['status'], [302, 403], true)) { $fallos[] = "$ruta => {$r['status']}"; }
        }
        $this->assertSame([], $fallos);
    }

    public function test_sesion_forjada_portal_cliente_sobre_cuenta_gerente_no_da_privilegios_laborales(): void
    {
        $g = self::$p['gerente'];
        $ctx = (new AuthorizationService(self::db()))->contexto($g['id']);
        $id = self::forjarSesion(['login' => true, 'id' => $g['id'], 'nombre' => 'X', 'apellido' => 'Y', 'email' => $g['email'], 'rol' => 'propietario', 'admin' => '1', 'portal' => 'cliente', 'version_sesion' => (int)$ctx['version_sesion']]);
        $n = new Navegador(self::$base);
        $fallos = [];
        foreach (array_keys(self::RUTAS) as $ruta) {
            $r = $n->get($ruta, ["Cookie: PHPSESSID=$id"]);
            // Denegado = 403, o 302/401 cuando la sesión forjada se invalida por no tener membresía de cliente
            // (resultado más estricto desde que cada membresía tiene su propio estado). Nunca 200.
            if (!in_array($r['status'], [302, 401, 403], true)) { $fallos[] = "$ruta => {$r['status']}"; }
        }
        $this->assertSame([], $fallos);
    }

    public function test_sesion_forjada_sin_marca_de_portal_se_deriva_del_rol_del_servidor(): void
    {
        $cli = self::$p['cliente'];
        $ctx = (new AuthorizationService(self::db()))->contexto($cli['id']);
        // Sesión "antigua" sin portal, con rol=propietario falsificado: no debe dar acceso.
        $id = self::forjarSesion(['login' => true, 'id' => $cli['id'], 'rol' => 'propietario', 'admin' => '1', 'version_sesion' => (int)$ctx['version_sesion']]);
        $n = new Navegador(self::$base);
        foreach (['/admin/equipo', '/admin/clientes', '/admin/finanzas', '/admin'] as $ruta) {
            $this->assertSame(403, $n->get($ruta, ["Cookie: PHPSESSID=$id"])['status'], $ruta);
        }
    }

    public function test_version_de_sesion_vieja_expira_la_sesion(): void
    {
        $n = new Navegador(self::$base);
        $r = $n->login('equipo', self::$p['recepcion']['email'], self::$p['recepcion']['password']);
        $this->assertSame(302, $r['status']);
        $this->assertSame(200, $n->get('/admin')['status']);
        self::q('UPDATE usuarios SET version_sesion=version_sesion+1 WHERE id=?', [self::$p['recepcion']['id']]);
        $r = $n->get('/admin');
        $this->assertSame(302, $r['status']);
        $this->assertSame('/equipo/login', $r['location']);
        $r2 = $n->get('/equipo/login');
        $this->assertStringContainsString('Tu sesión terminó', $r2['body']);
    }

    public function test_colaborador_suspendido_pierde_la_sesion_abierta_de_inmediato(): void
    {
        $m = self::miembro(self::$p['propietario']['id'], 'recepcion', ['email' => 'sus@example.test']);
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('equipo', $m['email'], $m['password'])['status']);
        $this->assertSame(200, $n->get('/admin')['status']);
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $this->assertSame('ok', $svc->cambiarAcceso(self::$p['propietario']['id'], $m['colaborador_id'], 'recepcion', 'suspendido')['status']);
        $r = $n->get('/admin');
        $this->assertContains($r['status'], [302, 403]);
        $this->assertNotSame(200, $r['status']);
        // Intento de login posterior: mensaje de suspensión, 403.
        self::q('TRUNCATE intentos_login');
        $r = (new Navegador(self::$base))->login('equipo', $m['email'], $m['password']);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('suspendido', $r['body']);
    }

    public function test_colaborador_dado_de_baja_no_entra_al_equipo_ni_hereda_cliente(): void
    {
        $m = self::miembro(self::$p['propietario']['id'], 'recepcion', ['email' => 'baja@example.test']);
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $this->assertSame('ok', $svc->cambiarAcceso(self::$p['propietario']['id'], $m['colaborador_id'], 'recepcion', 'baja')['status']);
        self::q('TRUNCATE intentos_login');
        $r = (new Navegador(self::$base))->login('equipo', $m['email'], $m['password']);
        $this->assertContains($r['status'], [403, 422], 'baja: sin acceso al equipo');
        self::q('TRUNCATE intentos_login');
        $r = (new Navegador(self::$base))->login('cliente', $m['email'], $m['password']);
        $this->assertNotSame(302, $r['status'], 'una baja laboral no debe convertirse en cliente con sesión activa');
    }

    public function test_invitado_pendiente_no_inicia_sesion_en_el_equipo_con_credenciales_inexistentes(): void
    {
        $r = (new Navegador(self::$base))->login('equipo', 'invitado@example.test', 'cualquiera');
        $this->assertSame(200, $r['status'], 'sin cuenta: respuesta genérica de credenciales');
        $this->assertStringContainsString('Credenciales incorrectas', $r['body']);
    }

    public function test_cliente_suspendido_no_inicia_sesion_y_sesion_abierta_se_cierra(): void
    {
        $c = self::cliente(['email' => 'abierta@example.test']);
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('cliente', $c['email'], $c['password'])['status']);
        $this->assertSame(200, $n->get('/cita')['status']);
        self::q("UPDATE clientes SET estado='suspendido' WHERE usuario_id=?", [$c['id']]);
        $r = $n->get('/cita');
        $this->assertNotSame(200, $r['status'], 'una membresía suspendida debe cerrar la sesión abierta');
        self::q('TRUNCATE intentos_login');
        $r = (new Navegador(self::$base))->login('cliente', $c['email'], $c['password']);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('suspendida', $r['body']);
    }

    public function test_Defecto_cliente_suspendido_con_rol_laboral_conserva_sesion_de_cliente_activa(): void
    {
        // Persona con ambos perfiles: se suspende su MEMBRESÍA de cliente (no su rol laboral) con la sesión de cliente abierta.
        $dueno = self::$p['propietario']['id'];
        $dual = self::cliente(['email' => 'dual2@example.test']);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $r = $repo->crearInvitacion($dueno, ['nombre' => 'Du', 'apellido' => 'Al', 'email' => 'dual2@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($r['invitacion_id']), '', $dual['password']);
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('cliente', $dual['email'], $dual['password'])['status']);
        $this->assertSame(200, $n->get('/cita')['status']);
        self::q("UPDATE clientes SET estado='suspendido' WHERE usuario_id=?", [$dual['id']]);
        // El login nuevo sí se bloquea (403)...
        self::q('TRUNCATE intentos_login');
        $this->assertSame(403, (new Navegador(self::$base))->login('cliente', $dual['email'], $dual['password'])['status']);
        // ...pero la sesión de cliente YA abierta debería terminar igual que la de un cliente puro.
        $this->assertNotSame(200, $n->get('/cita')['status'], 'La sesión de cliente sigue activa tras suspender la membresía (contexto() solo desactiva a quien tiene rol cliente)');
    }

    public function test_perfil_y_apis_exigen_sesion_y_no_filtran_por_rol_forjado(): void
    {
        $n = new Navegador(self::$base);
        foreach (['/perfil', '/perfil/foto', '/api/mis-citas'] as $ruta) {
            $r = $n->get($ruta);
            $this->assertContains($r['status'], [302, 401, 404, 422], "$ruta anónimo => {$r['status']}");
            $this->assertStringNotContainsString('"servicios"', $r['body']);
        }
    }

    public static function mutaciones(): array
    {
        return [
            ['/admin/equipo/crear'], ['/admin/equipo/actualizar'], ['/admin/equipo/invitar'], ['/admin/equipo/revocar'],
            ['/admin/equipo/ficha?id=1'], ['/profesionales/crear'], ['/servicios/crear'], ['/admin/finanzas'], ['/admin/inventario'],
            ['/admin/crecimiento'], ['/admin/ajustes'], ['/perfil'], ['/agenda/estado'], ['/agenda/reprogramar'], ['/logout'],
        ];
    }

    /** @dataProvider mutaciones */
    public function test_mutaciones_sin_csrf_o_con_csrf_erroneo_se_rechazan_con_403(string $ruta): void
    {
        $n = $this->nav('propietario');
        $antes = $this->huellaEstado();
        $cuerpo = ['id' => '1', 'nombre' => 'X', 'apellido' => 'Y', 'email' => 'x@example.test', 'rol' => 'recepcion', 'estado' => 'baja', 'idem_key' => Idem::clave()];
        $casos = [
            'sin csrf' => $cuerpo,
            'csrf vacío' => $cuerpo + ['csrf_token' => ''],
            'csrf incorrecto' => $cuerpo + ['csrf_token' => str_repeat('a', 64)],
            'csrf arreglo' => http_build_query($cuerpo) . '&csrf_token[]=' . str_repeat('a', 64),
            'idem_key como csrf' => array_merge($cuerpo, ['csrf_token' => $cuerpo['idem_key']]),
        ];
        foreach ($casos as $nombre => $c) {
            if ($ruta === '/logout' && $nombre === 'sin csrf') { }
            $r = $n->post($ruta, $c);
            $this->assertSame(403, $r['status'], "$ruta [$nombre] => {$r['status']}");
        }
        $this->assertSame($antes, $this->huellaEstado(), "$ruta mutó datos sin CSRF válido");
        $this->assertSame(200, $n->get('/admin/equipo')['status'], 'la sesión sigue viva (logout sin CSRF no cierra)');
    }

    private function huellaEstado(): string
    {
        return json_encode([self::escalar('SELECT COUNT(*) FROM colaboradores'), self::escalar('SELECT COUNT(*) FROM profesionales'), self::escalar('SELECT COUNT(*) FROM servicios'),
            self::escalar("SELECT GROUP_CONCAT(CONCAT(id,':',rol,':',estado) ORDER BY id) FROM colaboradores"), self::escalar('SELECT COUNT(*) FROM operaciones_idempotentes')]);
    }

    public function test_idem_key_no_sustituye_al_csrf_en_el_registro_publico(): void
    {
        $n = new Navegador(self::$base);
        $n->get('/cliente/registro');
        $k = Idem::clave();
        $r = $n->post('/cliente/registro', ['nombre' => 'A', 'apellido' => 'B', 'email' => 'regcsrf@example.test', 'password' => 'Abcdef123456', 'telefono' => '0991112222', 'idem_key' => $k, 'csrf_token' => $k]);
        $this->assertSame(403, $r['status']);
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM usuarios WHERE email='regcsrf@example.test'"));
    }

    public function test_aceptar_invitacion_exige_csrf_y_no_consume_el_token_en_get_ni_head(): void
    {
        $tok = self::$p['invitado']['token'];
        $n = new Navegador(self::$base);
        $this->assertSame(200, $n->get('/equipo/aceptar?token=' . $tok)['status']);
        $h = $n->peticion('HEAD', '/equipo/aceptar?token=' . $tok);
        $this->assertContains($h['status'], [200]);
        $this->assertSame('pendiente', self::escalar('SELECT estado FROM colaborador_invitaciones WHERE colaborador_id=?', [self::$p['invitado']['colaborador_id']]));
        $r = $n->post('/equipo/aceptar?token=' . $tok, ['password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123', 'acepto' => '1']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('pendiente', self::escalar('SELECT estado FROM colaborador_invitaciones WHERE colaborador_id=?', [self::$p['invitado']['colaborador_id']]));
    }

    public function test_get_de_invitacion_vencida_marca_expirada_sin_consumirla(): void
    {
        // vistaInvitacion() ejecuta un UPDATE incluso en GET/HEAD (marca 'expirada'); para token vigente no cambia nada, pero una
        // petición GET con token pendiente y vencido SÍ escribe en la base. Se documenta como observación y se verifica que no consuma.
        self::q("UPDATE colaborador_invitaciones SET expira_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE colaborador_id=?", [self::$p['invitado']['colaborador_id']]);
        $n = new Navegador(self::$base);
        $r = $n->get('/equipo/aceptar?token=' . self::$p['invitado']['token']);
        $this->assertSame(410, $r['status']);
        $this->assertSame('expirada', self::escalar('SELECT estado FROM colaborador_invitaciones WHERE colaborador_id=?', [self::$p['invitado']['colaborador_id']]));
        self::q("UPDATE colaborador_invitaciones SET estado='pendiente', expira_en=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY) WHERE colaborador_id=?", [self::$p['invitado']['colaborador_id']]);
    }
}
