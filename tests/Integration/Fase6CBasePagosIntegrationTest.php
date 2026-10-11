<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Classes\MockPaymentGateway;
use Controllers\PagoController;
use Model\ActiveRecord;
use Model\HorarioProfesional;
use Model\Profesional;
use Model\Servicio;
use Model\Usuario;
use MVC\Router;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\PagoRepository;
use Repositories\ProfesionalRepository;
use Repositories\ReporteRepository;
use Repositories\ServicioRepository;
use Repositories\UsuarioRepository;
use Services\CitaService;
use Services\PagoService;
use Services\ReporteService;

/**
 * Pruebas de integración contra MySQL 8 real para Fase 6C: Base para Pagos.
 * Verifica:
 * - Cálculo de monto exclusivamente en el servidor a partir del snapshot histórico de `citasservicios`,
 *   ignorando montos manipulados por el cliente.
 * - Prohibición estricta de almacenar números de tarjeta, CVV o fechas de expiración.
 * - Prohibición de confirmar pagos únicamente por redirección del navegador (`GET /pago/resultado`).
 * - Validación de firma HMAC-SHA256 e idempotencia transaccional (`FOR UPDATE`) en `POST /api/pagos/iniciar`
 *   y `POST /api/pagos/webhook`.
 * - Coherencia con `ReporteService::consultarReporte()` (`ingreso_pagado_total` y reembolsos).
 */
