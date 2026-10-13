<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

use Classes\ServiceUnavailableException;
use Services\AuthorizationService;

/** B/G. Cabeceras de caché de páginas sensibles y comportamiento de AuthorizationService ante fallos técnicos. */
final class CabecerasYDisponibilidadTest extends QaCase
{
    private static string $base;
    private static array $dueno;
    private static string $token;
    private static int $col;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor();
        self::$dueno = self::propietario('dueno@example.test');
        $r = (new \Repositories\ColaboradorRepository(self::db()))->crearInvitacion(self::$dueno['id'], ['nombre' => 'In', 'apellido' => 'Vi', 'email' => 'inv@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        self::$token = self::tokenDeInvitacion($r['invitacion_id']);
        self::$col = $r['colaborador_id'];
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); }

    public function test_paginas_con_datos_laborales_o_tokens_envian_cache_control_no_store(): void
    {
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('equipo', self::$dueno['email'], self::$dueno['password'])['status']);
        $sinNoStore = [];
        foreach (['/admin/equipo', '/admin/equipo/ficha?id=' . self::$col, '/equipo/aceptar?token=' . self::$token, '/admin/clientes', '/perfil'] as $ruta) {
            $r = $n->get($ruta);
            $cc = strtolower(implode(',', $r['headers']['cache-control'] ?? []));
            $pragma = strtolower(implode(',', $r['headers']['pragma'] ?? []));
            if (!str_contains($cc, 'no-store')) { $sinNoStore[] = "$ruta [{$r['status']}] Cache-Control='$cc'"; }
        }
        $this->assertSame([], $sinNoStore, 'estas páginas muestran notas de RR. HH., datos de personal o un token en la URL y deberían marcarse no-store (el CRM sí lo hace)');
    }

    public function test_cabeceras_de_seguridad_presentes_en_las_rutas_nuevas(): void
    {
        $n = new Navegador(self::$base);
        foreach (['/cliente/login', '/equipo/login', '/cliente/registro', '/equipo/aceptar?token=' . self::$token, '/equipo/aceptar?token=zz', '/nada'] as $ruta) {
            $r = $n->get($ruta);
            $this->assertSame('nosniff', $r['headers']['x-content-type-options'][0] ?? null, $ruta);
            $this->assertSame('SAMEORIGIN', $r['headers']['x-frame-options'][0] ?? null, $ruta);
            $this->assertStringContainsString('strict-origin', $r['headers']['referrer-policy'][0] ?? '', "$ruta: el token de invitación va en la URL; la política de referer no debe filtrarlo");
        }
    }

    public function test_metodos_no_permitidos_y_head_en_rutas_de_mutacion(): void
    {
        $n = new Navegador(self::$base);
        foreach (['/admin/equipo/crear', '/admin/equipo/actualizar', '/admin/equipo/invitar', '/admin/equipo/revocar'] as $ruta) {
            $this->assertSame(405, $n->get($ruta)['status'], "GET $ruta");
            $this->assertContains($n->peticion('PUT', $ruta, 'x=1')['status'], [404, 405]);
            $this->assertContains($n->peticion('DELETE', $ruta)['status'], [404, 405]);
        }
        foreach (['/admin/clientes', '/admin/clientes/ficha', '/admin/clientes/exportar'] as $ruta) {
            $this->assertSame(405, $n->post($ruta, ['x' => 1])['status'], "POST $ruta (el CRM es solo lectura)");
        }
    }

    public function test_authorizationservice_ante_conexion_cerrada_o_nula_lanza_503_nunca_null(): void
    {
        $db = self::admin();
        $db->select_db(self::nombreBd());
        $db->close();
        $svc = new AuthorizationService($db);
        try { $svc->contexto(1); $this->fail('Una conexión cerrada se trató como cuenta inexistente/inactiva'); }
        catch (ServiceUnavailableException) { $this->addToAssertionCount(1); }
        try { $svc->permite(1, 'equipo.gestionar'); $this->fail('permite() devolvió un valor sin poder verificar'); }
        catch (ServiceUnavailableException) { $this->addToAssertionCount(1); }
        $this->assertNull((new AuthorizationService(self::db()))->contexto(987654321), 'cuenta inexistente = null (único caso)');
        $this->assertNull((new AuthorizationService(self::db()))->contexto(-5));
    }

    public function test_authorizationservice_ante_tabla_ausente_lanza_503_con_pista_en_el_registro_y_sin_filtrar_sql(): void
    {
        $nombre = 'appsalon_qa_authz_test';
        self::crearBd($nombre, 9); // sin usuario_accesos/clientes/colaboradores
        $a = self::admin(); $a->select_db($nombre);
        $log = tempnam(sys_get_temp_dir(), 'qa_el_');
        $anterior = ini_set('error_log', $log);
        try {
            (new AuthorizationService($a))->contexto(1);
            $this->fail('esquema sin migrar tratado como "sin cuenta"');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringNotContainsString('usuario_accesos', $e->getMessage());
            $this->assertStringNotContainsString('SELECT', $e->getMessage());
        } finally { ini_set('error_log', $anterior ?: ''); }
        $l = (string)file_get_contents($log); @unlink($log);
        $this->assertStringContainsString('ESQUEMA DESACTUALIZADO', $l);
        $this->assertStringContainsString('migrador.php up', $l);
        $this->assertStringNotContainsString('SELECT', $l, 'el registro no debe contener consultas SQL');
        $a->close();
        self::borrarBd($nombre);
    }

    public function test_cuenta_borrada_con_sesion_abierta_cierra_la_sesion_y_conserva_la_ficha_laboral(): void
    {
        $m = self::miembro(self::$dueno['id'], 'recepcion', ['email' => 'borrada@example.test']);
        $n = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('equipo', $m['email'], $m['password'])['status']);
        $this->assertSame(200, $n->get('/admin')['status']);
        self::q('DELETE FROM usuarios WHERE id=?', [$m['id']]);
        $r = $n->get('/admin');
        $this->assertSame(302, $r['status']);
        $c = self::q('SELECT usuario_id, estado FROM colaboradores WHERE id=?', [$m['colaborador_id']])[0];
        $this->assertNull($c['usuario_id'], 'ON DELETE SET NULL');
        // La ficha huérfana en estado activo sin usuario no concede acceso ni rompe el listado.
        $this->assertSame(200, (function () { $o = new Navegador(self::$base); self::q('TRUNCATE intentos_login'); $o->login('equipo', self::$dueno['email'], self::$dueno['password']); return $o->get('/admin/equipo')['status']; })());
    }
}
