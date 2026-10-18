<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

use Services\ColaboradorService;

/** B/C/D/F. Escalada por POST con CSRF válido, mass assignment, cookies, ciclo de vida del colaborador y vínculo profesional. */
final class AtaquesVariosTest extends QaCase
{
    private static string $base;
    private static array $p = [];
    private static array $nav = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor();
        $dueno = self::propietario('dueno@example.test');
        self::$p['propietario'] = $dueno + ['portal' => 'equipo'];
        foreach (['gerente', 'recepcion', 'finanzas', 'profesional'] as $rol) {
            self::$p[$rol] = self::miembro($dueno['id'], $rol, ['email' => "$rol@example.test"]) + ['portal' => 'equipo'];
        }
        self::$p['cliente'] = self::cliente(['email' => 'cliente@example.test']) + ['portal' => 'cliente'];
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function nav(string $persona, ?string $portal = null): Navegador
    {
        $portal ??= self::$p[$persona]['portal'];
        $k = "$persona@$portal";
        if (!isset(self::$nav[$k])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $this->assertSame(302, $n->login($portal, self::$p[$persona]['email'], self::$p[$persona]['password'])['status'], $k);
            self::$nav[$k] = $n;
        }
        return self::$nav[$k];
    }

    private function svc(): ColaboradorService
    {
        return new ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
    }

    private function huella(): string
    {
        return json_encode([self::escalar("SELECT GROUP_CONCAT(CONCAT(usuario_id,':',rol,':',estado) ORDER BY id) FROM colaboradores"), self::escalar('SELECT COUNT(*) FROM colaboradores'),
            self::escalar("SELECT GROUP_CONCAT(CONCAT(usuario_id,':',rol,':',activo) ORDER BY usuario_id) FROM usuario_accesos"), self::escalar('SELECT COUNT(*) FROM profesionales'), self::escalar('SELECT COUNT(*) FROM correos_salida')]);
    }

    public static function atacantes(): array { return [['gerente'], ['recepcion'], ['finanzas'], ['profesional']]; }

    /** @dataProvider atacantes */
    public function test_roles_inferiores_no_pueden_mutar_el_equipo_aunque_tengan_csrf_valido(string $quien): void
    {
        $n = $this->nav($quien);
        $csrf = $n->csrf('/equipo/login');
        $antes = $this->huella();
        $propio = (int)self::$p[$quien]['colaborador_id'];
        $otro = (int)self::$p['recepcion']['colaborador_id'];
        $ataques = [
            ['/admin/equipo/actualizar', ['id' => $propio, 'rol' => 'propietario', 'estado' => 'activo']],
            ['/admin/equipo/actualizar', ['id' => $otro, 'rol' => 'recepcion', 'estado' => 'baja']],
            ['/admin/equipo/crear', ['nombre' => 'Mal', 'apellido' => 'Vado', 'email' => self::correo('atq'), 'rol' => 'gerente', 'puesto' => '', 'telefono' => '', 'fecha_ingreso' => '', 'idem_key' => Idem::clave()]],
            ['/admin/equipo/invitar', ['id' => $otro, 'idem_key' => Idem::clave()]],
            ['/admin/equipo/revocar', ['id' => $otro]],
            ['/admin/equipo/ficha?id=' . $otro, ['puesto' => 'Hackeado', 'notas_rh' => 'x', 'telefono' => '', 'fecha_ingreso' => '']],
            ['/profesionales/crear', ['nombre' => 'Hack', 'activo' => '1', 'idem_key' => Idem::clave()]],
        ];
        foreach ($ataques as [$ruta, $campos]) {
            $esperado = [403];
            $r = $n->post($ruta, $campos + ['csrf_token' => $csrf]);
            $this->assertContains($r['status'], $esperado, "$quien $ruta => {$r['status']}");
        }
        // Profesionales: el gerente sí gestiona profesionales; se limpia lo que haya creado antes de comparar.
        self::q("DELETE FROM profesionales WHERE nombre='Hack'");
        self::q('DELETE FROM operaciones_idempotentes');
        $this->assertSame($antes, $this->huella(), "$quien alteró el equipo");
        $this->assertSame('Puesto', self::escalar('SELECT puesto FROM colaboradores WHERE id=?', [$otro]));
    }

