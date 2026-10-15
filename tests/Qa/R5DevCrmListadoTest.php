<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';
require_once __DIR__ . '/../Support/LectorXlsx.php';

use Tests\Support\LectorXlsx;

/**
 * Ronda 5 (D, E, F) con HTTP real: filtro → listado → descarga CSV/XLSX con los mismos registros y totales; descarga directa sin
 * permiso (403 + auditoría); formato inválido (422); anónimo. Servidor propio en 8180-8189 (no compite con el rango del QA).
 */
final class R5DevCrmListadoTest extends QaCase
{
    private static string $base = '';
    private static $proc = null;
    private static array $p = [];
    private static array $nav = [];
    private static array $cli = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        $puerto = 0;
        for ($c = 8180; $c <= 8189 && $puerto === 0; $c++) { $s = @stream_socket_server("tcp://127.0.0.1:$c"); if ($s) { fclose($s); $puerto = $c; } }
        if ($puerto === 0) { throw new \RuntimeException('Sin puertos libres en 8180-8189'); }
        $log = sys_get_temp_dir() . "/c5_web_$puerto.log"; $sess = sys_get_temp_dir() . "/c5_sess_$puerto"; @mkdir($sess, 0777, true);
        foreach (glob($sess . '/sess_*') ?: [] as $v) { @unlink($v); }
        $env = array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '2640', 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1']);
        self::$proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sess, '-S', "127.0.0.1:$puerto", '-t', self::RAIZ . '/public', __DIR__ . '/router.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $pipes, self::RAIZ, $env);
        for ($i = 0; $i < 50; $i++) { $x = @fsockopen('127.0.0.1', $puerto, $en, $es, 0.2); if ($x) { fclose($x); break; } usleep(100000); }
        self::$base = "http://127.0.0.1:$puerto";

