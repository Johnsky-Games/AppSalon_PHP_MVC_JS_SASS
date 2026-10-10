<?php

namespace Tests\Integration;

use AppTerminationException;
use Controllers\APIController;
use Model\ActiveRecord;
use Model\BloqueoProfesional;
use Model\Cita;
use Model\CitaServicio;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Profesional;
use Model\Servicio;
use Model\Usuario;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Repositories\UsuarioRepository;
use Services\CitaService;
use Services\DisponibilidadService;
use Services\ProfesionalService;
use Services\ServicioService;

/**
 * Pruebas de integración y concurrencia real para la Fase 4B:
 * - Reservas con profesional, cálculo de duración/precios/hora_fin desde el catálogo.
 * - Snapshot histórico inmutable en `citas` y `citasservicios`.
 * - Descuento de ocupación real en `DisponibilidadService` y semántica semiabierta `[inicio, fin)`.
 * - Protección transaccional InnoDB (`FOR UPDATE` / `FOR SHARE`) frente a reservas concurrentes
 *   y mutaciones simultáneas de agenda/estado.
 */
class ReservaConcurrenciaIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;
    private static string $host;
    private static string $user;
    private static string $pass;
    private static string $dbName;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('DB_HOST') ?: 'appsalon-test-db';
        self::$user = getenv('DB_USER') ?: 'root';
        self::$pass = getenv('DB_PASS') ?: 'root';
        self::$dbName = getenv('DB_NAME') ?: 'appsalon_test';
        self::$port = (int)(getenv('DB_PORT') ?: 3306);

        self::$db = new mysqli(self::$host, self::$user, self::$pass, self::$dbName, self::$port);
        self::$db->set_charset('utf8mb4');
        ActiveRecord::setDB(self::$db);
    }

    protected function setUp(): void
    {
        validar_base_datos_prueba(self::$db);

        self::$db->query("DROP TRIGGER IF EXISTS test_fail_citasservicios_fase4b");
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
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['HTTP_REFERER'] = null;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        APIController::setServicioService(null);
        APIController::setCitaService(null);
        APIController::setDisponibilidadService(null);
        ActiveRecord::setDB(self::$db);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        if (self::$db) {
            self::$db->query("DROP TRIGGER IF EXISTS test_fail_citasservicios_fase4b");
        }
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['HTTP_REFERER'] = null;
        $_SERVER['HTTP_ACCEPT'] = null;
        $_SERVER['CONTENT_TYPE'] = null;
        $_SERVER['REQUEST_URI'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        APIController::setServicioService(null);
        APIController::setCitaService(null);
        APIController::setDisponibilidadService(null);
        http_response_code(200);
    }

    private function crearUsuario(string $email, string $admin = '0', string $nombre = 'Cliente', string $apellido = 'Prueba'): int
    {
        $usuario = new Usuario([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'password' => '123456',
            'telefono' => '0991234567',
            'admin' => $admin,
            'confirmado' => '1'
        ]);
        return (new UsuarioRepository(self::$db))->create($usuario);
    }

    private function autenticarUsuario(int $usuarioId, string $admin = '0'): string
    {
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['login'] = true;
        $_SESSION['id'] = $usuarioId;
        $_SESSION['admin'] = $admin;
        $_SESSION['csrf_token'] = $csrf;
        return $csrf;
    }

    private function crearServicio(string $nombre, string $precio, int $duracionMinutos): int
    {
        $repo = new ServicioRepository(self::$db);
        return $repo->create(new Servicio([
            'nombre' => $nombre,
            'precio' => $precio,
            'duracion_minutos' => $duracionMinutos
        ]));
    }

    private function crearProfesionalConAgenda(
        string $nombre,
        array $servicioIds,
        array $horarios = [],
        int $activo = 1
    ): int {
        $profRepo = new ProfesionalRepository(self::$db);
        $prof = new Profesional([
            'nombre' => $nombre,
            'activo' => $activo
        ]);
        $profId = $profRepo->createWithServicios($prof, $servicioIds);

        if (!empty($horarios)) {
            $entidades = [];
            foreach ($horarios as $h) {
                $entidades[] = new HorarioProfesional([
                    'profesionalId' => $profId,
                    'dia_semana' => $h['dia_semana'],
                    'hora_inicio' => $h['hora_inicio'],
                    'hora_fin' => $h['hora_fin']
                ]);
            }
            $profRepo->replaceHorarios($profId, $entidades);
        }

        return $profId;
    }

    /**
     * Obtiene el próximo lunes estrictamente futuro en America/Guayaquil.
     */
    private function proximoLunesFuturo(): string
    {
        $tz = new \DateTimeZone('America/Guayaquil');
        $hoy = new \DateTimeImmutable('now', $tz);
        return $hoy->modify('next monday')->format('Y-m-d');
    }

    /**
     * Invoca `APIController::guardar()` capturando el código HTTP y el JSON devuelto.
     */
    private function ejecutarGuardarApi(array $postData): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $postData;
        http_response_code(200);

        $code = 200;
        ob_start();
        try {
            APIController::guardar();
            $code = http_response_code() ?: 200;
        } catch (AppTerminationException $e) {
            $code = $e->getStatusCode();
        } finally {
            $raw = (string)ob_get_clean();
        }

        return [
            'httpCode' => $code,
            'raw' => $raw,
            'json' => json_decode($raw, true)
        ];
    }

    /**
     * Lanza múltiples procesos paralelos sincronizados mediante barrera READY / GO.
     *
     * @param array<int, array> $configs
     * @return array<int, array>
     */
    private function ejecutarWorkersParalelos(array $configs): array
    {
        $workerScript = __DIR__ . '/concurrent_reserva_worker.php';
        $procesos = [];

        $env = array_merge($_ENV, [
            'DB_HOST' => self::$host,
            'DB_USER' => self::$user,
            'DB_PASS' => self::$pass,
            'DB_NAME' => self::$dbName,
            'DB_PORT' => (string)self::$port,
        ]);

        foreach ($configs as $idx => $cfg) {
            $cmd = 'php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg(json_encode($cfg));
            $spec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $spec, $pipes, null, $env);
            $this->assertIsResource($proc, "No se pudo iniciar el worker paralelo #{$idx}");
            $procesos[$idx] = [
                'proc' => $proc,
                'pipes' => $pipes,
            ];
        }

        // Esperar barrera READY en STDERR en todos los workers antes de liberar cualquiera
        foreach ($procesos as $idx => $p) {
            $lineaReady = fgets($p['pipes'][2]);
            $this->assertSame("READY\n", $lineaReady, "El worker #{$idx} no emitió señal READY");
        }

        // Disparar señal GO simultáneamente a todos los workers
        foreach ($procesos as $p) {
            fwrite($p['pipes'][0], "GO\n");
            fflush($p['pipes'][0]);
        }

        // Recolectar salidas
        $resultados = [];
        foreach ($procesos as $idx => $p) {
            fclose($p['pipes'][0]);
            $stdout = stream_get_contents($p['pipes'][1]);
            $stderr = stream_get_contents($p['pipes'][2]);
            fclose($p['pipes'][1]);
            fclose($p['pipes'][2]);
            $exitCode = proc_close($p['proc']);

            $decoded = json_decode(trim((string)$stdout), true);
            $this->assertIsArray(
                $decoded,
                "Salida JSON inválida del worker #{$idx} (exit={$exitCode}): stdout={$stdout} stderr={$stderr}"
            );
            $decoded['stderr'] = $stderr;
            $resultados[$idx] = $decoded;
        }

        return $resultados;
    }

    public function testReservaValidaDeUnoYVariosServiciosConProfesionalCalculaDuracionPreciosYFinDesdeCatalogoEIgnoraValoresDelCliente(): void
    {
        $clienteRealId = $this->crearUsuario('cliente.real@appsalon.test', '0', 'Ana', 'Mora');
        $otroUsuarioId = $this->crearUsuario('impostor@appsalon.test', '0', 'Impostor', 'Falso');

        $servCorte = $this->crearServicio('Corte Dama', '25.00', 30);
        $servHidratacion = $this->crearServicio('Hidratación Profunda', '40.00', 45);

        $lunes = $this->proximoLunesFuturo();
        $profId = $this->crearProfesionalConAgenda(
            'Valeria Estilista',
            [$servCorte, $servHidratacion],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']]
        );

        $csrf = $this->autenticarUsuario($clienteRealId);

        // 1. Reserva de un solo servicio enviando valores manipulados del cliente (usuarioId, id, duracion, precio, hora_fin)
        $res1 = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'id' => '88888',
            'usuarioId' => (string)$otroUsuarioId,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'hora_fin' => '09:05',
            'duracion' => '5',
            'duracion_minutos' => '5',
            'duracion_total_minutos' => '5',
            'precio' => '0.01',
            'servicios' => (string)$servCorte
        ]);

        $this->assertSame(200, $res1['httpCode']);
        $this->assertTrue($res1['json']['resultado']['resultado']);
        $idCita1 = (int)$res1['json']['id'];
        $this->assertNotSame(88888, $idCita1);
        $this->assertSame($profId, $res1['json']['profesionalId']);
        $this->assertSame('09:00', $res1['json']['hora_inicio']);
        $this->assertSame('09:30', $res1['json']['hora_fin'], 'hora_fin debe calcularse desde el catálogo (30 min), ignorando 09:05 del cliente');
        $this->assertSame(30, $res1['json']['duracion_total_minutos']);

        $citaRepo = new CitaRepository(self::$db);
        $cita1 = $citaRepo->findById($idCita1);
        $this->assertNotNull($cita1);
        $this->assertSame((string)$clienteRealId, $cita1->usuarioId, 'usuarioId debe provenir exclusivamente de la sesión autenticada');
        $this->assertSame((string)$profId, $cita1->profesionalId);
        $this->assertSame('09:00:00', $cita1->hora_inicio);
        $this->assertSame('09:30:00', $cita1->hora_fin);
        $this->assertSame('30', $cita1->duracion_total_minutos);

        // 2. Reserva de varios servicios (30 + 45 = 75 min) con objetos manipulados en el cliente
        $service = new CitaService($citaRepo, new ServicioRepository(self::$db));
        $res2 = $service->reservar($clienteRealId, [
            'usuarioId' => $otroUsuarioId,
            'profesionalId' => $profId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'hora_fin' => '10:10',
            'duracion_total_minutos' => 10,
            'servicios' => [
                ['id' => $servCorte, 'nombre' => 'Nombre Falso', 'precio' => '1.00', 'duracion_minutos' => 5],
                ['id' => $servHidratacion, 'nombre' => 'Otro Falso', 'precio' => '2.00', 'duracion_minutos' => 5],
            ]
        ]);

        $this->assertSame(CitaService::STATUS_OK, $res2['status']);
        $this->assertSame(200, $res2['httpCode']);
        $this->assertSame('10:00', $res2['hora_inicio']);
        $this->assertSame('11:15', $res2['hora_fin']);
        $this->assertSame(75, $res2['duracion_total_minutos']);

        $serviciosCita2 = $citaRepo->findServiciosByCitaId((int)$res2['id']);
        $this->assertCount(2, $serviciosCita2);
        $this->assertSame('Corte Dama', $serviciosCita2[0]->nombre_servicio);
        $this->assertSame('25.00', $serviciosCita2[0]->precio_servicio);
        $this->assertSame('30', $serviciosCita2[0]->duracion_minutos);
        $this->assertSame('Hidratación Profunda', $serviciosCita2[1]->nombre_servicio);
        $this->assertSame('40.00', $serviciosCita2[1]->precio_servicio);
        $this->assertSame('45', $serviciosCita2[1]->duracion_minutos);
    }

    public function testInmutabilidadHistoricaAnteCambioOEliminacionPosteriorDelServicioEnCatalogo(): void
    {
        $clienteId = $this->crearUsuario('historico@appsalon.test', '0', 'Laura', 'Vega');
        $serv1 = $this->crearServicio('Manicura Tradicional', '18.50', 30);
        $serv2 = $this->crearServicio('Pedicura Spa', '32.00', 45);

        $lunes = $this->proximoLunesFuturo();
        $profId = $this->crearProfesionalConAgenda(
            'Sofía Especialista',
            [$serv1, $serv2],
            [['dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '16:00']]
        );

        $citaRepo = new CitaRepository(self::$db);
        $servRepo = new ServicioRepository(self::$db);
        $citaService = new CitaService($citaRepo, $servRepo);

        $reserva = $citaService->reservar($clienteId, [
            'profesionalId' => $profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => "{$serv1},{$serv2}"
        ]);
        $this->assertSame(CitaService::STATUS_OK, $reserva['status']);
        $citaId = (int)$reserva['id'];

        // Modificar el primer servicio (nombre, precio y duración) y eliminar el segundo del catálogo
        $servicioService = new ServicioService($servRepo);
        $upd = $servicioService->actualizar($serv1, [
            'nombre' => 'Manicura Rebautizada cara',
            'precio' => '99.99',
            'duracion_minutos' => '120'
        ]);
        $this->assertSame(ServicioService::STATUS_OK, $upd['status']);

        $del = $servicioService->eliminar($serv2);
        $this->assertSame(ServicioService::STATUS_OK, $del['status']);
        $this->assertNull($servRepo->findById($serv2), 'El segundo servicio fue eliminado del catálogo');

        // 1. Verificar que la cita y sus servicios capturados permanecen inmutables en base de datos
        $citaPersistida = $citaRepo->findById($citaId);
        $this->assertNotNull($citaPersistida);
        $this->assertSame('09:00:00', $citaPersistida->hora_inicio);
        $this->assertSame('10:15:00', $citaPersistida->hora_fin);
        $this->assertSame('75', $citaPersistida->duracion_total_minutos);

        $snapshotServicios = $citaRepo->findServiciosByCitaId($citaId);
        $this->assertCount(2, $snapshotServicios);
        $this->assertSame('Manicura Tradicional', $snapshotServicios[0]->nombre_servicio);
        $this->assertSame('18.50', $snapshotServicios[0]->precio_servicio);
        $this->assertSame('30', $snapshotServicios[0]->duracion_minutos);
        $this->assertSame('Pedicura Spa', $snapshotServicios[1]->nombre_servicio);
        $this->assertSame('32.00', $snapshotServicios[1]->precio_servicio);
        $this->assertSame('45', $snapshotServicios[1]->duracion_minutos);

        // 2. Verificar que la proyección administrativa por fecha usa los valores históricos inmutables
        $adminConsulta = $citaService->consultarCitasAdmin($lunes);
        $this->assertSame(CitaService::STATUS_OK, $adminConsulta['status']);
        $this->assertCount(2, $adminConsulta['citas']);
        $this->assertSame('Manicura Tradicional', $adminConsulta['citas'][0]->servicio);
        $this->assertSame('18.50', $adminConsulta['citas'][0]->precio);
        $this->assertSame('30', $adminConsulta['citas'][0]->duracion_minutos);
        $this->assertSame('Pedicura Spa', $adminConsulta['citas'][1]->servicio);
        $this->assertSame('32.00', $adminConsulta['citas'][1]->precio);
        $this->assertSame('45', $adminConsulta['citas'][1]->duracion_minutos);
    }

    public function testDescuentoDeCitasExistentesEnDisponibilidadYAceptacionDeCitasContiguas(): void
    {
        $clienteId = $this->crearUsuario('agenda@appsalon.test');
        $serv30 = $this->crearServicio('Corte Rápido', '20.00', 30);
        $serv45 = $this->crearServicio('Peinado', '35.00', 45);

        $lunes = $this->proximoLunesFuturo();
        $profId = $this->crearProfesionalConAgenda(
            'Marco Barbero',
            [$serv30, $serv45],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '11:00']]
        );

        $csrf = $this->autenticarUsuario($clienteId);

        // Reservar cita central [09:30, 10:15) de 45 minutos
        $resCentral = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:30',
            'servicios' => (string)$serv45
        ]);
        $this->assertSame(200, $resCentral['httpCode']);
        $this->assertSame('09:30', $resCentral['json']['hora_inicio']);
        $this->assertSame('10:15', $resCentral['json']['hora_fin']);

        $dispService = new DisponibilidadService(
            new ProfesionalRepository(self::$db),
            new ServicioRepository(self::$db),
            15,
            new CitaRepository(self::$db)
        );

        // Para un servicio de 30 min en [09:00, 11:00) con [09:30, 10:15) ocupado:
        // Ventana izquierda libre: [09:00, 09:30) -> cabe exactamente 09:00-09:30
        // Ventana derecha libre: [10:15, 11:00) -> caben 10:15-10:45 y 10:30-11:00
        $disp30 = $dispService->consultar($profId, $lunes, [$serv30]);
        $this->assertSame(DisponibilidadService::STATUS_OK, $disp30['status']);
        $intervalos30 = array_map(fn($i) => $i['inicio'] . '-' . $i['fin'], $disp30['intervalos']);
        $this->assertSame(['09:00-09:30', '10:15-10:45', '10:30-11:00'], $intervalos30);

        // Para un servicio de 45 min en [09:00, 11:00) con [09:30, 10:15) ocupado:
        // En [09:00, 09:30) NO cabe (solo 30 min); en [10:15, 11:00) cabe exactamente 10:15-11:00
        $disp45 = $dispService->consultar($profId, $lunes, [$serv45]);
        $this->assertSame(DisponibilidadService::STATUS_OK, $disp45['status']);
        $intervalos45 = array_map(fn($i) => $i['inicio'] . '-' . $i['fin'], $disp45['intervalos']);
        $this->assertSame(['10:15-11:00'], $intervalos45);

        // Reservar cita contigua anterior [09:00, 09:30) (finA == inicioB == 09:30) -> debe aceptarse con 200
        $resContiguaAntes = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$serv30
        ]);
        $this->assertSame(200, $resContiguaAntes['httpCode']);

        // Reservar cita contigua posterior [10:15, 11:00) (inicioC == finB == 10:15) -> debe aceptarse con 200
        $resContiguaDespues = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '10:15',
            'servicios' => (string)$serv45
        ]);
        $this->assertSame(200, $resContiguaDespues['httpCode']);

        // Ahora el turno [09:00, 11:00) está completamente lleno ([09:00,09:30) + [09:30,10:15) + [10:15,11:00))
        $dispVacia = $dispService->consultar($profId, $lunes, [$serv30]);
        $this->assertSame(DisponibilidadService::STATUS_EMPTY, $dispVacia['status']);
        $this->assertFalse($dispVacia['disponible']);
        $this->assertSame([], $dispVacia['intervalos']);
    }

    public function testRechazoDeSolapamientosParcialesYTotalesConHttp409(): void
    {
        $clienteId = $this->crearUsuario('solape@appsalon.test');
        $serv30 = $this->crearServicio('Servicio 30', '20.00', 30);
        $serv60 = $this->crearServicio('Servicio 60', '40.00', 60);
        $serv120 = $this->crearServicio('Servicio 120', '80.00', 120);

        $lunes = $this->proximoLunesFuturo();
        $profId = $this->crearProfesionalConAgenda(
            'Lucía Estilista',
            [$serv30, $serv60, $serv120],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '14:00']]
        );

        $csrf = $this->autenticarUsuario($clienteId);

        // Reserva base en [10:00, 11:00)
        $resBase = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'servicios' => (string)$serv60
        ]);
        $this->assertSame(200, $resBase['httpCode']);

        $casosSolape = [
            'solape_total_exacto_10_00_a_11_00' => ['hora' => '10:00', 'servicio' => $serv60],
            'solape_parcial_inicio_09_30_a_10_30' => ['hora' => '09:30', 'servicio' => $serv60],
            'solape_parcial_fin_10_30_a_11_30' => ['hora' => '10:30', 'servicio' => $serv60],
            'solape_contenido_interno_10_15_a_10_45' => ['hora' => '10:15', 'servicio' => $serv30],
            'solape_envolvente_09_30_a_11_30' => ['hora' => '09:30', 'servicio' => $serv120],
        ];

        foreach ($casosSolape as $nombreCaso => $caso) {
            $intento = $this->ejecutarGuardarApi([
                'csrf_token' => $csrf,
                'profesionalId' => (string)$profId,
                'fecha' => $lunes,
                'hora' => $caso['hora'],
                'servicios' => (string)$caso['servicio']
            ]);
            $this->assertSame(409, $intento['httpCode'], "El caso {$nombreCaso} debe rechazarse con HTTP 409");
            $this->assertFalse($intento['json']['resultado']);
            $this->assertSame('conflicto_ocupacion', $intento['json']['codigo']);
        }

        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas WHERE profesionalId = {$profId}")->fetch_assoc()['total'];
        $this->assertSame(1, $totalCitas, 'Ningún intento solapado debe persistir filas adicionales en citas');
    }

    public function testRechazoPorProfesionalInexistenteInactivoServicioIncompatibleHorarioNoDisponibleDescansoBloqueoOFechaInvalida(): void
    {
        $clienteId = $this->crearUsuario('validaciones@appsalon.test');
        $servCompatible = $this->crearServicio('Corte Compatible', '25.00', 45);
        $servIncompatible = $this->crearServicio('Alisado Incompatible', '90.00', 60);

        $lunes = $this->proximoLunesFuturo();
        $profActivoId = $this->crearProfesionalConAgenda(
            'Profesional Activo',
            [$servCompatible],
            [
                ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '13:00'],
                ['dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '18:00'],
            ],
            1
        );

        $profRepo = new ProfesionalRepository(self::$db);
        // Añadir descanso [10:30, 11:00) en el turno de la mañana
        $profRepo->createDescanso(new DescansoProfesional([
            'profesionalId' => $profActivoId,
            'dia_semana' => 1,
            'hora_inicio' => '10:30',
            'hora_fin' => '11:00',
            'motivo' => 'Pausa café'
        ]));
        // Añadir bloqueo parcial [15:00, 16:00) en la fecha del lunes futuro
        $profRepo->createBloqueo(new BloqueoProfesional([
            'profesionalId' => $profActivoId,
            'fecha_inicio' => $lunes,
            'fecha_fin' => $lunes,
            'hora_inicio' => '15:00',
            'hora_fin' => '16:00',
            'motivo' => 'Cita médica'
        ]));

        $profInactivoId = $this->crearProfesionalConAgenda(
            'Profesional Inactivo',
            [$servCompatible],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']],
            0
        );

        $csrf = $this->autenticarUsuario($clienteId);

        // 1. Profesional inexistente -> 404
        $resInexistente = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => '999999',
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$servCompatible
        ]);
        $this->assertSame(404, $resInexistente['httpCode']);
        $this->assertSame('profesional_no_encontrado', $resInexistente['json']['codigo']);

        // 2. Profesional inactivo -> 409
        $resInactivo = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profInactivoId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$servCompatible
        ]);
        $this->assertSame(409, $resInactivo['httpCode']);
        $this->assertSame('profesional_inactivo', $resInactivo['json']['codigo']);

        // 3. Servicio incompatible -> 422
        $resIncomp = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profActivoId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => "{$servCompatible},{$servIncompatible}"
        ]);
        $this->assertSame(422, $resIncomp['httpCode']);
        $this->assertSame('servicios_incompatibles', $resIncomp['json']['codigo']);

        // 4. Fuera de horario (antes de abrir 08:30, excediendo cierre de turno 12:30+45m=13:15, o en hueco 13:15) -> 422
        foreach (['08:30', '12:30', '13:15', '17:30'] as $horaFuera) {
            $resFuera = $this->ejecutarGuardarApi([
                'csrf_token' => $csrf,
                'profesionalId' => (string)$profActivoId,
                'fecha' => $lunes,
                'hora' => $horaFuera,
                'servicios' => (string)$servCompatible
            ]);
            $this->assertSame(422, $resFuera['httpCode'], "Hora fuera de turno {$horaFuera} debe devolver 422");
            $this->assertSame('fuera_de_horario', $resFuera['json']['codigo']);
        }

        // 5. Cruzando descanso [10:30, 11:00) con servicio de 45 min iniciando a las 10:00 (terminaría 10:45) -> 422
        $resCruzaDescanso = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profActivoId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'servicios' => (string)$servCompatible
        ]);
        $this->assertSame(422, $resCruzaDescanso['httpCode']);
        $this->assertSame('fuera_de_horario', $resCruzaDescanso['json']['codigo']);

        // 6. Cruzando bloqueo parcial [15:00, 16:00) iniciando a las 14:30 (terminaría 15:15) -> 422
        $resCruzaBloqueo = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profActivoId,
            'fecha' => $lunes,
            'hora' => '14:30',
            'servicios' => (string)$servCompatible
        ]);
        $this->assertSame(422, $resCruzaBloqueo['httpCode']);
        $this->assertSame('fuera_de_horario', $resCruzaBloqueo['json']['codigo']);

        // 7. Fecha inválida o no futura -> 422
        $hoyGuayaquil = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        foreach (['2026-02-30', 'fecha-invalida', $hoyGuayaquil, '2022-01-10'] as $fechaInvalida) {
            $resFecha = $this->ejecutarGuardarApi([
                'csrf_token' => $csrf,
                'profesionalId' => (string)$profActivoId,
                'fecha' => $fechaInvalida,
                'hora' => '09:00',
                'servicios' => (string)$servCompatible
            ]);
            $this->assertSame(422, $resFecha['httpCode'], "Fecha inválida {$fechaInvalida} debe devolver 422");
        }
    }

    public function testReservasSimultaneasConProfesionalesDistintosEnMismoIntervaloAmbasExitosas200(): void
    {
        $cliente1 = $this->crearUsuario('c1.distintos@appsalon.test');
        $cliente2 = $this->crearUsuario('c2.distintos@appsalon.test');
        $servicio = $this->crearServicio('CorteSimultaneo', '30.00', 45);
        $lunes = $this->proximoLunesFuturo();

        $profA = $this->crearProfesionalConAgenda(
            'Profesional A',
            [$servicio],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']]
        );
        $profB = $this->crearProfesionalConAgenda(
            'Profesional B',
            [$servicio],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']]
        );

        $resultados = $this->ejecutarWorkersParalelos([
            [
                'accion' => 'reservar',
                'usuarioId' => $cliente1,
                'profesionalId' => $profA,
                'fecha' => $lunes,
                'hora' => '10:00',
                'servicios' => (string)$servicio,
            ],
            [
                'accion' => 'reservar',
                'usuarioId' => $cliente2,
                'profesionalId' => $profB,
                'fecha' => $lunes,
                'hora' => '10:00',
                'servicios' => (string)$servicio,
            ],
        ]);

        $this->assertSame(200, $resultados[0]['httpCode'], 'Worker 0: ' . json_encode($resultados[0]));
        $this->assertSame(200, $resultados[1]['httpCode'], 'Worker 1: ' . json_encode($resultados[1]));

        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas WHERE fecha = '{$lunes}'")->fetch_assoc()['total'];
        $this->assertSame(2, $totalCitas, 'Las reservas simultáneas con profesionales distintos no deben bloquearse entre sí');
    }

    public function testDosReservasSimultaneasSobreMismoProfesionalEIntervaloSolapadoEnProcesosParalelosUna200YOtra409(): void
    {
        $cliente1 = $this->crearUsuario('race1@appsalon.test');
        $cliente2 = $this->crearUsuario('race2@appsalon.test');
        $servicio45 = $this->crearServicio('Corte Concurrencia', '30.00', 45);
        $lunes = $this->proximoLunesFuturo();

        $profId = $this->crearProfesionalConAgenda(
            'Profesional Concurrido',
            [$servicio45],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']]
        );

        // Worker 0 pide [10:00, 10:45) y Worker 1 pide [10:15, 11:00) (solape parcial en [10:15, 10:45))
        $resultados = $this->ejecutarWorkersParalelos([
            [
                'accion' => 'reservar',
                'usuarioId' => $cliente1,
                'profesionalId' => $profId,
                'fecha' => $lunes,
                'hora' => '10:00',
                'servicios' => (string)$servicio45,
            ],
            [
                'accion' => 'reservar',
                'usuarioId' => $cliente2,
                'profesionalId' => $profId,
                'fecha' => $lunes,
                'hora' => '10:15',
                'servicios' => (string)$servicio45,
            ],
        ]);

        $codigos = [$resultados[0]['httpCode'], $resultados[1]['httpCode']];
        sort($codigos);
        $this->assertSame(
            [200, 409],
            $codigos,
            'Exactamente una reserva concurrente debe responder 200 y la otra debe ser rechazada con 409: ' . json_encode($resultados)
        );

        // Verificar que la rechazada tiene código conflicto_ocupacion
        foreach ($resultados as $r) {
            if ($r['httpCode'] === 409) {
                $this->assertSame('conflicto_ocupacion', $r['decoded']['codigo'] ?? null, 'Respuesta 409: ' . json_encode($r));
                $this->assertFalse($r['decoded']['resultado'] ?? true);
            }
        }

        // Comprobar en la base de datos que únicamente se insertó una cita
        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas WHERE profesionalId = {$profId} AND fecha = '{$lunes}'")->fetch_assoc()['total'];
        $this->assertSame(1, $totalCitas, 'El bloqueo transaccional InnoDB debe impedir que persistan dos citas solapadas');
    }

    public function testCambioOBloqueoSimultaneoDeAgendaFrenteAReservaSobreEseRangoLeeEstadoActualTrasAdquirirBloqueo(): void
    {
        $clienteId = $this->crearUsuario('race.bloqueo@appsalon.test');
        $servicio45 = $this->crearServicio('Corte Agenda Viva', '30.00', 45);
        $lunes = $this->proximoLunesFuturo();

        $profId = $this->crearProfesionalConAgenda(
            'Profesional Bloqueo En Vivo',
            [$servicio45],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']]
        );

        // 1. Abrir una conexión independiente para una mutación administrativa de agenda que mantiene
        // el bloqueo FOR UPDATE sobre `profesionales` mientras un proceso paralelo intenta reservar en [10:00, 10:45).
        $connAdmin = new mysqli(self::$host, self::$user, self::$pass, self::$dbName, self::$port);
        $connAdmin->set_charset('utf8mb4');

        $connAdmin->begin_transaction();
        $stmtLock = $connAdmin->prepare("SELECT id FROM profesionales WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmtLock->bind_param('i', $profId);
        $stmtLock->execute();
        if ($resLock = $stmtLock->get_result()) {
            $resLock->free();
        }
        $stmtLock->close();

        // Insertar un bloqueo de agenda [10:00, 11:00) dentro de la transacción aún sin confirmar
        $stmtBloq = $connAdmin->prepare(
            "INSERT INTO bloqueos_profesionales (profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo)
             VALUES (?, ?, ?, '10:00:00', '11:00:00', 'Bloqueo simultáneo en curso')"
        );
        $stmtBloq->bind_param('iss', $profId, $lunes, $lunes);
        $stmtBloq->execute();
        $stmtBloq->close();

        // Lanzar worker paralelo de reserva que esperará por el bloqueo InnoDB de `profesionales`
        $workerScript = __DIR__ . '/concurrent_reserva_worker.php';
        $cfg = [
            'accion' => 'reservar',
            'usuarioId' => $clienteId,
            'profesionalId' => $profId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'servicios' => (string)$servicio45,
        ];
        $env = array_merge($_ENV, [
            'DB_HOST' => self::$host,
            'DB_USER' => self::$user,
            'DB_PASS' => self::$pass,
            'DB_NAME' => self::$dbName,
            'DB_PORT' => (string)self::$port,
        ]);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open('php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg(json_encode($cfg)), $spec, $pipes, null, $env);
        $this->assertIsResource($proc);

        $lineaReady = fgets($pipes[2]);
        $this->assertSame("READY\n", $lineaReady);

        // Disparar GO al worker mientras connAdmin todavía sostiene el bloqueo FOR UPDATE
        fwrite($pipes[0], "GO\n");
        fflush($pipes[0]);
        fclose($pipes[0]);

        // Dar margen breve para que el worker entre en espera del lock sobre `profesionales`, y confirmar el bloqueo
        usleep(120000);
        $connAdmin->commit();
        $connAdmin->close();

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $resWorker = json_decode(trim((string)$stdout), true);
        $this->assertIsArray($resWorker);
        $this->assertSame(
            422,
            $resWorker['httpCode'],
            'Tras adquirir el bloqueo, la reserva debe hacer lectura actual (FOR SHARE), ver el nuevo bloqueo confirmado y rechazarse con 422: ' . json_encode($resWorker)
        );
        $this->assertSame('fuera_de_horario', $resWorker['decoded']['codigo'] ?? null, 'Respuesta 422: ' . json_encode($resWorker));

        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas WHERE profesionalId = {$profId}")->fetch_assoc()['total'];
        $this->assertSame(0, $totalCitas, 'No debe haberse insertado la cita sobre el rango recién bloqueado');
    }

    public function testLiberacionDeDisponibilidadTrasEliminarCitaAutorizada(): void
    {
        $clienteId = $this->crearUsuario('liberar@appsalon.test');
        $servicio60 = $this->crearServicio('Masaje Capilar', '50.00', 60);
        $lunes = $this->proximoLunesFuturo();

        $profId = $this->crearProfesionalConAgenda(
            'Elena Terapeuta',
            [$servicio60],
            [['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '12:00']]
        );

        $csrf = $this->autenticarUsuario($clienteId);

        // 1. Reservar [10:00, 11:00)
        $resReserva = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'servicios' => (string)$servicio60
        ]);
        $this->assertSame(200, $resReserva['httpCode']);
        $idCita = (int)$resReserva['json']['id'];

        $dispService = new DisponibilidadService(
            new ProfesionalRepository(self::$db),
            new ServicioRepository(self::$db),
            15,
            new CitaRepository(self::$db)
        );

        // Verificar que 10:00-11:00 ya NO está disponible (solo queda 11:00-12:00)
        $dispOcupada = $dispService->consultar($profId, $lunes, [$servicio60]);
        $paresOcupados = array_map(fn($i) => $i['inicio'] . '-' . $i['fin'], $dispOcupada['intervalos']);
        $this->assertSame(['11:00-12:00'], $paresOcupados);

        // 2. Eliminar la cita mediante CitaService::eliminar (usuario propietario autorizado)
        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $resElim = $citaService->eliminar($idCita, $clienteId, false);
        $this->assertSame(CitaService::STATUS_OK, $resElim['status']);

        // 3. Verificar que [10:00, 11:00) vuelve a estar disponible y puede reservarse de nuevo
        $dispLiberada = $dispService->consultar($profId, $lunes, [$servicio60]);
        $paresLiberados = array_map(fn($i) => $i['inicio'] . '-' . $i['fin'], $dispLiberada['intervalos']);
        $this->assertContains('10:00-11:00', $paresLiberados);

        $resReReserva = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '10:00',
            'servicios' => (string)$servicio60
        ]);
        $this->assertSame(200, $resReReserva['httpCode']);
    }

    public function testConservacionDeCitasHistoricasSinProfesionalYContratoFrontendActual(): void
    {
        $clienteId = $this->crearUsuario('legado@appsalon.test', '0', 'Pedro', 'Histórico');
        $servId = $this->crearServicio('Corte Tradicional', '15.00', 30);
        $lunes = $this->proximoLunesFuturo();

        // 1. Insertar directamente una cita histórica previa (sin profesional ni snapshot)
        self::$db->query(
            "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId)
             VALUES ('{$lunes}', '11:00:00', NULL, NULL, NULL, {$clienteId}, NULL)"
        );
        $idCitaHistorica = (int)self::$db->insert_id;
        self::$db->query(
            "INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
             VALUES ({$idCitaHistorica}, {$servId}, NULL, NULL, NULL)"
        );

        // 2. Ejecutar reserva mediante el contrato actual del frontend (sin profesionalId en POST)
        $csrf = $this->autenticarUsuario($clienteId);
        $resFrontend = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'fecha' => $lunes,
            'hora' => '12:00',
            'servicios' => (string)$servId
        ]);
        $this->assertSame(200, $resFrontend['httpCode']);
        $this->assertTrue($resFrontend['json']['resultado']['resultado']);
        $idCitaFrontend = (int)$resFrontend['json']['resultado']['id'];

        $citaRepo = new CitaRepository(self::$db);
        $citaHist = $citaRepo->findById($idCitaHistorica);
        $this->assertNull($citaHist->profesionalId, 'La cita histórica debe conservar profesionalId = null');
        $this->assertNull($citaHist->hora_inicio);
        $this->assertNull($citaHist->hora_fin);

        $citaFront = $citaRepo->findById($idCitaFrontend);
        $this->assertNull($citaFront->profesionalId, 'La reserva sin profesional no debe inventar profesionalId');

        // Ambas aparecen correctamente en el panel de administración
        $citaService = new CitaService($citaRepo, new ServicioRepository(self::$db));
        $adminRes = $citaService->consultarCitasAdmin($lunes);
        $this->assertSame(CitaService::STATUS_OK, $adminRes['status']);
        $this->assertCount(2, $adminRes['citas']);
    }

    public function testPreservacionDeAutenticacionCsrfAutorizacionYFalloSqlRealConRollback(): void
    {
        $clienteId = $this->crearUsuario('seguridad.f4b@appsalon.test');
        $otroClienteId = $this->crearUsuario('intruso.f4b@appsalon.test');
        $servId = $this->crearServicio('Corte Seguro', '25.00', 30);
        $lunes = $this->proximoLunesFuturo();

        $profId = $this->crearProfesionalConAgenda(
            'Profesional Seguro',
            [$servId],
            [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '17:00']]
        );

        // 1. No autenticado -> 401
        $_SESSION = [];
        $resNoAuth = $this->ejecutarGuardarApi([
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$servId
        ]);
        $this->assertSame(401, $resNoAuth['httpCode']);

        // 2. CSRF inválido -> 403
        $csrf = $this->autenticarUsuario($clienteId);
        $resNoCsrf = $this->ejecutarGuardarApi([
            'csrf_token' => 'token_invalido',
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$servId
        ]);
        $this->assertSame(403, $resNoCsrf['httpCode']);

        // 3. Fallo SQL real en citasservicios durante reserva con profesional -> 500 y rollback total
        self::$db->query(
            "CREATE TRIGGER test_fail_citasservicios_fase4b BEFORE INSERT ON citasservicios
             FOR EACH ROW BEGIN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo interno SQL simulado en citasservicios';
             END"
        );

        $resFalloSql = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$servId
        ]);
        self::$db->query("DROP TRIGGER IF EXISTS test_fail_citasservicios_fase4b");

        $this->assertSame(500, $resFalloSql['httpCode']);
        $this->assertFalse($resFalloSql['json']['resultado']);
        $this->assertSame('No se pudo procesar la reserva. Operación cancelada.', $resFalloSql['json']['error']);
        $this->assertStringNotContainsString('SQLSTATE', $resFalloSql['raw']);

        $totalTrasRollback = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas")->fetch_assoc()['total'];
        $this->assertSame(0, $totalTrasRollback, 'El rollback debe revertir la inserción en citas ante fallo en citasservicios');

        // 4. Crear reserva legítima e intentar eliminarla con otro cliente no autorizado -> 403 y conserva ocupación
        $resLegitima = $this->ejecutarGuardarApi([
            'csrf_token' => $csrf,
            'profesionalId' => (string)$profId,
            'fecha' => $lunes,
            'hora' => '09:00',
            'servicios' => (string)$servId
        ]);
        $this->assertSame(200, $resLegitima['httpCode']);
        $idCita = (int)$resLegitima['json']['id'];

        $citaService = new CitaService(new CitaRepository(self::$db), new ServicioRepository(self::$db));
        $intentoIntruso = $citaService->eliminar($idCita, $otroClienteId, false);
        $this->assertSame(CitaService::STATUS_FORBIDDEN, $intentoIntruso['status']);
        $this->assertSame(403, $intentoIntruso['httpCode']);
        $this->assertNotNull((new CitaRepository(self::$db))->findById($idCita));
    }
}
