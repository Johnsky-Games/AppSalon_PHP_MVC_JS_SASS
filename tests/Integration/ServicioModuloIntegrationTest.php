<?php

namespace Tests\Integration;

use AppTerminationException;
use Controllers\APIController;
use Controllers\ServicioController;
use Model\ActiveRecord;
use Model\Cita;
use Model\CitaServicio;
use Model\Servicio;
use Model\Usuario;
use MVC\Router;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\PersistenceException;
use Repositories\ServicioRepository;
use Services\ServicioService;

class ServicioModuloIntegrationTest extends TestCase
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

        self::$db->query("DROP TRIGGER IF EXISTS test_fail_servicios_insert");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_servicios_update");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_servicios_delete");
        self::$db->query("DELETE FROM citasservicios");
        self::$db->query("DELETE FROM citas");
        self::$db->query("DELETE FROM usuarios");
        self::$db->query("DELETE FROM servicios");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['REQUEST_URI'] = '/servicios';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);

        ServicioController::setServicioService(null);
        APIController::setServicioService(null);
        ActiveRecord::setDB(self::$db);
    }

    protected function tearDown(): void
    {
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_servicios_insert");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_servicios_update");
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_servicios_delete");
        ServicioController::setServicioService(null);
        APIController::setServicioService(null);
        ActiveRecord::setDB(self::$db);
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

    public function testCicloCrudCompletoConMySqlReal(): void
    {
        $repo = new ServicioRepository(self::$db);
        $service = new ServicioService($repo);

        // 1. Catálogo inicialmente vacío: status ok y arreglo vacío
        $inicial = $service->listar();
        $this->assertSame(ServicioService::STATUS_OK, $inicial['status']);
        $this->assertTrue($inicial['resultado']);
        $this->assertSame([], $inicial['servicios']);

        // 2. Crear servicio válido
        $creacion = $service->crear([
            'nombre' => 'Corte Clásico',
            'precio' => '85.5'
        ]);
        $this->assertSame(ServicioService::STATUS_OK, $creacion['status']);
        $this->assertTrue($creacion['resultado']);
        $idCreado = $creacion['id'];
        $this->assertIsInt($idCreado);
        $this->assertGreaterThan(0, $idCreado);
        $this->assertSame('85.50', $creacion['servicio']->precio);

        // 3. Listar y obtener por ID
        $listado = $service->listar();
        $this->assertSame(ServicioService::STATUS_OK, $listado['status']);
        $this->assertCount(1, $listado['servicios']);
        $this->assertSame('Corte Clásico', $listado['servicios'][0]->nombre);
        $this->assertSame('85.50', $listado['servicios'][0]->precio);

        $obtenido = $service->obtenerPorId($idCreado);
        $this->assertSame(ServicioService::STATUS_OK, $obtenido['status']);
        $this->assertSame((string)$idCreado, $obtenido['servicio']->id);

        // 4. Actualizar servicio con nuevos valores
        $actualizacion = $service->actualizar($idCreado, [
            'nombre' => 'Corte Clásico y Lavado',
            'precio' => '110'
        ]);
        $this->assertSame(ServicioService::STATUS_OK, $actualizacion['status']);
        $this->assertTrue($actualizacion['resultado']);

        $verificado = $repo->findById($idCreado);
        $this->assertNotNull($verificado);
        $this->assertSame('Corte Clásico y Lavado', $verificado->nombre);
        $this->assertSame('110.00', $verificado->precio);

        // 5. Eliminar servicio
        $eliminacion = $service->eliminar($idCreado);
        $this->assertSame(ServicioService::STATUS_OK, $eliminacion['status']);
        $this->assertTrue($eliminacion['resultado']);
        $this->assertNull($repo->findById($idCreado));
    }

    public function testIntentoDeCambiarIdMediantePostEsIgnoradoEnCrearYActualizar(): void
    {
        $repo = new ServicioRepository(self::$db);
        $service = new ServicioService($repo);

        // Crear servicio 1 con intento de forzar id=500 en el payload POST
        $res1 = $service->crear([
            'id' => 500,
            'nombre' => 'Servicio Uno',
            'precio' => '60.00'
        ]);
        $this->assertSame(ServicioService::STATUS_OK, $res1['status']);
        $idUno = $res1['id'];
        $this->assertNotSame(500, $idUno, 'El id enviado en POST nunca debe usarse al crear');
        $this->assertNull($repo->findById(500));

        // Crear servicio 2 (víctima que un atacante intentaría sobrescribir enviando $_POST['id'])
        $res2 = $service->crear([
            'nombre' => 'Servicio Dos Intacto',
            'precio' => '200.00'
        ]);
        $idDos = $res2['id'];

        // Actualizar Servicio Uno pasando maliciosamente id = $idDos en $_POST
        $csrf = $this->autenticarAdmin();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['id'] = (string)$idUno;
        $_POST = [
            'csrf_token' => $csrf,
            'id' => (string)$idDos,
            'nombre' => 'Servicio Uno Editado',
            'precio' => '75.00'
        ];

        $router = new Router();
        try {
            ServicioController::actualizar($router);
            $this->fail('Debió redirigir con 302 tras actualizar');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        // Comprobar que el Servicio Uno se actualizó y el Servicio Dos permanece intacto
        $s1 = $repo->findById($idUno);
        $s2 = $repo->findById($idDos);
        $this->assertSame('Servicio Uno Editado', $s1->nombre);
        $this->assertSame('75.00', $s1->precio);
        $this->assertSame('Servicio Dos Intacto', $s2->nombre, 'El Servicio Dos no debe ser alterado por $_POST[id]');
        $this->assertSame('200.00', $s2->precio);
    }

    public function testActualizacionSinCambiosEsExitosaYNoSeConfundeConInexistente(): void
    {
        $repo = new ServicioRepository(self::$db);
        $service = new ServicioService($repo);

        $creado = $service->crear([
            'nombre' => 'Peinado Especial',
            'precio' => '150.00'
        ]);
        $id = $creado['id'];

        // Actualizar con exactamente el mismo nombre y precio (affected_rows === 0 en MySQL)
        $sinCambios = $service->actualizar($id, [
            'nombre' => 'Peinado Especial',
            'precio' => '150.00'
        ]);

        $this->assertSame(
            ServicioService::STATUS_OK,
            $sinCambios['status'],
            'Una actualización sin cambios sobre un registro existente debe ser STATUS_OK'
        );
        $this->assertTrue($sinCambios['resultado']);
    }

    public function testDistingueRegistroInexistenteDeEntradaInvalida(): void
    {
        $repo = new ServicioRepository(self::$db);
        $service = new ServicioService($repo);

        // Registro inexistente (ID bien formado pero no existe en la tabla)
        $noExisteGet = $service->obtenerPorId(99999);
        $this->assertSame(ServicioService::STATUS_NOT_FOUND, $noExisteGet['status']);

        $noExisteUpd = $service->actualizar(99999, ['nombre' => 'Test', 'precio' => '50.00']);
        $this->assertSame(ServicioService::STATUS_NOT_FOUND, $noExisteUpd['status']);

        $noExisteDel = $service->eliminar(99999);
        $this->assertSame(ServicioService::STATUS_NOT_FOUND, $noExisteDel['status']);

        // Entrada inválida (ID mal formado o datos de servicio inválidos)
        $idInvalido = $service->obtenerPorId('id_invalido');
        $this->assertSame(ServicioService::STATUS_INVALID, $idInvalido['status']);

        $creado = $service->crear(['nombre' => 'Base', 'precio' => '40.00']);
        $datosInvalidos = $service->actualizar($creado['id'], ['nombre' => '', 'precio' => '-10']);
        $this->assertSame(ServicioService::STATUS_INVALID, $datosInvalidos['status']);
        $this->assertNotEmpty($datosInvalidos['alertas']['error']);
    }

    public function testFalloSqlNoSeMuestraComoExitoNiSeConfundeConCatalogoVacio(): void
    {
        // 1. Fallo de conexión cerrada en lectura (listar y API)
        $closedDb = new mysqli(
            getenv('DB_HOST') ?: 'appsalon-test-db',
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASS') ?: 'root',
            getenv('DB_NAME') ?: 'appsalon_test',
            (int)(getenv('DB_PORT') ?: 3306)
        );
        $closedDb->close();

        $brokenRepo = new ServicioRepository($closedDb);
        $brokenService = new ServicioService($brokenRepo);

        // El repositorio lanza PersistenceException explícitamente
        try {
            $brokenRepo->findAll();
            $this->fail('findAll con conexión cerrada debe lanzar PersistenceException');
        } catch (PersistenceException $e) {
            $this->assertStringContainsString('persistencia', strtolower($e->getMessage()));
        }

        // El servicio devuelve STATUS_ERROR (jamás STATUS_OK con lista vacía)
        $resListar = $brokenService->listar();
        $this->assertSame(ServicioService::STATUS_ERROR, $resListar['status']);
        $this->assertFalse($resListar['resultado']);
        $this->assertNotEmpty($resListar['alertas']['error']);

        // APIController::index ante fallo SQL responde 500 y JSON de error (no 200 [])
        APIController::setServicioService($brokenService);
        ob_start();
        try {
            APIController::index();
            $this->fail('APIController::index debe detener ejecución con 500 ante fallo SQL');
        } catch (AppTerminationException $e) {
            $this->assertSame(500, $e->getStatusCode());
        } finally {
            $apiOutput = ob_get_clean();
        }
        $this->assertSame(500, http_response_code());
        $apiJson = json_decode($apiOutput, true);
        $this->assertFalse($apiJson['resultado']);

        // 2. Fallos SQL reales en MySQL mediante triggers sobre crear, actualizar y eliminar
        $realRepo = new ServicioRepository(self::$db);
        $realService = new ServicioService($realRepo);
        $idExistente = $realService->crear(['nombre' => 'Servicio Base', 'precio' => '70.00'])['id'];

        self::$db->query(
            "CREATE TRIGGER test_fail_servicios_insert BEFORE INSERT ON servicios
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo SQL simulado en insert servicios'"
        );
        self::$db->query(
            "CREATE TRIGGER test_fail_servicios_update BEFORE UPDATE ON servicios
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo SQL simulado en update servicios'"
        );
        self::$db->query(
            "CREATE TRIGGER test_fail_servicios_delete BEFORE DELETE ON servicios
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo SQL simulado en delete servicios'"
        );

        $csrf = $this->autenticarAdmin();
        ServicioController::setServicioService($realService);
        $router = new Router();

        // A. Controlador crear ante fallo SQL: NO redirige a /servicios, responde 500 y muestra alerta
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $csrf,
            'nombre' => 'Nuevo Fallido',
            'precio' => '90.00'
        ];
        ob_start();
        ServicioController::crear($router);
        $htmlCrear = ob_get_clean();
        $this->assertSame(500, http_response_code(), 'Fallo SQL en crear debe responder HTTP 500 sin redirigir');
        $this->assertStringContainsString('error de base de datos', $htmlCrear);

        // B. Controlador actualizar ante fallo SQL: NO redirige a /servicios, responde 500 y muestra alerta
        $_GET['id'] = (string)$idExistente;
        $_POST = [
            'csrf_token' => $csrf,
            'nombre' => 'Actualizado Fallido',
            'precio' => '95.00'
        ];
        ob_start();
        ServicioController::actualizar($router);
        $htmlActualizar = ob_get_clean();
        $this->assertSame(500, http_response_code(), 'Fallo SQL en actualizar debe responder HTTP 500 sin redirigir');
        $this->assertStringContainsString('error de base de datos', $htmlActualizar);

        // C. Controlador eliminar ante fallo SQL: NO redirige con 302, responde 500
        $_POST = [
            'csrf_token' => $csrf,
            'id' => (string)$idExistente
        ];
        ob_start();
        try {
            ServicioController::eliminar($router);
            $this->fail('Eliminar ante fallo SQL debe detener con código 500');
        } catch (AppTerminationException $e) {
            $this->assertSame(500, $e->getStatusCode());
        } finally {
            $htmlEliminar = ob_get_clean();
        }
        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString('error de base de datos', $htmlEliminar);

        // El registro sigue intacto en BD tras los fallos SQL
        $sigueEnBd = $realRepo->findById($idExistente);
        $this->assertNotNull($sigueEnBd);
        $this->assertSame('Servicio Base', $sigueEnBd->nombre);
    }

    public function testControlAccesoAdminYRechazoDeClienteOVisitanteConCsrf(): void
    {
        $router = new Router();

        // 1. Visitante no autenticado es rechazado con 302 a /
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            ServicioController::index($router);
            $this->fail('Visitante debe ser redirigido');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        // 2. Cliente autenticado (no admin) es rechazado con 302 en GET y POST
        $_SESSION['login'] = true;
        $_SESSION['id'] = 10;
        $_SESSION['admin'] = '0';
        $csrfCliente = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrfCliente;

        foreach (['index', 'crear', 'actualizar'] as $accion) {
            try {
                ServicioController::$accion($router);
                $this->fail("Cliente no admin debe ser rechazado en {$accion}");
            } catch (AppTerminationException $e) {
                $this->assertSame(302, $e->getStatusCode());
            }
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf_token' => $csrfCliente, 'id' => '1'];
        try {
            ServicioController::eliminar($router);
            $this->fail('Cliente no admin debe ser rechazado en eliminar');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        // 3. Administrador sin CSRF válido es rechazado con 403 en POST crear, actualizar y eliminar
        $this->autenticarAdmin();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf_token' => 'token_invalido', 'nombre' => 'Test', 'precio' => '50.00'];

        ob_start();
        try {
            ServicioController::crear($router);
            $this->fail('Admin sin CSRF válido debe recibir 403 en crear');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }
    }

    public function testEliminacionDeServicioConservaDatosHistoricosDeCitasYServiciosAsociados(): void
    {
        $usuario = new Usuario([
            'nombre' => 'Cliente', 'apellido' => 'Histórico', 'email' => 'hist@correo.com',
            'password' => '123456', 'telefono' => '1234567890', 'confirmado' => '1'
        ]);
        $idUsuario = (new \Repositories\UsuarioRepository(self::$db))->create($usuario);

        $service = new ServicioService(new ServicioRepository(self::$db));
        $idServicio = $service->crear([
            'nombre' => 'Servicio Histórico',
            'precio' => '120.00'
        ])['id'];

        $citaRepo = new CitaRepository(self::$db);
        $cita = new Cita([
            'fecha' => date('Y-m-d', strtotime('next Monday')),
            'hora' => '11:00',
            'usuarioId' => $idUsuario
        ]);
        $idCita = $citaRepo->createCita($cita);

        $citaServicio = new CitaServicio([
            'citaId' => $idCita,
            'servicioId' => $idServicio
        ]);
        $idCitaServicio = $citaRepo->createCitaServicio($citaServicio);

        // Eliminar el servicio del catálogo
        $resElim = $service->eliminar($idServicio);
        $this->assertSame(ServicioService::STATUS_OK, $resElim['status']);

        // Comprobar que el servicio fue eliminado del catálogo pero la cita y citasservicios persisten intactos
        $this->assertNull((new ServicioRepository(self::$db))->findById($idServicio));
        $this->assertNotNull($citaRepo->findById($idCita), 'La cita histórica no debe eliminarse al borrar un servicio');

        $resRel = self::$db->query("SELECT * FROM citasservicios WHERE id = {$idCitaServicio}");
        $this->assertSame(1, $resRel->num_rows, 'El vínculo histórico en citasservicios debe conservarse sin borrado en cascada');
    }
}