    public function test_dual_propietario_en_portal_cliente_no_puede_mutar_el_equipo(): void
    {
        $dual = self::cliente(['email' => 'dualprop@example.test']);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        // El propietario real invita a la persona como gerente y luego la promueve a propietario (camino de producción).
        $r = $repo->crearInvitacion(self::$p['propietario']['id'], ['nombre' => 'Du', 'apellido' => 'Al', 'email' => 'dualprop@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'gerente', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($r['invitacion_id']), '', $dual['password']);
        $this->assertSame('ok', $this->svc()->cambiarAcceso(self::$p['propietario']['id'], $r['colaborador_id'], 'propietario', 'activo')['status']);
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('cliente', $dual['email'], $dual['password'])['status']);
        $csrf = $n->csrf('/cliente/login');
        $antes = $this->huella();
        foreach ([['/admin/equipo/actualizar', ['id' => self::$p['recepcion']['colaborador_id'], 'rol' => 'cliente', 'estado' => 'baja']], ['/admin/equipo/revocar', ['id' => 1]], ['/admin/equipo/ficha?id=1', ['puesto' => 'x']]] as [$ruta, $campos]) {
            $this->assertSame(403, $n->post($ruta, $campos + ['csrf_token' => $csrf])['status'], $ruta);
        }
        $this->assertSame($antes, $this->huella());
        // El mismo dual en portal de equipo sí puede.
        $e = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $e->login('equipo', $dual['email'], $dual['password'])['status']);
        $this->assertSame(200, $e->get('/admin/equipo')['status']);
        // Aislamiento: devuelve a la persona a gerente para que las demás pruebas conserven un único propietario.
        $this->assertSame('ok', $this->svc()->cambiarAcceso(self::$p['propietario']['id'], $r['colaborador_id'], 'gerente', 'activo')['status']);
    }

