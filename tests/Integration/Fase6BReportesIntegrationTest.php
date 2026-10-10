<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Controllers\ReporteController;
use Model\ActiveRecord;
use Model\BloqueoProfesional;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Profesional;
use Model\Servicio;
use Model\Usuario;
use MVC\Router;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\ProfesionalRepository;
use Repositories\ReporteRepository;
use Repositories\ServicioRepository;
use Repositories\UsuarioRepository;
use Services\CitaService;
use Services\ReporteService;

/**
 * Pruebas de integración para Fase 6B: Reportes Administrativos (`GET /admin/reportes` y `GET /admin/reportes/exportar`).
 * Verifica contra MySQL 8 real:
 * - Protección por `isAdmin()` frente a visitantes no autenticados y clientes sin rol administrador.
 * - Cálculo exacto de ocupación respecto de las horas disponibles netas de turno (descontando descansos y bloqueos).
 * - Preservación de importes, nombres y duraciones históricos capturados al reservar tras editar o eliminar servicios del catálogo.
 * - Distinción explícita entre `importe_reservado_total` e `ingreso_pagado_total` (`$0.00` mientras el pago esté pendiente).
 * - Filtros combinados por rango de fechas, profesional (`profesional_id` y `'sin_profesional'`) y servicio (`servicio_id`),
 *   estados vacíos y rechazo `422` ante parámetros inválidos.
 * - Exportación CSV segura con neutralización de *CSV Formula Injection* y cabeceras HTTP apropiadas.
 */
