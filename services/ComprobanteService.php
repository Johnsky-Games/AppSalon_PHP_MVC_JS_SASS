<?php
namespace Services;

use Repositories\ComprobanteRepository;
use Repositories\OperacionRepository;

/**
 * Comprobantes INTERNOS de cobro y de devolución. No son factura electrónica ni documento autorizado por el SRI.
 *
 * Se emiten dentro de la misma transacción que el movimiento que documentan (cobro confirmado, transferencia verificada o
 * devolución). La numeración es un contador correlativo bloqueado FOR UPDATE: lo último que se hace en la transacción, de modo
 * que el candado se sostiene el mínimo tiempo, y un fallo que revierte el cobro revierte también el número (sin huecos).
 * El contenido emitido es una instantánea inmutable: lo que se imprime es lo que se emitió.
 */
final class ComprobanteService
{
    public const SERIE = 'CI';
    public const LEYENDA = 'Comprobante interno — no es factura autorizada por el SRI';
    public const VERSION_CONTENIDO = 1;

    private OperacionRepository $repo;
    private ComprobanteRepository $comprobantes;

    public function __construct(?OperacionRepository $repo = null)
    {
        $this->repo = $repo ?? new OperacionRepository();
        $this->comprobantes = new ComprobanteRepository($this->repo);
    }

    /**
     * Emite un comprobante con la foto financiera `$detalle` (resultado de CobroCitaService::detalle tras el movimiento).
     * Debe llamarse DENTRO de la transacción del movimiento.
     * @param array<string,mixed> $detalle
     * @return array{id:int,codigo:string,numero:int}
     */
    public function emitir(string $tipo, array $detalle, ?int $pagoId, ?int $devolucionId, int $actor): array
    {
        if (!in_array($tipo, ['cobro', 'devolucion'], true)) { throw new \InvalidArgumentException('Tipo de comprobante inválido.'); }
        $ahora = OperacionRepository::ahora();
        $contenido = $this->contenido($tipo, $detalle, $pagoId, $devolucionId, $actor, $ahora);
        $json = json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $numero = $this->comprobantes->siguienteNumero(self::SERIE);
        $codigo = self::codigo(self::SERIE, $numero);
        $id = $this->comprobantes->insertar([
            'serie' => self::SERIE, 'numero' => $numero, 'codigo' => $codigo, 'tipo' => $tipo, 'cita_id' => (int)$detalle['cita']['id'], 'pago_id' => $pagoId, 'devolucion_id' => $devolucionId,
            'emitido_por' => $actor, 'emitido_en' => $ahora, 'total' => (int)$detalle['total_centavos'], 'cobrado' => (int)$detalle['cobrado_centavos'], 'devuelto' => (int)$detalle['devuelto_centavos'],
            'saldo' => (int)$detalle['saldo_centavos'], 'json' => $json, 'sha256' => hash('sha256', $json),
        ]);
        return ['id' => $id, 'codigo' => $codigo, 'numero' => $numero];
    }

    public static function codigo(string $serie, int $numero): string
    {
        return $serie . '-' . str_pad((string)$numero, 6, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string,mixed>> comprobantes de la cita, del más reciente al más antiguo (sin contenido). */
    public function deCita(int $citaId): array
    {
        return $this->comprobantes->deCita($citaId);
    }

    /** Comprobante con su contenido decodificado, o null. Comprueba además que el SHA-256 guardado coincida (integridad). */
    public function obtener(int $id): ?array
    {
        $f = $this->comprobantes->porId($id);
        if (!$f) { return null; }
        $f['integro'] = hash_equals((string)$f['contenido_sha256'], hash('sha256', (string)$f['contenido_json']));
        $f['contenido'] = json_decode((string)$f['contenido_json'], true) ?: [];
        unset($f['contenido_json']);
        return $f;
    }

    /** @param array<string,mixed> $d */
    private function contenido(string $tipo, array $d, ?int $pagoId, ?int $devolucionId, int $actor, string $ahora): array
    {
        $emisor = $this->repo->consulta('SELECT nombre,apellido FROM usuarios WHERE id=?', 'i', [$actor])[0] ?? [];
        $negocio = 'App Salón';
        try {
            $a = (new \Repositories\CrecimientoRepository())->ajustes();
            $negocio = trim((string)($a['nombre_comercial'] ?? '')) ?: $negocio;
        } catch (\Throwable) { /* el comprobante no depende de que existan ajustes */ }
        return [
            'version' => self::VERSION_CONTENIDO, 'leyenda' => self::LEYENDA, 'tipo' => $tipo, 'negocio' => $negocio,
            'emitido_en' => $ahora, 'emitido_por' => trim((string)($emisor['nombre'] ?? '') . ' ' . (string)($emisor['apellido'] ?? '')),
            'movimiento' => ['pago_id' => $pagoId, 'devolucion_id' => $devolucionId],
            'cita' => $d['cita'],
            'servicios' => array_map(static fn(array $s): array => ['nombre' => $s['nombre'], 'precio_centavos' => $s['precio_centavos']], $d['servicios']),
            'pagos' => array_map(static fn(array $p): array => [
                'id' => $p['id'], 'referencia' => $p['referencia'], 'metodo' => $p['metodo'], 'estado' => $p['estado'], 'estado_verificacion' => $p['estado_verificacion'], 'simulado' => $p['simulado'],
                'monto_centavos' => $p['monto_centavos'], 'devuelto_centavos' => $p['devuelto_centavos'], 'recibido_centavos' => $p['recibido_centavos'], 'cambio_centavos' => $p['cambio_centavos'],
                'referencia_transferencia' => $p['referencia_transferencia'], 'pagado_at' => $p['pagado_at'], 'verificado_en' => $p['verificado_en'], 'verificado_por' => $p['verificado_por_nombre'],
            ], $d['pagos']),
            'devoluciones' => array_map(static fn(array $v): array => ['id' => $v['id'], 'pago_id' => $v['pago_id'], 'monto_centavos' => $v['monto_centavos'], 'metodo' => $v['metodo'], 'motivo' => $v['motivo'], 'registrado_en' => $v['registrado_en']], $d['devoluciones']),
            'totales' => ['total_centavos' => $d['total_centavos'], 'cobrado_centavos' => $d['cobrado_centavos'], 'cobrado_real_centavos' => $d['cobrado_real_centavos'], 'cobrado_simulado_centavos' => $d['cobrado_simulado_centavos'],
                'devuelto_centavos' => $d['devuelto_centavos'], 'pendiente_verificacion_centavos' => $d['pendiente_verificacion_centavos'], 'saldo_centavos' => $d['saldo_centavos'], 'estado' => $d['estado']],
        ];
    }
}
