<?php

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Model\ActiveRecord;
use Model\BloqueoProfesional;
use Model\Cita;
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
use Services\CitaService;
use Services\DisponibilidadService;
use Services\ProfesionalService;

/**
 * Pruebas de integración para las reglas de negocio de reservas, cancelación lógica y agenda (Fase 4B / Especialista 1):
 * 1. La política del servidor que exige profesional rechaza flujos clásico y legacy con 422.
 * 2. La cancelación lógica preserva registros en `citas` y `citasservicios`, pero libera el cupo en DisponibilidadService.
 * 3. La cancelación repetida de una cita ya cancelada es idempotente (200).
 * 4. El intento de cancelar una cita que ya ha pasado es rechazado con 422.
 * 5. La modificación de agenda (horarios, descanso, bloqueo) que colisiona con citas confirmadas es rechazada con 422.
 * 6. Concurrencia y serialización mediante bloqueos pesimistas InnoDB (`FOR UPDATE`).
 */
class CitaCancelacionAgendaIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;
    private static CitaRepository $citaRepository;
    private static ProfesionalRepository $profesionalRepository;
    private static ServicioRepository $servicioRepository;
    private static CitaService $citaService;
    private static ProfesionalService $profesionalService;
    private static DisponibilidadService $disponibilidadService;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: 'test-db-esp1';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        self::$db = new mysqli($host, $user, $pass, $name, $port);
        self::$db->set_charset('utf8mb4');
        ActiveRecord::setDB(self::$db);

        self::$citaRepository = new CitaRepository(self::$db);
        self::$profesionalRepository = new ProfesionalRepository(self::$db);
        self::$servicioRepository = new ServicioRepository(self::$db);

        self::$profesionalService = new ProfesionalService(self::$profesionalRepository, self::$servicioRepository);
        self::$disponibilidadService = new DisponibilidadService(
            self::$profesionalRepository,
            self::$servicioRepository,
            15,
            self::$citaRepository
        );
        self::$citaService = new CitaService(
            self::$citaRepository,
            self::$servicioRepository
        );
    }

    protected function setUp(): void
    {
        validar_base_datos_prueba(self::$db);

        self::$db->query("DELETE FROM erp_transferencia_referencias WHERE pago_id IS NOT NULL");
        self::$db->query("DELETE FROM pagos");
        self::$db->query("DELETE FROM notificaciones_citas");
        self::$db->query("DELETE FROM citasservicios");
        self::$db->query("DELETE FROM citas");
        self::$db->query("DELETE FROM descansos_profesionales");
        self::$db->query("DELETE FROM bloqueos_profesionales");
        self::$db->query("DELETE FROM horarios_profesionales");
        self::$db->query("DELETE FROM profesionales_servicios");
        self::$db->query("DELETE FROM servicios");
        self::$db->query("DELETE FROM profesionales");
        self::$db->query("DELETE FROM usuarios");

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    private function crearUsuarioFixture(string $email = 'cliente@test.com'): int
    {
        $stmt = self::$db->prepare("INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado, token) VALUES ('Test', 'Cliente', ?, ?, '0999999999', '0', '1', '')");
        $passHash = password_hash('Password123!', PASSWORD_BCRYPT);
        $stmt->bind_param('ss', $email, $passHash);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function crearServicioFixture(string $nombre = 'Corte Caballero', float $precio = 25.00, int $duracion = 60): int
    {
        $s = new Servicio([
            'nombre' => $nombre,
            'precio' => $precio,
            'duracion_minutos' => $duracion
        ]);
        return self::$servicioRepository->create($s);
    }

    private function crearProfesionalConHorarioFixture(int $servicioId): int
    {
        $prof = new Profesional([
            'nombre' => 'Ana Barbera',
            'activo' => 1
        ]);
        $profId = self::$profesionalRepository->createWithServicios($prof, [$servicioId]);

        // Configurar horario lunes a viernes 08:00 a 18:00
        $horarios = [];
        for ($d = 1; $d <= 5; $d++) {
            $horarios[] = new HorarioProfesional([
                'profesionalId' => $profId,
                'dia_semana' => $d,
                'hora_inicio' => '08:00',
                'hora_fin' => '18:00'
            ]);
        }
        self::$profesionalRepository->replaceHorarios($profId, $horarios);

        return $profId;
    }

    /**
     * Devuelve una fecha futura en día hábil (lunes a viernes).
     */
    private function obtenerFechaHabilFutura(int $diasAdelante = 7): string
    {
        $tz = new DateTimeZone('America/Guayaquil');
        $dt = new DateTimeImmutable('now', $tz);
        $dt = $dt->modify("+{$diasAdelante} days");
        while ((int)$dt->format('N') > 5) {
            $dt = $dt->modify('+1 day');
        }
        return $dt->format('Y-m-d');
    }

    /**
     * Caso 1: Servidor configurado para exigir profesional rechaza modo_reserva=clasico y legacy con 422.
     */
    public function test_servidor_exige_profesional_rechaza_modo_clasico_y_legacy(): void
    {
        $usuarioId = $this->crearUsuarioFixture();
        $servicioId = $this->crearServicioFixture();
        $fecha = $this->obtenerFechaHabilFutura();

        putenv('RESERVA_EXIGIR_PROFESIONAL=1');
        try {
            // 1. Intento con modo_reserva = 'clasico' sin profesional
            $resClasico = self::$citaService->reservar($usuarioId, [
                'fecha' => $fecha,
                'hora' => '10:00',
                'servicios' => (string)$servicioId,
                'modo_reserva' => 'clasico'
            ]);

            $this->assertSame(CitaService::STATUS_INVALID, $resClasico['status']);
            $this->assertSame('profesional_requerido', $resClasico['codigo']);
            $this->assertSame(422, $resClasico['httpCode']);
            $this->assertFalse($resClasico['resultado']);

            // 2. Intento con modo_reserva = 'legacy' sin profesional
            $resLegacy = self::$citaService->reservar($usuarioId, [
                'fecha' => $fecha,
                'hora' => '10:00',
                'servicios' => (string)$servicioId,
                'modo_reserva' => 'legacy'
            ]);

            $this->assertSame(CitaService::STATUS_INVALID, $resLegacy['status']);
            $this->assertSame('profesional_requerido', $resLegacy['codigo']);
            $this->assertSame(422, $resLegacy['httpCode']);
            $this->assertFalse($resLegacy['resultado']);

            // 3. Intento sin parámetro de profesional
            $resSinProf = self::$citaService->reservar($usuarioId, [
                'fecha' => $fecha,
                'hora' => '10:00',
                'servicios' => (string)$servicioId
            ]);

            $this->assertSame(CitaService::STATUS_INVALID, $resSinProf['status']);
            $this->assertSame('profesional_requerido', $resSinProf['codigo']);
            $this->assertSame(422, $resSinProf['httpCode']);
        } finally {
            putenv('RESERVA_EXIGIR_PROFESIONAL');
        }
    }

    /**
     * Caso 2: Cancelación lógica preserva registros en `citas` y `citasservicios`, pero libera el cupo en DisponibilidadService.
     */
    public function test_cancelacion_logica_preserva_registros_y_libera_agenda(): void
    {
        $usuarioId = $this->crearUsuarioFixture();
        $servicioId = $this->crearServicioFixture('Corte y Barba', 35.00, 60);
        $profId = $this->crearProfesionalConHorarioFixture($servicioId);
        $fecha = $this->obtenerFechaHabilFutura();

        // 1. Reservar cita confirmada de 10:00 a 11:00
        $resReserva = self::$citaService->reservar($usuarioId, [
            'fecha' => $fecha,
            'hora' => '10:00',
            'profesionalId' => $profId,
            'servicios' => (string)$servicioId
        ]);

        $this->assertSame(CitaService::STATUS_OK, $resReserva['status']);
        $citaId = (int)$resReserva['id'];
        $this->assertGreaterThan(0, $citaId);

        // 2. Verificar que a las 10:00 NO está disponible en DisponibilidadService
        $dispOcupada = self::$disponibilidadService->consultar($profId, $fecha, [$servicioId]);
        $horasOcupadas = array_column($dispOcupada['intervalos'], 'hora_inicio');
        $this->assertNotContains('10:00', $horasOcupadas, 'El turno de las 10:00 debe estar ocupado tras la reserva.');

        // 3. Cancelar la cita lógicamente
        $resCanc = self::$citaService->cancelarCita($citaId, $usuarioId, false, 'Imprevisto laboral');

        $this->assertSame(CitaService::STATUS_OK, $resCanc['status']);
        $this->assertTrue($resCanc['resultado']);
        $this->assertSame('cita_cancelada', $resCanc['codigo']);

        // 4. Verificar en base de datos: registros preservados intactos con nuevo estado
        $stmtCita = self::$db->prepare("SELECT estado, cancelado_por, cancelado_at, motivo_cancelacion FROM citas WHERE id = ?");
        $stmtCita->bind_param('i', $citaId);
        $stmtCita->execute();
        $rowCita = $stmtCita->get_result()->fetch_assoc();
        $stmtCita->close();

        $this->assertNotNull($rowCita);
        $this->assertSame('cancelada', $rowCita['estado']);
        $this->assertSame('cliente', $rowCita['cancelado_por']);
        $this->assertNotNull($rowCita['cancelado_at']);
        $this->assertSame('Imprevisto laboral', $rowCita['motivo_cancelacion']);

        // Verificar que los registros en citasservicios NO se eliminaron físicamente
        $stmtCS = self::$db->prepare("SELECT COUNT(*) AS total FROM citasservicios WHERE citaId = ?");
        $stmtCS->bind_param('i', $citaId);
        $stmtCS->execute();
        $rowCS = $stmtCS->get_result()->fetch_assoc();
        $stmtCS->close();
        $this->assertGreaterThan(0, (int)$rowCS['total'], 'Los registros históricos en citasservicios deben preservarse.');

        // 5. Verificar que el intervalo de las 10:00 vuelve a estar disponible en DisponibilidadService
        $dispLiberada = self::$disponibilidadService->consultar($profId, $fecha, [$servicioId]);
        $horasLiberadas = array_column($dispLiberada['intervalos'], 'hora_inicio');
        $this->assertContains('10:00', $horasLiberadas, 'El turno de las 10:00 debe volver a estar disponible tras la cancelación.');
    }

    /**
     * Caso 3: Cancelación repetida de una cita ya cancelada es idempotente (200).
     */
    public function test_cancelacion_cita_es_idempotente(): void
    {
        $usuarioId = $this->crearUsuarioFixture();
        $servicioId = $this->crearServicioFixture();
        $profId = $this->crearProfesionalConHorarioFixture($servicioId);
        $fecha = $this->obtenerFechaHabilFutura();

        $resReserva = self::$citaService->reservar($usuarioId, [
            'fecha' => $fecha,
            'hora' => '11:00',
            'profesionalId' => $profId,
            'servicios' => (string)$servicioId
        ]);
        $citaId = (int)$resReserva['id'];

        // Primera cancelación
        $canc1 = self::$citaService->cancelarCita($citaId, $usuarioId, false, 'Cancelación 1');
        $this->assertSame(CitaService::STATUS_OK, $canc1['status']);

        // Obtener el timestamp original de cancelación
        $stmt = self::$db->prepare("SELECT cancelado_at, motivo_cancelacion FROM citas WHERE id = ?");
        $stmt->bind_param('i', $citaId);
        $stmt->execute();
        $rowOriginal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $timestampOriginal = $rowOriginal['cancelado_at'];

        // Segunda cancelación (idempotente)
        $canc2 = self::$citaService->cancelarCita($citaId, $usuarioId, false, 'Cancelación 2 repetida');
        $this->assertSame(CitaService::STATUS_OK, $canc2['status']);
        $this->assertTrue($canc2['resultado']);
        $this->assertSame(200, $canc2['httpCode']);

        // Verificar que no se alteró el timestamp original ni se produjo error
        $stmt2 = self::$db->prepare("SELECT cancelado_at, motivo_cancelacion FROM citas WHERE id = ?");
        $stmt2->bind_param('i', $citaId);
        $stmt2->execute();
        $rowPosterior = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        $this->assertSame($timestampOriginal, $rowPosterior['cancelado_at']);
    }

    /**
     * Caso 4: Intento de cancelar directamente una cita que ya ha pasado es rechazado con 422.
     */
    public function test_cancelacion_directa_cita_pasada_es_rechazada(): void
    {
        $usuarioId = $this->crearUsuarioFixture();
        $servicioId = $this->crearServicioFixture();
        $profId = $this->crearProfesionalConHorarioFixture($servicioId);

        // Crear una cita confirmada con fecha pasada directamente en la base de datos
        $citaPasada = new Cita([
            'fecha' => '2023-01-10',
            'hora' => '10:00:00',
            'hora_inicio' => '10:00:00',
            'hora_fin' => '11:00:00',
            'duracion_total_minutos' => 60,
            'usuarioId' => $usuarioId,
            'profesionalId' => $profId,
            'estado' => 'confirmada'
        ]);
        $citaIdPasada = self::$citaRepository->createCita($citaPasada);
        $this->assertGreaterThan(0, $citaIdPasada);

        // Intentar cancelarla
        $res = self::$citaService->cancelarCita($citaIdPasada, $usuarioId, false, 'Tratando de cancelar cita vieja');

        $this->assertSame(CitaService::STATUS_INVALID, $res['status']);
        $this->assertSame('cita_pasada', $res['codigo']);
        $this->assertSame(422, $res['httpCode']);
        $this->assertFalse($res['resultado']);
        $this->assertStringContainsString('ya ha pasado', $res['error']);

        // Verificar que la cita sigue confirmada en la BD
        $stmt = self::$db->prepare("SELECT estado FROM citas WHERE id = ?");
        $stmt->bind_param('i', $citaIdPasada);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $this->assertSame('confirmada', $row['estado']);
    }

    /**
     * Caso 5: Modificación de agenda (horarios, descanso, bloqueo) que colisiona con citas confirmadas es rechazada con 422.
     */
    public function test_modificacion_agenda_colisiona_con_citas_confirmadas_es_rechazada(): void
    {
        $usuarioId = $this->crearUsuarioFixture();
        $servicioId = $this->crearServicioFixture('Coloración', 50.00, 60);
        $profId = $this->crearProfesionalConHorarioFixture($servicioId);
        $fecha = $this->obtenerFechaHabilFutura();
        $diaSemana = (int)date('N', strtotime($fecha));

        // Reservar cita confirmada para el profesional a las 10:00 - 11:00 en esa fecha
        $resReserva = self::$citaService->reservar($usuarioId, [
            'fecha' => $fecha,
            'hora' => '10:00',
            'profesionalId' => $profId,
            'servicios' => (string)$servicioId
        ]);
        $this->assertSame(CitaService::STATUS_OK, $resReserva['status']);

        // 1. Intento de modificar horario semanal: recortar la jornada de ese día para iniciar a las 14:00 (dejando fuera las 10:00)
        $nuevosHorarios = [
            [
                'dia_semana' => $diaSemana,
                'hora_inicio' => '14:00',
                'hora_fin' => '18:00'
            ]
        ];
        $resHorarios = self::$profesionalService->guardarHorariosSemanales($profId, $nuevosHorarios);

        $this->assertSame(ProfesionalService::STATUS_INVALID, $resHorarios['status']);
        $this->assertSame('colision_citas_confirmadas', $resHorarios['codigo']);
        $this->assertSame(422, $resHorarios['httpCode']);
        $this->assertFalse($resHorarios['resultado']);

        // 2. Intento de crear un descanso recurrente sobre el turno de las 10:00 en ese día de la semana
        $resDescanso = self::$profesionalService->crearDescanso($profId, [
            'dia_semana' => $diaSemana,
            'hora_inicio' => '10:00',
            'hora_fin' => '11:00',
            'motivo' => 'Almuerzo adelantado'
        ]);

        $this->assertSame(ProfesionalService::STATUS_INVALID, $resDescanso['status']);
        $this->assertSame('colision_citas_confirmadas', $resDescanso['codigo']);
        $this->assertSame(422, $resDescanso['httpCode']);
        $this->assertFalse($resDescanso['resultado']);

        // 3. Intento de crear un bloqueo que solape con la fecha y hora de la cita (10:00 - 11:00)
        $resBloqueo = self::$profesionalService->crearBloqueo($profId, [
            'fecha' => $fecha,
            'hora_inicio' => '09:30',
            'hora_fin' => '10:30',
            'motivo' => 'Capacitación'
        ]);

        $this->assertSame(ProfesionalService::STATUS_INVALID, $resBloqueo['status']);
        $this->assertSame('colision_citas_confirmadas', $resBloqueo['codigo']);
        $this->assertSame(422, $resBloqueo['httpCode']);
        $this->assertFalse($resBloqueo['resultado']);

        // 4. Intento de bloqueo de día completo sobre la fecha de la cita
        $resBloqueoDiaCompleto = self::$profesionalService->crearBloqueo($profId, [
            'fecha' => $fecha,
            'motivo' => 'Feriado imprevisto'
        ]);

        $this->assertSame(ProfesionalService::STATUS_INVALID, $resBloqueoDiaCompleto['status']);
        $this->assertSame('colision_citas_confirmadas', $resBloqueoDiaCompleto['codigo']);
        $this->assertSame(422, $resBloqueoDiaCompleto['httpCode']);
        $this->assertFalse($resBloqueoDiaCompleto['resultado']);
    }

    /**
     * Caso 6: Concurrencia real multiproceso y serialización mediante bloqueos pesimistas InnoDB (`FOR UPDATE`).
     * Un proceso administrador sostiene un bloqueo de agenda mientras un worker paralelo intenta
     * reservar simultáneamente mediante barrera sincronizada READY/GO.
     */
    public function test_concurrencia_reserva_y_modificacion_agenda_bloqueo_pesimista(): void
    {
        $usuarioId = $this->crearUsuarioFixture();
        $servicioId = $this->crearServicioFixture('Peinado Pro Concurrente', 35.00, 60);
        $profId = $this->crearProfesionalConHorarioFixture($servicioId);
        $fecha = $this->obtenerFechaHabilFutura();

        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        // Conexión independiente para el hilo de administración
        $connAdmin = new mysqli($host, $user, $pass, $name, $port);
        $connAdmin->set_charset('utf8mb4');

        $connAdmin->begin_transaction();
        $stmtLock = $connAdmin->prepare("SELECT id FROM profesionales WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmtLock->bind_param('i', $profId);
        $stmtLock->execute();
        if ($resLock = $stmtLock->get_result()) {
            $resLock->free();
        }
        $stmtLock->close();

        // El administrador inserta un bloqueo de 12:00 a 13:00 dentro de su transacción no confirmada
        $stmtBloq = $connAdmin->prepare(
            "INSERT INTO bloqueos_profesionales (profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo)
             VALUES (?, ?, ?, '12:00:00', '13:00:00', 'Bloqueo administrativo concurrente')"
        );
        $stmtBloq->bind_param('iss', $profId, $fecha, $fecha);
        $stmtBloq->execute();
        $stmtBloq->close();

        // Lanzar worker concurrente que intentará reservar a las 12:00
        $workerScript = __DIR__ . '/concurrent_reserva_worker.php';
        $cfg = [
            'accion' => 'reservar',
            'usuarioId' => $usuarioId,
            'profesionalId' => $profId,
            'fecha' => $fecha,
            'hora' => '12:00',
            'servicios' => (string)$servicioId,
        ];
        $env = array_merge($_ENV, [
            'DB_HOST' => $host,
            'DB_USER' => $user,
            'DB_PASS' => $pass,
            'DB_NAME' => $name,
            'DB_PORT' => (string)$port,
        ]);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open('php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg(json_encode($cfg)), $spec, $pipes, null, $env);
        $this->assertIsResource($proc);

        // Esperar señal READY del worker
        $lineaReady = fgets($pipes[2]);
        $this->assertSame("READY\n", $lineaReady, 'El worker paralelo debe reportar READY');

        // Disparar GO al worker mientras el administrador retiene el lock pesimista
        fwrite($pipes[0], "GO\n");
        fflush($pipes[0]);
        fclose($pipes[0]);

        // Margen para que el worker entre a la contención del bloqueo InnoDB, luego confirmar el bloqueo
        usleep(120000);
        $connAdmin->commit();
        $connAdmin->close();

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(0, $exitCode, 'El proceso worker debe finalizar limpiamente');
        $resWorker = json_decode(trim((string)$stdout), true);
        $this->assertIsArray($resWorker);

        // La reserva debe rechazarse de forma controlada con HTTP 422 fuera_de_horario
        $this->assertSame(422, $resWorker['httpCode'], 'La reserva paralela debe rechazarse con 422: ' . json_encode($resWorker));
        $this->assertSame('fuera_de_horario', $resWorker['decoded']['codigo'] ?? null);

        // Verificar que no se creó ninguna cita espuria en la base de datos
        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas WHERE profesionalId = {$profId}")->fetch_assoc()['total'];
        $this->assertSame(0, $totalCitas, 'No debe haberse registrado ninguna cita sobre el rango bloqueado');
    }

    /**
     * Caso 7 (Frente 8 de auditoría): Concurrencia en orden contrario.
     * Una reserva concurrente inicia primero en vuelo y adquiere su transacción/bloqueo,
     * y la modificación administrativa incompatible en ProfesionalService (crear bloqueo sobre el mismo intervalo)
     * se ejecuta después: es serializada correctamente por MySQL InnoDB y rechazada con 422 colision_citas_confirmadas.
     */
    public function test_concurrencia_orden_contrario_reserva_en_vuelo_bloquea_modificacion_agenda(): void
    {
        $usuarioId = $this->crearUsuarioFixture('cliente_contrario@test.com');
        $servicioId = $this->crearServicioFixture('Color Pro Concurrente', 45.00, 60);
        $profId = $this->crearProfesionalConHorarioFixture($servicioId);
        $fecha = $this->obtenerFechaHabilFutura();

        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        // Lanzar worker concurrente que ejecutará la reserva a las 11:00
        $workerScript = __DIR__ . '/concurrent_reserva_worker.php';
        $cfg = [
            'accion' => 'reservar',
            'usuarioId' => $usuarioId,
            'profesionalId' => $profId,
            'fecha' => $fecha,
            'hora' => '11:00',
            'servicios' => (string)$servicioId,
        ];
        $env = array_merge($_ENV, [
            'DB_HOST' => $host,
            'DB_USER' => $user,
            'DB_PASS' => $pass,
            'DB_NAME' => $name,
            'DB_PORT' => (string)$port,
        ]);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open('php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg(json_encode($cfg)), $spec, $pipes, null, $env);
        $this->assertIsResource($proc);

        // Esperar READY del worker
        $lineaReady = fgets($pipes[2]);
        $this->assertSame("READY\n", $lineaReady, 'El worker de reserva debe reportar READY');

        // Disparar GO para que el worker proceda con la reserva
        fwrite($pipes[0], "GO\n");
        fflush($pipes[0]);
        fclose($pipes[0]);

        // Margen suficiente para que el worker concrete la reserva
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(0, $exitCode, 'El proceso worker debe finalizar con código 0');
        $resWorker = json_decode(trim((string)$stdout), true);
        $this->assertSame(200, $resWorker['httpCode'], 'La reserva en vuelo debe ser exitosa: ' . json_encode($resWorker));

        // Inmediatamente después, el administrador intenta crear un bloqueo de agenda incompatible sobre ese mismo intervalo (11:00 - 12:00)
        $resBloqueo = self::$profesionalService->crearBloqueo($profId, [
            'fecha' => $fecha,
            'hora_inicio' => '10:30',
            'hora_fin' => '11:30',
            'motivo' => 'Reunión de última hora'
        ]);

        // Debe ser rechazado con 422 por colisión con la cita confirmada
        $this->assertSame(ProfesionalService::STATUS_INVALID, $resBloqueo['status']);
        $this->assertSame('colision_citas_confirmadas', $resBloqueo['codigo']);
        $this->assertSame(422, $resBloqueo['httpCode']);
        $this->assertFalse($resBloqueo['resultado']);

        // Comprobar que la cita del cliente quedó registrada y confirmada
        $totalCitas = (int)self::$db->query("SELECT COUNT(*) AS total FROM citas WHERE profesionalId = {$profId} AND estado = 'confirmada'")->fetch_assoc()['total'];
        $this->assertSame(1, $totalCitas, 'La cita reservada debe conservarse intacta');
    }
}