class Fase6CBasePagosIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
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
        self::$db->query("DELETE FROM bloqueos_profesionales");
        self::$db->query("DELETE FROM descansos_profesionales");
        self::$db->query("DELETE FROM horarios_profesionales");
        self::$db->query("DELETE FROM profesionales_servicios");
        self::$db->query("DELETE FROM profesionales");
        self::$db->query("DELETE FROM servicios");
        self::$db->query("DELETE FROM usuarios");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/api/pagos/iniciar';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_X_MOCK_SIGNATURE'], $_SERVER['HTTP_X_IDEMPOTENCY_KEY']);

        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        PagoController::setPagoService(null);
        PagoController::setRawJsonOverride(null);
        ActiveRecord::setDB(self::$db);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['HTTP_REFERER'] = null;
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_REQUESTED_WITH']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        PagoController::setPagoService(null);
        PagoController::setRawJsonOverride(null);
        unset($_SERVER['HTTP_X_MOCK_SIGNATURE'], $_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
        ActiveRecord::setDB(self::$db);
        http_response_code(200);
    }

    private function crearUsuarioCliente(string $email = 'cliente.pagos@appsalon.com'): int
    {
        $repo = new UsuarioRepository(self::$db);
        return $repo->create(new Usuario([
            'nombre' => 'Elena',
            'apellido' => 'Ríos',
            'email' => $email,
            'password' => password_hash('Password123!', PASSWORD_BCRYPT),
            'telefono' => '0991122334',
            'admin' => '0',
            'confirmado' => '1',
        ]));
    }

    private function crearServicio(string $nombre, string $precio, int $duracionMinutos): int
    {
        $repo = new ServicioRepository(self::$db);
        return $repo->create(new Servicio([
            'nombre' => $nombre,
            'precio' => $precio,
            'duracion_minutos' => $duracionMinutos,
        ]));
    }

    private function crearProfesionalConTurnoLunes(string $nombre, array $servicioIds): int
    {
        $repo = new ProfesionalRepository(self::$db);
        $profId = $repo->createWithServicios(new Profesional([
            'nombre' => $nombre,
            'activo' => 1,
        ]), $servicioIds);

        $repo->replaceHorarios($profId, [
            new HorarioProfesional([
                'profesionalId' => $profId,
                'dia_semana' => 1,
                'hora_inicio' => '09:00',
                'hora_fin' => '18:00',
            ]),
        ]);

        return $profId;
    }

    private function obtenerProximoLunes(): string
    {
        $tz = new \DateTimeZone(PagoService::TIMEZONE);
        $cursor = (new \DateTimeImmutable('now', $tz))->modify('+2 days');
        while ((int)$cursor->format('N') !== 1) {
            $cursor = $cursor->modify('+1 day');
        }
        return $cursor->format('Y-m-d');
    }

    private function autenticarCliente(int $usuarioId): string
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = $usuarioId;
        $_SESSION['nombre'] = 'Elena';
        $_SESSION['apellido'] = 'Ríos';
        $_SESSION['email'] = 'cliente.pagos@appsalon.com';
        unset($_SESSION['admin']);
        return csrf_token();
    }

    public function testCalculoImporteEnServidorYProhibicionDeAlmacenarDatosTarjetaEnMySQL(): void
    {
        $fechaLunes = $this->obtenerProximoLunes();
        $clienteId = $this->crearUsuarioCliente();
        $srv1Id = $this->crearServicio('Corte de Cabello', '80.00', 30);
        $srv2Id = $this->crearServicio('Masaje Capilar Premium', '115.00', 60);
        $profId = $this->crearProfesionalConTurnoLunes('Valeria Castro', [$srv1Id, $srv2Id]);

        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $resCita = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaLunes,
            'hora' => '11:30',
            'servicios' => [$srv1Id, $srv2Id],
        ]);
        $this->assertSame(CitaService::STATUS_OK, $resCita['status']);
        $citaId = (int)$resCita['id'];

        // Modificar precio en el catálogo después de reservar ($80.00 -> $180.00)
        // para verificar que el pago usa el snapshot histórico ($80 + $115 = $195.00)
        self::$db->query("UPDATE servicios SET precio = 180.00 WHERE id = {$srv1Id}");

        $csrf = $this->autenticarCliente($clienteId);

        // 1. Intento de enviar número de tarjeta, CVV y fecha de expiración -> rechazado con 422 y 0 filas en DB
        $_POST = [
            'csrf_token' => $csrf,
            'cita_id' => (string)$citaId,
            'monto' => '1.00',
            'numero_tarjeta' => '4111222233334444',
            'cvv' => '789',
            'fecha_expiracion' => '11/29',
        ];

        ob_start();
        try {
            PagoController::iniciar();
            $this->fail('El envío de datos de tarjeta debe ser rechazado con 422');
        } catch (AppTerminationException $e) {
            $this->assertSame(422, $e->getStatusCode());
        } finally {
            $jsonRechazo = (string)ob_get_clean();
        }

        $dataRechazo = json_decode($jsonRechazo, true);
        $this->assertFalse($dataRechazo['resultado']);
        $this->assertSame(PagoService::STATUS_INVALID, $dataRechazo['status']);

        $conteoPagos = (int)self::$db->query("SELECT COUNT(*) AS c FROM pagos")->fetch_assoc()['c'];
        $this->assertSame(0, $conteoPagos, 'No debe almacenarse ningún registro cuando se envían datos sensibles de tarjeta');

        // 2. Iniciar pago legítimo pero enviando un monto manipulado ($0.50 en lugar de $195.00)
        $_POST = [
            'csrf_token' => $csrf,
            'cita_id' => (string)$citaId,
            'monto' => '0.50',
            'metodo_pago' => 'tarjeta_mock',
        ];

        ob_start();
        PagoController::iniciar();
        $jsonInicio = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        $dataInicio = json_decode($jsonInicio, true);
        $this->assertTrue($dataInicio['resultado']);
        $this->assertTrue($dataInicio['monto_cliente_ignorado']);
        $this->assertSame(195.00, (float)$dataInicio['monto_calculado_servidor']);
        $this->assertSame(195.00, (float)$dataInicio['pago']['monto']);
        $this->assertSame('pendiente', $dataInicio['pago']['estado']);

        // Verificar en MySQL que el monto persistido es 195.00 y que payload_proveedor no contiene datos de tarjeta
        $filaPago = self::$db->query("SELECT * FROM pagos WHERE cita_id = {$citaId}")->fetch_assoc();
        $this->assertNotNull($filaPago);
        $this->assertEquals(195.00, (float)$filaPago['monto']);
        $this->assertSame('pendiente', $filaPago['estado']);
        $this->assertStringNotContainsString('4111222233334444', (string)$filaPago['payload_proveedor']);
        $this->assertStringNotContainsString('cvv', (string)$filaPago['payload_proveedor']);
    }

    public function testProhibicionConfirmacionPorRedireccionExclusivaYFlujoCompletoConWebhookFirmadoEIdempotencia(): void
    {
        $fechaLunes = $this->obtenerProximoLunes();
        $clienteId = $this->crearUsuarioCliente();
        $srvId = $this->crearServicio('Tratamiento Hidratante', '120.00', 60);
        $profId = $this->crearProfesionalConTurnoLunes('Valeria Castro', [$srvId]);

        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $resCita = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaLunes,
            'hora' => '10:00',
            'servicios' => [$srvId],
        ]);
        $citaId = (int)$resCita['id'];

        $gateway = new MockPaymentGateway('webhook_secret_integracion_6c');
        $pagoService = new PagoService(new PagoRepository(self::$db), $gateway);
        PagoController::setPagoService($pagoService);

        $csrf = $this->autenticarCliente($clienteId);
        $_POST = [
            'csrf_token' => $csrf,
            'cita_id' => (string)$citaId,
            'metodo_pago' => 'tarjeta_mock',
        ];

        ob_start();
        PagoController::iniciar();
        $dataInicio = json_decode((string)ob_get_clean(), true);
        $referencia = (string)$dataInicio['pago']['referencia_pago'];
        $this->assertNotEmpty($referencia);

        // 1. El cliente navega a GET /pago/resultado manipulando parámetros en la URL (?status=completado&estado=pagado&aprobado=1)
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_GET = [
            'referencia' => $referencia,
            'status' => 'completado',
            'estado' => 'pagado',
            'aprobado' => '1',
        ];

        $router = new Router();
        ob_start();
        PagoController::resultado($router);
        $htmlRedirect = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('Pendiente de Confirmación', $htmlRedirect);
        $this->assertStringContainsString('La confirmación de pagos nunca se realiza mediante parámetros en la URL del navegador', $htmlRedirect);

        // Verificar en MySQL que el pago y la cita siguen estrictamente en 'pendiente'
        $filaPagoAntes = self::$db->query("SELECT estado, pagado_at FROM pagos WHERE referencia_pago = '{$referencia}'")->fetch_assoc();
        $filaCitaAntes = self::$db->query("SELECT estado_pago FROM citas WHERE id = {$citaId}")->fetch_assoc();
        $this->assertSame('pendiente', $filaPagoAntes['estado']);
        $this->assertNull($filaPagoAntes['pagado_at']);
        $this->assertSame('pendiente', $filaCitaAntes['estado_pago']);

        // 2. Webhook con firma inválida -> HTTP 401 y sin cambios en MySQL
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_X_MOCK_SIGNATURE'] = 'firma_invalida_12345';
        $payloadWebhook = [
            'referencia_pago' => $referencia,
            'evento' => 'payment.succeeded',
            'estado' => 'completado',
            'monto' => 120.00,
        ];
        PagoController::setRawJsonOverride($payloadWebhook);

        ob_start();
        try {
            PagoController::webhook();
            $this->fail('Webhook con firma inválida debe terminar con 401');
        } catch (AppTerminationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        $filaPagoTras401 = self::$db->query("SELECT estado FROM pagos WHERE referencia_pago = '{$referencia}'")->fetch_assoc();
        $this->assertSame('pendiente', $filaPagoTras401['estado']);

        // 3. Webhook auténtico con firma HMAC-SHA256 válida -> HTTP 200 y transición a 'completado' / 'pagado'
        $firmaValida = $gateway->generarFirmaWebhook($payloadWebhook);
        $_SERVER['HTTP_X_MOCK_SIGNATURE'] = $firmaValida;

        ob_start();
        PagoController::webhook();
        $resWh1 = json_decode((string)ob_get_clean(), true);

        $this->assertSame(200, http_response_code());
        $this->assertTrue($resWh1['resultado']);
        $this->assertFalse($resWh1['idempotente']);
        $this->assertSame('completado', $resWh1['pago']['estado']);
        $pagadoAtOriginal = $resWh1['pago']['pagado_at'];
        $this->assertNotEmpty($pagadoAtOriginal);

        // 4. Envío repetido del mismo webhook (reintento del proveedor) -> idempotente, sin duplicar registros
        ob_start();
        PagoController::webhook();
        $resWh2 = json_decode((string)ob_get_clean(), true);

        $this->assertSame(200, http_response_code());
        $this->assertTrue($resWh2['resultado']);
        $this->assertTrue($resWh2['idempotente']);
        $this->assertTrue($resWh2['ya_procesado']);
        $this->assertSame($pagadoAtOriginal, $resWh2['pago']['pagado_at']);

        $totalFilasPagos = (int)self::$db->query("SELECT COUNT(*) AS c FROM pagos WHERE cita_referencia_id = {$citaId}")->fetch_assoc()['c'];
        $this->assertSame(1, $totalFilasPagos);

        $filaCitaDespues = self::$db->query("SELECT estado_pago FROM citas WHERE id = {$citaId}")->fetch_assoc();
        $this->assertSame('pagado', $filaCitaDespues['estado_pago']);

        // 5. Ahora GET /pago/resultado refleja el estado confirmado en base de datos
        PagoController::setRawJsonOverride(null);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_GET = ['referencia' => $referencia];

        ob_start();
        PagoController::resultado($router);
        $htmlConfirmado = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('Pago Completado (Confirmado en Servidor)', $htmlConfirmado);
        $this->assertStringContainsString('$120.00 USD', $htmlConfirmado);
    }

    public function testCoherenciaEntrePagosCompletadosReembolsosYReporteServiceIngresoPagadoTotal(): void
    {
        $fechaLunes = $this->obtenerProximoLunes();
        $clienteId = $this->crearUsuarioCliente();
        $srv1Id = $this->crearServicio('Corte de Cabello', '80.00', 30);
        $srv2Id = $this->crearServicio('Coloración Completa', '115.00', 60);
        $profId = $this->crearProfesionalConTurnoLunes('Valeria Castro', [$srv1Id, $srv2Id]);

        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));

        // Cita 1: $80 + $115 = $195.00 (10:00 - 11:30)
        $resCita1 = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaLunes,
            'hora' => '10:00',
            'servicios' => [$srv1Id, $srv2Id],
        ]);
        $cita1Id = (int)$resCita1['id'];

        // Cita 2: $80.00 (12:00 - 12:30)
        $resCita2 = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaLunes,
            'hora' => '12:00',
            'servicios' => [$srv1Id],
        ]);
        $cita2Id = (int)$resCita2['id'];
        $this->assertGreaterThan(0, $cita2Id);

        $reporteService = new ReporteService(new ReporteRepository(self::$db));

        // 1. Antes de confirmar pagos: importe_reservado_total = $275.00, ingreso_pagado_total = $0.00
        $repAntes = $reporteService->consultarReporte([
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
        ]);
        $this->assertSame(275.00, $repAntes['resumen_global']['importe_reservado_total']);
        $this->assertSame(0.00, $repAntes['resumen_global']['ingreso_pagado_total']);
        $this->assertSame(275.00, $repAntes['resumen_global']['saldo_pendiente_cobro_total']);

        // 2. Iniciar y confirmar pago de Cita 1 ($195.00) mediante webhook firmado
        $gateway = new MockPaymentGateway('secret_reporte_sync');
        $pagoService = new PagoService(new PagoRepository(self::$db), $gateway);

        $inicio = $pagoService->iniciarPago($clienteId, [
            'cita_id' => $cita1Id,
            'metodo_pago' => 'tarjeta_mock',
        ]);
        $ref1 = (string)$inicio['pago']['referencia_pago'];

        $payloadWh = [
            'referencia_pago' => $ref1,
            'evento' => 'payment.succeeded',
            'estado' => 'completado',
            'monto' => 195.00,
        ];
        $firma = $gateway->generarFirmaWebhook($payloadWh);
        $resWh = $pagoService->procesarWebhook($payloadWh, ['X-Mock-Signature' => $firma]);
        $this->assertSame(PagoService::STATUS_OK, $resWh['status']);

        // 3. Consultar ReporteService: ingreso_pagado_total debe ser $195.00 y saldo_pendiente $80.00
        $repDespuesPago = $reporteService->consultarReporte([
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
        ]);
        $this->assertSame(275.00, $repDespuesPago['resumen_global']['importe_reservado_total']);
        $this->assertSame(195.00, $repDespuesPago['resumen_global']['ingreso_pagado_total']);
        $this->assertSame(80.00, $repDespuesPago['resumen_global']['saldo_pendiente_cobro_total']);

        // Verificar que al filtrar por servicio 2 ($115.00), el ingreso pagado atribuible al servicio 2 es $115.00
        $repFiltroSrv2 = $reporteService->consultarReporte([
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
            'servicio_id' => (string)$srv2Id,
        ]);
        $this->assertSame(115.00, $repFiltroSrv2['resumen_global']['importe_reservado_total']);
        $this->assertSame(115.00, $repFiltroSrv2['resumen_global']['ingreso_pagado_total']);

        // 4. Reembolsar el pago de Cita 1 -> ingreso_pagado_total vuelve a $0.00 y etiqueta es 'Reembolsado'
        $resReembolso = $pagoService->reembolsarPago($ref1);
        $this->assertSame(PagoService::STATUS_OK, $resReembolso['status']);
        $this->assertSame('reembolsado', $resReembolso['pago']['estado']);

        $repTrasReembolso = $reporteService->consultarReporte([
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
        ]);
        $this->assertSame(275.00, $repTrasReembolso['resumen_global']['importe_reservado_total']);
        $this->assertSame(0.00, $repTrasReembolso['resumen_global']['ingreso_pagado_total']);
        $this->assertSame('Reembolsado', $repTrasReembolso['reservas'][0]['etiqueta_estado_pago']);
    }

    public function testSeguridadAutenticacionCsrfPertenenciaEIdempotenciaEnIniciarPago(): void
    {
        $fechaLunes = $this->obtenerProximoLunes();
        $cliente1Id = $this->crearUsuarioCliente('cliente1@appsalon.com');
        $cliente2Id = $this->crearUsuarioCliente('cliente2@appsalon.com');
        $srvId = $this->crearServicio('Corte', '80.00', 30);
        $profId = $this->crearProfesionalConTurnoLunes('Valeria Castro', [$srvId]);

        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $resCita = $citaService->reservarConProfesional($cliente1Id, [
            'profesionalId' => $profId,
            'fecha' => $fechaLunes,
            'hora' => '10:00',
            'servicios' => [$srvId],
        ]);
        $citaId = (int)$resCita['id'];

        // 1. Petición sin sesión -> 401
        $_SESSION = [];
        $_POST = ['cita_id' => (string)$citaId];
        ob_start();
        try {
            PagoController::iniciar();
            $this->fail('Debe rechazar usuario no autenticado con 401');
        } catch (AppTerminationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // 2. Petición autenticada sin CSRF -> 403
        $this->autenticarCliente($cliente1Id);
        $_POST = ['cita_id' => (string)$citaId];
        ob_start();
        try {
            PagoController::iniciar();
            $this->fail('Debe rechazar petición sin token CSRF con 403');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // 3. Cliente 2 intenta pagar la cita de Cliente 1 -> 403
        $csrfCliente2 = $this->autenticarCliente($cliente2Id);
        $_POST = [
            'csrf_token' => $csrfCliente2,
            'cita_id' => (string)$citaId,
        ];
        ob_start();
        try {
            PagoController::iniciar();
            $this->fail('Cliente distinto no debe poder iniciar pago de cita ajena');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // 4. Cliente 1 inicia pago dos veces con la misma clave de idempotencia -> devuelve la misma referencia sin duplicar filas
        $csrfCliente1 = $this->autenticarCliente($cliente1Id);
        $_POST = [
            'csrf_token' => $csrfCliente1,
            'cita_id' => (string)$citaId,
            'idempotency_key' => 'idem-cliente1-cita-' . $citaId,
        ];

        ob_start();
        PagoController::iniciar();
        $res1 = json_decode((string)ob_get_clean(), true);

        ob_start();
        PagoController::iniciar();
        $res2 = json_decode((string)ob_get_clean(), true);

        $this->assertFalse($res1['idempotente']);
        $this->assertTrue($res2['idempotente']);
        $this->assertSame($res1['pago']['referencia_pago'], $res2['pago']['referencia_pago']);

        $conteo = (int)self::$db->query("SELECT COUNT(*) AS c FROM pagos WHERE cita_id = {$citaId}")->fetch_assoc()['c'];
        $this->assertSame(1, $conteo);
    }
}
