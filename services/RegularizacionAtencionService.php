<?php
namespace Services;

use Classes\ConflictoIdempotenciaException;
use Classes\Idempotencia;
use Classes\OperacionEnCursoException;
use Classes\ReglaAtencion;
use Repositories\OperacionRepository;

/**
 * Regularización de la atención de citas de días anteriores (decisión del usuario sobre la ronda 5; ver docs/R5_REGULARIZACION.md).
 *
 *  · «Regularizar atención» es EXCLUSIVO de quien tiene `agenda.regularizar` (propietario y gerente) y solo cuando el servicio ocurrió y
 *    faltó registrarlo: exige motivo y fecha/hora EFECTIVA de atención, nunca futura.
 *  · Un profesional puede SOLICITAR la regularización de sus propias citas (`agenda.solicitar_regularizacion`), nunca aprobarla.
 *    El cliente no interviene (ni permisos de equipo ni portal).
 *  · No retrodata nada: la fecha de la cita no cambia; se guardan por separado la fecha/hora efectiva del servicio y QUIÉN regularizó
 *    y CUÁNDO lo hizo (momento real). Pagos, comprobantes, caja y comisiones posteriores llevan SU fecha real.
 *  · Una cita antigua sin resolver no se marca sola: queda «pendiente de revisión» hasta que alguien decide.
 *  · Cada decisión bloquea la cita (`FOR UPDATE`), compara y cambia el estado de forma atómica, es idempotente por clave de
 *    formulario y deja evento en `agenda_eventos` + fila en `citas_regularizaciones`.
 */
final class RegularizacionAtencionService
{
    public const MOTIVO_MIN = 10;
    public const MOTIVO_MAX = 500;
    public const ESTADOS_REGULARIZABLES = ['confirmada', 'en_servicio'];
    private const SCOPE = 'regularizacion.';

    private OperacionRepository $repo;
    private ?\DateTimeImmutable $ahoraFijo;

    public function __construct(?OperacionRepository $repo = null, ?\DateTimeImmutable $ahora = null)
    {
        $this->repo = $repo ?? new OperacionRepository();
        $this->ahoraFijo = $ahora;
    }

    public function ahora(): \DateTimeImmutable
    {
        $zona = new \DateTimeZone(ReglaAtencion::ZONA);
        return $this->ahoraFijo ? $this->ahoraFijo->setTimezone($zona) : new \DateTimeImmutable('now', $zona);
    }

    // ------------------------------------------------------------------------------------------------ solicitar (profesional)

