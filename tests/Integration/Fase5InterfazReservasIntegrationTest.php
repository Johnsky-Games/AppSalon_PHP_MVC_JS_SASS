<?php

namespace Tests\Integration;

use AppTerminationException;
use Controllers\AdminController;
use Controllers\APIController;
use Controllers\CitaController;
use Model\ActiveRecord;
use Model\HorarioProfesional;
use Model\Profesional;
use Model\Servicio;
use MVC\Router;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\CitaService;
use Services\DisponibilidadService;
use Services\ProfesionalService;
use Services\ServicioService;

/**
 * Pruebas de integración para Fase 5: Interfaz de Reservas y Panel Administrativo.
 * Verifica contra MySQL 8 real:
 * - `GET /api/profesionales`: autenticación 401, listado de profesionales activos, filtrado de compatibilidad por servicios y validación 422.
 * - `GET /api/mis-citas`: aislamiento estricto por `$_SESSION['id']`, representación de citas con profesional e históricas con `NULL`, y snapshot histórico de servicios.
 * - `POST /api/eliminar`: cancelación por cliente propietario en modo JSON (`200`) liberando disponibilidad del profesional, rechazo `403` ante intento de otro cliente y conservación de modo HTML (`302`).
 * - Política de `POST /api/citas`: rechazo `422` cuando el nuevo flujo (`modo_reserva=profesional` o `profesionalId` vacío) no envía un profesional válido.
 * - Vistas `/cita` (`CitaController::index`) y `/admin` (`AdminController::index`): fecha mínima en `America/Guayaquil`, profesional asignado, intervalo `[hora_inicio, hora_fin)`, snapshot histórico e identificación explícita de citas históricas sin inventar datos.
 */
