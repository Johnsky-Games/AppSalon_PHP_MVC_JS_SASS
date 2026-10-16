<?php

namespace Model;

/**
 * Entidad de dominio para el registro, control de idempotencia y reintentos
 * de notificaciones transaccionales de citas (`notificaciones_citas`).
 *
 * Desacoplada de `ActiveRecord`; toda su persistencia se realiza en `Repositories\NotificacionCitaRepository`.
 */
class NotificacionCita
{
    public const TIPO_CONFIRMACION = 'confirmacion';
    public const TIPO_CANCELACION = 'cancelacion';
    public const TIPO_RECORDATORIO = 'recordatorio';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_ENVIANDO = 'enviando';
    public const ESTADO_ENVIADO = 'enviado';
    public const ESTADO_FALLIDO = 'fallido';
    public const ESTADO_REINTENTANDO = 'reintentando';
    public const ESTADO_DESCARTADA = 'descartada';
    public const ESTADO_CANCELADA = 'cancelada';

    public const DEFAULT_MAX_INTENTOS = 3;

    public ?int $id = null;
    public ?int $cita_id = null;
    public int $cita_referencia_id = 0;
    public ?int $usuario_id = null;
    public string $tipo = '';
    public string $clave_idempotencia = '';
    public string $destinatario_email = '';
    public string $destinatario_nombre = '';
    public string $asunto = '';
    public ?string $payload_json = null;
    public string $estado = self::ESTADO_PENDIENTE;
    public int $intentos = 0;
    public int $max_intentos = self::DEFAULT_MAX_INTENTOS;
    public ?string $ultimo_error = null;
    public ?string $enviado_at = null;
    public ?string $proximo_intento_at = null;
    public ?string $worker_token = null;
    public ?string $lease_expires_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function __construct(array $args = [])
    {
        $this->id = isset($args['id']) && $args['id'] !== null && $args['id'] !== ''
            ? (int)$args['id']
            : null;
        $this->cita_id = isset($args['cita_id']) && $args['cita_id'] !== null && $args['cita_id'] !== ''
            ? (int)$args['cita_id']
            : null;
        $this->cita_referencia_id = isset($args['cita_referencia_id']) && $args['cita_referencia_id'] !== null && $args['cita_referencia_id'] !== ''
            ? (int)$args['cita_referencia_id']
            : ($this->cita_id ?? 0);
        $this->usuario_id = isset($args['usuario_id']) && $args['usuario_id'] !== null && $args['usuario_id'] !== ''
            ? (int)$args['usuario_id']
            : null;
        $this->tipo = is_scalar($args['tipo'] ?? null) ? trim((string)$args['tipo']) : '';
        $this->clave_idempotencia = is_scalar($args['clave_idempotencia'] ?? null) ? trim((string)$args['clave_idempotencia']) : '';
        $this->destinatario_email = is_scalar($args['destinatario_email'] ?? null) ? trim((string)$args['destinatario_email']) : '';
        $this->destinatario_nombre = is_scalar($args['destinatario_nombre'] ?? null) ? trim((string)$args['destinatario_nombre']) : '';
        $this->asunto = is_scalar($args['asunto'] ?? null) ? trim((string)$args['asunto']) : '';

        if (isset($args['payload_json'])) {
            if (is_array($args['payload_json'])) {
                $encoded = json_encode($args['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->payload_json = $encoded !== false ? $encoded : null;
            } elseif (is_string($args['payload_json']) && trim($args['payload_json']) !== '') {
                $this->payload_json = $args['payload_json'];
            } else {
                $this->payload_json = null;
            }
        }

        $this->estado = is_scalar($args['estado'] ?? null) && trim((string)$args['estado']) !== ''
            ? trim((string)$args['estado'])
            : self::ESTADO_PENDIENTE;
        $this->intentos = isset($args['intentos']) ? max(0, (int)$args['intentos']) : 0;
        $this->max_intentos = isset($args['max_intentos']) ? max(1, (int)$args['max_intentos']) : self::DEFAULT_MAX_INTENTOS;
        $this->ultimo_error = isset($args['ultimo_error']) && is_scalar($args['ultimo_error']) && trim((string)$args['ultimo_error']) !== ''
            ? trim((string)$args['ultimo_error'])
            : null;
        $this->enviado_at = isset($args['enviado_at']) && is_scalar($args['enviado_at']) && trim((string)$args['enviado_at']) !== ''
            ? trim((string)$args['enviado_at'])
            : null;
        $this->proximo_intento_at = isset($args['proximo_intento_at']) && is_scalar($args['proximo_intento_at']) && trim((string)$args['proximo_intento_at']) !== ''
            ? trim((string)$args['proximo_intento_at'])
            : null;
        $this->worker_token = isset($args['worker_token']) && is_scalar($args['worker_token']) && trim((string)$args['worker_token']) !== ''
            ? trim((string)$args['worker_token'])
            : null;
        $this->lease_expires_at = isset($args['lease_expires_at']) && is_scalar($args['lease_expires_at']) && trim((string)$args['lease_expires_at']) !== ''
            ? trim((string)$args['lease_expires_at'])
            : null;
        $this->created_at = isset($args['created_at']) && is_scalar($args['created_at']) && trim((string)$args['created_at']) !== ''
            ? trim((string)$args['created_at'])
            : null;
        $this->updated_at = isset($args['updated_at']) && is_scalar($args['updated_at']) && trim((string)$args['updated_at']) !== ''
            ? trim((string)$args['updated_at'])
            : null;
    }

    /**
     * Decodifica el JSON de `payload_json` en un arreglo asociativo.
     *
     * @return array<string, mixed>
     */
    public function obtenerPayload(): array
    {
        if ($this->payload_json === null || trim($this->payload_json) === '') {
            return [];
        }

        $decoded = json_decode($this->payload_json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