    /**
     * @param array{cita_id:mixed,motivo:mixed,efectiva:mixed,idem_key:mixed} $datos
     * @return array{repetida:bool,regularizacion_id:int,cita_id:int}
     */
    public function solicitar(int $actor, array $datos): array
    {
        $this->exigirPermiso($actor, 'agenda.solicitar_regularizacion', 'Su cuenta no puede solicitar regularizaciones.');
        $citaId = $this->id($datos['cita_id'] ?? null, 'La cita indicada no es válida.');
        $motivo = $this->motivo($datos['motivo'] ?? null);
        $ahora = $this->ahora();
        $efectiva = $this->efectiva($datos['efectiva'] ?? null, $ahora);
        $clave = $this->clave($datos['idem_key'] ?? null);
        $contenido = ['accion' => 'solicitar', 'cita' => $citaId, 'motivo' => $motivo, 'efectiva' => $efectiva['txt']];
        $r = $this->ejecutar('solicitar', $actor, $clave, $contenido, function () use ($actor, $citaId, $motivo, $efectiva, $ahora): array {
            $cita = $this->citaBloqueada($citaId);
            // Una cita ajena es indistinguible de una inexistente (no se revela a quién pertenece).
            $propia = $cita ? $this->repo->consulta('SELECT 1 FROM profesionales WHERE id=? AND usuarioId=?', 'ii', [(int)$cita['profesionalId'], $actor]) : [];
            if (!$cita || !$propia) { throw new AtencionRechazada('No encontramos esa cita en tu agenda.', 404); }
            $this->exigirRegularizable($cita, $ahora);
            if ($efectiva['dt'] < $this->inicioDelDia((string)$cita['fecha'])) { throw new AtencionRechazada('La fecha efectiva no puede ser anterior al día de la cita (' . $cita['fecha'] . ').', 422); }
            if ($this->repo->consulta("SELECT id FROM citas_regularizaciones WHERE cita_id=? AND estado='solicitada' FOR UPDATE", 'i', [$citaId])) {
                throw new AtencionRechazada('Esta cita ya tiene una solicitud de regularización pendiente de decisión.', 409);
            }
            $ahoraTxt = $ahora->format('Y-m-d H:i:s');
            $this->repo->consulta("INSERT INTO citas_regularizaciones (cita_id,estado,solicitada_por,solicitada_at,efectiva_propuesta_at,motivo_solicitud) VALUES (?,'solicitada',?,?,?,?)",
                'iisss', [$citaId, $actor, $ahoraTxt, $efectiva['txt'], $motivo]);
            $id = (int)$this->repo->db->insert_id;
            $this->evento($citaId, $actor, 'regularizacion_solicitud', ['estado' => $cita['estado']], ['solicitud_id' => $id, 'atencion_efectiva_propuesta' => $efectiva['txt'], 'motivo' => $motivo], $ahoraTxt);
            return ['recurso_tipo' => 'regularizacion', 'recurso_id' => $id, 'datos' => ['cita_id' => $citaId]];
        });
        return ['repetida' => $r['repetida'], 'regularizacion_id' => $r['recurso_id'], 'cita_id' => (int)($r['datos']['cita_id'] ?? $citaId)];
    }

    // ------------------------------------------------------------------------------------------------ regularizar (propietario / gerente)

