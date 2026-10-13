<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * Ronda 2B, ítem 5: pantallas nuevas (asistente de agenda, reasignación, auditoría de exportaciones, alta de cliente y avisos de homónimos,
 * consentimiento versionado) con Chromium real: sin desbordes de 320 a 1440 px, sin errores de consola, objetivos táctiles, foco visible,
 * movimiento reducido, sin JavaScript y contrato JS ↔ servidor del asistente. (Las pantallas ya existentes las cubren FrontendTest y los E2E de tests/*.js.)
 */
final class R2bFrontendTest extends R2bCase
{
    private static array $r = [];
    private static array $cfg = [];
    private static ?string $motivoSin = null;
    private const CHROME_RESPALDO = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

    private static function chromium(): ?string
    {
        $env = getenv('CHROME_BIN');
        if (is_string($env) && $env !== '') { return is_file($env) && is_executable($env) ? $env : null; }
        return is_file(self::CHROME_RESPALDO) && is_executable(self::CHROME_RESPALDO) ? self::CHROME_RESPALDO : null;
    }

    public static function setUpBeforeClass(): void
    {
        $chrome = self::chromium();
        if ($chrome === null || !is_dir(self::RAIZ . '/node_modules/puppeteer-core')) {
            self::$motivoSin = 'Chromium o puppeteer-core no disponibles (CHROME_BIN / npm ci)';
            return;
        }
        self::limpiar();
        $base = self::servidor([], 'r2b_front');
        $d = self::propietario(self::correo('dueno'));
        $s1 = self::servicio('Corte F', 20, 30); $s2 = self::servicio('Tinte F', 40, 60);
        $prof = self::miembro($d['id'], 'profesional');
        $pid = (int)$prof['profesional_id'];
        self::q('DELETE FROM profesionales_servicios WHERE profesionalId=?', [$pid]);
        foreach ([$s1, $s2] as $s) { self::q('INSERT INTO profesionales_servicios(profesionalId,servicioId) VALUES (?,?)', [$pid, $s]); }
        for ($i = 1; $i <= 7; $i++) { self::q('INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,?,?)', [$pid, $i, '09:00:00', '18:00:00']); }
        $cli = self::cliente(['email' => self::correo('cli-f'), 'nombre' => 'Clienta Larguísima Con Nombre Muy Extenso Para Probar', 'apellido' => 'Apellido Compuesto Extremadamente Largo', 'telefono' => '0995550101']);
        foreach ([8, 9] as $n) {
            $f = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify("+$n days")->format('Y-m-d');
            self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?,'10:00:00','10:00:00','11:00:00',60,?,?, 'confirmada','pendiente')", [$f, $cli['id'], $pid]);
            self::q("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,60)", [(int)self::db()->insert_id, $s2, 'Tinte F', '40.00']);
        }
        self::q("INSERT INTO profesionales(nombre,activo) VALUES ('Sustituta Disponible',1)");
        $sust = (int)self::db()->insert_id;
        foreach ([$s1, $s2] as $s) { self::q('INSERT INTO profesionales_servicios(profesionalId,servicioId) VALUES (?,?)', [$sust, $s]); }
        for ($i = 1; $i <= 7; $i++) { self::q('INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,?,?)', [$sust, $i, '09:00:00', '18:00:00']); }
        // Homónimos para los avisos y filas de auditoría.
        self::cliente(['email' => self::correo('hom'), 'nombre' => 'Valentina', 'apellido' => 'Cedeño Larga', 'telefono' => '0993332211']);
        self::q("INSERT INTO profesionales(nombre,activo) VALUES ('Gabriela Ponce',1)");
        for ($i = 0; $i < 8; $i++) { self::q("INSERT INTO crm_exportaciones_auditoria(ocurrido_en,actor_usuario_id,actor_rol,formato,tipo,resultado,motivo,filas,columnas_n) VALUES (UTC_TIMESTAMP(),?,?,'csv','clientes',?,?,?,?)", [$d['id'], 'propietario', $i % 2 ? 'denegada' : 'exitosa', $i % 2 ? 'sin_permiso' : null, $i % 2 ? null : 120, $i % 2 ? null : 19]); }
        // Cliente con consentimiento de una versión anterior.
        $conCons = self::cliente(['email' => self::correo('cons'), 'nombre' => 'Con', 'apellido' => 'Consentimiento']);
        $repo = new \Repositories\ConsentimientoRepository(self::db());
        $repo->aplicarDecision($conCons['id'], true, null, ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $conCons['id']]);
        $repo->publicar('Acepto recibir promociones y novedades del salón por correo electrónico, entiendo que puedo retirar este consentimiento cuando quiera desde mi perfil y que no afecta los avisos de mis reservas. (texto largo de prueba para el responsive)');
        self::$cfg = ['base' => $base, 'owner' => ['email' => $d['email'], 'password' => $d['password']], 'colProf' => (int)$prof['colaborador_id'], 'cliente' => ['email' => $conCons['email'], 'password' => $conCons['password']],
            'homonimo' => ['nombre' => 'Valentina', 'apellido' => 'Cedeño Larga'], 'profHomonimo' => 'Gabriela Ponce'];
        self::q('TRUNCATE intentos_login');
        $p = proc_open(['node', self::RAIZ . '/tests/Qa/frontend_r2b.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['QA_CONFIG' => json_encode(self::$cfg), 'CHROME_BIN' => $chrome]));
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        proc_close($p);
        self::$r = json_decode($out, true) ?: ['error' => 'sin salida JSON: ' . $err . substr($out, 0, 300)];
        self::$cfg['colProfId'] = $pid;
    }

    public static function tearDownAfterClass(): void { self::pararServidor('r2b_front'); }

    protected function setUp(): void
    {
        if (!self::$cfg) {
            if (getenv('QA_EXIGIR_NAVEGADOR') === '1') { $this->fail('QA_EXIGIR_NAVEGADOR=1: ' . self::$motivoSin); }
            $this->markTestSkipped(self::$motivoSin ?? 'Chromium no disponible');
        }
        $this->assertArrayNotHasKey('error', self::$r, self::$r['error'] ?? '');
    }

    public function test_sin_desbordamiento_horizontal_en_las_pantallas_nuevas_de_320_a_1440(): void
    {
        $this->assertGreaterThanOrEqual(59, count(self::$r['medidas']), 'se esperaban al menos 59 mediciones (pantallas x anchos)');
        foreach (self::$r['medidas'] as $d) { $this->assertSame(200, $d['status'], "{$d['ruta']} @{$d['ancho']}"); $this->assertNotSame('', trim($d['h1']), "{$d['ruta']} @{$d['ancho']}: sin h1"); }
        $malos = array_values(array_filter(self::$r['medidas'], fn($d) => $d['malo']));
        $this->assertSame([], array_map(fn($d) => "{$d['ruta']} @{$d['ancho']}px: scroll={$d['scrollWidth']} client={$d['clientWidth']} " . implode(',', $d['sobresale']), $malos));
    }

    public function test_sin_errores_de_consola_ni_recursos_rotos(): void
    {
        $this->assertSame([], array_map(fn($e) => "{$e['tipo']}: {$e['texto']} ({$e['url']})", self::$r['eventos']));
    }

    public function test_los_avisos_de_homonimos_aparecen_en_la_interfaz_real_a_todos_los_anchos_y_sin_javascript(): void
    {
        foreach (self::$r['clienteSinSesion'] as $k => $v) {
            if (str_starts_with($k, 'aviso')) { $this->assertTrue($v, "no apareció el aviso de homónimos ($k)"); }
        }
        $this->assertTrue(self::$r['sinJs']['avisoVisible']);
        $this->assertTrue(self::$r['sinJs']['botonConfirmar']);
        $this->assertGreaterThanOrEqual(2, self::$r['sinJs']['selectoresDecision']);
        $this->assertGreaterThanOrEqual(7, self::$r['sinJs']['formularioAgenda'], 'sin JS el asistente de agenda debe seguir mostrando las horas');
        $this->assertTrue(self::$r['clienteSinSesion']['avisoVersionAnterior']);
        $this->assertTrue(self::$r['clienteSinSesion']['reconfirmar']);
    }

    public function test_el_asistente_con_javascript_envia_exactamente_lo_que_el_servidor_espera(): void
    {
        $a = self::$r['asistente'];
        $this->assertSame(2, $a['filasLunesTrasAgregar'], 'añadir un turno no creó la fila');
        $this->assertSame(['09:00-13:00', '14:00-18:00'], $a['martesTrasCopiar'], 'copiar el lunes no copió los turnos');
        foreach ($a['nombresMartes'] as $n) { $this->assertMatchesRegularExpression('~^(franjas|descansos)\[2\]\[\d+\]\[(inicio|fin|motivo)\]$~', $n, 'nombre de campo mal reescrito por el JS'); }
        $this->assertSame(1, $a['filasViernesTrasQuitar']);
        $this->assertStringContainsString('actualizados', $a['mensaje'], 'el guardado no confirmó: ' . $a['mensaje']);
        $this->assertSame(['09:00-13:00', '14:00-18:00'], $a['martesRecargado']);
        $this->assertSame('Café <b>x</b>', $a['motivoRecargado'], 'el motivo debe guardarse literal y volver escapado');
        $pid = self::$cfg['colProfId'];
        $this->assertSame(2, (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales WHERE profesionalId=? AND dia_semana=2', [$pid]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM descansos_profesionales WHERE profesionalId=? AND dia_semana=1 AND motivo=?", [$pid, 'Café <b>x</b>']));
        $this->assertSame(2, (int)self::escalar('SELECT COUNT(*) FROM profesionales_servicios WHERE profesionalId=?', [$pid]), 'el guardado no debe quitar servicios con citas futuras');
    }

    public function test_foco_visible_movimiento_reducido_y_objetivos_tactiles(): void
    {
        foreach (self::$r['teclado'] as $t) {
            $this->assertGreaterThan(5, $t['total'], "{$t['ruta']}: poca navegación por teclado");
            $this->assertSame([], $t['sinIndicador'], "{$t['ruta']}: controles sin indicador de foco");
        }
        foreach (self::$r['movimiento'] as $m) { $this->assertSame(0, $m['animacionesInfinitas'], "{$m['ruta']}: animaciones infinitas con prefers-reduced-motion"); }
        $this->assertSame([], array_map(fn($o) => "{$o['ruta']} @{$o['ancho']}: " . implode(', ', $o['controles']), self::$r['objetivos']), 'controles interactivos de menos de 44 px de alto en las pantallas nuevas');
    }
}
