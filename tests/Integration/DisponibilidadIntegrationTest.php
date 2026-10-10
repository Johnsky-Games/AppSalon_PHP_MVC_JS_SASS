<?php

namespace Tests\Integration;

use AppTerminationException;
use Controllers\APIController;
use Model\ActiveRecord;
use Model\BloqueoProfesional;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Profesional;
use Model\Servicio;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\DisponibilidadService;

class DisponibilidadIntegrationTest extends TestCase
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
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['REQUEST_URI'] = '/api/disponibilidad';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);

        APIController::setDisponibilidadService(null);
        APIController::setServicioService(null);
        APIController::setCitaService(null);
        ActiveRecord::setDB(self::$db);
    }

    protected function tearDown(): void
    {
        APIController::setDisponibilidadService(null);
        APIController::setServicioService(null);
        APIController::setCitaService(null);
        ActiveRecord::setDB(self::$db);
    }

    private function autenticarCliente(): string
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = 10;
        $_SESSION['nombre'] = 'Cliente';
        $_SESSION['apellido'] = 'Prueba';
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;
        return $csrf;
    }

    /**
     * Ejecuta APIController::disponibilidad() capturando JSON y código HTTP.
     *
     * @return array{httpCode: int, json: array}
     */
    private function ejecutarEndpointDisponibilidad(array $query): array
    {
        $_GET = $query;
        http_response_code(200);
        ob_start();
        $code = 200;
        try {
            APIController::disponibilidad();
            $code = (int)http_response_code();
        } catch (AppTerminationException $e) {
            $code = $e->getStatusCode();
        }
        $raw = (string)ob_get_clean();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "La respuesta del endpoint debe ser JSON válido: {$raw}");

        return [
            'httpCode' => $code,
            'json' => $decoded
        ];
    }

    public function testEndpointRequiereAutenticacionActiva(): void
    {
        $_SESSION = [];
        $res = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => '1',
            'fecha' => '2026-10-12',
            'servicios' => '1'
        ]);

        $this->assertSame(401, $res['httpCode']);
        $this->assertFalse($res['json']['resultado']);
        $this->assertSame('unauthorized', $res['json']['status']);
        $this->assertSame('no_autenticado', $res['json']['codigo']);
    }

    public function testFlujoCompletoDisponibilidadEnMySqlRealConMultiplesFranjasDescansosBloqueosYEstadosDeError(): void
    {
        $this->autenticarCliente();

        $servRepo = new ServicioRepository(self::$db);
        $profRepo = new ProfesionalRepository(self::$db);

        // 1. Sembrar servicios en catálogo:
        // s1 = 30 min, s2 = 45 min, s3 = 60 min (no asignado a la profesional activa)
        $s1 = new Servicio(['nombre' => 'Corte Clásico', 'precio' => '80.00', 'duracion_minutos' => 30]);
        $s2 = new Servicio(['nombre' => 'Barba Premium', 'precio' => '60.00', 'duracion_minutos' => 45]);
        $s3 = new Servicio(['nombre' => 'Alisado Keratina', 'precio' => '200.00', 'duracion_minutos' => 60]);
        $idS1 = $servRepo->create($s1);
        $idS2 = $servRepo->create($s2);
        $idS3 = $servRepo->create($s3);

        // 2. Crear profesional activa (realiza s1 y s2) y profesional inactivo
        $profActiva = new Profesional(['nombre' => 'Sofía Andrade', 'activo' => 1]);
        $idProfActiva = $profRepo->createWithServicios($profActiva, [$idS1, $idS2]);

        $profInactivo = new Profesional(['nombre' => 'Mateo Inactivo', 'activo' => 0]);
        $idProfInactivo = $profRepo->createWithServicios($profInactivo, [$idS1, $idS2, $idS3]);

        // 3. Configurar horarios para Sofía Andrade:
        // - Lunes (dia_semana = 1, ej. 2026-10-12): 09:00-14:00
        // - Martes (dia_semana = 2, ej. 2026-10-13): dos franjas 08:00-11:00 y 14:00-16:00
        $profRepo->replaceHorarios($idProfActiva, [
            new HorarioProfesional(['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '14:00']),
            new HorarioProfesional(['dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '11:00']),
            new HorarioProfesional(['dia_semana' => 2, 'hora_inicio' => '14:00', 'hora_fin' => '16:00']),
        ]);

        // 4. Configurar descanso el Lunes de 11:00 a 12:00
        $profRepo->createDescanso(new DescansoProfesional([
            'profesionalId' => $idProfActiva,
            'dia_semana' => 1,
            'hora_inicio' => '11:00',
            'hora_fin' => '12:00',
            'motivo' => 'Almuerzo'
        ]));

        // 5. Configurar bloqueo parcial el Lunes 2026-10-12 de 12:45 a 13:15 y bloqueo de día completo el Lunes 2026-10-19
        $profRepo->createBloqueo(new BloqueoProfesional([
            'profesionalId' => $idProfActiva,
            'fecha_inicio' => '2026-10-12',
            'fecha_fin' => '2026-10-12',
            'hora_inicio' => '12:45',
            'hora_fin' => '13:15',
            'motivo' => 'Trámite bancario'
        ]));
        $profRepo->createBloqueo(new BloqueoProfesional([
            'profesionalId' => $idProfActiva,
            'fecha_inicio' => '2026-10-19',
            'fecha_fin' => '2026-10-19',
            'hora_inicio' => null,
            'hora_fin' => null,
            'motivo' => 'Capacitación día completo'
        ]));

        // --- CASO A: Solicitud inválida (422 solicitud_invalida) ---
        $resInvalida = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => (string)$idProfActiva,
            'fecha' => '2026-02-30',
            'servicios' => (string)$idS1
        ]);
        $this->assertSame(422, $resInvalida['httpCode']);
        $this->assertSame(DisponibilidadService::STATUS_INVALID, $resInvalida['json']['status']);
        $this->assertSame(DisponibilidadService::CODIGO_SOLICITUD_INVALIDA, $resInvalida['json']['codigo']);

        // --- CASO B: Profesional inexistente (404 profesional_no_encontrado) ---
        $resNoExiste = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => '99999',
            'fecha' => '2026-10-12',
            'servicios' => (string)$idS1
        ]);
        $this->assertSame(404, $resNoExiste['httpCode']);
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_NOT_FOUND, $resNoExiste['json']['status']);
        $this->assertSame(DisponibilidadService::CODIGO_PROFESIONAL_NO_ENCONTRADO, $resNoExiste['json']['codigo']);

        // --- CASO C: Profesional inactivo (409 profesional_inactivo) ---
        $resInactivo = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => (string)$idProfInactivo,
            'fecha' => '2026-10-12',
            'servicios' => (string)$idS1
        ]);
        $this->assertSame(409, $resInactivo['httpCode']);
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_INACTIVE, $resInactivo['json']['status']);
        $this->assertSame(DisponibilidadService::CODIGO_PROFESIONAL_INACTIVO, $resInactivo['json']['codigo']);

        // --- CASO D: Servicios incompatibles (422 servicios_incompatibles) ---
        $resIncompatible = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => (string)$idProfActiva,
            'fecha' => '2026-10-12',
            'servicios' => "{$idS1},{$idS3}"
        ]);
        $this->assertSame(422, $resIncompatible['httpCode']);
        $this->assertSame(DisponibilidadService::STATUS_INCOMPATIBLE_SERVICES, $resIncompatible['json']['status']);
        $this->assertSame(DisponibilidadService::CODIGO_SERVICIOS_INCOMPATIBLES, $resIncompatible['json']['codigo']);
        $this->assertSame([$idS3], $resIncompatible['json']['servicios_incompatibles']);

        // --- CASO E: Lunes 2026-10-12 con s1 (30m) + s2 (45m) = 75m (ignorando duracion=5 enviada por cliente) ---
        // Turno 09:00-14:00, descanso 11:00-12:00, bloqueo 12:45-13:15 -> ventanas libres:
        // [09:00, 11:00) (120m -> caben 09:00-10:15, 09:15-10:30, 09:30-10:45, 09:45-11:00)
        // [12:00, 12:45) (45m -> no cabe 75m)
        // [13:15, 14:00) (45m -> no cabe 75m)
        $resLunes = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => (string)$idProfActiva,
            'fecha' => '2026-10-12',
            'servicios' => "{$idS1},{$idS2}",
            'duracion' => '5',
            'duracion_minutos' => '5'
        ]);
        $this->assertSame(200, $resLunes['httpCode']);
        $this->assertTrue($resLunes['json']['resultado']);
        $this->assertTrue($resLunes['json']['disponible']);
        $this->assertSame(DisponibilidadService::STATUS_OK, $resLunes['json']['status']);
        $this->assertSame(DisponibilidadService::CODIGO_DISPONIBILIDAD_ENCONTRADA, $resLunes['json']['codigo']);
        $this->assertSame(75, $resLunes['json']['duracion_total_minutos']);
        $this->assertSame(15, $resLunes['json']['paso_minutos']);
        $this->assertSame('America/Guayaquil', $resLunes['json']['zona_horaria']);

        $paresLunes = array_map(fn(array $i) => $i['inicio'] . '-' . $i['fin'], $resLunes['json']['intervalos']);
        $this->assertSame([
            '09:00-10:15',
            '09:15-10:30',
            '09:30-10:45',
            '09:45-11:00', // termina exactamente cuando comienza el descanso 11:00-12:00
        ], $paresLunes);

        // Si en el mismo Lunes 2026-10-12 se pide solo s2 (45m), sí cabe exactamente en [12:00, 12:45) y en [13:15, 14:00)
        $resLunes45 = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => (string)$idProfActiva,
            'fecha' => '2026-10-12',
            'servicios' => (string)$idS2
        ]);
        $paresLunes45 = array_map(fn(array $i) => $i['inicio'] . '-' . $i['fin'], $resLunes45['json']['intervalos']);
        $this->assertContains('10:15-11:00', $paresLunes45); // fin exacto antes del descanso
        $this->assertContains('12:00-12:45', $paresLunes45); // encaja exacto entre fin de descanso (12:00) e inicio de bloqueo (12:45)
        $this->assertContains('13:15-14:00', $paresLunes45); // encaja exacto entre fin de bloqueo (13:15) y fin de turno (14:00)

        // --- CASO F: Martes 2026-10-13 con múltiples franjas (08:00-11:00 y 14:00-16:00) y duración 75m ---
        $resMartes = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => (string)$idProfActiva,
            'fecha' => '2026-10-13',
            'servicios' => [$idS1, $idS2]
        ]);
        $this->assertSame(200, $resMartes['httpCode']);
        $paresMartes = array_map(fn(array $i) => $i['inicio'] . '-' . $i['fin'], $resMartes['json']['intervalos']);
        $this->assertContains('08:00-09:15', $paresMartes);
        $this->assertContains('09:45-11:00', $paresMartes); // último de la primera franja
        $this->assertNotContains('10:00-11:15', $paresMartes); // jamás atraviesa hacia el hueco 11:00-14:00
        $this->assertContains('14:00-15:15', $paresMartes); // primero de la segunda franja
        $this->assertContains('14:45-16:00', $paresMartes); // último de la segunda franja

        // --- CASO G: Disponibilidad vacía por bloqueo de día completo (2026-10-19) o día sin turnos (2026-10-14 Miércoles) ---
        foreach (['2026-10-19', '2026-10-14'] as $fechaSinDisp) {
            $resVacia = $this->ejecutarEndpointDisponibilidad([
                'profesionalId' => (string)$idProfActiva,
                'fecha' => $fechaSinDisp,
                'servicios' => (string)$idS1
            ]);
            $this->assertSame(200, $resVacia['httpCode']);
            $this->assertTrue($resVacia['json']['resultado']);
            $this->assertFalse($resVacia['json']['disponible']);
            $this->assertSame(DisponibilidadService::STATUS_EMPTY, $resVacia['json']['status']);
            $this->assertSame(DisponibilidadService::CODIGO_DISPONIBILIDAD_VACIA, $resVacia['json']['codigo']);
            $this->assertSame([], $resVacia['json']['intervalos']);
        }
    }

    public function testEndpointDevuelveHttp500EnFalloSqlSinExponerDetallesInternos(): void
    {
        $this->autenticarCliente();

        $profRepoFallo = $this->createMock(ProfesionalRepository::class);
        $profRepoFallo->method('findById')->willThrowException(
            new PersistenceException('Error SQL interno confidencial en tabla profesionales')
        );
        $servRepo = new ServicioRepository(self::$db);

        APIController::setDisponibilidadService(new DisponibilidadService($profRepoFallo, $servRepo));

        $res = $this->ejecutarEndpointDisponibilidad([
            'profesionalId' => '1',
            'fecha' => '2026-10-12',
            'servicios' => '1'
        ]);

        $this->assertSame(500, $res['httpCode']);
        $this->assertFalse($res['json']['resultado']);
        $this->assertFalse($res['json']['disponible']);
        $this->assertSame(DisponibilidadService::STATUS_ERROR, $res['json']['status']);
        $this->assertSame(DisponibilidadService::CODIGO_ERROR_PERSISTENCIA, $res['json']['codigo']);
        $this->assertStringNotContainsString('confidencial', $res['json']['error']);
    }
}