    /**
     * @param array{cita_id:mixed,motivo:mixed,efectiva:mixed,idem_key:mixed} $datos
     * @return array{repetida:bool,regularizacion_id:int,cita_id:int,atencion_efectiva_at:string,regularizada_at:string}
     */
    public function regularizar(int $actor, array $datos): array
    {
        $this->exigirPermiso($actor, 'agenda.regularizar', 'Solo el propietario o el gerente pueden regularizar la atención.');
        $citaId = $this->id($datos['cita_id'] ?? null, 'La cita indicada no es válida.');
        $motivo = $this->motivo($datos['motivo'] ?? null);
        $ahora = $this->ahora();
        $efectiva = $this->efectiva($datos['efectiva'] ?? null, $ahora);
        $clave = $this->clave($datos['idem_key'] ?? null);
        $contenido = ['accion' => 'regularizar', 'cita' => $citaId, 'motivo' => $motivo, 'efectiva' => $efectiva['txt']];
        $r = $this->ejecutar('regularizar', $actor, $clave, $contenido, function () use ($actor, $citaId, $motivo, $efectiva, $ahora): array {
            $cita = $this->citaBloqueada($citaId);
            if (!$cita) { throw new AtencionRechazada('La cita no existe.', 404); }
            $this->exigirRegularizable($cita, $ahora);
            if ($efectiva['dt'] < $this->inicioDelDia((string)$cita['fecha'])) { throw new AtencionRechazada('La fecha efectiva no puede ser anterior al día de la cita (' . $cita['fecha'] . ').', 422); }
            $ahoraTxt = $ahora->format('Y-m-d H:i:s');
            // Compare-and-set: además del bloqueo, la escritura exige que el estado siga siendo el leído.
            $this->repo->consulta('UPDATE citas SET estado=\'completada\', atencion_efectiva_at=?, regularizada_por=?, regularizada_at=? WHERE id=? AND estado=?',
                'sisis', [$efectiva['txt'], $actor, $ahoraTxt, $citaId, $cita['estado']]);
            if ($this->repo->db->affected_rows !== 1) { throw new AtencionRechazada('La cita cambió mientras se procesaba. Ábrala de nuevo para ver su estado actual.', 409); }
            // Si había una solicitud abierta, esta decisión la resuelve; si no, se registra la regularización directa.
            $abierta = $this->repo->consulta("SELECT id FROM citas_regularizaciones WHERE cita_id=? AND estado='solicitada' FOR UPDATE", 'i', [$citaId])[0] ?? null;
            if ($abierta) {
                $regId = (int)$abierta['id'];
                $this->repo->consulta("UPDATE citas_regularizaciones SET estado='aprobada', resuelta_por=?, resuelta_at=?, atencion_efectiva_at=?, motivo_resolucion=? WHERE id=? AND estado='solicitada'",
                    'isssi', [$actor, $ahoraTxt, $efectiva['txt'], $motivo, $regId]);
            } else {
                $this->repo->consulta("INSERT INTO citas_regularizaciones (cita_id,estado,resuelta_por,resuelta_at,atencion_efectiva_at,motivo_resolucion) VALUES (?,'aprobada',?,?,?,?)",
                    'iisss', [$citaId, $actor, $ahoraTxt, $efectiva['txt'], $motivo]);
                $regId = (int)$this->repo->db->insert_id;
            }
            $this->evento($citaId, $actor, 'regularizar', ['estado' => $cita['estado'], 'fecha' => $cita['fecha']],
                ['estado' => 'completada', 'accion' => 'regularizar', 'regularizacion_id' => $regId, 'atencion_efectiva_at' => $efectiva['txt'], 'motivo' => $motivo], $ahoraTxt);
            return ['recurso_tipo' => 'regularizacion', 'recurso_id' => $regId, 'datos' => ['cita_id' => $citaId, 'atencion_efectiva_at' => $efectiva['txt'], 'regularizada_at' => $ahoraTxt]];
        });
        return ['repetida' => $r['repetida'], 'regularizacion_id' => $r['recurso_id'], 'cita_id' => $citaId,
            'atencion_efectiva_at' => (string)($r['datos']['atencion_efectiva_at'] ?? ''), 'regularizada_at' => (string)($r['datos']['regularizada_at'] ?? '')];
    }

    // ------------------------------------------------------------------------------------------------ rechazar (propietario / gerente)

    /**
     * @param array{regularizacion_id:mixed,motivo:mixed,idem_key:mixed} $datos
     * @return array{repetida:bool,regularizacion_id:int,cita_id:int}
     */
    public function rechazar(int $actor, array $datos): array
    {
        $this->exigirPermiso($actor, 'agenda.regularizar', 'Solo el propietario o el gerente pueden decidir una solicitud de regularización.');
        $regId = $this->id($datos['regularizacion_id'] ?? null, 'La solicitud indicada no es válida.');
        $motivo = $this->motivo($datos['motivo'] ?? null);
        $clave = $this->clave($datos['idem_key'] ?? null);
        $ahora = $this->ahora();
        $r = $this->ejecutar('rechazar', $actor, $clave, ['accion' => 'rechazar', 'solicitud' => $regId, 'motivo' => $motivo], function () use ($actor, $regId, $motivo, $ahora): array {
            $sol = $this->repo->consulta('SELECT cita_id FROM citas_regularizaciones WHERE id=?', 'i', [$regId])[0] ?? null;
            if (!$sol) { throw new AtencionRechazada('La solicitud no existe.', 404); }
            $citaId = (int)$sol['cita_id'];
            $cita = $this->citaBloqueada($citaId); // orden único: cita → solicitud
            $fila = $this->repo->consulta('SELECT * FROM citas_regularizaciones WHERE id=? FOR UPDATE', 'i', [$regId])[0] ?? null;
            if (!$fila || !$cita) { throw new AtencionRechazada('La solicitud no existe.', 404); }
            if ($fila['estado'] !== 'solicitada') { throw new AtencionRechazada('Esta solicitud ya fue resuelta (' . ($fila['estado'] === 'aprobada' ? 'aprobada' : 'rechazada') . ').', 409); }
            $ahoraTxt = $ahora->format('Y-m-d H:i:s');
            $this->repo->consulta("UPDATE citas_regularizaciones SET estado='rechazada', resuelta_por=?, resuelta_at=?, motivo_resolucion=? WHERE id=? AND estado='solicitada'",
                'issi', [$actor, $ahoraTxt, $motivo, $regId]);
            if ($this->repo->db->affected_rows !== 1) { throw new AtencionRechazada('La solicitud cambió mientras se procesaba.', 409); }
            $this->evento($citaId, $actor, 'regularizacion_rechazo', ['estado' => $cita['estado']], ['regularizacion_id' => $regId, 'motivo' => $motivo], $ahoraTxt);
            return ['recurso_tipo' => 'regularizacion', 'recurso_id' => $regId, 'datos' => ['cita_id' => $citaId]];
        });
        return ['repetida' => $r['repetida'], 'regularizacion_id' => $regId, 'cita_id' => (int)($r['datos']['cita_id'] ?? 0)];
    }

