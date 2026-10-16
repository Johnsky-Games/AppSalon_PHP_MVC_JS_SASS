<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Ronda 5 — destino REAL (HTTP) de los enlaces que llegan por correo, según portal, permisos y estado de sesión.
 * Escrita por el integrador (no es revisión independiente): complementa tests/Integration/R5EnlacesCorreoTest.php, que audita el CONTENIDO de los correos.
 */
final class R5IntEnlacesCorreoHttpTest extends QaCase
{
    private static string $base;
    private static array $dueno;
    private static array $cliente;
    private static array $gerente;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor([], 'r5enl');
        self::$dueno = self::propietario(self::correo('dueno'));
        self::$gerente = self::miembro(self::$dueno['id'], 'gerente');
        self::$cliente = self::cliente(['email' => self::correo('clienta')]);
    }

    public static function tearDownAfterClass(): void { self::pararServidor('r5enl'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function sesion(string $portal, array $p): Navegador
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login($portal, $p['email'], $p['password'])['status']);
        return $n;
    }

    /** Enlace de recordatorio/confirmación de cita: /cita (portal del cliente). */
    public function test_enlace_de_notificacion_de_cita_segun_quien_lo_abre(): void
    {
        $r = (new Navegador(self::$base))->get('/cita');
        $this->assertSame([302, '/'], [$r['status'], $r['location']], 'anónimo → acceso de clientes');
        $this->assertSame(200, $this->sesion('cliente', self::$cliente)->get('/cita')['status'], 'cliente con sesión → reserva');
        $e = $this->sesion('equipo', self::$gerente)->get('/cita');
        $this->assertSame([302, '/admin'], [$e['status'], $e['location']], 'equipo → su inicio, nunca la reserva del cliente');
    }

    public function test_sesion_vencida_o_invalidada_vuelve_al_acceso_de_su_portal(): void
    {
        $c = $this->sesion('cliente', self::$cliente);
        $g = $this->sesion('equipo', self::$gerente);
        self::q('UPDATE usuarios SET version_sesion=version_sesion+1 WHERE id IN (?,?)', [self::$cliente['id'], self::$gerente['id']]);
        $rc = $c->get('/cita');
        $this->assertSame([302, '/'], [$rc['status'], $rc['location']], 'cliente con sesión invalidada');
        $rg = $g->get('/agenda');
        $this->assertSame([302, '/equipo/login'], [$rg['status'], $rg['location']], 'equipo con sesión invalidada');
    }

    public function test_membresia_de_cliente_suspendida_no_abre_el_enlace_y_no_afecta_al_equipo(): void
    {
        $dual = self::cliente(['email' => self::correo('dual')]);
        $c = $this->sesion('cliente', $dual);
        self::q("UPDATE clientes SET estado='suspendido' WHERE usuario_id=?", [$dual['id']]);
        $r = $c->get('/cita');
        $this->assertContains($r['status'], [302, 403], 'cliente suspendido: sin acceso a la reserva');
        $this->assertNotSame(200, $r['status']);
        $this->assertSame(200, $this->sesion('equipo', self::$gerente)->get('/agenda')['status'], 'el equipo no se ve afectado');
    }

    /** Correos de invitación/recuperación/confirmación con token inválido: página genérica, sin Location a otro portal ni contenido privado. */
    public function test_enlaces_con_token_invalido_no_filtran_ni_cruzan_portales(): void
    {
        foreach (['/equipo/aceptar?token=' . str_repeat('a', 64), '/recuperar?token=' . str_repeat('b', 64) . '&portal=equipo', '/recuperar?token=' . str_repeat('b', 64), '/confirmar-cuenta?token=' . str_repeat('c', 64)] as $ruta) {
            foreach ([new Navegador(self::$base), $this->sesion('cliente', self::$cliente), $this->sesion('equipo', self::$gerente)] as $i => $n) {
                $r = $n->get($ruta);
                $this->assertNotSame(500, $r['status'], "$ruta (navegador $i)");
                $this->assertStringNotContainsString(self::$gerente['email'], $r['body'], "$ruta no debe revelar cuentas");
                $this->assertStringNotContainsString(self::$cliente['email'], $r['body']);
                if ($r['location'] !== null) {
                    $this->assertDoesNotMatchRegularExpression('~^/(admin|agenda|caja)~', $r['location'], "$ruta redirige a una pantalla privada del equipo");
                }
            }
        }
    }

    /** El portal de la recuperación lo fija el enlace del correo: la pantalla vuelve al acceso correcto. */
    public function test_el_formulario_de_recuperacion_conserva_el_portal_del_enlace(): void
    {
        $n = new Navegador(self::$base);
        $eq = $n->get('/olvide?portal=equipo');
        $this->assertSame(200, $eq['status']);
        $this->assertStringNotContainsString('/cliente/registro', $eq['body'], 'la pantalla del equipo no ofrece el registro de clientes');
    }
}
