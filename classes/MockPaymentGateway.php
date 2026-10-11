<?php

namespace Classes;

use Interfaces\PaymentGatewayInterface;

/**
 * Proveedor de pagos simulado (Mock / Sandbox) para Fase 6C.
 *
 * Implementa `PaymentGatewayInterface` sin realizar cobros reales ni depender de
 * credenciales comerciales externas:
 * - Soporta modos de simulación: aprobación (`aprobado`), rechazo por fondos insuficientes
 *   (`fondos_insuficientes`) y fallo de comunicación (`error_comunicacion`).
 * - Valida firmas criptográficas HMAC-SHA256 en notificaciones webhook (`X-Mock-Signature`).
 * - Prohíbe y sanea cualquier dato sensible de tarjeta (número PAN, CVV, fecha de expiración).
 */
class MockPaymentGateway implements PaymentGatewayInterface
{
    public const PROVEEDOR = 'mock';

    public const MODO_APROBADO = 'aprobado';
    public const MODO_FONDOS_INSUFICIENTES = 'fondos_insuficientes';
    public const MODO_ERROR_COMUNICACION = 'error_comunicacion';

    public const DEFAULT_WEBHOOK_SECRET = 'appsalon_mock_webhook_secret_2026';

    /**
     * Lista de nombres de campos prohibidos por contener datos sensibles de tarjetas (PCI-DSS).
     *
     * @var array<int, string>
     */
    public const CAMPOS_TARJETA_PROHIBIDOS = [
        'numero_tarjeta',
        'card_number',
        'cardnumber',
        'pan',
        'tarjeta',
        'tarjeta_numero',
        'cc_number',
        'cvv',
        'cvc',
        'cvv2',
        'cid',
        'codigo_seguridad',
        'security_code',
        'fecha_expiracion',
        'fecha_vencimiento',
        'expiracion',
        'vencimiento',
        'expiry',
        'expiration_date',
        'exp_month',
        'exp_year',
        'track_data',
        'track1',
        'track2',
        'pin',
    ];

    private string $webhookSecret;
    private string $modoSimulacion;

