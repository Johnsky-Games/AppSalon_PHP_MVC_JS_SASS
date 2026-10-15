<?php

namespace Tests\Integration;

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\ServicioRepository;
use Services\AgendaAtencionService;
use Services\CitaService;

/**
 * Ronda 5 · agente a5 — atención de citas contra la base real: máquina de estados, regla de adelanto en el servidor, trazabilidad
 * (actor + hora del negocio + estado anterior/nuevo), idempotencia, atomicidad, permisos y concurrencia con procesos PHP reales.
 */
final class R5AtencionCitaIntegrationTest extends TestCase
{
    private \mysqli $db;
    private CitaRepository $repo;
    private array $usuarios = [];
    private array $citas = [];
    private int $prof;
    private int $profAjeno;
    private int $servicio;

    private const AHORA = '2030-01-07 12:00:00';

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        // 0 cliente titular · 1 recepción/gestión · 2 profesional propio · 3 profesional ajeno · 4 persona sin ningún permiso
        for ($i = 0; $i < 5; $i++) {
            $email = 'r5at-' . bin2hex(random_bytes(8)) . '@test.local';
            $nombre = ['Titular', 'Gestora', 'Propio', 'Ajeno', 'Nadie'][$i];
            $stmt = $this->db->prepare("INSERT INTO usuarios (nombre, apellido, email, password, confirmado) VALUES (?, 'R5', ?, 'hash', 1)");
            $stmt->bind_param('ss', $nombre, $email);
            $this->assertTrue($stmt->execute());
            $this->usuarios[] = $this->db->insert_id;
            $stmt->close();
        }
        $this->db->query("INSERT INTO servicios (nombre, precio, duracion_minutos) VALUES ('R5 fixture', 35, 30)");
        $this->servicio = $this->db->insert_id;
        $this->db->query("INSERT INTO profesionales (nombre, activo, usuarioId) VALUES ('R5 propio', 1, {$this->usuarios[2]})");
        $this->prof = $this->db->insert_id;
        $this->db->query("INSERT INTO profesionales (nombre, activo, usuarioId) VALUES ('R5 ajeno', 1, {$this->usuarios[3]})");
        $this->profAjeno = $this->db->insert_id;
        $this->repo = new CitaRepository($this->db);
    }

    protected function tearDown(): void
    {
        CitaRepository::$observadorTrasBloqueoAgenda = null;
        $this->db->query('DROP TRIGGER IF EXISTS r5_falla_evento');
        foreach ($this->citas as $id) {
            $this->db->query("DELETE FROM agenda_eventos WHERE citaId = $id");
            $this->db->query("DELETE FROM citasservicios WHERE citaId = $id");
            $this->db->query("DELETE FROM citas WHERE id = $id");
        }
        $this->db->query("DELETE FROM profesionales WHERE id IN ({$this->prof}, {$this->profAjeno})");
        $this->db->query("DELETE FROM servicios WHERE id = {$this->servicio}");
        foreach ($this->usuarios as $id) { $this->db->query("DELETE FROM usuarios WHERE id = $id"); }
        $this->db->close();
    }

    private function servicioA(string $ahora = self::AHORA): CitaService
    {
        return new CitaService($this->repo, new ServicioRepository($this->db), new \DateTimeImmutable($ahora, new \DateTimeZone('America/Guayaquil')));
    }

    private function cita(string $fecha, string $hora, string $estado = 'confirmada', ?int $prof = null): int
    {
        $fin = date('H:i:s', strtotime($hora) + 1800);
        $hora = strlen($hora) === 5 ? $hora . ':00' : $hora;
        $stmt = $this->db->prepare("INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado, estado_pago) VALUES (?, ?, ?, ?, 30, ?, ?, ?, 'pendiente')");
        $u = $this->usuarios[0]; $p = $prof ?? $this->prof;
        $stmt->bind_param('ssssiis', $fecha, $hora, $hora, $fin, $u, $p, $estado);
        $this->assertTrue($stmt->execute());
        $id = $this->db->insert_id;
        $stmt->close();
        $this->citas[] = $id;
        $this->assertTrue((bool)$this->db->query("INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos) VALUES ($id, {$this->servicio}, 'Corte histórico', 20, 30)"));
        return $id;
    }

    private function fila(int $id): array
    {
        return $this->db->query("SELECT * FROM citas WHERE id = $id")->fetch_assoc();
    }

    private function eventos(int $id): array
    {
        return $this->db->query("SELECT * FROM agenda_eventos WHERE citaId = $id AND tipo = 'estado' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }

    /** Aplica una transición como la gestión (agenda.gestionar) salvo que se indique otra cosa. */
    private function mover(int $id, string $estado, string $ahora = self::AHORA, ?int $actor = null, bool $gestiona = true, bool $propia = false): array
    {
        return $this->servicioA($ahora)->actualizarAgenda($id, $actor ?? $this->usuarios[1], $gestiona, $propia, 'estado', ['estado' => $estado]);
    }

    // ------------------------------------------------------------------ regla de adelanto en el servidor

    public function testIniciarExigeLosSesentaMinutosDeAdelantoYRespondeConElMotivo(): void
    {
        $id = $this->cita('2030-01-07', '13:01'); // ahora 12:00 → disponible desde las 12:01
        $r = $this->mover($id, 'en_servicio');
        $this->assertFalse($r['resultado']);
        $this->assertSame(422, $r['httpCode']);
        $this->assertStringContainsString('disponible desde las 12:01', $r['error']);
        $this->assertSame('confirmada', $this->fila($id)['estado']);
        $this->assertCount(0, $this->eventos($id), 'un rechazo no escribe nada');
        // Un minuto después de la apertura de la ventana (exactamente 60 min antes) sí.
        $this->assertTrue($this->mover($id, 'en_servicio', '2030-01-07 12:01:00')['resultado']);
        $this->assertSame('en_servicio', $this->fila($id)['estado']);
    }

    public function testFronteraExactaDe60MinutosYUnSegundoAntes(): void
    {
        $id = $this->cita('2030-01-07', '13:00');
        $this->assertSame(422, $this->mover($id, 'en_servicio', '2030-01-07 11:59:59')['httpCode']);
        $this->assertTrue($this->mover($id, 'en_servicio', '2030-01-07 12:00:00')['resultado'], 'exactamente 60 minutos antes');
    }

    public function testLaVentanaCierraAlTerminarElDiaYUnaCitaDeMadrugadaSeAbreLaVispera(): void
    {
        $ayer = $this->cita('2030-01-06', '23:30');
        // Decisión del usuario: una cita de un día anterior NO se inicia de forma ordinaria (se reprograma o se regulariza).
        $r = $this->mover($ayer, 'en_servicio', '2030-01-07 00:00:00');
        $this->assertFalse($r['resultado']);
        $this->assertSame(422, $r['httpCode']);
        $this->assertStringContainsString('reprográmala', $r['error']);
        $this->assertSame('confirmada', $this->fila($ayer)['estado']);
        $this->assertNull($this->fila($ayer)['atencion_inicio_at']);
        $this->assertSame([], $this->eventos($ayer), 'el rechazo no escribe nada');
        // Hasta el último segundo del día de la cita sí.
        $mismoDia = $this->cita('2030-01-07', '23:30');
        $this->assertTrue($this->mover($mismoDia, 'en_servicio', '2030-01-07 23:59:59')['resultado']);

        $madrugada = $this->cita('2030-01-08', '00:30');
        $this->assertSame(422, $this->mover($madrugada, 'en_servicio', '2030-01-07 23:29:59')['httpCode']);
        $this->assertTrue($this->mover($madrugada, 'en_servicio', '2030-01-07 23:30:00')['resultado'], 'la víspera, 60 min antes');
    }

    public function testElRelojSeEvaluaEnHoraDeGuayaquilNoEnLaDelProceso(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $anterior = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo'); // el proceso corre en otra zona: no debe afectar la regla
        try {
            $this->assertTrue($this->mover($id, 'en_servicio', '2030-01-07 10:00:00')['resultado']);
            $e = $this->eventos($id)[0];
            $this->assertSame('2030-01-07 10:00:00', $e['creado_en'], 'la hora guardada es la del negocio');
            $this->assertSame('2030-01-07 10:00:00', $this->fila($id)['atencion_inicio_at']);
        } finally { date_default_timezone_set($anterior); }
    }

    public function testRegistrarAusenciaSoloDesdeLaHoraDeLaCita(): void
    {
        $id = $this->cita('2030-01-07', '13:00');
        $this->assertSame(422, $this->mover($id, 'no_asistio')['httpCode']);
        $this->assertTrue($this->mover($id, 'no_asistio', '2030-01-07 13:00:00')['resultado']);
        $this->assertSame('no_asistio', $this->fila($id)['estado']);
    }

    public function testFinalizarNoDependeDelRelojAunqueSeHayaIniciadoAntesDeLaHora(): void
    {
        $id = $this->cita('2030-01-07', '13:00');
        $this->assertTrue($this->mover($id, 'en_servicio', '2030-01-07 12:10:00')['resultado']);
        $this->assertTrue($this->mover($id, 'completada', '2030-01-07 12:12:00')['resultado'], 'iniciada 50 min antes de la hora y finalizada antes de ella');
    }

    // ------------------------------------------------------------------ trazabilidad y estados

    public function testCadaTransicionDejaActorHoraEstadoAnteriorYNuevo(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $this->assertTrue($this->mover($id, 'en_servicio', '2030-01-07 11:05:00', $this->usuarios[1])['resultado']);
        $this->assertTrue($this->mover($id, 'completada', '2030-01-07 11:40:00', $this->usuarios[2], false, true)['resultado']);
        $f = $this->fila($id);
        $this->assertSame('completada', $f['estado']);
        $this->assertSame('2030-01-07 11:05:00', $f['atencion_inicio_at']);
        $this->assertSame((string)$this->usuarios[1], $f['atencion_inicio_por']);
        $this->assertSame('2030-01-07 11:40:00', $f['atencion_fin_at']);
        $this->assertSame((string)$this->usuarios[2], $f['atencion_fin_por']);
        $this->assertNull($f['ausencia_at']);
        $this->assertSame('pendiente', $f['estado_pago'], 'finalizar no toca el estado financiero');
        $ev = $this->eventos($id);
        $this->assertCount(2, $ev);
        $this->assertSame([(string)$this->usuarios[1], (string)$this->usuarios[2]], array_column($ev, 'actorId'));
        $this->assertSame(['2030-01-07 11:05:00', '2030-01-07 11:40:00'], array_column($ev, 'creado_en'));
        $this->assertSame('confirmada', json_decode($ev[0]['antes_json'], true)['estado']);
        $this->assertSame('en_servicio', json_decode($ev[0]['despues_json'], true)['estado']);
        $this->assertSame('iniciar', json_decode($ev[0]['despues_json'], true)['accion']);
        $this->assertSame('gestion', json_decode($ev[0]['despues_json'], true)['origen']);
        $this->assertSame('en_servicio', json_decode($ev[1]['antes_json'], true)['estado']);
        $this->assertSame('completada', json_decode($ev[1]['despues_json'], true)['estado']);
        $this->assertSame('profesional_propio', json_decode($ev[1]['despues_json'], true)['origen']);
    }

    public function testLaAusenciaDejaQuienYCuando(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $this->assertTrue($this->mover($id, 'no_asistio', '2030-01-07 11:20:00', $this->usuarios[1])['resultado']);
        $f = $this->fila($id);
        $this->assertSame(['no_asistio', '2030-01-07 11:20:00', (string)$this->usuarios[1]], [$f['estado'], $f['ausencia_at'], $f['ausencia_por']]);
        $this->assertNull($f['atencion_inicio_at']);
        $this->assertNull($f['atencion_fin_at']);
    }

    public function testMatrizCompletaDeTransiciones(): void
    {
        $estados = ['confirmada', 'en_servicio', 'completada', 'no_asistio', 'cancelada'];
        $validas = ['confirmada>en_servicio', 'confirmada>no_asistio', 'en_servicio>completada'];
        foreach ($estados as $desde) {
            foreach (['en_servicio', 'completada', 'no_asistio'] as $hacia) {
                $id = $this->cita('2030-01-07', '11:00', $desde);
                $r = $this->mover($id, $hacia, '2030-01-07 11:30:00');
                $clave = "$desde>$hacia";
                if ($desde === $hacia) {
                    $this->assertTrue($r['resultado'], $clave);
                    $this->assertTrue($r['idempotente'], $clave);
                    $this->assertCount(0, $this->eventos($id), "$clave: repetir un estado no escribe");
                } elseif (in_array($clave, $validas, true)) {
                    $this->assertTrue($r['resultado'], $clave);
                    $this->assertSame($hacia, $this->fila($id)['estado'], $clave);
                    $this->assertCount(1, $this->eventos($id), $clave);
                } else {
                    $this->assertFalse($r['resultado'], $clave);
                    $this->assertSame(409, $r['httpCode'], $clave);
                    $this->assertSame($desde, $this->fila($id)['estado'], "$clave: el estado no cambia");
                    $this->assertSame($desde, $r['estado_actual'], "$clave: informa el estado vigente");
                    $this->assertCount(0, $this->eventos($id), $clave);
                }
            }
        }
    }

    public function testEstadoDesconocidoYOperacionInvalidaSeRechazan(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        foreach (['', 'cancelada', 'confirmada', 'pagada', "en_servicio'; DROP TABLE citas;--"] as $malo) {
            $this->assertSame(422, $this->mover($id, $malo)['httpCode'], $malo);
        }
        $this->assertSame('confirmada', $this->fila($id)['estado']);
    }

    public function testLosMensajesDelConflictoNombranElEstadoVigente(): void
    {
        $id = $this->cita('2030-01-07', '11:00', 'completada');
        $r = $this->mover($id, 'en_servicio');
        $this->assertSame(409, $r['httpCode']);
        $this->assertStringContainsString('«atendida»', $r['error']);
        $this->assertStringContainsString('registro histórico', $r['error']);
    }

    // ------------------------------------------------------------------ idempotencia, terminales y trigger

    public function testRepetirLaTransicionNoEscribeNiCambiaLaHoraRegistrada(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $this->assertFalse($this->mover($id, 'en_servicio', '2030-01-07 11:05:00')['idempotente']);
        $r = $this->mover($id, 'en_servicio', '2030-01-07 11:45:00', $this->usuarios[2], false, true);
        $this->assertTrue($r['resultado']);
        $this->assertTrue($r['idempotente']);
        $this->assertSame('Estado ya actualizado.', $r['mensaje']);
        $f = $this->fila($id);
        $this->assertSame(['2030-01-07 11:05:00', (string)$this->usuarios[1]], [$f['atencion_inicio_at'], $f['atencion_inicio_por']], 'ni la hora ni el actor se pisan');
        $this->assertCount(1, $this->eventos($id));
    }

    public function testUnaCitaCompletadaOConAusenciaNuncaVuelveAtrasNiPorSqlDirecto(): void
    {
        foreach (['completada', 'no_asistio'] as $terminal) {
            $id = $this->cita('2030-01-07', '11:00', $terminal);
            foreach (['confirmada', 'en_servicio', $terminal === 'completada' ? 'no_asistio' : 'completada', 'cancelada'] as $destino) {
                $r = $this->mover($id, $destino);
                $this->assertFalse($r['resultado'], "$terminal>$destino");
                try {
                    $ok = $this->db->query("UPDATE citas SET estado = '$destino' WHERE id = $id");
                    $this->assertFalse($ok, "El trigger debía impedir $terminal>$destino por SQL directo");
                    $mensaje = $this->db->error;
                } catch (\mysqli_sql_exception $e) {
                    $mensaje = $e->getMessage();
                }
                $this->assertStringContainsString('historica', $mensaje);
                $this->assertSame($terminal, $this->fila($id)['estado']);
            }
            // Cambiar otros datos de una cita histórica (p. ej. el estado de pago) sigue siendo posible: solo el estado es inmutable.
            $this->assertTrue((bool)$this->db->query("UPDATE citas SET estado_pago = 'pagado' WHERE id = $id"));
        }
    }

    // ------------------------------------------------------------------ atomicidad

    public function testSiFallaLaAuditoriaLaTransicionSeRevierteCompleta(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $this->assertTrue($this->db->query("CREATE TRIGGER r5_falla_evento BEFORE INSERT ON agenda_eventos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo simulado'"));
        $r = $this->mover($id, 'en_servicio', '2030-01-07 11:05:00');
        $this->assertFalse($r['resultado']);
        $this->assertSame(500, $r['httpCode']);
        $f = $this->fila($id);
        $this->assertSame('confirmada', $f['estado']);
        $this->assertNull($f['atencion_inicio_at'], 'ni el estado ni las columnas de trazabilidad quedan a medias');
        $this->assertNull($f['atencion_inicio_por']);
        $this->db->query('DROP TRIGGER r5_falla_evento');
        $this->assertTrue($this->mover($id, 'en_servicio', '2030-01-07 11:06:00')['resultado'], 'y se puede reintentar');
    }

    // ------------------------------------------------------------------ permisos

    public function testPermisosPorRolEnElServidor(): void
    {
        $propia = $this->cita('2030-01-07', '11:00');
        $ajena = $this->cita('2030-01-07', '11:00', 'confirmada', $this->profAjeno);
        // gestión: cualquier cita
        $this->assertTrue($this->mover($ajena, 'en_servicio', self::AHORA, $this->usuarios[1], true, false)['resultado']);
        // profesional propio: solo la suya
        $this->assertTrue($this->mover($propia, 'en_servicio', self::AHORA, $this->usuarios[2], false, true)['resultado']);
        $this->assertSame(403, $this->mover($this->cita('2030-01-07', '11:00', 'confirmada', $this->profAjeno), 'en_servicio', self::AHORA, $this->usuarios[2], false, true)['httpCode']);
        // el titular de la cita (cliente) no cambia estados de atención
        $otra = $this->cita('2030-01-07', '11:00');
        $this->assertSame(403, $this->mover($otra, 'en_servicio', self::AHORA, $this->usuarios[0], false, false)['httpCode']);
        // una persona sin permisos, y un profesional al que se le negó la agenda propia (permiso false)
        $this->assertSame(403, $this->mover($otra, 'en_servicio', self::AHORA, $this->usuarios[4], false, false)['httpCode']);
        $this->assertSame(403, $this->mover($otra, 'en_servicio', self::AHORA, $this->usuarios[2], false, false)['httpCode']);
        $this->assertSame('confirmada', $this->fila($otra)['estado']);
        $this->assertCount(0, $this->eventos($otra));
        // sin sesión / id inválido
        $this->assertSame(401, $this->servicioA()->actualizarAgenda($otra, 0, true, false, 'estado', ['estado' => 'en_servicio'])['httpCode']);
        $this->assertSame(422, $this->servicioA()->actualizarAgenda('abc', $this->usuarios[1], true, false, 'estado', ['estado' => 'en_servicio'])['httpCode']);
        $this->assertSame(404, $this->servicioA()->actualizarAgenda(2147483000, $this->usuarios[1], true, false, 'estado', ['estado' => 'en_servicio'])['httpCode']);
    }

    // ------------------------------------------------------------------ presentación: acciones, detalle y alcance

    private function permisos(string $rol): array
    {
        return match ($rol) {
            'propietario', 'gerente' => ['gestiona' => true, 'propia' => false, 'finanzas' => true, 'regularizar' => true, 'solicitar' => false],
            'recepcion' => ['gestiona' => true, 'propia' => false, 'finanzas' => false],
            'profesional' => ['gestiona' => false, 'propia' => true, 'finanzas' => false, 'solicitar' => true],
            'finanzas' => ['gestiona' => false, 'propia' => false, 'finanzas' => true],
            default => ['gestiona' => false, 'propia' => false, 'finanzas' => false],
        };
    }

    public function testLasAccionesDeLaTarjetaDependenDelEstadoElRelojYLosPermisos(): void
    {
        $ahora = new \DateTimeImmutable(self::AHORA, new \DateTimeZone('America/Guayaquil'));
        $cita = static fn(string $estado, string $hora, string $pago = 'pendiente') => ['id' => 1, 'estado' => $estado, 'fecha' => '2030-01-07', 'hora' => $hora, 'hora_inicio' => $hora, 'estado_pago' => $pago];
        $claves = static fn(array $a) => array_map(static fn($x) => $x['clave'] . ($x['habilitada'] ? '' : '(off)'), $a['operar']);
        // confirmada a las 15:00 (ahora 12:00): iniciar y ausencia deshabilitados con su motivo
        $a = AgendaAtencionService::accionesDe($cita('confirmada', '15:00:00'), $ahora, $this->permisos('recepcion'));
        $this->assertSame(['iniciar(off)', 'ausencia(off)'], $claves($a));
        $this->assertSame('Disponible desde las 14:00', $a['operar'][0]['motivo']);
        $this->assertSame('Disponible desde las 15:00', $a['operar'][1]['motivo']);
        $this->assertTrue($a['reprogramar']);
        // confirmada a las 11:00 (ya comenzó): habilitadas; sin reprogramar
        $a = AgendaAtencionService::accionesDe($cita('confirmada', '11:00:00'), $ahora, $this->permisos('profesional'));
        $this->assertSame(['iniciar', 'ausencia'], $claves($a));
        $this->assertFalse($a['reprogramar']);
        $this->assertTrue($a['operar'][1]['confirmar'], 'la ausencia pide confirmación');
        // en servicio: finalizar para todos los que operan; «finalizar y cobrar» solo agenda.gestionar + finanzas.gestionar
        foreach (['propietario' => true, 'gerente' => true, 'recepcion' => false, 'profesional' => false] as $rol => $cobra) {
            $a = AgendaAtencionService::accionesDe($cita('en_servicio', '11:00:00'), $ahora, $this->permisos($rol));
            $this->assertSame($cobra ? ['finalizar', 'finalizar_cobrar'] : ['finalizar'], $claves($a), $rol);
        }
        // finanzas sin agenda y alguien sin permisos: ninguna acción de atención
        $this->assertSame([], AgendaAtencionService::accionesDe($cita('en_servicio', '11:00:00'), $ahora, $this->permisos('finanzas'))['operar']);
        $this->assertSame(['operar' => [], 'finanzas' => [], 'reprogramar' => false, 'pendiente_revision' => false, 'regularizar' => false, 'solicitar' => false, 'solicitud_abierta' => 0],
            AgendaAtencionService::accionesDe($cita('confirmada', '11:00:00'), $ahora, $this->permisos('cliente')));
        // terminales: sin acciones de atención
        foreach (['completada', 'no_asistio', 'cancelada'] as $t) {
            $this->assertSame([], AgendaAtencionService::accionesDe($cita($t, '11:00:00'), $ahora, $this->permisos('propietario'))['operar'], $t);
        }
        // enlaces financieros solo con finanzas.gestionar y según el estado de pago
        $f = static fn(array $a) => array_column($a['finanzas'], 'clave');
        $this->assertSame(['cobrar'], $f(AgendaAtencionService::accionesDe($cita('completada', '11:00:00', 'pendiente'), $ahora, $this->permisos('gerente'))));
        $this->assertSame(['cobrar', 'comprobante'], $f(AgendaAtencionService::accionesDe($cita('completada', '11:00:00', 'parcial'), $ahora, $this->permisos('gerente'))));
        $this->assertSame(['pagos', 'comprobante'], $f(AgendaAtencionService::accionesDe($cita('completada', '11:00:00', 'pagado'), $ahora, $this->permisos('finanzas'))));
        $this->assertSame([], $f(AgendaAtencionService::accionesDe($cita('completada', '11:00:00', 'pagado'), $ahora, $this->permisos('recepcion'))));
        $this->assertSame([], $f(AgendaAtencionService::accionesDe($cita('confirmada', '11:00:00', 'pendiente'), $ahora, $this->permisos('gerente'))));
    }

    public function testElDetalleTraePreciosHistoricosEnCentavosSoloConFinanzasYRespetaElAlcance(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $this->db->query("INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos) VALUES ($id, {$this->servicio}, 'Tinte', 15.50, 45)");
        $this->db->query("UPDATE servicios SET precio = 999 WHERE id = {$this->servicio}");
        $this->assertTrue($this->mover($id, 'en_servicio', '2030-01-07 11:05:00')['resultado']);
        $svc = new AgendaAtencionService($this->repo, new \DateTimeImmutable(self::AHORA, new \DateTimeZone('America/Guayaquil')));

        $conFin = $svc->detalle($id, $this->usuarios[1], $this->permisos('gerente'));
        $this->assertSame(3550, $conFin['total_centavos']);
        $this->assertSame([2000, 1550], array_column($conFin['servicios'], 'precio_centavos'));
        $this->assertSame('pendiente', $conFin['estado_pago']);
        $this->assertSame('$35.50', AgendaAtencionService::dinero($conFin['total_centavos']));
        $this->assertSame('Titular R5', $conFin['cliente']);
        $this->assertSame('R5 propio', $conFin['profesional_nombre']);
        $this->assertSame('Inició la atención', $conFin['historial'][0]['descripcion']);
        $this->assertSame('Gestora R5', $conFin['historial'][0]['actor']);

        $sinFin = $svc->detalle($id, $this->usuarios[1], $this->permisos('recepcion'));
        $this->assertArrayNotHasKey('total_centavos', $sinFin);
        $this->assertArrayNotHasKey('estado_pago', $sinFin);
        foreach ($sinFin['servicios'] as $s) { $this->assertArrayNotHasKey('precio_servicio', $s); $this->assertArrayNotHasKey('precio_centavos', $s); }

        // Alcance: el profesional propio la ve; el ajeno no (null, igual que si no existiera).
        $this->assertNotNull($svc->detalle($id, $this->usuarios[2], $this->permisos('profesional')));
        $this->assertNull($svc->detalle($id, $this->usuarios[3], $this->permisos('profesional')));
        $this->assertNull($svc->detalle(2147483000, $this->usuarios[3], $this->permisos('profesional')));
    }

    public function testListarFiltraPorEstadoYOcultaElPagoSinFinanzas(): void
    {
        $a = $this->cita('2030-01-07', '09:00', 'confirmada');
        $b = $this->cita('2030-01-07', '10:00', 'completada');
        $svc = new AgendaAtencionService($this->repo, new \DateTimeImmutable(self::AHORA, new \DateTimeZone('America/Guayaquil')));
        $todas = $svc->listar('2030-01-07', '2030-01-07', $this->usuarios[1], $this->permisos('propietario'));
        $this->assertEqualsCanonicalizing([$a, $b], array_map(fn($c) => (int)$c['id'], array_filter($todas, fn($c) => in_array((int)$c['id'], [$a, $b], true))));
        $this->assertArrayHasKey('estado_pago', $todas[0]);
        $soloCompletadas = $svc->listar('2030-01-07', '2030-01-07', $this->usuarios[1], $this->permisos('propietario'), 'completada');
        $this->assertContains($b, array_map(fn($c) => (int)$c['id'], $soloCompletadas));
        $this->assertNotContains($a, array_map(fn($c) => (int)$c['id'], $soloCompletadas));
        $sinPago = $svc->listar('2030-01-07', '2030-01-07', $this->usuarios[2], $this->permisos('profesional'));
        $this->assertArrayNotHasKey('estado_pago', $sinPago[0]);
        $this->assertSame([], $svc->listar('2030-01-07', '2030-01-07', $this->usuarios[3], $this->permisos('profesional')), 'el profesional ajeno no ve citas de otro');
        $this->assertSame('completada', AgendaAtencionService::estadoFiltro('completada'));
        $this->assertNull(AgendaAtencionService::estadoFiltro('<x>'));
        $this->assertNull(AgendaAtencionService::estadoFiltro(['completada']));
    }

    // ------------------------------------------------------------------ concurrencia real (procesos PHP)

    /** @param array<int,array{actor:int,estado:string,gestiona:bool,propia:bool,ahora?:string}> $trabajos */
    private function correrEnParalelo(int $cita, array $trabajos, int $demoraMs = 400): array
    {
        $procesos = [];
        foreach ($trabajos as $t) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_atencion_worker.php', (string)$cita, (string)$t['actor'], $t['estado'], $t['gestiona'] ? '1' : '0', $t['propia'] ? '1' : '0', $t['ahora'] ?? '2030-01-07 11:30:00', (string)$demoraMs],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($p);
            stream_set_timeout($pipes[1], 20);
            $this->assertSame("READY\n", fgets($pipes[1]), 'worker listo antes de la barrera');
            $procesos[] = [$p, $pipes];
        }
        foreach ($procesos as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        $res = [];
        foreach ($procesos as [$p, $pipes]) {
            $linea = fgets($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($p), 'worker terminó con error: ' . $err);
            $j = json_decode((string)$linea, true);
            $this->assertIsArray($j, 'salida del worker: ' . $linea . $err);
            $res[] = $j;
        }
        return $res;
    }

    public function testTresProcesosQueIniciaranLaMismaCitaProducenUnaSolaTransicionYUnSoloEvento(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $trabajo = ['actor' => $this->usuarios[1], 'estado' => 'en_servicio', 'gestiona' => true, 'propia' => false];
        $res = $this->correrEnParalelo($id, [$trabajo, $trabajo, $trabajo]);
        $this->assertSame([true, true, true], array_column($res, 'resultado'), json_encode($res));
        $this->assertSame(1, count(array_filter(array_column($res, 'idempotente'), fn($x) => $x === false)), 'exactamente uno ejecutó la transición');
        $this->assertSame(2, count(array_filter(array_column($res, 'idempotente'), fn($x) => $x === true)), 'los otros recibieron «ya actualizado»');
        $this->assertCount(1, $this->eventos($id), 'un único evento');
        $this->assertSame('en_servicio', $this->fila($id)['estado']);
    }

    public function testIniciarYRegistrarAusenciaALaVezGanaUnoYElOtroRecibeElEstadoVigente(): void
    {
        $id = $this->cita('2030-01-07', '11:00');
        $res = $this->correrEnParalelo($id, [
            ['actor' => $this->usuarios[1], 'estado' => 'en_servicio', 'gestiona' => true, 'propia' => false],
            ['actor' => $this->usuarios[1], 'estado' => 'no_asistio', 'gestiona' => true, 'propia' => false],
        ]);
        $ganadores = array_values(array_filter($res, fn($r) => $r['resultado']));
        $perdedores = array_values(array_filter($res, fn($r) => !$r['resultado']));
        $this->assertCount(1, $ganadores, json_encode($res));
        $this->assertCount(1, $perdedores);
        $this->assertSame(409, $perdedores[0]['httpCode']);
        $final = $this->fila($id)['estado'];
        $this->assertSame($ganadores[0]['estado'], $final, 'el estado final es el del ganador');
        $this->assertSame($final, $perdedores[0]['estado_actual'], 'el perdedor recibe el estado vigente');
        $this->assertStringContainsString(mb_strtolower(\Classes\ReglaAtencion::etiqueta($final)), mb_strtolower($perdedores[0]['error']));
        $this->assertCount(1, $this->eventos($id));
    }

    public function testDosProcesosQueFinalizanLaMismaCitaNoDuplicanNiPisanLaHora(): void
    {
        $id = $this->cita('2030-01-07', '11:00', 'en_servicio');
        $t = ['actor' => $this->usuarios[1], 'estado' => 'completada', 'gestiona' => true, 'propia' => false];
        $res = $this->correrEnParalelo($id, [$t, $t]);
        $this->assertSame([true, true], array_column($res, 'resultado'));
        $this->assertCount(1, $this->eventos($id));
        $f = $this->fila($id);
        $this->assertSame('completada', $f['estado']);
        $this->assertSame('2030-01-07 11:30:00', $f['atencion_fin_at']);
    }
}
