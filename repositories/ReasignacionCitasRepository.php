<?php
namespace Repositories;

use Classes\Transaccion;
use Model\ActiveRecord;
use Model\NotificacionCita;
use mysqli;

/**
 * Persistencia de la revisión y reasignación asistida de citas futuras de un profesional
 * (previa a su baja o suspensión).
 *
 * Garantías:
 *  - la reasignación mueve SOLO `citas.profesionalId`; los snapshots de `citasservicios`, los pagos y los
 *    movimientos de comisión no se tocan;
 *  - antes de escribir se bloquean las filas de profesional (origen y destino, en orden ascendente de id, como
 *    cualquier reserva), la cita y las citas del destino ese día, y se revalida el hueco con la misma lógica
 *    que la reserva real (CitaRepository::validarHuecoEnTransaccion), de modo que dos reasignaciones o una
 *    reserva simultánea sobre el mismo hueco se serializan y solo una gana;
 *  - todo (cita, auditoría, evento de agenda y aviso al cliente en el outbox) ocurre en una sola transacción.
 */
final class ReasignacionCitasRepository
{
    public const ESTADOS_QUE_OCUPAN = ['confirmada', 'en_servicio'];

    /**
     * Punto de observación para pruebas de concurrencia (mismo patrón que Idempotencia::$observadorTrasReserva):
     * se invoca con los bloqueos tomados y el hueco ya validado, antes de escribir, de modo que una prueba puede
     * mantener la transacción abierta y comprobar que otra reasignación o reserva sobre el mismo hueco espera y pierde.
     * @var callable|null
     */
    public static $observadorTrasBloqueos = null;

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión'); }
        $this->db = $conexion;
    }

    public function conexion(): mysqli { return $this->db; }

    private function sql(string $sql, string $tipos = '', array $args = []): \mysqli_stmt
    {
        $s = $this->db->prepare($sql);
        if (!$s) {
            error_log('[ReasignacionCitasRepository] prepare: ' . $this->db->error);
            throw new PersistenceException('No fue posible preparar la operación de reasignación', (int)$this->db->errno);
        }
        if ($tipos !== '') { $s->bind_param($tipos, ...$args); }
        try {
            $ok = $s->execute();
            $errno = (int)$s->errno;
        } catch (\mysqli_sql_exception $e) {
            $ok = false;
            $errno = (int)$e->getCode();
        }
        if (!$ok) {
            $s->close();
            if ($errno === 1062) { throw new DuplicateEntryException('Registro duplicado', $errno); }
            throw new PersistenceException('No fue posible completar la operación de reasignación', $errno);
        }
        return $s;
    }

    private function una(string $sql, string $tipos = '', array $args = []): ?array
    {
        $s = $this->sql($sql, $tipos, $args);
        $f = $s->get_result()->fetch_assoc();
        $s->close();
        return $f ?: null;
    }

    private function todas(string $sql, string $tipos = '', array $args = []): array
    {
        $s = $this->sql($sql, $tipos, $args);
        $f = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        return $f;
    }

    // ------------------------------------------------------------------ Lectura

    /** Ficha profesional vinculada a un colaborador, con su estado laboral. */
    public function fichaDeColaborador(int $colaboradorId): ?array
    {
        return $this->una("SELECT c.id colaborador_id, c.codigo, c.nombre, c.apellido, c.rol, c.estado colaborador_estado, c.usuario_id,
                p.id profesional_id, p.nombre profesional_nombre, p.activo
            FROM colaboradores c JOIN profesionales p ON p.colaborador_id = c.id WHERE c.id = ?", 'i', [$colaboradorId]);
    }

    /**
     * Citas que ocupan agenda (confirmada / en servicio) desde $hoy (AAAA-MM-DD, America/Guayaquil) del profesional.
     * El cliente se devuelve siempre; es el servicio quien lo oculta según los permisos del actor.
     */
    public function citasPendientes(int $profesionalId, string $hoy, bool $bloquear = false): array
    {
        $cierre = $bloquear ? ' FOR UPDATE' : '';
        return $this->todas("SELECT c.id, c.fecha, c.hora, c.hora_inicio, c.hora_fin, c.duracion_total_minutos, c.estado, c.estado_pago,
                c.usuarioId, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) cliente
            FROM citas c LEFT JOIN usuarios u ON u.id = c.usuarioId
            WHERE c.profesionalId = ? AND c.estado IN ('confirmada','en_servicio') AND c.fecha >= ?
            ORDER BY c.fecha, COALESCE(c.hora_inicio, c.hora), c.id LIMIT 500{$cierre}", 'is', [$profesionalId, $hoy]);
    }

    /** @return array<int, array<int, array{servicio_id:int,nombre:string}>> citaId → servicios (snapshot histórico) */
    public function serviciosDeCitas(array $citaIds): array
    {
        $citaIds = array_values(array_unique(array_map('intval', $citaIds)));
        if (!$citaIds) { return []; }
        $marcas = implode(',', array_fill(0, count($citaIds), '?'));
        $filas = $this->todas("SELECT cs.citaId, cs.servicioId, COALESCE(cs.nombre_servicio, s.nombre, 'Servicio') nombre
            FROM citasservicios cs LEFT JOIN servicios s ON s.id = cs.servicioId WHERE cs.citaId IN ({$marcas}) ORDER BY cs.id",
            str_repeat('i', count($citaIds)), $citaIds);
        $salida = [];
        foreach ($filas as $f) {
            $salida[(int)$f['citaId']][] = ['servicio_id' => (int)$f['servicioId'], 'nombre' => (string)$f['nombre']];
        }
        return $salida;
    }

    /**
     * Citas cuyo pago ya devengó comisión (pendiente o liquidada) para el profesional que las atendía.
     * Mover una de ellas dejaría el devengo atribuido a otra persona; se bloquea y se documenta.
     *
     * @return array<int,bool>
     */
    public function citasConComisionDevengada(array $citaIds): array
    {
        $citaIds = array_values(array_unique(array_map('intval', $citaIds)));
        if (!$citaIds) { return []; }
        $marcas = implode(',', array_fill(0, count($citaIds), '?'));
        $filas = $this->todas("SELECT DISTINCT p.cita_referencia_id id
            FROM pagos p JOIN comisiones_movimientos m ON m.pago_id = p.id
            WHERE m.tipo = 'devengo' AND m.estado IN ('pendiente','liquidada') AND p.cita_referencia_id IN ({$marcas})",
            str_repeat('i', count($citaIds)), $citaIds);
        $salida = [];
        foreach ($filas as $f) { $salida[(int)$f['id']] = true; }
        return $salida;
    }

    /** ¿Esta cita ya fue cancelada por la baja de ese profesional (decisión registrada)? */
    public function cancelacionRegistrada(int $citaId, int $profesionalOrigenId): bool
    {
        return $this->una("SELECT 1 x FROM citas_reasignaciones WHERE clave_unica = ? AND accion = 'cancelada' AND profesional_origen_id = ?", 'si', ["cancelada:{$citaId}", $profesionalOrigenId]) !== null;
    }

    public function historial(int $citaId): array
    {
        return $this->todas('SELECT id, accion, profesional_origen_id, profesional_origen_nombre, profesional_destino_id, profesional_destino_nombre,
                actor_id, motivo, snapshot_json, creado_en FROM citas_reasignaciones WHERE cita_referencia_id = ? ORDER BY id', 'i', [$citaId]);
    }

    // ------------------------------------------------------------------ Escritura

    /**
     * Reasigna UNA cita a otro profesional. Debe ejecutarse dentro de la transacción del llamador (o abre la suya).
     * No lanza por una regla de negocio incumplida: devuelve ['ok'=>false,...] SIN haber escrito nada, para que el
     * llamador decida si revierte todo el lote. Los fallos técnicos sí lanzan PersistenceException.
     *
     * @param string $ahoraLocal 'Y-m-d H:i:s' en America/Guayaquil
     * @return array{ok:bool,codigo?:string,error?:string,httpCode?:int,reasignacion_id?:int,notificacion_id?:?int,datos?:array}
     */
    public function reasignarEnTransaccion(int $actor, int $citaId, int $origenId, int $destinoId, string $motivo, string $ahoraLocal): array
    {
        return Transaccion::ejecutar($this->db, function () use ($actor, $citaId, $origenId, $destinoId, $motivo, $ahoraLocal): array {
            $falla = static fn(string $codigo, string $error, int $http = 409): array => ['ok' => false, 'codigo' => $codigo, 'error' => $error, 'httpCode' => $http];
            if ($origenId === $destinoId) { return $falla('mismo_profesional', 'El profesional sustituto no puede ser el mismo.', 422); }

            // 1. Profesionales en orden ascendente de id: igual que cualquier reserva, evita interbloqueos.
            $ids = [$origenId, $destinoId];
            sort($ids);
            $filas = [];
            foreach ($ids as $id) {
                $f = $this->una('SELECT id, nombre, activo FROM profesionales WHERE id = ? FOR UPDATE', 'i', [$id]);
                if ($f) { $filas[(int)$f['id']] = $f; }
            }
            if (!isset($filas[$origenId])) { return $falla('origen_inexistente', 'El profesional de origen no existe.', 404); }
            if (!isset($filas[$destinoId])) { return $falla('destino_inexistente', 'El profesional sustituto no existe.', 404); }
            if ((int)$filas[$destinoId]['activo'] !== 1) { return $falla('destino_inactivo', 'El profesional sustituto está inactivo.'); }

            // 2. La cita, con bloqueo, y comprobación de que sigue siendo la que el operador revisó.
            $cita = $this->una('SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, estado, estado_pago, profesionalId, usuarioId FROM citas WHERE id = ? FOR UPDATE', 'i', [$citaId]);
            if (!$cita) { return $falla('cita_no_encontrada', 'La cita no existe.', 404); }
            if ((int)$cita['profesionalId'] !== $origenId) { return $falla('cita_cambio', 'La cita cambió de profesional mientras la revisabas. Recarga la página.'); }
            if ($cita['estado'] !== 'confirmada') { return $falla('cita_no_reasignable', 'Solo se reasignan citas confirmadas (esta está «' . $cita['estado'] . '»).'); }
            $horaInicio = (string)($cita['hora_inicio'] ?: $cita['hora']);
            if (($cita['fecha'] . ' ' . $horaInicio) <= $ahoraLocal) { return $falla('cita_iniciada', 'La cita ya comenzó; resuélvela desde la agenda.', 422); }
            if (!$cita['hora_fin'] || !$cita['hora_inicio']) { return $falla('cita_sin_intervalo', 'Cita histórica sin horario de inicio y fin: requiere gestión manual.', 422); }

            // 3. El sustituto debe prestar TODOS los servicios de la cita.
            $serviciosCita = array_map('intval', array_column($this->todas('SELECT servicioId FROM citasservicios WHERE citaId = ?', 'i', [$citaId]), 'servicioId'));
            if (!$serviciosCita) { return $falla('cita_sin_servicios', 'La cita no tiene servicios registrados: requiere gestión manual.', 422); }
            $habilitados = array_map('intval', array_column($this->todas('SELECT servicioId FROM profesionales_servicios WHERE profesionalId = ? LOCK IN SHARE MODE', 'i', [$destinoId]), 'servicioId'));
            if (array_diff($serviciosCita, $habilitados)) {
                return $falla('servicios_incompatibles', 'El profesional sustituto no realiza todos los servicios de la cita.', 422);
            }

            // 4. Comisión ya devengada: no se mueve (documentado en docs/AGENDA_OPERATIVA.md).
            if (isset($this->citasConComisionDevengada([$citaId])[$citaId])) {
                return $falla('comision_devengada', 'El pago de esta cita ya devengó comisión para el profesional actual; no se reasigna automáticamente. Cancélala o gestiónala con finanzas.');
            }

            // 5. Hueco real del destino (horario, descansos, bloqueos, ocupación) con los bloqueos de una reserva.
            $inicioMin = $this->aMinutos((string)$cita['hora_inicio']);
            $finMin = $this->aMinutos((string)$cita['hora_fin']);
            $problema = (new CitaRepository($this->db))->validarHuecoEnTransaccion($this->db, $destinoId, (string)$cita['fecha'], $inicioMin, $finMin, $citaId);
            if ($problema !== null) { return $falla($problema['codigo'], $problema['error']); }

            // Punto de observación de pruebas: hueco ya validado y escritura aún pendiente (si faltara el bloqueo del
            // profesional sustituto, otra reasignación o reserva validaría el mismo hueco y habría doble reserva).
            if (self::$observadorTrasBloqueos !== null) { (self::$observadorTrasBloqueos)($citaId); }

            // 6. Escritura: solo cambia el profesional de la cita.
            $s = $this->sql("UPDATE citas SET profesionalId = ? WHERE id = ? AND profesionalId = ? AND estado = 'confirmada'", 'iii', [$destinoId, $citaId, $origenId]);
            $afectadas = $s->affected_rows;
            $s->close();
            if ($afectadas !== 1) { return $falla('cita_cambio', 'La cita cambió mientras se reasignaba. Recarga la página.'); }

            $snapshotHorario = [
                'fecha' => (string)$cita['fecha'], 'hora_inicio' => substr((string)$cita['hora_inicio'], 0, 5), 'hora_fin' => substr((string)$cita['hora_fin'], 0, 5),
                'duracion_total_minutos' => (int)$cita['duracion_total_minutos'], 'estado' => (string)$cita['estado'], 'estado_pago' => (string)$cita['estado_pago'],
                'servicios' => $this->todas('SELECT cs.servicioId servicio_id, COALESCE(cs.nombre_servicio, s.nombre) nombre, cs.precio_servicio precio, cs.duracion_minutos
                    FROM citasservicios cs LEFT JOIN servicios s ON s.id = cs.servicioId WHERE cs.citaId = ? ORDER BY cs.id', 'i', [$citaId]),
                'comision_devengada' => false,
            ];
            $json = json_encode($snapshotHorario, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $motivo = mb_substr(trim($motivo), 0, 255, 'UTF-8');
            $nombreOrigen = (string)$filas[$origenId]['nombre'];
            $nombreDestino = (string)$filas[$destinoId]['nombre'];
            $this->sql("INSERT INTO citas_reasignaciones(cita_id, cita_referencia_id, accion, profesional_origen_id, profesional_origen_nombre,
                    profesional_destino_id, profesional_destino_nombre, actor_id, motivo, snapshot_json, creado_en)
                VALUES (?, ?, 'reasignada', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())", 'iiisisiss',
                [$citaId, $citaId, $origenId, $nombreOrigen, $destinoId, $nombreDestino, $actor, $motivo, $json])->close();
            $reasignacionId = (int)$this->db->insert_id;

            $antes = json_encode(['profesionalId' => $origenId, 'profesional' => $nombreOrigen], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $despues = json_encode(['profesionalId' => $destinoId, 'profesional' => $nombreDestino, 'reasignacion' => $reasignacionId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->sql("INSERT INTO agenda_eventos (citaId, actorId, tipo, antes_json, despues_json, creado_en) VALUES (?, ?, 'reasignacion', ?, ?, ?)",
                'iisss', [$citaId, $actor, $antes, $despues, $ahoraLocal])->close();

            // 7. Avisos pendientes de esta cita: que no mencionen al profesional anterior.
            $this->sql("UPDATE notificaciones_citas SET payload_json = JSON_SET(payload_json, '$.profesional_id', ?, '$.profesional_nombre', ?)
                WHERE cita_referencia_id = ? AND tipo IN ('recordatorio','confirmacion') AND estado IN ('pendiente','fallido','reintentando') AND payload_json IS NOT NULL",
                'isi', [$destinoId, $nombreDestino, $citaId])->close();

            // 8. Aviso al cliente por el outbox existente, en la MISMA transacción (clave única por reasignación).
            $notifRepo = new NotificacionCitaRepository($this->db);
            $snapshot = $notifRepo->obtenerSnapshotCompletoCitaEnConexion($citaId, $this->db);
            $notificacionId = null;
            if ($snapshot !== null && filter_var(trim((string)($snapshot['cliente_email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false) {
                $snapshot['tipo_notificacion'] = NotificacionCita::TIPO_CONFIRMACION;
                $snapshot['reasignada'] = true;
                $snapshot['profesional_anterior'] = $nombreOrigen;
                $evento = $notifRepo->registrarEventoOutboxEnConexion([
                    'cita_id' => $citaId, 'cita_referencia_id' => $citaId,
                    'usuario_id' => (int)$cita['usuarioId'] > 0 ? (int)$cita['usuarioId'] : null,
                    'tipo' => NotificacionCita::TIPO_CONFIRMACION,
                    'clave_idempotencia' => "cita:{$citaId}:reasignacion:{$reasignacionId}",
                    'destinatario_email' => trim((string)$snapshot['cliente_email']),
                    'destinatario_nombre' => (string)($snapshot['cliente_nombre'] ?? 'Cliente AppSalon'),
                    'asunto' => "Cambio de profesional en tu cita #{$citaId} — AppSalon",
                    'payload_json' => $snapshot,
                ], gmdate('Y-m-d H:i:s'), $this->db);
                $notificacionId = (int)$evento->id;
            }

            return ['ok' => true, 'reasignacion_id' => $reasignacionId, 'notificacion_id' => $notificacionId,
                'datos' => ['cita_id' => $citaId, 'origen' => $origenId, 'destino' => $destinoId, 'destino_nombre' => $nombreDestino]];
        });
    }

    /**
     * Deja constancia (idempotente) de que una cita se canceló por la baja del profesional, por el flujo de
     * cancelación existente. Se llama después de cancelar; la cancelación en sí ya generó su aviso al cliente.
     */
    public function registrarCancelacion(int $actor, int $citaId, array $profesional, string $motivo, array $snapshot): void
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $clave = "cancelada:{$citaId}";
        $motivo = mb_substr(trim($motivo), 0, 255, 'UTF-8');
        $nombre = (string)$profesional['nombre'];
        $pid = (int)$profesional['id'];
        $this->sql("INSERT IGNORE INTO citas_reasignaciones(cita_id, cita_referencia_id, accion, profesional_origen_id, profesional_origen_nombre,
                actor_id, motivo, snapshot_json, clave_unica, creado_en)
            VALUES (?, ?, 'cancelada', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())", 'iiisisss', [$citaId, $citaId, $pid, $nombre, $actor, $motivo, $json, $clave])->close();
    }

    public function auditarEquipo(?int $actor, ?int $usuarioId, string $accion, string $detalle): void
    {
        $this->sql('INSERT INTO equipo_auditoria(actor_id,usuario_id,accion,detalle) VALUES(?,?,?,?)', 'iiss', [$actor, $usuarioId, $accion, mb_substr($detalle, 0, 255, 'UTF-8')])->close();
    }

    private function aMinutos(string $hora): int
    {
        $p = explode(':', $hora);
        return ((int)($p[0] ?? 0)) * 60 + (int)($p[1] ?? 0);
    }
}
