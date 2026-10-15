<?php
namespace Classes\Exportacion;

/**
 * Fachada única de exportaciones tabulares (CSV y XLSX) para todas las secciones.
 *
 * Contrato de uso (ronda 5):
 *   1. $r = Exportador::generar($formato, $nombreBase, $columnas, $filas, $totales?, $opciones?);   // escribe en un flujo temporal (disco si es grande)
 *   2. el llamador AUDITA con $r['filas'], $r['sha256'], $r['bytes'] (si la auditoría falla NO se entrega el archivo);
 *   3. Exportador::entregar($r);                                                                      // cabeceras seguras + envío por bloques
 * La autorización se comprueba en el controlador ANTES de llamar a generar() y se vuelve a comprobar en cada petición de descarga.
 * Los totales los calcula quien llama a partir del MISMO universo filtrado que la lista. Nunca se exportan fórmulas.
 */
final class Exportador
{
    public const FORMATOS = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    public const MAX_FILAS_PREDETERMINADO = 50000;
    private const MEMORIA_TEMPORAL = 2097152;

    /** Formato pedido en la consulta (`formato=csv|xlsx`); csv por omisión. Devuelve null si el valor no es válido. */
    public static function formatoDe(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') { return 'csv'; }
        return is_string($valor) && isset(self::FORMATOS[strtolower($valor)]) ? strtolower($valor) : null;
    }

    /**
     * @param array<int,array{clave:string,titulo:string,tipo?:string}> $columnas
     * @param iterable<array<string,mixed>> $filas  generador recomendado (memoria acotada)
     * @param array<string,mixed>|null $totales     valores por clave de columna; se escribe una fila «Total»
     * @param array{max_filas?:int,hoja?:string,ahora?:\DateTimeImmutable} $opciones
     * @return array{flujo:resource,formato:string,mime:string,archivo:string,filas:int,bytes:int,sha256:string,truncado:bool,columnas:string[]}
     */
    public static function generar(string $formato, string $nombreBase, array $columnas, iterable $filas, ?array $totales = null, array $opciones = []): array
    {
        if (!isset(self::FORMATOS[$formato])) { throw new \InvalidArgumentException('Formato de exportación no válido.'); }
        $cols = [];
        foreach ($columnas as $c) {
            $tipo = $c['tipo'] ?? 'texto';
            if (!in_array($tipo, ['texto', 'entero', 'dinero', 'decimal', 'porcentaje', 'fecha', 'fechahora'], true)) { throw new \InvalidArgumentException('Tipo de columna no válido.'); }
            $cols[] = ['clave' => (string)$c['clave'], 'titulo' => (string)$c['titulo'], 'tipo' => $tipo];
        }
        if (!$cols) { throw new \InvalidArgumentException('La exportación necesita al menos una columna.'); }
        $max = (int)($opciones['max_filas'] ?? self::MAX_FILAS_PREDETERMINADO);
        if ($formato === 'xlsx') { $max = min($max, XlsxEscritor::MAX_FILAS); }
        $flujo = fopen('php://temp/maxmemory:' . self::MEMORIA_TEMPORAL, 'w+b');
        if ($flujo === false) { throw new \RuntimeException('No se pudo preparar la exportación.'); }
        $w = $formato === 'xlsx' ? new XlsxEscritor($flujo, (string)($opciones['hoja'] ?? 'Datos'), $opciones['ahora'] ?? null) : new CsvEscritor($flujo);
        $w->iniciar($cols);
        $n = 0; $truncado = false;
        foreach ($filas as $fila) {
            if ($n >= $max) { $truncado = true; break; }
            $w->fila($fila);
            $n++;
        }
        if ($totales !== null && !$truncado) { $w->totales('Total', $totales); }
        $w->cerrar();
        $bytes = (int)ftell($flujo);
        rewind($flujo);
        $h = hash_init('sha256');
        hash_update_stream($h, $flujo);
        rewind($flujo);
        return [
            'flujo' => $flujo, 'formato' => $formato, 'mime' => self::FORMATOS[$formato], 'filas' => $n, 'bytes' => $bytes,
            'sha256' => hash_final($h), 'truncado' => $truncado, 'columnas' => array_column($cols, 'titulo'),
            'archivo' => self::nombreArchivo($nombreBase, $formato),
        ];
    }

    /** Envía el archivo generado y cierra el flujo. En pruebas (CLI sin cabeceras observables) registra las cabeceras en $GLOBALS. */
    public static function entregar(array $r, bool $enviarCuerpo = true): array
    {
        $cabeceras = [
            'Content-Type' => $r['mime'],
            'Content-Disposition' => 'attachment; filename="' . $r['archivo'] . '"',
            'Content-Length' => (string)$r['bytes'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];
        $GLOBALS['__appsalon_ultima_exportacion'] = ['cabeceras' => $cabeceras, 'filas' => $r['filas'], 'sha256' => $r['sha256'], 'formato' => $r['formato']];
        if (!headers_sent()) {
            http_response_code(200);
            foreach ($cabeceras as $k => $v) { header($k . ': ' . $v); }
        }
        if ($enviarCuerpo) {
            while (!feof($r['flujo'])) {
                $bloque = fread($r['flujo'], 65536);
                if ($bloque === false || $bloque === '') { break; }
                echo $bloque;
            }
        }
        fclose($r['flujo']);
        return $cabeceras;
    }

    /** Nombre ASCII seguro: sin rutas, sin comillas ni saltos de línea. */
    public static function nombreArchivo(string $base, string $formato): string
    {
        $b = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $b = trim((string)$b, '._-');
        return ($b === '' ? 'exportacion' : substr($b, 0, 80)) . '.' . $formato;
    }
}
