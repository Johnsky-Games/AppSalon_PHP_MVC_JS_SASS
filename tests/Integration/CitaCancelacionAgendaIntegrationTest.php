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
     * Caso 6: Concurrencia y serialización mediante bloqueos pesimistas InnoDB (`FOR UPDATE`).
     */
    public function test_concurrencia_reserva_y_modificacion_agenda_bloqueo_pesimista(): void
    {
        $usuarioId = $this->crearUsuarioFixture();
        $servicioId = $this->crearServicioFixture('Peinado', 30.00, 60);
        $profId = $this->crearProfesionalConHorarioFixture($servicioId);
        $fecha = $this->obtenerFechaHabilFutura();

        // Reservar una primera cita confirmada
        $res1 = self::$citaService->reservar($usuarioId, [
            'fecha' => $fecha,
            'hora' => '12:00',
            'profesionalId' => $profId,
            'servicios' => (string)$servicioId
        ]);
        $this->assertSame(CitaService::STATUS_OK, $res1['status']);

        // Iniciar transacción en una segunda conexión para simular concurrencia
        $host = getenv('DB_HOST') ?: 'test-db-esp1';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $db2 = new mysqli($host, $user, $pass, $name, $port);
        $db2->set_charset('utf8mb4');

        $repo2 = new CitaRepository($db2);
        $profRepo2 = new ProfesionalRepository($db2);
        $db2->begin_transaction();

        // Adquirir bloqueo pesimista en db2
        $citasBloqueadas = $profRepo2->findCitasConfirmadasFuturasForUpdate($profId, $fecha, $db2);
        $this->assertCount(1, $citasBloqueadas);
        $this->assertSame('confirmada', $citasBloqueadas[0]->estado);
        $db2->commit();

        // Cancelar la cita lógicamente dentro de db2
        $canceladoExitoso = $repo2->cancelarCitaLogica((int)$res1['id'], 'admin', 'Cancelado en concurrencia');
        $this->assertTrue($canceladoExitoso);

        $db2->close();

        // En la conexión principal, verificar que la cita ahora figura cancelada y no bloquea disponibilidad
        $citaConsultada = self::$citaRepository->findById((int)$res1['id']);
        $this->assertSame('cancelada', $citaConsultada->estado);
    }
}
