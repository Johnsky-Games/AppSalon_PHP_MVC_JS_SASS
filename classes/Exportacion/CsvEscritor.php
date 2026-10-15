<?php
namespace Classes\Exportacion;

/** CSV compatible con Excel: UTF-8 con BOM, comillas RFC 4180, fórmulas neutralizadas, importes exactos desde centavos. */
final class CsvEscritor implements EscritorTabla
{
    /** @var resource */
    private $salida;
    /** @var array<int,array{clave:string,titulo:string,tipo:string}> */
    private array $columnas = [];
    private int $filas = 0;

    /** @param resource $salida */
    public function __construct($salida) { $this->salida = $salida; }

    public function iniciar(array $columnas): void
    {
        $this->columnas = $columnas;
        fwrite($this->salida, "\xEF\xBB\xBF");
        $this->linea(array_map(static fn(array $c): string => Neutralizacion::textoCsv($c['titulo']), $columnas));
    }

    public function fila(array $valores): void
    {
        $celdas = [];
        foreach ($this->columnas as $c) { $celdas[] = self::celda($c['tipo'], $valores[$c['clave']] ?? null); }
        $this->linea($celdas);
        $this->filas++;
    }

    public function totales(string $etiqueta, array $valores): void
    {
        $celdas = [];
        foreach ($this->columnas as $i => $c) {
            $celdas[] = $i === 0 ? Neutralizacion::textoCsv($etiqueta) : (array_key_exists($c['clave'], $valores) ? self::celda($c['tipo'], $valores[$c['clave']]) : '');
        }
        $this->linea($celdas);
    }

    public function nota(string $texto): void
    {
        $this->linea(array_merge([Neutralizacion::textoCsv($texto)], array_fill(0, max(0, count($this->columnas) - 1), '')));
    }

    public function cerrar(): void {}

    public function filasEscritas(): int { return $this->filas; }

    private static function celda(string $tipo, mixed $v): string
    {
        if ($v === null || $v === '') { return ''; }
        return match ($tipo) {
            'entero' => (string)(int)$v,
            'dinero' => Neutralizacion::dinero($v),
            'decimal' => is_numeric($v) ? (string)$v : '',
            'porcentaje' => is_numeric($v) ? rtrim(rtrim(number_format((float)$v * 100, 2, '.', ''), '0'), '.') . '%' : '',
            default => Neutralizacion::textoCsv($v),   // texto, fecha, fechahora
        };
    }

    /** @param string[] $celdas */
    private function linea(array $celdas): void
    {
        // El tercer/cuarto argumento fijan el escape vacío (RFC 4180) y evitan el aviso de obsolescencia de PHP 8.4.
        if (fputcsv($this->salida, $celdas, ',', '"', '', "\r\n") === false) { throw new \RuntimeException('No se pudo escribir el archivo.'); }
    }
}
