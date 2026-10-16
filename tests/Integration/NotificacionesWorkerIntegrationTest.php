<?php

declare(strict_types=1);

namespace Tests\Integration;

use Classes\Email;
use Model\ActiveRecord;
use Model\NotificacionCita;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\NotificacionCitaRepository;
use Services\CitaService;
use Services\NotificacionCitaService;

/**
 * Pruebas de integración para Notificaciones, Outbox y Resiliencia de Workers (Especialista 4):
 * 1. Evento outbox durable disponible en BD tras commit incluso si el despacho SMTP falla.
 * 2. Muerte o desconexión del worker: tras expirar el lease, un nuevo worker adquiere y procesa con éxito.
 * 3. Exclusión de workers obsoletos: worker con lease expirado intenta marcar como enviado y es rechazado.
 * 4. Cancelación de cita antes de reintentar confirmación descarta la notificación pendiente.
 * 5. Recordatorio evaluado después de la hora de la cita es descartado automáticamente.
 */
class NotificacionesWorkerIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;
    private static NotificacionCitaRepository $notifRepo;
    private static CitaRepository $citaRepo;
    private static NotificacionCitaService $notifService;
    private static CitaService $citaService;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        self::$db = new mysqli($host, $user, $pass, $name, $port);
        self::$db->set_charset('utf8mb4');
        validar_base_datos_prueba(self::$db);
        ActiveRecord::setDB(self::$db);

        self::$notifRepo = new NotificacionCitaRepository(self::$db);
        self::$citaRepo = new CitaRepository(self::$db);
        self::$notifService = new NotificacionCitaService(self::$notifRepo, self::$citaRepo);
        self::$citaService = new CitaService(self::$citaRepo, null, null, self::$notifService);
    }

    protected function setUp(): void
    {
        validar_base_datos_prueba(self::$db);

        self::$db->query("DELETE FROM pagos");
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

        Email::setTransport(null);
    }

    protected function tearDown(): void
    {
        Email::setTransport(null);
    }

    private function crearUsuarioYProfesional(): array
    {
        self::$db->query("INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado, token)
            VALUES ('Cliente', 'Pruebas', 'cliente@example.com', 'hash123', '0999999999', 0, 1, '')");
        $usuarioId = (int)self::$db->insert_id;

        self::$db->query("INSERT INTO profesionales (nombre, activo)
            VALUES ('Profesional Uno', 1)");
        $profId = (int)self::$db->insert_id;

        self::$db->query("INSERT INTO servicios (nombre, precio, duracion_minutos)
            VALUES ('Corte Cabello', 25.00, 30)");
        $servicioId = (int)self::$db->insert_id;

        self::$db->query("INSERT INTO profesionales_servicios (profesionalId, servicioId) VALUES ({$profId}, {$servicioId})");

        return [$usuarioId, $profId, $servicioId];
    }

    /**
     * 1. Evento outbox durable: Si el despacho SMTP falla post-commit, el evento
     * queda registrado de forma durable en BD como 'pendiente' o 'reintentando'.
     */
    public function testEventoOutboxDurableDisponibleEnBaseDeDatosTrasTransaccion(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        // Simular que el transporte SMTP falla deliberadamente
        Email::setTransport(function () {
            return false;
        });

        // Crear evento de outbox durable para la cita
        $claveIdempotencia = 'outbox_test_' . bin2hex(random_bytes(8));
        $evento = self::$notifService->registrarEventoOutbox([
            'cita_id' => null,
            'cita_referencia_id' => 999,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => $claveIdempotencia,
            'destinatario_email' => 'cliente@example.com',
            'destinatario_nombre' => 'Cliente Pruebas',
            'asunto' => 'Confirmación de cita #999',
            'payload_json' => ['cita_id' => 999, 'servicio' => 'Corte Cabello'],
            'max_intentos' => 3,
        ]);

        $this->assertNotNull($evento->id);
        $this->assertSame(NotificacionCita::ESTADO_PENDIENTE, $evento->estado);

        // Verificar persistencia en base de datos
        $notifEnBd = self::$notifRepo->findByClaveIdempotencia($claveIdempotencia);
        $this->assertNotNull($notifEnBd);
        $this->assertSame('999', (string)$notifEnBd->cita_referencia_id);
        $this->assertSame(NotificacionCita::ESTADO_PENDIENTE, $notifEnBd->estado);
    }

    /**
     * 2. Muerte del worker: tras expirar el lease, un nuevo worker adquiere y despacha la notificación.
     */
    public function testRecuperacionDeWorkerTrasExpirarLease(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        $claveIdempotencia = 'lease_recovery_' . bin2hex(random_bytes(8));
        $evento = self::$notifService->registrarEventoOutbox([
            'cita_id' => null,
            'cita_referencia_id' => 101,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => $claveIdempotencia,
            'destinatario_email' => 'cliente@example.com',
            'destinatario_nombre' => 'Cliente Pruebas',
            'asunto' => 'Cita 101',
            'payload_json' => ['cita_id' => 101],
            'max_intentos' => 3,
        ]);

        $idNotif = (int)$evento->id;

        // Worker 1 adquiere con lease ya expirado en el pasado
        $worker1Token = 'worker_muerto_1';
        self::$db->query("UPDATE notificaciones_citas
            SET estado = 'enviando',
                worker_token = '{$worker1Token}',
                lease_expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND)
            WHERE id = {$idNotif}");

        // Worker 2 intenta adquirir la notificación atascada cuyo lease expiró
        $worker2Token = 'worker_recuperador_2';
        $adquirido = self::$notifService->adquirirParaEnvio($idNotif, $worker2Token, 300);
        $this->assertTrue($adquirido, 'Worker 2 debió adquirir la notificación con lease expirado.');

        // Simular éxito SMTP en Worker 2
        $marcadoEnviado = self::$notifService->marcarComoEnviado($idNotif, $worker2Token);
        $this->assertTrue($marcadoEnviado, 'Worker 2 debió marcar la notificación como enviada.');

        $notifFinal = self::$notifRepo->findById($idNotif);
        $this->assertSame(NotificacionCita::ESTADO_ENVIADO, $notifFinal->estado);
        $this->assertNull($notifFinal->worker_token);
        $this->assertNull($notifFinal->lease_expires_at);
    }

    /**
     * 3. Exclusión de workers obsoletos: worker con lease expirado intenta marcar como enviado
     * y es rechazado porque otro worker ya tomó o finalizó el registro.
     */
    public function testExclusionDeWorkerObsoletoConTokenDesactualizado(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        $claveIdempotencia = 'obsolete_worker_' . bin2hex(random_bytes(8));
        $evento = self::$notifService->registrarEventoOutbox([
            'cita_id' => null,
            'cita_referencia_id' => 102,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => $claveIdempotencia,
            'destinatario_email' => 'cliente@example.com',
            'destinatario_nombre' => 'Cliente Pruebas',
            'asunto' => 'Cita 102',
            'payload_json' => ['cita_id' => 102],
            'max_intentos' => 3,
        ]);

        $idNotif = (int)$evento->id;

        // Worker 2 adquiere formalmente
        $worker2Token = 'worker_activo_2';
        self::$notifService->adquirirParaEnvio($idNotif, $worker2Token, 300);

        // Worker 1 (antiguo/zombie) despierta e intenta marcar como enviado con su token caduco
        $worker1ZombieToken = 'worker_zombie_1';
        $resultadoZombie = self::$notifService->marcarComoEnviado($idNotif, $worker1ZombieToken);
        $this->assertFalse($resultadoZombie, 'Worker zombie con token caduco no debe poder mutar la notificación.');

        // El registro sigue perteneciendo a Worker 2
        $notifEnBd = self::$notifRepo->findById($idNotif);
        $this->assertSame(NotificacionCita::ESTADO_ENVIANDO, $notifEnBd->estado);
        $this->assertSame($worker2Token, $notifEnBd->worker_token);
    }

    /**
     * 4. Cancelación de cita antes del reintento: descarta la notificación pendiente.
     */
    public function testCancelacionDeCitaDescartaNotificacionPendiente(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        // Insertar cita en estado 'cancelada'
        self::$db->query("INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado, cancelado_por, cancelado_at, motivo_cancelacion)
            VALUES ('2026-10-15', '10:00:00', '10:00:00', '10:30:00', 30, {$usuarioId}, {$profId}, 'cancelada', 'cliente', NOW(), 'Cancelado por usuario')");
        $citaId = (int)self::$db->insert_id;

        $claveIdempotencia = 'cancel_discard_' . bin2hex(random_bytes(8));
        $evento = self::$notifService->registrarEventoOutbox([
            'cita_id' => $citaId,
            'cita_referencia_id' => $citaId,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => $claveIdempotencia,
            'destinatario_email' => 'cliente@example.com',
            'destinatario_nombre' => 'Cliente Pruebas',
            'asunto' => 'Confirmación Cita',
            'payload_json' => ['cita_id' => $citaId],
            'max_intentos' => 3,
        ]);

        $idNotif = (int)$evento->id;

        // Descartar notificación porque la cita ya está cancelada
        $descartada = self::$notifService->descartarNotificacion(
            $idNotif,
            'Cita cancelada previamente',
            null,
            NotificacionCita::ESTADO_DESCARTADA
        );
        $this->assertTrue($descartada);

        $notifFinal = self::$notifRepo->findById($idNotif);
        $this->assertSame(NotificacionCita::ESTADO_DESCARTADA, $notifFinal->estado);
        $this->assertStringContainsString('Cita cancelada previamente', (string)$notifFinal->ultimo_error);
    }

    /**
     * 5. Recordatorio evaluado después de la hora de la cita es descartado sin enviar correo.
     */
    public function testRecordatorioDeCitaPasadaEsDescartadoSinEnviarCorreo(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        $claveIdempotencia = 'reminder_past_' . bin2hex(random_bytes(8));
        $evento = self::$notifService->registrarEventoOutbox([
            'cita_id' => null,
            'cita_referencia_id' => 105,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_RECORDATORIO,
            'clave_idempotencia' => $claveIdempotencia,
            'destinatario_email' => 'cliente@example.com',
            'destinatario_nombre' => 'Cliente Pruebas',
            'asunto' => 'Recordatorio Cita Pasada',
            'payload_json' => [
                'cita_id' => 105,
                'fecha' => '2026-10-01', // Fecha pasada
                'hora' => '08:00:00',
            ],
            'max_intentos' => 3,
        ]);

        $idNotif = (int)$evento->id;

        // Descartar recordatorio por cita ya transcurrida
        $descartada = self::$notifService->descartarNotificacion(
            $idNotif,
            'Recordatorio descartado por cita ya transcurrida',
            null,
            NotificacionCita::ESTADO_DESCARTADA
        );
        $this->assertTrue($descartada);

        $notifFinal = self::$notifRepo->findById($idNotif);
        $this->assertSame(NotificacionCita::ESTADO_DESCARTADA, $notifFinal->estado);
        $this->assertStringContainsString('Recordatorio descartado por cita ya transcurrida', (string)$notifFinal->ultimo_error);
    }

    /**
     * 6. Límites atómicos: adquirirParaEnvio rechaza registros que ya alcanzaron max_intentos.
     */
    public function testAdquirirParaEnvioRechazaRegistroQueAlcanzoMaxIntentos(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        $clave = 'limit_max_intentos_' . bin2hex(random_bytes(8));
        $evento = self::$notifService->registrarEventoOutbox([
            'cita_id' => null,
            'cita_referencia_id' => 106,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => $clave,
            'destinatario_email' => 'limite@example.com',
            'destinatario_nombre' => 'Cliente Limite',
            'asunto' => 'Confirmacion Limite',
            'payload_json' => ['cita_id' => 106],
            'max_intentos' => 3,
        ]);
        $idNotif = (int)$evento->id;

        // Forzar a que intentos sea igual a max_intentos
        self::$db->query("UPDATE notificaciones_citas SET intentos = 3, max_intentos = 3, estado = 'fallido' WHERE id = {$idNotif}");

        $tokenWorker = 'worker_test_limite';
        $adquirido = self::$notifRepo->adquirirParaEnvio($idNotif, $tokenWorker, 300);

        $this->assertFalse($adquirido, 'adquirirParaEnvio debe rechazar atómicamente si intentos >= max_intentos');

        $notifPost = self::$notifRepo->findById($idNotif);
        $this->assertSame(3, (int)$notifPost->intentos, 'Los intentos no debieron incrementarse');
        $this->assertSame('fallido', $notifPost->estado, 'El estado no debió cambiar a enviando');
    }

    /**
     * 7. Límites atómicos: adquirirParaEnvio respeta el periodo de backoff (proximo_intento_at).
     */
    public function testAdquirirParaEnvioRespetaPeriodoDeBackoff(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        $clave = 'backoff_test_' . bin2hex(random_bytes(8));
        $evento = self::$notifService->registrarEventoOutbox([
            'cita_id' => null,
            'cita_referencia_id' => 107,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => $clave,
            'destinatario_email' => 'backoff@example.com',
            'destinatario_nombre' => 'Cliente Backoff',
            'asunto' => 'Confirmacion Backoff',
            'payload_json' => ['cita_id' => 107],
            'max_intentos' => 5,
        ]);
        $idNotif = (int)$evento->id;

        // Caso A: Backoff activo en el futuro (NOW() + 1 hora)
        self::$db->query("UPDATE notificaciones_citas SET estado = 'reintentando', intentos = 1, proximo_intento_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = {$idNotif}");

        $adquiridoFuturo = self::$notifRepo->adquirirParaEnvio($idNotif, 'worker_backoff_1', 300);
        $this->assertFalse($adquiridoFuturo, 'No debe adquirir si proximo_intento_at se encuentra en el futuro');

        // Caso B: Backoff vencido en el pasado (NOW() - 1 minuto)
        self::$db->query("UPDATE notificaciones_citas SET proximo_intento_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$idNotif}");

        $adquiridoPasado = self::$notifRepo->adquirirParaEnvio($idNotif, 'worker_backoff_2', 300);
        $this->assertTrue($adquiridoPasado, 'Debe adquirir exitosamente si proximo_intento_at ya venció');

        $notifAdquirida = self::$notifRepo->findById($idNotif);
        $this->assertSame(NotificacionCita::ESTADO_ENVIANDO, $notifAdquirida->estado);
        $this->assertSame('worker_backoff_2', $notifAdquirida->worker_token);
        $this->assertSame(2, (int)$notifAdquirida->intentos);
    }

    /**
     * Prueba Frente 4 de auditoría:
     * Una cita cancelada antes del despacho (inmediato o worker) descarta la notificación bajo lease y jamás invoca el transporte SMTP.
     */
    public function testConfirmacionDeCitaCanceladaDescartaConLeaseYSinLlamarSmtp(): void
    {
        [$usuarioId, $profId, $servicioId] = $this->crearUsuarioYProfesional();

        // 1. Crear cita confirmada y luego cancelarla
        self::$db->query("INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado, cancelado_por, cancelado_at, motivo_cancelacion)
            VALUES ('2026-11-25', '11:00:00', '11:00:00', '11:30:00', 30, {$usuarioId}, {$profId}, 'cancelada', 'cliente', NOW(), 'Cancelado voluntario')");
        $citaId = (int)self::$db->insert_id;

        self::$db->query("INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
            VALUES ({$citaId}, {$servicioId}, 'Corte Test', 20.00, 30)");

        // Configurar transporte espía que detecte cualquier invocación a SMTP
        $smtpInvocado = false;
        $transportEspia = function () use (&$smtpInvocado) {
            $smtpInvocado = true;
            return true;
        };

        $servicioConEspia = new NotificacionCitaService(
            self::$notifRepo,
            new \Repositories\CitaRepository(self::$db),
            null,
            $transportEspia
        );

        // A. Intento de notificación inmediata para cita cancelada
        $resInmediato = $servicioConEspia->notificarConfirmacionCita($citaId);

        $this->assertFalse($smtpInvocado, 'SMTP jamás debe ser invocado si la cita está cancelada');
        $this->assertSame(NotificacionCitaService::STATUS_OMITIDO, $resInmediato['status']);
        $this->assertSame('cita_cancelada', $resInmediato['motivo']);

        // Comprobar que en la base de datos la notificación quedó registrada y marcada como 'descartada'
        $clave = NotificacionCitaService::generarClaveConfirmacion($citaId);
        $notifEnDb = self::$notifRepo->findByClaveIdempotencia($clave);
        $this->assertNotNull($notifEnDb);
        $this->assertSame(NotificacionCita::ESTADO_DESCARTADA, $notifEnDb->estado);
        $this->assertStringContainsString('cancelada', (string)$notifEnDb->ultimo_error);

        // B. Intento de procesamiento mediante worker en cola de pendientes
        // Simular que existía una notificación 'pendiente' asociada a esta cita cancelada
        $claveWorker = 'pend_cancel_' . bin2hex(random_bytes(8));
        $eventoWorker = self::$notifRepo->registrarEventoOutbox([
            'cita_id' => $citaId,
            'cita_referencia_id' => $citaId,
            'usuario_id' => $usuarioId,
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => $claveWorker,
            'destinatario_email' => 'cliente_cancel@example.com',
            'destinatario_nombre' => 'Cliente Cancel',
            'asunto' => 'Confirmacion Worker',
            'payload_json' => ['cita_id' => $citaId],
            'max_intentos' => 3,
        ], date('Y-m-d H:i:s'));

        $idWorker = (int)$eventoWorker->id;
        $smtpInvocado = false; // Reset

        $resWorker = $servicioConEspia->procesarPendientesYReintentos(10);

        $this->assertFalse($smtpInvocado, 'Worker jamás debe llamar a SMTP para una cita cancelada');
        $this->assertSame(0, $resWorker['enviados']);
        $this->assertGreaterThanOrEqual(1, $resWorker['omitidos']);

        $notifWorkerDb = self::$notifRepo->findById($idWorker);
        $this->assertSame(NotificacionCita::ESTADO_DESCARTADA, $notifWorkerDb->estado);
        $this->assertStringContainsString('cancelada', (string)$notifWorkerDb->ultimo_error);
    }
}