        $d = self::propietario(self::correo('dueno'));
        self::$p['dueno'] = $d + ['portal' => 'equipo'];
        foreach (['gerente', 'recepcion', 'finanzas'] as $rol) { self::$p[$rol] = self::miembro($d['id'], $rol) + ['portal' => 'equipo']; }
        // Clientes: activos recientes, sin confirmar, con comunicaciones otorgadas / revocadas, y un nombre hostil.
        $srv = self::servicio('Corte R5', 20.0, 30);
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1)', ['Pro R5']); $prof = (int)self::db()->insert_id;
        foreach ([['ana', ['nombre' => 'Ana', 'apellido' => 'Activa'], true], ['beto', ['nombre' => 'Beto', 'apellido' => 'Sinconfirmar', 'confirmado' => false], false], ['cata', ['nombre' => 'Cata', 'apellido' => 'Revocada'], true], ['hostil', ['nombre' => '=HYPERLINK("http://x","a")', 'apellido' => 'Hostil'], false]] as [$k, $o, $visita]) {
            self::$cli[$k] = self::cliente($o + ['email' => self::correo($k)]);
            self::q('UPDATE usuarios SET nombre=? WHERE id=?', [$o['nombre'], self::$cli[$k]['id']]);
            if ($visita) {
                self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?, '10:00:00','10:00:00','10:30:00',30,?,?,'completada','pagado')", [(new \DateTimeImmutable('-4 days'))->format('Y-m-d'), self::$cli[$k]['id'], $prof]);
                $cid = (int)self::db()->insert_id;
                self::q('INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,30)', [$cid, $srv, 'Corte', '20.00']);
            }
        }
        foreach (['ana' => [true], 'cata' => [true, false]] as $k => $decisiones) {
            foreach ($decisiones as $acepta) { (new \Repositories\ConsentimientoRepository(self::db()))->aplicarDecision(self::$cli[$k]['id'], $acepta, null, ['origen' => 'perfil', 'canal' => 'portal_cliente']); }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) { @proc_terminate(self::$proc); proc_close(self::$proc); self::$proc = null; }
    }

    private function nav(string $quien): Navegador
    {
        if (!isset(self::$nav[$quien])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $this->assertSame(302, $n->login('equipo', self::$p[$quien]['email'], self::$p[$quien]['password'])['status'], $quien);
            self::$nav[$quien] = $n;
        }
        return self::$nav[$quien];
    }

    /** @return string[] ids de la tabla del listado (enlaces a la ficha) */
    private static function idsDe(string $html): array
    {
        preg_match_all('~/admin/clientes/ficha\?id=(\d+)~', $html, $m);
        $ids = array_values(array_unique($m[1])); sort($ids); return $ids;
    }
    private static function enlace(string $html, string $texto): string
    {
        $this_ = preg_quote($texto, '~');
        if (!preg_match('~<a [^>]*href="([^"]+)"[^>]*>\s*' . $this_ . '~u', $html, $m)) { throw new \RuntimeException("Sin enlace «{$texto}»"); }
        return html_entity_decode($m[1]);
    }
    private static function csv(string $cuerpo): array
    {
        $fh = fopen('php://temp', 'r+'); fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', $cuerpo)); rewind($fh);
        $cab = fgetcsv($fh, 0, ',', '"', ''); $filas = []; $total = null;
        while (($f = fgetcsv($fh, 0, ',', '"', '')) !== false) { if ($f === [null]) { continue; } if ($f[0] === 'Total') { $total = array_combine($cab, $f); continue; } $filas[] = array_combine($cab, $f); }
        return [$cab, $filas, $total];
    }

    public function test_panel_filtro_listado_y_descargas_recorren_el_mismo_universo(): void
    {
        $n = $this->nav('dueno');
        $panel = $n->get('/admin/clientes?cuenta=confirmada');
        $this->assertSame(200, $panel['status']);
        $this->assertStringContainsString('Quitar el filtro Cuenta: Confirmada', $panel['body']);
        $this->assertStringContainsString('Limpiar filtros', $panel['body']);
        // «Ver listado» de los clientes activos conserva el filtro y abre la lista real
        $href = self::enlace($panel['body'], 'Ver listado de');
        $this->assertStringContainsString('/admin/clientes/listado?', $href);
        $this->assertStringContainsString('cuenta=confirmada', $href);
        $lista = $n->get($href);
        $this->assertSame(200, $lista['status']);
        $ids = self::idsDe($lista['body']);
        $this->assertNotEmpty($ids);
        // Descargas del listado (mismos filtros): CSV y XLSX
        $csvUrl = self::enlace($lista['body'], 'Exportar clientes (CSV)'); $xlsxUrl = self::enlace($lista['body'], 'Exportar clientes (Excel XLSX)');
        $this->assertStringContainsString('formato=csv', $csvUrl); $this->assertStringContainsString('formato=xlsx', $xlsxUrl);
        $csv = $n->get($csvUrl); $xls = $n->get($xlsxUrl);
        $this->assertSame(200, $csv['status']); $this->assertSame(200, $xls['status']);
        $this->assertStringStartsWith('text/csv', $csv['headers']['content-type'][0]);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xls['headers']['content-type'][0]);
        $this->assertStringContainsString('attachment; filename="clientes_', $csv['headers']['content-disposition'][0]);
        $this->assertSame('0', $xls['headers']['x-exportacion-truncada'][0]);
        [$cab, $filas, $total] = self::csv($csv['body']);
        $idsC = array_column($filas, 'ID'); sort($idsC);
        $filasX = LectorXlsx::filas($xls['body']); array_shift($filasX); $totalX = array_pop($filasX);
        $idsX = array_column($filasX, 0); sort($idsX);
        $this->assertSame($ids, $idsC, 'CSV = tabla del listado');
        $this->assertSame($ids, $idsX, 'XLSX = tabla del listado');
        $this->assertSame('Total', $totalX[0]);
        // El conteo que muestra la pantalla es el del archivo
        $this->assertMatchesRegularExpression('~<strong>' . count($ids) . '</strong> clientes~', $lista['body']);
        $this->assertSame(count($ids) . ' clientes', $total['Nombre']);
        // Sin ninguno de los hostiles ejecutable
        foreach ($filas as $f) { foreach ($f as $celda) { $this->assertDoesNotMatchRegularExpression('/^\s*[=+\-@]/', $celda); } }
        // Auditoría con formato, filas y hash del archivo
        $a = self::q("SELECT * FROM crm_exportaciones_auditoria WHERE actor_usuario_id=? AND resultado='exitosa' ORDER BY id DESC LIMIT 2", [self::$p['dueno']['id']]);
        $this->assertSame(['xlsx', 'csv'], array_column($a, 'formato'));
        $this->assertSame(hash('sha256', $xls['body']), $a[0]['contenido_sha256']); $this->assertSame(hash('sha256', $csv['body']), $a[1]['contenido_sha256']);
        $this->assertSame(count($ids), (int)$a[0]['filas']);
    }

    public function test_cada_indicador_con_enlace_abre_su_listado_con_la_misma_cifra(): void
    {
        $n = $this->nav('dueno');
        $panel = $n->get('/admin/clientes');
        preg_match_all('~<a class="crm-kpi__listado" href="([^"]+)">Ver listado de ([^<]+)</a>~u', $panel['body'], $m, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(5, count($m), 'las cifras de clientes ofrecen su listado');
        foreach ($m as [, $href, $texto]) {
            $r = $n->get(html_entity_decode($href));
            $this->assertSame(200, $r['status'], $href);
            if (preg_match('/(\d+)/', $texto, $mm) && !preg_match('/las |los |la |el /', $texto)) {
                $this->assertMatchesRegularExpression('~<strong>' . $mm[1] . '</strong> clientes?~u', $r['body'], "«{$texto}» debe coincidir con el conteo del listado");
            }
            $this->assertStringContainsString('id="crm-conteo"', $r['body']);
        }
    }

    public function test_descarga_directa_sin_permiso_es_403_y_queda_anotada(): void
    {
        $antes = (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE resultado='denegada'");
        foreach (['recepcion', 'finanzas'] as $rol) {
            $n = $this->nav($rol);
            foreach (['csv', 'xlsx'] as $fmt) {
                $r = $n->get("/admin/clientes/exportar?tipo=clientes&formato=$fmt");
                $this->assertSame(403, $r['status'], "$rol $fmt");
                $this->assertStringNotContainsString('PK', substr($r['body'], 0, 2));
            }
        }
        $this->assertGreaterThan($antes, (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE resultado='denegada'"));
        // recepción puede ver el listado (clientes.ver) pero no recibe botones de descarga; finanzas no recibe listado nominal
        $l = $this->nav('recepcion')->get('/admin/clientes/listado');
        $this->assertSame(200, $l['status']); $this->assertStringNotContainsString('Exportar clientes', $l['body']);
        $this->assertSame(403, $this->nav('finanzas')->get('/admin/clientes/listado')['status']);
        $anon = new Navegador(self::$base);
        $this->assertSame(302, $anon->get('/admin/clientes/listado')['status']);
        $this->assertSame(302, $anon->get('/admin/clientes/exportar?tipo=clientes&formato=xlsx')['status']);
    }

    public function test_formato_o_filtro_invalido_es_422_y_no_entrega_archivo(): void
    {
        $n = $this->nav('dueno');
        foreach (['formato=xls', 'formato=csv&cuenta=hola', 'formato=xlsx&saldo[]=1', 'formato=csv&visitas_min=-3', 'formato[]=csv'] as $q) {
            $r = $n->get('/admin/clientes/exportar?tipo=clientes&' . $q);
            $this->assertSame(422, $r['status'], $q);
            $this->assertArrayNotHasKey('content-disposition', $r['headers'], $q);
        }
        $r = $n->get('/admin/clientes/listado?actividad=<script>alert(1)</script>');
        $this->assertSame(422, $r['status']); $this->assertStringNotContainsString('<script>alert(1)', $r['body']);
    }

    public function test_listado_sin_javascript_funciona_como_pagina_completa_y_accesible(): void
    {
        $r = $this->nav('dueno')->get('/admin/clientes/listado?segmento=nuevo&orden=ultima&dir=desc&por_pagina=10');
        $this->assertSame(200, $r['status']);
        foreach (['<h1 class="nombre-pagina"', 'aria-sort="descending"', 'role="status"', '<caption class="crm-sr">', 'aria-label="Paginación de clientes"', 'Volver a clientes'] as $t) { $this->assertStringContainsString($t, $r['body'], $t); }
        $this->assertSame('no-store', $r['headers']['cache-control'][0] ?? '');
    }
}
