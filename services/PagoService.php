<?php

namespace Services;

use Classes\MockPaymentGateway;
use Interfaces\PaymentGatewayInterface;
use Model\Pago;
use Repositories\PagoRepository;
use Repositories\PersistenceException;

/**
 * Servicio de dominio para la Base de Pagos (Fase 6C):
 * - Calcula el importe a cobrar exclusivamente en el servidor a partir de los snapshots
 *   históricos de `citasservicios`, ignorando cualquier monto enviado por el cliente.
 * - Genera referencias únicas criptográficamente impredecibles (`PAY-YYYYMMDD-...`).
 * - Garantiza idempotencia transaccional con bloqueo `FOR UPDATE` tanto al iniciar pagos
 *   como al procesar notificaciones/webhooks del proveedor.
 * - Prohíbe terminantemente recibir o almacenar números de tarjeta (PAN), CVV o fechas de expiración.
 * - Prohíbe confirmar pagos por simple redirección del navegador (`GET /pago/resultado`),
 *   exigiendo notificación server-to-server o webhook con firma válida.
 */
class PagoService
{
    public const STATUS_OK = 'ok';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_FORBIDDEN = 'forbidden';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_GATEWAY_ERROR = 'gateway_error';
    public const STATUS_ERROR = 'error';

    public const TIMEZONE = 'America/Guayaquil';

    /**
     * Métodos de pago admitidos en la base de pagos (sin captura de PAN/CVV en servidor).
     *
     * @var array<int, string>
     */
    public const METODOS_PAGO_PERMITIDOS = [
        'tarjeta_mock',
        'transferencia_mock',
        'efectivo',
    ];

    private PagoRepository $pagoRepository;
    private PaymentGatewayInterface $gateway;
    private ?\DateTimeImmutable $ahoraReferencia;

    public function __construct(
        ?PagoRepository $pagoRepository = null,
        ?PaymentGatewayInterface $gateway = null,
        ?\DateTimeImmutable $ahoraReferencia = null
    ) {
        $this->pagoRepository = $pagoRepository ?? new PagoRepository();
        $this->gateway = $gateway ?? new MockPaymentGateway();
        $this->ahoraReferencia = $ahoraReferencia;
    }

    public function getGateway(): PaymentGatewayInterface
    {
        return $this->gateway;
    }

    public function setGateway(PaymentGatewayInterface $gateway): void
    {
        $this->gateway = $gateway;
    }

    public function obtenerAhora(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        if ($this->ahoraReferencia !== null) {
            return $this->ahoraReferencia->setTimezone($tz);
        }
        return new \DateTimeImmutable('now', $tz);
    }

    /**
     * Genera una referencia única no predecible en el servidor (`PAY-YYYYMMDD-HEX16`).
     */
    public function generarReferenciaUnica(): string
    {
        $fecha = $this->obtenerAhora()->format('Ymd');
        $aleatorio = strtoupper(bin2hex(random_bytes(8)));
        return "PAY-{$fecha}-{$aleatorio}";
    }

    /**
     * Normaliza o genera la clave de idempotencia para un intento de pago.
     */
    public static function normalizarIdempotencyKey(?string $keyRaw, int $citaId): string
    {
        if ($keyRaw !== null && trim($keyRaw) !== '') {
            $limpia = preg_replace('/[^a-zA-Z0-9:_\-.]/', '_', trim($keyRaw)) ?? '';
            $limpia = substr($limpia, 0, 120);
            if ($limpia !== '') {
                return $limpia;
            }
        }
        return "pago:cita:{$citaId}:intento";
    }

