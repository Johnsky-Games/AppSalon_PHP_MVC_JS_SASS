<?php
namespace Classes\Exportacion;

/**
 * Escritor ZIP mínimo y en flujo (sin ext-zip: el contenedor de producción no la instala). Entradas pequeñas con tamaño conocido y
 * una entrada grande por flujo con descriptor de datos (bit 3): la memoria usada no depende del número de filas.
 */
final class EscritorZip
{
    /** @var resource */
    private $salida;
    private int $posicion = 0;
    /** @var array<int,array{nombre:string,crc:int,comp:int,tam:int,pos:int,flags:int,metodo:int}> */
    private array $central = [];
    private ?array $abierta = null;
    private int $dosHora;
    private int $dosFecha;

    /** @param resource $salida */
    public function __construct($salida, ?\DateTimeImmutable $ahora = null)
    {
        $this->salida = $salida;
        $t = ($ahora ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->dosHora = ((int)$t->format('G') << 11) | ((int)$t->format('i') << 5) | intdiv((int)$t->format('s'), 2);
        $this->dosFecha = (max(0, (int)$t->format('Y') - 1980) << 9) | ((int)$t->format('n') << 5) | (int)$t->format('j');
    }

    private function escribir(string $bytes): void
    {
        if ($bytes === '') { return; }
        $n = fwrite($this->salida, $bytes);
        if ($n !== strlen($bytes)) { throw new \RuntimeException('No se pudo escribir el archivo.'); }
        $this->posicion += $n;
    }

    /** Entrada completa (tamaño conocido). */
    public function agregar(string $nombre, string $contenido): void
    {
        $crc = crc32($contenido);
        $comp = $contenido;
        $metodo = 0;
        if (function_exists('gzdeflate') && strlen($contenido) > 128) {
            $d = gzdeflate($contenido, 6);
            if ($d !== false && strlen($d) < strlen($contenido)) { $comp = $d; $metodo = 8; }
        }
        $pos = $this->posicion;
        $this->escribir($this->cabeceraLocal($nombre, 0x0800, $metodo, $crc, strlen($comp), strlen($contenido)));
        $this->escribir($comp);
        $this->central[] = ['nombre' => $nombre, 'crc' => $crc, 'comp' => strlen($comp), 'tam' => strlen($contenido), 'pos' => $pos, 'flags' => 0x0800, 'metodo' => $metodo];
    }

    /** Abre una entrada por flujo; se alimenta con escribirEntrada() y se cierra con cerrarEntrada(). */
    public function abrirEntrada(string $nombre): void
    {
        if ($this->abierta !== null) { throw new \LogicException('Ya hay una entrada abierta.'); }
        $usaDeflate = function_exists('deflate_init');
        $this->abierta = [
            'nombre' => $nombre, 'pos' => $this->posicion, 'tam' => 0, 'comp' => 0, 'metodo' => $usaDeflate ? 8 : 0,
            'crc' => hash_init('crc32b'), 'def' => $usaDeflate ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null,
        ];
        $this->escribir($this->cabeceraLocal($nombre, 0x0808, $this->abierta['metodo'], 0, 0, 0));
    }

    public function escribirEntrada(string $datos): void
    {
        if ($this->abierta === null) { throw new \LogicException('No hay entrada abierta.'); }
        if ($datos === '') { return; }
        hash_update($this->abierta['crc'], $datos);
        $this->abierta['tam'] += strlen($datos);
        $salida = $this->abierta['def'] !== null ? deflate_add($this->abierta['def'], $datos, ZLIB_NO_FLUSH) : $datos;
        $this->abierta['comp'] += strlen($salida);
        $this->escribir($salida);
    }

    public function cerrarEntrada(): void
    {
        if ($this->abierta === null) { throw new \LogicException('No hay entrada abierta.'); }
        $a = $this->abierta;
        if ($a['def'] !== null) {
            $resto = deflate_add($a['def'], '', ZLIB_FINISH);
            $a['comp'] += strlen($resto);
            $this->escribir($resto);
        }
        $crc = unpack('N', hash_final($a['crc'], true))[1];
        $this->escribir(pack('VVVV', 0x08074B50, $crc, $a['comp'], $a['tam']));
        $this->central[] = ['nombre' => $a['nombre'], 'crc' => $crc, 'comp' => $a['comp'], 'tam' => $a['tam'], 'pos' => $a['pos'], 'flags' => 0x0808, 'metodo' => $a['metodo']];
        $this->abierta = null;
    }

    public function cerrar(): void
    {
        if ($this->abierta !== null) { $this->cerrarEntrada(); }
        $inicio = $this->posicion;
        foreach ($this->central as $e) {
            $this->escribir(pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $e['flags'], $e['metodo'], $this->dosHora, $this->dosFecha, $e['crc'], $e['comp'], $e['tam'], strlen($e['nombre']), 0, 0, 0, 0, 0, $e['pos']) . $e['nombre']);
        }
        $tamCentral = $this->posicion - $inicio;
        $this->escribir(pack('VvvvvVVv', 0x06054B50, 0, 0, count($this->central), count($this->central), $tamCentral, $inicio, 0));
    }

    private function cabeceraLocal(string $nombre, int $flags, int $metodo, int $crc, int $comp, int $tam): string
    {
        return pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $metodo, $this->dosHora, $this->dosFecha, $crc, $comp, $tam, strlen($nombre), 0) . $nombre;
    }
}