    public function test_registro_publico_ignora_campos_privilegiados(): void
    {
        $email = self::correo('mass');
        $n = new Navegador(self::$base);
        $csrf = $n->csrf('/cliente/registro');
        $r = $n->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'M', 'apellido' => 'A', 'email' => $email, 'telefono' => '0991112222', 'password' => 'Abcdef123456',
            'admin' => '1', 'confirmado' => '1', 'rol' => 'propietario', 'id' => '1', 'token' => 'x', 'estado' => 'suspendido', 'origen' => 'recepcion', 'portal' => 'equipo', 'usuario_id' => '1', 'rh' => '1']);
        $this->assertSame(302, $r['status']);
        $u = self::q('SELECT id, admin, confirmado, token FROM usuarios WHERE email=?', [$email])[0];
        $this->assertSame([0, 0], [(int)$u['admin'], (int)$u['confirmado']]);
        $this->assertNotSame(1, (int)$u['id']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuario_accesos WHERE usuario_id=?', [$u['id']]));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM colaboradores WHERE usuario_id=?', [$u['id']]));
        $c = self::q('SELECT estado, origen FROM clientes WHERE usuario_id=?', [$u['id']])[0];
        $this->assertSame(['activo', 'registro_publico'], [$c['estado'], $c['origen']]);
        $this->assertNotSame('x', $u['token']);
    }

    public function test_login_regenera_la_sesion_y_las_cookies_son_httponly_samesite(): void
    {
        $n = new Navegador(self::$base);
        $n->get('/cliente/login');
        $antes = $n->cookieSesion();
        $this->assertNotNull($antes);
        $csrf = Navegador::csrfDe($n->ultimo['body']);
        $this->assertStringContainsStringIgnoringCase('httponly', implode(';', $n->ultimo['headers']['set-cookie'] ?? ['HttpOnly']), 'cookie sin HttpOnly');
        $r = $n->post('/cliente/login', ['csrf_token' => $csrf, 'email' => self::$p['cliente']['email'], 'password' => self::$p['cliente']['password']]);
        $this->assertSame(302, $r['status']);
        $this->assertNotSame($antes, $n->cookieSesion(), 'el identificador de sesión no se regeneró al iniciar sesión (fijación de sesión)');
        $cookie = implode(';', $r['headers']['set-cookie'] ?? []);
        $this->assertStringContainsStringIgnoringCase('samesite=lax', $cookie);
        $this->assertStringContainsStringIgnoringCase('httponly', $cookie);
        // El identificador previo ya no sirve.
        $viejo = new Navegador(self::$base);
        $this->assertSame(302, $viejo->get('/cita', ["Cookie: PHPSESSID=$antes"])['status']);
        // Cierre de sesión: el id deja de servir.
        $id = $n->cookieSesion();
        $csrf = $n->csrf('/cita');
        $n->post('/logout', ['csrf_token' => $csrf]);
        $this->assertSame(302, (new Navegador(self::$base))->get('/cita', ["Cookie: PHPSESSID=$id"])['status']);
    }

    public function test_nombres_hostiles_de_cuentas_se_escapan_en_el_encabezado_de_todos_los_portales(): void
    {
        $xss = '<script>alert("n")</script>';
        $cli = self::cliente(['email' => 'xss.cli@example.test', 'nombre' => $xss, 'apellido' => '<img src=x onerror=alert(1)>']);
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('cliente', $cli['email'], $cli['password'])['status']);
        foreach (['/cita', '/perfil', '/mis-citas', '/mi-bienestar'] as $ruta) {
            $r = $n->get($ruta);
            $this->assertStringNotContainsString('<script>alert("n")', $r['body'], $ruta);
            $this->assertStringNotContainsString('<img src=x onerror', $r['body'], $ruta);
        }
        // Personal con nombre hostil (invitación aceptada): el encabezado del portal del equipo también escapa.
        $svc = $this->svc();
        $email = 'xss.eq@example.test';
        $r = $svc->invitar(self::$p['propietario']['id'], ['nombre' => $xss, 'apellido' => '"><svg onload=alert(1)>', 'email' => $email, 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => ''], Idem::clave());
        $this->assertSame('ok', $r['status']);
        $tok = self::tokenDeInvitacion((int)self::escalar('SELECT MAX(id) FROM colaborador_invitaciones'));
        $this->assertSame('ok', $svc->aceptar($tok, ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'], '10.9.0.1')['status']);
        self::q('TRUNCATE intentos_login');
        $e = new Navegador(self::$base);
        $this->assertSame(302, $e->login('equipo', $email, 'Nueva-Pass-123')['status']);
        foreach (['/admin', '/admin/equipo', '/admin/clientes', '/perfil'] as $ruta) {
            $b = $e->get($ruta);
            $this->assertStringNotContainsString('<script>alert("n")', $b['body'], $ruta);
            $this->assertStringNotContainsString('<svg onload', $b['body'], $ruta);
        }
    }

    // ------------------------------------------------------------------ ciclo de vida del colaborador

    public function test_Defecto_cambiar_el_acceso_de_un_profesional_desvincula_su_ficha_de_la_cuenta(): void
    {
        $m = self::miembro(self::$p['propietario']['id'], 'profesional', ['email' => 'vinculo.' . bin2hex(random_bytes(2)) . '@example.test']);
        $this->assertSame((string)$m['id'], (string)self::escalar('SELECT usuarioId FROM profesionales WHERE id=?', [$m['profesional_id']]));
        // Cita futura del profesional para comprobar su agenda por HTTP.
        $c = self::cliente();
        $fecha = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify('+5 days')->format('Y-m-d');
        self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?,'10:00:00','10:00:00','10:30:00',30,?,?,'confirmada','pendiente')", [$fecha, $c['id'], $m['profesional_id']]);
        $nav = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $nav->login('equipo', $m['email'], $m['password'])['status']);
        $agenda = fn() => $nav->get('/agenda?desde=' . $fecha . '&hasta=' . $fecha);
        $this->assertStringContainsString($c['email'] === '' ? 'Cli' : 'Cli', $agenda()['body'], 'control: antes del cambio ve su cita');
        // El propietario pulsa «Guardar acceso» SIN cambiar nada (mismo rol y estado) desde /admin/equipo.
        $owner = $this->nav('propietario');
        $csrf = $owner->csrf('/equipo/login');
        $r = $owner->post('/admin/equipo/actualizar', ['csrf_token' => $csrf, 'id' => $m['colaborador_id'], 'rol' => 'profesional', 'estado' => 'activo']);
        $this->assertSame(302, $r['status']);
        $this->assertSame((string)$m['id'], (string)self::escalar('SELECT usuarioId FROM profesionales WHERE id=?', [$m['profesional_id']]), 'La ficha profesional perdió su cuenta (profesionales.usuarioId=NULL) al guardar el acceso sin cambios: EquipoRepository::actualizar() ejecuta UPDATE profesionales SET usuarioId=NULL incondicionalmente y solo re-vincula si recibe profesionalId');
        // Efecto visible: su sesión cambió de versión; vuelve a entrar y su agenda queda vacía.
        self::q('TRUNCATE intentos_login');
        $nav2 = new Navegador(self::$base);
        $nav2->login('equipo', $m['email'], $m['password']);
        $this->assertStringContainsString('Cli', $nav2->get('/agenda?desde=' . $fecha . '&hasta=' . $fecha)['body'], 'tras guardar el acceso, el profesional ya no ve sus propias citas');
    }

    public function test_Defecto_suspender_y_reactivar_a_un_profesional_lo_deja_activo_en_el_catalogo_pero_sin_cuenta(): void
    {
        $m = self::miembro(self::$p['propietario']['id'], 'profesional', ['email' => 'susrea.' . bin2hex(random_bytes(2)) . '@example.test']);
        $svc = $this->svc();
        $this->assertSame('ok', $svc->cambiarAcceso(self::$p['propietario']['id'], $m['colaborador_id'], 'profesional', 'suspendido')['status']);
        $this->assertSame('ok', $svc->cambiarAcceso(self::$p['propietario']['id'], $m['colaborador_id'], 'profesional', 'activo')['status']);
        $f = self::q('SELECT usuarioId, activo FROM profesionales WHERE id=?', [$m['profesional_id']])[0];
        $this->assertSame(1, (int)$f['activo'], 'reactivado: aparece en la agenda pública');
        $this->assertSame((string)$m['id'], (string)$f['usuarioId'], 'reactivado pero sin cuenta vinculada: no puede ver su agenda, ni atender, ni aparece en "Mis clientes"');
        $ctx = (new \Services\AuthorizationService(self::db()))->contexto($m['id']);
        $this->assertNotNull($ctx['profesional_id']);
    }

    public function test_ciclo_de_estados_de_un_colaborador_no_profesional_es_consistente(): void
    {
        $m = self::miembro(self::$p['propietario']['id'], 'recepcion', ['email' => 'ciclo.' . bin2hex(random_bytes(2)) . '@example.test']);
        $svc = $this->svc();
        $dueno = self::$p['propietario']['id'];
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('equipo', $m['email'], $m['password'])['status']);
        foreach ([['recepcion', 'suspendido'], ['recepcion', 'activo'], ['gerente', 'activo'], ['recepcion', 'baja'], ['recepcion', 'activo']] as [$rol, $estado]) {
            $this->assertSame('ok', $svc->cambiarAcceso($dueno, $m['colaborador_id'], $rol, $estado)['status'], "$rol/$estado");
            $c = self::q('SELECT c.estado ce, c.rol cr, a.rol ar, a.activo aa, u.admin FROM colaboradores c JOIN usuario_accesos a ON a.usuario_id=c.usuario_id JOIN usuarios u ON u.id=c.usuario_id WHERE c.id=?', [$m['colaborador_id']])[0];
            $this->assertSame([$estado, $rol, $rol, $estado === 'activo' ? 1 : 0, in_array($rol, ['propietario', 'gerente']) ? 1 : 0], [$c['ce'], $c['cr'], $c['ar'], (int)$c['aa'], (int)$c['admin']], "$rol/$estado");
        }
        $this->assertSame('forbidden', $svc->cambiarAcceso($dueno, $m['colaborador_id'], 'cliente', 'activo')['status'], 'el rol cliente no es un rol laboral');
        $this->assertSame('forbidden', $svc->cambiarAcceso($dueno, $m['colaborador_id'], 'x', 'activo')['status']);
        $this->assertSame('forbidden', $svc->cambiarAcceso($dueno, $m['colaborador_id'], 'recepcion', 'inexistente')['status']);
        // No es posible dejar a nadie sin propietario: el único propietario no se suspende ni se degrada a sí mismo.
        $cp = (int)self::escalar('SELECT id FROM colaboradores WHERE usuario_id=?', [$dueno]);
        $this->assertSame('forbidden', $svc->cambiarAcceso($dueno, $cp, 'gerente', 'activo')['status']);
        $this->assertSame('forbidden', $svc->cambiarAcceso($dueno, $cp, 'propietario', 'suspendido')['status']);
        $this->assertSame('forbidden', $svc->cambiarAcceso($dueno, $cp, 'propietario', 'baja')['status']);
    }

    public function test_Defecto_no_se_puede_reinvitar_el_mismo_correo_tras_revocar_la_invitacion(): void
    {
        $email = self::correo('reinv');
        $svc = $this->svc();
        $dueno = self::$p['propietario']['id'];
        $r = $svc->invitar($dueno, ['nombre' => 'Re', 'apellido' => 'Inv', 'email' => $email, 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => ''], Idem::clave());
        $this->assertSame('ok', $r['status']);
        $this->assertSame('ok', $svc->revocar($dueno, $r['colaborador_id'])['status']);
        // Se corrige el rol y se vuelve a invitar a la MISMA persona.
        $r2 = $svc->invitar($dueno, ['nombre' => 'Re', 'apellido' => 'Inv', 'email' => $email, 'telefono' => '', 'puesto' => '', 'rol' => 'gerente', 'fecha_ingreso' => ''], Idem::clave());
        $this->assertSame('ok', $r2['status'], 'Una invitación revocada deja una ficha en "baja" sin cuenta que bloquea el correo para siempre (UNIQUE email_norm) y que ninguna acción de la UI puede reactivar o reenviar: ' . json_encode($r2));
    }

    public function test_ficha_heredada_conserva_su_persona_y_se_puede_reinvitar_tras_revocar(): void
    {
        self::q("INSERT INTO profesionales(nombre,activo) VALUES ('Heredada QA',1)");
        $fid = (int)self::db()->insert_id;
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $this->assertContains($fid, array_map('intval', array_column($repo->profesionalesSinCuenta(), 'id')));
        $svc = $this->svc();
        $dueno = self::$p['propietario']['id'];
        $email = self::correo('her');
        $r = $svc->invitar($dueno, ['nombre' => 'He', 'apellido' => 'Re', 'email' => $email, 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => (string)$fid], Idem::clave());
        $this->assertSame('ok', $r['status'], json_encode($r));
        $this->assertSame('ok', $svc->revocar($dueno, $r['colaborador_id'])['status']);
        $this->assertNotContains($fid, array_map('intval', array_column($repo->profesionalesSinCuenta(), 'id')), 'La ficha mantiene su identidad laboral.');
        $nuevo = $svc->invitar($dueno, ['nombre' => 'He', 'apellido' => 'Re', 'email' => $email, 'rol' => 'profesional', 'profesional_id' => (string)$fid], Idem::clave());
        $this->assertSame('ok', $nuevo['status'], json_encode($nuevo));
        $this->assertSame($r['colaborador_id'], $nuevo['colaborador_id']);
        $this->assertSame($fid, (int)$nuevo['profesional_id']);
    }

    public function test_invitar_profesional_con_ficha_heredada_ya_vinculada_es_rechazado(): void
    {
        self::q("INSERT INTO profesionales(nombre,activo) VALUES ('Ocupada QA',1)");
        $fid = (int)self::db()->insert_id;
        $svc = $this->svc();
        $dueno = self::$p['propietario']['id'];
        $a = $svc->invitar($dueno, ['nombre' => 'A', 'apellido' => 'A', 'email' => self::correo('oc1'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => (string)$fid], Idem::clave());
        $this->assertSame('ok', $a['status']);
        $b = $svc->invitar($dueno, ['nombre' => 'B', 'apellido' => 'B', 'email' => self::correo('oc2'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => (string)$fid], Idem::clave());
        $this->assertSame('forbidden', $b['status'], json_encode($b));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaboradores WHERE id=?', [$a['colaborador_id']]));
        $c = $svc->invitar($dueno, ['nombre' => 'C', 'apellido' => 'C', 'email' => self::correo('oc3'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => '999999'], Idem::clave());
        $this->assertSame('forbidden', $c['status']);
    }

    public function test_Defecto_cliente_suspendido_con_rol_laboral_puede_reservar_por_la_api_con_sesion_de_cliente(): void
    {
        $dueno = self::$p['propietario']['id'];
        $m = self::miembro($dueno, 'profesional', ['email' => 'profres.' . bin2hex(random_bytes(2)) . '@example.test']);
        $sid = (int)self::escalar('SELECT servicioId FROM profesionales_servicios WHERE profesionalId=?', [$m['profesional_id']]);
        for ($d = 1; $d <= 7; $d++) { self::q("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,'08:00:00','20:00:00')", [$m['profesional_id'], $d]); }
        $dual = self::cliente(['email' => 'dualsusp.' . bin2hex(random_bytes(2)) . '@example.test']);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $r = $repo->crearInvitacion($dueno, ['nombre' => 'Du', 'apellido' => 'Al', 'email' => $dual['email'], 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($r['invitacion_id']), '', $dual['password']);
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('cliente', $dual['email'], $dual['password'])['status']);
        $csrf = $n->csrf('/cliente/login');
        self::q("UPDATE clientes SET estado='suspendido', suspendido_motivo='abuso' WHERE usuario_id=?", [$dual['id']]);
        $fecha = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify('+6 days')->format('Y-m-d');
        $res = $n->post('/api/citas', ['profesionalId' => $m['profesional_id'], 'fecha' => $fecha, 'hora' => '10:00', 'servicios' => (string)$sid], ['X-CSRF-Token: ' . $csrf]);
        $this->assertContains($res['status'], [401, 403], 'un cliente suspendido reservó con una sesión previa: ' . $res['body']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM citas WHERE usuarioId=?', [$dual['id']]));
    }

    public function test_Defecto_nombre_y_apellido_de_60_caracteres_validos_para_el_formulario_hacen_fallar_la_invitacion(): void
    {
        $n60 = str_repeat('ñ', 60);
        $svc = $this->svc();
        $r = $svc->invitar(self::$p['propietario']['id'], ['nombre' => $n60, 'apellido' => $n60, 'email' => self::correo('largo'), 'telefono' => '', 'puesto' => str_repeat('p', 80), 'rol' => 'recepcion', 'fecha_ingreso' => ''], Idem::clave());
        $this->assertSame('ok', $r['status'], 'Límites declarados: nombre 60 + apellido 60. correos_salida.destinatario_nombre es VARCHAR(120) y recibe "nombre apellido" (121 caracteres): ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $sid = self::servicio();
        $r = $svc->invitar(self::$p['propietario']['id'], ['nombre' => str_repeat('a', 60), 'apellido' => str_repeat('b', 59), 'email' => self::correo('largo2'), 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => ''], Idem::clave());
        $this->assertSame('ok', $r['status'], 'el caso de 119 caracteres sí debe funcionar: ' . json_encode($r));
    }

    public function test_Defecto_recepcion_puede_editar_el_perfil_del_propietario_y_de_otro_personal_por_perfil_id(): void
    {
        $n = $this->nav('recepcion');
        $csrf = $n->csrf('/equipo/login');
        $duenoId = self::$p['propietario']['id'];
        $antes = self::q('SELECT nombre, apellido, telefono FROM usuarios WHERE id=?', [$duenoId])[0];
        $r = $n->post('/perfil?id=' . $duenoId, ['csrf_token' => $csrf, 'nombre' => 'Hackeado', 'apellido' => 'Por Recepcion', 'telefono' => '0990000000', 'contacto_email' => '1', 'recordatorios' => '1']);
        $despues = self::q('SELECT nombre, apellido, telefono FROM usuarios WHERE id=?', [$duenoId])[0];
        // Restaurar para no contaminar otras pruebas.
        self::q('UPDATE usuarios SET nombre=?, apellido=?, telefono=? WHERE id=?', [$antes['nombre'], $antes['apellido'], $antes['telefono'], $duenoId]);
        $this->assertSame($antes, $despues, 'perfiles.gestionar (recepción) debería limitarse a perfiles de CLIENTES; pudo reescribir nombre y teléfono de la cuenta del propietario (HTTP ' . $r['status'] . ')');
    }

    public function test_Defecto_colaborador_suspendido_con_membresia_de_cliente_activa_pierde_tambien_el_portal_de_clientes(): void
    {
        $dueno = self::$p['propietario']['id'];
        $dual = self::cliente(['email' => 'dualstaffsusp.' . bin2hex(random_bytes(2)) . '@example.test']);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $r = $repo->crearInvitacion($dueno, ['nombre' => 'Du', 'apellido' => 'Al', 'email' => $dual['email'], 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($r['invitacion_id']), '', $dual['password']);
        $this->assertSame('ok', $this->svc()->cambiarAcceso($dueno, $r['colaborador_id'], 'recepcion', 'suspendido')['status']);
        $this->assertSame('activo', self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$dual['id']]), 'la membresía de cliente sigue activa: son estados independientes');
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $login = $n->login('cliente', $dual['email'], $dual['password']);
        $this->assertSame(302, $login['status'], 'El login de cliente de alguien con membresía activa debería funcionar: ' . substr(strip_tags($login['body']), 0, 200));
        $this->assertSame(200, $n->get('/cita')['status'], 'La sesión de cliente se invalida en la petición siguiente porque contexto().activo=0 se calcula con el estado laboral (suspendido) y no distingue el portal');
    }

    /**
     * CAMBIO DE CONTRATO deliberado (ronda 2, AGENDA_OPERATIVA «Aviso de posibles homónimos»): la casilla `confirmar_homonimo` ya NO
     * confirma nada. El segundo paso correcto exige la MISMA clave de operación, el MISMO correo (la huella de idempotencia lo incluye),
     * `decision=confirmar` y el `token_confirmacion` HMAC que devolvió el primer paso. Se mantiene la garantía original: nunca se crea
     * la ficha de un homónimo sin una confirmación explícita del operador y el servidor recalcula las coincidencias.
     */
    public function test_homonimo_de_profesional_exige_confirmacion_explicita(): void
    {
        $svc = $this->svc();
        $dueno = self::$p['propietario']['id'];
        $sid = self::servicio();
        $sufijo = bin2hex(random_bytes(3));
        self::q("INSERT INTO profesionales(nombre,activo) VALUES (?,1)", ["  Laura   Gómez $sufijo "]);
        $base = ['nombre' => 'Laura', 'apellido' => "Gómez $sufijo", 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'servicios' => [(string)$sid], 'nombre_profesional' => "laura gómez $sufijo"];
        $email = self::correo('hom1');
        $clave = Idem::clave();
        $a = $svc->invitar($dueno, $base + ['email' => $email], $clave);
        $this->assertSame('invalid', $a['status'], json_encode($a));
        $this->assertTrue($a['requiere_homonimo'] ?? false);
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE colaborador_id IS NOT NULL AND nombre=?", ["laura gómez $sufijo"]));
        $b = $svc->invitar($dueno, $base + ['email' => $email, 'decision' => 'confirmar', 'token_confirmacion' => $a['homonimos']['token']], $clave);
        $this->assertSame('ok', $b['status'], json_encode($b));
    }

    /** Ataques al token del aviso de homónimos: no forjable, no reutilizable, ligado a operador, clave y conjunto de coincidencias. */
    public function test_homonimo_de_profesional_token_forjado_reutilizado_u_otra_clave_no_crea_nada(): void
    {
        $svc = $this->svc();
        $dueno = self::$p['propietario']['id'];
        $gerente = self::$p['gerente']['id'];
        $sid = self::servicio();
        $sufijo = bin2hex(random_bytes(3));
        self::q("INSERT INTO profesionales(nombre,activo) VALUES (?,1)", ["Marta Ríos $sufijo"]);
        $original = self::q("SELECT * FROM profesionales WHERE nombre=?", ["Marta Ríos $sufijo"])[0];
        $base = ['nombre' => 'Marta', 'apellido' => "Ríos $sufijo", 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'servicios' => [(string)$sid], 'nombre_profesional' => "Marta Ríos $sufijo"];
        $creadas = fn() => (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE colaborador_id IS NOT NULL AND nombre=?", ["Marta Ríos $sufijo"]);
        $emailA = self::correo('mr');
        $claveA = Idem::clave();
        $a = $svc->invitar($dueno, $base + ['email' => $emailA], $claveA);
        $this->assertSame('invalid', $a['status']);
        $token = $a['homonimos']['token'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        $intenta = function (int $actor, string $clave, array $extra, string $email) use ($svc, $base): array {
            return $svc->invitar($actor, $base + ['email' => $email] + $extra, $clave);
        };
        // 1. casilla vieja (ya no confirma), con y sin decisión
        $this->assertSame('invalid', $intenta($dueno, $claveA, ['confirmar_homonimo' => '1'], $emailA)['status']);
        $this->assertSame('invalid', $intenta($dueno, $claveA, ['confirmar_homonimo' => '1', 'decision' => 'confirmar'], $emailA)['status'], 'decisión sin token');
        // 2. token vacío, basura, de longitud correcta pero falso, o enviado como arreglo
        foreach (['', 'abc', str_repeat('0', 64), strtoupper($token), $token . 'x', ' ' . $token] as $t) {
            $r = $intenta($dueno, $claveA, ['decision' => 'confirmar', 'token_confirmacion' => $t], $emailA);
            $this->assertSame('invalid', $r['status'], "token «{$t}» aceptado");
        }
        $r = $intenta($dueno, $claveA, ['decision' => 'confirmar', 'token_confirmacion' => [$token]], $emailA);
        $this->assertSame('invalid', $r['status'], 'token en arreglo aceptado');
        // 3. token válido de OTRO operador (gerente usa el del propietario)
        $this->assertSame('invalid', $intenta($gerente, $claveA, ['decision' => 'confirmar', 'token_confirmacion' => $token], $emailA)['status'], 'el token no debe valer para otro operador');
        // 4. token válido con OTRA clave de operación
        $claveB = Idem::clave();
        $this->assertSame('invalid', $intenta($dueno, $claveB, ['decision' => 'confirmar', 'token_confirmacion' => $token], $emailA)['status'], 'el token no debe valer con otra clave');
        // 5. el conjunto de coincidencias cambió (aparece un homónimo nuevo): el token viejo deja de valer
        self::q("INSERT INTO profesionales(nombre,activo) VALUES (?,0)", ["marta rios $sufijo"]);
        $r = $intenta($dueno, $claveA, ['decision' => 'confirmar', 'token_confirmacion' => $token], $emailA);
        $this->assertSame('invalid', $r['status'], 'token con un conjunto distinto de ids aceptado');
        $this->assertTrue($r['homonimos']['confirmacion_invalida'] ?? false);
        $this->assertSame(0, $creadas(), 'se creó una ficha con un token que no corresponde');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM colaboradores WHERE email_norm=?", [mb_strtolower($emailA)]), 'se creó colaborador');
        // 6. con el token NUEVO (recalculado por el servidor para el conjunto real) sí se crea, una sola vez; reutilizarlo no crea otra
        $tokenNuevo = $r['homonimos']['token'];
        $this->assertNotSame($token, $tokenNuevo);
        $ok = $intenta($dueno, $claveA, ['decision' => 'confirmar', 'token_confirmacion' => $tokenNuevo], $emailA);
        $this->assertSame('ok', $ok['status'], json_encode($ok));
        $this->assertSame(1, $creadas());
        $rep = $intenta($dueno, $claveA, ['decision' => 'confirmar', 'token_confirmacion' => $tokenNuevo], $emailA);
        $this->assertSame('ok', $rep['status']);
        $this->assertTrue($rep['repetida'] ?? false);
        $this->assertSame(1, $creadas(), 'doble clic: una sola ficha');
        $emailB = self::correo('mr2');
        $claveC = Idem::clave();
        $otra = $intenta($dueno, $claveC, ['decision' => 'confirmar', 'token_confirmacion' => $tokenNuevo], $emailB);
        $this->assertSame('invalid', $otra['status'], 'un token ya usado no autoriza un alta nueva con otra clave');
        $this->assertSame(1, $creadas());
        // 7. cancelar no crea nada ni toca al homónimo existente
        $claveD = Idem::clave();
        $this->assertSame('invalid', $intenta($dueno, $claveD, [], $emailB)['status']);
        $can = $intenta($dueno, $claveD, ['decision' => 'cancelar'], $emailB);
        $this->assertSame('cancelado', $can['status'], json_encode($can));
        $this->assertSame(1, $creadas());
        // 8. el homónimo preexistente no se modificó ni se borró ni se fusionó
        $this->assertSame($original, self::q("SELECT * FROM profesionales WHERE id=?", [$original['id']])[0]);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE id=?", [$original['id']]));
    }

    public function test_identificadores_hostiles_en_fichas_y_perfiles_dan_404_sin_advertencias(): void
    {
        $o = $this->nav('propietario');
        $antes = strlen(self::logServidor());
        foreach (['/admin/equipo/ficha?id[]=1', '/admin/equipo/ficha?id=1abc', '/admin/equipo/ficha?id=-1', '/admin/equipo/ficha?id=0', '/admin/equipo/ficha?id=99999999999999999999', '/admin/equipo/ficha',
                  '/admin/clientes/ficha?id[]=1', '/admin/clientes/ficha?id=1e3', '/admin/clientes/ficha?id=%00', '/admin/clientes/ficha?id=00001', '/admin/clientes/ficha?id=99999999999',
                  '/perfil?id[]=1', '/perfil?id=abc', '/perfil/foto?id[]=1', '/perfil/foto?id=-3'] as $ruta) {
            $r = $o->get($ruta);
            $this->assertContains($r['status'], [404, 422], "$ruta => {$r['status']}");
        }
        $this->assertDoesNotMatchRegularExpression('/Warning|Notice|TypeError|Fatal/i', substr(self::logServidor(), $antes));
    }
}
