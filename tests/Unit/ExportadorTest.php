<?php
namespace Tests\Unit;

use Classes\Exportacion\Exportador;
use Classes\Exportacion\Neutralizacion;
use Classes\Exportacion\XlsxEscritor;
use PHPUnit\Framework\TestCase;

final class ExportadorTest extends TestCase
{
    private const COLS = [
        ['clave' => 'id', 'titulo' => 'ID', 'tipo' => 'entero'],
        ['clave' => 'n', 'titulo' => 'Nombre'],
        ['clave' => 'm', 'titulo' => 'Monto (USD)', 'tipo' => 'dinero'],
        ['clave' => 'f', 'titulo' => 'Fecha', 'tipo' => 'fecha'],
    ];

    private static function filas(): \Generator
    {
        yield ['id' => 1, 'n' => '=HYPERLINK("http://x","a")', 'm' => 1234, 'f' => '2026-10-15'];
        yield ['id' => 2, 'n' => "+cmd|' /C calc'!A0", 'm' => -550, 'f' => '2026-10-16'];
        yield ['id' => 3, 'n' => "@SUM(1)\x00\x07 Ñandú, \"comillas\"\nsalto", 'm' => 5, 'f' => null];
    }

    /** Lector mínimo de ZIP (sin ext-zip) sobre el directorio central. @return array<string,string> */
    private static function descomprimir(string $zip): array
    {
        $fin = strrpos($zip, "PK\x05\x06");
        self::assertNotFalse($fin, 'sin registro de fin de directorio');
        $e = unpack('vdisco/vdirdisco/vn/vtotal/Vtam/Vpos', substr($zip, $fin + 4, 18));
        $salida = []; $p = $e['pos'];
        for ($i = 0; $i < $e['total']; $i++) {
            $c = unpack('Vsig/vver/vneed/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vnom/vextra/vcom/vdisk/vint/Vext/Vrel', substr($zip, $p, 46));
            self::assertSame(0x02014B50, $c['sig']);
            $nombre = substr($zip, $p + 46, $c['nom']);
            $l = unpack('vnom/vextra', substr($zip, $c['rel'] + 26, 4));
            $ini = $c['rel'] + 30 + $l['nom'] + $l['extra'];
            $datos = substr($zip, $ini, $c['comp']);
            $plano = $c['metodo'] === 8 ? gzinflate($datos) : $datos;
            self::assertSame($c['tam'], strlen($plano), "tamaño de $nombre");
            self::assertSame($c['crc'], crc32($plano), "CRC de $nombre");
            $salida[$nombre] = $plano;
            $p += 46 + $c['nom'] + $c['extra'] + $c['com'];
        }
        return $salida;
    }

    public function test_csv_con_bom_formulas_neutralizadas_y_dinero_exacto(): void
    {
        $r = Exportador::generar('csv', 'Clientes 2026/10', self::COLS, self::filas(), ['id' => 6, 'm' => 689]);
        $csv = stream_get_contents($r['flujo']);
        $this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
        $this->assertSame(3, $r['filas']);
        $this->assertSame(hash('sha256', $csv), $r['sha256']);
        $this->assertStringContainsString("1,\"'=HYPERLINK(\"\"http://x\"\",\"\"a\"\")\",12.34,2026-10-15", $csv);
        $this->assertStringContainsString("2,\"'+cmd|' /C calc'!A0\",-5.50,2026-10-16", $csv);
        $this->assertStringContainsString("'@SUM(1)", $csv);
        $this->assertStringNotContainsString("\x00", $csv);
        $this->assertStringContainsString("Total,,6.89,", $csv);
        $this->assertSame('Clientes_2026_10.csv', $r['archivo']);
    }

    public function test_xlsx_es_un_zip_valido_con_texto_en_linea_y_numeros_tipados(): void
    {
        $r = Exportador::generar('xlsx', '../../etc/passwd', self::COLS, self::filas(), ['id' => 6, 'm' => 689], ['hoja' => 'Clien/tes: [1]']);
        $zip = stream_get_contents($r['flujo']);
        $this->assertSame('etc_passwd.xlsx', $r['archivo']);
        $this->assertStringStartsWith('PK', $zip);
        $p = self::descomprimir($zip);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $parte) {
            $this->assertArrayHasKey($parte, $p);
            $this->assertNotFalse(simplexml_load_string($p[$parte]), "$parte es XML bien formado");
        }
        $hoja = $p['xl/worksheets/sheet1.xml'];
        $this->assertStringNotContainsString('<f>', $hoja, 'nunca fórmulas');
        $this->assertStringContainsString('t="inlineStr"', $hoja);
        $this->assertStringContainsString('=HYPERLINK(&quot;http://x&quot;,&quot;a&quot;)', $hoja);
        $this->assertStringContainsString('<v>12.34</v>', $hoja);
        $this->assertStringContainsString('<v>-5.50</v>', $hoja);
        $this->assertStringContainsString('<v>6.89</v>', $hoja);
        $this->assertStringContainsString('name="Clien tes   1"', $p['xl/workbook.xml']);
        $this->assertSame(3, $r['filas']);
    }

    public function test_fechas_de_excel_y_limites(): void
    {
        $this->assertSame('46310', XlsxEscritor::serial('2026-10-15'));
        $this->assertNull(XlsxEscritor::serial('2026-02-29'));
        $this->assertNull(XlsxEscritor::serial('15/10/2026'));
        $this->assertStringStartsWith('46310.5', XlsxEscritor::serial('2026-10-15 12:00:00'));
        $this->assertSame('-0.05', Neutralizacion::dinero(-5));
        $this->assertSame('12345678.90', Neutralizacion::dinero(1234567890));
    }

    public function test_truncado_por_maximo_y_formato_invalido(): void
    {
        $r = Exportador::generar('csv', 'x', self::COLS, self::filas(), ['id' => 99], ['max_filas' => 2]);
        $this->assertTrue($r['truncado']);
        $this->assertSame(2, $r['filas']);
        $this->assertStringNotContainsString('Total', stream_get_contents($r['flujo']), 'un archivo truncado no declara totales');
        $this->assertNull(Exportador::formatoDe('xls'));
        $this->assertSame('csv', Exportador::formatoDe(null));
        $this->assertSame('xlsx', Exportador::formatoDe('XLSX'));
        $this->expectException(\InvalidArgumentException::class);
        Exportador::generar('xls', 'x', self::COLS, []);
    }

    public function test_memoria_acotada_con_muchas_filas(): void
    {
        $gen = (static function () { for ($i = 1; $i <= 30000; $i++) { yield ['id' => $i, 'n' => "Cliente $i", 'm' => $i, 'f' => '2026-10-15']; } })();
        $antes = memory_get_usage();
        $r = Exportador::generar('xlsx', 'grande', self::COLS, $gen);
        $this->assertSame(30000, $r['filas']);
        $this->assertLessThan(12 * 1048576, memory_get_peak_usage() - $antes + 1);
        fclose($r['flujo']);
    }
}