    /**
     * Inicia un intento de pago calculando el monto exclusivamente en el servidor
     * y aplicando idempotencia transaccional con bloqueo `FOR UPDATE`.
     *
     * @param int $usuarioAutenticadoId
     * @param array<string, mixed> $datos
     * @param bool $esAdmin
     * @return array<string, mixed>
     */
    public function iniciarPago(int $usuarioAutenticadoId, array $datos, bool $esAdmin = false): array
    {
        // 1. Prohibición estricta de recibir o almacenar datos sensibles de tarjetas (PAN, CVV, expiración)
        if (MockPaymentGateway::contieneDatosTarjetaSensibles($datos)) {
            return [
                'status' => self::STATUS_INVALID,
                'http_code' => 422,
                'errores' => [
                    'Por seguridad (PCI-DSS), está prohibido enviar o almacenar números de tarjeta, CVV o fechas de expiración en el servidor.',
                ],
            ];
        }

        $citaIdRaw = $datos['cita_id'] ?? $datos['citaId'] ?? null;
        if ($citaIdRaw === null || !is_scalar($citaIdRaw) || !ctype_digit(trim((string)$citaIdRaw)) || (int)$citaIdRaw <= 0) {
            return [
                'status' => self::STATUS_INVALID,
                'http_code' => 422,
                'errores' => ['El identificador de la cita es obligatorio y debe ser un entero positivo.'],
            ];
        }
        $citaId = (int)$citaIdRaw;

        $metodoPagoRaw = $datos['metodo_pago'] ?? 'tarjeta_mock';
        if (!is_scalar($metodoPagoRaw)) {
            return [
                'status' => self::STATUS_INVALID,
                'http_code' => 422,
                'errores' => ['El método de pago indicado no es válido.'],
            ];
        }
        $metodoPago = strtolower(trim((string)$metodoPagoRaw)) ?: 'tarjeta_mock';
        if (!in_array($metodoPago, self::METODOS_PAGO_PERMITIDOS, true)) {
            return [
                'status' => self::STATUS_INVALID,
                'http_code' => 422,
                'errores' => ['El método de pago seleccionado no está permitido.'],
            ];
        }

        $idempotencyKeyExplicit = isset($datos['idempotency_key']) && is_scalar($datos['idempotency_key']) && trim((string)$datos['idempotency_key']) !== '';
        $idempotencyKey = self::normalizarIdempotencyKey(
            $idempotencyKeyExplicit ? (string)$datos['idempotency_key'] : null,
            $citaId
        );

        $modoSimulacion = isset($datos['modo_simulacion']) && is_scalar($datos['modo_simulacion'])
            ? trim((string)$datos['modo_simulacion'])
            : null;

        try {
            $this->pagoRepository->beginTransaction();

            // 2. Bloquear la fila de la cita con FOR UPDATE y calcular el monto exclusivamente en servidor
            $cita = $this->pagoRepository->obtenerCitaConImporteServidor($citaId, true);
            if ($cita === null) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'http_code' => 404,
                    'errores' => ['La cita indicada no existe.'],
                ];
            }

