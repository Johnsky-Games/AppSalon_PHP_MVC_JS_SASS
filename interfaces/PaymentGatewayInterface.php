<?php

namespace Interfaces;

/**
 * Interfaz sustituible de proveedor de pagos (Fase 6C — Base para Pagos).
 *
 * Permite desacoplar el dominio de AppSalon (`Services\PagoService`) de cualquier
 * pasarela comercial específica (Stripe, Mercado Pago, PayPhone, Kushki, etc.) o
 * proveedor simulado (`Classes\MockPaymentGateway`) sin modificar las reglas de negocio:
 * - El monto a cobrar es calculado exclusivamente en el servidor a partir de la base de datos.
 * - Queda prohibido almacenar números de tarjeta (PAN), CVV o fechas de expiración.
 * - Ningún pago se confirma únicamente por redirección del navegador; se exige
 *   verificación server-to-server o notificación/webhook firmada e idempotente.
 */
interface PaymentGatewayInterface
{
    /**
     * Inicializa una intención o sesión de pago con el proveedor externo.
     *
     * @param array<string, mixed> $datosPago Datos validados en servidor (`referencia_pago`, `monto`, `moneda`, `cita_id`, `metodo_pago`, etc.).
     * @return array{
     *   ok: bool,
     *   proveedor: string,
     *   estado: string,
     *   transaccion_externa_id: ?string,
     *   redirect_url: ?string,
     *   client_token: ?string,
     *   codigo_error: ?string,
     *   mensaje: string,
     *   payload_sanitizado: array<string, mixed>
     * }
     */
    public function iniciarPago(array $datosPago): array;

    /**
     * Consulta directamente (server-to-server) el estado de una transacción en el proveedor.
     *
     * @param string $referenciaExterna Identificador de transacción externa o referencia de pago.
     * @return array{
     *   ok: bool,
     *   proveedor: string,
     *   referencia_externa: string,
     *   estado: string,
     *   monto: ?float,
     *   moneda: string,
     *   codigo_error: ?string,
     *   mensaje: string,
     *   payload_sanitizado: array<string, mixed>
     * }
     */
    public function verificarPago(string $referenciaExterna): array;

    /**
     * Valida la autenticidad (firma criptográfica / token) y procesa una notificación
     * asíncrona (webhook) enviada por el proveedor de pagos.
     *
     * @param array<string, mixed> $payload Cuerpo decodificado del webhook.
     * @param array<string, mixed> $headers Cabeceras HTTP recibidas.
     * @return array{
     *   ok: bool,
     *   firma_valida: bool,
     *   proveedor: string,
     *   evento: string,
     *   referencia_pago: string,
     *   transaccion_externa_id: ?string,
     *   estado: string,
     *   monto: ?float,
     *   moneda: string,
     *   metodo_pago: ?string,
     *   idempotency_key: ?string,
     *   codigo_error: ?string,
     *   mensaje: string,
     *   payload_sanitizado: array<string, mixed>
     * }
     */
    public function procesarNotificacionWebhook(array $payload, array $headers): array;

    /**
     * Solicita el reembolso total o parcial de una transacción previamente completada.
     *
     * @param string $referenciaExterna Identificador de la transacción externa o referencia de pago.
     * @param float $monto Monto a reembolsar validado en servidor.
     * @return array{
     *   ok: bool,
     *   proveedor: string,
     *   referencia_externa: string,
     *   reembolso_id: ?string,
     *   estado: string,
     *   monto: float,
     *   codigo_error: ?string,
     *   mensaje: string,
     *   payload_sanitizado: array<string, mixed>
     * }
     */
    public function reembolsarPago(string $referenciaExterna, float $monto): array;
}