    // ------------------------------------------------------------------------------------------------ lectura

    /**
     * Solicitudes y decisiones de una cita (la más reciente primero), con nombres de las cuentas.
     * @return array<int,array<string,mixed>>
     */
    public function deCita(int $citaId): array
    {
        return $this->repo->consulta(
            "SELECT r.*, CONCAT(us.nombre,' ',us.apellido) solicitada_por_nombre, CONCAT(ur.nombre,' ',ur.apellido) resuelta_por_nombre
             FROM citas_regularizaciones r LEFT JOIN usuarios us ON us.id=r.solicitada_por LEFT JOIN usuarios ur ON ur.id=r.resuelta_por
             WHERE r.cita_id=? ORDER BY r.id DESC", 'i', [$citaId]);
    }

    // ------------------------------------------------------------------------------------------------ núcleo

    /** @return array<string,mixed>|null */
    private function citaBloqueada(int $citaId): ?array
    {
        return $this->repo->consulta('SELECT id,fecha,hora,hora_inicio,estado,profesionalId,usuarioId FROM citas WHERE id=? FOR UPDATE', 'i', [$citaId])[0] ?? null;
    }

    /** La cita debe estar sin resolver (confirmada o en servicio) y su día debe haber pasado. */
    private function exigirRegularizable(array $cita, \DateTimeImmutable $ahora): void
    {
        $estado = (string)$cita['estado'];
        if ($estado === 'completada') {
            $quien = $this->repo->consulta("SELECT CONCAT(u.nombre,' ',u.apellido) n FROM citas c LEFT JOIN usuarios u ON u.id=c.regularizada_por WHERE c.id=? AND c.regularizada_at IS NOT NULL", 'i', [(int)$cita['id']])[0]['n'] ?? null;
            throw new AtencionRechazada($quien ? 'Esta atención ya fue regularizada por ' . $quien . '.' : 'Esta cita ya está atendida.', 409);
        }
        if (!in_array($estado, self::ESTADOS_REGULARIZABLES, true)) {
            throw new AtencionRechazada('La cita está «' . mb_strtolower(ReglaAtencion::etiqueta($estado)) . '» y es un registro histórico: no se regulariza.', 409);
        }
        if (!ReglaAtencion::esPendienteRevision($estado, (string)$cita['fecha'], $ahora)) {
            throw new AtencionRechazada('Solo se regulariza la atención de citas de días anteriores; para la cita de hoy use el flujo ordinario de la agenda.', 422);
        }
    }

    private function inicioDelDia(string $fecha): \DateTimeImmutable
    {
        return new \DateTimeImmutable($fecha . ' 00:00:00', new \DateTimeZone(ReglaAtencion::ZONA));
    }

    private function evento(int $citaId, int $actor, string $tipo, array $antes, array $despues, string $ahoraTxt): void
    {
        $this->repo->consulta('INSERT INTO agenda_eventos (citaId,actorId,tipo,antes_json,despues_json,creado_en) VALUES (?,?,?,?,?,?)', 'iissss',
            [$citaId, $actor, $tipo, json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($despues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $ahoraTxt]);
    }

    /** @return array{repetida:bool,recurso_tipo:string,recurso_id:int,datos:array<string,mixed>} */
    private function ejecutar(string $operacion, int $actor, string $clave, array $contenido, callable $alta): array
    {
        try {
            return Idempotencia::ejecutar($this->repo->db, self::SCOPE . $operacion, Idempotencia::actorUsuario($actor, 'equipo'), $clave, $contenido, static fn() => $alta());
        } catch (ConflictoIdempotenciaException) {
            throw new AtencionRechazada('Esta solicitud ya se había enviado con otros datos. No se cambió nada: abra la cita de nuevo y repita la operación.', 409);
        } catch (OperacionEnCursoException) {
            throw new AtencionRechazada('La misma operación sigue procesándose. Espere unos segundos y revise la cita antes de repetirla.', 409);
        }
    }

    private function exigirPermiso(int $actor, string $permiso, string $mensaje): void
    {
        if ($actor <= 0 || !(new AuthorizationService($this->repo->db))->permite($actor, $permiso)) { throw new AtencionRechazada($mensaje, 403); }
    }

    private function id(mixed $v, string $mensaje): int
    {
        $n = is_int($v) ? $v : (is_string($v) && preg_match('/^[1-9]\d{0,9}$/D', $v) ? (int)$v : 0);
        if ($n < 1) { throw new AtencionRechazada($mensaje, 422); }
        return $n;
    }

    private function clave(mixed $v): string
    {
        if (!Idempotencia::claveValida($v)) { throw new AtencionRechazada('El formulario venció o es inválido. Abra la cita de nuevo; no se registró ningún cambio.', 422); }
        return $v;
    }

    private function motivo(mixed $v): string
    {
        if (!is_string($v)) { throw new AtencionRechazada('Indique el motivo (al menos ' . self::MOTIVO_MIN . ' caracteres).', 422); }
        $t = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if (!mb_check_encoding($t, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $t)) { throw new AtencionRechazada('El motivo contiene caracteres no válidos.', 422); }
        if (mb_strlen($t) < self::MOTIVO_MIN) { throw new AtencionRechazada('Indique el motivo (al menos ' . self::MOTIVO_MIN . ' caracteres).', 422); }
        if (mb_strlen($t) > self::MOTIVO_MAX) { throw new AtencionRechazada('El motivo no puede superar ' . self::MOTIVO_MAX . ' caracteres.', 422); }
        return $t;
    }

    /**
     * Fecha y hora efectiva de la atención («2026-10-12T15:30» de un campo datetime-local, o «2026-10-12 15:30»). Nunca futura.
     * @return array{dt:\DateTimeImmutable,txt:string}
     */
    private function efectiva(mixed $v, \DateTimeImmutable $ahora): array
    {
        if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})$/D', trim($v), $m)) {
            throw new AtencionRechazada('Indique la fecha y la hora en que se atendió (formato AAAA-MM-DD HH:MM).', 422);
        }
        $zona = new \DateTimeZone(ReglaAtencion::ZONA);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $m[1] . ' ' . $m[2], $zona);
        if (!$dt || $dt->format('Y-m-d H:i') !== $m[1] . ' ' . $m[2]) { throw new AtencionRechazada('La fecha u hora de atención no es válida.', 422); }
        if ($dt > $ahora) { throw new AtencionRechazada('La fecha y hora de atención no pueden ser futuras.', 422); }
        return ['dt' => $dt, 'txt' => $dt->format('Y-m-d H:i:00')];
    }
}
