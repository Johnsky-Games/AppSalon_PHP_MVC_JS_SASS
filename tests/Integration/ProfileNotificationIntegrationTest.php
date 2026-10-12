<?php
namespace Tests\Integration;

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\NotificacionCitaRepository;
use Services\NotificacionCitaService;

final class ProfileNotificationIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $user;
    private int $cita;
    private NotificacionCitaRepository $repo;
    private NotificacionCitaService $service;
    protected function setUp(): void
    {
        $this->db=ActiveRecord::getDB(); validar_base_datos_prueba($this->db);
        $this->db->query('DELETE FROM notificaciones_citas');
        $email='notif-' . bin2hex(random_bytes(5)) . '@test.local';
        $stmt=$this->db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Perfil','Correo',?,'hash','0991234567',0,1)"); $stmt->bind_param('s',$email);$stmt->execute();$stmt->close();$this->user=(int)$this->db->insert_id;
        $stmt=$this->db->prepare("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,estado) VALUES ('2030-01-02','11:00:00','11:00:00','11:30:00',30,?,'confirmada')");$stmt->bind_param('i',$this->user);$stmt->execute();$stmt->close();$this->cita=(int)$this->db->insert_id;
        $this->repo=new NotificacionCitaRepository($this->db);
        $this->service=new NotificacionCitaService($this->repo,new CitaRepository($this->db),new \DateTimeImmutable('2030-01-01 12:00:00',new \DateTimeZone('America/Guayaquil')),static fn()=>true);
    }
    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM notificaciones_citas WHERE cita_referencia_id=' . $this->cita);
        $this->db->query('DELETE FROM citas WHERE id=' . $this->cita);
        $this->db->query('DELETE FROM usuarios WHERE id=' . $this->user);
    }
    private function evento(string $tipo,string $fecha='2030-01-02',string $hora='11:00:00'): int
    {
        $evento=$this->service->registrarEventoOutbox(['cita_id'=>$this->cita,'cita_referencia_id'=>$this->cita,'usuario_id'=>$this->user,'tipo'=>$tipo,'clave_idempotencia'=>bin2hex(random_bytes(16)),'destinatario_email'=>'perfil@test.local','destinatario_nombre'=>'Perfil Correo','asunto'=>'Evento perfil','payload_json'=>['cita_id'=>$this->cita,'fecha'=>$fecha,'hora_inicio'=>$hora]]);
        return (int)$evento->id;
    }
    public function testOptoutEvitaGeneracionYDescartaRecordatorioYaEnColaPeroNoConfirmacion(): void
    {
        $this->db->query('INSERT INTO perfiles_usuario(usuarioId,recordatorios) VALUES (' . $this->user . ',0)');
        $resultado=$this->service->procesarRecordatorios(24); self::assertSame(0,$resultado['enviados']);
        $recordatorio=$this->evento('recordatorio');$confirmacion=$this->evento('confirmacion');
        $this->service->procesarPendientesYReintentos();
        self::assertSame('descartada',$this->repo->findById($recordatorio)->estado);
        self::assertSame('recordatorios_desactivados',$this->repo->findById($recordatorio)->ultimo_error);
        self::assertSame('enviado',$this->repo->findById($confirmacion)->estado);
    }
    public function testHorarioAntiguoNoSeDespachaTrasReprogramacionYNuevoSi(): void
    {
        $anterior=$this->evento('recordatorio');$anteriorConfirmacion=$this->evento('confirmacion');
        $this->db->query("UPDATE citas SET hora='12:00:00',hora_inicio='12:00:00',hora_fin='12:30:00' WHERE id=" . $this->cita);
        $nuevo=$this->evento('confirmacion','2030-01-02','12:00:00');
        $this->service->procesarPendientesYReintentos();
        self::assertSame('descartada',$this->repo->findById($anterior)->estado);
        self::assertSame('horario_reprogramado',$this->repo->findById($anterior)->ultimo_error);
        self::assertSame('descartada',$this->repo->findById($anteriorConfirmacion)->estado);
        self::assertSame('enviado',$this->repo->findById($nuevo)->estado);
    }
    public function testCitaCompletadaDescartaRecordatorios(): void
    {
        $id=$this->evento('recordatorio');$this->db->query("UPDATE citas SET estado='completada' WHERE id=" . $this->cita);
        $this->service->procesarPendientesYReintentos();
        self::assertSame('descartada',$this->repo->findById($id)->estado);
        self::assertSame('cita_finalizada',$this->repo->findById($id)->ultimo_error);
    }
}
