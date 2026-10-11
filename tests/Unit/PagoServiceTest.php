<?php

namespace Tests\Unit;

use Classes\MockPaymentGateway;
use Model\Pago;
use PHPUnit\Framework\TestCase;
use Repositories\PagoRepository;
use Services\PagoService;

/**
 * Pruebas unitarias para Fase 6C: Base para Pagos (`PagoService`, `MockPaymentGateway`, `PagoRepository`).
 */
class PagoServiceTest extends TestCase
{
    public function testCalculoDeMontoExclusivamenteEnServidorIgnorandoMontosManipuladosPorCliente(): void
    {
        $repo = $this->createMock(PagoRepository::class);
        $gateway = new MockPaymentGateway('secret_test_key', MockPaymentGateway::MODO_APROBADO);

        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('commit');
        $repo->expects($this->never())->method('rollback');

        // La cita en el servidor cuesta $195.00 ($80.00 + $115.00)
        $repo->expects($this->once())
            ->method('obtenerCitaConImporteServidor')
            ->with(42, true)
            ->willReturn([
                'id' => 42,
                'fecha' => '2026-10-12',
                'hora' => '11:30:00',
                'hora_inicio' => '11:30:00',
                'hora_fin' => '13:00:00',
                'duracion_total_minutos' => 90,
                'usuario_id' => 7,
                'profesional_id' => 2,
                'profesional_nombre' => 'Valeria Castro',
                'estado_pago' => 'pendiente',
                'monto_servidor' => 195.00,
                'servicios' => [
                    ['id' => 1, 'servicio_id' => 1, 'nombre' => 'Corte', 'precio' => 80.00, 'duracion_minutos' => 30],
                    ['id' => 2, 'servicio_id' => 4, 'nombre' => 'Masaje Capilar', 'precio' => 115.00, 'duracion_minutos' => 60],
                ],
            ]);

        $repo->method('findCompletadoPorCitaId')->willReturn(null);
        $repo->method('findByIdempotencyKey')->willReturn(null);

        $montoPersistido = null;
        $referenciaGenerada = null;
        $repo->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (Pago $pago) use (&$montoPersistido, &$referenciaGenerada): int {
                $montoPersistido = $pago->monto;
                $referenciaGenerada = $pago->referencia_pago;
                $pago->id = 100;
                return 100;
            });

        $reloj = new \DateTimeImmutable('2026-10-12 10:00:00', new \DateTimeZone(PagoService::TIMEZONE));
        $service = new PagoService($repo, $gateway, $reloj);

        // El cliente intenta manipular el monto enviando $0.01
        $resultado = $service->iniciarPago(7, [
            'cita_id' => '42',
            'monto' => '0.01',
            'amount' => '0.01',
            'precio' => '0.01',
            'metodo_pago' => 'tarjeta_mock',
        ]);

