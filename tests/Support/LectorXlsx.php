<?php
namespace Tests\Support;

/** Lector mínimo de XLSX sin ext-zip (directorio central del ZIP + gzinflate) para comprobar los archivos que escribe XlsxEscritor. */
final class LectorXlsx
{
    /** @return array<string,string> partes del paquete */
    public static function partes(string $zip): array
    {
        $fin = strrpos($zip, "PK\x05\x06");
        if ($fin === false) { throw new \RuntimeException('El archivo no es un ZIP/XLSX válido'); }
        $e = unpack('vdisco/vdirdisco/vn/vtotal/Vtam/Vpos', substr($zip, $fin + 4, 18));
        $salida = []; $p = $e['pos'];
        for ($i = 0; $i < $e['total']; $i++) {
            $c = unpack('Vsig/vver/vneed/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vnom/vextra/vcom/vdisk/vint/Vext/Vrel', substr($zip, $p, 46));
            $nombre = substr($zip, $p + 46, $c['nom']);
            $l = unpack('vnom/vextra', substr($zip, $c['rel'] + 26, 4));
            $datos = substr($zip, $c['rel'] + 30 + $l['nom'] + $l['extra'], $c['comp']);
            $plano = $c['metodo'] === 8 ? gzinflate($datos) : $datos;
            if (strlen($plano) !== $c['tam'] || crc32($plano) !== $c['crc']) { throw new \RuntimeException("CRC/tamaño inválido en $nombre"); }
            $salida[$nombre] = $plano;
            $p += 46 + $c['nom'] + $c['extra'] + $c['com'];
        }
        return $salida;
    }

    /**
     * Filas de la primera hoja como arreglos de celdas (texto en línea o número tal como está escrito). Las filas vacías se conservan.
     * @return array<int,array<int,string>>
     */
    public static function filas(string $zip): array
    {
        $xml = simplexml_load_string(self::partes($zip)['xl/worksheets/sheet1.xml']);
        if ($xml === false) { throw new \RuntimeException('Hoja XML mal formada'); }
        $ns = $xml->getNamespaces(true)[''] ?? '';
        $xml->registerXPathNamespace('m', $ns);
        $filas = [];
        foreach ($xml->xpath('//m:sheetData/m:row') as $row) {
            $fila = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                preg_match('/^([A-Z]+)/', $ref, $m);
                $col = 0; foreach (str_split($m[1]) as $ch) { $col = $col * 26 + ord($ch) - 64; }
                $fila[$col - 1] = (string)$c['t'] === 'inlineStr' ? (string)$c->is->t : (string)$c->v;
            }
            if ($fila) { $max = max(array_keys($fila)); for ($i = 0; $i <= $max; $i++) { $fila[$i] ??= ''; } ksort($fila); }
            $filas[] = array_values($fila);
        }
        return $filas;
    }
}
