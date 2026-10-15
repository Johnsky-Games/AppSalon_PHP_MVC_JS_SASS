<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Ronda 5 — reproducción de los fallos A/B/C observados en la validación funcional, contra HTTP real.
 * Estas pruebas documentan el comportamiento ANTES de la corrección (R5-A1…) y pasan a ser de regresión después.
 */
final class R5DiagnosticoTest extends QaCase
{
    private static string $base;
    private static array $dueno;
    private static array $cliente;
    private static int $pid;
    private static int $svc;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor([], 'r5diag');
        self::$dueno = self::propietario(self::correo('dueno'));
        self::$cliente = self::cliente(['email' => self::correo('clienta'), 'nombre' => 'Clienta', 'apellido' => 'Cinco']);
        self::$svc = self::servicio('Corte R5', 20.0, 30);
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1)', ['Pro R5']);
        self::$pid = (int)self::db()->insert_id;
        self::q('INSERT INTO profesionales_servicios(profesionalId,servicioId) VALUES (?,?)', [self::$pid, self::$svc]);
    }

    public static function tearDownAfterClass(): void { self::pararServidor('r5diag'); }

    private function dueno(): Navegador
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('equipo', self::$dueno['email'], self::$dueno['password'])['status']);
        return $n;
    }

    private static function dia(int $d): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify("$d days")->format('Y-m-d');
    }

    private function cita(string $fecha, string $ini, string $estado = 'confirmada'): int
    {
        $fin = date('H:i:s', strtotime("$ini +30 minutes"));
        self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?,?,?,?,30,?,?,?, 'pendiente')", [$fecha, $ini, $ini, $fin, self::$cliente['id'], self::$pid, $estado]);
        $id = (int)self::db()->insert_id;
        self::q('INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,30)', [$id, self::$svc, 'Corte R5', '20.00']);
        return $id;
    }

    /** Envía el formulario «Iniciar atención» tal como lo renderiza /agenda (campos ocultos incluidos). */
    private function clicIniciar(Navegador $n, int $cita, string $desde, string $hasta): array
    {
        $pag = $n->get("/agenda?desde=$desde&hasta=$hasta");
        $this->assertSame(200, $pag['status']);
        $this->assertMatchesRegularExpression('~<form method="POST" action="/agenda/estado">(?:(?!</form>).)*name="id" value="' . $cita . '"(?:(?!</form>).)*value="en_servicio"~s', $pag['body'], 'la agenda ofrece «Iniciar atención»');
        return $n->post('/agenda/estado', ['csrf_token' => Navegador::csrfDe($pag['body']), 'id' => $cita, 'estado' => 'en_servicio']);
    }

    public function test_A_cita_ya_comenzada_inicia_y_vuelve_a_la_agenda(): void
    {
        $n = $this->dueno();
        $c = $this->cita(self::dia(-1), '10:00:00');
        $r = $this->clicIniciar($n, $c, self::dia(-1), self::dia(1));
        fwrite(STDERR, "\n[A-ok] status={$r['status']} location=" . ($r['location'] ?? '-') . "\n");
        $this->assertSame(302, $r['status']);
        $this->assertSame('en_servicio', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        // Observado antes de la ronda 5: vuelve a /agenda SIN el período consultado.
        $this->assertSame('/agenda', $r['location']);
    }

    public function test_A_B_cita_futura_ofrece_iniciar_y_devuelve_pagina_cruda_con_enlace_al_portal_cliente(): void
    {
        $n = $this->dueno();
        $c = $this->cita(self::dia(1), '15:00:00');
        $r = $this->clicIniciar($n, $c, self::dia(0), self::dia(1));
        fwrite(STDERR, "\n[A/B-futura] status={$r['status']} ct=" . ($r['headers']['content-type'][0] ?? '-') . "\n" . substr(strip_tags($r['body']), 0, 200) . "\nlinks: " . implode(',', array_unique((function () use ($r) { preg_match_all('~href="([^"]+)"~', $r['body'], $m); return $m[1]; })())) . "\n");
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        $this->assertSame(422, $r['status']);
        // Causa raíz B: página sin layout y con enlace fijo al portal de CLIENTES.
        $this->assertStringContainsString('href="/mis-citas"', $r['body']);
        $this->assertStringNotContainsString('<html', strtolower($r['body']));
    }

    public function test_B_mis_citas_en_sesion_de_equipo_muestra_contenido_de_cliente(): void
    {
        $n = $this->dueno();
        $r = $n->get('/mis-citas');
        fwrite(STDERR, "\n[B-mis-citas] status={$r['status']} reservar=" . (int)str_contains($r['body'], 'Reservar una cita') . "\n");
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Reservar una cita', $r['body']);
    }

    public function test_C_no_existe_ruta_de_cobro_desde_la_cita(): void
    {
        $n = $this->dueno();
        $c = $this->cita(self::dia(-1), '11:00:00', 'completada');
        $r = $n->get("/agenda?desde=" . self::dia(-1) . "&hasta=" . self::dia(0));
        $this->assertStringNotContainsString('Cobrar', $r['body']);
        $f = $n->get('/admin/finanzas');
        fwrite(STDERR, "\n[C-finanzas] status={$f['status']} pide_id=" . (int)(bool)preg_match('~name="(cita|citaId|cita_id|reserva)[^"]*"~', $f['body']) . "\n");
        $this->assertSame(200, $f['status']);
    }
}