class Fase6BReportesIntegrationTest extends TestCase
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

        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        ReporteController::setReporteService(null);
        ActiveRecord::setDB(self::$db);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        ReporteController::setReporteService(null);
        ActiveRecord::setDB(self::$db);
    }

    private function autenticarAdmin(): void
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $_SESSION['nombre'] = 'Admin';
        $_SESSION['apellido'] = 'Salon';
        $_SESSION['email'] = 'admin@appsalon.com';
        $_SESSION['admin'] = '1';
    }

    private function crearUsuarioCliente(string $nombre = 'Carlos', string $apellido = 'Mendoza', string $email = 'carlos@correo.com'): int
    {
        $repo = new UsuarioRepository(self::$db);
        return $repo->create(new Usuario([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'password' => password_hash('Password123!', PASSWORD_BCRYPT),
            'telefono' => '0991234567',
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

    private function crearProfesionalConAgenda(
        string $nombre,
        array $servicioIds,
        int $diaSemana,
        string $horaInicio,
        string $horaFin,
        ?array $descanso = null,
        ?array $bloqueo = null
    ): int {
        $repo = new ProfesionalRepository(self::$db);
        $profId = $repo->createWithServicios(new Profesional([
            'nombre' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)) . '@appsalon.com',
            'telefono' => '0987654321',
            'activo' => 1,
        ]), $servicioIds);

        $repo->replaceHorarios($profId, [
            new HorarioProfesional([
                'profesionalId' => $profId,
                'dia_semana' => $diaSemana,
                'hora_inicio' => $horaInicio,
                'hora_fin' => $horaFin,
            ]),
        ]);

        if ($descanso !== null) {
            $repo->createDescanso(new DescansoProfesional([
                'profesionalId' => $profId,
                'dia_semana' => $diaSemana,
                'hora_inicio' => $descanso['inicio'],
                'hora_fin' => $descanso['fin'],
                'motivo' => $descanso['motivo'] ?? 'Almuerzo',
            ]));
        }

        if ($bloqueo !== null) {
            $repo->createBloqueo(new BloqueoProfesional([
                'profesionalId' => $profId,
                'fecha_inicio' => $bloqueo['fecha_inicio'],
                'fecha_fin' => $bloqueo['fecha_fin'],
                'hora_inicio' => $bloqueo['hora_inicio'] ?? null,
                'hora_fin' => $bloqueo['hora_fin'] ?? null,
                'motivo' => $bloqueo['motivo'] ?? 'Capacitación',
            ]));
        }

        return $profId;
    }

    private function obtenerProximoLunes(): string
    {
        $tz = new \DateTimeZone(ReporteService::TIMEZONE);
        $cursor = (new \DateTimeImmutable('now', $tz))->modify('+2 days');
        while ((int)$cursor->format('N') !== 1) {
            $cursor = $cursor->modify('+1 day');
        }
        return $cursor->format('Y-m-d');
    }

    public function testPermisosAdministrativosEnReportesYExportarCsv(): void
    {
        $router = new Router();

        // 1. Visitante no autenticado -> bloqueado con 302 en HTML y 403 en JSON
        $_SESSION = [];
        try {
            ReporteController::index($router);
            $this->fail('Visitante sin sesión no debe acceder a /admin/reportes');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        try {
            ReporteController::exportarCsv($router);
            $this->fail('Visitante sin sesión no debe acceder a /admin/reportes/exportar');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        // 2. Cliente autenticado sin rol admin -> bloqueado con 302 en HTML y 403 en JSON
        $_SESSION = [
            'login' => true,
            'id' => 15,
            'nombre' => 'Cliente',
            'apellido' => 'NoAdmin',
            'admin' => '0',
        ];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        ob_start();
        try {
            ReporteController::index($router);
            $this->fail('Cliente sin rol admin no debe acceder a /admin/reportes');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        ob_start();
        try {
            ReporteController::exportarCsv($router);
            $this->fail('Cliente sin rol admin no debe acceder a /admin/reportes/exportar');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }
    }

    public function testCalculoOcupacionEImportesHistoricosAnteEdicionYEliminacionDeServiciosEnCatalogo(): void
    {
        $fechaLunes = $this->obtenerProximoLunes();
        $clienteId = $this->crearUsuarioCliente();

        $srv1Id = $this->crearServicio('Corte Clásico', '80.00', 30);
        $srv2Id = $this->crearServicio('Tratamiento Capilar', '120.00', 60);

        // Sofía Andrade: Lunes 09:00-18:00 (540 min), descanso 13:00-14:00 (60 min), bloqueo 15:00-16:30 (90 min)
        // Minutos disponibles netos en $fechaLunes = 540 - 60 - 90 = 390 min (6.50 h)
        $profSofiaId = $this->crearProfesionalConAgenda(
            'Sofía Andrade',
            [$srv1Id, $srv2Id],
            1,
            '09:00',
            '18:00',
            ['inicio' => '13:00', 'fin' => '14:00'],
            [
                'fecha_inicio' => $fechaLunes,
                'fecha_fin' => $fechaLunes,
                'hora_inicio' => '15:00',
                'hora_fin' => '16:30',
            ]
        );

        // Crear reserva con Sofía Andrade: 10:00 - 11:30 (90 min), $80.00 + $120.00 = $200.00
        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $resReserva = $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profSofiaId,
            'fecha' => $fechaLunes,
            'hora' => '10:00',
            'servicios' => [$srv1Id, $srv2Id],
        ]);
        $this->assertSame(CitaService::STATUS_OK, $resReserva['status']);

        // Insertar además una cita histórica sin profesional (profesionalId = NULL) en la misma fecha con Servicio 1 ($80.00)
        $stmtHist = self::$db->prepare("INSERT INTO citas (fecha, hora, usuarioId, profesionalId) VALUES (?, '12:00:00', ?, NULL)");
        $stmtHist->bind_param('si', $fechaLunes, $clienteId);
        $stmtHist->execute();
        $citaHistId = (int)$stmtHist->insert_id;
        $stmtHist->close();

        $stmtCsHist = self::$db->prepare(
            "INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
             VALUES (?, ?, 'Corte Clásico', 80.00, 30)"
        );
        $stmtCsHist->bind_param('ii', $citaHistId, $srv1Id);
        $stmtCsHist->execute();
        $stmtCsHist->close();

        // Ahora editar el Servicio 1 en el catálogo ($80.00 -> $160.00, 30 min -> 45 min)
        // y eliminar el Servicio 2 del catálogo (ON DELETE SET NULL en citasservicios)
        self::$db->query("UPDATE servicios SET nombre = 'Corte VIP Modificado', precio = 160.00, duracion_minutos = 45 WHERE id = {$srv1Id}");
        self::$db->query("DELETE FROM servicios WHERE id = {$srv2Id}");

        // Consultar el reporte administrativo mediante ReporteController::index
        $this->autenticarAdmin();
        $_GET = [
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
        ];

        $router = new Router();
        ob_start();
        ReporteController::index($router);
        $html = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        // Importe reservado histórico: $200.00 (Sofía) + $80.00 (histórica) = $280.00 (NO usa el precio nuevo $160.00 ni pierde el servicio borrado $120.00)
        $this->assertStringContainsString('$280.00', $html);
        // Ingreso pagado debe mostrarse explícitamente en $0.00
        $this->assertStringContainsString('$0.00', $html);
        $this->assertStringContainsString('Pendiente de cobro (sin pago registrado)', $html);

        // Ocupación de Sofía Andrade: 90 min ocupados / 390 min disponibles = 23.08%
        $this->assertStringContainsString('90 min /', $html);
        $this->assertStringContainsString('390 min', $html);
        $this->assertStringContainsString('23.08%', $html);

        // Nombres históricos preservados y fila de citas históricas sin profesional presente
        $this->assertStringContainsString('Corte Clásico', $html);
        $this->assertStringContainsString('Tratamiento Capilar', $html);
        $this->assertStringContainsString('(Eliminado del catálogo)', $html);
        $this->assertStringContainsString('Sin profesional asignado (citas históricas)', $html);
    }

    public function testFiltrosCombinadosEstadoVacioYRechazo422EnVistaYExportacion(): void
    {
        $fechaLunes = $this->obtenerProximoLunes();
        $clienteId = $this->crearUsuarioCliente();
        $srv1Id = $this->crearServicio('Corte de Cabello Hombre', '80.00', 30);
        $srv2Id = $this->crearServicio('Corte de Barba', '60.00', 30);

        $profSofiaId = $this->crearProfesionalConAgenda('Sofía Andrade', [$srv1Id, $srv2Id], 1, '09:00', '17:00');
        $profValeriaId = $this->crearProfesionalConAgenda('Valeria Castro', [$srv1Id, $srv2Id], 1, '09:00', '17:00');

        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profSofiaId,
            'fecha' => $fechaLunes,
            'hora' => '10:00',
            'servicios' => [$srv1Id, $srv2Id],
        ]);

        $this->autenticarAdmin();
        $router = new Router();

        // 1. Filtrar por profesional 1 y servicio 2 -> subtotal atribuible al servicio 2 = $60.00 (total cita = $140.00)
        $_GET = [
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
            'profesional_id' => (string)$profSofiaId,
            'servicio_id' => (string)$srv2Id,
        ];
        ob_start();
        ReporteController::index($router);
        $htmlFiltrado = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('$60.00', $htmlFiltrado);
        $this->assertStringContainsString('$140.00', $htmlFiltrado);
        $this->assertStringContainsString('Sofía Andrade', $htmlFiltrado);

        // 2. Filtrar por profesional 2 (Valeria Castro, sin citas) -> estado vacío informativo
        $_GET = [
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
            'profesional_id' => (string)$profValeriaId,
        ];
        ob_start();
        ReporteController::index($router);
        $htmlVacio = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('No se encontraron reservas para el rango de fechas y filtros seleccionados.', $htmlVacio);

        // 3. Rango inválido (fecha_inicio > fecha_fin) -> HTTP 422 tanto en vista como en exportarCsv
        $_GET = [
            'fecha_inicio' => '2026-11-20',
            'fecha_fin' => '2026-11-10',
        ];
        ob_start();
        ReporteController::index($router);
        $htmlInvalido = (string)ob_get_clean();

        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString('La fecha de inicio no puede ser posterior a la fecha de fin.', $htmlInvalido);

        ob_start();
        ReporteController::exportarCsv($router);
        $htmlExportInvalido = (string)ob_get_clean();

        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString('La fecha de inicio no puede ser posterior a la fecha de fin.', $htmlExportInvalido);
    }

    public function testExportacionCsvSeguraNeutralizaFormulaInjectionYEmiteCabeceras(): void
    {
        $fechaLunes = $this->obtenerProximoLunes();
        $clienteId = $this->crearUsuarioCliente('=CMD("calc")', 'Apellido', 'formula@correo.com');
        $srvId = $this->crearServicio('+SUM(1,2)', '95.00', 45);
        $profId = $this->crearProfesionalConAgenda('@PROF_INJECT', [$srvId], 1, '09:00', '17:00');

        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $citaService->reservarConProfesional($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $fechaLunes,
            'hora' => '10:00',
            'servicios' => [$srvId],
        ]);

        $this->autenticarAdmin();
        $_GET = [
            'fecha_inicio' => $fechaLunes,
            'fecha_fin' => $fechaLunes,
        ];

        $router = new Router();
        $csvOutput = '';
        ob_start();
        try {
            ReporteController::exportarCsv($router);
            $this->fail('exportarCsv debe finalizar con AppTerminationException(200)');
        } catch (AppTerminationException $e) {
            $this->assertSame(200, $e->getStatusCode());
        } finally {
            $csvOutput = (string)ob_get_clean();
        }

        $headers = ReporteController::getUltimosHeadersExportacion();
        $this->assertSame('text/csv; charset=UTF-8', $headers['Content-Type'] ?? '');
        $this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? '');
        $this->assertStringContainsString(
            sprintf('attachment; filename="reporte_reservas_%s_a_%s.csv"', $fechaLunes, $fechaLunes),
            $headers['Content-Disposition'] ?? ''
        );

        // Verificar neutralización de fórmulas con comilla simple inicial y columnas de importes
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvOutput);
        $this->assertStringContainsString("'=CMD(\"\"calc\"\") Apellido", $csvOutput);
        $this->assertStringContainsString("'+SUM(1,2)", $csvOutput);
        $this->assertStringContainsString("'@PROF_INJECT", $csvOutput);
        $this->assertStringContainsString('Importe Reservado Total (USD)', $csvOutput);
        $this->assertStringContainsString('Ingreso Pagado Total (USD)', $csvOutput);
        $this->assertStringContainsString('95.00,0.00', $csvOutput);
    }
}
