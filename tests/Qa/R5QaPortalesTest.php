<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * R5-QA · Separación de portales (matriz 2 del usuario): «no mezclar el portal del cliente con el del equipo».
 *
 * Req P1 escáner de enlaces del equipo · Req P2 escáner del cliente · Req P3 sesión de equipo en pantallas exclusivas del cliente ·
 * Req P4 doble pertenencia (persona cliente + propietario/recepción) en cada portal · Req P5 membresías suspendidas ·
 * Req P6 sesión expirada · Req P7 rol cambiado en caliente · Req P8 errores 403/404/409/422/500/503 originados por acciones del equipo y del cliente ·
 * Req P9 matriz ruta × rol del contrato (R5_CONTRATOS §6).
 */
final class R5QaPortalesTest extends R5Case
{
    private static string $base;
    private static array $e;
    private static int $citaProA;
    private static int $citaCompletada;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorQ5('portales');
        self::$e = self::escenario();
        [$f, $h] = self::slotComenzado();
        self::$citaProA = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h]);
        self::$citaCompletada = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h, 'estado' => 'completada']);
        // Doble pertenencia: el propietario TAMBIÉN es cliente; una recepcionista y un gerente también.
        self::q("INSERT INTO clientes(usuario_id,estado,origen) VALUES (?, 'activo', 'recepcion') ON DUPLICATE KEY UPDATE estado='activo'", [self::$e['dueno']['id']]);
        self::$e['dobleRecepcion'] = self::personaDoble(self::$e['dueno']['id'], 'recepcion');
        self::$e['dobleGerente'] = self::personaDoble(self::$e['dueno']['id'], 'gerente');
    }

    public static function tearDownAfterClass(): void { self::pararServidorQ5('portales'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    // ------------------------------------------------------------------ ayudas

    private function eq(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }

    /** Inicio esperado del portal del equipo por rol (includes/funciones.php::inicio_portal, contrato §4). */
    private const INICIO = ['dueno' => '/admin', 'gerente' => '/admin', 'recepcion' => '/admin', 'finanzas' => '/admin/finanzas', 'proA' => '/agenda', 'proB' => '/agenda'];

    private function homeDeError(string $html): string
    {
        $x = self::xp($html);
        foreach ($x->query('//a') as $a) {
            if (preg_match('/Volver al inicio|Ir al inicio|Iniciar sesi/iu', $a->textContent)) { return self::pathDe(self::rutaDe($a->getAttribute('href'))); }
        }
        return '';
    }

    /** Páginas del equipo escaneadas por rol (ruta ⇒ roles que las ven; el resto debe recibir un 403 igualmente limpio). */
    private function paginasEquipo(): array
    {
        $c = self::$citaCompletada;
        $cli = self::$e['cliente']['id'];
        return ['/admin', '/agenda', '/agenda?desde=' . self::gye()->format('Y-m-d') . '&hasta=' . self::gye('+2 days')->format('Y-m-d'), "/agenda/cita?id=$c", '/caja', "/caja/cita?id=$c",
            '/admin/finanzas', '/admin/clientes', "/admin/clientes/ficha?id=$cli", '/admin/clientes/nuevo', '/admin/reportes', '/admin/inventario', '/admin/ajustes', '/admin/equipo',
            '/servicios', '/profesionales', '/admin/crecimiento', '/perfil', '/ruta-que-no-existe', '/agenda/cita?id=99999999', '/caja/cita?id=99999999'];
    }

    // ================================================================== P1 / P2 · escáneres

    /** Req P1: ninguna pantalla del equipo (ni sus páginas de error 403/404) enlaza a rutas EXCLUSIVAS del cliente, para ningún rol. */
    public function test_P1_escaner_de_enlaces_pantallas_del_equipo_sin_rutas_del_cliente(): void
    {
        $malas = []; $resumen = [];
        foreach (['dueno', 'gerente', 'recepcion', 'finanzas', 'proA'] as $rol) {
            $n = $this->eq($rol);
            foreach ($this->paginasEquipo() as $ruta) {
                $r = $n->get($ruta);
                $resumen[$rol][$ruta] = $r['status'];
                if ($r['status'] === 302) { continue; }
                foreach (self::enlacesCruzados($r['body'], 'equipo') as $d) { $malas[] = "$rol GET $ruta ({$r['status']}) → enlace/acción a «{$d}»"; }
                if (str_contains($r['body'], 'Reservar una cita')) { $malas[] = "$rol GET $ruta ({$r['status']}) → contiene «Reservar una cita»"; }
            }
        }
        $this->sinFallos($malas, "enlaces al portal del cliente en pantallas del equipo:");
    }

    /** Req P2: ninguna pantalla del cliente (ni sus 403/404) enlaza a rutas exclusivas del equipo. */
    public function test_P2_escaner_de_enlaces_pantallas_del_cliente_sin_rutas_del_equipo(): void
    {
        $n = self::entrar(self::$base, self::$e['cliente'], 'cliente');
        $malas = [];
        foreach (['/cita', '/mis-citas', '/mi-bienestar', '/perfil', '/agenda', '/agenda/cita?id=' . self::$citaCompletada, '/caja', '/caja/cita?id=' . self::$citaCompletada, '/admin', '/admin/finanzas',
            '/admin/clientes', '/admin/clientes/exportar', '/admin/reportes', '/ruta-que-no-existe'] as $ruta) {
            $r = $n->get($ruta);
            if ($r['status'] === 302) { continue; }
            foreach (self::enlacesCruzados($r['body'], 'cliente') as $d) { $malas[] = "cliente GET $ruta ({$r['status']}) → enlace a «{$d}»"; }
        }
        $this->sinFallos($malas, "enlaces del portal del equipo en pantallas del cliente:");
    }

    // ================================================================== P3

    /**
     * Req P3 (contrato §4): una sesión de equipo que abre una pantalla EXCLUSIVA del cliente (/mis-citas, /cita, /mi-bienestar, /api/mis-citas) es
     * redirigida al inicio de su portal con aviso; nunca se le muestra «Reservar una cita» ni datos de cliente.
     */
    public function test_P3_sesion_de_equipo_en_pantallas_del_cliente_vuelve_al_inicio_del_equipo_con_aviso(): void
    {
        $fallos = [];
        foreach (['dueno', 'gerente', 'recepcion', 'finanzas', 'proA'] as $rol) {
            $n = $this->eq($rol);
            foreach (['/mis-citas', '/cita', '/mi-bienestar'] as $ruta) {
                $r = $n->get($ruta);
                if (!in_array($r['status'], [302, 303], true)) { $fallos[] = "$rol GET $ruta → {$r['status']} (debía redirigir al inicio del equipo)" . (str_contains($r['body'], 'Reservar una cita') ? ' y MUESTRA «Reservar una cita»' : ''); continue; }
                $loc = self::ubicacion($r);
                if (self::pathDe($loc) !== self::INICIO[$rol]) { $fallos[] = "$rol GET $ruta → Location $loc (esperado " . self::INICIO[$rol] . ')'; continue; }
                $fin = $n->get($loc);
                if ($fin['status'] !== 200) { $fallos[] = "$rol destino $loc → {$fin['status']}"; continue; }
                if (!preg_match('~class="alerta[^"]*"|role="(alert|status)"~', $fin['body'])) { $fallos[] = "$rol destino $loc sin aviso visible"; }
            }
            $api = $n->get('/api/mis-citas', ['Accept: application/json']);
            if (!in_array($api['status'], [302, 303, 403], true) || str_contains($api['body'], '"citas"')) { $fallos[] = "$rol GET /api/mis-citas → {$api['status']}: " . substr($api['body'], 0, 120); }
        }
        $this->sinFallos($fallos);
    }

    // ================================================================== P4

    /**
     * Req P4: una persona que es cliente Y propietario/recepción/gerente usa cada portal con SUS permisos: en el portal del equipo ve la agenda y
     * no las pantallas de cliente; en el portal de clientes ve /cita y /mis-citas (con SUS reservas) pero NO porta ningún privilegio laboral.
     */
    public function test_P4_doble_pertenencia_cada_portal_con_sus_propios_permisos(): void
    {
        $propia = self::crearCita(['cliente' => self::$e['dueno']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::gye('+2 days')->format('Y-m-d'), 'hora' => '16:00:00']);
        foreach (['dueno' => '/admin', 'dobleRecepcion' => '/admin', 'dobleGerente' => '/admin'] as $quien => $inicio) {
            $persona = self::$e[$quien];
            $eq = self::entrar(self::$base, $persona, 'equipo');
            $this->assertSame(200, $eq->get('/agenda')['status'], "$quien: /agenda en el portal del equipo");
            $r = $eq->get('/mis-citas');
            $this->assertContains($r['status'], [302, 303], "$quien: /mis-citas con sesión de equipo debe redirigir (no mostrar la pantalla de cliente)");
            $this->assertSame($inicio, self::pathDe(self::ubicacion($r)), "$quien: destino de /mis-citas");
            $cl = self::entrar(self::$base, $persona, 'cliente');
            $this->assertSame(200, $cl->get('/cita')['status'], "$quien: /cita en el portal del cliente");
            $mc = $cl->get('/mis-citas');
            $this->assertSame(200, $mc['status']);
            if ($quien === 'dueno') { $this->assertStringContainsString((string)$propia, $mc['body'], 'el propietario como cliente debe ver su propia reserva'); }
            foreach (['/agenda', '/caja', '/admin', '/admin/finanzas', '/admin/clientes', '/admin/reportes', '/admin/equipo'] as $ruta) {
                $g = $cl->get($ruta);
                $this->assertSame(403, $g['status'], "$quien (sesión de CLIENTE) GET $ruta debe ser 403, fue {$g['status']}");
                $this->assertContains('/cita', array_map([self::class, 'pathDe'], self::destinos($g['body'])), "$quien: el 403 de $ruta debe ofrecer «Volver» a /cita");
                $this->assertSame([], self::enlacesCruzados($g['body'], 'cliente'), "$quien: el 403 de $ruta enlaza al portal del equipo");
            }
            // Exportación directa como cliente: 403 y auditoría de la denegación.
            $antes = (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE resultado='denegada'");
            $x = $cl->get('/admin/clientes/exportar');
            $this->assertSame(403, $x['status'], "$quien: exportación directa desde el portal del cliente");
            $this->assertStringNotContainsString('@example.test', $x['body'], 'la denegación no debe filtrar datos');
            $this->assertGreaterThan($antes, (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE resultado='denegada'"), "$quien: la denegación debe quedar auditada");
        }
    }

    // ================================================================== P5

    /** Req P5-a: membresía de CLIENTE suspendida: el portal de clientes la rechaza (403) sin abrir sesión; el portal del equipo sigue funcionando (membresías independientes). */
    public function test_P5_membresia_cliente_suspendida_bloquea_solo_el_portal_de_clientes(): void
    {
        $p = self::personaDoble(self::$e['dueno']['id'], 'recepcion', 'suspendido');
        self::q('TRUNCATE intentos_login');
        $cl = new Navegador(self::$base);
        $r = $cl->login('cliente', $p['email'], $p['password']);
        $this->assertSame(403, $r['status'], 'login de cliente con membresía suspendida: ' . $r['status']);
        $this->assertStringContainsString('suspendid', self::texto($r['body']));
        $this->assertContains($cl->get('/cita')['status'], [302, 303], 'sin sesión de cliente: /cita debe redirigir al acceso');
        $eq = self::entrar(self::$base, $p, 'equipo');
        $this->assertSame(200, $eq->get('/agenda')['status'], 'el acceso laboral no depende de la membresía de cliente');
    }

    /** Req P5-b: suspender la membresía de cliente EN CALIENTE expulsa la sesión de cliente (siguiente petición) y no toca la sesión de equipo de la misma persona. */
    public function test_P5_suspension_en_caliente_de_la_membresia_cliente(): void
    {
        $p = self::personaDoble(self::$e['dueno']['id'], 'gerente');
        $eq = self::entrar(self::$base, $p, 'equipo');
        $cl = self::entrar(self::$base, $p, 'cliente');
        $this->assertSame(200, $cl->get('/cita')['status']);
        self::q("UPDATE clientes SET estado='suspendido' WHERE usuario_id=?", [$p['id']]);
        $r = $cl->get('/cita');
        $this->assertContains($r['status'], [302, 303, 403], 'la sesión de cliente suspendida no debe seguir viendo /cita (fue ' . $r['status'] . ')');
        $this->assertNotSame(200, $r['status']);
        $this->assertSame(200, $eq->get('/agenda')['status'], 'la sesión de equipo de la misma persona no se ve afectada');
        $this->assertSame(200, $eq->get('/admin/clientes')['status']);
    }

    /** Req P5-c: acceso LABORAL suspendido (colaborador/acceso inactivo): el portal del equipo lo rechaza; su membresía de cliente activa sigue sirviendo. */
    public function test_P5_acceso_laboral_suspendido_bloquea_solo_el_portal_del_equipo(): void
    {
        $p = self::personaDoble(self::$e['dueno']['id'], 'recepcion');
        $eq = self::entrar(self::$base, $p, 'equipo');
        $this->assertSame(200, $eq->get('/agenda')['status']);
        self::q('UPDATE usuario_accesos SET activo=0 WHERE usuario_id=?', [$p['id']]);
        $r = $eq->get('/agenda');
        $this->assertContains($r['status'], [302, 303, 401, 403], 'con el acceso laboral suspendido /agenda no puede ser 200 (fue ' . $r['status'] . ')');
        if (in_array($r['status'], [302, 303], true)) { $this->assertSame('/equipo/login', self::pathDe(self::ubicacion($r)), 'una sesión de equipo vencida vuelve al acceso DEL EQUIPO'); }
        self::q('TRUNCATE intentos_login');
        $nuevo = new Navegador(self::$base);
        $l = $nuevo->login('equipo', $p['email'], $p['password']);
        $this->assertNotSame(302, $l['status'], 'el login de equipo con acceso suspendido no debe abrir sesión');
        $this->assertContains($nuevo->get('/agenda')['status'], [302, 303, 401, 403]);
        $cl = self::entrar(self::$base, $p, 'cliente');
        $this->assertSame(200, $cl->get('/cita')['status'], 'la membresía de cliente sigue activa');
    }

    // ================================================================== P6

    /**
     * Req P6: sesión expirada (archivo de sesión borrado). En una pantalla del equipo: redirige a un acceso; en una ACCIÓN (estado, cobro) no cambia nada ni da 5xx.
     */
    public function test_P6_sesion_expirada_en_pantallas_y_acciones_del_equipo(): void
    {
        $n = $this->eq('dueno');
        $this->assertSame(200, $n->get('/agenda')['status']);
        $csrf = Navegador::csrfDe($n->get('/agenda')['body']) ?? '';
        $this->assertNotSame('', $csrf);
        $c = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::slotComenzado()[0], 'hora' => self::slotComenzado()[1]]);
        @unlink(self::archivoSesionQ5((string)$n->cookieSesion(), 'portales'));
        foreach (['/agenda', '/caja', '/admin/finanzas', '/admin/clientes'] as $ruta) {
            $r = $n->get($ruta);
            $this->assertContains($r['status'], [302, 303, 401], "sesión expirada GET $ruta → {$r['status']}");
        }
        $r = $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $c, 'estado' => 'en_servicio']);
        $this->assertLessThan(500, $r['status']);
        $this->assertSame('confirmada', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [$c]), 'una acción con sesión expirada no debe cambiar el estado');
        $r2 = $n->post('/caja/cita/cobrar', ['csrf_token' => $csrf, 'id' => self::$citaCompletada, 'cita_id' => self::$citaCompletada, 'monto' => '5.00', 'metodo' => 'efectivo']);
        $this->assertLessThan(500, $r2['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [self::$citaCompletada]), 'un cobro con sesión expirada no debe registrarse');
    }

    /**
     * Req P6/UX: la sesión que se invalida en el servidor (versión de sesión: cambio de contraseña/rol) devuelve al acceso del EQUIPO con aviso
     * («Tu sesión terminó o cambió tu acceso»), no al acceso del cliente.
     */
    public function test_P6_sesion_invalidada_vuelve_al_login_del_equipo_con_aviso(): void
    {
        $n = $this->eq('recepcion');
        $this->assertSame(200, $n->get('/agenda')['status']);
        self::q('UPDATE usuarios SET version_sesion=version_sesion+1 WHERE id=?', [self::$e['recepcion']['id']]);
        $r = $n->get('/agenda');
        $this->assertContains($r['status'], [302, 303]);
        $this->assertSame('/equipo/login', self::pathDe(self::ubicacion($r)), 'debe volver al acceso del equipo');
        $login = $n->get('/equipo/login');
        $this->assertStringContainsString('sesión terminó', self::texto($login['body']), 'falta el aviso de sesión terminada');
        $this->assertStringNotContainsString('Reservar', $login['body']);
    }

    /** Req P6/UX: un visitante anónimo que abre una URL del equipo cae en el acceso del EQUIPO (no en el de clientes); una del cliente, en el de clientes. */
    public function test_P6_anonimo_en_url_del_equipo_va_al_acceso_del_equipo(): void
    {
        $fallos = [];
        foreach (['/agenda', '/agenda/cita?id=1', '/caja', '/caja/cita?id=1', '/admin/finanzas', '/admin/clientes'] as $ruta) {
            $r = (new Navegador(self::$base))->get($ruta);
            $loc = self::pathDe(self::ubicacion($r));
            if (!in_array($r['status'], [302, 303], true) || $loc !== '/equipo/login') { $fallos[] = "anónimo GET $ruta → {$r['status']} Location «{$loc}» (esperado /equipo/login)"; }
        }
        foreach (['/cita', '/mis-citas'] as $ruta) {
            $r = (new Navegador(self::$base))->get($ruta);
            $loc = self::pathDe(self::ubicacion($r));
            if (!in_array($r['status'], [302, 303], true) || !in_array($loc, ['/', '/cliente/login'], true)) { $fallos[] = "anónimo GET $ruta → {$r['status']} Location «{$loc}» (esperado / o /cliente/login)"; }
        }
        $this->sinFallos($fallos);
    }

    // ================================================================== P7

    /**
     * Req P7: el rol cambia EN CALIENTE (sin reiniciar sesión): gerente → recepción pierde finanzas.gestionar al instante en pantallas y en acciones
     * forjadas; recepción → profesional pierde la gestión de la agenda; un rol convertido en «cliente» expulsa la sesión de equipo.
     */
    public function test_P7_rol_cambiado_en_caliente_se_aplica_en_la_siguiente_peticion(): void
    {
        $g = self::miembro(self::$e['dueno']['id'], 'gerente', ['nombre' => 'Caliente']);
        $n = self::entrar(self::$base, $g, 'equipo');
        $pag = $n->get('/agenda');
        $this->assertSame(200, $pag['status']);
        $this->assertSame(200, $n->get('/caja')['status'], 'el gerente accede a /caja');
        $c = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::slotComenzado()[0], 'hora' => self::slotComenzado()[1], 'estado' => 'completada']);
        $csrf = Navegador::csrfDe($pag['body']);
        // → recepción
        self::q("UPDATE usuario_accesos SET rol='recepcion' WHERE usuario_id=?", [$g['id']]);
        $r = $n->get('/caja');
        $this->assertSame(403, $r['status'], 'tras bajar a recepción /caja debe ser 403 (fue ' . $r['status'] . ')');
        $this->assertSame(403, $n->get('/admin/finanzas')['status']);
        $this->assertSame(200, $n->get('/agenda')['status'], 'recepción conserva la agenda');
        $f = $n->post('/caja/cita/cobrar', ['csrf_token' => $csrf, 'id' => $c, 'cita_id' => $c, 'monto' => '20.00', 'metodo' => 'efectivo', 'recibido' => '20.00', 'idempotencia' => bin2hex(random_bytes(8))]);
        $this->assertSame(403, $f['status'], 'cobro forjado tras perder finanzas.gestionar: ' . $f['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [$c]));
        $this->assertSame([], self::enlacesCruzados($f['body'], 'equipo'));
        // → profesional (sin gestión de agenda)
        self::q("UPDATE usuario_accesos SET rol='profesional' WHERE usuario_id=?", [$g['id']]);
        $otra = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proB']['profesional_id'], 'fecha' => self::slotComenzado()[0], 'hora' => self::slotComenzado()[1]]);
        $x = $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $otra, 'estado' => 'en_servicio']);
        $this->assertSame(403, $x['status'], 'un profesional sin vínculo no puede mover citas ajenas');
        $this->assertSame('confirmada', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [$otra]));
        // → cliente: la sesión de equipo deja de ser válida
        self::q("UPDATE usuario_accesos SET rol='cliente' WHERE usuario_id=?", [$g['id']]);
        $y = $n->get('/agenda');
        $this->assertNotSame(200, $y['status'], 'una sesión de equipo cuyo rol pasó a «cliente» no puede ver la agenda');
    }

    // ================================================================== P8

    /**
     * Req P8: cada error (403, 404, 409, 422) originado en una ACCIÓN del equipo se muestra como página completa, con el «Volver al inicio» del
     * PORTAL del rol (no /perfil) y sin enlaces del cliente. (500/503 en P8b.)
     */
    public function test_P8_errores_403_404_409_422_de_acciones_del_equipo(): void
    {
        $dueno = $this->eq('dueno');
        $rec = $this->eq('recepcion');
        [$f, $h] = self::slotComenzado();
        $donante = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h]);
        $terminal = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h, 'estado' => 'completada']);
        [$ff, $fh] = self::slotFuturo();
        $futura = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $ff, 'hora' => $fh]);
        $csrfD = Navegador::csrfDe($dueno->get('/agenda')['body']);
        $csrfR = Navegador::csrfDe($rec->get('/agenda')['body']);
        $casos = [
            ['403 recepción a caja', 403, $rec->get('/caja'), '/admin'],
            ['403 recepción cobra', 403, $rec->post('/caja/cita/cobrar', ['csrf_token' => $csrfR, 'id' => $terminal, 'cita_id' => $terminal, 'monto' => '5.00', 'metodo' => 'efectivo']), '/admin'],
            ['404 cita inexistente', 404, $dueno->get('/agenda/cita?id=99999999'), '/admin'],
            ['409 transición inválida', 409, $dueno->post('/agenda/estado', ['csrf_token' => $csrfD, 'id' => $terminal, 'estado' => 'en_servicio']), '/admin'],
            ['422 inicio futuro', 422, $dueno->post('/agenda/estado', ['csrf_token' => $csrfD, 'id' => $futura, 'estado' => 'en_servicio']), '/admin'],
            ['422 período inválido', 422, $dueno->get('/agenda?desde=2026-02-30&hasta=x'), '/admin'],
        ];
        $fallos = [];
        foreach ($casos as [$nombre, $esperado, $r, $inicio]) {
            if ($r['status'] !== $esperado) { $fallos[] = "$nombre: estado {$r['status']} (esperado $esperado)"; continue; }
            if (!self::esPaginaCompleta($r['body'])) { $fallos[] = "$nombre: no es una página completa"; }
            foreach (self::enlacesCruzados($r['body'], 'equipo') as $d) { $fallos[] = "$nombre: enlaza al portal del cliente («{$d}»)"; }
            $home = $this->homeDeError($r['body']);
            if ($home !== '' && $home !== $inicio) { $fallos[] = "$nombre: «Volver al inicio» apunta a $home (esperado $inicio)"; }
            if (str_contains($r['body'], 'Reservar una cita')) { $fallos[] = "$nombre: muestra «Reservar una cita»"; }
        }
        $this->sinFallos($fallos);
    }

    /** Req P8: errores originados en acciones del CLIENTE (403 al intentar rutas del equipo, 404, 422 al reprogramar una cita ya comenzada) vuelven al portal del cliente. */
    public function test_P8_errores_de_acciones_del_cliente_vuelven_a_su_portal(): void
    {
        $cl = self::entrar(self::$base, self::$e['cliente'], 'cliente');
        [$f, $h] = self::slotComenzado();
        $mia = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h]);
        $ajena = self::crearCita(['cliente' => self::$e['cliente2']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h]);
        $csrf = Navegador::csrfDe($cl->get('/cita')['body']) ?? $cl->csrf('/cita');
        $casos = [
            ['403 agenda', 403, $cl->get('/agenda')],
            ['403 estado', 403, $cl->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $mia, 'estado' => 'en_servicio'])],
            ['422/409 reprogramar cita ya comenzada', null, $cl->post('/agenda/reprogramar', ['csrf_token' => $csrf, 'id' => $mia, 'fecha' => self::gye('+3 days')->format('Y-m-d'), 'hora' => '11:00', 'dia' => (int)self::gye('+3 days')->format('N')])],
            ['403/404 reprogramar cita ajena', null, $cl->post('/agenda/reprogramar', ['csrf_token' => $csrf, 'id' => $ajena, 'fecha' => self::gye('+3 days')->format('Y-m-d'), 'hora' => '11:00', 'dia' => (int)self::gye('+3 days')->format('N')])],
            ['404 ruta', 404, $cl->get('/ruta-que-no-existe')],
        ];
        $fallos = [];
        foreach ($casos as [$nombre, $esperado, $r]) {
            if ($esperado !== null && $r['status'] !== $esperado) { $fallos[] = "$nombre: estado {$r['status']} (esperado $esperado)"; }
            if ($r['status'] < 400) { $fallos[] = "$nombre: debió ser un error y fue {$r['status']}"; continue; }
            foreach (self::enlacesCruzados($r['body'], 'cliente') as $d) { $fallos[] = "$nombre: enlaza al portal del equipo («{$d}»)"; }
            $home = $this->homeDeError($r['body']);
            if ($home !== '' && !in_array($home, ['/cita'], true)) { $fallos[] = "$nombre: «Volver al inicio» apunta a $home (esperado /cita)"; }
        }
        $this->assertSame('confirmada', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [$mia]));
        $this->sinFallos($fallos);
    }

    /** Req P8: errores de reprogramación hechos por el EQUIPO vuelven a la agenda (no a /mis-citas). */
    public function test_P8_reprogramar_desde_la_agenda_con_error_vuelve_a_la_agenda(): void
    {
        $n = $this->eq('recepcion');
        [$f, $h] = self::slotComenzado();
        $c = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => $f, 'hora' => $h]);
        $pag = $n->get('/agenda?desde=' . $f . '&hasta=' . $f);
        $csrf = Navegador::csrfDe($pag['body']);
        $r = $n->post('/agenda/reprogramar', ['csrf_token' => $csrf, 'id' => $c, 'fecha' => self::gye('+3 days')->format('Y-m-d'), 'hora' => '11:00', 'dia' => (int)self::gye('+3 days')->format('N'), 'volver' => 'agenda', 'r_desde' => $f, 'r_hasta' => $f]);
        $this->assertGreaterThanOrEqual(400, $r['status']);
        $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'), 'reprogramar con error enlaza al portal del cliente');
        $this->assertNotEmpty(array_filter(self::destinos($r['body']), fn($d) => self::pathDe($d) === '/agenda'), 'sin «Volver» a la agenda');
    }

    /**
     * Req P8: 503 por indisponibilidad técnica (tabla de membresías inaccesible) en una pantalla del equipo: página 503 completa, sin enlaces
     * del cliente, sin filtrar SQL, y la SESIÓN SIGUE VIGENTE al restaurar el servicio (un fallo técnico no es «sesión expirada»).
     */
    public function test_P8_503_tecnico_no_expulsa_la_sesion_ni_mezcla_portales(): void
    {
        $n = $this->eq('dueno');
        $this->assertSame(200, $n->get('/agenda')['status']);
        self::q('RENAME TABLE clientes TO clientes_q5_bak');
        try {
            $r = $n->get('/agenda');
        } finally {
            self::q('RENAME TABLE clientes_q5_bak TO clientes');
        }
        $this->assertSame(503, $r['status'], 'estado ante el fallo técnico: ' . $r['status']);
        $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'), 'el 503 enlaza al portal del cliente');
        $this->assertStringNotContainsString('SQLSTATE', $r['body']);
        $this->assertStringNotContainsString('clientes_q5_bak', $r['body']);
        $this->assertSame(200, $n->get('/agenda')['status'], 'tras restaurar, la sesión de equipo debe seguir vigente');
    }

    // ================================================================== P9

    /** Req P9 (contrato §6): matriz ruta × rol. Quién ve cada pantalla de agenda y caja; el resto recibe 403 limpio; anónimos, redirección al acceso del equipo. */
    public function test_P9_matriz_de_rutas_y_permisos_por_rol(): void
    {
        $c = self::$citaCompletada;
        $matriz = [
            '/agenda' => ['dueno' => 200, 'gerente' => 200, 'recepcion' => 200, 'proA' => 200, 'finanzas' => 403],
            "/agenda/cita?id=$c" => ['dueno' => 200, 'gerente' => 200, 'recepcion' => 200, 'proA' => 200, 'proB' => 403, 'finanzas' => 403],
            '/caja' => ['dueno' => 200, 'gerente' => 200, 'finanzas' => 200, 'recepcion' => 403, 'proA' => 403],
            "/caja/cita?id=$c" => ['dueno' => 200, 'gerente' => 200, 'finanzas' => 200, 'recepcion' => 403, 'proA' => 403],
            '/caja/exportar?formato=csv' => ['dueno' => 200, 'gerente' => 200, 'finanzas' => 200, 'recepcion' => 403, 'proA' => 403],
            '/admin/finanzas' => ['dueno' => 200, 'gerente' => 200, 'finanzas' => 200, 'recepcion' => 403, 'proA' => 403],
        ];
        $fallos = [];
        foreach (['dueno', 'gerente', 'recepcion', 'finanzas', 'proA', 'proB'] as $rol) {
            $n = $this->eq($rol);
            foreach ($matriz as $ruta => $roles) {
                if (!isset($roles[$rol])) { continue; }
                $r = $n->get($ruta);
                $ok = $roles[$rol] === 403 && $ruta === "/agenda/cita?id=$c" && $rol === 'proB' ? in_array($r['status'], [403, 404], true) : $r['status'] === $roles[$rol];
                if (!$ok) { $fallos[] = "$rol GET $ruta → {$r['status']} (esperado {$roles[$rol]})"; }
                if ($r['status'] === 403 && self::enlacesCruzados($r['body'], 'equipo')) { $fallos[] = "$rol GET $ruta → 403 con enlaces al portal del cliente"; }
            }
        }
        foreach (array_keys($matriz) as $ruta) {
            $r = (new Navegador(self::$base))->get($ruta);
            if (!in_array($r['status'], [302, 303, 401], true)) { $fallos[] = "anónimo GET $ruta → {$r['status']} (esperado redirección al acceso)"; }
        }
        $this->sinFallos($fallos);
    }
}
