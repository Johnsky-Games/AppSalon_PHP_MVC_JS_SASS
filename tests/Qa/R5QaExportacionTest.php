<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * R5-QA · Listado y exportaciones (matriz 6 del usuario): CSV y XLSX reales, mismos registros y totales que la pantalla, descarga sin permiso ⇒ 403 + auditoría,
 * formato inválido ⇒ 422, datos hostiles, y VALIDACIÓN INDEPENDIENTE de los archivos con openpyxl 3.1.5 y LibreOffice headless.
 *
 * X1 el motor (Exportador) con datos hostiles: CSV/XLSX válidos, sin fórmulas, importes exactos (pasa sobre la base: el motor ya está integrado)
 * X2 límites y memoria con decenas de miles de filas · X3 CADA exportación encontrada en la interfaz ofrece CSV y XLSX ·
 * X4 contrato de las rutas (cabeceras, formato inválido 422, sin permiso 403 + auditoría, hash auditado = hash del archivo, sin auditoría ⇒ no se entrega) ·
 * X5 CSV = XLSX = pantalla (registros y totales) · X6 datos hostiles de negocio de punta a punta.
 */
final class R5QaExportacionTest extends R5Case
{
    private static string $base;
    private static array $e;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorQ5('export');
        self::$e = self::escenario();
    }

    public static function tearDownAfterClass(): void { self::pararServidorQ5('export'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    protected function tearDown(): void
    {
        foreach (self::q("SELECT trigger_name t FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'q5_%'") as $t) { self::q('DROP TRIGGER IF EXISTS `' . $t['t'] . '`'); }
    }

    private function eq(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }

    // ================================================================== X1 · el motor con datos hostiles

    /** @return array{0:array,1:array,2:array} columnas, filas y totales de un conjunto con celdas HOSTILES y todos los tipos */
    private function conjuntoHostil(): array
    {
        $cols = [['clave' => 'nombre', 'titulo' => 'Nombre'], ['clave' => 'nota', 'titulo' => 'Nota'], ['clave' => 'importe', 'titulo' => 'Importe', 'tipo' => 'dinero'],
            ['clave' => 'cant', 'titulo' => 'Cantidad', 'tipo' => 'entero'], ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha'], ['clave' => 'tasa', 'titulo' => 'Tasa', 'tipo' => 'porcentaje']];
        $hostiles = ['=1+1', '+1+1', '-1+1', '@SUM(1,1)', "\t=1+1", "\r=1+1", "\n=1+1", '=HYPERLINK("http://evil.test","clic")', "=cmd|' /C calc'!A0", "<script>alert(1)</script>", "'; DROP TABLE citas;--",
            "a\0b", "nul\0=1", "\xC3\x28 utf8 inválido", "\xF0\x28\x8C\xBC", 'Ñandú ✓ 😀', '00123', '1E5', '0x1F', ' =espacio', '|calc', '"comillas" y, comas', "linea1\nlinea2", str_repeat('x', 40000), '', '<![CDATA[x]]>', '&amp; &lt;'];
        $filas = [];
        foreach ($hostiles as $i => $h) { $filas[] = ['nombre' => $h, 'nota' => $hostiles[($i + 3) % count($hostiles)], 'importe' => 12345 + $i, 'cant' => $i, 'fecha' => '2026-10-' . sprintf('%02d', 1 + $i % 28), 'tasa' => 0.125]; }
        $filas[] = ['nombre' => 'Negativo', 'nota' => 'n', 'importe' => -550, 'cant' => -3, 'fecha' => '2026-02-28', 'tasa' => 0];
        $filas[] = ['nombre' => 'Céntimos', 'nota' => '0.10+0.20', 'importe' => 30, 'cant' => 1, 'fecha' => '2026-01-01', 'tasa' => 1];
        $filas[] = ['nombre' => 'Grande', 'nota' => 'g', 'importe' => 99999999999, 'cant' => PHP_INT_MAX >> 20, 'fecha' => '2026-12-31', 'tasa' => 0.3333];
        $suma = array_sum(array_column($filas, 'importe'));
        return [$cols, $filas, ['importe' => $suma, 'cant' => array_sum(array_column($filas, 'cant'))]];
    }

    private function generar(string $formato): string
    {
        [$cols, $filas, $tot] = $this->conjuntoHostil();
        $r = \Classes\Exportacion\Exportador::generar($formato, 'hostil', $cols, $filas, $tot);
        rewind($r['flujo']);
        $bytes = stream_get_contents($r['flujo']);
        fclose($r['flujo']);
        $this->assertSame(count($filas), $r['filas']);
        $this->assertSame(hash('sha256', $bytes), $r['sha256'], 'el hash informado no es el del archivo');
        return $bytes;
    }

    /**
     * Req F/6: XLSX con datos hostiles es un ZIP/OOXML válido (NO HTML renombrado), lo abre openpyxl 3.1.5 y NINGUNA celda es fórmula; los textos que empiezan por
     * = + - @ tab CR quedan como CADENAS; los importes son numéricos exactos (centavos → decimales sin coma flotante); hay fila de totales.
     */
    public function test_X1_xlsx_hostil_abre_en_openpyxl_sin_formulas_e_importes_exactos(): void
    {
        $x = $this->generar('xlsx');
        $this->assertTrue(self::esZipXlsx($x), 'el archivo XLSX no es un ZIP (¿HTML renombrado?)');
        $this->assertStringNotContainsString('<html', strtolower(substr($x, 0, 2000)));
        $o = self::xlsxOpenpyxl($x);
        $this->assertSame(0, $o['formulas'], 'openpyxl detectó celdas de fórmula en el XLSX');
        [$cols, $filas, $tot] = $this->conjuntoHostil();
        $this->assertCount(count($filas) + 2, $o['filas'], 'encabezado + filas + totales');
        $this->assertSame(['Nombre', 'Nota', 'Importe', 'Cantidad', 'Fecha', 'Tasa'], array_column($o['filas'][0], 'v'));
        foreach ($filas as $i => $f) {
            $celdas = $o['filas'][$i + 1];
            $this->assertSame('n', $celdas[2]['t'], "fila $i: el importe debe ser numérico");
            $this->assertSame(self::centavos(number_format((float)$celdas[2]['v'], 2, '.', '')), $f['importe'], "fila $i: importe {$celdas[2]['v']} ≠ " . self::dolares($f['importe']));
            $this->assertNotSame('f', $celdas[0]['t']);
            if ($f['nombre'] !== '' && $i < 22) {
                $esperado = str_replace(["\0"], '', $f['nombre']);
                if (mb_check_encoding($esperado, 'UTF-8') && !str_contains($f['nombre'], "\0")) {
                    $this->assertSame('s', $celdas[0]['t'], "fila $i: «" . addcslashes(mb_substr($esperado, 0, 20), "\r\n\t") . '» debe ser cadena');
                    $norm = static fn(string $t): string => str_replace(["\r\n", "\r"], "\n", $t);   // XML normaliza los saltos de línea
                    $this->assertSame($norm(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $esperado)), $norm((string)$celdas[0]['v']), "fila $i: el texto cambió");
                }
            }
        }
        $total = end($o['filas']);
        $this->assertSame($tot['importe'], self::centavos(number_format((float)$total[2]['v'], 2, '.', '')), 'la fila Total no suma lo mismo que las filas');
        // Raw: ninguna etiqueta <f> en el XML de la hoja.
        $crudo = self::xlsxCrudo($x);
        foreach ($crudo as $i => $fila) { foreach ($fila as $c) { $this->assertNotSame('f', $c['t'], "fila $i con <f> en el XML"); } }
    }

    /** Req F/6: texto de una celda > 32 767 caracteres (límite de Excel) no corrompe el libro: se abre y la celda respeta el límite. */
    public function test_X1_celda_mas_larga_que_el_limite_de_excel(): void
    {
        $x = $this->generar('xlsx');
        $o = self::xlsxOpenpyxl($x);
        foreach ($o['filas'] as $i => $fila) { foreach ($fila as $c) { if (is_string($c['v'])) { $this->assertLessThanOrEqual(32767, mb_strlen($c['v']), "fila $i: celda de " . mb_strlen($c['v']) . ' caracteres (límite de Excel 32 767)'); } } }
    }

    /** Req F/6: CSV con BOM UTF-8, re-legible con el lector estándar, mismas filas y columnas, texto con = + - @ tab CR neutralizado y sin NUL; importes exactos. */
    public function test_X1_csv_hostil_con_bom_relegible_y_neutralizado(): void
    {
        $csv = $this->generar('csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'falta el BOM UTF-8');
        $this->assertTrue(mb_check_encoding($csv, 'UTF-8'), 'el CSV no es UTF-8 válido');
        $this->assertStringNotContainsString("\0", $csv);
        $filas = self::csvFilas($csv);
        [$cols, $datos, $tot] = $this->conjuntoHostil();
        $this->assertCount(count($datos) + 2, $filas, 'encabezado + filas + total (las celdas multilínea deben ir entre comillas)');
        foreach ($filas as $i => $f) { $this->assertCount(6, $f, "fila $i: número de columnas"); }
        foreach (array_slice($filas, 0, -1) as $i => $f) {
            foreach ([0, 1] as $col) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $f[$col], "fila $i col $col: «" . addcslashes(mb_substr($f[$col], 0, 20), "\r\n\t") . '» puede interpretarse como fórmula');
            }
        }
        foreach ($datos as $i => $d) { $this->assertSame(self::dolares($d['importe']), $filas[$i + 1][2], "fila $i: importe"); }
        $this->assertSame(self::dolares($tot['importe']), end($filas)[2], 'total');
    }

    /** Req F/6: LibreOffice headless abre el XLSX y lo convierte a CSV y a PDF; los importes y el número de filas del CSV convertido coinciden con los datos. */
    public function test_X1_libreoffice_convierte_el_xlsx_a_csv_y_pdf(): void
    {
        [$cols, $datos, $tot] = $this->conjuntoHostil();
        // Conjunto acotado a texto sin multilínea ni 40 000 caracteres para que la comparación fila a fila sea determinista.
        $limpios = array_values(array_filter($datos, fn($d) => !str_contains($d['nombre'], "\n") && !str_contains($d['nota'], "\n") && !str_contains($d['nombre'], "\r") && !str_contains($d['nota'], "\r") && strlen($d['nombre']) < 200 && strlen($d['nota']) < 200 && mb_check_encoding($d['nombre'] . $d['nota'], 'UTF-8') && !str_contains($d['nombre'] . $d['nota'], "\0")));
        $suma = array_sum(array_column($limpios, 'importe'));
        $r = \Classes\Exportacion\Exportador::generar('xlsx', 'lo', $cols, $limpios, ['importe' => $suma]);
        rewind($r['flujo']); $x = stream_get_contents($r['flujo']); fclose($r['flujo']);
        $csv = self::libreOffice($x, 'xlsx', 'csv');
        $filas = self::csvFilas($csv);
        $this->assertCount(count($limpios) + 2, $filas, 'LibreOffice leyó otro número de filas');
        foreach ($limpios as $i => $d) {
            $this->assertEqualsWithDelta((float)self::dolares($d['importe']), (float)str_replace(',', '', $filas[$i + 1][2]), 0.0001, "fila $i: importe leído por LibreOffice");
        }
        $this->assertEqualsWithDelta((float)self::dolares($suma), (float)str_replace(',', '', end($filas)[2]), 0.001, 'total leído por LibreOffice');
        foreach ($filas as $i => $f) { $this->assertStringNotContainsString('#NAME', implode('|', $f), "fila $i: error de fórmula en LibreOffice"); $this->assertStringNotContainsString('Err:', implode('|', $f)); }
        $pdf = self::libreOffice($x, 'xlsx', 'pdf');
        $this->assertStringStartsWith('%PDF', $pdf, 'LibreOffice no pudo producir un PDF del libro');
    }

    // ================================================================== X2 · límites y memoria

    /** Req F/6 (límites): 50 000 filas × 8 columnas en XLSX y CSV con memoria acotada (< 48 MB de pico) y en tiempo razonable; el XLSX resultante abre en openpyxl con todas sus filas. */
    public function test_X2_cincuenta_mil_filas_con_memoria_acotada(): void
    {
        $codigo = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
$cols = [['clave'=>'a','titulo'=>'A'],['clave'=>'b','titulo'=>'B'],['clave'=>'c','titulo'=>'C','tipo'=>'dinero'],['clave'=>'d','titulo'=>'D','tipo'=>'entero'],['clave'=>'e','titulo'=>'E','tipo'=>'fecha'],['clave'=>'f','titulo'=>'F'],['clave'=>'g','titulo'=>'G'],['clave'=>'h','titulo'=>'H','tipo'=>'porcentaje']];
$gen = (function () { for ($i = 0; $i < 50000; $i++) { yield ['a'=>"Cliente $i",'b'=>'=SUMA('.$i.')','c'=>$i*7,'d'=>$i,'e'=>'2026-10-'.sprintf('%02d',1+$i%28),'f'=>str_repeat('z',60),'g'=>"g$i",'h'=>0.5]; } })();
$t=microtime(true);
$r = \Classes\Exportacion\Exportador::generar($argv[2], 'grande', $cols, $gen, ['c'=>1,'d'=>2]);
fclose($r['flujo']);
echo json_encode(['filas'=>$r['filas'],'bytes'=>$r['bytes'],'pico'=>memory_get_peak_usage(true),'seg'=>round(microtime(true)-$t,2)]);
PHP;
        foreach (['csv', 'xlsx'] as $fmt) {
            $p = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $codigo, self::RAIZ, $fmt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            proc_close($p);
            $j = json_decode($out, true);
            $this->assertIsArray($j, "$fmt: el proceso no produjo resultado: $err");
            $this->assertSame(50000, $j['filas']);
            $this->assertLessThan(48 * 1048576, $j['pico'], "$fmt: pico de memoria " . round($j['pico'] / 1048576, 1) . ' MB');
            $this->assertLessThan(60, $j['seg'], "$fmt: tardó {$j['seg']} s");
        }
    }

    // ================================================================== datos para los endpoints

    /** Siembra caja y pagos con conceptos hostiles; devuelve [ingresos, egresos] en centavos. */
    private function sembrarCaja(): array
    {
        $cli = self::$e['cliente']['id'];
        $hoy = self::gye()->format('Y-m-d H:i:s');
        $conceptos = ['Corte normal', '=HYPERLINK("http://evil.test","x")', '+cmd', '-2+3', '@SUM(A1)', "\t=1", "<script>alert(1)</script>", "'; DROP TABLE pagos;--", 'Ñandú, "comillas"', "salto\nlinea"];
        $ing = 0;
        foreach ($conceptos as $i => $con) {
            $c = self::crearCita(['cliente' => $cli, 'pro' => self::$e['proA']['profesional_id'], 'estado' => 'completada', 'pago' => 'pagado', 'servicios' => [[$con === 'Corte normal' ? 'Corte Q5' : 'Servicio ' . $i, '20.00']]]);
            $monto = 2000 + $i;
            self::q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,pagado_at) VALUES (?,?,?,?,'manual',?,'completado',?,?,?)",
                [$c, $c, $cli, 'MAN-Q5-' . $i . bin2hex(random_bytes(3)), self::dolares($monto), $i % 2 ? 'transferencia' : 'efectivo', 'q5:' . bin2hex(random_bytes(8)), $hoy]);
            $pid = (int)self::db()->insert_id;
            self::q("INSERT INTO caja_movimientos(actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES (?,'ingreso',?,?,?,?,?,?,?)",
                [self::$e['dueno']['id'], $monto, $con, $i % 2 ? 'transferencia' : 'efectivo', $pid, 'q5c:' . bin2hex(random_bytes(8)), hash('sha256', (string)$i), $hoy]);
            $ing += $monto;
        }
        self::q("INSERT INTO caja_movimientos(actor_id,tipo,monto_centavos,concepto,metodo,idempotencia,huella,registrado_en) VALUES (?,'egreso',?,?,'efectivo',?,?,?)", [self::$e['dueno']['id'], 1550, '=1+1 compra', 'q5e:' . bin2hex(random_bytes(8)), hash('sha256', 'e'), $hoy]);
        return [$ing, 1550];
    }

    /** Todas las exportaciones tabulares que el contrato exige: ruta, rol que puede, rol que NO puede. */
    private function exportaciones(): array
    {
        $hoy = self::gye()->format('Y-m-d');
        return [
            'CRM clientes' => ['/admin/clientes/exportar?tipo=clientes', 'dueno', 'recepcion'],
            'CRM comercial' => ['/admin/clientes/exportar?tipo=comercial', 'gerente', 'proA'],
            'Caja' => ["/caja/exportar?desde=$hoy&hasta=$hoy", 'finanzas', 'recepcion'],
            'Reportes' => ['/admin/reportes/exportar', 'dueno', 'recepcion'],
        ];
    }

    private function con(string $ruta, string $formato): string { return $ruta . (str_contains($ruta, '?') ? '&' : '?') . 'formato=' . rawurlencode($formato); }

    // ================================================================== X3

    /**
     * Req F/6: TODA exportación que la interfaz ofrece (enlaces «Exportar/Descargar/CSV/Excel» de las pantallas del equipo) acepta formato=csv y formato=xlsx
     * y entrega un archivo real de cada tipo. Rastrea las pantallas como propietario y prueba cada destino de exportación encontrado.
     */
    public function test_X3_toda_exportacion_de_la_interfaz_ofrece_csv_y_xlsx(): void
    {
        $this->sembrarCaja();
        $n = $this->eq('dueno');
        $paginas = ['/admin', '/admin/clientes', '/admin/clientes/auditoria', '/admin/reportes', '/admin/finanzas', '/caja', '/admin/crecimiento', '/admin/inventario', '/agenda', '/admin/equipo', '/admin/ajustes'];
        $destinos = [];
        foreach ($paginas as $p) {
            $r = $n->get($p);
            if ($r['status'] !== 200) { continue; }
            foreach (self::destinos($r['body']) as $d) {
                if (preg_match('~exportar|descargar|export|\.csv|\.xlsx~i', $d)) { $destinos[self::pathDe($d)] = $d; }
            }
        }
        $this->assertNotEmpty($destinos, 'no se encontró ninguna exportación en la interfaz');
        $fallos = [];
        foreach (['/admin/clientes/exportar', '/admin/reportes/exportar', '/caja/exportar'] as $esperada) {
            if (!isset($destinos[$esperada])) { $fallos[] = "la interfaz no ofrece ningún enlace a $esperada"; }
        }
        foreach ($destinos as $path => $d) {
            $csv = $n->get($this->con($d, 'csv'));
            if ($csv['status'] !== 200 || !str_contains(implode(';', $csv['headers']['content-type'] ?? []), 'text/csv')) { $fallos[] = "$path formato=csv → {$csv['status']} " . implode(';', $csv['headers']['content-type'] ?? []); }
            $x = $n->get($this->con($d, 'xlsx'));
            if ($x['status'] !== 200 || !self::esZipXlsx($x['body'])) { $fallos[] = "$path formato=xlsx → {$x['status']} " . ($x['status'] === 200 ? 'el cuerpo no es un XLSX (PK…)' : ''); }
        }
        foreach ($destinos as $path => $d) {
            $r = $n->get(preg_replace('/formato=[^&]*&?/', '', $d));
            $enlazados = [];
            foreach ($paginas as $p) { foreach (self::destinos($n->get($p)['body']) as $dd) { if (self::pathDe($dd) === $path) { $enlazados[] = $this->queryDe($dd)['formato'] ?? 'csv'; } } }
            if (!in_array('xlsx', $enlazados, true)) { $fallos[] = "la interfaz no ofrece el enlace/botón a Excel de $path (solo: " . json_encode(array_values(array_unique($enlazados))) . ')'; }
        }
        $this->sinFallos($fallos);
    }

    private function queryDe(string $ruta): array { parse_str((string)parse_url(html_entity_decode($ruta), PHP_URL_QUERY), $q); return $q; }

    // ================================================================== X4

    /** Req F/6: cabeceras seguras y archivo coherente: Content-Type correcto, attachment con nombre ASCII seguro, nosniff, no-store, BOM en CSV, ZIP en XLSX. */
    public function test_X4_cabeceras_y_formatos_de_cada_exportacion(): void
    {
        $this->sembrarCaja();
        $fallos = [];
        foreach ($this->exportaciones() as $nombre => [$ruta, $rolOk]) {
            $n = $this->eq($rolOk);
            foreach (['csv' => 'text/csv', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '' => 'text/csv'] as $fmt => $mime) {
                $r = $fmt === '' ? $n->get($ruta) : $n->get($this->con($ruta, $fmt));
                $h = $r['headers'];
                if ($r['status'] !== 200) { $fallos[] = "$nombre formato=" . ($fmt ?: '(omitido)') . " → {$r['status']}"; continue; }
                if (!str_contains($h['content-type'][0] ?? '', $mime)) { $fallos[] = "$nombre $fmt: Content-Type " . ($h['content-type'][0] ?? '-'); }
                if (!preg_match('/^attachment; filename="[A-Za-z0-9._-]+\.(csv|xlsx)"$/', $h['content-disposition'][0] ?? '')) { $fallos[] = "$nombre $fmt: Content-Disposition " . ($h['content-disposition'][0] ?? '-'); }
                if (($h['x-content-type-options'][0] ?? '') !== 'nosniff') { $fallos[] = "$nombre $fmt: falta nosniff"; }
                if (!str_contains($h['cache-control'][0] ?? '', 'no-store')) { $fallos[] = "$nombre $fmt: falta Cache-Control no-store"; }
                $esXlsx = $fmt === 'xlsx';
                if ($esXlsx && !self::esZipXlsx($r['body'])) { $fallos[] = "$nombre: el XLSX no es un ZIP"; }
                if (!$esXlsx && !str_starts_with($r['body'], "\xEF\xBB\xBF")) { $fallos[] = "$nombre: el CSV no empieza con BOM"; }
                if (!$esXlsx && str_contains(strtolower(substr($r['body'], 0, 500)), '<html')) { $fallos[] = "$nombre: el CSV parece HTML"; }
            }
        }
        $this->sinFallos($fallos);
    }

    /** Req F/6: formato inválido ⇒ 422 en el layout (nunca un archivo ni 5xx) y nada se entrega; ningún valor de formato se refleja sin escapar. */
    public function test_X4_formato_invalido_es_422(): void
    {
        $fallos = [];
        foreach ($this->exportaciones() as $nombre => [$ruta, $rolOk]) {
            $n = $this->eq($rolOk);
            foreach (['pdf', 'xls', 'XLSX ', 'csv;xlsx', '<script>alert(1)</script>', '../../etc/passwd', "xlsx\0", str_repeat('a', 3000)] as $malo) {
                $r = $n->get($this->con($ruta, $malo));
                if ($r['status'] !== 422) { $fallos[] = "$nombre formato=" . substr(addcslashes($malo, "\0"), 0, 20) . " → {$r['status']} (esperado 422)"; continue; }
                if (!self::esPaginaCompleta($r['body'])) { $fallos[] = "$nombre: el 422 no es una página completa"; }
                if (str_contains($r['body'], '<script>alert(1)</script>')) { $fallos[] = "$nombre: refleja el formato hostil sin escapar"; }
                if (self::enlacesCruzados($r['body'], 'equipo')) { $fallos[] = "$nombre: el 422 enlaza al portal del cliente"; }
            }
            $arr = $n->get($ruta . (str_contains($ruta, '?') ? '&' : '?') . 'formato[]=csv');
            if ($arr['status'] !== 422) { $fallos[] = "$nombre formato[]=csv → {$arr['status']} (esperado 422)"; }
        }
        $this->sinFallos($fallos);
    }

    private function tablasAuditoria(): array
    {
        return array_column(self::q("SELECT table_name t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '%exportaciones%'"), 't');
    }

    /** TRUNCATE no activa los triggers de solo-anexado: sólo para aislar mediciones en la BD de pruebas. */
    private function vaciarAuditorias(): void
    {
        self::db()->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->tablasAuditoria() as $t) { self::q("TRUNCATE TABLE `$t`"); }
        self::db()->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private function filasAuditoria(): int
    {
        $n = 0;
        foreach ($this->tablasAuditoria() as $t) { $n += (int)self::escalar("SELECT COUNT(*) FROM `$t`"); }
        return $n;
    }

    /**
     * Req F/6: descarga DIRECTA sin permiso ⇒ 403 (sin datos) y queda AUDITADA la denegación; anónimo ⇒ redirección. Para recepción, profesional, finanzas (según la ruta) y cliente.
     */
    public function test_X4_descarga_directa_sin_permiso_es_403_y_se_audita(): void
    {
        $this->sembrarCaja();
        $fallos = [];
        $cli = self::entrar(self::$base, self::$e['cliente'], 'cliente');
        foreach ($this->exportaciones() as $nombre => [$ruta, , $rolNo]) {
            foreach (['csv', 'xlsx'] as $fmt) {
                foreach ([[$rolNo, $this->eq($rolNo)], ['cliente', $cli]] as [$quien, $nav]) {
                    $this->vaciarAuditorias();   // las denegaciones consecutivas del mismo actor pueden agruparse: se mide la PRIMERA de cada serie
                    $antes = $this->filasAuditoria();
                    $r = $nav->get($this->con($ruta, $fmt));
                    if ($r['status'] !== 403) { $fallos[] = "$nombre $fmt como $quien → {$r['status']} (esperado 403)"; }
                    if (str_contains($r['body'], '@example.test') || str_contains($r['body'], 'Corte Q5') || self::esZipXlsx($r['body'])) { $fallos[] = "$nombre $fmt como $quien: el 403 contiene datos"; }
                    if ($quien === $rolNo && $this->filasAuditoria() <= $antes) { $fallos[] = "$nombre $fmt como $quien: la denegación NO quedó auditada"; }
                }
            }
            $r = (new Navegador(self::$base))->get($this->con($ruta, 'xlsx'));
            if (!in_array($r['status'], [302, 303, 401], true)) { $fallos[] = "$nombre anónimo → {$r['status']}"; }
        }
        $this->sinFallos($fallos);
    }

    /**
     * Req F/6 (contrato §7): autorizar → generar → AUDITAR → entregar. La auditoría de éxito guarda actor, rol, formato, filas y el SHA-256 del archivo
     * entregado; si la auditoría falla NO se entrega el archivo (503 sin datos).
     */
    public function test_X4_la_auditoria_registra_hash_filas_y_formato_y_si_falla_no_se_entrega(): void
    {
        $this->sembrarCaja();
        $n = $this->eq('dueno');
        $fallos = [];
        foreach (['csv', 'xlsx'] as $fmt) {
            $r = $n->get($this->con('/admin/clientes/exportar?tipo=clientes', $fmt));
            if ($r['status'] !== 200) { $fallos[] = "CRM $fmt → {$r['status']}"; continue; }
            $fila = self::q("SELECT * FROM crm_exportaciones_auditoria WHERE resultado='exitosa' ORDER BY id DESC LIMIT 1")[0] ?? null;
            if (!$fila) { $fallos[] = "CRM $fmt: sin fila de auditoría exitosa"; continue; }
            if ($fila['formato'] !== $fmt) { $fallos[] = "CRM $fmt: la auditoría registra formato «{$fila['formato']}»"; }
            if ($fila['contenido_sha256'] !== hash('sha256', $r['body'])) { $fallos[] = "CRM $fmt: el hash auditado ({$fila['contenido_sha256']}) no es el del archivo entregado"; }
            if ((int)$fila['actor_usuario_id'] !== self::$e['dueno']['id'] || $fila['actor_rol'] !== 'propietario') { $fallos[] = "CRM $fmt: actor/rol auditados incorrectos"; }
            if ((int)$fila['filas'] !== (int)self::escalar('SELECT COUNT(*) FROM clientes')) { $fallos[] = "CRM $fmt: filas auditadas {$fila['filas']}"; }
            if (preg_match('/@example\.test|Bearer|csrf_token|PHPSESSID/i', json_encode($fila))) { $fallos[] = "CRM $fmt: la auditoría contiene datos personales o tokens"; }
        }
        // Resto de exportaciones: algún registro de auditoría general con el hash del archivo.
        $generales = array_values(array_diff($this->tablasAuditoria(), ['crm_exportaciones_auditoria']));
        if (!$generales) { $fallos[] = 'no existe la tabla de auditoría general de exportaciones (migración 026)'; }
        else {
            $hoy = self::gye()->format('Y-m-d');
            foreach (['Caja' => "/caja/exportar?desde=$hoy&hasta=$hoy", 'Reportes' => '/admin/reportes/exportar'] as $nombre => $ruta) {
                $r = $n->get($this->con($ruta, 'xlsx'));
                if ($r['status'] !== 200) { $fallos[] = "$nombre xlsx → {$r['status']}"; continue; }
                $hash = hash('sha256', $r['body']);
                $hallado = false;
                foreach ($generales as $t) { foreach (self::q("SELECT * FROM `$t` ORDER BY 1 DESC LIMIT 20") as $fila) { if (in_array($hash, array_map('strval', $fila), true)) { $hallado = true; } } }
                if (!$hallado) { $fallos[] = "$nombre: ninguna fila de auditoría contiene el SHA-256 del archivo entregado"; }
            }
        }
        // Si la auditoría no se puede escribir, NO se entrega.
        self::q("CREATE TRIGGER q5_audit_crm BEFORE INSERT ON crm_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='q5'");
        foreach ($generales as $t) { self::q("CREATE TRIGGER q5_audit_$t BEFORE INSERT ON `$t` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='q5'"); }
        try {
            $hoy = self::gye()->format('Y-m-d');
            foreach (['/admin/clientes/exportar?tipo=clientes', "/caja/exportar?desde=$hoy&hasta=$hoy", '/admin/reportes/exportar'] as $ruta) {
                foreach (['csv', 'xlsx'] as $fmt) {
                    $r = $n->get($this->con($ruta, $fmt));
                    if (!in_array($r['status'], [500, 503], true)) { $fallos[] = "sin auditoría $ruta $fmt → {$r['status']} (esperado 503 o 500 sin entregar datos)"; }
                    if (self::esZipXlsx($r['body']) || str_contains($r['body'], 'Clienta') || str_contains($r['body'], '@example.test') || str_contains(($r['headers']['content-disposition'][0] ?? ''), 'attachment')) { $fallos[] = "sin auditoría $ruta $fmt: SE ENTREGÓ el archivo"; }
                }
            }
        } finally {
            self::q('DROP TRIGGER IF EXISTS q5_audit_crm');
            foreach ($generales as $t) { self::q("DROP TRIGGER IF EXISTS q5_audit_$t"); }
        }
        $this->sinFallos($fallos);
    }

    // ================================================================== X5 · CSV = XLSX = pantalla

    private function tablaExportada(Navegador $n, string $ruta, string $fmt): array
    {
        $r = $n->get($this->con($ruta, $fmt));
        $this->assertSame(200, $r['status'], "GET " . $this->con($ruta, $fmt) . " → {$r['status']}");
        if ($fmt === 'csv') { return self::csvFilas($r['body']); }
        $this->assertTrue(self::esZipXlsx($r['body']), 'formato=xlsx no entregó un XLSX (' . ($r['headers']['content-type'][0] ?? '-') . ')');
        $o = self::xlsxOpenpyxl($r['body']);
        $this->assertSame(0, $o['formulas'], 'XLSX con fórmulas');
        return array_map(fn($fila) => array_map(fn($c) => $c['v'] === null ? '' : (is_float($c['v']) || is_int($c['v']) ? (string)$c['v'] : (string)$c['v']), $fila), $o['filas']);
    }

    private function numero(string $v): ?string { $v = str_replace(['$', ',', ' '], '', $v); return is_numeric($v) ? $v : null; }

    /**
     * Req F/6 (Listado → detalle → CSV y XLSX con los mismos registros y totales): caja. El CSV y el XLSX tienen las MISMAS filas (mismos valores, importes
     * iguales numéricamente), las sumas de la columna de importes coinciden con la caja en SQL y la fila de totales coincide con la pantalla /caja.
     */
    public function test_X5_caja_csv_xlsx_y_pantalla_coinciden_en_filas_y_totales(): void
    {
        [$ing, $egr] = $this->sembrarCaja();
        $n = $this->eq('dueno');
        $hoy = self::gye()->format('Y-m-d');
        $ruta = "/caja/exportar?desde=$hoy&hasta=$hoy";
        $csv = $this->tablaExportada($n, $ruta, 'csv');
        $xlsx = $this->tablaExportada($n, $ruta, 'xlsx');
        $this->assertCount(count($csv), $xlsx, 'CSV y XLSX tienen distinto número de filas');
        $this->assertSame($csv[0], $xlsx[0], 'encabezados distintos');
        $colImporte = null;
        foreach (['/cobrado|confirmad/i', '/importe|monto/i', '/total/i'] as $re) { foreach ($csv[0] as $i => $h) { if (preg_match($re, $h)) { $colImporte = $i; break 2; } } }
        $this->assertNotNull($colImporte, 'no hay columna de importe cobrado: ' . json_encode($csv[0], JSON_UNESCAPED_UNICODE));
        $detalle = array_slice($csv, 1, -1);
        $totalFila = end($csv);
        $this->assertGreaterThanOrEqual(11, count($detalle), 'faltan movimientos de caja en la exportación');
        foreach ($detalle as $i => $f) {
            $a = $this->numero($f[$colImporte]); $b = $this->numero($xlsx[$i + 1][$colImporte]);
            $this->assertNotNull($a, "CSV fila $i importe no numérico «{$f[$colImporte]}»"); $this->assertNotNull($b, "XLSX fila $i importe no numérico");
            $this->assertSame(self::centavos(number_format((float)$a, 2, '.', '')), self::centavos(number_format((float)$b, 2, '.', '')), "fila $i: CSV $a ≠ XLSX $b");
        }
        // Sumas contra SQL (ingresos − egresos, o ingresos y egresos separados según el diseño de la hoja).
        $sumaFilas = 0;
        foreach ($detalle as $f) { $sumaFilas += self::centavos(number_format((float)($this->numero($f[$colImporte]) ?? '0'), 2, '.', '')); }
        // La base de la clase acumula días previos de otras pruebas: el universo exportado es TODA la caja de hoy, no solo lo sembrado aquí.
        $ingHoy = (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM caja_movimientos WHERE tipo='ingreso' AND pago_id IS NOT NULL AND DATE(registrado_en)=?", [$hoy]);
        $egrHoy = (int)self::escalar("SELECT COALESCE(SUM(monto_centavos),0) FROM caja_movimientos WHERE tipo='egreso' AND pago_id IS NOT NULL AND DATE(registrado_en)=?", [$hoy]);
        $this->assertGreaterThanOrEqual($ing, $ingHoy);
        $this->assertTrue(in_array($sumaFilas, [$ingHoy + $egrHoy, $ingHoy - $egrHoy, $ingHoy], true),
            "la suma de la columna «{$csv[0][$colImporte]}» (" . self::dolares($sumaFilas) . ") no corresponde a lo cobrado en caja (ingresos " . self::dolares($ing) . ", egresos sueltos " . self::dolares($egr) . ')');
        $this->assertNotNull($this->numero($totalFila[$colImporte]), 'la fila de totales no tiene importe');
        $pantalla = self::texto($n->get("/caja?desde=$hoy&hasta=$hoy")['body']);
        $this->assertStringContainsString(number_format($sumaFilas / 100, 2, '.', ','), $pantalla, 'la pantalla /caja no muestra el total cobrado que exportó (' . number_format($sumaFilas / 100, 2, '.', ',') . ')');
        $this->assertStringContainsString(number_format(array_sum(array_map(fn($f) => (float)$this->numero($f[$colImporte]), $detalle)), 2, '.', ','), $pantalla);
    }

    /** Req F/6 + E: CRM — el CSV, el XLSX y la lista en pantalla (todas las páginas) tienen exactamente los mismos clientes con los mismos filtros. */
    public function test_X5_crm_csv_xlsx_y_lista_coinciden(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            self::cliente(['email' => "x5.$i." . bin2hex(random_bytes(3)) . '@example.test', 'nombre' => "Equis$i", 'apellido' => 'Cinco', 'estado' => $i % 3 === 0 ? 'suspendido' : 'activo']);
        }
        $n = $this->eq('dueno');
        $q = 'membresia=activo&tipo=clientes';
        $csv = $this->tablaExportada($n, "/admin/clientes/exportar?$q", 'csv');
        $xlsx = $this->tablaExportada($n, "/admin/clientes/exportar?$q", 'xlsx');
        $this->assertSame(count($csv), count($xlsx), 'CSV y XLSX difieren en filas');
        $col = null;
        foreach ($csv[0] as $i => $h) { if (preg_match('/correo|e-?mail/i', $h)) { $col = $i; } }
        $this->assertNotNull($col, 'sin columna de correo: ' . json_encode($csv[0], JSON_UNESCAPED_UNICODE));
        $a = array_column(array_slice($csv, 1), $col); $b = array_column(array_slice($xlsx, 1), $col);
        $a = array_values(array_filter($a, fn($x) => str_contains($x, '@'))); $b = array_values(array_filter($b, fn($x) => str_contains($x, '@')));
        sort($a); sort($b);
        $this->assertSame($a, $b, 'CSV y XLSX no contienen los mismos clientes');
        $esperado = array_column(self::q("SELECT u.email FROM usuarios u JOIN clientes c ON c.usuario_id=u.id WHERE c.estado='activo'"), 'email');
        sort($esperado);
        $this->assertSame($esperado, $a, 'la exportación no coincide con los clientes activos en SQL');
        $lista = [];
        for ($p = 1; $p <= 5; $p++) {
            $r = $n->get("/admin/clientes?membresia=activo&por_pagina=10&pagina=$p");
            preg_match_all('~/admin/clientes/ficha\?id=(\d+)~', $r['body'], $m);
            $lista = array_merge($lista, $m[1]);
        }
        $this->assertGreaterThanOrEqual(count($esperado), count(array_unique($lista)), 'la lista en pantalla no alcanza a mostrar a todos los activos');
    }

    // ================================================================== X6 · datos hostiles de negocio

    /** Req F/6: clientes y conceptos con fórmulas, tab, CR, XSS y SQL salen NEUTRALIZADOS en el CSV y como cadenas en el XLSX, de punta a punta (BD → HTTP → archivo → openpyxl). */
    public function test_X6_datos_hostiles_de_negocio_de_punta_a_punta(): void
    {
        $nombres = ['=HYPERLINK("http://evil.test","x")', '+SUMA(1)', '-2+5', '@cmd', "\t=1+1", "\r=1+1", '<img src=x onerror=alert(1)>', "Robert'); DROP TABLE clientes;--"];
        foreach ($nombres as $i => $nom) {
            self::cliente(['email' => "x6.$i." . bin2hex(random_bytes(3)) . '@example.test', 'nombre' => $nom, 'apellido' => 'Hostil' . $i]);
        }
        $this->sembrarCaja();
        $n = $this->eq('dueno');
        $hoy = self::gye()->format('Y-m-d');
        $fallos = [];
        foreach (['/admin/clientes/exportar?tipo=clientes' => 'CRM', "/caja/exportar?desde=$hoy&hasta=$hoy" => 'Caja', '/admin/reportes/exportar' => 'Reportes'] as $ruta => $nombre) {
            $csv = $n->get($this->con($ruta, 'csv'));
            if ($csv['status'] !== 200) { $fallos[] = "$nombre csv → {$csv['status']}"; continue; }
            foreach (self::csvFilas($csv['body']) as $i => $fila) {
                foreach ($fila as $j => $c) {
                    if (preg_match('/^[=+\-@\t\r]/', $c) && !preg_match('/^-?\d+(\.\d+)?%?$/', $c) && !preg_match('/^[+-]?\d/', $c)) { $fallos[] = "$nombre CSV fila $i col $j: «" . addcslashes(mb_substr($c, 0, 30), "\r\n\t") . '» sin neutralizar'; }
                }
            }
            $x = $n->get($this->con($ruta, 'xlsx'));
            if ($x['status'] !== 200) { $fallos[] = "$nombre xlsx → {$x['status']}"; continue; }
            try { $o = self::xlsxOpenpyxl($x['body']); } catch (\Throwable $e) { $fallos[] = "$nombre XLSX no abre en openpyxl: " . $e->getMessage(); continue; }
            if ($o['formulas'] > 0) { $fallos[] = "$nombre XLSX con {$o['formulas']} fórmulas"; }
            foreach (self::xlsxCrudo($x['body']) as $i => $fila) { foreach ($fila as $c) { if ($c['t'] === 'f') { $fallos[] = "$nombre XLSX fila $i con <f>"; } } }
            $pdf = null;
            try { $pdf = self::libreOffice($x['body'], 'xlsx', 'csv'); } catch (\Throwable $e) { $fallos[] = "$nombre: LibreOffice no abre el XLSX: " . $e->getMessage(); }
            if ($pdf !== null && preg_match('/#NAME\?|Err:\d+|#VALUE!/', $pdf)) { $fallos[] = "$nombre: LibreOffice evaluó celdas como fórmula"; }
        }
        $this->sinFallos($fallos);
    }

    // ================================================================== límites con miles de filas por HTTP

    /**
     * Req F/6 (límites): con 12 000 clientes la exportación CRM (tope documentado de 10 000 filas) responde en tiempo razonable en CSV y XLSX, respeta el
     * tope, el XLSX abre en openpyxl con todas las filas entregadas, y el recorte queda INFORMADO (auditoría con truncado=1 o aviso en la respuesta).
     */
    public function test_X7_miles_de_filas_por_http_respetan_el_tope_y_lo_informan(): void
    {
        self::q("INSERT INTO usuarios(nombre,apellido,email,password,telefono,confirmado) SELECT CONCAT('Masivo',n), 'Prueba', CONCAT('masivo',n,'@example.test'), 'x', '0990000000', 1 FROM (SELECT a.N + b.N*10 + c.N*100 + d.N*1000 + e.N*10000 + 1 n FROM (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c, (SELECT 0 N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d, (SELECT 0 N UNION SELECT 1) e) t WHERE n <= 12000");
        self::q("INSERT INTO clientes(usuario_id,estado,origen) SELECT id,'activo','migracion' FROM usuarios WHERE email LIKE 'masivo%@example.test'");
        $n = $this->eq('dueno');
        $n->timeout = 120;
        $fallos = [];
        foreach (['csv', 'xlsx'] as $fmt) {
            $t = microtime(true);
            $r = $n->get($this->con('/admin/clientes/exportar?tipo=clientes', $fmt));
            $seg = microtime(true) - $t;
            if ($r['status'] !== 200) { $fallos[] = "$fmt con 12 000 clientes → {$r['status']}"; continue; }
            if ($seg > 60) { $fallos[] = "$fmt tardó " . round($seg, 1) . ' s'; }
            if ($fmt === 'xlsx' && !self::esZipXlsx($r['body'])) { $fallos[] = 'formato=xlsx no entregó un XLSX (' . ($r['headers']['content-type'][0] ?? '-') . ')'; continue; }
            $filas = $fmt === 'csv' ? count(self::csvFilas($r['body'])) - 1 : count(self::xlsxCrudo($r['body'])) - 1;
            if ($filas > 10001) { $fallos[] = "$fmt entregó $filas filas (tope documentado 10 000 + total)"; }
            if ($filas < 10000) { $fallos[] = "$fmt entregó solo $filas filas"; }
            $aud = self::q("SELECT truncado,filas FROM crm_exportaciones_auditoria WHERE resultado='exitosa' ORDER BY id DESC LIMIT 1")[0] ?? null;
            if (!$aud || (int)$aud['truncado'] !== 1) { $fallos[] = "$fmt: el recorte a 10 000 filas no quedó registrado (truncado=1)"; }
            if ($fmt === 'xlsx') { $o = self::xlsxOpenpyxl($r['body']); if ($o['formulas'] !== 0 || count($o['filas']) < 10001) { $fallos[] = 'xlsx grande: openpyxl ve ' . count($o['filas']) . ' filas / ' . $o['formulas'] . ' fórmulas'; } }
        }
        $this->sinFallos($fallos);
    }
}
