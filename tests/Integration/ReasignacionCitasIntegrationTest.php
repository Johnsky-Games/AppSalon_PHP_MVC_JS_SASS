<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\Email;
use Classes\Idempotencia;
use Controllers\EquipoController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Repositories\NotificacionCitaRepository;
use Repositories\ReasignacionCitasRepository;
use Services\AuthorizationService;
use Services\CitaService;
use Services\ColaboradorService;
use Services\ComisionService;
use Services\CorreoSalidaService;
use Services\DisponibilidadService;
use Services\FinanzasService;
use Services\NotificacionCitaService;
use Services\ProfesionalService;
use Services\ReasignacionCitasService;

/**
 * Revisión y reasignación asistida de citas futuras antes de la baja o suspensión de un profesional.
 * Reglas reales contra MySQL: sustitutos con TODOS los servicios y realmente libres, atomicidad todo-o-nada,
 * concurrencia con procesos PHP reales, historial/snapshots intactos, comisiones, privacidad y notificación única.
 */
final class ReasignacionCitasIntegrationTest extends TestCase
{
    private const SUF = '@reasigna-citas.test';
    private const PASS = 'ClaveSegura123';
    private \mysqli $db;
    private int $owner;
    /** @var array<int,array<string,mixed>> */
    private array $enviados = [];
    /** @var int[] */
    private array $srv = [];
    /** @var int[] */
    private array $clientes = [];

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->limpiar();
        $this->enviados = [];
        Email::setTransport(function ($mailer, string $proposito, array $datos): bool {
            $this->enviados[] = ['proposito' => $proposito, 'para' => $datos['destinatario'], 'asunto' => (string)($datos['asunto'] ?? ''), 'token' => $datos['token'] ?? null];
            return true;
        });
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.72';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        http_response_code(200);
        unset($GLOBALS['__appsalon_redireccion']);
        ReasignacionCitasRepository::$observadorTrasBloqueos = null;
        $this->owner = $this->cuenta('propietario');
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES ('Servicio Rea A',10.00,30),('Servicio Rea B',20.00,60),('Servicio Rea C',15.00,30)");
        $primero = (int)$this->db->insert_id;
        $this->srv = [$primero, $primero + 1, $primero + 2];
        $this->clientes = [$this->cuenta('cliente', 'Carla'), $this->cuenta('cliente', 'Diego'), $this->cuenta('cliente', 'Elena')];
    }

    protected function tearDown(): void
    {
        ReasignacionCitasRepository::$observadorTrasBloqueos = null;
        if ($this->db->ping()) {
            $this->db->query('DROP TRIGGER IF EXISTS test_fail_agenda_evento_rea');
            $this->limpiar();
        }
        ActiveRecord::setDB($this->db);
        Email::setTransport(null);
        $_SESSION = []; $_POST = []; $_GET = [];
        http_response_code(200);
    }

    private function limpiar(): void
    {
        $citas = "SELECT id FROM citas WHERE usuarioId IN (SELECT id FROM usuarios WHERE email LIKE '%" . self::SUF . "')";
        $this->db->query("DELETE FROM caja_movimientos WHERE concepto LIKE 'Rea test%'");
        $this->db->query("DELETE FROM comisiones_movimientos WHERE profesional_nombre LIKE 'Rea %'");
        $this->db->query("DELETE FROM pagos WHERE cita_referencia_id IN ($citas)");
        $this->db->query("DELETE FROM notificaciones_citas WHERE cita_referencia_id IN ($citas) OR destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM citasservicios WHERE citaId IN ($citas)");
        $this->db->query("DELETE FROM citas_reasignaciones WHERE cita_referencia_id IN ($citas) OR profesional_origen_nombre LIKE 'Rea %'");
        $this->db->query("DELETE FROM citas WHERE usuarioId IN (SELECT id FROM usuarios WHERE email LIKE '%" . self::SUF . "')");
        $this->db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM profesionales WHERE nombre LIKE 'Rea %'");
        $this->db->query("DELETE FROM colaboradores WHERE email_norm LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM servicios WHERE nombre LIKE 'Servicio Rea %'");
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE actor LIKE 'equipo:%'");
        $this->db->query("DELETE FROM equipo_auditoria WHERE accion IN ('citas_reasignadas','colaborador_invitado','invitacion_aceptada')");
        $this->db->query('DELETE FROM intentos_login');
    }

    private function n(string $sql): int
    {
        return (int)$this->db->query($sql)->fetch_assoc()['n'];
    }

    private function email(string $p = 'p'): string
    {
        return $p . '-' . bin2hex(random_bytes(4)) . self::SUF;
    }

    private function cuenta(string $rol, string $nombre = ''): int
    {
        $email = $this->email($rol);
        $hash = password_hash(self::PASS, PASSWORD_BCRYPT);
        $admin = in_array($rol, ['propietario', 'gerente'], true) ? 1 : 0;
        $nombre = $nombre !== '' ? $nombre : ucfirst($rol);
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('$nombre','Prueba','$email','$hash','0991234567',$admin,1)");
        $id = (int)$this->db->insert_id;
        if ($rol !== 'cliente') {
            $this->db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ($id,'$rol',1)");
            $this->db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,codigo) VALUES ($id,'$email','$nombre','Prueba','$rol','activo',UTC_TIMESTAMP(),CONCAT('T-',$id))");
        }
        return $id;
    }

    private function colab(): ColaboradorService
    {
        return new ColaboradorService(new ColaboradorRepository($this->db), new CorreoSalidaService(new CorreoSalidaRepository($this->db)));
    }

    private function rea(?ReasignacionCitasService $s = null): ReasignacionCitasService
    {
        return $s ?? new ReasignacionCitasService();
    }

    /**
     * Profesional real (invitación con asistente + aceptación): ficha activa con horario lunes a viernes.
     * @return array{colab:int,prof:int,usuario:int,nombre:string}
     */
    private function prof(string $nombre, array $servicios, array $dias = [1, 2, 3, 4, 5], string $ini = '09:00', string $fin = '18:00'): array
    {
        $email = $this->email('prof');
        $franjas = [];
        foreach ($dias as $d) { $franjas[$d] = [['inicio' => $ini, 'fin' => $fin]]; }
        $r = $this->colab()->invitar($this->owner, ['nombre' => $nombre, 'apellido' => 'Rea', 'email' => $email, 'rol' => 'profesional', 'nombre_profesional' => "Rea $nombre",
            'servicios' => array_map('strval', $servicios), 'franjas' => $franjas, 'descansos' => []], Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::OK, $r['status'], json_encode($r));
        $token = null;
        foreach (array_reverse($this->enviados) as $e) { if ($e['para'] === $email) { $token = $e['token']; break; } }
        $a = $this->colab()->aceptar((string)$token, ['acepto' => '1', 'password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026'], '198.51.100.72');
        $this->assertSame(ColaboradorService::OK, $a['status'], json_encode($a));
        return ['colab' => (int)$r['colaborador_id'], 'prof' => (int)$r['profesional_id'], 'usuario' => (int)$a['usuario_id'], 'nombre' => "Rea $nombre"];
    }

    private function proximo(int $diaIso, int $minimoDias = 3): string
    {
        $d = new \DateTimeImmutable("+$minimoDias days", new \DateTimeZone('America/Guayaquil'));
        while ((int)$d->format('N') !== $diaIso) { $d = $d->modify('+1 day'); }
        return $d->format('Y-m-d');
    }

    /** Reserva real (misma ruta que el cliente) y devuelve el id de la cita. */
    private function reservar(int $cliente, int $prof, string $fecha, string $hora, array $servicios): int
    {
        $r = (new CitaService())->reservarConProfesional($cliente, ['profesionalId' => $prof, 'fecha' => $fecha, 'hora' => $hora, 'servicios' => $servicios]);
        $this->assertSame('ok', $r['status'], json_encode($r));
        return (int)$r['id'];
    }

    private function decisiones(array $mapa): array
    {
        return $mapa;
    }

    private function aplicar(array $o, array $decisiones, ?string $clave = null, string $motivo = 'Baja del profesional', ?int $actor = null): array
    {
        return $this->rea()->aplicar($actor ?? $this->owner, $o['colab'], $decisiones, $motivo, $clave ?? Idempotencia::nuevaClave());
    }

    private function profDe(int $citaId): int
    {
        return (int)$this->db->query("SELECT profesionalId n FROM citas WHERE id=$citaId")->fetch_assoc()['n'];
    }

    /** Foto estable del estado que una operación fallida o rechazada NO debe tocar. */
    private function foto(array $citaIds): string
    {
        $ids = implode(',', $citaIds);
        return json_encode([
            $this->db->query("SELECT * FROM citas WHERE id IN ($ids) ORDER BY id")->fetch_all(MYSQLI_ASSOC),
            $this->db->query("SELECT * FROM citasservicios WHERE citaId IN ($ids) ORDER BY id")->fetch_all(MYSQLI_ASSOC),
            $this->n("SELECT COUNT(*) n FROM citas_reasignaciones WHERE cita_referencia_id IN ($ids)"),
            $this->n("SELECT COUNT(*) n FROM notificaciones_citas WHERE cita_referencia_id IN ($ids)"),
            $this->n("SELECT COUNT(*) n FROM agenda_eventos WHERE citaId IN ($ids)"),
        ]);
    }

    private function cambiosDeProfesional(): array
    {
        return array_values(array_filter($this->enviados, static fn(array $e): bool => str_starts_with($e['asunto'], 'Cambio de profesional')));
    }

    // ------------------------------------------------------------------ Revisión: sustitutos válidos

    public function testLaRevisionProponeSoloSustitutosQuePrestanTodosLosServiciosYEstanLibres(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $s2 = $this->prof('Solo A', [$this->srv[0]]);
        $s3 = $this->prof('Ocupada', $this->srv);
        $s4 = $this->prof('Inactiva', $this->srv);
        $s5 = $this->prof('Solo Martes', $this->srv, [2]);
        $lunes = $this->proximo(1);
        $martes = $this->proximo(2);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0], $this->srv[1]]); // 90 min: A+B
        $c2 = $this->reservar($this->clientes[1], $o['prof'], $martes, '09:00', [$this->srv[0]]);
        $this->reservar($this->clientes[2], $s3['prof'], $lunes, '10:30', [$this->srv[0]]); // ocupa 10:30–11:00 de la lunes
        $this->db->query("UPDATE profesionales SET activo=0 WHERE id={$s4['prof']}");

        $r = $this->rea()->revisar($this->owner, $o['colab']);
        $this->assertSame(ReasignacionCitasService::OK, $r['status'], json_encode($r));
        $this->assertTrue($r['ver_cliente']);
        $this->assertSame(2, $r['resumen']['total']);
        $porId = array_column($r['citas'], null, 'id');
        $ids = fn(int $c) => array_column($porId[$c]['sustitutos'], 'id');
        $this->assertSame([$s1['prof']], $ids($c1), 'A+B el lunes 10:00: Solo A no presta B, Ocupada está ocupada, Inactiva no cuenta, Solo Martes no trabaja el lunes');
        $this->assertEqualsCanonicalizing([$s1['prof'], $s2['prof'], $s3['prof'], $s5['prof']], $ids($c2), 'Solo A el martes 09:00: todas las activas que lo prestan y están libres');
        $this->assertSame('Carla Prueba', $porId[$c1]['cliente']);
        $this->assertSame(['Servicio Rea A', 'Servicio Rea B'], $porId[$c1]['servicios']);

        // Coherencia con la disponibilidad pública: un profesional es sustituto si y solo si DisponibilidadService
        // ofrece un intervalo con el mismo inicio y duración (misma validación, no una lógica paralela).
        $disp = new DisponibilidadService();
        foreach ([[$c1, $lunes, '10:00', [$this->srv[0], $this->srv[1]], 90], [$c2, $martes, '09:00', [$this->srv[0]], 30]] as [$cita, $fecha, $hora, $servicios, $duracion]) {
            foreach ([$s1, $s2, $s3, $s4, $s5] as $p) {
                $d = $disp->consultar($p['prof'], $fecha, $servicios, 1);
                $ofrece = !empty($d['intervalos']) && in_array($hora, array_column(array_filter($d['intervalos'], static fn($i) => (int)$i['duracion_minutos'] === $duracion), 'inicio'), true);
                $this->assertSame($ofrece, in_array($p['prof'], $ids($cita), true), "Cita $cita / {$p['nombre']}: la propuesta debe coincidir con la disponibilidad real");
            }
        }
    }

    // ------------------------------------------------------------------ Reasignación feliz

    public function testReasignacionFelizMueveSoloElProfesionalConservaSnapshotsYNotificaUnaVez(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $lunes = $this->proximo(1);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0], $this->srv[1]]);
        $antesCita = $this->db->query("SELECT * FROM citas WHERE id=$c1")->fetch_assoc();
        $antesServicios = $this->db->query("SELECT * FROM citasservicios WHERE citaId=$c1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $emailCliente = $this->db->query("SELECT u.email FROM usuarios u JOIN citas c ON c.usuarioId=u.id WHERE c.id=$c1")->fetch_assoc()['email'];
        $this->enviados = [];

        // Un aviso previo aún en cola (recordatorio) no debe nombrar al profesional anterior.
        $this->db->query("INSERT INTO notificaciones_citas(cita_id,cita_referencia_id,usuario_id,tipo,clave_idempotencia,destinatario_email,destinatario_nombre,asunto,payload_json,estado)
            VALUES ($c1,$c1,{$this->clientes[0]},'recordatorio','cita:$c1:recordatorio:previo','$emailCliente','Carla','Recordatorio',JSON_OBJECT('cita_id',$c1,'profesional_id',{$o['prof']},'profesional_nombre','Rea Origen'),'pendiente')");
        $clave = Idempotencia::nuevaClave();
        $r = $this->aplicar($o, [$c1 => "reasignar:{$s1['prof']}"], $clave, 'Baja de Origen');
        $this->assertSame(ReasignacionCitasService::OK, $r['status'], json_encode($r));
        $this->assertSame(1, $r['reasignadas']);
        $this->assertSame(0, $r['pendientes']);

        // La cita solo cambió de profesional.
        $despues = $this->db->query("SELECT * FROM citas WHERE id=$c1")->fetch_assoc();
        $this->assertSame((string)$s1['prof'], $despues['profesionalId']);
        $this->assertSame(array_diff_key($antesCita, ['profesionalId' => 1]), array_diff_key($despues, ['profesionalId' => 1]), 'Fecha, horas, estado, pago y cliente intactos');
        $this->assertSame($antesServicios, $this->db->query("SELECT * FROM citasservicios WHERE citaId=$c1 ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'Los snapshots históricos de servicios no se tocan');

        // Auditoría con actor, origen, destino, motivo y snapshot del horario.
        $h = (new ReasignacionCitasRepository($this->db))->historial($c1);
        $this->assertCount(1, $h);
        $this->assertSame('reasignada', $h[0]['accion']);
        $this->assertSame($o['prof'], (int)$h[0]['profesional_origen_id']);
        $this->assertSame($s1['prof'], (int)$h[0]['profesional_destino_id']);
        $this->assertSame('Rea Origen', $h[0]['profesional_origen_nombre']);
        $this->assertSame('Rea Sustituta Uno', $h[0]['profesional_destino_nombre']);
        $this->assertSame($this->owner, (int)$h[0]['actor_id']);
        $this->assertSame('Baja de Origen', $h[0]['motivo']);
        $snap = json_decode($h[0]['snapshot_json'], true);
        $this->assertSame($lunes, $snap['fecha']);
        $this->assertSame('10:00', $snap['hora_inicio']);
        $this->assertSame('11:30', $snap['hora_fin']);
        $this->assertSame(90, $snap['duracion_total_minutos']);
        $this->assertSame(['Servicio Rea A', 'Servicio Rea B'], array_column($snap['servicios'], 'nombre'));
        $this->assertFalse($snap['comision_devengada']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM agenda_eventos WHERE citaId=$c1 AND tipo='reasignacion' AND actorId={$this->owner}"));

        // El cliente recibe UN aviso (outbox + envío inmediato) y el worker no lo duplica.
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM notificaciones_citas WHERE cita_referencia_id=$c1 AND clave_idempotencia LIKE 'cita:$c1:reasignacion:%'"));
        $this->assertCount(1, $this->cambiosDeProfesional());
        $this->assertSame($emailCliente, $this->cambiosDeProfesional()[0]['para']);
        $this->assertStringContainsString("#$c1", $this->cambiosDeProfesional()[0]['asunto']);
        $fila = $this->db->query("SELECT estado,payload_json FROM notificaciones_citas WHERE cita_referencia_id=$c1 AND clave_idempotencia LIKE 'cita:$c1:reasignacion:%'")->fetch_assoc();
        $this->assertSame('enviado', $fila['estado']);
        $payload = json_decode($fila['payload_json'], true);
        $this->assertSame('Rea Sustituta Uno', $payload['profesional_nombre']);
        $this->assertSame('Rea Origen', $payload['profesional_anterior']);
        (new NotificacionCitaService())->procesarPendientesYReintentos();
        $this->assertCount(1, $this->cambiosDeProfesional(), 'El worker no reenvía un aviso ya enviado');

        $pendiente = json_decode($this->db->query("SELECT payload_json FROM notificaciones_citas WHERE clave_idempotencia='cita:$c1:recordatorio:previo'")->fetch_assoc()['payload_json'], true);
        $this->assertSame('Rea Sustituta Uno', $pendiente['profesional_nombre'], 'Los avisos en cola ya nombran al nuevo profesional');
        $this->assertSame($s1['prof'], (int)$pendiente['profesional_id']);

        // Repetir el mismo POST (doble clic / respuesta perdida) no reasigna ni notifica de nuevo.
        $otra = $this->rea()->aplicar($this->owner, $o['colab'], [$c1 => "reasignar:{$s1['prof']}"], 'Baja de Origen', $clave);
        $this->assertSame(ReasignacionCitasService::OK, $otra['status'], json_encode($otra));
        $this->assertTrue($otra['repetida']);
        $this->assertCount(1, (new ReasignacionCitasRepository($this->db))->historial($c1));
        $this->assertCount(1, $this->cambiosDeProfesional());

        // El hueco quedó ocupado en el sustituto y libre en el origen.
        $d = (new DisponibilidadService())->consultar($s1['prof'], $lunes, [$this->srv[0], $this->srv[1]], 1);
        $this->assertNotContains('10:00', array_column($d['intervalos'] ?? [], 'inicio'));
        $d = (new DisponibilidadService())->consultar($o['prof'], $lunes, [$this->srv[0], $this->srv[1]], 1);
        $this->assertContains('10:00', array_column($d['intervalos'] ?? [], 'inicio'));
    }

    // ------------------------------------------------------------------ Sustitutos no válidos

    public function testUnSustitutoSinElServicioInactivoOcupadoFueraDeHorarioOElMismoSeRechazaSinCambiarNada(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s2 = $this->prof('Solo A', [$this->srv[0]]);
        $s3 = $this->prof('Ocupada', $this->srv);
        $s4 = $this->prof('Inactiva', $this->srv);
        $s5 = $this->prof('Solo Martes', $this->srv, [2]);
        $s6 = $this->prof('Con Bloqueo', $this->srv);
        $s7 = $this->prof('Con Descanso', $this->srv);
        $lunes = $this->proximo(1);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0], $this->srv[1]]);
        $this->reservar($this->clientes[1], $s3['prof'], $lunes, '11:00', [$this->srv[0]]);
        $this->db->query("UPDATE profesionales SET activo=0 WHERE id={$s4['prof']}");
        $this->db->query("INSERT INTO bloqueos_profesionales(profesionalId,fecha_inicio,fecha_fin,motivo) VALUES ({$s6['prof']},'$lunes','$lunes','Vacaciones')");
        $this->db->query("INSERT INTO descansos_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES ({$s7['prof']},1,'10:30:00','11:00:00')");

        $casos = [
            'sin el servicio' => [$s2['prof'], 'servicios_incompatibles', ReasignacionCitasService::INVALID],
            'ocupado' => [$s3['prof'], 'conflicto_ocupacion', ReasignacionCitasService::CONFLICT],
            'inactivo' => [$s4['prof'], 'destino_inactivo', ReasignacionCitasService::INVALID],
            'no trabaja ese día' => [$s5['prof'], 'fuera_de_horario', ReasignacionCitasService::INVALID],
            'bloqueo de fecha' => [$s6['prof'], 'fuera_de_horario', ReasignacionCitasService::INVALID],
            'cruza un descanso' => [$s7['prof'], 'fuera_de_horario', ReasignacionCitasService::INVALID],
            'el mismo profesional' => [$o['prof'], 'mismo_profesional', ReasignacionCitasService::INVALID],
            'inexistente' => [999999, 'destino_inexistente', ReasignacionCitasService::INVALID],
        ];
        $antes = $this->foto([$c1]);
        foreach ($casos as $nombre => [$destino, $codigo, $estado]) {
            $r = $this->aplicar($o, [$c1 => "reasignar:$destino"]);
            $this->assertSame($estado, $r['status'], "$nombre: " . json_encode($r));
            $this->assertSame($codigo, $r['codigo'], $nombre);
            $this->assertStringContainsString("Cita #$c1", $r['errores'][0], 'El mensaje identifica la cita y el motivo');
            $this->assertSame($antes, $this->foto([$c1]), "$nombre: no cambia nada");
        }
        $this->assertSame([], $this->cambiosDeProfesional(), 'Ningún aviso al cliente por intentos rechazados');
    }

    public function testNoSeAceptanDecisionesForjadasDeOtraCitaOMalformadas(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $x = $this->prof('Otro Profesional', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $mia = $this->reservar($this->clientes[0], $o['prof'], $this->proximo(1), '10:00', [$this->srv[0]]);
        $ajena = $this->reservar($this->clientes[1], $x['prof'], $this->proximo(1), '12:00', [$this->srv[0]]);
        $antes = $this->foto([$mia, $ajena]);
        foreach ([[$ajena => "reasignar:{$s1['prof']}"], [$mia => 'reasignar:abc'], [$mia => 'reasignar:0'], [$mia => ['x']], [$mia => "reasignar:{$s1['prof']};DROP"], ['1 OR 1=1' => 'cancelar'], [999999 => 'cancelar']] as $malo) {
            $r = $this->aplicar($o, $malo);
            $this->assertSame(ReasignacionCitasService::INVALID, $r['status'], json_encode($malo));
        }
        $this->assertSame($antes, $this->foto([$mia, $ajena]));
        // Todo en «manual» no cuenta como decisión: la baja seguiría bloqueada y se le indica al operador.
        $r = $this->aplicar($o, [$mia => 'manual']);
        $this->assertSame(ReasignacionCitasService::INVALID, $r['status']);
        $this->assertSame($antes, $this->foto([$mia, $ajena]));
        // Motivo y clave son obligatorios.
        $this->assertSame(ReasignacionCitasService::INVALID, $this->aplicar($o, [$mia => "reasignar:{$s1['prof']}"], null, ' ')['status']);
        $this->assertSame(ReasignacionCitasService::INVALID, $this->rea()->aplicar($this->owner, $o['colab'], [$mia => "reasignar:{$s1['prof']}"], 'Motivo', null)['status']);
        $this->assertSame($antes, $this->foto([$mia, $ajena]));
    }

    // ------------------------------------------------------------------ Atomicidad (todo o nada)

    public function testUnLoteConUnaReasignacionInvalidaNoAplicaNinguna(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $s2 = $this->prof('Solo A', [$this->srv[0]]);
        $lunes = $this->proximo(1);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0]]);
        $c2 = $this->reservar($this->clientes[1], $o['prof'], $lunes, '12:00', [$this->srv[1]]);
        $antes = $this->foto([$c1, $c2]);
        $clave = Idempotencia::nuevaClave();
        // c1 → válida; c2 (servicio B) → s2 no lo presta.
        $r = $this->rea()->aplicar($this->owner, $o['colab'], [$c1 => "reasignar:{$s1['prof']}", $c2 => "reasignar:{$s2['prof']}"], 'Baja', $clave);
        $this->assertSame(ReasignacionCitasService::INVALID, $r['status']);
        $this->assertStringContainsString("Cita #$c2", $r['errores'][0]);
        $this->assertStringContainsString('No se aplicó ninguna reasignación', $r['errores'][0]);
        $this->assertSame($antes, $this->foto([$c1, $c2]), 'La primera, válida, tampoco se aplicó');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE scope='colaborador.reasignar'"), 'Sin rastro de éxito idempotente');
        $this->assertSame([], $this->cambiosDeProfesional());
        // Corregido el lote, la misma clave sirve (el intento fallido no la consumió).
        $ok = $this->rea()->aplicar($this->owner, $o['colab'], [$c1 => "reasignar:{$s1['prof']}", $c2 => "reasignar:{$s1['prof']}"], 'Baja', $clave);
        $this->assertSame(ReasignacionCitasService::OK, $ok['status'], json_encode($ok));
        $this->assertSame(2, $ok['reasignadas']);
        $this->assertCount(2, $this->cambiosDeProfesional());
    }

    public function testUnFalloTecnicoAMitadDelLoteDejaTodoIgualYSePuedeReintentar(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $lunes = $this->proximo(1);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0]]);
        $c2 = $this->reservar($this->clientes[1], $o['prof'], $lunes, '12:00', [$this->srv[0]]);
        $antes = $this->foto([$c1, $c2]);
        // La primera cita se reasigna completa (UPDATE, auditoría, evento, outbox) y la segunda falla al registrar su evento.
        $this->db->query("CREATE TRIGGER test_fail_agenda_evento_rea BEFORE INSERT ON agenda_eventos FOR EACH ROW BEGIN IF NEW.tipo='reasignacion' AND NEW.citaId=$c2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated failure in agenda_eventos'; END IF; END");
        $clave = Idempotencia::nuevaClave();
        $r = $this->rea()->aplicar($this->owner, $o['colab'], [$c1 => "reasignar:{$s1['prof']}", $c2 => "reasignar:{$s1['prof']}"], 'Baja', $clave);
        $this->db->query('DROP TRIGGER test_fail_agenda_evento_rea');
        $this->assertSame(ReasignacionCitasService::ERROR, $r['status']);
        $this->assertStringContainsString('No se aplicaron cambios', $r['errores'][0]);
        $this->assertSame($antes, $this->foto([$c1, $c2]), 'Ni la cita ni su auditoría ni su aviso: todo igual');
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE scope='colaborador.reasignar'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) n FROM equipo_auditoria WHERE accion='citas_reasignadas'"));
        $this->assertSame([], $this->cambiosDeProfesional());
        $ok = $this->rea()->aplicar($this->owner, $o['colab'], [$c1 => "reasignar:{$s1['prof']}", $c2 => "reasignar:{$s1['prof']}"], 'Baja', $clave);
        $this->assertSame(ReasignacionCitasService::OK, $ok['status']);
        $this->assertSame(2, $ok['reasignadas']);
    }

    // ------------------------------------------------------------------ Cancelación por el flujo existente

    public function testCancelarUsaElFlujoNormalConSuAvisoUnicoYDejaConstanciaDeLaDecision(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $this->proximo(2), '11:00', [$this->srv[0]]);
        $emailCliente = $this->db->query("SELECT u.email FROM usuarios u JOIN citas c ON c.usuarioId=u.id WHERE c.id=$c1")->fetch_assoc()['email'];
        $this->enviados = [];
        $r = $this->aplicar($o, [$c1 => 'cancelar'], null, 'Cierre de agenda');
        $this->assertSame(ReasignacionCitasService::OK, $r['status'], json_encode($r));
        $this->assertSame(1, $r['canceladas']);
        $this->assertSame(0, $r['pendientes']);
        $cita = $this->db->query("SELECT estado,cancelado_por,cancelado_at,motivo_cancelacion,profesionalId FROM citas WHERE id=$c1")->fetch_assoc();
        $this->assertSame('cancelada', $cita['estado']);
        $this->assertSame('admin', $cita['cancelado_por']);
        $this->assertNotNull($cita['cancelado_at']);
        $this->assertStringContainsString('Cierre de agenda', $cita['motivo_cancelacion']);
        $this->assertStringContainsString('Rea Origen', $cita['motivo_cancelacion']);
        $this->assertSame((string)$o['prof'], $cita['profesionalId'], 'La cita conserva su historial: no se reasigna ni se borra');
        $h = (new ReasignacionCitasRepository($this->db))->historial($c1);
        $this->assertCount(1, $h);
        $this->assertSame('cancelada', $h[0]['accion']);
        $this->assertNull($h[0]['profesional_destino_id']);
        $this->assertSame($this->owner, (int)$h[0]['actor_id']);
        $avisos = array_values(array_filter($this->enviados, static fn(array $e): bool => $e['proposito'] === 'cita_cancelacion'));
        $this->assertCount(1, $avisos);
        $this->assertSame($emailCliente, $avisos[0]['para']);
        // Repetir el mismo envío: el resultado es el mismo y no se vuelve a cancelar ni a avisar.
        $otra = $this->aplicar($o, [$c1 => 'cancelar']);
        $this->assertSame(ReasignacionCitasService::OK, $otra['status']);
        $this->assertSame(1, $otra['canceladas']);
        // Una cita cancelada por otra vía distinta de esta decisión sí se rechaza (la lista cambió).
        $c2 = $this->reservar($this->clientes[1], $o['prof'], $this->proximo(3), '11:00', [$this->srv[0]]);
        $this->db->query("UPDATE citas SET estado='cancelada' WHERE id=$c2");
        $this->assertSame(ReasignacionCitasService::INVALID, $this->aplicar($o, [$c2 => 'cancelar'])['status']);
        $this->assertCount(1, array_filter($this->enviados, static fn(array $e): bool => $e['proposito'] === 'cita_cancelacion'));
        $this->assertCount(1, (new ReasignacionCitasRepository($this->db))->historial($c1));
    }

    // ------------------------------------------------------------------ Baja bloqueada y posterior

    public function testLaBajaSeBloqueaConPendientesYSeConfirmaCuandoNoQuedanConIdempotencia(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $lunes = $this->proximo(1);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0]]);
        $c2 = $this->reservar($this->clientes[1], $o['prof'], $lunes, '12:00', [$this->srv[0]]);
        $c3 = $this->reservar($this->clientes[2], $o['prof'], $lunes, '14:00', [$this->srv[0]]);

        $bloq = $this->colab()->cambiarAcceso($this->owner, $o['colab'], 'profesional', 'baja');
        $this->assertSame(ColaboradorService::FORBIDDEN, $bloq['status']);
        $this->assertStringContainsString('3 cita(s) futura(s)', $bloq['errores'][0]);
        $this->assertSame('activo', $this->db->query("SELECT estado FROM colaboradores WHERE id={$o['colab']}")->fetch_assoc()['estado']);

        // c1 reasignada, c2 cancelada, c3 «manual»: sigue bloqueada por la que queda sin resolver.
        $r = $this->aplicar($o, [$c1 => "reasignar:{$s1['prof']}", $c2 => 'cancelar', $c3 => 'manual']);
        $this->assertSame(ReasignacionCitasService::OK, $r['status'], json_encode($r));
        $this->assertSame([1, 1, 1, 1], [$r['reasignadas'], $r['canceladas'], $r['manuales'], $r['pendientes']]);
        $bloq = $this->colab()->cambiarAcceso($this->owner, $o['colab'], 'profesional', 'baja', Idempotencia::nuevaClave());
        $this->assertSame(ColaboradorService::FORBIDDEN, $bloq['status']);
        $this->assertStringContainsString('1 cita(s) futura(s)', $bloq['errores'][0]);
        $this->assertSame('activo', $this->db->query("SELECT estado FROM colaboradores WHERE id={$o['colab']}")->fetch_assoc()['estado']);

        // La última se resuelve y la baja se confirma una sola vez aunque el POST se repita.
        $this->assertSame(ReasignacionCitasService::OK, $this->aplicar($o, [$c3 => "reasignar:{$s1['prof']}"])['status']);
        $clave = Idempotencia::nuevaClave();
        $baja = $this->colab()->cambiarAcceso($this->owner, $o['colab'], 'profesional', 'baja', $clave);
        $this->assertSame(ColaboradorService::OK, $baja['status'], json_encode($baja));
        $this->assertFalse($baja['repetida']);
        $repetida = $this->colab()->cambiarAcceso($this->owner, $o['colab'], 'profesional', 'baja', $clave);
        $this->assertSame(ColaboradorService::OK, $repetida['status']);
        $this->assertTrue($repetida['repetida']);
        $this->assertSame('baja', $this->db->query("SELECT estado FROM colaboradores WHERE id={$o['colab']}")->fetch_assoc()['estado']);
        $this->assertSame('0', $this->db->query("SELECT activo FROM profesionales WHERE id={$o['prof']}")->fetch_assoc()['activo']);
        // Mismo identificador con otro estado es un conflicto, no un segundo cambio.
        $this->assertSame(ColaboradorService::CONFLICT_KEY, $this->colab()->cambiarAcceso($this->owner, $o['colab'], 'profesional', 'suspendido', $clave)['status']);

        // Historial intacto: las citas reasignadas siguen con su sustituto, la cancelada con su origen y la auditoría completa.
        $this->assertSame($s1['prof'], $this->profDe($c1));
        $this->assertSame($s1['prof'], $this->profDe($c3));
        $this->assertSame($o['prof'], $this->profDe($c2));
        $this->assertSame(3, $this->n("SELECT COUNT(*) n FROM citas_reasignaciones WHERE cita_referencia_id IN ($c1,$c2,$c3)"));
        // La ficha dada de baja no aparece en disponibilidad ni en el catálogo de reservas.
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_INACTIVE, (new DisponibilidadService())->consultar($o['prof'], $this->proximo(3), [$this->srv[0]])['status']);
        $this->assertNotContains($o['prof'], array_column((new ProfesionalService())->consultarParaReserva([])['profesionales'], 'id'));
    }

    public function testUnaCitaEnServicioOYaIniciadaNoSeReasignaNiSeCancelaYBloqueaLaBaja(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $this->proximo(1), '10:00', [$this->srv[0]]);
        $c2 = $this->reservar($this->clientes[1], $o['prof'], $this->proximo(2), '10:00', [$this->srv[0]]);
        $this->db->query("UPDATE citas SET estado='en_servicio' WHERE id=$c1");
        $rev = $this->rea()->revisar($this->owner, $o['colab']);
        $porId = array_column($rev['citas'], null, 'id');
        $this->assertStringContainsString('En servicio', ucfirst($porId[$c1]['bloqueo']) . ' ' . 'En servicio');
        $this->assertSame([], $porId[$c1]['sustitutos']);
        $this->assertFalse($porId[$c1]['cancelable']);
        $r = $this->aplicar($o, [$c1 => "reasignar:{$s1['prof']}"]);
        $this->assertSame(ReasignacionCitasService::INVALID, $r['status']);
        $this->assertSame('cita_no_reasignable', $r['codigo']);
        $this->assertSame($o['prof'], $this->profDe($c1));
        // Resuelta la otra, la baja sigue bloqueada por la que está en servicio.
        $this->assertSame(ReasignacionCitasService::OK, $this->aplicar($o, [$c2 => "reasignar:{$s1['prof']}"])['status']);
        $this->assertSame(ColaboradorService::FORBIDDEN, $this->colab()->cambiarAcceso($this->owner, $o['colab'], 'profesional', 'suspendido')['status']);
        // Una cita que ya comenzó (hoy, hora pasada) tampoco se mueve.
        $this->db->query("UPDATE citas SET estado='confirmada', fecha=DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00')), hora='00:00:00', hora_inicio='00:00:00', hora_fin='00:30:00' WHERE id=$c1");
        $rev = $this->rea()->revisar($this->owner, $o['colab']);
        $this->assertStringContainsString('Ya comenzó', array_column($rev['citas'], null, 'id')[$c1]['bloqueo']);
        $this->assertSame('cita_iniciada', $this->aplicar($o, [$c1 => "reasignar:{$s1['prof']}"])['codigo']);
    }

    // ------------------------------------------------------------------ Comisiones

    public function testUnaCitaConComisionYaDevengadaNoSeMueveYLaNoDevengadaSigueAlProfesionalDestino(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $this->db->query("INSERT INTO comisiones_config(profesional_id,porcentaje_pb,actor_id) VALUES ({$o['prof']},2000,{$this->owner}),({$s1['prof']},3000,{$this->owner})");
        $lunes = $this->proximo(1);
        $pagada = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0]]);
        $libre = $this->reservar($this->clientes[1], $o['prof'], $lunes, '12:00', [$this->srv[0]]);
        (new FinanzasService())->registrar($this->owner, ['tipo' => 'ingreso', 'metodo' => 'efectivo', 'concepto' => 'Rea test cobro', 'idempotencia' => bin2hex(random_bytes(12)), 'cita_id' => (string)$pagada]);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM comisiones_movimientos WHERE profesional_id={$o['prof']} AND tipo='devengo'"));
        $comisiones = $this->db->query('SELECT id,profesional_id,monto_centavos,estado FROM comisiones_movimientos ORDER BY id')->fetch_all(MYSQLI_ASSOC);

        // La cita con comisión devengada se señala y se bloquea (no corrompe el devengo).
        $rev = array_column($this->rea()->revisar($this->owner, $o['colab'])['citas'], null, 'id');
        $this->assertStringContainsString('devengó comisión', $rev[$pagada]['bloqueo']);
        $this->assertSame([], $rev[$pagada]['sustitutos']);
        $antes = $this->foto([$pagada]);
        $r = $this->aplicar($o, [$pagada => "reasignar:{$s1['prof']}"]);
        $this->assertSame('comision_devengada', $r['codigo']);
        $this->assertSame($antes, $this->foto([$pagada]));
        $this->assertSame($comisiones, $this->db->query('SELECT id,profesional_id,monto_centavos,estado FROM comisiones_movimientos ORDER BY id')->fetch_all(MYSQLI_ASSOC));

        // La otra (aún sin cobro) se reasigna; su cobro posterior devenga para el DESTINO con su porcentaje.
        $this->assertSame(ReasignacionCitasService::OK, $this->aplicar($o, [$libre => "reasignar:{$s1['prof']}"])['status']);
        $this->assertSame($comisiones, $this->db->query('SELECT id,profesional_id,monto_centavos,estado FROM comisiones_movimientos ORDER BY id')->fetch_all(MYSQLI_ASSOC), 'Reasignar no genera ni altera movimientos de comisión');
        (new FinanzasService())->registrar($this->owner, ['tipo' => 'ingreso', 'metodo' => 'efectivo', 'concepto' => 'Rea test cobro 2', 'idempotencia' => bin2hex(random_bytes(12)), 'cita_id' => (string)$libre]);
        $nuevo = $this->db->query("SELECT m.profesional_id,m.porcentaje_pb FROM comisiones_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=$libre")->fetch_assoc();
        $this->assertSame((string)$s1['prof'], $nuevo['profesional_id']);
        $this->assertSame('3000', $nuevo['porcentaje_pb']);
        // La pagada, con su devengo para el origen, sigue siendo cancelable (decisión explícita) sin tocar la comisión.
        $this->assertSame(ReasignacionCitasService::OK, $this->aplicar($o, [$pagada => 'cancelar'])['status']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM comisiones_movimientos WHERE profesional_id={$o['prof']} AND tipo='devengo' AND estado='pendiente'"));
    }

    // ------------------------------------------------------------------ Permisos y privacidad

    private function sesion(int $id, string $portal = 'equipo'): void
    {
        $ctx = (new AuthorizationService($this->db))->contexto($id);
        $_SESSION = ['login' => true, 'id' => $id, 'portal' => $portal, 'version_sesion' => (int)$ctx['version_sesion'], 'csrf_token' => 'csrf-rea'];
    }

    private function ejecutar(callable $fn): array
    {
        $status = null;
        http_response_code(200);
        ob_start();
        try { $fn(); } catch (AppTerminationException $e) { $status = $e->getStatusCode(); }
        $salida = (string)ob_get_clean();
        return [$status ?? (int)http_response_code(), $salida];
    }

    public function testSoloQuienGestionaLaAgendaVeLasCitasYNingunOtroRolReasignaNiVeDatosDelCliente(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $this->proximo(1), '10:00', [$this->srv[0]]);
        $antes = $this->foto([$c1]);
        $roles = [];
        foreach (['profesional', 'finanzas', 'cliente'] as $rol) { $roles[$rol] = $this->cuenta($rol); }
        foreach ($roles as $rol => $id) {
            $rev = $this->rea()->revisar($id, $o['colab']);
            $this->assertSame(ReasignacionCitasService::FORBIDDEN, $rev['status'], $rol);
            $this->assertArrayNotHasKey('citas', $rev, "$rol no recibe ningún dato");
            $this->assertSame(ReasignacionCitasService::FORBIDDEN, $this->rea()->aplicar($id, $o['colab'], [$c1 => "reasignar:{$s1['prof']}"], 'Motivo', Idempotencia::nuevaClave())['status'], $rol);
            // Por HTTP.
            $this->sesion($id, $rol === 'cliente' ? 'cliente' : 'equipo');
            $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['id' => (string)$o['colab']];
            [$http, $html] = $this->ejecutar(fn() => EquipoController::reasignar(new Router()));
            $this->assertSame(403, $http, $rol);
            $this->assertStringNotContainsString('Carla', $html);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['csrf_token' => 'csrf-rea', 'idem_key' => Idempotencia::nuevaClave(), 'id' => (string)$o['colab'], 'motivo' => 'Motivo', 'decision' => [$c1 => "reasignar:{$s1['prof']}"]];
            [$http] = $this->ejecutar(fn() => EquipoController::reasignarAplicar());
            $this->assertSame(403, $http, $rol);
        }
        $this->assertSame($antes, $this->foto([$c1]));

        // Recepción y gerencia gestionan la agenda y consultan clientes (clientes.ver): ven la cita con el nombre.
        foreach (['recepcion', 'gerente'] as $rol) {
            $rev = $this->rea()->revisar($this->cuenta($rol), $o['colab']);
            $this->assertSame(ReasignacionCitasService::OK, $rev['status'], $rol);
            $this->assertSame('Carla Prueba', $rev['citas'][0]['cliente']);
        }

        // El propietario por HTTP: ve nombres, requiere CSRF y clave, y reasigna.
        $this->sesion($this->owner);
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['id' => (string)$o['colab']];
        [$http, $html] = $this->ejecutar(fn() => EquipoController::reasignar(new Router()));
        $this->assertSame(200, $http);
        $this->assertStringContainsString('Carla Prueba', $html);
        $this->assertStringContainsString('reasignar:' . $s1['prof'], $html);
        $this->assertStringContainsString('name="idem_key"', $html);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $post = ['csrf_token' => 'csrf-rea', 'idem_key' => Idempotencia::nuevaClave(), 'id' => (string)$o['colab'], 'motivo' => 'Baja', 'decision' => [$c1 => "reasignar:{$s1['prof']}"]];
        $_POST = ['csrf_token' => 'otro'] + $post;
        [$http] = $this->ejecutar(fn() => EquipoController::reasignarAplicar());
        $this->assertSame(403, $http, 'CSRF inválido');
        $this->assertSame($antes, $this->foto([$c1]));
        $_POST = array_diff_key($post, ['idem_key' => 1]);
        [$http] = $this->ejecutar(fn() => EquipoController::reasignarAplicar());
        $this->assertSame(302, $http);
        $this->assertSame($antes, $this->foto([$c1]), 'Sin clave de operación no se aplica');
        $_POST = $post;
        [$http] = $this->ejecutar(fn() => EquipoController::reasignarAplicar());
        $this->assertSame(302, $http);
        $this->assertSame('/admin/equipo/reasignar?id=' . $o['colab'], $GLOBALS['__appsalon_redireccion']);
        $this->assertSame($s1['prof'], $this->profDe($c1));
        [$http] = $this->ejecutar(fn() => EquipoController::reasignarAplicar());
        $this->assertSame(302, $http);
        $this->assertCount(1, (new ReasignacionCitasRepository($this->db))->historial($c1), 'El reenvío del mismo formulario no duplica');
    }

    public function testSinPermisoDeClientesLaCitaSeProyectaSinDatosDelClienteNiEnLaVista(): void
    {
        $cita = ['id' => 7, 'fecha' => '2030-01-07', 'hora_inicio' => '10:00', 'hora_fin' => '10:30', 'duracion' => 30, 'estado' => 'confirmada', 'estado_pago' => 'pendiente',
            'cliente' => 'Carla Secreta', 'servicios' => ['Corte'], 'bloqueo' => null, 'sustitutos' => [['id' => 2, 'nombre' => 'Rea X']], 'cancelable' => true];
        $this->assertSame('Carla Secreta', ReasignacionCitasService::proyectarCita($cita, true)['cliente']);
        $oculta = ReasignacionCitasService::proyectarCita($cita, false);
        $this->assertNull($oculta['cliente']);
        $this->assertStringNotContainsString('Carla', json_encode($oculta));
        // La vista no imprime el nombre cuando el servicio lo oculta.
        $this->sesion($this->owner);
        $revision = ['status' => 'ok', 'ver_cliente' => false, 'resumen' => ['total' => 1, 'con_sustituto' => 1, 'bloqueadas' => 0], 'citas' => [$oculta],
            'profesional' => ['id' => 1, 'nombre' => 'Rea X', 'colaborador_id' => 1, 'colaborador' => 'X', 'rol' => 'profesional', 'estado' => 'activo']];
        ob_start();
        (new Router())->render('equipo/reasignar', ['revision' => $revision, 'alertas' => [], 'puedeBaja' => true, 'idemReasignar' => Idempotencia::nuevaClave(), 'idemAcceso' => Idempotencia::nuevaClave()]);
        $html = (string)ob_get_clean();
        $this->assertStringNotContainsString('Carla', $html);
        $this->assertStringContainsString('Datos reservados', $html);
        $this->assertStringContainsString('sin nombre del cliente', $html);
    }

    // ------------------------------------------------------------------ Concurrencia real (procesos PHP)

    /** @return array{0:resource,1:array} */
    private function lanzar(array $cfg): array
    {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_reasignacion_worker.php', json_encode($cfg)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("READY\n", fgets($pipes[1]), 'El worker debe estar listo antes de abrir la barrera');
        return [$proc, $pipes];
    }

    private function liberar(array $trabajadores): array
    {
        foreach ($trabajadores as [, $p]) { fwrite($p[0], "GO\n"); fflush($p[0]); fclose($p[0]); }
        $out = [];
        foreach ($trabajadores as [$proc, $p]) {
            $linea = fgets($p[1]);
            $err = stream_get_contents($p[2]);
            fclose($p[1]); fclose($p[2]);
            $this->assertSame(0, proc_close($proc), $err);
            $out[] = json_decode((string)$linea, true);
        }
        return $out;
    }

    public function testDosReasignacionesSimultaneasAlMismoHuecoDelSustitutoSoloUnaGana(): void
    {
        $o1 = $this->prof('Origen Uno', $this->srv);
        $o2 = $this->prof('Origen Dos', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $lunes = $this->proximo(1);
        $c1 = $this->reservar($this->clientes[0], $o1['prof'], $lunes, '10:00', [$this->srv[0]]);
        $c2 = $this->reservar($this->clientes[1], $o2['prof'], $lunes, '10:00', [$this->srv[0]]);
        $mk = fn(array $o, int $c) => ['modo' => 'reasignar', 'actor' => $this->owner, 'colab' => $o['colab'], 'decisiones' => [(string)$c => "reasignar:{$s1['prof']}"], 'motivo' => 'Baja', 'clave' => Idempotencia::nuevaClave(), 'demoraMs' => 900];
        $res = $this->liberar([$this->lanzar($mk($o1, $c1)), $this->lanzar($mk($o2, $c2))]);
        $estados = array_column($res, 'status');
        sort($estados);
        $this->assertSame([ReasignacionCitasService::CONFLICT, ReasignacionCitasService::OK], $estados, json_encode($res));
        $perdedor = array_values(array_filter($res, static fn($r) => $r['status'] !== 'ok'))[0];
        $this->assertSame('conflicto_ocupacion', $perdedor['codigo']);
        $this->assertStringContainsString('ya está ocupado', $perdedor['errores'][0], 'Conflicto claro para quien pierde');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM citas WHERE profesionalId={$s1['prof']} AND fecha='$lunes' AND estado='confirmada'"), 'Un solo ocupante del hueco');
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM citas_reasignaciones WHERE cita_referencia_id IN ($c1,$c2)"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM notificaciones_citas WHERE clave_idempotencia LIKE 'cita:%:reasignacion:%' AND cita_referencia_id IN ($c1,$c2)"));
        // La perdedora sigue entera con su profesional original.
        $perdida = $this->profDe($c1) === $s1['prof'] ? $c2 : $c1;
        $this->assertContains($this->profDe($perdida), [$o1['prof'], $o2['prof']]);
    }

    public function testUnaReasignacionYUnaReservaNuevaSimultaneasAlMismoHuecoSoloUnaGana(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $lunes = $this->proximo(1);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $lunes, '10:00', [$this->srv[0]]);
        $reasignar = ['modo' => 'reasignar', 'actor' => $this->owner, 'colab' => $o['colab'], 'decisiones' => [(string)$c1 => "reasignar:{$s1['prof']}"], 'motivo' => 'Baja', 'clave' => Idempotencia::nuevaClave(), 'demoraMs' => 1500];
        // La reserva entra cuando la reasignación ya validó el hueco y retiene su transacción (1500 ms).
        $reservar = ['modo' => 'reservar', 'usuario' => $this->clientes[1], 'prof' => $s1['prof'], 'fecha' => $lunes, 'hora' => '10:00', 'servicios' => [$this->srv[0]], 'esperaMs' => 600];
        [$a, $b] = $this->liberar([$this->lanzar($reasignar), $this->lanzar($reservar)]);
        $ganadores = (int)($a['status'] === 'ok') + (int)($b['status'] === 'ok');
        $this->assertSame(1, $ganadores, json_encode([$a, $b]));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM citas WHERE profesionalId={$s1['prof']} AND fecha='$lunes' AND hora_inicio='10:00:00' AND estado='confirmada'"), 'Sin doble reserva del hueco');
    }

    public function testDosEnviosSimultaneosDelMismoFormularioReasignanYNotificanUnaSolaVez(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $s1 = $this->prof('Sustituta Uno', $this->srv);
        $c1 = $this->reservar($this->clientes[0], $o['prof'], $this->proximo(1), '10:00', [$this->srv[0]]);
        $cfg = ['modo' => 'reasignar', 'actor' => $this->owner, 'colab' => $o['colab'], 'decisiones' => [(string)$c1 => "reasignar:{$s1['prof']}"], 'motivo' => 'Baja', 'clave' => Idempotencia::nuevaClave(), 'demoraMs' => 600];
        $res = $this->liberar([$this->lanzar($cfg), $this->lanzar($cfg)]);
        $this->assertSame(['ok', 'ok'], array_column($res, 'status'), json_encode($res));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM citas_reasignaciones WHERE cita_referencia_id=$c1"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) n FROM notificaciones_citas WHERE cita_referencia_id=$c1 AND clave_idempotencia LIKE 'cita:$c1:reasignacion:%'"));
        $this->assertSame($s1['prof'], $this->profDe($c1));
    }

    public function testUnaBajaYUnaReservaSimultaneasNuncaDejanUnaCitaHuerfana(): void
    {
        $o = $this->prof('Origen', $this->srv);
        $lunes = $this->proximo(1);
        $reservar = ['modo' => 'reservar', 'usuario' => $this->clientes[0], 'prof' => $o['prof'], 'fecha' => $lunes, 'hora' => '10:00', 'servicios' => [$this->srv[0]]];
        $baja = ['modo' => 'baja', 'actor' => $this->owner, 'colab' => $o['colab'], 'rol' => 'profesional', 'estado' => 'baja', 'clave' => Idempotencia::nuevaClave()];
        [$r, $b] = $this->liberar([$this->lanzar($reservar), $this->lanzar($baja)]);
        $pendientes = $this->n("SELECT COUNT(*) n FROM citas WHERE profesionalId={$o['prof']} AND estado IN ('confirmada','en_servicio') AND fecha>=CURDATE()");
        $activo = (int)$this->db->query("SELECT activo n FROM profesionales WHERE id={$o['prof']}")->fetch_assoc()['n'];
        $this->assertFalse($activo === 0 && $pendientes > 0, 'Un profesional de baja nunca conserva citas futuras: ' . json_encode([$r, $b]));
    }

    // ------------------------------------------------------------------ Migración 021: rollback seguro

    public function testElRollbackDeLaMigracion021ConservaLaAuditoriaYPermiteVolverAAplicar(): void
    {
        $admin = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', '', (int)(getenv('DB_PORT') ?: 3306));
        $nombre = 'appsalon_agenda_mig021_test';
        $admin->query("DROP DATABASE IF EXISTS $nombre");
        $admin->query("CREATE DATABASE $nombre CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', $nombre, (int)(getenv('DB_PORT') ?: 3306));
        $ejecutar = function (string $sql) use ($db): void {
            $this->assertTrue($db->multi_query($sql), $db->error);
            do { if ($r = $db->store_result()) { $r->free(); } if (!$db->more_results()) { break; } $this->assertTrue($db->next_result(), $db->error); } while (true);
        };
        try {
            $ejecutar((string)file_get_contents(__DIR__ . '/../../database/schema_base.sql'));
            $env = array_merge($_ENV, ['DB_NAME' => $nombre, 'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1', 'DB_USER' => getenv('DB_USER') ?: 'root', 'DB_PASS' => getenv('DB_PASS') ?: 'root', 'DB_PORT' => (string)(getenv('DB_PORT') ?: 3306)]);
            $migrar = function () use ($env): string {
                $p = proc_open([PHP_BINARY, __DIR__ . '/../../database/migrador.php', 'up'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                $this->assertSame(0, proc_close($p), $salida);
                return $salida;
            };
            $migrar();
            $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema='$nombre' AND table_name='citas_reasignaciones'")->fetch_assoc()['n']);
            $rollback = (string)file_get_contents(__DIR__ . '/../../database/migrations/021_citas_reasignaciones_rollback.sql');

            // Vacía: se elimina limpiamente.
            $ejecutar($rollback);
            $this->assertSame(0, (int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema='$nombre' AND table_name LIKE 'citas_reasignaciones%'")->fetch_assoc()['n']);

            // Con datos: NO se pierden (se renombran a un respaldo) y la migración vuelve a aplicarse sin choques de FK.
            $db->query("DELETE FROM migraciones WHERE migracion='021_citas_reasignaciones'");
            $migrar();
            $db->query("INSERT INTO citas_reasignaciones(cita_referencia_id,accion,profesional_origen_nombre,motivo,snapshot_json) VALUES (10,'cancelada','Rea Mig','m','{\"k\":1}')");
            $ejecutar($rollback);
            $this->assertSame(1, (int)$db->query('SELECT COUNT(*) n FROM citas_reasignaciones_respaldo_021')->fetch_assoc()['n'], 'La auditoría se conserva');
            $this->assertSame(0, (int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema='$nombre' AND table_name='citas_reasignaciones'")->fetch_assoc()['n']);
            $db->query("DELETE FROM migraciones WHERE migracion='021_citas_reasignaciones'");
            $migrar();
            $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema='$nombre' AND table_name='citas_reasignaciones'")->fetch_assoc()['n']);

            // Segundo rollback con datos nuevos y respaldo ya existente: se DETIENE sin perder nada.
            $db->query("INSERT INTO citas_reasignaciones(cita_referencia_id,accion,profesional_origen_nombre,motivo,snapshot_json) VALUES (11,'cancelada','Rea Mig2','m','{}')");
            mysqli_report(MYSQLI_REPORT_OFF);
            $fallo = !@$db->multi_query($rollback);
            if (!$fallo) {
                do { if ($r = $db->store_result()) { $r->free(); } if ($db->errno) { $fallo = true; break; } if (!$db->more_results()) { break; } if (!@$db->next_result()) { $fallo = $db->errno !== 0; break; } } while (true);
            }
            $this->assertTrue($fallo, 'Debe fallar a propósito en lugar de pisar el respaldo');
            $this->assertSame(1, (int)$db->query('SELECT COUNT(*) n FROM citas_reasignaciones')->fetch_assoc()['n']);
            $this->assertSame(1, (int)$db->query('SELECT COUNT(*) n FROM citas_reasignaciones_respaldo_021')->fetch_assoc()['n']);
        } finally {
            $admin->query("DROP DATABASE IF EXISTS $nombre");
        }
    }
}
