<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Model\Usuario;
use Model\Servicio;
use Model\Cita;
use Model\CitaServicio;
use Controllers\APIController;
use Repositories\CitaRepository;
use Repositories\ServicioRepository;
use Repositories\UsuarioRepository;
use AppTerminationException;
use mysqli;

class ApiSeguridadTest extends TestCase
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
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        APIController::setServicioService(null);
        APIController::setCitaService(null);
        http_response_code(200);
    }

    public function testVisitanteNoPuedeCrearCitasSinAutenticacion(): void
    {
        // Sin sesión activa
        $_SESSION['login'] = false;
        $_SESSION['id'] = null;

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;

        $output = '';
        try {
            ob_start();
            APIController::guardar();
        } catch (AppTerminationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(401, http_response_code(), 'Visitante sin sesión debe recibir 401');
        $json = json_decode($output, true);
        $this->assertFalse($json['resultado']);
    }

    public function testOperacionGuardarRechazaPeticionSinCsrfValido(): void
    {
        // Sesión válida pero CSRF ausente o alterado
        $_SESSION['login'] = true;
        $_SESSION['id'] = 10;
        $_SESSION['csrf_token'] = 'token_sesion_valido';
        $_POST['csrf_token'] = 'token_falso_alterado';

        $output = '';
        try {
            ob_start();
            APIController::guardar();
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(403, http_response_code(), 'Petición con CSRF inválido debe recibir 403');
        $json = json_decode($output, true);
        $this->assertFalse($json['resultado']);
    }

    public function testClienteNoPuedeEliminarCitaDeOtroClienteIdor(): void
    {
        $usuarioRepo = new UsuarioRepository(self::$db);

        // 1. Crear Usuario A (dueño de la cita) y Usuario B (atacante)
        $usuarioA = new Usuario([
            'nombre' => 'Dueño', 'apellido' => 'A', 'email' => 'dueno@correo.com',
            'password' => '123456', 'telefono' => '1111111111', 'confirmado' => '1'
        ]);
        $idA = $usuarioRepo->create($usuarioA);

        $usuarioB = new Usuario([
            'nombre' => 'Atacante', 'apellido' => 'B', 'email' => 'atacante@correo.com',
            'password' => '123456', 'telefono' => '2222222222', 'confirmado' => '1'
        ]);
        $idB = $usuarioRepo->create($usuarioB);

        // 2. Crear cita perteneciente al Usuario A
        $citaRepo = new CitaRepository(self::$db);
        $citaA = new Cita([
            'fecha' => '2026-10-20',
            'hora' => '14:00',
            'usuarioId' => $idA
        ]);
        $idCita = $citaRepo->createCita($citaA);

        // 3. Usuario B intenta eliminar la cita de Usuario A enviando id de la cita de A
        $_SESSION['login'] = true;
        $_SESSION['id'] = $idB; // Sesión del atacante
        $_SESSION['admin'] = '0';

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;
        $_POST['id'] = $idCita;

        try {
            ob_start();
            APIController::eliminar();
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        $this->assertSame(403, http_response_code(), 'Eliminar cita ajena debe retornar 403 Forbidden');

        // 4. Demostrar que la cita sigue intacta en la base de datos
        $citaVerificada = $citaRepo->findById($idCita);
        $this->assertNotNull($citaVerificada, 'La cita del Usuario A no debe ser eliminada por el Usuario B');
    }

    public function testClientePuedeEliminarSuPropiaCita(): void
    {
        $usuario = new Usuario([
            'nombre' => 'Propietario', 'apellido' => 'P', 'email' => 'prop@correo.com',
            'password' => '123456', 'telefono' => '3333333333', 'confirmado' => '1'
        ]);
        $idUsuario = (new UsuarioRepository(self::$db))->create($usuario);

        $citaRepo = new CitaRepository(self::$db);
        $cita = new Cita([
            'fecha' => '2026-10-22',
            'hora' => '15:00',
            'usuarioId' => $idUsuario
        ]);
        $idCita = $citaRepo->createCita($cita);

        $_SESSION['login'] = true;
        $_SESSION['id'] = $idUsuario;
        $_SESSION['admin'] = '0';

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;
        $_POST['id'] = $idCita;

        try {
            ob_start();
            APIController::eliminar();
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // Verificar que la cita fue eliminada
        $citaEliminada = $citaRepo->findById($idCita);
        $this->assertNull($citaEliminada, 'El propietario debe poder eliminar su propia cita');
    }

    public function testReservaValidaGuardaCitaConUsuarioDeSesion(): void
    {
        // 1. Crear usuario y servicio en BD
        $usuario = new Usuario([
            'nombre' => 'Cliente', 'apellido' => 'Real', 'email' => 'real@correo.com',
            'password' => '123456', 'telefono' => '4444444444', 'confirmado' => '1'
        ]);
        $idUsuarioReal = (new UsuarioRepository(self::$db))->create($usuario);

        $servicio = new Servicio([
            'nombre' => 'Corte Cabello',
            'precio' => '50.00'
        ]);
        $idServicio = (new ServicioRepository(self::$db))->create($servicio);

        // 2. Sesión activa para Usuario Real
        $_SESSION['login'] = true;
        $_SESSION['id'] = $idUsuarioReal;

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;

        // Fecha futura en día de semana (jueves)
        $_POST['fecha'] = date('Y-m-d', strtotime('next Thursday'));
        $_POST['hora'] = '11:00';
        $_POST['servicios'] = (string)$idServicio;
        // Intento malicioso en POST de asignar otro usuarioId
        $_POST['usuarioId'] = '9999';

        ob_start();
        APIController::guardar();
        $output = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $json = json_decode($output, true);
        $this->assertNotEmpty($json['resultado']);
        $this->assertTrue((bool)$json['resultado']['resultado']);

        $idCitaCreada = (int)$json['resultado']['id'];
        $citaGuardada = (new CitaRepository(self::$db))->findById($idCitaCreada);

        $this->assertNotNull($citaGuardada);
        // Debe haberse asignado a la sesión del usuario real (idUsuarioReal), NUNCA a 9999
        $this->assertSame($idUsuarioReal, (int)$citaGuardada->usuarioId, 'El usuarioId debe ser forzado desde $_SESSION');

        // Verificar que se guardó la relación en citasservicios
        $rel = self::$db->query("SELECT * FROM citasservicios WHERE citaId = {$idCitaCreada}");
        $this->assertSame(1, $rel->num_rows);
    }

    public function testGuardarRechazaReservaMismoDiaOFechaPasada(): void
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;

        // Intento de reservar para hoy
        $_POST['fecha'] = date('Y-m-d');
        $_POST['hora'] = '11:00';
        $_POST['servicios'] = '1';

        $output = '';
        try {
            ob_start();
            APIController::guardar();
        } catch (AppTerminationException $e) {
            $this->assertSame(422, $e->getStatusCode());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(422, http_response_code());
        $json = json_decode($output, true);
        $this->assertFalse($json['resultado']);
        $this->assertStringContainsString('mismo día', $json['error']);
    }

    public function testGuardarRechazaHorarioInvalidoPasadoLimite(): void
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;

        $_POST['fecha'] = date('Y-m-d', strtotime('next Wednesday'));
        $_POST['hora'] = '18:30'; // Pasado las 18:00
        $_POST['servicios'] = '1';

        $output = '';
        try {
            ob_start();
            APIController::guardar();
        } catch (AppTerminationException $e) {
            $this->assertSame(422, $e->getStatusCode());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(422, http_response_code());
        $json = json_decode($output, true);
        $this->assertFalse($json['resultado']);
        $this->assertStringContainsString('10:00 a 18:00', $json['error']);
    }

    public function testGuardarDesduplicaServiciosRepetidosPoliticaExplicita(): void
    {
        $servicio = new Servicio(['nombre' => 'Manicure', 'precio' => '30.00']);
        $idS = (new ServicioRepository(self::$db))->create($servicio);

        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;

        $_POST['fecha'] = date('Y-m-d', strtotime('next Tuesday'));
        $_POST['hora'] = '12:00';
        // Servicios repetidos en el payload
        $_POST['servicios'] = "{$idS},{$idS},{$idS}";

        ob_start();
        APIController::guardar();
        $output = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $json = json_decode($output, true);
        $idCita = (int)$json['resultado']['id'];

        // Comprobar que en BD solo existe 1 registro asociado, no 3
        $res = self::$db->query("SELECT * FROM citasservicios WHERE citaId = {$idCita}");
        $this->assertSame(1, $res->num_rows, 'La política de desduplicación debe persistir exactamente un registro por servicio único');
    }

    public function testGuardarRechazaFormatoHoraConSegundos(): void
    {
        $servicio = new Servicio(['nombre' => 'Corte Varón', 'precio' => '50.00']);
        $idS = (new ServicioRepository(self::$db))->create($servicio);

        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;

        $_POST['fecha'] = date('Y-m-d', strtotime('next Wednesday'));
        $_POST['hora'] = '18:00:01'; // Rechazado por incluir segundos
        $_POST['servicios'] = (string)$idS;

        $output = '';
        try {
            ob_start();
            APIController::guardar();
        } catch (AppTerminationException $e) {
            $this->assertSame(422, $e->getStatusCode());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame(422, http_response_code());
        $json = json_decode($output, true);
        $this->assertFalse($json['resultado']);
        $this->assertSame('Formato de hora inválido. Se requiere HH:MM.', $json['error']);
    }

    public function testLoginRechazaCargaNoEscalarTipoInvalido(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $router = new \MVC\Router();
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;
        $_POST['email'] = ['ataque_array@correo.com'];
        $_POST['password'] = '123456';

        ob_start();
        \Controllers\LoginController::login($router);
        $output = ob_get_clean();

        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString('Los campos email y password deben ser cadenas de texto válidas', $output);
    }

    public function testEliminarCitaExitosaNoEjecutaRollbackFalso(): void
    {
        $cliente = new Usuario([
            'nombre' => 'Cliente', 'apellido' => 'Test', 'email' => 'cliente_elim@correo.com',
            'password' => 'password', 'telefono' => '1234567890', 'confirmado' => '1'
        ]);
        $clienteId = (new UsuarioRepository(self::$db))->create($cliente);

        $servicio = new Servicio(['nombre' => 'Barba', 'precio' => '40.00']);
        $servicioId = (new ServicioRepository(self::$db))->create($servicio);

        $citaRepo = new CitaRepository(self::$db);
        $cita = new Cita([
            'fecha' => date('Y-m-d', strtotime('next Thursday')),
            'hora' => '15:00',
            'usuarioId' => $clienteId
        ]);
        $citaId = $citaRepo->createCita($cita);

        $cs = new CitaServicio([
            'citaId' => $citaId,
            'servicioId' => $servicioId
        ]);
        $citaRepo->createCitaServicio($cs);

        // Autenticar al cliente propietario
        $_SESSION['login'] = true;
        $_SESSION['id'] = $clienteId;
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;
        $_POST['id'] = (string)$citaId;

        try {
            APIController::eliminar();
            $this->fail('Debe terminar con redirección 302');
        } catch (AppTerminationException $e) {
            $this->assertSame(302, $e->getStatusCode(), 'La eliminación exitosa debe redirigir con 302');
        }

        // Verificar que el commit persistió y la cita y sus servicios fueron eliminados
        $checkCita = self::$db->query("SELECT * FROM citas WHERE id = {$citaId}");
        $this->assertSame(0, $checkCita->num_rows, 'La cita debe haber sido eliminada tras el commit exitoso');

        $checkCS = self::$db->query("SELECT * FROM citasservicios WHERE citaId = {$citaId}");
        $this->assertSame(0, $checkCS->num_rows, 'Los servicios asociados deben haber sido eliminados');
    }
}

