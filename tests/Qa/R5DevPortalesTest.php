<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5DevPortalesCase.php';

/**
 * Ronda 5 · n5 — portales, permisos y navegación, contra HTTP real (puertos 8170-8179).
 * Parte de la reproducción de R5DiagnosticoTest (fallo B) y la amplía a toda la app.
 */
final class R5DevPortalesTest extends R5DevPortalesCase
{
    private static string $base;
    private static array $dueno;
    private static array $roles = [];
    private static array $cliente;

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
    }

    public static function tearDownAfterClass(): void { self::pararServidorPropio(); }

    public function test_repro_B_mis_citas_en_sesion_de_equipo(): void
    {
        $n = self::entrar(self::$base, 'equipo', self::$dueno);
        $r = $n->get('/mis-citas');
        fwrite(STDERR, "\n[B] status={$r['status']} location=" . ($r['location'] ?? '-') . ' reservar=' . (int)str_contains($r['body'], 'Reservar una cita') . "\n");
        $this->assertTrue(true);
    }

    public function test_repro_error_de_equipo_calcula_inicio(): void
    {
        $n = self::entrar(self::$base, 'equipo', self::$roles['profesional']);
        $r = $n->get('/admin/finanzas');
        preg_match_all('~href="([^"]+)"~', $r['body'], $m);
        fwrite(STDERR, "\n[403-prof] status={$r['status']} links=" . implode(',', array_unique($m[1])) . "\n");
        $n2 = self::entrar(self::$base, 'equipo', self::$roles['finanzas']);
        $r = $n2->get('/admin/equipo');
        preg_match_all('~href="([^"]+)"~', $r['body'], $m);
        fwrite(STDERR, "[403-fin] status={$r['status']} links=" . implode(',', array_unique($m[1])) . "\n");
        $nc = self::entrar(self::$base, 'cliente', self::$cliente);
        foreach (['/agenda', '/admin', '/admin/finanzas', '/api/mis-citas', '/servicios'] as $ruta) {
            $r = $nc->get($ruta);
            preg_match_all('~href="([^"]+)"~', $r['body'], $m);
            fwrite(STDERR, "[cli $ruta] status={$r['status']} loc=" . ($r['location'] ?? '-') . ' links=' . implode(',', array_unique($m[1])) . "\n");
        }
        $this->assertTrue(true);
    }
}
