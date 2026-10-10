<?php

namespace Tests\Integration;

use AppTerminationException;
use Controllers\AdminController;
use Controllers\APIController;
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
use Repositories\UsuarioRepository;
use Services\CitaService;
use Services\ServicioService;

class CitaModuloIntegrationTest extends TestCase
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
        self::$db->query("DELETE FROM usuarios");
        self::$db->query("DELETE FROM servicios");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['HTTP_REFERER'] = null;
        $_SERVER['HTTP_ACCEPT'] = null;
        $_SERVER['CONTENT_TYPE'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        APIController::setServicioService(null);
        APIController::setCitaService(null);
        AdminController::setCitaService(null);
        ActiveRecord::setDB(self::$db);
        http_response_code(200);
    }

    private function crearUsuario(string $email, string $admin = '0', string $nombre = 'Cliente', string $apellido = 'Prueba'): int
    {
        $usuario = new Usuario([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'password' => '123456',
            'telefono' => '1234567890',
            'admin' => $admin,
            'confirmado' => '1'
        ]);
        return (new UsuarioRepository(self::$db))->create($usuario);
    }

    private function crearServicio(string $nombre, string $precio): int
    {
        $repo = new ServicioRepository(self::$db);
        return $repo->create(new Servicio([
            'nombre' => $nombre,
            'precio' => $precio
        ]));
    }

    private function construirCitaService(?mysqli $db = null): CitaService
    {
        $conn = $db ?? self::$db;
        return new CitaService(
            new CitaRepository($conn),
            new ServicioRepository($conn)
        );
    }

    public function testReservarIgnoraUsuarioIdEIdDelPayloadYPersisteEnTransaccionAtomica(): void
    {
        $idClienteReal = $this->crearUsuario('cliente.real@correo.com');
        $idOtroUsuario = $this->crearUsuario('otro.usuario@correo.com');
        $idServ1 = $this->crearServicio('Corte Clásico', '80.00');
        $idServ2 = $this->crearServicio('Barba Express', '50.00');

        $fecha = date('Y-m-d', strtotime('next Thursday'));
        $service = $this->construirCitaService();

        // Aunque $_SESSION tenga otro valor o esté vacía, el servicio usa exclusivamente el argumento verificado
        $_SESSION['id'] = $idOtroUsuario;

        $resultado = $service->reservar($idClienteReal, [
            'id' => '99999',
            'usuarioId' => (string)$idOtroUsuario,
            'fecha' => $fecha,
            'hora' => '18:00', // Límite superior exacto permitido
            'servicios' => "{$idServ1}, {$idServ2}, {$idServ1}"
        ]);

        $this->assertSame(CitaService::STATUS_OK, $resultado['status']);
        $this->assertTrue($resultado['resultado']['resultado']);
        $idCita = (int)$resultado['id'];
        $this->assertNotSame(99999, $idCita);

        $citaRepo = new CitaRepository(self::$db);
        $citaPersistida = $citaRepo->findById($idCita);
        $this->assertNotNull($citaPersistida);
        $this->assertSame((string)$idClienteReal, $citaPersistida->usuarioId, 'El servicio jamás debe confiar en usuarioId del payload ni leer $_SESSION');
        $this->assertSame($fecha, $citaPersistida->fecha);
        $this->assertSame('18:00:00', $citaPersistida->hora);

        $resServicios = self::$db->query("SELECT servicioId FROM citasservicios WHERE citaId = {$idCita} ORDER BY id ASC");
        $this->assertSame(2, $resServicios->num_rows, 'Los servicios repetidos deben desduplicarse antes de insertar');
    }

    public function testReservarRechazaServiciosInexistentesSinInsertarCitaParcial(): void
    {
        $idCliente = $this->crearUsuario('cliente.serv@correo.com');
        $idServicioExistente = $this->crearServicio('Peinado', '90.00');
        $idServicioInexistente = $idServicioExistente + 9999;

        $service = $this->construirCitaService();
        $resultado = $service->reservar($idCliente, [
            'fecha' => date('Y-m-d', strtotime('next Tuesday')),
            'hora' => '10:00',
            'servicios' => "{$idServicioExistente},{$idServicioInexistente}"
        ]);

        $this->assertSame(CitaService::STATUS_NOT_FOUND, $resultado['status']);
        $this->assertSame(422, $resultado['httpCode']);
        $this->assertStringContainsString('no existen o no son válidos', $resultado['error']);

        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas")->fetch_assoc()['total'];
        $totalRel = (int)self::$db->query("SELECT COUNT(*) AS total FROM citasservicios")->fetch_assoc()['total'];
        $this->assertSame(0, $totalCitas, 'No debe crearse ninguna cita si alguno de los servicios no existe');
        $this->assertSame(0, $totalRel);
    }

    public function testRepositorioCrearReservaAtomicaEjecutaRollbackEnUnicaConexionAnteFalloSqlEnCitaServicios(): void
    {
        $idCliente = $this->crearUsuario('rollback.real@correo.com');
        $idServicio1 = $this->crearServicio('Lavado', '40.00');
        $idServicio2 = $this->crearServicio('Secado', '50.00');

        $citaRepo = new CitaRepository(self::$db);
        $cita = new Cita([
            'fecha' => date('Y-m-d', strtotime('next Friday')),
            'hora' => '13:00',
            'usuarioId' => $idCliente
        ]);

        // Crear trigger MySQL que permite el primer insert en `citasservicios` ($idServicio1)
        // pero falla en el segundo insert ($idServicio2), probando rollback real de múltiples escrituras previas.
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_second_citaservicio");
        self::$db->query(
            "CREATE TRIGGER test_fail_second_citaservicio BEFORE INSERT ON citasservicios
             FOR EACH ROW BEGIN
                 IF NEW.servicioId = {$idServicio2} THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo SQL real en segundo servicio de la cita';
                 END IF;
             END"
        );

        $excepcionCapturada = false;
        try {
            $citaRepo->crearReservaAtomica($cita, [$idServicio1, $idServicio2]);
        } catch (PersistenceException $e) {
            $excepcionCapturada = true;
        } finally {
            self::$db->query("DROP TRIGGER IF EXISTS test_fail_second_citaservicio");
        }

        $this->assertTrue($excepcionCapturada, 'Debe lanzar PersistenceException cuando falla la inserción de un servicio asociado');

        // Verificar en MySQL que el rollback revirtió tanto la fila de `citas` como la primera fila de `citasservicios`
        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas")->fetch_assoc()['total'];
        $totalCitaServicios = (int)self::$db->query("SELECT COUNT(*) AS total FROM citasservicios")->fetch_assoc()['total'];
        $this->assertSame(0, $totalCitas, 'El rollback debe eliminar la cita principal insertada antes del fallo');
        $this->assertSame(0, $totalCitaServicios, 'El rollback debe eliminar los citasservicios previos de la transacción abortada');
    }

    public function testApiGuardarDevuelve500YEjecutaRollbackSinDejarRegistrosParcialesAnteFalloSqlIntermedio(): void
    {
        $idCliente = $this->crearUsuario('api.rollback@correo.com');
        $idServ1 = $this->crearServicio('Corte', '70.00');
        $idServ2 = $this->crearServicio('Tinte', '150.00');

        // Subclase de CitaRepository que falla al insertar el segundo servicio tras haber insertado cita y primer servicio en MySQL real
        $repoConFalloEnSegundoServicio = new class(self::$db) extends CitaRepository {
            private int $contadorServicios = 0;

            public function createCitaServicio(CitaServicio $citaServicio, ?mysqli $dbOverride = null): int
            {
                $this->contadorServicios++;
                if ($this->contadorServicios === 2) {
                    throw new PersistenceException('Fallo SQL simulado al insertar el segundo servicio de la cita');
                }
                return parent::createCitaServicio($citaServicio, $dbOverride);
            }
        };

        $citaService = new CitaService(
            $repoConFalloEnSegundoServicio,
            new ServicioRepository(self::$db)
        );
        APIController::setCitaService($citaService);

        $_SESSION['login'] = true;
        $_SESSION['id'] = $idCliente;
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $token,
            'fecha' => date('Y-m-d', strtotime('next Monday')),
            'hora' => '12:30',
            'servicios' => "{$idServ1},{$idServ2}"
        ];

        ob_start();
        APIController::guardar();
        $output = ob_get_clean();

        $this->assertSame(500, http_response_code());
        $payload = json_decode($output, true);
        $this->assertFalse($payload['resultado']);
        $this->assertStringContainsString('Operación cancelada', $payload['error']);

        // Ni la cita ni el primer servicio deben existir en MySQL tras el rollback
        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas")->fetch_assoc()['total'];
        $totalCS = (int)self::$db->query("SELECT COUNT(*) AS total FROM citasservicios")->fetch_assoc()['total'];
        $this->assertSame(0, $totalCitas, 'No debe quedar la cita registrada tras el fallo del segundo servicio');
        $this->assertSame(0, $totalCS, 'No debe quedar el primer citasservicio tras el rollback');
    }

    public function testRepositorioCompruebaBeginTransactionYCommitEjecutandoRollbackSiCommitFalla(): void
    {
        $idCliente = $this->crearUsuario('commit.fail@correo.com');
        $idServ = $this->crearServicio('Masaje Capilar', '60.00');

        // 1. Conexión donde begin_transaction devuelve false
        $dbFallaBegin = new class extends mysqli {
            public function __construct()
            {
            }

            public function begin_transaction(int $flags = 0, ?string $name = null): bool
            {
                return false;
            }
        };

        $serviceFallaBegin = new CitaService(
            new CitaRepository($dbFallaBegin),
            new ServicioRepository(self::$db)
        );

        $resBegin = $serviceFallaBegin->reservar($idCliente, [
            'fecha' => date('Y-m-d', strtotime('next Wednesday')),
            'hora' => '14:00',
            'servicios' => (string)$idServ
        ]);
        $this->assertSame(CitaService::STATUS_ERROR, $resBegin['status']);

        // 2. Conexión real a MySQL donde commit() falla después de insertar cita y citasservicios
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        $dbFallaCommit = new class($host, $user, $pass, $name, $port) extends mysqli {
            public bool $rollbackEjecutado = false;

            public function commit(int $flags = 0, ?string $name = null): bool
            {
                return false;
            }

            public function rollback(int $flags = 0, ?string $name = null): bool
            {
                $this->rollbackEjecutado = true;
                return parent::rollback($flags, $name);
            }
        };
        $dbFallaCommit->set_charset('utf8mb4');

        $serviceFallaCommit = new CitaService(
            new CitaRepository($dbFallaCommit),
            new ServicioRepository($dbFallaCommit)
        );

        $resCommit = $serviceFallaCommit->reservar($idCliente, [
            'fecha' => date('Y-m-d', strtotime('next Wednesday')),
            'hora' => '14:00',
            'servicios' => (string)$idServ
        ]);

        $this->assertSame(CitaService::STATUS_ERROR, $resCommit['status']);
        $this->assertTrue($dbFallaCommit->rollbackEjecutado, 'Si commit() devuelve false, debe ejecutarse rollback()');
        $dbFallaCommit->close();

        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas")->fetch_assoc()['total'];
        $this->assertSame(0, $totalCitas, 'Ningún registro debe persistir si falla el commit');
    }

    public function testEliminarVerificaPermisosDePropietarioYAdministradorYDistingueEstados(): void
    {
        $idPropietario = $this->crearUsuario('prop.cita@correo.com', '0', 'Ana', 'Dueña');
        $idOtroCliente = $this->crearUsuario('otro.cita@correo.com', '0', 'Luis', 'Ajeno');
        $idAdmin = $this->crearUsuario('admin.cita@correo.com', '1', 'Admin', 'General');
        $idServicio = $this->crearServicio('Corte Dama', '110.00');

        $service = $this->construirCitaService();
        $fecha = date('Y-m-d', strtotime('next Thursday'));

        // Crear dos citas del propietario
        $idCita1 = $service->reservar($idPropietario, [
            'fecha' => $fecha,
            'hora' => '10:30',
            'servicios' => (string)$idServicio
        ])['id'];

        $idCita2 = $service->reservar($idPropietario, [
            'fecha' => $fecha,
            'hora' => '11:30',
            'servicios' => (string)$idServicio
        ])['id'];

        // 1. ID inválido -> STATUS_INVALID
        $resInvalido = $service->eliminar('id_invalido', $idPropietario, false);
        $this->assertSame(CitaService::STATUS_INVALID, $resInvalido['status']);

        // 2. Registro inexistente -> STATUS_NOT_FOUND
        $resInexistente = $service->eliminar(999999, $idPropietario, false);
        $this->assertSame(CitaService::STATUS_NOT_FOUND, $resInexistente['status']);

        // 3. Otro cliente no propietario ni admin -> STATUS_FORBIDDEN
        $resProhibido = $service->eliminar($idCita1, $idOtroCliente, false);
        $this->assertSame(CitaService::STATUS_FORBIDDEN, $resProhibido['status']);
        $this->assertNotNull((new CitaRepository(self::$db))->findById($idCita1), 'Cita no debe borrarse tras intento no autorizado');

        // 4. Propietario elimina su propia cita -> STATUS_OK
        $resProp = $service->eliminar($idCita1, $idPropietario, false);
        $this->assertSame(CitaService::STATUS_OK, $resProp['status']);
        $this->assertNull((new CitaRepository(self::$db))->findById($idCita1));

        // 5. Administrador elimina la cita de cualquier usuario -> STATUS_OK
        $resAdmin = $service->eliminar($idCita2, $idAdmin, true);
        $this->assertSame(CitaService::STATUS_OK, $resAdmin['status']);
        $this->assertNull((new CitaRepository(self::$db))->findById($idCita2));
    }

    public function testEliminarEjecutaRollbackRestaurandoCitaServiciosSiFallaBorradoDeCitaPrincipal(): void
    {
        $idCliente = $this->crearUsuario('elim.rollback@correo.com');
        $idServ = $this->crearServicio('Hidratación', '95.00');

        $service = $this->construirCitaService();
        $idCita = $service->reservar($idCliente, [
            'fecha' => date('Y-m-d', strtotime('next Tuesday')),
            'hora' => '16:00',
            'servicios' => (string)$idServ
        ])['id'];

        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        // Conexión real que permite DELETE FROM citasservicios pero falla al preparar DELETE FROM citas
        $dbFallaDeleteCita = new class($host, $user, $pass, $name, $port) extends mysqli {
            public function prepare(string $query): \mysqli_stmt|false
            {
                if (stripos($query, 'DELETE FROM citas WHERE') !== false) {
                    return false;
                }
                return parent::prepare($query);
            }
        };
        $dbFallaDeleteCita->set_charset('utf8mb4');

        $serviceConFallo = new CitaService(
            new CitaRepository($dbFallaDeleteCita),
            new ServicioRepository($dbFallaDeleteCita)
        );

        $resultado = $serviceConFallo->eliminar($idCita, $idCliente, false);
        $this->assertSame(CitaService::STATUS_ERROR, $resultado['status']);
        $dbFallaDeleteCita->close();

        // Comprobar que el rollback restauró las filas de citasservicios que se habían eliminado en el paso 1
        $citaPersistida = (new CitaRepository(self::$db))->findById($idCita);
        $this->assertNotNull($citaPersistida, 'La cita debe seguir existiendo tras el fallo');

        $resCS = self::$db->query("SELECT COUNT(*) AS total FROM citasservicios WHERE citaId = {$idCita}");
        $this->assertSame(1, (int)$resCS->fetch_assoc()['total'], 'El rollback debe restaurar los servicios asociados de citasservicios');
    }

    public function testAdminControllerConsultaCitasPorFechaYDistingueListaVaciaDeFalloSql(): void
    {
        $idAdmin = $this->crearUsuario('admin.panel@correo.com', '1', 'Admin', 'Panel');
        $idCliente = $this->crearUsuario('cliente.panel@correo.com', '0', 'Elena', 'Gómez');
        $idServ1 = $this->crearServicio('Corte', '100.00');
        $idServ2 = $this->crearServicio('Brushing', '75.50');

        $fechaConCita = date('Y-m-d', strtotime('next Thursday'));
        $fechaSinCita = date('Y-m-d', strtotime('next Friday'));

        $service = $this->construirCitaService();
        $idCita = $service->reservar($idCliente, [
            'fecha' => $fechaConCita,
            'hora' => '15:00',
            'servicios' => "{$idServ1},{$idServ2}"
        ])['id'];

        $_SESSION['login'] = true;
        $_SESSION['id'] = $idAdmin;
        $_SESSION['admin'] = '1';
        $_SESSION['nombre'] = 'Admin';
        $_SESSION['apellido'] = 'Panel';

        $router = new Router();

        // 1. Consulta en fecha con citas registradas
        $_GET = ['fecha' => $fechaConCita];
        ob_start();
        AdminController::index($router);
        $htmlConCitas = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('Elena Gómez', $htmlConCitas);
        $this->assertStringContainsString('Corte 100.00', $htmlConCitas);
        $this->assertStringContainsString('Brushing 75.50', $htmlConCitas);
        $this->assertStringContainsString('$ 175.5', $htmlConCitas);
        $this->assertStringNotContainsString('No se registra citas para la fecha seleccionada', $htmlConCitas);

        // 2. Consulta en fecha válida sin citas
        $_GET = ['fecha' => $fechaSinCita];
        ob_start();
        AdminController::index($router);
        $htmlSinCitas = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('No se registra citas para la fecha seleccionada', $htmlSinCitas);

        // 3. Fallo SQL en consulta administrativa -> HTTP 500 y alerta de error sin fingir lista vacía
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        $dbCerrada = new mysqli($host, $user, $pass, $name, $port);
        $dbCerrada->close();
        ActiveRecord::setDB($dbCerrada);

        $_GET = ['fecha' => $fechaConCita];
        ob_start();
        AdminController::index($router);
        $htmlFalloSql = ob_get_clean();

        $this->assertSame(500, http_response_code(), 'Un fallo SQL en /admin debe devolver HTTP 500');
        $this->assertStringContainsString('No fue posible consultar las citas para la fecha seleccionada', $htmlFalloSql);
        $this->assertStringNotContainsString('No se registra citas para la fecha seleccionada', $htmlFalloSql);
        $this->assertNotNull($idCita);
    }

    public function testAdminControllerExigeRolAdministrador(): void
    {
        $router = new Router();

        // Cliente autenticado no administrador
        $_SESSION = ['login' => true, 'id' => 10, 'admin' => '0'];
        try {
            AdminController::index($router);
            $this->fail('Cliente sin rol admin debe ser redirigido de /admin');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }
    }

    public function testApiEliminarManejaFalloSqlEnModalidadesHtmlYJsonConRollbackSinRedireccion302(): void
    {
        $idAdmin = $this->crearUsuario('admin.elim@correo.com', '1', 'Admin', 'Elim');
        $idCliente = $this->crearUsuario('cliente.elim.html@correo.com', '0', 'Pedro', 'Cliente');
        $idServ1 = $this->crearServicio('Corte', '80.00');
        $idServ2 = $this->crearServicio('Barba', '55.00');

        $service = $this->construirCitaService();
        $idCita = $service->reservar($idCliente, [
            'fecha' => date('Y-m-d', strtotime('next Thursday')),
            'hora' => '11:00',
            'servicios' => "{$idServ1},{$idServ2}"
        ])['id'];

        $token = bin2hex(random_bytes(32));
        $_SESSION = [
            'login' => true,
            'id' => $idAdmin,
            'admin' => '1',
            'csrf_token' => $token
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_REFERER'] = '/admin';
        $_POST = [
            'csrf_token' => $token,
            'id' => (string)$idCita
        ];

        // Provocar fallo SQL real en el segundo paso de la eliminación (tras borrar citasservicios, al borrar en citas)
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_delete_cita");
        self::$db->query(
            "CREATE TRIGGER test_fail_delete_cita BEFORE DELETE ON citas
             FOR EACH ROW BEGIN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo SQL interno secreto en delete citas';
             END"
        );

        try {
            // 1. Petición HTML del formulario administrativo (no JSON)
            $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

            $salidaHtml = '';
            try {
                ob_start();
                APIController::eliminar();
                $this->fail('APIController::eliminar en petición HTML debe detener ejecución con 500 y no redirigir');
            } catch (AppTerminationException $e) {
                $this->assertSame(500, $e->getStatusCode(), 'En petición HTML ante fallo SQL debe terminar con 500, nunca 302');
            } finally {
                $salidaHtml = ob_get_clean();
            }

            $this->assertSame(500, http_response_code());
            $this->assertStringContainsString('Error 500: No fue posible eliminar la cita debido a un error de base de datos.', $salidaHtml);
            $this->assertStringNotContainsString('Fallo SQL interno secreto', $salidaHtml, 'No debe exponer detalles SQL internos');
            $this->assertStringNotContainsString('SQLSTATE', $salidaHtml);

            // Comprobar que la cita y sus servicios asociados se conservaron tras el rollback
            $citaTrasFalloHtml = (new CitaRepository(self::$db))->findById($idCita);
            $this->assertNotNull($citaTrasFalloHtml, 'La cita debe conservarse intacta tras el fallo en petición HTML');
            $serviciosTrasFalloHtml = (int)self::$db->query("SELECT COUNT(*) AS total FROM citasservicios WHERE citaId = {$idCita}")->fetch_assoc()['total'];
            $this->assertSame(2, $serviciosTrasFalloHtml, 'Los 2 servicios asociados deben haberse restaurado por rollback');

            // 2. Petición JSON con el mismo fallo de persistencia
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $salidaJson = '';
            try {
                ob_start();
                APIController::eliminar();
                $this->fail('APIController::eliminar en petición JSON debe detener ejecución con 500');
            } catch (AppTerminationException $e) {
                $this->assertSame(500, $e->getStatusCode());
            } finally {
                $salidaJson = ob_get_clean();
            }

            $this->assertSame(500, http_response_code());
            $payloadJson = json_decode($salidaJson, true);
            $this->assertIsArray($payloadJson);
            $this->assertFalse($payloadJson['resultado']);
            $this->assertSame('No fue posible eliminar la cita debido a un error de base de datos.', $payloadJson['error']);
            $this->assertStringNotContainsString('Fallo SQL interno secreto', $salidaJson);

            // La cita y sus servicios siguen intactos tras el segundo rollback
            $this->assertNotNull((new CitaRepository(self::$db))->findById($idCita));
            $serviciosTrasFalloJson = (int)self::$db->query("SELECT COUNT(*) AS total FROM citasservicios WHERE citaId = {$idCita}")->fetch_assoc()['total'];
            $this->assertSame(2, $serviciosTrasFalloJson);
        } finally {
            self::$db->query("DROP TRIGGER IF EXISTS test_fail_delete_cita");
        }

        // 3. Eliminación exitosa una vez restablecida la persistencia (Petición HTML -> 302)
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        try {
            ob_start();
            APIController::eliminar();
            $this->fail('La eliminación exitosa debe terminar con redirección 302');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        $this->assertNull((new CitaRepository(self::$db))->findById($idCita), 'La cita debe eliminarse cuando no hay fallo SQL');
        $serviciosTrasExito = (int)self::$db->query("SELECT COUNT(*) AS total FROM citasservicios WHERE citaId = {$idCita}")->fetch_assoc()['total'];
        $this->assertSame(0, $serviciosTrasExito);
    }
}


