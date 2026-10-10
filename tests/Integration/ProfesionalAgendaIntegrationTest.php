<?php

namespace Tests\Integration;

use AppTerminationException;
use Controllers\ProfesionalController;
use Model\ActiveRecord;
use Model\Servicio;
use MVC\Router;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Repositories\UsuarioRepository;
use Services\CitaService;
use Services\ProfesionalService;
use Services\ServicioService;

class ProfesionalAgendaIntegrationTest extends TestCase
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

        $this->limpiarTriggers();

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
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['REQUEST_URI'] = '/profesionales';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);

        ProfesionalController::setProfesionalService(null);
        ProfesionalController::setServicioService(null);
        ActiveRecord::setDB(self::$db);
    }

    protected function tearDown(): void
    {
        $this->limpiarTriggers();
        ProfesionalController::setProfesionalService(null);
        ProfesionalController::setServicioService(null);
        ActiveRecord::setDB(self::$db);
    }

    private function limpiarTriggers(): void
    {
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_prof_serv_insert");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_horarios_insert");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_descansos_insert");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_bloqueos_insert");
    }

    private function autenticarAdmin(): string
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $_SESSION['nombre'] = 'Admin';
        $_SESSION['apellido'] = 'Principal';
        $_SESSION['admin'] = '1';
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;
        return $csrf;
    }

    public function testControlDePermisosAdminYProteccionCsrfEnRutasDeProfesionalesYAgenda(): void
    {
        $router = new Router();

        // 1. Visitante no autenticado -> 302 a /
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach (['index', 'crear', 'actualizar', 'horarios'] as $accion) {
            try {
                ProfesionalController::$accion($router);
                $this->fail("Visitante debe ser redirigido en {$accion}");
            } catch (AppTerminationException $e) {
                $this->assertSame(302, $e->getStatusCode());
            }
        }

        // 2. Cliente autenticado sin rol admin -> 302 a /
        $_SESSION['login'] = true;
        $_SESSION['id'] = 15;
        $_SESSION['admin'] = '0';
        $csrfCliente = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrfCliente;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf_token' => $csrfCliente, 'nombre' => 'Intento Cliente'];
        foreach (['crear', 'cambiarEstado', 'horarios', 'crearDescanso', 'crearBloqueo'] as $accionPost) {
            try {
                ProfesionalController::$accionPost($router);
                $this->fail("Cliente no admin debe ser rechazado en {$accionPost}");
            } catch (AppTerminationException $e) {
                $this->assertSame(302, $e->getStatusCode());
            }
        }

        // 3. Administrador con CSRF inválido -> 403
        $this->autenticarAdmin();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['id'] = '1';
        $_POST = ['csrf_token' => 'token_invalido', 'nombre' => 'Sin CSRF'];

        foreach (['crear', 'actualizar', 'cambiarEstado', 'horarios', 'crearDescanso', 'eliminarDescanso', 'crearBloqueo', 'eliminarBloqueo'] as $accionAdmin) {
            ob_start();
            try {
                ProfesionalController::$accionAdmin($router);
                $this->fail("Admin sin CSRF válido debe recibir 403 en {$accionAdmin}");
            } catch (AppTerminationException $e) {
                $this->assertSame(403, $e->getStatusCode());
            } finally {
                ob_end_clean();
            }
        }
    }

    public function testCicloCompletoDeProfesionalServiciosDesactivacionHorariosDescansosYBloqueos(): void
    {
        $servRepo = new ServicioRepository(self::$db);
        $servService = new ServicioService($servRepo);

        $s1 = $servService->crear(['nombre' => 'Corte Caballero', 'precio' => '80.00', 'duracion_minutos' => '30'])['id'];
        $s2 = $servService->crear(['nombre' => 'Diseño de Barba', 'precio' => '60.00', 'duracion_minutos' => '25'])['id'];
        $s3 = $servService->crear(['nombre' => 'Tinte Completo', 'precio' => '180.00', 'duracion_minutos' => '90'])['id'];

        $profRepo = new ProfesionalRepository(self::$db);
        $profService = new ProfesionalService($profRepo, $servRepo);

        // 1. Crear profesional asignando servicios 1 y 2
        $creacion = $profService->crear([
            'id' => 999,
            'nombre' => 'Mateo Salazar',
            'activo' => '1',
            'servicios' => [$s1, $s2]
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $creacion['status']);
        $profId = $creacion['id'];
        $this->assertNotSame(999, $profId);

        $obtenido = $profRepo->findById($profId);
        $this->assertNotNull($obtenido);
        $this->assertSame('Mateo Salazar', $obtenido->nombre);
        $this->assertTrue($obtenido->estaActivo());
        $this->assertSame([(int)$s1, (int)$s2], $obtenido->servicioIds);
        $this->assertSame(['Corte Caballero', 'Diseño de Barba'], $obtenido->serviciosNombres);

        // 2. Actualizar profesional y cambiar servicios a [s2, s3]
        $actualizacion = $profService->actualizar($profId, [
            'id' => 888,
            'nombre' => 'Mateo Salazar Master',
            'activo' => '1',
            'servicios' => [$s2, $s3]
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $actualizacion['status']);
        $actualizado = $profRepo->findById($profId);
        $this->assertSame('Mateo Salazar Master', $actualizado->nombre);
        $this->assertSame([(int)$s2, (int)$s3], $actualizado->servicioIds);

        // 3. Configurar horarios semanales (Lunes=1 y Martes=2 de 09:00 a 18:00)
        $resHorarios = $profService->guardarHorariosSemanales($profId, [
            1 => ['activo' => '1', 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '18:00'],
            2 => ['activo' => '1', 'dia_semana' => 2, 'hora_inicio' => '10:00', 'hora_fin' => '19:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $resHorarios['status']);
        $this->assertCount(2, $profRepo->findHorariosByProfesional($profId));

        // 4. Registrar descanso válido el lunes (13:00 a 14:00)
        $resDescanso = $profService->crearDescanso($profId, [
            'dia_semana' => 1,
            'hora_inicio' => '13:00',
            'hora_fin' => '14:00',
            'motivo' => 'Hora de almuerzo'
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $resDescanso['status']);
        $descansoId = $resDescanso['id'];

        // 5. Registrar bloqueo por fecha completa y bloqueo por intervalo en fechas futuras
        $fechaFutura1 = (new \DateTimeImmutable('+5 days', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $fechaFutura2 = (new \DateTimeImmutable('+6 days', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');

        $resBloqueoDia = $profService->crearBloqueo($profId, [
            'fecha_inicio' => $fechaFutura1,
            'fecha_fin' => $fechaFutura1,
            'motivo' => 'Capacitación externa'
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $resBloqueoDia['status']);
        $this->assertTrue($resBloqueoDia['bloqueo']->esDiaCompleto());

        $resBloqueoIntervalo = $profService->crearBloqueo($profId, [
            'fecha_inicio' => $fechaFutura2,
            'fecha_fin' => $fechaFutura2,
            'hora_inicio' => '15:00',
            'hora_fin' => '17:00',
            'motivo' => 'Cita odontológica'
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $resBloqueoIntervalo['status']);
        $this->assertFalse($resBloqueoIntervalo['bloqueo']->esDiaCompleto());

        // 6. Desactivar profesional vía cambiarEstado y comprobar que conserva servicios, horarios, descansos y bloqueos
        $resDesactivar = $profService->cambiarEstado($profId, 0);
        $this->assertSame(ProfesionalService::STATUS_OK, $resDesactivar['status']);

        $agenda = $profService->obtenerAgendaCompleta($profId);
        $this->assertSame(ProfesionalService::STATUS_OK, $agenda['status']);
        $this->assertFalse($agenda['profesional']->estaActivo());
        $this->assertSame([(int)$s2, (int)$s3], $agenda['profesional']->servicioIds);
        $this->assertCount(2, $agenda['horarios']);
        $this->assertCount(1, $agenda['descansos']);
        $this->assertCount(2, $agenda['bloqueos']);

        // 7. Eliminar descanso y bloqueo
        $this->assertSame(ProfesionalService::STATUS_OK, $profService->eliminarDescanso($profId, $descansoId)['status']);
        $this->assertSame(ProfesionalService::STATUS_OK, $profService->eliminarBloqueo($profId, $resBloqueoIntervalo['id'])['status']);
        $this->assertCount(0, $profRepo->findDescansosByProfesional($profId));
        $this->assertCount(1, $profRepo->findBloqueosByProfesional($profId));
    }

    public function testRollbackTransaccionalYRespuestaHttp500AnteFallosSql(): void
    {
        $servRepo = new ServicioRepository(self::$db);
        $s1 = $servRepo->create(new Servicio(['nombre' => 'Servicio Test', 'precio' => '50.00', 'duracion_minutos' => '30']));

        $profRepo = new ProfesionalRepository(self::$db);
        $profService = new ProfesionalService($profRepo, $servRepo);

        // 1. Trigger que falla al insertar en profesionales_servicios -> debe hacer rollback de profesionales
        self::$db->query(
            "CREATE TRIGGER test_fail_prof_serv_insert BEFORE INSERT ON profesionales_servicios
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated failure in profesionales_servicios'"
        );

        $csrf = $this->autenticarAdmin();
        $router = new Router();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $csrf,
            'nombre' => 'Profesional No Debe Quedar Huerfano',
            'activo' => '1',
            'servicios' => [(string)$s1]
        ];

        ob_start();
        ProfesionalController::crear($router);
        $htmlCrear = ob_get_clean();

        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString('error de base de datos', $htmlCrear);
        $this->assertStringNotContainsString('Simulated failure', $htmlCrear);

        $conteoProf = (int)self::$db->query("SELECT COUNT(*) AS c FROM profesionales")->fetch_assoc()['c'];
        $this->assertSame(0, $conteoProf, 'El rollback debe evitar que quede un profesional sin sus servicios cuando falla la transacción');

        self::$db->query("DROP TRIGGER IF EXISTS test_fail_prof_serv_insert");

        // 2. Crear profesional con horario inicial y provocar fallo al reemplazar horarios -> debe conservar el horario previo
        $profId = $profService->crear(['nombre' => 'Valeria Rojas', 'servicios' => [$s1]])['id'];
        $profService->guardarHorariosSemanales($profId, [
            1 => ['activo' => '1', 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']
        ]);

        self::$db->query(
            "CREATE TRIGGER test_fail_horarios_insert BEFORE INSERT ON horarios_profesionales
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated failure in horarios_profesionales'"
        );

        $_GET['id'] = (string)$profId;
        $_POST = [
            'csrf_token' => $csrf,
            'accion' => 'guardar_horarios',
            'profesionalId' => (string)$profId,
            'horarios' => [
                2 => ['enviado' => '1', 'activo' => '1', 'dia_semana' => '2', 'hora_inicio' => '10:00', 'hora_fin' => '18:00']
            ]
        ];

        ob_start();
        ProfesionalController::horarios($router);
        $htmlHorarios = ob_get_clean();

        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString('error de base de datos', $htmlHorarios);
        $this->assertStringNotContainsString('Simulated failure', $htmlHorarios);

        $horariosConservados = $profRepo->findHorariosByProfesional($profId);
        $this->assertCount(1, $horariosConservados, 'El rollback en replaceHorarios debe conservar el horario original del lunes');
        $this->assertSame(1, $horariosConservados[0]->dia_semana);
        $this->assertSame('09:00', $horariosConservados[0]->hora_inicio);
    }

    public function testCompatibilidadConFlujoActualDeReservasSinAtribuirProfesional(): void
    {
        $usuarioRepo = new UsuarioRepository(self::$db);
        $clienteId = $usuarioRepo->create(new \Model\Usuario([
            'nombre' => 'Cliente',
            'apellido' => 'Actual',
            'email' => 'cliente.fase3a@correo.com',
            'password' => 'Password123!',
            'telefono' => '0991122334',
            'confirmado' => '1'
        ]));

        $servRepo = new ServicioRepository(self::$db);
        $idServicio = $servRepo->create(new Servicio([
            'nombre' => 'Corte Con Duración',
            'precio' => '75.00',
            'duracion_minutos' => '40'
        ]));

        $citaService = new CitaService(new CitaRepository(self::$db), $servRepo);
        $fechaLaborable = date('Y-m-d', strtotime('next Wednesday'));

        $resReserva = $citaService->reservar($clienteId, [
            'fecha' => $fechaLaborable,
            'hora' => '11:30',
            'servicios' => (string)$idServicio
        ]);

        $this->assertSame(CitaService::STATUS_OK, $resReserva['status']);
        $this->assertTrue($resReserva['resultado']['resultado']);
    }

    public function testControladorRechazaSolicitudesInvalidasConHttp422SinModificarBaseDeDatosYConservaMultiplesFranjasPorDia(): void
    {
        $servRepo = new ServicioRepository(self::$db);
        $s1 = $servRepo->create(new Servicio(['nombre' => 'Peinado', 'precio' => '65.00', 'duracion_minutos' => '45']));

        $profRepo = new ProfesionalRepository(self::$db);
        $profService = new ProfesionalService($profRepo, $servRepo);

        $profId = $profService->crear(['nombre' => 'Daniela Paredes', 'servicios' => [$s1]])['id'];

        // Sembrar dos franjas el mismo día (Lunes=1: 08:00-12:00 y 14:00-18:00) y una en Martes=2 (09:00-17:00)
        $resInicial = $profService->guardarHorariosSemanales($profId, [
            '1_0' => ['enviado' => '1', 'dia_semana' => 1, 'activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '12:00'],
            '1_1' => ['enviado' => '1', 'dia_semana' => 1, 'activo' => '1', 'hora_inicio' => '14:00', 'hora_fin' => '18:00'],
            '2_0' => ['enviado' => '1', 'dia_semana' => 2, 'activo' => '1', 'hora_inicio' => '09:00', 'hora_fin' => '17:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $resInicial['status']);
        $this->assertCount(3, $profRepo->findHorariosByProfesional($profId));

        $csrf = $this->autenticarAdmin();
        $router = new Router();
        $_GET['id'] = (string)$profId;

        $casosInvalidos = [
            'accion_desconocida' => [
                'csrf_token' => $csrf,
                'accion' => 'accion_inexistente',
                'profesionalId' => (string)$profId
            ],
            'accion_no_escalar' => [
                'csrf_token' => $csrf,
                'accion' => ['guardar_horarios'],
                'profesionalId' => (string)$profId
            ],
            'horarios_ausente' => [
                'csrf_token' => $csrf,
                'accion' => 'guardar_horarios',
                'profesionalId' => (string)$profId
            ],
            'horarios_escalar' => [
                'csrf_token' => $csrf,
                'accion' => 'guardar_horarios',
                'profesionalId' => (string)$profId,
                'horarios' => 'invalido'
            ],
            'activo_invalido' => [
                'csrf_token' => $csrf,
                'accion' => 'guardar_horarios',
                'profesionalId' => (string)$profId,
                'horarios' => [
                    '1_0' => [
                        'enviado' => '1',
                        'dia_semana' => '1',
                        'activo' => 'invalido',
                        'hora_inicio' => '08:00',
                        'hora_fin' => '12:00'
                    ]
                ]
            ]
        ];

        foreach ($casosInvalidos as $nombreCaso => $payload) {
            http_response_code(200);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $payload;

            ob_start();
            ProfesionalController::horarios($router);
            $html = ob_get_clean();

            $this->assertSame(422, http_response_code(), "El caso '{$nombreCaso}' debe responder HTTP 422");
            $this->assertStringContainsString('alerta error', $html, "El caso '{$nombreCaso}' debe mostrar alerta de error");

            $horariosActuales = $profRepo->findHorariosByProfesional($profId);
            $this->assertCount(3, $horariosActuales, "El caso '{$nombreCaso}' no debe modificar las 3 franjas en la base de datos");
            $this->assertSame(1, $horariosActuales[0]->dia_semana);
            $this->assertSame('08:00', $horariosActuales[0]->hora_inicio);
            $this->assertSame('12:00', $horariosActuales[0]->hora_fin);
            $this->assertSame(1, $horariosActuales[1]->dia_semana);
            $this->assertSame('14:00', $horariosActuales[1]->hora_inicio);
            $this->assertSame('18:00', $horariosActuales[1]->hora_fin);
            $this->assertSame(2, $horariosActuales[2]->dia_semana);
            $this->assertSame('09:00', $horariosActuales[2]->hora_inicio);
            $this->assertSame('17:00', $horariosActuales[2]->hora_fin);
        }

        // Comprobar que GET /profesionales/horarios renderiza ambas franjas del lunes (08:00-12:00 y 14:00-18:00)
        http_response_code(200);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        ob_start();
        ProfesionalController::horarios($router);
        $htmlGet = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('value="08:00"', $htmlGet);
        $this->assertStringContainsString('value="12:00"', $htmlGet);
        $this->assertStringContainsString('value="14:00"', $htmlGet);
        $this->assertStringContainsString('value="18:00"', $htmlGet);
        $this->assertStringContainsString('name="horarios[1_0][hora_inicio]"', $htmlGet);
        $this->assertStringContainsString('name="horarios[1_1][hora_inicio]"', $htmlGet);

        // Comprobar que un envío explícito y válido del formulario permite desactivar todos los días
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $csrf,
            'accion' => 'guardar_horarios',
            'profesionalId' => (string)$profId,
            'horarios' => [
                '1_0' => ['enviado' => '1', 'dia_semana' => '1', 'activo' => '0', 'hora_inicio' => '08:00', 'hora_fin' => '12:00'],
                '2_0' => ['enviado' => '1', 'dia_semana' => '2', 'activo' => '0', 'hora_inicio' => '09:00', 'hora_fin' => '17:00']
            ]
        ];
        try {
            ProfesionalController::horarios($router);
            $this->fail('El guardado válido que desactiva todos los días debe redirigir con 302');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }
        $this->assertCount(0, $profRepo->findHorariosByProfesional($profId));
    }
}