    /**
     * Registro en memoria de transacciones simuladas para verificación server-to-server.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $transacciones = [];

    public function __construct(?string $webhookSecret = null, string $modoSimulacion = self::MODO_APROBADO)
    {
        $envSecret = $_ENV['PAYMENT_WEBHOOK_SECRET'] ?? getenv('PAYMENT_WEBHOOK_SECRET') ?: '';
        $this->webhookSecret = trim((string)($webhookSecret ?? ($envSecret !== '' ? $envSecret : self::DEFAULT_WEBHOOK_SECRET)));
        if ($this->webhookSecret === '') {
            $this->webhookSecret = self::DEFAULT_WEBHOOK_SECRET;
        }
        $this->modoSimulacion = self::normalizarModoSimulacion($modoSimulacion);
    }

    public function setModoSimulacion(string $modo): void
    {
        $this->modoSimulacion = self::normalizarModoSimulacion($modo);
    }

    public function getModoSimulacion(): string
    {
        return $this->modoSimulacion;
    }

    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public static function normalizarModoSimulacion(string $modo): string
    {
        $limpio = strtolower(trim($modo));
        if (in_array($limpio, ['fondos_insuficientes', 'insufficient_funds', 'rechazado', 'declined'], true)) {
            return self::MODO_FONDOS_INSUFICIENTES;
        }
        if (in_array($limpio, ['error_comunicacion', 'communication_error', 'timeout', 'network_error'], true)) {
            return self::MODO_ERROR_COMUNICACION;
        }
        return self::MODO_APROBADO;
    }

    /**
     * Detecta si un arreglo contiene claves o valores con datos sensibles de tarjetas
     * (PAN de 13 a 19 dígitos, CVV o fechas de expiración).
     *
     * @param array<string, mixed> $datos
     */
    public static function contieneDatosTarjetaSensibles(array $datos): bool
    {
        foreach ($datos as $clave => $valor) {
            $claveNorm = strtolower(trim((string)$clave));
            if (in_array($claveNorm, self::CAMPOS_TARJETA_PROHIBIDOS, true)) {
                return true;
            }

            if (is_array($valor)) {
                if (self::contieneDatosTarjetaSensibles($valor)) {
                    return true;
                }
                continue;
            }

            if (is_string($valor) || is_numeric($valor)) {
                $strVal = trim((string)$valor);
                // Detectar secuencias tipo PAN de tarjeta (13 a 19 dígitos continuos o separados por espacios/guiones)
                if (
                    in_array($claveNorm, ['metodo_pago', 'notas', 'detalle', 'payload', 'token', 'datos_pago'], true)
                    && preg_match('/(?:\d[ -]*?){13,19}/', $strVal)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Elimina recursivamente cualquier campo de tarjeta prohibido y enmascara secuencias PAN
     * para garantizar que jamás se almacenen datos de tarjetas en la base de datos.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function sanearPayloadSinDatosTarjeta(array $payload): array
    {
        $limpio = [];
        foreach ($payload as $clave => $valor) {
            $claveStr = (string)$clave;
            $claveNorm = strtolower(trim($claveStr));

            if (in_array($claveNorm, self::CAMPOS_TARJETA_PROHIBIDOS, true)) {
                continue;
            }

            if (is_array($valor)) {
                $limpio[$claveStr] = self::sanearPayloadSinDatosTarjeta($valor);
                continue;
            }

            if (is_string($valor)) {
                // Neutralizar cualquier secuencia de 13-19 dígitos que parezca PAN
                $limpio[$claveStr] = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[REDACTED_PAN]', $valor) ?? $valor;
                continue;
            }

            $limpio[$claveStr] = $valor;
        }

        return $limpio;
    }

    /**
     * Genera la firma HMAC-SHA256 canónica para un payload de webhook del proveedor simulado.
     *
     * @param array<string, mixed> $payload
     */
    public function generarFirmaWebhook(array $payload): string
    {
        $referencia = trim((string)($payload['referencia_pago'] ?? $payload['referencia'] ?? ''));
        $evento = trim((string)($payload['evento'] ?? $payload['event'] ?? 'payment.succeeded'));
        $estado = strtolower(trim((string)($payload['estado'] ?? $payload['status'] ?? 'completado')));
        $montoRaw = $payload['monto'] ?? $payload['amount'] ?? null;
        $montoStr = $montoRaw !== null && is_numeric($montoRaw)
            ? number_format((float)$montoRaw, 2, '.', '')
            : '';

        $mensajeCanonico = "{$referencia}|{$evento}|{$estado}|{$montoStr}";
        return hash_hmac('sha256', $mensajeCanonico, $this->webhookSecret);
    }

    /**
     * Verifica la firma HMAC-SHA256 recibida en las cabeceras del webhook.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public function verificarFirmaWebhook(array $payload, array $headers): bool
    {
        $firmaRecibida = '';
        $clavesCabecera = [
            'X-Mock-Signature',
            'x-mock-signature',
            'HTTP_X_MOCK_SIGNATURE',
            'X-Webhook-Signature',
            'x-webhook-signature',
            'HTTP_X_WEBHOOK_SIGNATURE',
        ];

        foreach ($clavesCabecera as $clave) {
            if (isset($headers[$clave]) && is_scalar($headers[$clave])) {
                $firmaRecibida = trim((string)$headers[$clave]);
                if ($firmaRecibida !== '') {
                    break;
                }
            }
        }

        if ($firmaRecibida === '') {
            return false;
        }

        // Soportar prefijo opcional 'sha256='
        if (str_starts_with(strtolower($firmaRecibida), 'sha256=')) {
            $firmaRecibida = substr($firmaRecibida, 7);
        }

        $appEnv = strtolower(trim((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development')));
        if ($appEnv === 'production') {
            $secretosInseguros = [
                '',
                self::DEFAULT_WEBHOOK_SECRET,
                'mock_secret_cambiar_en_produccion',
                'mock_secret',
            ];
            if (in_array($this->webhookSecret, $secretosInseguros, true)) {
                return false;
            }
        }

        $firmaCanonica = $this->generarFirmaWebhook($payload);
        if (hash_equals($firmaCanonica, $firmaRecibida)) {
            return true;
        }

        // Soportar también HMAC sobre el JSON serializado del payload (sin clave 'signature')
        $copiaPayload = $payload;
        unset($copiaPayload['signature'], $copiaPayload['firma']);
        $jsonPayload = json_encode($copiaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($jsonPayload)) {
            $firmaJson = hash_hmac('sha256', $jsonPayload, $this->webhookSecret);
            if (hash_equals($firmaJson, $firmaRecibida)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function iniciarPago(array $datosPago): array
    {
        $referenciaPago = trim((string)($datosPago['referencia_pago'] ?? ''));
        $monto = isset($datosPago['monto']) && is_numeric($datosPago['monto'])
            ? round((float)$datosPago['monto'], 2)
            : 0.00;
        $moneda = strtoupper(trim((string)($datosPago['moneda'] ?? 'USD'))) ?: 'USD';
        $metodoPago = trim((string)($datosPago['metodo_pago'] ?? 'tarjeta_mock')) ?: 'tarjeta_mock';

        $modo = isset($datosPago['modo_simulacion']) && is_string($datosPago['modo_simulacion'])
            ? self::normalizarModoSimulacion($datosPago['modo_simulacion'])
            : $this->modoSimulacion;

        if ($modo === self::MODO_ERROR_COMUNICACION) {
            return [
                'ok' => false,
                'proveedor' => self::PROVEEDOR,
                'estado' => 'error_comunicacion',
                'transaccion_externa_id' => null,
                'redirect_url' => null,
                'client_token' => null,
                'codigo_error' => 'GATEWAY_COMMUNICATION_ERROR',
                'mensaje' => 'Error de comunicación con la pasarela de pagos simulada.',
                'payload_sanitizado' => [
                    'proveedor' => self::PROVEEDOR,
                    'modo_simulacion' => $modo,
                    'codigo_error' => 'GATEWAY_COMMUNICATION_ERROR',
                ],
            ];
        }

        $txExternaId = 'MOCK-TX-' . strtoupper(bin2hex(random_bytes(8)));

        if ($modo === self::MODO_FONDOS_INSUFICIENTES) {
            $payloadSanitizado = self::sanearPayloadSinDatosTarjeta([
                'proveedor' => self::PROVEEDOR,
                'transaccion_externa_id' => $txExternaId,
                'referencia_pago' => $referenciaPago,
                'estado_proveedor' => 'declined',
                'codigo_error' => 'INSUFFICIENT_FUNDS',
                'metodo_pago' => $metodoPago,
                'monto' => $monto,
                'moneda' => $moneda,
            ]);

            $this->transacciones[$txExternaId] = [
                'referencia_pago' => $referenciaPago,
                'transaccion_externa_id' => $txExternaId,
                'estado' => 'fallido',
                'monto' => $monto,
                'moneda' => $moneda,
                'codigo_error' => 'INSUFFICIENT_FUNDS',
            ];
            if ($referenciaPago !== '') {
                $this->transacciones[$referenciaPago] = $this->transacciones[$txExternaId];
            }

            return [
                'ok' => false,
                'proveedor' => self::PROVEEDOR,
                'estado' => 'fallido',
                'transaccion_externa_id' => $txExternaId,
                'redirect_url' => '/pago/resultado?referencia=' . rawurlencode($referenciaPago),
                'client_token' => null,
                'codigo_error' => 'INSUFFICIENT_FUNDS',
                'mensaje' => 'Pago rechazado por el proveedor simulado: fondos insuficientes.',
                'payload_sanitizado' => $payloadSanitizado,
            ];
        }

        $clientToken = 'tok_mock_' . bin2hex(random_bytes(12));
        $redirectUrl = '/pago/resultado?referencia=' . rawurlencode($referenciaPago);

        $payloadSanitizado = self::sanearPayloadSinDatosTarjeta([
            'proveedor' => self::PROVEEDOR,
            'transaccion_externa_id' => $txExternaId,
            'referencia_pago' => $referenciaPago,
            'estado_proveedor' => 'pending_confirmation',
            'metodo_pago' => $metodoPago,
            'monto' => $monto,
            'moneda' => $moneda,
            'redirect_url' => $redirectUrl,
        ]);

        $this->transacciones[$txExternaId] = [
            'referencia_pago' => $referenciaPago,
            'transaccion_externa_id' => $txExternaId,
            'estado' => 'pendiente',
            'monto' => $monto,
            'moneda' => $moneda,
            'codigo_error' => null,
        ];
        if ($referenciaPago !== '') {
            $this->transacciones[$referenciaPago] = $this->transacciones[$txExternaId];
        }

        return [
            'ok' => true,
            'proveedor' => self::PROVEEDOR,
            'estado' => 'pendiente',
            'transaccion_externa_id' => $txExternaId,
            'redirect_url' => $redirectUrl,
            'client_token' => $clientToken,
            'codigo_error' => null,
            'mensaje' => 'Intento de pago inicializado en servidor; pendiente de confirmación vía webhook o verificación server-to-server.',
            'payload_sanitizado' => $payloadSanitizado,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function verificarPago(string $referenciaExterna): array
    {
        $ref = trim($referenciaExterna);
        if ($this->modoSimulacion === self::MODO_ERROR_COMUNICACION) {
            return [
                'ok' => false,
                'proveedor' => self::PROVEEDOR,
                'referencia_externa' => $ref,
                'estado' => 'error_comunicacion',
                'monto' => null,
                'moneda' => 'USD',
                'codigo_error' => 'GATEWAY_COMMUNICATION_ERROR',
                'mensaje' => 'Error de comunicación al verificar estado con el proveedor simulado.',
                'payload_sanitizado' => [
                    'proveedor' => self::PROVEEDOR,
                    'referencia_externa' => $ref,
                    'codigo_error' => 'GATEWAY_COMMUNICATION_ERROR',
                ],
            ];
        }

        if ($ref === '' || !isset($this->transacciones[$ref])) {
            return [
                'ok' => false,
                'proveedor' => self::PROVEEDOR,
                'referencia_externa' => $ref,
                'estado' => 'no_encontrado',
                'monto' => null,
                'moneda' => 'USD',
                'codigo_error' => 'TRANSACTION_NOT_FOUND',
                'mensaje' => 'La transacción indicada no existe en el proveedor simulado.',
                'payload_sanitizado' => [
                    'proveedor' => self::PROVEEDOR,
                    'referencia_externa' => $ref,
                ],
            ];
        }

        $info = $this->transacciones[$ref];
        return [
            'ok' => true,
            'proveedor' => self::PROVEEDOR,
            'referencia_externa' => (string)$info['transaccion_externa_id'],
            'estado' => (string)$info['estado'],
            'monto' => isset($info['monto']) ? (float)$info['monto'] : null,
            'moneda' => (string)($info['moneda'] ?? 'USD'),
            'codigo_error' => $info['codigo_error'] ?? null,
            'mensaje' => 'Estado verificado directamente con el proveedor simulado.',
            'payload_sanitizado' => self::sanearPayloadSinDatosTarjeta($info),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function procesarNotificacionWebhook(array $payload, array $headers): array
    {
        $payloadLimpio = self::sanearPayloadSinDatosTarjeta($payload);
        $referenciaPago = trim((string)($payload['referencia_pago'] ?? $payload['referencia'] ?? ''));
        $evento = trim((string)($payload['evento'] ?? $payload['event'] ?? 'payment.succeeded'));
        $estadoRaw = strtolower(trim((string)($payload['estado'] ?? $payload['status'] ?? 'completado')));
        $txExternaId = isset($payload['transaccion_externa_id']) && trim((string)$payload['transaccion_externa_id']) !== ''
            ? trim((string)$payload['transaccion_externa_id'])
            : null;
        $monto = isset($payload['monto']) && is_numeric($payload['monto'])
            ? round((float)$payload['monto'], 2)
            : null;
        $moneda = strtoupper(trim((string)($payload['moneda'] ?? 'USD'))) ?: 'USD';
        $metodoPago = isset($payload['metodo_pago']) && trim((string)$payload['metodo_pago']) !== ''
            ? trim((string)$payload['metodo_pago'])
            : null;
        $idempotencyKey = isset($payload['idempotency_key']) && trim((string)$payload['idempotency_key']) !== ''
            ? trim((string)$payload['idempotency_key'])
            : null;

        if (!$this->verificarFirmaWebhook($payload, $headers)) {
            return [
                'ok' => false,
                'firma_valida' => false,
                'proveedor' => self::PROVEEDOR,
                'evento' => $evento,
                'referencia_pago' => $referenciaPago,
                'transaccion_externa_id' => $txExternaId,
                'estado' => 'rechazado_firma',
                'monto' => $monto,
                'moneda' => $moneda,
                'metodo_pago' => $metodoPago,
                'idempotency_key' => $idempotencyKey,
                'codigo_error' => 'INVALID_WEBHOOK_SIGNATURE',
                'mensaje' => 'Firma de notificación webhook inválida o ausente.',
                'payload_sanitizado' => $payloadLimpio,
            ];
        }

        $estadoNormalizado = 'pendiente';
        if (in_array($estadoRaw, ['completado', 'pagado', 'aprobado', 'succeeded', 'paid'], true)) {
            $estadoNormalizado = 'completado';
        } elseif (in_array($estadoRaw, ['fallido', 'rechazado', 'failed', 'declined', 'fondos_insuficientes'], true)) {
            $estadoNormalizado = 'fallido';
        } elseif (in_array($estadoRaw, ['reembolsado', 'refunded'], true)) {
            $estadoNormalizado = 'reembolsado';
        } elseif (in_array($estadoRaw, ['procesando', 'processing'], true)) {
            $estadoNormalizado = 'procesando';
        }

        if ($txExternaId === null && $referenciaPago !== '' && isset($this->transacciones[$referenciaPago]['transaccion_externa_id'])) {
            $txExternaId = (string)$this->transacciones[$referenciaPago]['transaccion_externa_id'];
        }
        if ($txExternaId === null) {
            $txExternaId = 'MOCK-WH-' . strtoupper(bin2hex(random_bytes(8)));
        }

        if ($referenciaPago !== '') {
            $this->transacciones[$referenciaPago] = [
                'referencia_pago' => $referenciaPago,
                'transaccion_externa_id' => $txExternaId,
                'estado' => $estadoNormalizado,
                'monto' => $monto,
                'moneda' => $moneda,
                'codigo_error' => $estadoNormalizado === 'fallido' ? 'PAYMENT_FAILED' : null,
            ];
            $this->transacciones[$txExternaId] = $this->transacciones[$referenciaPago];
        }

        return [
            'ok' => true,
            'firma_valida' => true,
            'proveedor' => self::PROVEEDOR,
            'evento' => $evento,
            'referencia_pago' => $referenciaPago,
            'transaccion_externa_id' => $txExternaId,
            'estado' => $estadoNormalizado,
            'monto' => $monto,
            'moneda' => $moneda,
            'metodo_pago' => $metodoPago,
            'idempotency_key' => $idempotencyKey,
            'codigo_error' => $estadoNormalizado === 'fallido' ? 'PAYMENT_FAILED' : null,
            'mensaje' => 'Notificación webhook validada correctamente.',
            'payload_sanitizado' => array_merge($payloadLimpio, [
                'evento_procesado' => $evento,
                'estado_normalizado' => $estadoNormalizado,
                'transaccion_externa_id' => $txExternaId,
            ]),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function reembolsarPago(string $referenciaExterna, float $monto): array
    {
        $ref = trim($referenciaExterna);
        $montoRedondeado = round(max(0.0, $monto), 2);

        if ($this->modoSimulacion === self::MODO_ERROR_COMUNICACION) {
            return [
                'ok' => false,
                'proveedor' => self::PROVEEDOR,
                'referencia_externa' => $ref,
                'reembolso_id' => null,
                'estado' => 'error_comunicacion',
                'monto' => $montoRedondeado,
                'codigo_error' => 'GATEWAY_COMMUNICATION_ERROR',
                'mensaje' => 'Error de comunicación al solicitar reembolso en el proveedor simulado.',
                'payload_sanitizado' => [
                    'proveedor' => self::PROVEEDOR,
                    'referencia_externa' => $ref,
                    'codigo_error' => 'GATEWAY_COMMUNICATION_ERROR',
                ],
            ];
        }

        if ($ref === '' || $montoRedondeado <= 0) {
            return [
                'ok' => false,
                'proveedor' => self::PROVEEDOR,
                'referencia_externa' => $ref,
                'reembolso_id' => null,
                'estado' => 'fallido',
                'monto' => $montoRedondeado,
                'codigo_error' => 'INVALID_REFUND_PARAMETERS',
                'mensaje' => 'Referencia externa o monto de reembolso inválido.',
                'payload_sanitizado' => [
                    'proveedor' => self::PROVEEDOR,
                    'referencia_externa' => $ref,
                ],
            ];
        }

        $reembolsoId = 'MOCK-REFUND-' . strtoupper(bin2hex(random_bytes(6)));
        if (isset($this->transacciones[$ref])) {
            $this->transacciones[$ref]['estado'] = 'reembolsado';
        }

        return [
            'ok' => true,
            'proveedor' => self::PROVEEDOR,
            'referencia_externa' => $ref,
            'reembolso_id' => $reembolsoId,
            'estado' => 'reembolsado',
            'monto' => $montoRedondeado,
            'codigo_error' => null,
            'mensaje' => 'Reembolso procesado exitosamente en el proveedor simulado.',
            'payload_sanitizado' => [
                'proveedor' => self::PROVEEDOR,
                'referencia_externa' => $ref,
                'reembolso_id' => $reembolsoId,
                'estado' => 'reembolsado',
                'monto' => $montoRedondeado,
            ],
        ];
    }
}