class Fase5InterfazReservasIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        $conn = @new mysqli($host, $user, $pass, $name, $port);
        if ($conn->connect_errno) {
            self::markTestSkipped('No hay conexión MySQL de pruebas disponible: ' . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
        self::$db = $conn;
        ActiveRecord::setDB($conn);
    }

    protected function setUp(): void
    {
        if (!self::$db instanceof mysqli) {
            $this->markTestSkipped('MySQL no disponible.');
        }

        ActiveRecord::setDB(self::$db);
        APIController::setServicioService(null);
        APIController::setCitaService(null);
        APIController::setDisponibilidadService(null);
        APIController::setProfesionalService(null);
        CitaService::setExigirProfesional(null);

        self::$db->query('SET FOREIGN_KEY_CHECKS = 0');
        self::$db->query('TRUNCATE TABLE citasservicios');
        self::$db->query('TRUNCATE TABLE citas');
        self::$db->query('TRUNCATE TABLE bloqueos_profesionales');
        self::$db->query('TRUNCATE TABLE descansos_profesionales');
        self::$db->query('TRUNCATE TABLE horarios_profesionales');
        self::$db->query('TRUNCATE TABLE profesionales_servicios');
        self::$db->query('TRUNCATE TABLE profesionales');
        self::$db->query('TRUNCATE TABLE servicios');
        self::$db->query('TRUNCATE TABLE usuarios');
        self::$db->query('SET FOREIGN_KEY_CHECKS = 1');

        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = '';
        $_SERVER['REQUEST_URI'] = '/api/citas';
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_REFERER']);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        APIController::setServicioService(null);
        APIController::setCitaService(null);
        APIController::setDisponibilidadService(null);
        APIController::setProfesionalService(null);
        CitaService::setExigirProfesional(null);
    }

    private function crearUsuario(
        string $email,
        string $admin = '0',
        string $nombre = 'Cliente',
        string $apellido = 'Prueba'
    ): int {
        $stmt = self::$db->prepare(
            "INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado)
             VALUES (?, ?, ?, ?, '0991234567', ?, 1)"
        );
        $hash = password_hash('Password123!', PASSWORD_BCRYPT);
        $adminInt = (int)$admin;
        $stmt->bind_param('ssssi', $nombre, $apellido, $email, $hash, $adminInt);
        $stmt->execute();
        $id = (int)self::$db->insert_id;
        $stmt->close();
        return $id;
    }

    private function crearServicio(string $nombre, string $precio, int $duracionMinutos = 30): int
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
        array $horarios = [],
        int $activo = 1
    ): int {
        $repo = new ProfesionalRepository(self::$db);
        $profId = $repo->createWithServicios(
            new Profesional(['nombre' => $nombre, 'activo' => $activo]),
            $servicioIds
        );

        if (!empty($horarios)) {
            $entidades = [];
            foreach ($horarios as $h) {
                $entidades[] = new HorarioProfesional([
                    'profesionalId' => $profId,
                    'dia_semana' => $h['dia_semana'],
                    'hora_inicio' => $h['hora_inicio'],
                    'hora_fin' => $h['hora_fin'],
                ]);
            }
            $repo->replaceHorarios($profId, $entidades);
        }

        return $profId;
    }

    private function proximoLunesFuturo(): string
    {
        $tz = new \DateTimeZone('America/Guayaquil');
        $hoy = new \DateTimeImmutable('now', $tz);
        return $hoy->modify('next monday')->format('Y-m-d');
    }

    private function autenticarUsuario(int $usuarioId, string $admin = '0', string $nombre = 'Cliente', string $apellido = 'Prueba'): string
    {
        $csrf = bin2hex(random_bytes(32));
        $_SESSION = [
            'login' => true,
            'id' => $usuarioId,
            'admin' => $admin,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'csrf_token' => $csrf,
        ];
        return $csrf;
    }

    private function ejecutarEndpointJson(callable $callable): array
    {
        ob_start();
        $code = 200;
        try {
            $callable();
            $code = (int)(http_response_code() ?: 200);
        } catch (AppTerminationException $e) {
            $code = $e->getStatusCode();
        } finally {
            $raw = (string)ob_get_clean();
        }

        return [
            'httpCode' => $code,
            'raw' => $raw,
            'json' => json_decode($raw, true),
        ];
    }

    public function testEndpointProfesionalesRequiereAutenticacionYFiltraCompatibilidadPorServicios(): void
    {
        $s1 = $this->crearServicio('Corte Ejecutivo', '20.00', 30);
        $s2 = $this->crearServicio('Barba Clásica', '15.00', 30);
        $s3 = $this->crearServicio('Coloración Completa', '65.00', 60);

        $profIntegral = $this->crearProfesionalConAgenda('Sofía Integral', [$s1, $s2, $s3], [], 1);
        $profParcial = $this->crearProfesionalConAgenda('Marco Barbería', [$s1, $s2], [], 1);
        $this->crearProfesionalConAgenda('Inactivo No Listar', [$s1, $s2, $s3], [], 0);

        // 1. No autenticado -> 401
        $_SESSION = [];
        $_GET = [];
        $resNoAuth = $this->ejecutarEndpointJson(fn() => APIController::profesionales());
        $this->assertSame(401, $resNoAuth['httpCode']);
        $this->assertFalse($resNoAuth['json']['resultado']);
        $this->assertSame('no_autenticado', $resNoAuth['json']['codigo']);

        // 2. Autenticado sin filtro -> devuelve solo los 2 profesionales activos
        $clienteId = $this->crearUsuario('prof.api@appsalon.test');
        $this->autenticarUsuario($clienteId);

        $_GET = [];
        $resTodos = $this->ejecutarEndpointJson(fn() => APIController::profesionales());
        $this->assertSame(200, $resTodos['httpCode']);
        $this->assertTrue($resTodos['json']['resultado']);
        $this->assertCount(2, $resTodos['json']['profesionales']);
        $this->assertCount(2, $resTodos['json']['profesionales_compatibles']);

        // 3. Filtrando por servicios s1 y s3 -> solo Sofía Integral es compatible con ambos
        $_GET = ['servicios' => "{$s1},{$s3}"];
        $resFiltro = $this->ejecutarEndpointJson(fn() => APIController::profesionales());
        $this->assertSame(200, $resFiltro['httpCode']);
        $this->assertCount(2, $resFiltro['json']['profesionales']);
        $this->assertCount(1, $resFiltro['json']['profesionales_compatibles']);
        $this->assertSame($profIntegral, $resFiltro['json']['profesionales_compatibles'][0]['id']);

        $mapaComp = [];
        foreach ($resFiltro['json']['profesionales'] as $p) {
            $mapaComp[$p['id']] = $p['compatible'];
        }
        $this->assertTrue($mapaComp[$profIntegral]);
        $this->assertFalse($mapaComp[$profParcial]);

        // 4. Parámetro servicios inválido -> 422
        $_GET = ['servicios' => '1,abc'];
        $resInvalido = $this->ejecutarEndpointJson(fn() => APIController::profesionales());
        $this->assertSame(422, $resInvalido['httpCode']);
        $this->assertFalse($resInvalido['json']['resultado']);
        $this->assertSame('solicitud_invalida', $resInvalido['json']['codigo']);
    }

    public function testMisCitasAislaPorSesionMuestraSnapshotHistoricoYCitasAntiguasSinProfesional(): void
    {
        $clienteA = $this->crearUsuario('cliente.a@appsalon.test', '0', 'Ana', 'Mora');
        $clienteB = $this->crearUsuario('cliente.b@appsalon.test', '0', 'Beto', 'Lara');

        $serv1 = $this->crearServicio('Corte Autor', '25.00', 45);
        $serv2 = $this->crearServicio('Hidratación Capilar', '40.00', 30);
        $lunes = $this->proximoLunesFuturo();

        $profId = $this->crearProfesionalConAgenda(
            'Valeria Estilista',
            [$serv1, $serv2],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '18:00']]
        );

        // 1. Reserva nueva con profesional para Cliente A en [10:00, 11:15)
        $csrfA = $this->autenticarUsuario($clienteA, '0', 'Ana', 'Mora');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $csrfA,
            'modo_reserva' => 'profesional',
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'servicios' => "{$serv1},{$serv2}"
        ];
        $resReservaA = $this->ejecutarEndpointJson(fn() => APIController::guardar());
        $this->assertSame(200, $resReservaA['httpCode']);
        $idCitaConProf = (int)$resReservaA['json']['id'];

        // 2. Insertar una cita histórica sin profesional (NULL) para Cliente A
        self::$db->query(
            "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId)
             VALUES ('{$lunes}', '15:00:00', NULL, NULL, NULL, {$clienteA}, NULL)"
        );
        $idCitaHistorica = (int)self::$db->insert_id;
        self::$db->query(
            "INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
             VALUES ({$idCitaHistorica}, {$serv1}, NULL, NULL, NULL)"
        );

        // 3. Reserva para Cliente B (que Cliente A NUNCA debe ver)
        $csrfB = $this->autenticarUsuario($clienteB, '0', 'Beto', 'Lara');
        $_POST = [
            'csrf_token' => $csrfB,
            'modo_reserva' => 'profesional',
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '12:00',
            'servicios' => (string)$serv1
        ];
        $resReservaB = $this->ejecutarEndpointJson(fn() => APIController::guardar());
        $this->assertSame(200, $resReservaB['httpCode']);
        $idCitaClienteB = (int)$resReservaB['json']['id'];

        // 4. Modificar el servicio 2 en el catálogo para comprobar que la cita de Cliente A conserva su snapshot histórico
        $servicioService = new ServicioService(new ServicioRepository(self::$db));
        $servicioService->actualizar($serv2, [
            'nombre' => 'Hidratación Editada Catálogo',
            'precio' => '99.00',
            'duracion_minutos' => '90',
        ]);

        // 5. Consultar GET /api/mis-citas autenticado como Cliente A (intentando inyectar usuarioId de B en GET)
        $this->autenticarUsuario($clienteA, '0', 'Ana', 'Mora');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['usuarioId' => (string)$clienteB];
        $resMisCitasA = $this->ejecutarEndpointJson(fn() => APIController::misCitas());

        $this->assertSame(200, $resMisCitasA['httpCode']);
        $this->assertTrue($resMisCitasA['json']['resultado']);
        $citasA = $resMisCitasA['json']['citas'];
        $this->assertCount(2, $citasA, 'Cliente A solo debe ver sus 2 citas y nunca la de Cliente B');

        $idsDevueltos = array_column($citasA, 'id');
        $this->assertContains($idCitaConProf, $idsDevueltos);
        $this->assertContains($idCitaHistorica, $idsDevueltos);
        $this->assertNotContains($idCitaClienteB, $idsDevueltos);

        $porId = [];
        foreach ($citasA as $c) {
            $porId[$c['id']] = $c;
        }

        // Verificar cita con profesional y su snapshot histórico inmutable
        $citaNueva = $porId[$idCitaConProf];
        $this->assertSame($profId, $citaNueva['profesionalId']);
        $this->assertSame('Valeria Estilista', $citaNueva['profesional_nombre']);
        $this->assertFalse($citaNueva['es_historica_sin_profesional']);
        $this->assertSame('10:00', $citaNueva['hora_inicio']);
        $this->assertSame('11:15', $citaNueva['hora_fin']);
        $this->assertSame(75, $citaNueva['duracion_total_minutos']);
        $this->assertSame('65.00', $citaNueva['total_formateado']);
        $this->assertTrue($citaNueva['puede_cancelar']);
        $this->assertSame('Hidratación Capilar', $citaNueva['servicios'][1]['nombre']);
        $this->assertSame('40.00', $citaNueva['servicios'][1]['precio']);
        $this->assertSame(30, $citaNueva['servicios'][1]['duracion_minutos']);

        // Verificar cita histórica sin profesional (no inventa profesional ni intervalo)
        $citaHist = $porId[$idCitaHistorica];
        $this->assertNull($citaHist['profesionalId']);
        $this->assertNull($citaHist['profesional_nombre']);
        $this->assertTrue($citaHist['es_historica_sin_profesional']);
        $this->assertNull($citaHist['hora_inicio']);
        $this->assertNull($citaHist['hora_fin']);
        $this->assertNull($citaHist['duracion_total_minutos']);
        $this->assertSame('15:00', $citaHist['hora']);
    }

    public function testCancelacionDeCitaEnModoJsonPorPropietarioLiberaDisponibilidadYRechazaIntrusoCon403(): void
    {
        $clientePropietario = $this->crearUsuario('dueno.f5@appsalon.test');
        $clienteIntruso = $this->crearUsuario('intruso.f5@appsalon.test');
        $serv60 = $this->crearServicio('Alisado Express', '55.00', 60);
        $lunes = $this->proximoLunesFuturo();

        $profId = $this->crearProfesionalConAgenda(
            'Camila Especialista',
            [$serv60],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '11:00']]
        );

        $csrfProp = $this->autenticarUsuario($clientePropietario);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $csrfProp,
            'modo_reserva' => 'profesional',
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$serv60
        ];
        $resReserva = $this->ejecutarEndpointJson(fn() => APIController::guardar());
        $this->assertSame(200, $resReserva['httpCode']);
        $idCita = (int)$resReserva['json']['id'];

        // 1. Intruso intenta cancelar por JSON -> 403 y la cita no se elimina
        $csrfIntruso = $this->autenticarUsuario($clienteIntruso);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = [
            'id' => (string)$idCita,
            'csrf_token' => $csrfIntruso,
        ];
        $resIntruso = $this->ejecutarEndpointJson(fn() => APIController::eliminar());
        $this->assertSame(403, $resIntruso['httpCode']);
        $this->assertFalse($resIntruso['json']['resultado']);
        $this->assertSame('no_autorizado', $resIntruso['json']['codigo']);

        // 2. Propietario cancela su cita con Accept: application/json -> 200 JSON y libera [09:00, 10:00)
        $csrfProp = $this->autenticarUsuario($clientePropietario);
        $_POST = [
            'id' => (string)$idCita,
            'csrf_token' => $csrfProp,
        ];
        $resCancelacion = $this->ejecutarEndpointJson(fn() => APIController::eliminar());
        $this->assertSame(200, $resCancelacion['httpCode']);
        $this->assertTrue($resCancelacion['json']['resultado']);
        $this->assertSame($idCita, $resCancelacion['json']['id']);

        // Verificar que 09:00-10:00 vuelve a ofrecerse en GET /api/disponibilidad
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'servicios' => (string)$serv60,
        ];
        $resDisp = $this->ejecutarEndpointJson(fn() => APIController::disponibilidad());
        $this->assertSame(200, $resDisp['httpCode']);
        $pares = array_map(fn($i) => $i['inicio'] . '-' . $i['fin'], $resDisp['json']['intervalos']);
        $this->assertContains('09:00-10:00', $pares);
    }

    public function testVistasCitaYAdminRenderizanProfesionalIntervaloSnapshotHistoricoYCitasHistoricas(): void
    {
        $adminId = $this->crearUsuario('admin.f5@appsalon.test', '1', 'Admin', 'General');
        $clienteId = $this->crearUsuario('cliente.vista@appsalon.test', '0', 'Lucía', 'Pérez');
        $serv1 = $this->crearServicio('Peinado Gala', '45.00', 45);
        $lunes = $this->proximoLunesFuturo();

        $profId = $this->crearProfesionalConAgenda(
            'Daniela Maquilladora',
            [$serv1],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']]
        );

        // 1. Verificar vista /cita para el cliente (fechaMinima en America/Guayaquil, selector #profesional y #mis-citas)
        $this->autenticarUsuario($clienteId, '0', 'Lucía', 'Pérez');
        $router = new Router();
        ob_start();
        CitaController::index($router);
        $htmlCita = (string)ob_get_clean();

        $mananaGuayaquil = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $this->assertStringContainsString('id="profesional"', $htmlCita);
        $this->assertStringContainsString('id="contenedor-horarios-disponibles"', $htmlCita);
        $this->assertStringContainsString('id="mis-citas"', $htmlCita);
        $this->assertStringContainsString('min="' . $mananaGuayaquil . '"', $htmlCita);

        // 2. Crear cita con profesional y cita histórica sin profesional en la misma fecha
        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $resConProf = $citaService->reservar($clienteId, [
            'modo_reserva' => 'profesional',
            'profesionalId' => $profId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'servicios' => (string)$serv1,
        ]);
        $this->assertSame(CitaService::STATUS_OK, $resConProf['status']);

        self::$db->query(
            "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId)
             VALUES ('{$lunes}', '14:00:00', NULL, NULL, NULL, {$clienteId}, NULL)"
        );
        $idHistorica = (int)self::$db->insert_id;
        self::$db->query(
            "INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
             VALUES ({$idHistorica}, {$serv1}, NULL, NULL, NULL)"
        );

        // Editar el servicio en catálogo para verificar que la cita con profesional en /admin mantiene su snapshot
        $servicioService = new ServicioService(new ServicioRepository(self::$db));
        $servicioService->actualizar($serv1, [
            'nombre' => 'Peinado Editado Catálogo',
            'precio' => '80.00',
            'duracion_minutos' => '90',
        ]);

        // 3. Renderizar /admin para esa fecha
        $this->autenticarUsuario($adminId, '1', 'Admin', 'General');
        $_GET = ['fecha' => $lunes];
        ob_start();
        AdminController::index($router);
        $htmlAdmin = (string)ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('Daniela Maquilladora', $htmlAdmin);
        $this->assertStringContainsString('10:00 - 10:45 (45 min)', $htmlAdmin);
        $this->assertStringContainsString('Peinado Gala 45.00 (45 min)', $htmlAdmin);
        $this->assertStringContainsString('Sin profesional asignado (cita histórica)', $htmlAdmin);
    }
}
