<?php
namespace Tests\Integration;

require_once __DIR__ . '/BaseDatosAislada.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\ServicioRepository;
use Services\CitaService;

final class AgendaOperativaIntegrationTest extends TestCase
{
    private static string $base;
    private static ?\mysqli $admin = null;
    private \mysqli $db;
    private ?\mysqli $previa = null;
    private CitaService $service;
    private CitaRepository $repo;
    private array $usuarios = [];
    private array $citas = [];
    private int $prof;
    private int $servicio;
    public static function setUpBeforeClass(): void
    {
        self::$admin = BaseDatosAislada::crear('agenda');
        self::$base = BaseDatosAislada::nombre(self::$admin);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin) { BaseDatosAislada::destruir(self::$admin); self::$admin = null; }
    }

    protected function setUp(): void
    {
        $this->previa = ActiveRecord::getDB();
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', self::$base, (int)(getenv('DB_PORT') ?: 3306));
        validar_base_datos_prueba($this->db);
        $this->db->set_charset('utf8mb4'); ActiveRecord::setDB($this->db);
        for ($i = 0; $i < 3; $i++) {
            $email = 'agenda-' . bin2hex(random_bytes(8)) . '@test.local';
            $stmt = $this->db->prepare("INSERT INTO usuarios (nombre, apellido, email, password, confirmado) VALUES ('Agenda', 'Prueba', ?, 'hash', 1)");
            $stmt->bind_param('s', $email); $this->assertTrue($stmt->execute()); $this->usuarios[] = $this->db->insert_id; $stmt->close();
        }
        $this->db->query("INSERT INTO servicios (nombre, precio, duracion_minutos) VALUES ('Agenda fixture', 35, 30)"); $this->servicio = $this->db->insert_id;
        $this->db->query("INSERT INTO profesionales (nombre, activo, usuarioId) VALUES ('Agenda fixture', 1, {$this->usuarios[2]})"); $this->prof = $this->db->insert_id;
        foreach ([1,2] as $dia) $this->db->query("INSERT INTO horarios_profesionales (profesionalId, dia_semana, hora_inicio, hora_fin) VALUES ({$this->prof}, $dia, '10:00', '18:00')");
        $this->db->query("INSERT INTO profesionales_servicios (profesionalId, servicioId) VALUES ({$this->prof}, {$this->servicio})");
        $this->repo = new CitaRepository($this->db);
        $this->service = new CitaService($this->repo, new ServicioRepository($this->db), new \DateTimeImmutable('2030-01-07 12:00:00', new \DateTimeZone('America/Guayaquil')));
    }
    private function cita(string $fecha = '2030-01-08', string $hora = '11:00', string $estado = 'confirmada'): int
    {
        $fin = date('H:i', strtotime($hora) + 1800);
        $sql = "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado, estado_pago) VALUES (?, ?, ?, ?, 30, ?, ?, ?, 'pagado')";
        $stmt = $this->db->prepare($sql); $u = $this->usuarios[0]; $p = $this->prof; $stmt->bind_param('ssssiis', $fecha, $hora, $hora, $fin, $u, $p, $estado); $this->assertTrue($stmt->execute()); $id = $this->db->insert_id; $stmt->close(); $this->citas[] = $id;
        $this->assertTrue($this->db->query("INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos) VALUES ($id, {$this->servicio}, 'Precio histórico', 20, 30)"));
        return $id;
    }
    protected function tearDown(): void
    {
        foreach ($this->citas as $id) { $this->db->query("DELETE FROM citasservicios WHERE citaId = $id"); $this->db->query("DELETE FROM citas WHERE id = $id"); }
        $this->db->query("DELETE FROM profesionales WHERE id = {$this->prof}");
        $this->db->query("DELETE FROM servicios WHERE id = {$this->servicio}");
        foreach ($this->usuarios as $id) $this->db->query("DELETE FROM usuarios WHERE id = $id");
        ActiveRecord::setDB($this->previa);
        $this->db->close();
    }
    public function testReprogramarPreservaPrecioEstadoPagoYIdRechazaSolapamientoYAjeno(): void
    {
        $id = $this->cita(); $this->cita('2030-01-08', '14:00');
        $datos = ['fecha' => '2030-01-08', 'hora' => '14:15'];
        $this->assertSame(403, $this->service->actualizarAgenda($id, $this->usuarios[1], false, false, 'reprogramar', $datos)['httpCode']);
        $this->assertSame(409, $this->service->actualizarAgenda($id, $this->usuarios[0], false, false, 'reprogramar', $datos)['httpCode']);
        $this->assertSame('11:00:00', $this->repo->findById($id)->hora);
        $datos['hora'] = '15:00';
        $this->assertTrue($this->service->actualizarAgenda($id, $this->usuarios[0], false, false, 'reprogramar', $datos)['resultado']);
        $this->assertSame('15:00:00', $this->repo->findById($id)->hora);
        $this->assertSame('pagado', $this->repo->findById($id)->estado_pago);
        $this->assertSame('20.00', $this->db->query("SELECT precio_servicio FROM citasservicios WHERE citaId = $id")->fetch_assoc()['precio_servicio']);
        $this->assertSame('1', $this->db->query("SELECT COUNT(*) n FROM agenda_eventos WHERE citaId = $id")->fetch_assoc()['n']);
        $this->assertSame('1', $this->db->query("SELECT COUNT(*) n FROM notificaciones_citas WHERE cita_id = $id AND asunto LIKE 'Nuevo horario%'")->fetch_assoc()['n']);
        $this->assertTrue($this->service->actualizarAgenda($id, $this->usuarios[0], false, false, 'reprogramar', $datos)['resultado']);
        $this->assertSame('1', $this->db->query("SELECT COUNT(*) n FROM agenda_eventos WHERE citaId = $id")->fetch_assoc()['n']);
    }
    public function testTransicionesProfesionalPropioEIdempotencia(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $this->assertSame(403, $this->service->actualizarAgenda($id, $this->usuarios[1], false, true, 'estado', ['estado' => 'en_servicio'])['httpCode']);
        $this->assertSame(409, $this->service->actualizarAgenda($id, $this->usuarios[2], false, true, 'estado', ['estado' => 'completada'])['httpCode']);
        foreach (['en_servicio', 'completada', 'completada'] as $estado) $this->assertTrue($this->service->actualizarAgenda($id, $this->usuarios[2], false, true, 'estado', ['estado' => $estado])['resultado']);
        $this->assertSame('completada', $this->repo->findById($id)->estado);
        $this->assertSame('2', $this->db->query("SELECT COUNT(*) n FROM agenda_eventos WHERE citaId = $id")->fetch_assoc()['n']);
        $this->assertSame(409, $this->service->actualizarAgenda($id, $this->usuarios[2], false, true, 'estado', ['estado' => 'no_asistio'])['httpCode']);
        $this->assertCount(1, $this->repo->findOcupacionByProfesionalEnFecha($this->prof, '2030-01-07'));
    }
    public function testHistorialIncluyeCanceladasYNoOfreceCancelarHoraPasada(): void
    {
        $this->cita('2030-01-07', '11:00'); $this->cita('2030-01-08', '11:00', 'cancelada');
        $list = $this->service->consultarCitasCliente($this->usuarios[0], false);
        $this->assertCount(2, $list['citas']);
        foreach ($list['citas'] as $cita) $this->assertFalse($cita['puede_cancelar']);
    }
    public function testAtencionFuturaYFormatoInvalidoNoMutanYListadoAplicaAlcance(): void
    {
        $id = $this->cita();
        $this->assertSame(422, $this->service->actualizarAgenda($id, $this->usuarios[2], false, true, 'estado', ['estado' => 'en_servicio'])['httpCode']);
        $this->assertSame(422, $this->service->actualizarAgenda($id, $this->usuarios[0], false, false, 'reprogramar', ['fecha' => '2030-02-30', 'hora' => '11:00'])['httpCode']);
        $this->assertSame('confirmada', $this->repo->findById($id)->estado);
        $this->assertCount(1, $this->repo->listarAgenda('2030-01-07', '2030-01-08', $this->usuarios[2]));
        $this->assertCount(0, $this->repo->listarAgenda('2030-01-07', '2030-01-08', $this->usuarios[1]));
    }
    public function testReprogramacionRespetaTurnoYEstadosTerminalesNoReabren(): void
    {
        $id = $this->cita();
        foreach (['09:30', '17:45'] as $hora) {
            $this->assertSame(409, $this->service->actualizarAgenda($id, $this->usuarios[0], false, false, 'reprogramar', ['fecha' => '2030-01-08', 'hora' => $hora])['httpCode']);
        }
        $this->assertSame('11:00:00', $this->repo->findById($id)->hora);
        $ausente = $this->cita('2030-01-07', '11:00');
        $this->assertTrue($this->service->actualizarAgenda($ausente, $this->usuarios[2], false, true, 'estado', ['estado' => 'no_asistio'])['resultado']);
        $this->assertCount(0, $this->repo->findOcupacionByProfesionalEnFecha($this->prof, '2030-01-07'));
        $this->assertSame(409, $this->service->actualizarAgenda($ausente, $this->usuarios[0], false, false, 'reprogramar', ['fecha' => '2030-01-08', 'hora' => '15:00'])['httpCode']);
    }

    public function testFalloAuditoriaRevierteReprogramacion(): void
    {
        $id = $this->cita();
        $this->assertTrue($this->db->query("CREATE TRIGGER agenda_test_failure BEFORE INSERT ON agenda_eventos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated failure'"));
        try {
            $result = $this->service->actualizarAgenda($id, $this->usuarios[0], false, false, 'reprogramar', ['fecha' => '2030-01-08', 'hora' => '15:00']);
            $this->assertSame(500, $result['httpCode']);
            $this->assertSame('11:00:00', $this->repo->findById($id)->hora);
        } finally { $this->db->query('DROP TRIGGER IF EXISTS agenda_test_failure'); }
    }
}
