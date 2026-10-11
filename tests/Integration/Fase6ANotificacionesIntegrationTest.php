<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Controllers\APIController;
use Model\ActiveRecord;
use Model\HorarioProfesional;
use Model\NotificacionCita;
use Model\Profesional;
use Model\Servicio;
use Model\Usuario;
use mysqli;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\NotificacionCitaRepository;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Repositories\UsuarioRepository;
use Services\CitaService;
use Services\DisponibilidadService;
use Services\NotificacionCitaService;
use Services\ProfesionalService;

class Fase6ANotificacionesIntegrationTest extends TestCase
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

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['REQUEST_URI'] = '/api/citas';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);

        Email::setTransport(function (): bool {
            return true;
        });
        Email::limpiarEmailsEnviados();

        APIController::setServicioService(null);
        APIController::setCitaService(null);
        APIController::setDisponibilidadService(null);
        APIController::setProfesionalService(null);
        CitaService::setExigirProfesional(null);
        ActiveRecord::setDB(self::$db);
    }

    protected function tearDown(): void
    {
        self::$db->query("DELETE FROM notificaciones_citas");
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        APIController::setServicioService(null);
        APIController::setCitaService(null);
        APIController::setDisponibilidadService(null);
        APIController::setProfesionalService(null);
        CitaService::setExigirProfesional(null);
        ActiveRecord::setDB(self::$db);
    }

    private function autenticarCliente(int $id, string $nombre = 'Carlos Mendoza'): string
    {
        $_SESSION['login'] = true;
        $_SESSION['id'] = $id;
        $_SESSION['nombre'] = $nombre;
        unset($_SESSION['admin']);
        $csrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrf;
        return $csrf;
    }

    private function crearUsuarioCliente(string $nombre, string $apellido, string $email): int
    {
        $repo = new UsuarioRepository(self::$db);
        return $repo->create(new Usuario([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'password' => password_hash('Password123!', PASSWORD_BCRYPT),
            'telefono' => '0991234567',
            'admin' => 0,
            'confirmado' => 1,
        ]));
    }

    private function crearServicioCatalogo(string $nombre, string $precio, int $duracionMinutos): int
    {
        $repo = new ServicioRepository(self::$db);
        return $repo->create(new Servicio([
            'nombre' => $nombre,
            'precio' => $precio,
            'duracion_minutos' => $duracionMinutos,
        ]));
    }

    /**
     * @param array<int, int> $servicioIds
     */
    private function crearProfesionalConHorarioLunes(string $nombre, array $servicioIds): int
    {
        $repo = new ProfesionalRepository(self::$db);
        $profId = $repo->createWithServicios(
            new Profesional(['nombre' => $nombre, 'activo' => 1]),
            $servicioIds
        );

        $repo->replaceHorarios($profId, [
            new HorarioProfesional([
                'profesionalId' => $profId,
                'dia_semana' => 1, // Lunes
                'hora_inicio' => '09:00',
                'hora_fin' => '18:00',
            ])
        ]);

        return $profId;
    }

    private function proximoDiaSemanaIso(int $diaIso): string
    {
        $tz = new \DateTimeZone('America/Guayaquil');
        $cursor = new \DateTimeImmutable('tomorrow', $tz);
        while ((int)$cursor->format('N') !== $diaIso) {
            $cursor = $cursor->modify('+1 day');
        }
        return $cursor->format('Y-m-d');
    }

    private function ejecutarControladorCapturandoJson(callable $accion): array
    {
        ob_start();
        $code = 200;
        try {
            $accion();
            $code = http_response_code() ?: 200;
        } catch (AppTerminationException $e) {
            $code = $e->getStatusCode();
        } catch (\Classes\AppTerminationException $e) {
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

    public function testReservaConProfesionalYCancelacionEnvianCorreosDeConfirmacionYCancelacionYPreservanHistorialConSetNull(): void
    {
        $uid = $this->crearUsuarioCliente('Carlos', 'Mendoza', 'carlos@notificaciones.local');
        $s1 = $this->crearServicioCatalogo('Corte de Cabello Hombre', '80.00', 30);
        $s2 = $this->crearServicioCatalogo('Masaje Capilar Premium', '115.00', 60);
        $profId = $this->crearProfesionalConHorarioLunes('Valeria Castro', [$s1, $s2]);
        $lunesFuturo = $this->proximoDiaSemanaIso(1);

        $csrf = $this->autenticarCliente($uid);
        $citaRepo = new CitaRepository(self::$db);
        $servRepo = new ServicioRepository(self::$db);
        $notifRepo = new NotificacionCitaRepository(self::$db);
        $notifService = new NotificacionCitaService($notifRepo, $citaRepo);
        APIController::setCitaService(new CitaService($citaRepo, $servRepo, null, $notifService));

        // 1. Crear reserva en POST /api/citas
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/citas';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = [
            'csrf_token' => $csrf,
            'modo_reserva' => 'profesional',
            'profesionalId' => (string)$profId,
            'fecha' => $lunesFuturo,
            'hora' => '11:30',
            'servicios' => "{$s1},{$s2}",
        ];

        $resReserva = $this->ejecutarControladorCapturandoJson(fn() => APIController::guardar());
        $this->assertSame(200, $resReserva['httpCode']);
        $this->assertTrue($resReserva['json']['resultado']['resultado'] ?? false);
        $citaId = (int)$resReserva['json']['id'];
        $this->assertGreaterThan(0, $citaId);

        // Verificar correo de confirmación enviado
        $this->assertCount(1, Email::$emailsEnviados);
        $correoConf = Email::$emailsEnviados[0];
        $this->assertSame('cita_confirmacion', $correoConf['proposito']);
        $this->assertSame('confirmacion', $correoConf['tipo']);
        $this->assertSame('carlos@notificaciones.local', $correoConf['destinatario']);
        $this->assertSame("cita:{$citaId}:confirmacion", $correoConf['clave_idempotencia']);

        /** @var PHPMailer $mailerConf */
        $mailerConf = $correoConf['mailer'];
        $this->assertInstanceOf(PHPMailer::class, $mailerConf);
        $this->assertStringContainsString("Confirmación de Reserva #{$citaId}", $mailerConf->Body);
        $this->assertStringContainsString('Carlos Mendoza', $mailerConf->Body);
        $this->assertStringContainsString('Valeria Castro', $mailerConf->Body);
        $this->assertStringContainsString('11:30 - 13:00 (90 min)', $mailerConf->Body);
        $this->assertStringContainsString('Corte de Cabello Hombre (30 min)', $mailerConf->Body);
        $this->assertStringContainsString('Masaje Capilar Premium (60 min)', $mailerConf->Body);
        $this->assertStringContainsString('$195.00', $mailerConf->Body);

        // Verificar persistencia en notificaciones_citas
        $notifConfBd = $notifRepo->findByClaveIdempotencia("cita:{$citaId}:confirmacion");
        $this->assertNotNull($notifConfBd);
        $this->assertSame($citaId, $notifConfBd->cita_id);
        $this->assertSame($citaId, $notifConfBd->cita_referencia_id);
        $this->assertSame(NotificacionCita::ESTADO_ENVIADO, $notifConfBd->estado);
        $this->assertSame(1, $notifConfBd->intentos);
        $this->assertNotNull($notifConfBd->enviado_at);

        // 2. Cancelar la cita en POST /api/eliminar (modo JSON)
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/eliminar';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = [
            'csrf_token' => $csrf,
            'id' => (string)$citaId,
        ];

        $resElim = $this->ejecutarControladorCapturandoJson(fn() => APIController::eliminar());
        $this->assertSame(200, $resElim['httpCode']);
        $this->assertTrue($resElim['json']['resultado'] ?? false);

        // Verificar correo de cancelación enviado
        $this->assertCount(2, Email::$emailsEnviados);
        $correoCanc = Email::$emailsEnviados[1];
        $this->assertSame('cita_cancelacion', $correoCanc['proposito']);
        $this->assertSame('cancelacion', $correoCanc['tipo']);
        $this->assertSame('carlos@notificaciones.local', $correoCanc['destinatario']);
        $this->assertSame("cita:{$citaId}:cancelacion", $correoCanc['clave_idempotencia']);

        /** @var PHPMailer $mailerCanc */
        $mailerCanc = $correoCanc['mailer'];
        $this->assertInstanceOf(PHPMailer::class, $mailerCanc);
        $this->assertStringContainsString("Cita #{$citaId} Cancelada", $mailerCanc->Body);
        $this->assertStringContainsString('Valeria Castro', $mailerCanc->Body);
        $this->assertStringContainsString('11:30 - 13:00 (90 min)', $mailerCanc->Body);
        $this->assertStringContainsString('$195.00', $mailerCanc->Body);

        // Verificar que AMBOS registros ('confirmacion' y 'cancelacion') permanecen en notificaciones_citas
        // con su clave de idempotencia, estado 'enviado' y trazabilidad preservada
        $historialCita = $notifRepo->findByCitaReferenciaId($citaId);
        $this->assertCount(2, $historialCita);

        $this->assertSame('confirmacion', $historialCita[0]->tipo);
        $this->assertSame($citaId, $historialCita[0]->cita_referencia_id);
        $this->assertSame('enviado', $historialCita[0]->estado);

        $this->assertSame('cancelacion', $historialCita[1]->tipo);
        $this->assertSame($citaId, $historialCita[1]->cita_referencia_id);
        $this->assertSame('enviado', $historialCita[1]->estado);

        // La cita se cancela lógicamente y permanece registrada con estado 'cancelada'
        $citaEnBd = $citaRepo->findById($citaId);
        $this->assertNotNull($citaEnBd);
        $this->assertSame('cancelada', $citaEnBd->estado);
    }

    public function testFalloSmtpEnConfirmacionYEnCancelacionNuncaRevierteLaReservaNiLaEliminacionEnBaseDeDatos(): void
    {
        $uid = $this->crearUsuarioCliente('Lucía', 'Paredes', 'lucia@notificaciones.local');
        $s1 = $this->crearServicioCatalogo('Corte de Cabello Mujer', '120.00', 60);
        $profId = $this->crearProfesionalConHorarioLunes('Sofía Andrade', [$s1]);
        $lunesFuturo = $this->proximoDiaSemanaIso(1);

        // Simular caída total del servidor SMTP lanzando excepción
        Email::setTransport(function (): bool {
            throw new \RuntimeException('SMTP connection refused on 127.0.0.1:2525');
        });

        $csrf = $this->autenticarCliente($uid, 'Lucía Paredes');
        $citaRepo = new CitaRepository(self::$db);
        $servRepo = new ServicioRepository(self::$db);
        $notifRepo = new NotificacionCitaRepository(self::$db);
        $relojRef = new \DateTimeImmutable('2026-10-10 15:00:00', new \DateTimeZone('America/Guayaquil'));
        $notifService = new NotificacionCitaService($notifRepo, $citaRepo, $relojRef);
        APIController::setCitaService(new CitaService($citaRepo, $servRepo, $relojRef, $notifService));

        // 1. Reservar con SMTP caído -> la reserva DEBE confirmarse con HTTP 200
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/citas';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = [
            'csrf_token' => $csrf,
            'modo_reserva' => 'profesional',
            'profesionalId' => (string)$profId,
            'fecha' => $lunesFuturo,
            'hora' => '10:00',
            'servicios' => (string)$s1,
        ];

        $resReserva = $this->ejecutarControladorCapturandoJson(fn() => APIController::guardar());
        $this->assertSame(200, $resReserva['httpCode'], 'El fallo SMTP nunca debe revertir ni hacer fallar la reserva');
        $this->assertTrue($resReserva['json']['resultado']['resultado'] ?? false);
        $citaId = (int)$resReserva['json']['id'];
        $this->assertGreaterThan(0, $citaId);

        // La cita debe existir en la tabla `citas`
        $citaPersistida = $citaRepo->findById($citaId);
        $this->assertNotNull($citaPersistida, 'La cita debe estar confirmada en base de datos pese al fallo SMTP');
        $this->assertSame($lunesFuturo, $citaPersistida->fecha);

        // La notificación de confirmación debe quedar registrada en estado 'fallido' con intentos = 1 y proximo_intento_at
        $notifConf = $notifRepo->findByClaveIdempotencia("cita:{$citaId}:confirmacion");
        $this->assertNotNull($notifConf);
        $this->assertSame(NotificacionCita::ESTADO_FALLIDO, $notifConf->estado);
        $this->assertSame(1, $notifConf->intentos);
        $this->assertNotNull($notifConf->proximo_intento_at);
        $this->assertStringContainsString('SMTP connection refused', (string)$notifConf->ultimo_error);

        // 2. Cancelar la cita con SMTP caído -> la eliminación DEBE confirmarse con HTTP 200 y liberar el horario
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/eliminar';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST = [
            'csrf_token' => $csrf,
            'id' => (string)$citaId,
        ];

        $resElim = $this->ejecutarControladorCapturandoJson(fn() => APIController::eliminar());
        $this->assertSame(200, $resElim['httpCode'], 'El fallo SMTP nunca debe impedir ni revertir la cancelación');
        $this->assertTrue($resElim['json']['resultado'] ?? false);

        $citaCanceladaBd = $citaRepo->findById($citaId);
        $this->assertNotNull($citaCanceladaBd, 'La cita debe preservarse en base de datos');
        $this->assertSame('cancelada', $citaCanceladaBd->estado, 'La cita debe figurar en estado cancelada');

        $notifCanc = $notifRepo->findByClaveIdempotencia("cita:{$citaId}:cancelacion");
        $this->assertNotNull($notifCanc);
        $this->assertSame(NotificacionCita::ESTADO_FALLIDO, $notifCanc->estado);
        $this->assertSame(1, $notifCanc->intentos);
        $this->assertSame($citaId, $notifCanc->cita_referencia_id);

        // 3. Restaurar SMTP, avanzar el reloj 10 minutos para vencer el backoff y procesar reintentos
        Email::setTransport(function (): bool {
            return true;
        });
        Email::limpiarEmailsEnviados();

        $notifService->setAhoraReferencia($relojRef->modify('+10 minutes'));
        $resReintentos = $notifService->procesarPendientesYReintentos();

        $this->assertSame(NotificacionCitaService::STATUS_OK, $resReintentos['status']);
        // La cancelación se envía exitosamente (1). La confirmación pendiente se descarta (1 omitida) porque la cita ya está cancelada.
        $this->assertSame(1, $resReintentos['enviados']);
        $this->assertSame(1, $resReintentos['omitidos']);
        $this->assertSame(0, $resReintentos['fallidos']);
        $this->assertCount(1, Email::$emailsEnviados, 'Solo el correo de cancelación debe enviarse; la confirmación de la cita ya cancelada se descarta.');

        $notifConfTrasReintento = $notifRepo->findByClaveIdempotencia("cita:{$citaId}:confirmacion");
        $this->assertSame(NotificacionCita::ESTADO_DESCARTADA, $notifConfTrasReintento->estado);
        $this->assertStringContainsString('cita asociada está cancelada', (string)$notifConfTrasReintento->ultimo_error);

        $notifCancTrasReintento = $notifRepo->findByClaveIdempotencia("cita:{$citaId}:cancelacion");
        $this->assertSame(NotificacionCita::ESTADO_ENVIADO, $notifCancTrasReintento->estado);
        $this->assertSame(2, $notifCancTrasReintento->intentos);
        $this->assertNull($notifCancTrasReintento->ultimo_error);

        // 4. Segunda pasada de reintentos o invocación directa no debe volver a enviar ningún correo (idempotencia)
        $resSegundaPasada = $notifService->procesarPendientesYReintentos();
        $this->assertSame(0, $resSegundaPasada['enviados']);
        $this->assertCount(1, Email::$emailsEnviados, 'No deben enviarse correos duplicados en pasadas posteriores');

        $resInvocacionRepetida = $notifService->notificarCancelacionCitaDesdeSnapshot($notifCancTrasReintento->obtenerPayload(), false);
        $this->assertSame(NotificacionCitaService::STATUS_YA_ENVIADO, $resInvocacionRepetida['status']);
        $this->assertCount(1, Email::$emailsEnviados);
    }

    public function testRecordatoriosConfigurablesYScriptCliProcesarNotificacionesEnAmericaGuayaquil(): void
    {
        $uid = $this->crearUsuarioCliente('Elena', 'Ríos', 'elena@notificaciones.local');
        $s1 = $this->crearServicioCatalogo('Corte Clásico', '80.00', 30);
        $profId = $this->crearProfesionalConHorarioLunes('Sofía Andrade', [$s1]);
        $lunesFuturo = $this->proximoDiaSemanaIso(1);

        // Instante de referencia: domingo previo a las 10:00:00 en America/Guayaquil
        $tz = new \DateTimeZone('America/Guayaquil');
        $domingoPrevio = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lunesFuturo . ' 10:00:00', $tz)
            ->modify('-1 day');

        $citaRepo = new CitaRepository(self::$db);
        $servRepo = new ServicioRepository(self::$db);
        $notifRepo = new NotificacionCitaRepository(self::$db);
        $notifService = new NotificacionCitaService($notifRepo, $citaRepo, $domingoPrevio);
        $citaService = new CitaService($citaRepo, $servRepo, $domingoPrevio, $notifService);

        // Cita 1: Lunes 09:30 -> a 23.5 horas del domingo 10:00 (DENTRO de ventana de 24h)
        $r1 = $citaService->reservar($uid, [
            'profesionalId' => $profId,
            'fecha' => $lunesFuturo,
            'hora' => '09:30',
            'servicios' => (string)$s1,
        ]);
        $this->assertSame(200, $r1['httpCode']);
        $citaEnVentanaId = (int)$r1['id'];

        // Cita 2: Lunes 15:00 -> a 29 horas del domingo 10:00 (FUERA de ventana de 24h, DENTRO de 36h)
        $r2 = $citaService->reservar($uid, [
            'profesionalId' => $profId,
            'fecha' => $lunesFuturo,
            'hora' => '15:00',
            'servicios' => (string)$s1,
        ]);
        $this->assertSame(200, $r2['httpCode']);
        $citaFueraVentana24Id = (int)$r2['id'];

        // Cita 3: Lunes 10:00 -> dentro de ventana de 24h pero CANCELADA antes del recordatorio
        $r3 = $citaService->reservar($uid, [
            'profesionalId' => $profId,
            'fecha' => $lunesFuturo,
            'hora' => '10:00',
            'servicios' => (string)$s1,
        ]);
        $this->assertSame(200, $r3['httpCode']);
        $citaCanceladaId = (int)$r3['id'];
        $citaService->eliminar($citaCanceladaId, $uid, false);

        Email::limpiarEmailsEnviados();

        // 1. Procesar recordatorios con ventana de 24h desde el servicio
        $resRec24 = $notifService->procesarRecordatorios(24);
        $this->assertSame(NotificacionCitaService::STATUS_OK, $resRec24['status']);
        $this->assertSame(24, $resRec24['ventana_horas']);
        $this->assertSame(1, $resRec24['en_ventana']);
        $this->assertSame(1, $resRec24['enviados']);
        $this->assertCount(1, Email::$emailsEnviados);

        $correoRec = Email::$emailsEnviados[0];
        $this->assertSame('cita_recordatorio', $correoRec['proposito']);
        $this->assertSame("cita:{$citaEnVentanaId}:recordatorio:{$lunesFuturo}_09:30", $correoRec['clave_idempotencia']);

        // 2. Segunda ejecución con la misma ventana de 24h no debe reenviar el recordatorio (idempotencia)
        $resRec24Repetido = $notifService->procesarRecordatorios(24);
        $this->assertSame(1, $resRec24Repetido['en_ventana']);
        $this->assertSame(0, $resRec24Repetido['enviados']);
        $this->assertSame(1, $resRec24Repetido['ya_enviados']);
        $this->assertCount(1, Email::$emailsEnviados);

        // 3. Ejecutar el script CLI `scripts/procesar_notificaciones.php` con --horas=36 y --ahora
        $scriptPath = __DIR__ . '/../../scripts/procesar_notificaciones.php';
        $ahoraStr = $domingoPrevio->format('Y-m-d H:i:s');
        $cmd = sprintf(
            'php %s --horas=36 --ahora=%s --json --simular-envio',
            escapeshellarg($scriptPath),
            escapeshellarg($ahoraStr)
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge($_ENV, [
            'DB_HOST' => self::$host,
            'DB_USER' => self::$user,
            'DB_PASS' => self::$pass,
            'DB_NAME' => self::$dbName,
            'DB_PORT' => (string)self::$port,
            'EMAIL_HOST' => '',
        ]);

        $proc = proc_open($cmd, $descriptorSpec, $pipes, null, $env);
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(0, $exitCode, "El script CLI debe finalizar con código 0. STDERR: {$stderr}");
        $salidaJson = json_decode((string)$stdout, true);
        $this->assertIsArray($salidaJson);
        $this->assertSame('ok', $salidaJson['status']);
        $this->assertSame(36, $salidaJson['recordatorios']['ventana_horas']);
        $this->assertSame(2, $salidaJson['recordatorios']['en_ventana'], 'en_ventana check: ' . $stdout);
        $this->assertSame(1, $salidaJson['recordatorios']['enviados'], 'Solo la cita de las 15:00 debe enviarse como nueva en la ventana de 36h. STDOUT: ' . $stdout);
        $this->assertSame(1, $salidaJson['recordatorios']['ya_enviados'], 'La cita de las 09:30 debe contarse como ya enviada');

        // Verificar que la cita cancelada jamás recibió recordatorio
        $notifsCancelada = $notifRepo->findByCitaReferenciaId($citaCanceladaId);
        foreach ($notifsCancelada as $n) {
            $this->assertNotSame(NotificacionCita::TIPO_RECORDATORIO, $n->tipo);
        }

        // Verificar que la cita de las 15:00 quedó registrada como recordatorio enviado en BD
        $notifRec15 = $notifRepo->findByClaveIdempotencia("cita:{$citaFueraVentana24Id}:recordatorio:{$lunesFuturo}_15:00");
        $this->assertNotNull($notifRec15);
        $this->assertSame(NotificacionCita::ESTADO_ENVIADO, $notifRec15->estado);
    }

    /**
     * Prueba de Outbox en Flujo Clásico:
     * Comprueba que CitaRepository::crearReservaAtomica inserte el evento en outbox
     * dentro de la misma transacción antes del commit.
     */
    public function testFlujoClasicoDeReservaRegistraEventoOutboxEnMismaTransaccion(): void
    {
        $usuarioId = $this->crearUsuarioCliente('Clasico', 'Usuario', 'clasico_outbox@test.com');
        $servicioId = $this->crearServicioCatalogo('Corte Clasico Outbox', '25.00', 30);

        $cita = new \Model\Cita([
            'fecha' => '2026-11-10',
            'hora' => '10:00:00',
            'usuarioId' => $usuarioId,
            'estado' => 'confirmada',
        ]);

        $citaRepo = new CitaRepository(self::$db);
        $notifRepo = new NotificacionCitaRepository(self::$db);

        $idCitaCreada = $citaRepo->crearReservaAtomica($cita, [$servicioId]);
        $this->assertGreaterThan(0, $idCitaCreada);

        // Verificar que el evento de confirmación en outbox fue insertado
        $eventoOutbox = $notifRepo->findByClaveIdempotencia("cita:{$idCitaCreada}:confirmacion");
        $this->assertNotNull($eventoOutbox, 'El flujo clásico debe registrar atómicamente el evento outbox en la transacción');
        $this->assertSame(NotificacionCita::TIPO_CONFIRMACION, $eventoOutbox->tipo);
        $this->assertSame('clasico_outbox@test.com', $eventoOutbox->destinatario_email);
        $this->assertSame(NotificacionCita::ESTADO_PENDIENTE, $eventoOutbox->estado);
    }
}
