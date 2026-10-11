<?php

namespace Tests\Integration;

use AppTerminationException;
use Controllers\AdminReporteController;
use Controllers\ReporteController;
use Model\ActiveRecord;
use Model\HorarioProfesional;
use Model\Pago;
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
use Services\CitaService;
use Services\PagoService;
use Services\ReporteService;

/**
 * Pruebas de integración para Reportes e Historial Financiero (Especialista 5):
 * 1. Ciclo: crear cita -> registrar pago completado -> cancelar cita -> consultar reporte:
 *    el pago completado y el ingreso recaudado permanecen intactos en el reporte.
 * 2. Pago con reembolso posterior: refleja cobro completado, devolución y cálculo neto.
 * 3. Cita con servicios posteriormente eliminados del catálogo: conserva nombre y precio histórico
 *    en el reporte y exportación CSV.
 * 4. Distinción explícita entre filtro por fecha de cita y fecha de cobro.
 */
class ReportesFinancierosIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;
    private static ReporteRepository $reporteRepository;
    private static ReporteService $reporteService;
    private static CitaRepository $citaRepository;
    private static ServicioRepository $servicioRepository;
    private static PagoRepository $pagoRepository;

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

        self::$reporteRepository = new ReporteRepository(self::$db);
        self::$reporteService = new ReporteService(self::$reporteRepository);
        self::$citaRepository = new CitaRepository(self::$db);
        self::$servicioRepository = new ServicioRepository(self::$db);
        self::$pagoRepository = new PagoRepository(self::$db);
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
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['CONTENT_TYPE'] = 'text/html';
        $_SERVER['REQUEST_URI'] = '/admin/reportes';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        AdminReporteController::setReporteService(null);
        ReporteController::setReporteService(null);
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
        AdminReporteController::setReporteService(null);
        ReporteController::setReporteService(null);
        ActiveRecord::setDB(self::$db);
        http_response_code(200);
    }

    private function autenticarAdmin(): void
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $_SESSION['nombre'] = 'Admin';
        $_SESSION['apellido'] = 'General';
        $_SESSION['admin'] = '1';
    }

    private function crearCliente(string $nombre = 'Carlos', string $apellido = 'Pérez', string $email = 'carlos@correo.com'): int
    {
        $stmt = self::$db->prepare("INSERT INTO usuarios (nombre, apellido, email, password, telefono, confirmado, admin) VALUES (?, ?, ?, 'Password123!', '0991234567', 1, 0)");
        $stmt->bind_param('sss', $nombre, $apellido, $email);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function crearServicio(string $nombre = 'Corte Caballero', string $precio = '80.00', int $duracion = 30): int
    {
        $precioFloat = (float)$precio;
        $stmt = self::$db->prepare("INSERT INTO servicios (nombre, precio, duracion_minutos) VALUES (?, ?, ?)");
        $stmt->bind_param('sdi', $nombre, $precioFloat, $duracion);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function crearProfesionalConHorario(string $nombre = 'Valeria Castro', array $servicioIds = []): int
    {
        $stmt = self::$db->prepare("INSERT INTO profesionales (nombre, activo) VALUES (?, 1)");
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $profId = (int)$stmt->insert_id;
        $stmt->close();

        foreach ($servicioIds as $sId) {
            self::$db->query("INSERT INTO profesionales_servicios (profesionalId, servicioId) VALUES ({$profId}, " . (int)$sId . ")");
        }

        $horarios = [];
        for ($dia = 1; $dia <= 5; $dia++) {
            $horarios[] = new HorarioProfesional([
                'profesionalId' => $profId,
                'dia_semana' => $dia,
                'hora_inicio' => '08:00',
                'hora_fin' => '18:00',
            ]);
        }
        $profRepo = new ProfesionalRepository(self::$db);
        $profRepo->replaceHorarios($profId, $horarios);

        return $profId;
    }

    /**
     * Escenario 1:
     * Ciclo: crear cita -> registrar pago completado -> cancelar cita -> consultar reporte:
     * el pago completado y el ingreso recaudado permanecen intactos en el reporte.
     */
    public function testPagoCompletadoPermaneceIntactoEnReporteTrasCancelarCita(): void
    {
        $clienteId = $this->crearCliente();
        $srvId = $this->crearServicio('Corte Clásico', '75.00', 30);
        $profId = $this->crearProfesionalConHorario('Valeria Castro', [$srvId]);

        $fechaServicio = '2026-10-21';
        $citaService = new CitaService(self::$citaRepository, self::$servicioRepository);

        // 1. Crear cita confirmada
        $resReserva = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaServicio,
            'hora' => '10:00',
            'servicios' => [$srvId],
        ]);
        $this->assertSame(CitaService::STATUS_OK, $resReserva['status']);
        $citaId = (int)($resReserva['id'] ?? $resReserva['resultado']['id'] ?? $resReserva['cita_id']);

        // 2. Registrar pago completado en la tabla duradera `pagos`
        $pago = new Pago([
            'cita_id' => $citaId,
            'cita_referencia_id' => $citaId,
            'usuario_id' => $clienteId,
            'referencia_pago' => 'PAY-TEST-001',
            'proveedor' => 'mock',
            'transaccion_externa_id' => 'tx_mock_001',
            'monto' => 75.00,
            'moneda' => 'USD',
            'estado' => Pago::ESTADO_COMPLETADO,
            'metodo_pago' => 'card_mock',
            'idempotency_key' => 'idemp_key_001',
            'pagado_at' => $fechaServicio . ' 10:05:00',
            'created_at' => $fechaServicio . ' 10:05:00',
        ]);
        $pagoCreado = self::$pagoRepository->create($pago);
        $this->assertNotNull($pagoCreado);
        self::$pagoRepository->actualizarEstadoPagoCita($citaId, 'pagado');

        // 3. Cancelar la cita lógicamente (agenda/operativa liberada)
        $cancelado = self::$citaRepository->cancelarCitaLogica($citaId, $clienteId, 'Cancelación por cliente');
        $this->assertTrue($cancelado);

        // Verificar que la cita está marcada como cancelada en BD
        $citaDb = self::$citaRepository->findById($citaId);
        $this->assertNotNull($citaDb);
        $this->assertSame('cancelada', $citaDb->estado);

        // 4. Consultar el reporte administrativo para la fecha del servicio
        $reporte = self::$reporteService->consultarReporte([
            'fecha_inicio' => $fechaServicio,
            'fecha_fin' => $fechaServicio,
        ]);

        $this->assertSame(ReporteService::STATUS_OK, $reporte['status']);

        // Dimensiones operativas: la cita cancelada no compromete importe ni ocupa minutos
        $this->assertSame(0, $reporte['resumen_global']['total_citas_activas']);
        $this->assertSame(1, $reporte['resumen_global']['total_citas_canceladas']);
        $this->assertSame(0, $reporte['resumen_global']['minutos_ocupados_profesionales']);
        $this->assertSame(0.00, $reporte['resumen_global']['importe_reservado_total']);

        // Dimensiones contables: el pago completado y el ingreso recaudado PERMANECEN INTACTOS
        $this->assertSame(75.00, $reporte['resumen_global']['cobros_confirmados_total']);
        $this->assertSame(75.00, $reporte['resumen_global']['ingreso_pagado_total']);
        $this->assertSame(75.00, $reporte['resumen_global']['neto_cobrado_total']);
        $this->assertSame(0.00, $reporte['resumen_global']['reembolsos_total']);
        $this->assertSame(75.00, $reporte['resumen_global']['cobros_confirmados_simulados']);

        // Comprobar que en los movimientos financieros figura el pago con su referencia
        $this->assertNotEmpty($reporte['movimientos_financieros']);
        $mov0 = $reporte['movimientos_financieros'][0];
        $this->assertSame('PAY-TEST-001', $mov0['referencia_pago']);
        $this->assertSame(75.00, $mov0['monto']);
        $this->assertSame('completado', $mov0['estado']);
        $this->assertSame('cancelada', $mov0['cita_estado']);

        // Comprobar que el HTML administrativo mediante AdminReporteController refleja el cobro
        $this->autenticarAdmin();
        $_GET = ['fecha_inicio' => $fechaServicio, 'fecha_fin' => $fechaServicio];
        $router = new Router();
        ob_start();
        AdminReporteController::index($router);
        $html = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('$75.00', $html);
        $this->assertStringContainsString('PAY-TEST-001', $html);
        $this->assertStringContainsString('Cancelada', $html);
    }

    /**
     * Escenario 2:
     * Pago con reembolso posterior: refleja cobro completado, devolución y cálculo neto.
     */
    public function testPagoConReembolsoPosteriorReflejaDevolucionYCalculoNeto(): void
    {
        $clienteId = $this->crearCliente();
        $srv1Id = $this->crearServicio('Tratamiento Capilar', '120.00', 60);
        $srv2Id = $this->crearServicio('Corte Barba', '40.00', 20);
        $profId = $this->crearProfesionalConHorario('Sofía Andrade', [$srv1Id, $srv2Id]);

        $fechaServicio = '2026-10-22';
        $citaService = new CitaService(self::$citaRepository, self::$servicioRepository);

        // Cita 1: $120.00
        $res1 = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaServicio,
            'hora' => '09:00',
            'servicios' => [$srv1Id],
        ]);
        $cita1Id = (int)($res1['id'] ?? $res1['resultado']['id'] ?? $res1['cita_id']);

        // Cita 2: $40.00
        $res2 = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaServicio,
            'hora' => '11:00',
            'servicios' => [$srv2Id],
        ]);
        $cita2Id = (int)($res2['id'] ?? $res2['resultado']['id'] ?? $res2['cita_id']);

        // Pago 1: $120.00 completado
        self::$pagoRepository->create(new Pago([
            'cita_id' => $cita1Id,
            'cita_referencia_id' => $cita1Id,
            'usuario_id' => $clienteId,
            'referencia_pago' => 'PAY-COMPLETADO-120',
            'proveedor' => 'mock',
            'monto' => 120.00,
            'estado' => Pago::ESTADO_COMPLETADO,
            'idempotency_key' => 'idemp_120',
            'pagado_at' => $fechaServicio . ' 09:10:00',
            'created_at' => $fechaServicio . ' 09:10:00',
        ]));
        self::$pagoRepository->actualizarEstadoPagoCita($cita1Id, 'pagado');

        // Pago 2: $40.00 completado inicialmente
        $pago2 = self::$pagoRepository->create(new Pago([
            'cita_id' => $cita2Id,
            'cita_referencia_id' => $cita2Id,
            'usuario_id' => $clienteId,
            'referencia_pago' => 'PAY-REEMBOLSADO-40',
            'proveedor' => 'mock',
            'monto' => 40.00,
            'estado' => Pago::ESTADO_COMPLETADO,
            'idempotency_key' => 'idemp_40',
            'pagado_at' => $fechaServicio . ' 11:10:00',
            'created_at' => $fechaServicio . ' 11:10:00',
        ]));
        self::$pagoRepository->actualizarEstadoPagoCita($cita2Id, 'pagado');

        // Reembolsar el pago 2 mediante PagoService
        $pagoService = new PagoService(self::$pagoRepository);
        $resReembolso = $pagoService->reembolsarPago('PAY-REEMBOLSADO-40', 40.00);
        $this->assertSame(PagoService::STATUS_OK, $resReembolso['status']);

        // Consultar reporte
        $reporte = self::$reporteService->consultarReporte([
            'fecha_inicio' => $fechaServicio,
            'fecha_fin' => $fechaServicio,
        ]);

        $this->assertSame(ReporteService::STATUS_OK, $reporte['status']);

        // Cobros confirmados: $120.00
        $this->assertSame(120.00, $reporte['resumen_global']['cobros_confirmados_total']);
        // Reembolsos: $40.00
        $this->assertSame(40.00, $reporte['resumen_global']['reembolsos_total']);
        // Neto cobrado: 120.00 - 40.00 = 80.00
        $this->assertSame(80.00, $reporte['resumen_global']['neto_cobrado_total']);

        // Desglose por profesional Sofía Andrade
        $profSofia = $reporte['resumen_profesionales'][0];
        $this->assertSame(120.00, $profSofia['ingreso_pagado']);
        $this->assertSame(40.00, $profSofia['reembolsos']);
        $this->assertSame(80.00, $profSofia['neto_cobrado']);

        // Comprobar exportación CSV
        $this->autenticarAdmin();
        $_GET = ['fecha_inicio' => $fechaServicio, 'fecha_fin' => $fechaServicio];
        $router = new Router();
        ob_start();
        try {
            AdminReporteController::exportarCsv($router);
        } catch (AppTerminationException $e) {
            $this->assertSame(200, $e->getStatusCode());
        }
        $csv = (string)ob_get_clean();

        $this->assertStringContainsString('Cobros Confirmados Total (USD)', $csv);
        $this->assertStringContainsString('Reembolsos Total (USD)', $csv);
        $this->assertStringContainsString('Neto Cobrado Total (USD)', $csv);
        $this->assertStringContainsString('120.00,40.00,80.00', $csv);
    }

    /**
     * Escenario 3:
     * Cita con servicios posteriormente eliminados del catálogo: conserva nombre y precio
     * histórico en el reporte y exportación CSV.
     */
    public function testCitaConServicioEliminadoConservaSnapshotHistoricoEnReporteYCsv(): void
    {
        $clienteId = $this->crearCliente();
        $srvId = $this->crearServicio('Coloración Fantasía Premium', '165.00', 90);
        $profId = $this->crearProfesionalConHorario('Camila Vega', [$srvId]);

        $fechaServicio = '2026-10-23';
        $citaService = new CitaService(self::$citaRepository, self::$servicioRepository);

        $resReserva = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaServicio,
            'hora' => '14:00',
            'servicios' => [$srvId],
        ]);
        $citaId = (int)($resReserva['id'] ?? $resReserva['resultado']['id'] ?? $resReserva['cita_id']);

        // Registrar pago de la cita
        self::$pagoRepository->create(new Pago([
            'cita_id' => $citaId,
            'cita_referencia_id' => $citaId,
            'usuario_id' => $clienteId,
            'referencia_pago' => 'PAY-SNAP-165',
            'proveedor' => 'mock',
            'monto' => 165.00,
            'estado' => Pago::ESTADO_COMPLETADO,
            'idempotency_key' => 'idemp_snap_165',
            'pagado_at' => $fechaServicio . ' 14:10:00',
            'created_at' => $fechaServicio . ' 14:10:00',
        ]));
        self::$pagoRepository->actualizarEstadoPagoCita($citaId, 'pagado');

        // Eliminar el servicio del catálogo (en citasservicios servicioId pasa a NULL por ON DELETE SET NULL)
        self::$db->query("DELETE FROM servicios WHERE id = {$srvId}");

        // Consultar el reporte
        $reporte = self::$reporteService->consultarReporte([
            'fecha_inicio' => $fechaServicio,
            'fecha_fin' => $fechaServicio,
        ]);

        $this->assertSame(ReporteService::STATUS_OK, $reporte['status']);
        $this->assertSame(165.00, $reporte['resumen_global']['importe_reservado_total']);
        $this->assertSame(165.00, $reporte['resumen_global']['ingreso_pagado_total']);

        // Comprobar que en el desglose de servicios se conserva el nombre y precio histórico
        $this->assertNotEmpty($reporte['resumen_servicios']);
        $srvReporte = $reporte['resumen_servicios'][0];
        $this->assertSame('Coloración Fantasía Premium', $srvReporte['servicio_nombre']);
        $this->assertSame(165.00, $srvReporte['importe_reservado']);
        $this->assertTrue($srvReporte['es_eliminado_catalogo']);

        // Comprobar que en el listado de reservas y CSV se conserva el nombre
        $this->autenticarAdmin();
        $_GET = ['fecha_inicio' => $fechaServicio, 'fecha_fin' => $fechaServicio];
        $router = new Router();

        ob_start();
        AdminReporteController::index($router);
        $html = (string)ob_get_clean();
        $this->assertStringContainsString('Coloración Fantasía Premium', $html);
        $this->assertStringContainsString('$165.00', $html);
        $this->assertStringContainsString('(Eliminado del catálogo)', $html);

        ob_start();
        try {
            AdminReporteController::exportarCsv($router);
        } catch (AppTerminationException $e) {
            $this->assertSame(200, $e->getStatusCode());
        }
        $csv = (string)ob_get_clean();
        $this->assertStringContainsString('Coloración Fantasía Premium', $csv);
        $this->assertStringContainsString('165.00', $csv);
        $this->assertStringContainsString('ELIMINADO_CATALOGO', $csv);
    }

    /**
     * Escenario 4:
     * Distinción explícita entre filtro por fecha de cita y fecha de cobro.
     */
    public function testDistincionExplicitaEntreFiltroPorFechaDeCitaYFechaDeCobro(): void
    {
        $clienteId = $this->crearCliente();
        $srvId = $this->crearServicio('Tratamiento Facial', '90.00', 45);
        $profId = $this->crearProfesionalConHorario('Andrea Molina', [$srvId]);

        // Fecha de cobro = 2026-10-10, Fecha del servicio = 2026-10-26 (Lunes)
        $fechaCobro = '2026-10-10';
        $fechaServicio = '2026-10-26';

        $citaService = new CitaService(self::$citaRepository, self::$servicioRepository);
        $resReserva = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaServicio,
            'hora' => '15:00',
            'servicios' => [$srvId],
        ]);
        $citaId = (int)($resReserva['id'] ?? $resReserva['resultado']['id'] ?? $resReserva['cita_id']);

        // Registrar pago con fecha de cobro anticipada
        self::$pagoRepository->create(new Pago([
            'cita_id' => $citaId,
            'cita_referencia_id' => $citaId,
            'usuario_id' => $clienteId,
            'referencia_pago' => 'PAY-ANTICIPADO-090',
            'proveedor' => 'mock',
            'monto' => 90.00,
            'estado' => Pago::ESTADO_COMPLETADO,
            'idempotency_key' => 'idemp_ant_090',
            'pagado_at' => $fechaCobro . ' 16:00:00',
            'created_at' => $fechaCobro . ' 16:00:00',
        ]));
        self::$pagoRepository->actualizarEstadoPagoCita($citaId, 'pagado');

        // 1. Filtrar por FECHA DE COBRO para la fecha 2026-10-10:
        // Debe mostrar el movimiento financiero recaudado ($90.00)
        $repCobro10 = self::$reporteService->consultarReporte([
            'fecha_inicio' => $fechaCobro,
            'fecha_fin' => $fechaCobro,
            'criterio_fecha' => 'cobro',
        ]);
        $this->assertSame(ReporteService::STATUS_OK, $repCobro10['status']);
        $this->assertSame(90.00, $repCobro10['resumen_global']['cobros_confirmados_total']);
        $this->assertCount(1, $repCobro10['movimientos_financieros']);
        $this->assertSame('PAY-ANTICIPADO-090', $repCobro10['movimientos_financieros'][0]['referencia_pago']);

        // 2. Filtrar por FECHA DE SERVICIO para la fecha 2026-10-10:
        // No hay citas en agenda ese día -> 0 citas
        $repServ10 = self::$reporteService->consultarReporte([
            'fecha_inicio' => $fechaCobro,
            'fecha_fin' => $fechaCobro,
            'criterio_fecha' => 'servicio',
        ]);
        $this->assertSame(0, $repServ10['resumen_global']['total_citas_activas']);
        $this->assertSame(0.00, $repServ10['resumen_global']['cobros_confirmados_total']);

        // 3. Filtrar por FECHA DE SERVICIO para la fecha 2026-10-25:
        // La cita figura en agenda operativa con su importe reservado
        $repServ25 = self::$reporteService->consultarReporte([
            'fecha_inicio' => $fechaServicio,
            'fecha_fin' => $fechaServicio,
            'criterio_fecha' => 'servicio',
        ]);
        $this->assertSame(ReporteService::STATUS_OK, $repServ25['status']);
        $this->assertSame(1, $repServ25['resumen_global']['total_citas_activas']);
        $this->assertSame(90.00, $repServ25['resumen_global']['importe_reservado_total']);

        // 4. Filtrar por FECHA DE COBRO para la fecha 2026-10-25:
        // No se cobró dinero ese día (se cobró el 10) -> $0 cobros
        $repCobro25 = self::$reporteService->consultarReporte([
            'fecha_inicio' => $fechaServicio,
            'fecha_fin' => $fechaServicio,
            'criterio_fecha' => 'cobro',
        ]);
        $this->assertSame(0.00, $repCobro25['resumen_global']['cobros_confirmados_total']);
        $this->assertEmpty($repCobro25['movimientos_financieros']);
    }

    /**
     * Escenario 5 (Frente 3 de auditoría):
     * Cobro y reembolso en períodos separados:
     * - Cobro en mes 1 (Enero): cobros = $100.00, reembolsos = $0.00, neto = $100.00.
     * - Reembolso en mes 2 (Febrero): cobros = $0.00, reembolsos = $100.00, neto = -$100.00 (sin max(0)).
     * - Valida resumen global, resumen por profesional y exportación CSV.
     */
    public function testCobroEnMesYReembolsoEnOtroPeriodoConNetosNegativos(): void
    {
        $clienteId = $this->crearCliente('Lucía', 'Gómez', 'lucia@correo.com');
        $srvId = $this->crearServicio('Balayage Especial', '100.00', 120);
        $profId = $this->crearProfesionalConHorario('Elena Ramos', [$srvId]);

        $fechaCobroEnero = '2026-01-15';
        $fechaReembolsoFebrero = '2026-02-10';
        $fechaServicio = '2026-11-20';

        // Insertar la cita directamente para permitir cualquier fecha de simulación
        self::$db->query(
            "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado, estado_pago)
             VALUES ('{$fechaServicio}', '10:00:00', '10:00:00', '12:00:00', 120, {$clienteId}, {$profId}, 'confirmada', 'pagado')"
        );
        $citaId = (int)self::$db->insert_id;
        self::$db->query(
            "INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
             VALUES ({$citaId}, {$srvId}, 'Balayage Especial', 100.00, 120)"
        );

        // 1. Registrar pago completado en Enero
        $pago = new Pago([
            'cita_id' => $citaId,
            'cita_referencia_id' => $citaId,
            'usuario_id' => $clienteId,
            'referencia_pago' => 'PAY-BITEMPORAL-100',
            'proveedor' => 'mock',
            'monto' => 100.00,
            'estado' => Pago::ESTADO_COMPLETADO,
            'idempotency_key' => 'idemp_bitemporal_100',
            'pagado_at' => $fechaCobroEnero . ' 11:00:00',
            'created_at' => $fechaCobroEnero . ' 11:00:00',
        ]);
        self::$pagoRepository->create($pago);
        self::$pagoRepository->actualizarEstadoPagoCita($citaId, 'pagado');

        // 2. Reembolsar en Febrero con fecha específica de reembolso
        self::$pagoRepository->actualizarEstadoPago(
            (int)$pago->id,
            Pago::ESTADO_REEMBOLSADO,
            'tx_reembolso_feb',
            $fechaCobroEnero . ' 11:00:00',
            ['motivo' => 'devolucion_febrero'],
            'mock',
            $fechaReembolsoFebrero . ' 15:30:00'
        );
        self::$pagoRepository->actualizarEstadoPagoCita($citaId, 'reembolsado');

        // 3. Consultar reporte para el mes de Enero por fecha de cobro:
        // El cobro permanece intacto en Enero: cobros = 100.00, reembolsos = 0.00, neto = 100.00
        $repEnero = self::$reporteService->consultarReporte([
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-01-31',
            'criterio_fecha' => 'cobro',
        ]);

        $this->assertSame(ReporteService::STATUS_OK, $repEnero['status']);
        $this->assertSame(100.00, $repEnero['resumen_global']['cobros_confirmados_total']);
        $this->assertSame(0.00, $repEnero['resumen_global']['reembolsos_total']);
        $this->assertSame(100.00, $repEnero['resumen_global']['neto_cobrado_total']);

        // 4. Consultar reporte para el mes de Febrero por fecha de cobro:
        // En Febrero solo ocurrió el reembolso: cobros = 0.00, reembolsos = 100.00, neto = -100.00
        $repFebrero = self::$reporteService->consultarReporte([
            'fecha_inicio' => '2026-02-01',
            'fecha_fin' => '2026-02-28',
            'criterio_fecha' => 'cobro',
        ]);

        $this->assertSame(ReporteService::STATUS_OK, $repFebrero['status']);
        $this->assertSame(0.00, $repFebrero['resumen_global']['cobros_confirmados_total']);
        $this->assertSame(100.00, $repFebrero['resumen_global']['reembolsos_total']);
        $this->assertSame(-100.00, $repFebrero['resumen_global']['neto_cobrado_total']);

        // Verificar resumen por profesional en Febrero
        $this->assertNotEmpty($repFebrero['resumen_profesionales']);
        $profElena = null;
        foreach ($repFebrero['resumen_profesionales'] as $pItem) {
            if ($pItem['profesional_id'] === $profId) {
                $profElena = $pItem;
                break;
            }
        }
        $this->assertNotNull($profElena);
        $this->assertSame(0.00, $profElena['ingreso_pagado']);
        $this->assertSame(100.00, $profElena['reembolsos']);
        $this->assertSame(-100.00, $profElena['neto_cobrado']);

        // 5. Validar exportación CSV en Febrero reflejando los netos negativos
        $this->autenticarAdmin();
        $_GET = ['fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-02-28', 'criterio_fecha' => 'cobro'];
        $router = new Router();
        ob_start();
        try {
            AdminReporteController::exportarCsv($router);
        } catch (AppTerminationException $e) {
            $this->assertSame(200, $e->getStatusCode());
        }
        $csv = (string)ob_get_clean();
        $this->assertStringContainsString('-100.00', $csv);
    }
}
