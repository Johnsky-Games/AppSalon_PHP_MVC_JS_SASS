<?php
namespace Classes\Exportacion;

/**
 * XLSX real (Office Open XML) escrito en flujo y sin dependencias: cada texto es una cadena en línea (jamás una fórmula),
 * los importes salen de centavos enteros, las fechas son fechas de Excel con formato y la memoria no crece con las filas.
 * Tipos de columna: texto | entero | dinero (centavos) | decimal | porcentaje (razón 0–1) | fecha (Y-m-d) | fechahora (Y-m-d H:i[:s]).
 */
final class XlsxEscritor implements EscritorTabla
{
    public const MAX_FILAS = 1048575;   // el límite de Excel es 1 048 576 con el encabezado
    private EscritorZip $zip;
    /** @var array<int,array{clave:string,titulo:string,tipo:string}> */
    private array $columnas = [];
    private int $fila = 0;
    private string $hoja;

    /** @param resource $salida */
    public function __construct($salida, string $hoja = 'Datos', ?\DateTimeImmutable $ahora = null)
    {
        $this->zip = new EscritorZip($salida, $ahora);
        $limpia = preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', Neutralizacion::textoXml($hoja));
        $this->hoja = mb_substr(trim((string)$limpia) !== '' ? trim((string)$limpia) : 'Datos', 0, 31);
    }

    public function iniciar(array $columnas): void
    {
        $this->columnas = $columnas;
        $z = $this->zip;
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $z->agregar('[Content_Types].xml', $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->agregar('_rels/.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->agregar('xl/workbook.xml', $x . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . htmlspecialchars($this->hoja, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->agregar('xl/_rels/workbook.xml.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // Estilos: 0 base · 1 encabezado · 2 dinero · 3 entero · 4 fecha · 5 fecha y hora · 6 porcentaje · 7 decimal · 8 total texto · 9 total dinero · 10 total entero · 11 texto (forzado)
        $z->agregar('xl/styles.xml', $x . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="3"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm"/><numFmt numFmtId="166" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8F0F1"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="12"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="166" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="1" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

        $z->abrirEntrada('xl/worksheets/sheet1.xml');
        $cols = '';
        foreach ($columnas as $i => $c) {
            $ancho = max(12, min(48, (int)round(mb_strlen($c['titulo']) * 1.3) + 2));
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $ancho . '" customWidth="1"/>';
        }
        $z->escribirEntrada($x . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>' . $cols . '</cols><sheetData>');
        $cel = '';
        foreach ($columnas as $i => $c) { $cel .= self::texto(self::ref($i, 1), $c['titulo'], 1); }
        $z->escribirEntrada('<row r="1">' . $cel . '</row>');
        $this->fila = 1;
    }

    public function fila(array $valores): void
    {
        if ($this->fila >= self::MAX_FILAS) { throw new \OverflowException('La tabla supera el máximo de filas de Excel.'); }
        $this->fila++;
        $cel = '';
        foreach ($this->columnas as $i => $c) { $cel .= $this->celda(self::ref($i, $this->fila), $c['tipo'], $valores[$c['clave']] ?? null, false); }
        $this->zip->escribirEntrada('<row r="' . $this->fila . '">' . $cel . '</row>');
    }

    public function totales(string $etiqueta, array $valores): void
    {
        $this->fila++;
        $cel = '';
        foreach ($this->columnas as $i => $c) {
            $ref = self::ref($i, $this->fila);
            if ($i === 0) { $cel .= self::texto($ref, $etiqueta, 8); }
            elseif (array_key_exists($c['clave'], $valores)) { $cel .= $this->celda($ref, $c['tipo'], $valores[$c['clave']], true); }
        }
        $this->zip->escribirEntrada('<row r="' . $this->fila . '">' . $cel . '</row>');
    }

    public function nota(string $texto): void
    {
        $this->fila++;
        $this->zip->escribirEntrada('<row r="' . $this->fila . '">' . self::texto(self::ref(0, $this->fila), $texto, 8) . '</row>');
    }

    public function cerrar(): void
    {
        $ultima = self::ref(max(0, count($this->columnas) - 1), 1);
        $this->zip->escribirEntrada('</sheetData><autoFilter ref="A1:' . $ultima . '"/></worksheet>');
        $this->zip->cerrarEntrada();
        $this->zip->cerrar();
    }

    public function filasEscritas(): int { return max(0, $this->fila - 1); }

    private function celda(string $ref, string $tipo, mixed $v, bool $total): string
    {
        if ($v === null || $v === '') { return ''; }
        switch ($tipo) {
            case 'entero':     return '<c r="' . $ref . '" s="' . ($total ? 10 : 3) . '"><v>' . (int)$v . '</v></c>';
            case 'dinero':     return '<c r="' . $ref . '" s="' . ($total ? 9 : 2) . '"><v>' . Neutralizacion::dinero($v) . '</v></c>';
            case 'decimal':    return is_numeric($v) ? '<c r="' . $ref . '" s="7"><v>' . (0 + $v) . '</v></c>' : '';
            case 'porcentaje': return is_numeric($v) ? '<c r="' . $ref . '" s="6"><v>' . (0 + $v) . '</v></c>' : '';
            case 'fecha':
            case 'fechahora':
                $n = self::serial((string)$v);
                return $n === null ? self::texto($ref, (string)$v, 0) : '<c r="' . $ref . '" s="' . ($tipo === 'fecha' ? 4 : 5) . '"><v>' . $n . '</v></c>';
            default:           return self::texto($ref, $v, $total ? 8 : 11);
        }
    }

    /** Cadena en línea (jamás fórmula). */
    private static function texto(string $ref, mixed $v, int $estilo): string
    {
        $t = htmlspecialchars(Neutralizacion::textoXml($v), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">' . $t . '</t></is></c>';
    }

    /** Número de serie de Excel (sistema 1900) para una fecha o fecha-hora local ya presentada; null si no es válida. */
    public static function serial(string $valor): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $valor, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) { return null; }
        $base = new \DateTimeImmutable('1899-12-30 00:00:00', new \DateTimeZone('UTC'));
        $dia = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $m[1], $m[2], $m[3]), new \DateTimeZone('UTC'));
        $dias = (int)$base->diff($dia)->days;
        $seg = ((int)($m[4] ?? 0)) * 3600 + ((int)($m[5] ?? 0)) * 60 + (int)($m[6] ?? 0);
        return $seg === 0 ? (string)$dias : $dias . rtrim(substr(sprintf('%.8F', $seg / 86400), 1), '0');
    }

    private static function ref(int $col, int $fila): string
    {
        $s = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) { $s = chr(65 + ($n - 1) % 26) . $s; }
        return $s . $fila;
    }
}
