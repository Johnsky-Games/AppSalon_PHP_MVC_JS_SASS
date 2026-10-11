<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\MockPaymentGateway;
use Controllers\PagoController;
use Model\ActiveRecord;
use Model\Cita;
use Model\CitaServicio;
use Model\Pago;
use Model\Profesional;
use Model\Servicio;
use Model\Usuario;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\PagoRepository;
use Services\PagoService;

/**
 * Pruebas de integración para las garantías de seguridad, idempotencia y autorización de Pagos:
 * 1. Usuario A no obtiene el pago de B mediante reutilización de `idempotency_key` (recibe HTTP 409 sin datos ajenos).
 * 2. Reembolso seguido de replay tardío de confirmación de webhook permanece en estado `reembolsado`.
 * 3. Intento de reembolso parcial es rechazado con HTTP 422 (`REEMBOLSO_PARCIAL_NO_PERMITIDO`).
 * 4. Concurrencia con distintas claves de idempotencia sobre la misma cita no produce doble cobro ni doble confirmación.
 * 5. En `APP_ENV=production`, el controlador rechaza `MockPaymentGateway` y rechaza firmas con secretos por defecto.
 */
class PagoIdempotenciaSeguridadIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        self::$db = new mysqli($host, $user, $pass, $name, $port);
        self::$db->set_charset('utf8mb4');
        ActiveRecord::setDB(self::$db);
    }

    protected function setUp(): void
    {
        validar_base_datos_prueba(self::$db);

        self::$db->query("DELETE FROM pagos");
        self::$db->query("DELETE FROM notificaciones_citas");
        self::$db->query("DELETE FROM citasservicios");
        self::$db->query("DELETE FROM citas");
        self::$db->query("DELETE FROM servicios");
        self::$db->query("DELETE FROM profesionales");
        self::$db->query("DELETE FROM usuarios");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset(
            $_SERVER['HTTP_X_MOCK_SIGNATURE'],
            $_SERVER['HTTP_X_IDEMPOTENCY_KEY'],
            $_ENV['APP_ENV'],
            $_ENV['PAYMENT_GATEWAY_PROVIDER'],
            $_ENV['PAYMENT_WEBHOOK_SECRET']
        );
        putenv('APP_ENV=testing');
        putenv('PAYMENT_GATEWAY_PROVIDER=mock');
        putenv('PAYMENT_WEBHOOK_SECRET=test_safe_webhook_secret_2026');

        PagoController::setPagoService(null);
        PagoController::setRawJsonOverride(null);
        ActiveRecord::setDB(self::$db);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        PagoController::setPagoService(null);
        PagoController::setRawJsonOverride(null);
        unset(
            $_SERVER['HTTP_X_MOCK_SIGNATURE'],
            $_SERVER['HTTP_X_IDEMPOTENCY_KEY'],
            $_ENV['APP_ENV'],
            $_ENV['PAYMENT_GATEWAY_PROVIDER'],
            $_ENV['PAYMENT_WEBHOOK_SECRET']
        );
        putenv('APP_ENV=testing');
        putenv('PAYMENT_GATEWAY_PROVIDER=mock');
        putenv('PAYMENT_WEBHOOK_SECRET=test_safe_webhook_secret_2026');
        ActiveRecord::setDB(self::$db);
    }

    private function crearUsuario(string $email, string $nombre = 'Test'): int
    {
        $stmt = self::$db->prepare("INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado, token) VALUES (?, 'Prueba', ?, ?, '0990001122', '0', '1', '')");
        $passHash = password_hash('Password123!', PASSWORD_BCRYPT);
        $stmt->bind_param('sss', $nombre, $email, $passHash);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function crearServicio(string $nombre, float $precio): int
    {
        $stmt = self::$db->prepare("INSERT INTO servicios (nombre, precio, duracion_minutos) VALUES (?, ?, 30)");
        $precioStr = number_format($precio, 2, '.', '');
        $stmt->bind_param('ss', $nombre, $precioStr);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function crearProfesional(string $nombre): int
    {
        $stmt = self::$db->prepare("INSERT INTO profesionales (nombre, activo) VALUES (?, 1)");
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function crearCitaConServicio(int $usuarioId, int $profesionalId, int $servicioId, float $precioSnapshot): int
    {
        $fecha = date('Y-m-d', strtotime('+3 days'));
        $stmtCita = self::$db->prepare("INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado_pago) VALUES (?, '10:00:00', '10:00:00', '10:30:00', 30, ?, ?, 'pendiente')");
        $stmtCita->bind_param('sii', $fecha, $usuarioId, $profesionalId);
        $stmtCita->execute();
        $citaId = (int)$stmtCita->insert_id;
        $stmtCita->close();

        $stmtCS = self::$db->prepare("INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos) VALUES (?, ?, 'Corte', ?, 30)");
        $precioStr = number_format($precioSnapshot, 2, '.', '');
        $stmtCS->bind_param('iis', $citaId, $servicioId, $precioStr);
        $stmtCS->execute();
        $stmtCS->close();

        return $citaId;
    }

    /**
     * 1. Usuario A no obtiene el pago de B mediante reutilización de `idempotency_key`
     *    (recibe HTTP 409 Conflicto sin revelar datos del pago ajeno).
     */
    public function testUsuarioBNoObtienePagoDeAUsoIdempotencyKeyCrossTenantRetorna409SinDatosAjenos(): void
    {
        $usuarioA = $this->crearUsuario('usuario.a@example.com', 'Usuario A');
        $usuarioB = $this->crearUsuario('usuario.b@example.com', 'Usuario B');
        $profesional = $this->crearProfesional('Profesional Test');
        $servicio = $this->crearServicio('Corte Clásico', 25.00);

        $citaA = $this->crearCitaConServicio($usuarioA, $profesional, $servicio, 25.00);
        $citaB = $this->crearCitaConServicio($usuarioB, $profesional, $servicio, 25.00);

        $gateway = new MockPaymentGateway();
        $service = new PagoService(new PagoRepository(self::$db), $gateway);

        $idempotencyKeyCompartida = 'idemp_key_compartida_segura_12345';

        // 1. Usuario A inicia pago exitosamente con la clave de idempotencia
        $resA = $service->iniciarPago($usuarioA, [
            'cita_id' => $citaA,
            'metodo_pago' => 'tarjeta_mock',
            'idempotency_key' => $idempotencyKeyCompartida,
        ]);

        $this->assertSame(PagoService::STATUS_OK, $resA['status']);
        $this->assertSame(200, $resA['http_code']);
        $this->assertNotEmpty($resA['pago']['referencia_pago']);
        $refA = $resA['pago']['referencia_pago'];

        // 2. Usuario B intenta reutilizar la MISMA clave de idempotencia para su cita B
        $resB = $service->iniciarPago($usuarioB, [
            'cita_id' => $citaB,
            'metodo_pago' => 'tarjeta_mock',
            'idempotency_key' => $idempotencyKeyCompartida,
        ]);

        // Verificaciones de seguridad:
        // - Debe responder con HTTP 409 Conflicto
        $this->assertSame(409, $resB['http_code']);
        $this->assertSame(PagoService::STATUS_INVALID, $resB['status']);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $resB['codigo_error']);
        $this->assertSame('La clave de idempotencia ya fue utilizada para una operación diferente', $resB['error']);

        // - NO debe contener datos del pago del usuario A (cero fuga de datos cross-tenant)
        $this->assertArrayNotHasKey('pago', $resB);
        $this->assertArrayNotHasKey('redirect_url', $resB);
        $resBJson = json_encode($resB);
        $this->assertStringNotContainsString($refA, $resBJson);
        $this->assertStringNotContainsString((string)$citaA, $resBJson);
        $this->assertStringNotContainsString((string)$usuarioA, $resBJson);

        // 3. Usuario A reintenta la MISMA operación con su MISMA clave y debe recibir respuesta idempotente
        $resARepetido = $service->iniciarPago($usuarioA, [
            'cita_id' => $citaA,
            'metodo_pago' => 'tarjeta_mock',
            'idempotency_key' => $idempotencyKeyCompartida,
        ]);

        $this->assertSame(PagoService::STATUS_OK, $resARepetido['status']);
        $this->assertSame(200, $resARepetido['http_code']);
        $this->assertTrue($resARepetido['idempotente']);
        $this->assertSame($refA, $resARepetido['pago']['referencia_pago']);
    }

    /**
     * 2. Reembolso seguido de replay tardío de confirmación de webhook permanece en estado `reembolsado`.
     */
    public function testReembolsoSeguidoDeReplayTardioWebhookPermaneceEnEstadoReembolsado(): void
    {
        $usuario = $this->crearUsuario('cliente.reembolso@example.com');
        $profesional = $this->crearProfesional('Profesional Reembolsos');
        $servicio = $this->crearServicio('Tratamiento Capilar', 60.00);
        $citaId = $this->crearCitaConServicio($usuario, $profesional, $servicio, 60.00);

        $gateway = new MockPaymentGateway();
        $repo = new PagoRepository(self::$db);
        $service = new PagoService($repo, $gateway);

        // 1. Iniciar pago
        $inicio = $service->iniciarPago($usuario, [
            'cita_id' => $citaId,
            'metodo_pago' => 'tarjeta_mock',
        ]);
        $this->assertSame(200, $inicio['http_code']);
        $refPago = $inicio['pago']['referencia_pago'];

        // 2. Confirmar pago via webhook exitoso
        $payloadWebhook = [
            'referencia_pago' => $refPago,
            'evento' => 'payment.succeeded',
            'estado' => 'completado',
            'monto' => 60.00,
        ];
        $headers = [
            'X-Mock-Signature' => $gateway->generarFirmaWebhook($payloadWebhook),
        ];
        $resWh1 = $service->procesarWebhook($payloadWebhook, $headers);
        $this->assertSame(200, $resWh1['http_code']);
        $this->assertSame(Pago::ESTADO_COMPLETADO, $resWh1['pago']['estado']);

        // Verificar estado de cita y pago en BD
        $pagoEnDb = $repo->findByReferencia($refPago);
        $this->assertNotNull($pagoEnDb);
        $this->assertSame(Pago::ESTADO_COMPLETADO, $pagoEnDb->estado);

        // 3. Administrador reembolsa el pago
        $resReembolso = $service->reembolsarPago($refPago, 60.00);
        $this->assertSame(200, $resReembolso['http_code']);
        $this->assertSame(Pago::ESTADO_REEMBOLSADO, $resReembolso['pago']['estado']);

        $pagoReembolsado = $repo->findByReferencia($refPago);
        $this->assertSame(Pago::ESTADO_REEMBOLSADO, $pagoReembolsado->estado);

        // 4. Se recibe un replay tardío del webhook con evento 'completado' (o 'payment.succeeded')
        $resReplayTardio = $service->procesarWebhook($payloadWebhook, $headers);

        $this->assertSame(200, $resReplayTardio['http_code']);
        $this->assertTrue($resReplayTardio['idempotente']);
        $this->assertTrue($resReplayTardio['terminal_reembolsado']);
        $this->assertSame(Pago::ESTADO_REEMBOLSADO, $resReplayTardio['pago']['estado']);

        // Verificar que en base de datos el pago y la cita continúen estrictamente en estado 'reembolsado'
        $pagoFinal = $repo->findByReferencia($refPago);
        $this->assertSame(Pago::ESTADO_REEMBOLSADO, $pagoFinal->estado);

        $citaFinal = $repo->obtenerCitaConImporteServidor($citaId);
        $this->assertSame('reembolsado', $citaFinal['estado_pago']);
    }

    /**
     * 3. Intento de reembolso parcial es rechazado con HTTP 422 (`REEMBOLSO_PARCIAL_NO_PERMITIDO`).
     */
    public function testIntentoReembolsoParcialEsRechazadoConHttp422(): void
    {
        $usuario = $this->crearUsuario('cliente.parcial@example.com');
        $profesional = $this->crearProfesional('Profesional Parcial');
        $servicio = $this->crearServicio('Tinte Completo', 80.00);
        $citaId = $this->crearCitaConServicio($usuario, $profesional, $servicio, 80.00);

        $gateway = new MockPaymentGateway();
        $repo = new PagoRepository(self::$db);
        $service = new PagoService($repo, $gateway);

        // Iniciar y completar pago
        $inicio = $service->iniciarPago($usuario, [
            'cita_id' => $citaId,
            'metodo_pago' => 'tarjeta_mock',
        ]);
        $refPago = $inicio['pago']['referencia_pago'];

        $payload = [
            'referencia_pago' => $refPago,
            'evento' => 'payment.succeeded',
            'estado' => 'completado',
            'monto' => 80.00,
        ];
        $headers = ['X-Mock-Signature' => $gateway->generarFirmaWebhook($payload)];
        $service->procesarWebhook($payload, $headers);

        // 1. Intentar reembolso parcial por un monto menor ($40.00 de $80.00)
        $resParcial = $service->reembolsarPago($refPago, 40.00);

        $this->assertSame(422, $resParcial['http_code']);
        $this->assertSame(PagoService::STATUS_INVALID, $resParcial['status']);
        $this->assertSame('REEMBOLSO_PARCIAL_NO_PERMITIDO', $resParcial['codigo_error']);
        $this->assertStringContainsString('Solo se admiten reembolsos totales', $resParcial['error']);

        // El pago en BD sigue intacto en estado completado
        $pagoActual = $repo->findByReferencia($refPago);
        $this->assertSame(Pago::ESTADO_COMPLETADO, $pagoActual->estado);

        // 2. Reembolso total por el importe íntegro ($80.00) debe aceptarse sin error
        $resTotal = $service->reembolsarPago($refPago, 80.00);
        $this->assertSame(200, $resTotal['http_code']);
        $this->assertSame(Pago::ESTADO_REEMBOLSADO, $resTotal['pago']['estado']);
    }

    /**
     * 4. Concurrencia con distintas claves de idempotencia sobre la misma cita
     *    no produce doble cobro ni doble confirmación.
     */
    public function testConcurrenciaConDistintasClavesIdempotenciaMismaCitaNoProduceDobleCobroNiDobleConfirmacion(): void
    {
        $usuario = $this->crearUsuario('cliente.concurrente@example.com');
        $profesional = $this->crearProfesional('Profesional Concurrencia');
        $servicio = $this->crearServicio('Corte y Barba', 35.00);
        $citaId = $this->crearCitaConServicio($usuario, $profesional, $servicio, 35.00);

        $gateway = new MockPaymentGateway();
        $repo = new PagoRepository(self::$db);
        $service = new PagoService($repo, $gateway);

        // Hilo 1 inicia pago con clave 1
        $resHilo1 = $service->iniciarPago($usuario, [
            'cita_id' => $citaId,
            'idempotency_key' => 'idemp_hilo_1_' . bin2hex(random_bytes(4)),
        ]);
        $this->assertSame(200, $resHilo1['http_code']);
        $refPago1 = $resHilo1['pago']['referencia_pago'];

        // Confirmar el pago de Hilo 1 mediante webhook
        $payloadWh1 = [
            'referencia_pago' => $refPago1,
            'evento' => 'payment.succeeded',
            'estado' => 'completado',
            'monto' => 35.00,
        ];
        $headersWh1 = ['X-Mock-Signature' => $gateway->generarFirmaWebhook($payloadWh1)];
        $resConfirmacion = $service->procesarWebhook($payloadWh1, $headersWh1);
        $this->assertSame(200, $resConfirmacion['http_code']);

        // Hilo 2 intenta iniciar pago para la MISMA cita pero con una clave de idempotencia distinta
        $resHilo2 = $service->iniciarPago($usuario, [
            'cita_id' => $citaId,
            'idempotency_key' => 'idemp_hilo_2_' . bin2hex(random_bytes(4)),
        ]);

        // Verificación: No debe crear un nuevo cobro, debe detectar que la cita ya está pagada
        $this->assertSame(200, $resHilo2['http_code']);
        $this->assertTrue($resHilo2['ya_pagado']);
        $this->assertTrue($resHilo2['idempotente']);
        $this->assertSame($refPago1, $resHilo2['pago']['referencia_pago']);

        // Comprobar que en la base de datos existe exactamente un único pago completado para esa cita
        $stmtConteo = self::$db->prepare("SELECT COUNT(*) AS total FROM pagos WHERE (cita_id = ? OR cita_referencia_id = ?) AND estado = 'completado'");
        $stmtConteo->bind_param('ii', $citaId, $citaId);
        $stmtConteo->execute();
        $resConteo = $stmtConteo->get_result()->fetch_assoc();
        $stmtConteo->close();

        $this->assertSame(1, (int)$resConteo['total'], 'No deben existir cobros duplicados en estado completado para la misma cita.');
    }

    /**
     * 5. En `APP_ENV=production`, el controlador rechaza `MockPaymentGateway`
     *    y rechaza firmas con secretos por defecto.
     */
    public function testEnAppEnvProductionControladorRechazaMockGatewayYSecretosPorDefecto(): void
    {
        // 5.1 En producción con MockPaymentGateway, resolverGateway debe lanzar RuntimeException
        $_ENV['APP_ENV'] = 'production';
        $_ENV['PAYMENT_GATEWAY_PROVIDER'] = 'mock';
        putenv('APP_ENV=production');
        putenv('PAYMENT_GATEWAY_PROVIDER=mock');

        $excepcionCapturada = false;
        try {
            PagoController::resolverGateway();
        } catch (\RuntimeException $e) {
            $excepcionCapturada = true;
            $this->assertStringContainsString('MockPaymentGateway está estrictamente prohibido en entorno de producción', $e->getMessage());
        }
        $this->assertTrue($excepcionCapturada, 'Debe prohibir terminantemente MockPaymentGateway en producción.');

        // 5.2 En producción con secreto por defecto o ausente, resolverGateway debe rechazar la configuración
        $_ENV['PAYMENT_GATEWAY_PROVIDER'] = 'stripe';
        $_ENV['PAYMENT_WEBHOOK_SECRET'] = MockPaymentGateway::DEFAULT_WEBHOOK_SECRET;
        putenv('PAYMENT_GATEWAY_PROVIDER=stripe');
        putenv('PAYMENT_WEBHOOK_SECRET=' . MockPaymentGateway::DEFAULT_WEBHOOK_SECRET);

        $excepcionSecretCapturada = false;
        try {
            PagoController::resolverGateway();
        } catch (\RuntimeException $e) {
            $excepcionSecretCapturada = true;
            $this->assertStringContainsString('no puede ser nulo ni utilizar el valor por defecto', $e->getMessage());
        }
        $this->assertTrue($excepcionSecretCapturada, 'Debe rechazar secretos por defecto en producción.');

        // 5.3 En producción con 'mock_secret_cambiar_en_produccion'
        $_ENV['PAYMENT_WEBHOOK_SECRET'] = 'mock_secret_cambiar_en_produccion';
        putenv('PAYMENT_WEBHOOK_SECRET=mock_secret_cambiar_en_produccion');

        $excepcionLegacySecret = false;
        try {
            PagoController::resolverGateway();
        } catch (\RuntimeException $e) {
            $excepcionLegacySecret = true;
            $this->assertStringContainsString('no puede ser nulo ni utilizar el valor por defecto', $e->getMessage());
        }
        $this->assertTrue($excepcionLegacySecret, 'Debe rechazar mock_secret_cambiar_en_produccion en producción.');

        // 5.4 En producción, MockPaymentGateway::verificarFirmaWebhook debe rechazar firmas calculadas con secreto por defecto
        $mockGateway = new MockPaymentGateway(MockPaymentGateway::DEFAULT_WEBHOOK_SECRET);
        $payloadPrueba = ['referencia_pago' => 'PAY-TEST', 'evento' => 'test', 'estado' => 'completado', 'monto' => 10.00];
        $headersPrueba = ['X-Mock-Signature' => $mockGateway->generarFirmaWebhook($payloadPrueba)];

        $firmaAceptada = $mockGateway->verificarFirmaWebhook($payloadPrueba, $headersPrueba);
        $this->assertFalse($firmaAceptada, 'MockPaymentGateway debe rechazar la verificación de firmas con el secreto por defecto bajo APP_ENV=production.');

        // 5.5 Invocar PagoController::webhook() directamente bajo APP_ENV=production debe responder HTTP 500
        $_ENV['APP_ENV'] = 'production';
        $_ENV['PAYMENT_GATEWAY_PROVIDER'] = 'mock';
        putenv('APP_ENV=production');
        putenv('PAYMENT_GATEWAY_PROVIDER=mock');
        PagoController::setPagoService(null);

        ob_start();
        $terminoCon500 = false;
        try {
            PagoController::webhook();
        } catch (AppTerminationException $e) {
            $terminoCon500 = ($e->getCode() === 500);
        }
        $salida = ob_get_clean();

        $this->assertTrue($terminoCon500, 'PagoController::webhook debe detener ejecución con código 500 en producción con mock gateway.');
        $jsonRespuesta = json_decode($salida, true);
        $this->assertIsArray($jsonRespuesta);
        $this->assertSame(PagoService::STATUS_ERROR, $jsonRespuesta['status']);
        $this->assertSame('PAYMENT_CONFIGURATION_ERROR', $jsonRespuesta['codigo_error']);
        $this->assertStringContainsString('MockPaymentGateway está estrictamente prohibido en entorno de producción', $jsonRespuesta['error']);
    }
}