            if (!$esAdmin && (int)$cita['usuario_id'] !== $usuarioAutenticadoId) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_FORBIDDEN,
                    'http_code' => 403,
                    'errores' => ['No tiene permisos para iniciar el pago de esta cita.'],
                ];
            }

            $montoServidor = round((float)$cita['monto_servidor'], 2);
            if ($montoServidor <= 0) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_INVALID,
                    'http_code' => 422,
                    'errores' => ['La cita no posee servicios con importe válido para procesar el pago.'],
                ];
            }

            // Detectar si el cliente envió un monto manipulado para dejar constancia de que fue ignorado
            $montoClienteRaw = $datos['monto'] ?? $datos['amount'] ?? $datos['total'] ?? $datos['precio'] ?? null;
            $montoClienteIgnorado = false;
            if ($montoClienteRaw !== null && is_numeric($montoClienteRaw)) {
                if (abs((float)$montoClienteRaw - $montoServidor) > 0.009) {
                    $montoClienteIgnorado = true;
                }
            }

            // 3. Verificar si la cita ya cuenta con un pago completado (idempotencia por cita pagada)
            $pagoCompletado = $this->pagoRepository->findCompletadoPorCitaId($citaId, true);
            if ($pagoCompletado !== null) {
                $this->pagoRepository->commit();
                return [
                    'status' => self::STATUS_OK,
                    'http_code' => 200,
                    'idempotente' => true,
                    'ya_pagado' => true,
                    'monto_calculado_servidor' => $montoServidor,
                    'monto_cliente_ignorado' => $montoClienteIgnorado,
                    'pago' => $pagoCompletado->toArray(),
                    'redirect_url' => '/pago/resultado?referencia=' . rawurlencode($pagoCompletado->referencia_pago),
                ];
            }

            // Si la cita ya está marcada como pagada en citas, prevenir cualquier nuevo cobro
            if (($cita['estado_pago'] ?? '') === 'pagado') {
                $ultimoPago = $this->pagoRepository->findUltimoPorCitaId($citaId, true);
                $this->pagoRepository->commit();
                return [
                    'status' => self::STATUS_OK,
                    'http_code' => 200,
                    'idempotente' => true,
                    'ya_pagado' => true,
                    'monto_calculado_servidor' => $montoServidor,
                    'monto_cliente_ignorado' => $montoClienteIgnorado,
                    'pago' => $ultimoPago ? $ultimoPago->toArray() : null,
                    'redirect_url' => $ultimoPago ? ('/pago/resultado?referencia=' . rawurlencode($ultimoPago->referencia_pago)) : null,
                ];
            }

            // 4. Verificar idempotencia por idempotency_key con bloqueo FOR UPDATE y huella de operación
            $pagoExistenteKey = $this->pagoRepository->findByIdempotencyKey($idempotencyKey, true);
            if ($pagoExistenteKey !== null) {
                // Mitigación cross-tenant: Si la clave ya fue utilizada para otro usuario, otra cita o distinto monto
                $huellaCoincide = $pagoExistenteKey->coincideConOperacion(
                    (int)$cita['usuario_id'],
                    $citaId,
                    $montoServidor
                );

                if (!$huellaCoincide) {
                    $this->pagoRepository->rollback();
                    return [
                        'status' => self::STATUS_INVALID,
                        'http_code' => 409,
                        'codigo_error' => 'IDEMPOTENCY_KEY_CONFLICT',
                        'error' => 'La clave de idempotencia ya fue utilizada para una operación diferente',
                        'errores' => ['La clave de idempotencia ya fue utilizada para una operación diferente'],
                    ];
                }

                if (
                    $idempotencyKeyExplicit
                    || in_array($pagoExistenteKey->estado, [Pago::ESTADO_PENDIENTE, Pago::ESTADO_PROCESANDO, Pago::ESTADO_COMPLETADO], true)
                ) {
                    $this->pagoRepository->commit();
                    return [
                        'status' => self::STATUS_OK,
                        'http_code' => 200,
                        'idempotente' => true,
                        'ya_pagado' => $pagoExistenteKey->estado === Pago::ESTADO_COMPLETADO,
                        'monto_calculado_servidor' => $pagoExistenteKey->monto,
                        'monto_cliente_ignorado' => $montoClienteIgnorado,
                        'pago' => $pagoExistenteKey->toArray(),
                        'redirect_url' => '/pago/resultado?referencia=' . rawurlencode($pagoExistenteKey->referencia_pago),
                    ];
                }

                // Si el intento previo con clave por defecto había quedado en 'fallido', generar clave de reintento
                $idempotencyKey = "pago:cita:{$citaId}:retry:" . bin2hex(random_bytes(6));
            }

            // 5. Generar referencia única en servidor e invocar al gateway con el monto calculado en servidor
            $referenciaPago = $this->generarReferenciaUnica();
            $datosGateway = [
                'referencia_pago' => $referenciaPago,
                'cita_id' => $citaId,
                'usuario_id' => $cita['usuario_id'],
                'monto' => $montoServidor,
                'moneda' => Pago::MONEDA_USD,
                'metodo_pago' => $metodoPago,
                'idempotency_key' => $idempotencyKey,
            ];
            if ($modoSimulacion !== null) {
                $datosGateway['modo_simulacion'] = $modoSimulacion;
            }

            $resGateway = $this->gateway->iniciarPago($datosGateway);

            if (($resGateway['estado'] ?? '') === 'error_comunicacion') {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_GATEWAY_ERROR,
                    'http_code' => 502,
                    'errores' => [$resGateway['mensaje'] ?? 'Error de comunicación con el proveedor de pagos.'],
                    'codigo_error' => $resGateway['codigo_error'] ?? 'GATEWAY_COMMUNICATION_ERROR',
                ];
            }

            $estadoInicial = ($resGateway['estado'] ?? Pago::ESTADO_PENDIENTE) === Pago::ESTADO_FALLIDO
                ? Pago::ESTADO_FALLIDO
                : Pago::ESTADO_PENDIENTE;

            $nuevoPago = new Pago([
                'cita_id' => $citaId,
                'cita_referencia_id' => $citaId,
                'usuario_id' => $cita['usuario_id'],
                'referencia_pago' => $referenciaPago,
                'proveedor' => $resGateway['proveedor'] ?? MockPaymentGateway::PROVEEDOR,
                'transaccion_externa_id' => $resGateway['transaccion_externa_id'] ?? null,
                'monto' => $montoServidor,
                'moneda' => Pago::MONEDA_USD,
                'estado' => $estadoInicial,
                'metodo_pago' => $metodoPago,
                'idempotency_key' => $idempotencyKey,
                'payload_proveedor' => $resGateway['payload_sanitizado'] ?? [],
                'pagado_at' => null,
            ]);

            $this->pagoRepository->create($nuevoPago);
            $this->pagoRepository->actualizarEstadoPagoCita($citaId, 'pendiente');
            $this->pagoRepository->commit();

            $pagoPersistido = $this->pagoRepository->findById((int)$nuevoPago->id) ?? $nuevoPago;

            if ($estadoInicial === Pago::ESTADO_FALLIDO) {
                return [
                    'status' => self::STATUS_INVALID,
                    'http_code' => 422,
                    'idempotente' => false,
                    'ya_pagado' => false,
                    'monto_calculado_servidor' => $montoServidor,
                    'monto_cliente_ignorado' => $montoClienteIgnorado,
                    'codigo_error' => $resGateway['codigo_error'] ?? 'PAYMENT_DECLINED',
                    'errores' => [$resGateway['mensaje'] ?? 'El pago fue rechazado por el proveedor.'],
                    'pago' => $pagoPersistido->toArray(),
                    'redirect_url' => $resGateway['redirect_url'] ?? ('/pago/resultado?referencia=' . rawurlencode($referenciaPago)),
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'http_code' => 200,
                'idempotente' => false,
                'ya_pagado' => false,
                'monto_calculado_servidor' => $montoServidor,
                'monto_cliente_ignorado' => $montoClienteIgnorado,
                'pago' => $pagoPersistido->toArray(),
                'redirect_url' => $resGateway['redirect_url'] ?? ('/pago/resultado?referencia=' . rawurlencode($referenciaPago)),
                'client_token' => $resGateway['client_token'] ?? null,
            ];
        } catch (PersistenceException $e) {
            $this->pagoRepository->rollback();
            error_log('[PagoService::iniciarPago] PersistenceException: ' . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'http_code' => 500,
                'errores' => ['No fue posible inicializar el pago en este momento.'],
            ];
        } catch (\Throwable $e) {
            $this->pagoRepository->rollback();
            error_log('[PagoService::iniciarPago] Excepción inesperada: ' . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'http_code' => 500,
                'errores' => ['Error interno al procesar la solicitud de pago.'],
            ];
        }
    }

    /**
     * Procesa de forma idempotente una notificación asíncrona (webhook) del proveedor de pagos
     * validando su firma criptográfica y actualizando el estado en MySQL con bloqueo `FOR UPDATE`.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function procesarWebhook(array $payload, array $headers = []): array
    {
        if (MockPaymentGateway::contieneDatosTarjetaSensibles($payload)) {
            return [
                'status' => self::STATUS_INVALID,
                'http_code' => 422,
                'errores' => [
                    'El payload del webhook contiene datos sensibles de tarjeta prohibidos por la política de seguridad.',
                ],
            ];
        }

        $verificacion = $this->gateway->procesarNotificacionWebhook($payload, $headers);
        if (!$verificacion['firma_valida']) {
            return [
                'status' => self::STATUS_UNAUTHORIZED,
                'http_code' => 401,
                'codigo_error' => $verificacion['codigo_error'] ?? 'INVALID_WEBHOOK_SIGNATURE',
                'errores' => [$verificacion['mensaje'] ?? 'Firma de webhook inválida o ausente.'],
            ];
        }

        $referenciaPago = trim((string)($verificacion['referencia_pago'] ?? ''));
        $txExternaId = isset($verificacion['transaccion_externa_id']) && trim((string)$verificacion['transaccion_externa_id']) !== ''
            ? trim((string)$verificacion['transaccion_externa_id'])
            : null;

        if ($referenciaPago === '' && $txExternaId === null) {
            return [
                'status' => self::STATUS_INVALID,
                'http_code' => 422,
                'errores' => ['La referencia de pago o transacción externa es obligatoria en la notificación del proveedor.'],
            ];
        }

        // 1. Obtener la cita asociada previamente para respetar el orden canónico de bloqueo: primero citas, luego pagos
        $citaIdAsociada = null;
        if ($referenciaPago !== '') {
            $citaIdAsociada = $this->pagoRepository->obtenerCitaIdPorReferencia($referenciaPago);
        }
        if ($citaIdAsociada === null && $txExternaId !== null) {
            $citaIdAsociada = $this->pagoRepository->obtenerCitaIdPorTransaccionExterna($txExternaId);
        }

        try {
            $this->pagoRepository->beginTransaction();

            // Bloquear primero la fila de citas si existe para prevenir deadlocks con iniciarPago o reembolsarPago
            if ($citaIdAsociada !== null && $citaIdAsociada > 0) {
                $this->pagoRepository->bloquearCitaPorId($citaIdAsociada);
            }

            // Bloquear posteriormente la fila de pagos
            $pago = null;
            if ($referenciaPago !== '') {
                $pago = $this->pagoRepository->findByReferencia($referenciaPago, true);
            }
            if ($pago === null && $txExternaId !== null) {
                $pago = $this->pagoRepository->findByTransaccionExternaId($txExternaId, true);
            }

            if ($pago === null) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'http_code' => 404,
                    'errores' => ['No existe un registro de pago para la referencia o transacción indicada.'],
                ];
            }

            // REGLA TERMINAL: Una vez en 'reembolsado', el cobro es terminal.
            // Una confirmación tardía de webhook (replay de evento 'completado' u otro) NUNCA puede revertir 'reembolsado'.
            if ($pago->estado === Pago::ESTADO_REEMBOLSADO) {
                $this->pagoRepository->commit();
                return [
                    'status' => self::STATUS_OK,
                    'http_code' => 200,
                    'idempotente' => true,
                    'ya_procesado' => true,
                    'terminal_reembolsado' => true,
                    'mensaje' => 'El pago ya se encuentra en estado terminal reembolsado. Evento tardío ignorado de forma idempotente.',
                    'pago' => $pago->toArray(),
                ];
            }

            $nuevoEstado = (string)$verificacion['estado'];

            // Deduplica eventos del proveedor mediante transaccion_externa_id y mismo estado
            if (
                $pago->estado === $nuevoEstado
                && ($txExternaId === null || $pago->transaccion_externa_id === $txExternaId)
            ) {
                $this->pagoRepository->commit();
                return [
                    'status' => self::STATUS_OK,
                    'http_code' => 200,
                    'idempotente' => true,
                    'ya_procesado' => true,
                    'pago' => $pago->toArray(),
                ];
            }

            // 1. Idempotencia ante webhooks repetidos del mismo estado final completado
            if ($pago->estado === Pago::ESTADO_COMPLETADO && $nuevoEstado === Pago::ESTADO_COMPLETADO) {
                $this->pagoRepository->commit();
                return [
                    'status' => self::STATUS_OK,
                    'http_code' => 200,
                    'idempotente' => true,
                    'ya_procesado' => true,
                    'pago' => $pago->toArray(),
                ];
            }

            // No degradar un pago ya completado ante un evento atrasado de fallo/pendiente
            if (
                $pago->estado === Pago::ESTADO_COMPLETADO
                && in_array($nuevoEstado, [Pago::ESTADO_PENDIENTE, Pago::ESTADO_PROCESANDO, Pago::ESTADO_FALLIDO], true)
            ) {
                $this->pagoRepository->commit();
                return [
                    'status' => self::STATUS_OK,
                    'http_code' => 200,
                    'idempotente' => true,
                    'ya_procesado' => true,
                    'ignorado_por_estado_final' => true,
                    'pago' => $pago->toArray(),
                ];
            }

            // 2. Validar que el monto del webhook coincida exactamente con el monto calculado en servidor
            if ($verificacion['monto'] !== null) {
                $montoWebhook = round((float)$verificacion['monto'], 2);
                if (abs($montoWebhook - $pago->monto) > 0.009) {
                    $this->pagoRepository->rollback();
                    return [
                        'status' => self::STATUS_INVALID,
                        'http_code' => 422,
                        'codigo_error' => 'AMOUNT_MISMATCH',
                        'errores' => [
                            sprintf(
                                'El monto notificado (%.2f) no coincide con el importe registrado en servidor (%.2f).',
                                $montoWebhook,
                                $pago->monto
                            ),
                        ],
                    ];
                }
            }

            $pagadoAt = null;
            if ($nuevoEstado === Pago::ESTADO_COMPLETADO) {
                $pagadoAt = $pago->pagado_at ?? $this->obtenerAhora()->format('Y-m-d H:i:s');
            }

            $this->pagoRepository->actualizarEstadoPago(
                (int)$pago->id,
                $nuevoEstado,
                $verificacion['transaccion_externa_id'] ?? $pago->transaccion_externa_id,
                $pagadoAt,
                $verificacion['payload_sanitizado'] ?? [],
                $verificacion['metodo_pago'] ?? $pago->metodo_pago
            );

            if ($pago->cita_id !== null) {
                if ($nuevoEstado === Pago::ESTADO_COMPLETADO) {
                    $this->pagoRepository->actualizarEstadoPagoCita($pago->cita_id, 'pagado');
                } elseif ($nuevoEstado === Pago::ESTADO_REEMBOLSADO) {
                    $this->pagoRepository->actualizarEstadoPagoCita($pago->cita_id, 'reembolsado');
                } elseif ($nuevoEstado === Pago::ESTADO_FALLIDO) {
                    $this->pagoRepository->actualizarEstadoPagoCita($pago->cita_id, 'pendiente');
                }
            }

            $pagoActualizado = $this->pagoRepository->findById((int)$pago->id) ?? $pago;
            $this->pagoRepository->commit();

            return [
                'status' => self::STATUS_OK,
                'http_code' => 200,
                'idempotente' => false,
                'ya_procesado' => false,
                'pago' => $pagoActualizado->toArray(),
            ];
        } catch (PersistenceException $e) {
            $this->pagoRepository->rollback();
            error_log('[PagoService::procesarWebhook] PersistenceException: ' . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'http_code' => 500,
                'errores' => ['No fue posible procesar la notificación de pago en este momento.'],
            ];
        } catch (\Throwable $e) {
            $this->pagoRepository->rollback();
            error_log('[PagoService::procesarWebhook] Excepción inesperada: ' . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'http_code' => 500,
                'errores' => ['Error interno al procesar el webhook de pago.'],
            ];
        }
    }

    /**
     * Consulta el estado real de un pago exclusivamente desde la base de datos (para `GET /pago/resultado`).
     *
     * PROHIBICIÓN ESTRICTA: Jamás confirma ni actualiza el estado de un pago a partir
     * de parámetros enviados en la URL de redirección del navegador (`status=completado`,
     * `estado=pagado`, `aprobado=1`, etc.).
     *
     * @param array<string, mixed> $queryParams
     * @param int $usuarioAutenticadoId
     * @param bool $esAdmin
     * @return array<string, mixed>
     */
    public function consultarResultadoPago(array $queryParams, int $usuarioAutenticadoId, bool $esAdmin = false): array
    {
        $referenciaRaw = $queryParams['referencia'] ?? $queryParams['referencia_pago'] ?? null;
        $citaIdRaw = $queryParams['cita_id'] ?? $queryParams['citaId'] ?? null;

        // Detectar si la URL del navegador traía parámetros que pretendían confirmar el pago por redirección
        $parametrosRedirectSospechosos = ['status', 'estado', 'pagado', 'aprobado', 'success', 'payment_status', 'collection_status'];
        $intentoRedirectDetectado = false;
        foreach ($parametrosRedirectSospechosos as $param) {
            if (isset($queryParams[$param])) {
                $intentoRedirectDetectado = true;
                break;
            }
        }

        try {
            $pago = null;
            if ($referenciaRaw !== null && is_scalar($referenciaRaw) && trim((string)$referenciaRaw) !== '') {
                $pago = $this->pagoRepository->findByReferencia(trim((string)$referenciaRaw));
            } elseif ($citaIdRaw !== null && is_scalar($citaIdRaw) && ctype_digit(trim((string)$citaIdRaw)) && (int)$citaIdRaw > 0) {
                $pago = $this->pagoRepository->findUltimoPorCitaId((int)$citaIdRaw);
            } else {
                return [
                    'status' => self::STATUS_INVALID,
                    'http_code' => 422,
                    'errores' => ['Debe especificar una referencia de pago válida para consultar su estado.'],
                ];
            }

            if ($pago === null) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'http_code' => 404,
                    'errores' => ['No se encontró el registro de pago solicitado.'],
                ];
            }

            if (!$esAdmin && (int)$pago->usuario_id !== $usuarioAutenticadoId) {
                return [
                    'status' => self::STATUS_FORBIDDEN,
                    'http_code' => 403,
                    'errores' => ['No tiene permisos para consultar este registro de pago.'],
                ];
            }

            $citaIdConsulta = $pago->cita_id ?? $pago->cita_referencia_id;
            $cita = $citaIdConsulta > 0 ? $this->pagoRepository->obtenerCitaConImporteServidor($citaIdConsulta) : null;

            $advertenciaRedirect = null;
            if ($intentoRedirectDetectado && $pago->estado !== Pago::ESTADO_COMPLETADO) {
                $advertenciaRedirect = 'La confirmación de pagos nunca se realiza mediante parámetros en la URL del navegador; el estado mostrado corresponde estrictamente al registro verificado en el servidor.';
            }

            return [
                'status' => self::STATUS_OK,
                'http_code' => 200,
                'pago' => $pago->toArray(),
                'cita' => $cita,
                'intento_confirmacion_por_redirect_ignorado' => $intentoRedirectDetectado && $pago->estado !== Pago::ESTADO_COMPLETADO,
                'advertencia_seguridad' => $advertenciaRedirect,
            ];
        } catch (PersistenceException $e) {
            error_log('[PagoService::consultarResultadoPago] PersistenceException: ' . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'http_code' => 500,
                'errores' => ['No fue posible consultar el estado del pago en este momento.'],
            ];
        }
    }

    /**
     * Procesa el reembolso de un pago completado actualizando `pagos` y `citas`.
     */
    public function reembolsarPago(string $referenciaPago, ?float $monto = null): array
    {
        $ref = trim($referenciaPago);
        if ($ref === '') {
            return [
                'status' => self::STATUS_INVALID,
                'http_code' => 422,
                'errores' => ['La referencia de pago es obligatoria para solicitar un reembolso.'],
            ];
        }

        $citaIdAsociada = $this->pagoRepository->obtenerCitaIdPorReferencia($ref);

        try {
            $this->pagoRepository->beginTransaction();

            // Bloquear primero la cita para garantizar orden de bloqueo uniforme (citas -> pagos)
            if ($citaIdAsociada !== null && $citaIdAsociada > 0) {
                $this->pagoRepository->bloquearCitaPorId($citaIdAsociada);
            }

            $pago = $this->pagoRepository->findByReferencia($ref, true);
            if ($pago === null) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'http_code' => 404,
                    'errores' => ['El pago indicado no existe.'],
                ];
            }

            if ($pago->estado === Pago::ESTADO_REEMBOLSADO) {
                $this->pagoRepository->commit();
                return [
                    'status' => self::STATUS_OK,
                    'http_code' => 200,
                    'idempotente' => true,
                    'pago' => $pago->toArray(),
                ];
            }

            if ($pago->estado !== Pago::ESTADO_COMPLETADO) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_CONFLICT,
                    'http_code' => 409,
                    'errores' => ['Solo se pueden reembolsar pagos en estado completado.'],
                ];
            }

            $montoReembolso = $monto !== null ? round((float)$monto, 2) : $pago->monto;

            // Rechazar explícitamente reembolsos parciales
            if ($monto !== null && abs($montoReembolso - $pago->monto) > 0.009) {
                if ($montoReembolso < $pago->monto) {
                    $this->pagoRepository->rollback();
                    return [
                        'status' => self::STATUS_INVALID,
                        'http_code' => 422,
                        'codigo_error' => 'REEMBOLSO_PARCIAL_NO_PERMITIDO',
                        'error' => 'Solo se admiten reembolsos totales mientras el negocio no apruebe la capacidad de saldos parciales.',
                        'errores' => ['Solo se admiten reembolsos totales mientras el negocio no apruebe la capacidad de saldos parciales.'],
                    ];
                }
            }

            if ($montoReembolso <= 0 || $montoReembolso > $pago->monto) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_INVALID,
                    'http_code' => 422,
                    'codigo_error' => 'MONTO_REEMBOLSO_INVALIDO',
                    'errores' => ['El monto a reembolsar es inválido o supera el monto pagado.'],
                ];
            }

            $refExterna = $pago->transaccion_externa_id ?? $pago->referencia_pago;
            $resReembolso = $this->gateway->reembolsarPago($refExterna, $montoReembolso);
            if (!$resReembolso['ok']) {
                $this->pagoRepository->rollback();
                return [
                    'status' => self::STATUS_GATEWAY_ERROR,
                    'http_code' => 502,
                    'errores' => [$resReembolso['mensaje'] ?? 'Error al procesar el reembolso con el proveedor.'],
                ];
            }

            $this->pagoRepository->actualizarEstadoPago(
                (int)$pago->id,
                Pago::ESTADO_REEMBOLSADO,
                $pago->transaccion_externa_id,
                $pago->pagado_at,
                $resReembolso['payload_sanitizado'] ?? []
            );

            if ($pago->cita_id !== null) {
                $this->pagoRepository->actualizarEstadoPagoCita($pago->cita_id, 'reembolsado');
            }

            $pagoActualizado = $this->pagoRepository->findById((int)$pago->id) ?? $pago;
            $this->pagoRepository->commit();

            return [
                'status' => self::STATUS_OK,
                'http_code' => 200,
                'idempotente' => false,
                'pago' => $pagoActualizado->toArray(),
            ];
        } catch (PersistenceException $e) {
            $this->pagoRepository->rollback();
            error_log('[PagoService::reembolsarPago] PersistenceException: ' . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'http_code' => 500,
                'errores' => ['No fue posible procesar el reembolso en este momento.'],
            ];
        }
    }
}
