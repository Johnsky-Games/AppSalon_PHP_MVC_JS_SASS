<?php

namespace Model;

/**
 * Entidad de dominio para la tabla `pagos` (Fase 6C — Base para Pagos).
 * Desacoplada de `ActiveRecord`.
 */
class Pago
{
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_PROCESANDO = 'procesando';
    public const ESTADO_COMPLETADO = 'completado';
    public const ESTADO_FALLIDO = 'fallido';
    public const ESTADO_REEMBOLSADO = 'reembolsado';

    public const MONEDA_USD = 'USD';

    public ?int $id = null;
    public ?int $cita_id = null;
    public int $cita_referencia_id = 0;
    public ?int $usuario_id = null;
    public string $referencia_pago = '';
    public string $proveedor = 'mock';
    public ?string $transaccion_externa_id = null;
    public float $monto = 0.00;
    public string $moneda = self::MONEDA_USD;
    public string $estado = self::ESTADO_PENDIENTE;
    public ?string $metodo_pago = null;
    public string $idempotency_key = '';
    public ?string $payload_proveedor = null;
    public ?string $pagado_at = null;
    public ?string $reembolsado_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $args
     */
    public function __construct(array $args = [])
    {
        $this->id = isset($args['id']) && $args['id'] !== null && $args['id'] !== '' ? (int)$args['id'] : null;
        $this->cita_id = array_key_exists('cita_id', $args) && $args['cita_id'] !== null && $args['cita_id'] !== ''
            ? (int)$args['cita_id']
            : null;
        $this->cita_referencia_id = isset($args['cita_referencia_id']) ? (int)$args['cita_referencia_id'] : (int)($this->cita_id ?? 0);
        $this->usuario_id = array_key_exists('usuario_id', $args) && $args['usuario_id'] !== null && $args['usuario_id'] !== ''
            ? (int)$args['usuario_id']
            : null;
        $this->referencia_pago = trim((string)($args['referencia_pago'] ?? ''));
        $this->proveedor = trim((string)($args['proveedor'] ?? 'mock')) ?: 'mock';
        $this->transaccion_externa_id = isset($args['transaccion_externa_id']) && trim((string)$args['transaccion_externa_id']) !== ''
            ? trim((string)$args['transaccion_externa_id'])
            : null;
        $this->monto = isset($args['monto']) && is_numeric($args['monto'])
            ? round((float)$args['monto'], 2)
            : 0.00;
        $this->moneda = strtoupper(trim((string)($args['moneda'] ?? self::MONEDA_USD))) ?: self::MONEDA_USD;
        $this->estado = trim((string)($args['estado'] ?? self::ESTADO_PENDIENTE)) ?: self::ESTADO_PENDIENTE;
        $this->metodo_pago = isset($args['metodo_pago']) && trim((string)$args['metodo_pago']) !== ''
            ? trim((string)$args['metodo_pago'])
            : null;
        $this->idempotency_key = trim((string)($args['idempotency_key'] ?? ''));
        $this->payload_proveedor = isset($args['payload_proveedor']) && $args['payload_proveedor'] !== null
            ? (is_string($args['payload_proveedor'])
                ? $args['payload_proveedor']
                : (json_encode($args['payload_proveedor'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null))
            : null;
        $this->pagado_at = isset($args['pagado_at']) && $args['pagado_at'] !== null && $args['pagado_at'] !== ''
            ? (string)$args['pagado_at']
            : null;
        $this->reembolsado_at = isset($args['reembolsado_at']) && $args['reembolsado_at'] !== null && $args['reembolsado_at'] !== ''
            ? (string)$args['reembolsado_at']
            : null;
        $this->created_at = isset($args['created_at']) && $args['created_at'] !== null
            ? (string)$args['created_at']
            : null;
        $this->updated_at = isset($args['updated_at']) && $args['updated_at'] !== null
            ? (string)$args['updated_at']
            : null;
    }

    /**
     * Determina si el pago se encuentra en un estado terminal que no admite transiciones posteriores.
     */
    public function esTerminal(): bool
    {
        return $this->estado === self::ESTADO_REEMBOLSADO;
    }

    /**
     * Valida si el registro coincide exactamente con la huella de operación (usuario, cita y monto),
     * previniendo colisiones e intentos de reutilización de idempotency_key cross-tenant.
     */
    public function coincideConOperacion(int $usuarioId, int $citaId, float $monto): bool
    {
        $citaCoincide = ((int)$this->cita_id === $citaId) || ((int)$this->cita_referencia_id === $citaId);
        $usuarioCoincide = ($this->usuario_id !== null && (int)$this->usuario_id === $usuarioId);
        $montoCoincide = abs($this->monto - round($monto, 2)) < 0.01;

        return $citaCoincide && $usuarioCoincide && $montoCoincide;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cita_id' => $this->cita_id,
            'cita_referencia_id' => $this->cita_referencia_id,
            'usuario_id' => $this->usuario_id,
            'referencia_pago' => $this->referencia_pago,
            'proveedor' => $this->proveedor,
            'transaccion_externa_id' => $this->transaccion_externa_id,
            'monto' => $this->monto,
            'moneda' => $this->moneda,
            'estado' => $this->estado,
            'metodo_pago' => $this->metodo_pago,
            'idempotency_key' => $this->idempotency_key,
            'pagado_at' => $this->pagado_at,
            'reembolsado_at' => $this->reembolsado_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