        $this->assertSame(PagoService::STATUS_OK, $resultado['status']);
        $this->assertSame(200, $resultado['http_code']);
        $this->assertTrue($resultado['monto_cliente_ignorado']);
        $this->assertSame(195.00, $resultado['monto_calculado_servidor']);
        $this->assertSame(195.00, $montoPersistido);
        $this->assertSame(195.00, $resultado['pago']['monto']);
        $this->assertMatchesRegularExpression('/^PAY-20261012-[0-9A-F]{16}$/', (string)$referenciaGenerada);
    }

    public function testProhibicionEstrictaDeAlmacenarDatosSensiblesDeTarjeta(): void
    {
        $repo = $this->createMock(PagoRepository::class);
        // Jamás debe tocar la base de datos si el payload trae datos de tarjeta
        $repo->expects($this->never())->method('beginTransaction');
        $repo->expects($this->never())->method('create');

        $service = new PagoService($repo, new MockPaymentGateway());

        // 1. Intento con numero_tarjeta, cvv y fecha_expiracion en iniciarPago
        $resTarjeta = $service->iniciarPago(7, [
            'cita_id' => 10,
            'numero_tarjeta' => '4111111111111111',
            'cvv' => '123',
            'fecha_expiracion' => '12/28',
        ]);

        $this->assertSame(PagoService::STATUS_INVALID, $resTarjeta['status']);
        $this->assertSame(422, $resTarjeta['http_code']);
        $this->assertStringContainsString('PCI-DSS', implode(' ', $resTarjeta['errores']));

        // 2. Intento con PAN embebido en campo de texto o clave alternativa (card_number / cvc)
        $resPan = $service->iniciarPago(7, [
            'cita_id' => 10,
            'card_number' => '5500000000000004',
            'cvc' => '999',
        ]);
        $this->assertSame(PagoService::STATUS_INVALID, $resPan['status']);
        $this->assertSame(422, $resPan['http_code']);

        // 3. Sanitización defensiva en MockPaymentGateway y PagoRepository::serializarPayloadSeguro
        $jsonSeguro = PagoRepository::serializarPayloadSeguro([
            'proveedor' => 'mock',
            'numero_tarjeta' => '4111111111111111',
            'cvv' => '456',
            'fecha_expiracion' => '08/29',
            'anidado' => [
                'card_number' => '4242424242424242',
                'exp_month' => '08',
                'exp_year' => '2029',
                'nota' => 'Intento con PAN 4111 2222 3333 4444 en texto',
            ],
        ]);

        $this->assertIsString($jsonSeguro);
        $this->assertStringNotContainsString('4111111111111111', $jsonSeguro);
        $this->assertStringNotContainsString('4242424242424242', $jsonSeguro);
        $this->assertStringNotContainsString('4111 2222 3333 4444', $jsonSeguro);
        $this->assertStringNotContainsString('"cvv"', $jsonSeguro);
        $this->assertStringNotContainsString('"fecha_expiracion"', $jsonSeguro);
        $this->assertStringContainsString('[REDACTED_PAN]', $jsonSeguro);
    }

    public function testProhibicionDeConfirmacionPorRedireccionExclusivaDelNavegador(): void
    {
        $repo = $this->createMock(PagoRepository::class);
        // Jamás debe actualizar el estado en DB cuando se consulta GET /pago/resultado
        $repo->expects($this->never())->method('actualizarEstadoPago');
        $repo->expects($this->never())->method('actualizarEstadoPagoCita');

        $pagoPendiente = new Pago([
            'id' => 15,
            'cita_id' => 42,
            'cita_referencia_id' => 42,
            'usuario_id' => 7,
            'referencia_pago' => 'PAY-20261012-AABBCCDDEEFF0011',
            'proveedor' => 'mock',
            'transaccion_externa_id' => 'MOCK-TX-999',
            'monto' => 195.00,
            'moneda' => 'USD',
            'estado' => Pago::ESTADO_PENDIENTE,
            'metodo_pago' => 'tarjeta_mock',
            'idempotency_key' => 'pago:cita:42:intento',
        ]);

        $repo->expects($this->once())
            ->method('findByReferencia')
            ->with('PAY-20261012-AABBCCDDEEFF0011')
            ->willReturn($pagoPendiente);

        $repo->method('obtenerCitaConImporteServidor')->willReturn([
            'id' => 42,
            'fecha' => '2026-10-12',
            'hora' => '11:30:00',
            'hora_inicio' => '11:30:00',
            'hora_fin' => '13:00:00',
            'usuario_id' => 7,
            'profesional_nombre' => 'Valeria Castro',
            'estado_pago' => 'pendiente',
            'monto_servidor' => 195.00,
        ]);

        $service = new PagoService($repo, new MockPaymentGateway());

        // El navegador redirige con parámetros manipulados pretendiendo simular éxito
        $resultado = $service->consultarResultadoPago([
            'referencia' => 'PAY-20261012-AABBCCDDEEFF0011',
            'status' => 'completado',
            'estado' => 'pagado',
            'aprobado' => '1',
            'payment_status' => 'approved',
        ], 7);

        $this->assertSame(PagoService::STATUS_OK, $resultado['status']);
        $this->assertSame(200, $resultado['http_code']);
        // El estado real sigue siendo 'pendiente', nunca 'completado'
        $this->assertSame(Pago::ESTADO_PENDIENTE, $resultado['pago']['estado']);
        $this->assertTrue($resultado['intento_confirmacion_por_redirect_ignorado']);
        $this->assertNotEmpty($resultado['advertencia_seguridad']);
    }

    public function testModosDeSimulacionValidacionDeFirmaWebhookEIdempotenciaUnitaria(): void
    {
        $gateway = new MockPaymentGateway('mi_secreto_hmac_2026', MockPaymentGateway::MODO_APROBADO);

        // 1. Modo fondos insuficientes
        $resFondos = $gateway->iniciarPago([
            'referencia_pago' => 'PAY-20261012-FONDOS01',
            'monto' => 120.00,
            'moneda' => 'USD',
            'modo_simulacion' => MockPaymentGateway::MODO_FONDOS_INSUFICIENTES,
        ]);
        $this->assertFalse($resFondos['ok']);
        $this->assertSame('fallido', $resFondos['estado']);
        $this->assertSame('INSUFFICIENT_FUNDS', $resFondos['codigo_error']);

        // 2. Modo error de comunicación
        $resTimeout = $gateway->iniciarPago([
            'referencia_pago' => 'PAY-20261012-TIMEOUT01',
            'monto' => 120.00,
            'moneda' => 'USD',
            'modo_simulacion' => MockPaymentGateway::MODO_ERROR_COMUNICACION,
        ]);
        $this->assertFalse($resTimeout['ok']);
        $this->assertSame('error_comunicacion', $resTimeout['estado']);
        $this->assertSame('GATEWAY_COMMUNICATION_ERROR', $resTimeout['codigo_error']);

        // 3. Validación de firma en webhook: firma inválida es rechazada con 401
        $repo = $this->createMock(PagoRepository::class);
        $service = new PagoService($repo, $gateway);

        $payloadWebhook = [
            'referencia_pago' => 'PAY-20261012-VALID01',
            'evento' => 'payment.succeeded',
            'estado' => 'completado',
            'monto' => 195.00,
        ];

        $resSinFirma = $service->procesarWebhook($payloadWebhook, []);
        $this->assertSame(PagoService::STATUS_UNAUTHORIZED, $resSinFirma['status']);
        $this->assertSame(401, $resSinFirma['http_code']);

        $resFirmaFalsa = $service->procesarWebhook($payloadWebhook, [
            'X-Mock-Signature' => 'firma_manipulada_invalida',
        ]);
        $this->assertSame(PagoService::STATUS_UNAUTHORIZED, $resFirmaFalsa['status']);
        $this->assertSame(401, $resFirmaFalsa['http_code']);

        // 4. Firma válida con pago ya completado -> idempotente sin volver a ejecutar UPDATE
        $firmaValida = $gateway->generarFirmaWebhook($payloadWebhook);
        $pagoYaCompletado = new Pago([
            'id' => 88,
            'cita_id' => 42,
            'cita_referencia_id' => 42,
            'usuario_id' => 7,
            'referencia_pago' => 'PAY-20261012-VALID01',
            'proveedor' => 'mock',
            'transaccion_externa_id' => 'MOCK-TX-88',
            'monto' => 195.00,
            'moneda' => 'USD',
            'estado' => Pago::ESTADO_COMPLETADO,
            'idempotency_key' => 'pago:cita:42:intento',
            'pagado_at' => '2026-10-12 10:05:00',
        ]);

        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('commit');
        $repo->expects($this->once())
            ->method('findByReferencia')
            ->with('PAY-20261012-VALID01', true)
            ->willReturn($pagoYaCompletado);
        $repo->expects($this->never())->method('actualizarEstadoPago');

        $resIdempotente = $service->procesarWebhook($payloadWebhook, [
            'X-Mock-Signature' => $firmaValida,
        ]);
        $this->assertSame(PagoService::STATUS_OK, $resIdempotente['status']);
        $this->assertSame(200, $resIdempotente['http_code']);
        $this->assertTrue($resIdempotente['idempotente']);
        $this->assertTrue($resIdempotente['ya_procesado']);
    }
}
