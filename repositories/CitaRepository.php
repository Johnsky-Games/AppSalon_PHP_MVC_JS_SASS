<?php

namespace Repositories;

use Model\ActiveRecord;
use Model\AdminCita;
use Model\BloqueoProfesional;
use Model\Cita;
use Model\CitaServicio;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\NotificacionCita;
use Model\Servicio;
use mysqli;
use Repositories\NotificacionCitaRepository;

/**
 * Repositorio para consultas y persistencia transaccional de `citas` y `citasservicios`,
 * incluyendo reservas con profesional bajo bloqueo pesimista InnoDB (`FOR UPDATE` / `LOCK IN SHARE MODE`),
 * captura de snapshot histórico de servicios y proyección administrativa por fecha.
 */
class CitaRepository
{
    private ?mysqli $db;
    private bool $dbExplicitlyProvided = false;

    public function __construct(?mysqli $db = null)
    {
        if (func_num_args() > 0) {
            $this->db = $db;
            $this->dbExplicitlyProvided = true;
        } else {
            $this->db = null;
        }
    }

    /**
     * Obtiene la conexión configurada (o la global activa si no fue inyectada explícitamente).
     */
    public function getDb(): ?mysqli
    {
        return $this->dbExplicitlyProvided ? $this->db : ($this->db ?? ActiveRecord::getDB());
    }

    /**
     * Resuelve la conexión activa a MySQL o lanza PersistenceException si no está disponible.
     */
    private function resolveDb(): mysqli
    {
        $db = $this->getDb();
        if (!$db instanceof mysqli) {
            throw new PersistenceException('No hay conexión activa a la base de datos.');
        }
        return $db;
    }

    /**
     * Busca una cita por su identificador primario utilizando sentencia preparada.
     *
     * @param int $id Identificador entero positivo.
     * @return Cita|null Entidad Cita si existe, o null si no existe registro.
     * @throws PersistenceException Si ocurre un fallo SQL o de conexión.
     */
    public function findById(int $id): ?Cita
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId,
                        estado, cancelado_por, cancelado_at, motivo_cancelacion, estado_pago
                 FROM citas
                 WHERE id = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda de cita: " . $db->error);
            }

            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda de cita: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultado de cita: " . $err);
            }

            $fila = $resultado->fetch_assoc();
            $resultado->free();
            $stmt->close();

            if (!$fila) {
                return null;
            }

            return new Cita($fila);
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::findById] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::findById] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al buscar cita.", 0, $e);
        }
    }

    /**
     * Obtiene los servicios asociados a una cita desde `citasservicios`, incluyendo su snapshot histórico.
     *
     * @param int $citaId
     * @return array<int, CitaServicio>
     * @throws PersistenceException
     */
    public function findServiciosByCitaId(int $citaId): array
    {
        if ($citaId <= 0) {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos
                 FROM citasservicios
                 WHERE citaId = ?
                 ORDER BY id ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de servicios de cita: " . $db->error);
            }

            $stmt->bind_param('i', $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de servicios de cita: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener servicios de cita: " . $err);
            }

            $items = [];
            while ($fila = $res->fetch_assoc()) {
                $items[] = new CitaServicio($fila);
            }
            $res->free();
            $stmt->close();

            return $items;
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::findServiciosByCitaId] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::findServiciosByCitaId] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar servicios de la cita.", 0, $e);
        }
    }

    /**
     * Obtiene las citas existentes de un profesional en una fecha `AAAA-MM-DD` para el cálculo de ocupación real.
     *
     * @param int $profesionalId
     * @param string $fecha
     * @return array<int, Cita>
     * @throws PersistenceException
     */
    public function findOcupacionByProfesionalEnFecha(int $profesionalId, string $fecha): array
    {
        if ($profesionalId <= 0 || trim($fecha) === '') {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId,
                        estado, cancelado_por, cancelado_at, motivo_cancelacion, estado_pago
                 FROM citas
                 WHERE profesionalId = ? AND fecha = ? AND estado IN ('confirmada', 'en_servicio', 'completada')
                 ORDER BY COALESCE(hora_inicio, hora) ASC, id ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de ocupación del profesional: " . $db->error);
            }

            $stmt->bind_param('is', $profesionalId, $fecha);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de ocupación del profesional: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener ocupación del profesional: " . $err);
            }

            $citas = [];
            while ($fila = $res->fetch_assoc()) {
                $citas[] = new Cita($fila);
            }
            $res->free();
            $stmt->close();

            return $citas;
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::findOcupacionByProfesionalEnFecha] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::findOcupacionByProfesionalEnFecha] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar ocupación del profesional.", 0, $e);
        }
    }

    /**
     * Inserta un registro en la tabla `citas` sobre la conexión provista.
     *
     * @throws PersistenceException Si falla la preparación o ejecución SQL.
     */
    public function createCita(Cita $cita, ?mysqli $dbOverride = null): int
    {
        $db = $dbOverride ?? $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción de cita: " . $db->error);
            }

            $fecha = (string)$cita->fecha;
            $hora = (string)$cita->hora;
            $horaInicio = ($cita->hora_inicio !== null && trim((string)$cita->hora_inicio) !== '')
                ? trim((string)$cita->hora_inicio)
                : null;
            $horaFin = ($cita->hora_fin !== null && trim((string)$cita->hora_fin) !== '')
                ? trim((string)$cita->hora_fin)
                : null;
            $duracionTotal = ($cita->duracion_total_minutos !== null && ctype_digit((string)$cita->duracion_total_minutos) && (int)$cita->duracion_total_minutos > 0)
                ? (int)$cita->duracion_total_minutos
                : null;
            $usuarioId = (int)$cita->usuarioId;
            $profesionalId = ($cita->profesionalId !== null && ctype_digit((string)$cita->profesionalId) && (int)$cita->profesionalId > 0)
                ? (int)$cita->profesionalId
                : null;

            $stmt->bind_param(
                'ssssiii',
                $fecha,
                $hora,
                $horaInicio,
                $horaFin,
                $duracionTotal,
                $usuarioId,
                $profesionalId
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar inserción de cita: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $insertId = (int)$db->insert_id;
            $stmt->close();

            if ($afectadas !== 1 || $insertId <= 0) {
                throw new PersistenceException("La inserción de la cita no produjo un identificador válido.");
            }

            $cita->id = (string)$insertId;
            return $insertId;
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::createCita] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::createCita] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al insertar cita.", 0, $e);
        }
    }

    /**
     * Inserta un vínculo de servicio en la tabla `citasservicios` sobre la conexión provista,
     * incluyendo opcionalmente el snapshot histórico (`nombre_servicio`, `precio_servicio`, `duracion_minutos`).
     *
     * @throws PersistenceException Si falla la preparación o ejecución SQL.
     */
    public function createCitaServicio(CitaServicio $citaServicio, ?mysqli $dbOverride = null): int
    {
        $db = $dbOverride ?? $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción en citasservicios: " . $db->error);
            }

            $citaId = (int)$citaServicio->citaId;
            $servicioId = (int)$citaServicio->servicioId;
            $nombreServicio = ($citaServicio->nombre_servicio !== null && trim((string)$citaServicio->nombre_servicio) !== '')
                ? trim((string)$citaServicio->nombre_servicio)
                : null;
            $precioServicio = ($citaServicio->precio_servicio !== null && trim((string)$citaServicio->precio_servicio) !== '')
                ? trim((string)$citaServicio->precio_servicio)
                : null;
            $duracionMinutos = ($citaServicio->duracion_minutos !== null && ctype_digit((string)$citaServicio->duracion_minutos) && (int)$citaServicio->duracion_minutos > 0)
                ? (int)$citaServicio->duracion_minutos
                : null;

            $stmt->bind_param(
                'iissi',
                $citaId,
                $servicioId,
                $nombreServicio,
                $precioServicio,
                $duracionMinutos
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar inserción en citasservicios: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $insertId = (int)$db->insert_id;
            $stmt->close();

            if ($afectadas !== 1 || $insertId <= 0) {
                throw new PersistenceException("La inserción en citasservicios no afectó ninguna fila.");
            }

            $citaServicio->id = (string)$insertId;
            return $insertId;
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::createCitaServicio] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::createCitaServicio] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al insertar servicio de cita.", 0, $e);
        }
    }

    /**
     * Ejecuta la reserva completa (inserción en `citas` y todos sus vínculos en `citasservicios`)
     * utilizando una única conexión MySQL y una transacción atómica explícita.
     * Captura además el snapshot histórico de cada servicio desde el catálogo.
     *
     * @param Cita $cita Entidad con fecha, hora y usuarioId validados.
     * @param array<int, int> $idServicios Lista de IDs enteros positivos de servicios.
     * @return int ID de la cita creada.
     * @throws PersistenceException Si falla el inicio de transacción, alguna escritura o el commit.
     */
    public function crearReservaAtomica(Cita $cita, array $idServicios): int
    {
        $db = $this->resolveDb();

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar la transacción de reserva.");
            }
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::crearReservaAtomica] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::crearReservaAtomica] Fallo al iniciar transacción: " . $e->getMessage());
            throw new PersistenceException("No se pudo iniciar la transacción de reserva.", 0, $e);
        }

        try {
            $serviciosPorId = $this->bloquearYCargarServiciosCatalogo($db, $idServicios);

            $duracionTotal = 0;
            $todosEncontrados = true;
            foreach ($idServicios as $sid) {
                $sidInt = (int)$sid;
                if (!isset($serviciosPorId[$sidInt])) {
                    $todosEncontrados = false;
                    break;
                }
                $durServicio = filter_var(
                    $serviciosPorId[$sidInt]->duracion_minutos,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
                );
                if ($durServicio === false) {
                    throw new PersistenceException("Duración corrupta en catálogo para servicio ID {$sidInt}.");
                }
                $duracionTotal += $durServicio;
            }

            if ($todosEncontrados && $duracionTotal > 0 && $cita->hora !== '') {
                $horaNormalizada = HorarioProfesional::normalizarHora($cita->hora);
                if ($horaNormalizada !== '') {
                    $partesH = explode(':', $horaNormalizada);
                    $inicioMin = ((int)$partesH[0] * 60) + (int)$partesH[1];
                    $finMin = $inicioMin + $duracionTotal;

                    if ($cita->hora_inicio === null) {
                        $cita->hora_inicio = sprintf('%02d:%02d', intdiv($inicioMin, 60), $inicioMin % 60);
                    }
                    if ($cita->duracion_total_minutos === null) {
                        $cita->duracion_total_minutos = (string)$duracionTotal;
                    }
                    if ($cita->hora_fin === null && $finMin < 1440) {
                        $cita->hora_fin = sprintf('%02d:%02d', intdiv($finMin, 60), $finMin % 60);
                    }
                }
            }

            $idCita = $this->createCita($cita, $db);

            foreach ($idServicios as $idServicio) {
                $sidInt = (int)$idServicio;
                $servCatalogo = $serviciosPorId[$sidInt] ?? null;
                $citaServicio = new CitaServicio([
                    'citaId' => $idCita,
                    'servicioId' => $sidInt,
                    'nombre_servicio' => $servCatalogo ? $servCatalogo->nombre : null,
                    'precio_servicio' => $servCatalogo ? $servCatalogo->precio : null,
                    'duracion_minutos' => $servCatalogo ? $servCatalogo->duracion_minutos : null,
                ]);
                $this->createCitaServicio($citaServicio, $db);
            }

            // Registrar evento Outbox en la misma conexión y transacción antes del commit
            $notifRepo = new NotificacionCitaRepository($db);
            $ahoraUtc = gmdate('Y-m-d H:i:s');
            $claveConfirmacion = "cita:{$idCita}:confirmacion";
            $snapshotParaOutbox = $notifRepo->obtenerSnapshotCompletoCitaEnConexion($idCita, $db);
            if ($snapshotParaOutbox !== null) {
                $emailCliente = trim((string)($snapshotParaOutbox['cliente_email'] ?? ''));
                $nombreCliente = trim((string)($snapshotParaOutbox['cliente_nombre'] ?? 'Cliente AppSalon'));
                $asunto = "Confirmación de tu cita #{$idCita} en AppSalon";

                $notifRepo->registrarEventoOutboxEnConexion([
                    'cita_id' => $idCita,
                    'cita_referencia_id' => $idCita,
                    'usuario_id' => isset($cita->usuarioId) && (int)$cita->usuarioId > 0 ? (int)$cita->usuarioId : null,
                    'tipo' => NotificacionCita::TIPO_CONFIRMACION,
                    'clave_idempotencia' => $claveConfirmacion,
                    'destinatario_email' => $emailCliente,
                    'destinatario_nombre' => $nombreCliente,
                    'asunto' => $asunto,
                    'payload_json' => $snapshotParaOutbox,
                ], $ahoraUtc, $db);
            }

            if (!$db->commit()) {
                throw new PersistenceException("Fallo al confirmar la transacción de la reserva.");
            }

            return $idCita;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log("[CitaRepository::crearReservaAtomica] Error adicional en rollback: " . $rollbackError->getMessage());
            }

            if ($e instanceof PersistenceException) {
                throw $e;
            }

            error_log("[CitaRepository::crearReservaAtomica] Rollback ejecutado por error: " . $e->getMessage());
            throw new PersistenceException("No se pudo procesar la reserva. Operación revertida.", 0, $e);
        }
    }

    /**
     * Revalida estado del profesional, compatibilidad y duración de servicios, horario semanal,
     * descansos, bloqueos y ocupación real, y persiste la reserva con su snapshot histórico
     * dentro de una única transacción InnoDB sobre una misma conexión MySQL.
     *
     * Orden consistente de bloqueo para evitar interbloqueos (deadlocks):
     * 1. `profesionales` (`FOR UPDATE`)
     * 2. `servicios` (`LOCK IN SHARE MODE`, ordenados por `id ASC`)
     * 3. `profesionales_servicios` (`LOCK IN SHARE MODE`)
     * 4. `horarios_profesionales`, `descansos_profesionales`, `bloqueos_profesionales` (`LOCK IN SHARE MODE`)
     * 5. `citas` del profesional en la fecha (`FOR UPDATE`)
     * 6. Escritura en `citas` y `citasservicios`
     *
     * @param int $usuarioId ID del usuario autenticado.
     * @param int $profesionalId ID del profesional solicitado.
     * @param string $fecha Fecha validada `AAAA-MM-DD`.
     * @param string $horaInicio Hora de inicio validada `HH:MM`.
     * @param int $diaSemanaIso Día de la semana ISO-8601 (`1`..`7`) en `America/Guayaquil`.
     * @param array<int, int> $idServicios Lista desduplicada de IDs enteros positivos de servicios.
     * @return array Resultado estructurado de la transacción.
     * @throws PersistenceException Ante cualquier error SQL o corrupción de datos en el catálogo/citas.
     */
    public function crearReservaConProfesionalAtomica(
        int $usuarioId,
        int $profesionalId,
        string $fecha,
        string $horaInicio,
        int $diaSemanaIso,
        array $idServicios
    ): array {
        $db = $this->resolveDb();
        $inTransaction = false;

        try {
            if (!$db->query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED")) {
                throw new PersistenceException("No se pudo configurar el aislamiento transaccional de la reserva: " . $db->error);
            }
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar la transacción de reserva con profesional.");
            }
            $inTransaction = true;

            // 1. Lectura actual con bloqueo exclusivo sobre el profesional (`FOR UPDATE`)
            $stmtProf = $db->prepare("SELECT p.id,p.nombre,IF(p.colaborador_id IS NULL OR EXISTS(SELECT 1 FROM colaboradores c WHERE c.id=p.colaborador_id AND c.rol='profesional' AND c.estado='activo'),p.activo,0) activo FROM profesionales p WHERE p.id=? LIMIT 1 FOR UPDATE");
            if (!$stmtProf) {
                throw new PersistenceException("Error al preparar bloqueo de profesional: " . $db->error);
            }
            $stmtProf->bind_param('i', $profesionalId);
            if (!$stmtProf->execute()) {
                $err = $stmtProf->error;
                $stmtProf->close();
                throw new PersistenceException("Error al ejecutar bloqueo de profesional: " . $err);
            }
            $resProf = $stmtProf->get_result();
            if ($resProf === false) {
                $err = $stmtProf->error;
                $stmtProf->close();
                throw new PersistenceException("Error al obtener profesional bloqueado: " . $err);
            }
            $filaProf = $resProf->fetch_assoc();
            $resProf->free();
            $stmtProf->close();

            if (!$filaProf) {
                $db->rollback();
                $inTransaction = false;
                return [
                    'status' => 'not_found',
                    'codigo' => 'profesional_no_encontrado',
                    'httpCode' => 404,
                    'resultado' => false,
                    'error' => 'El profesional seleccionado no existe.'
                ];
            }

            if ((int)$filaProf['activo'] !== 1) {
                $db->rollback();
                $inTransaction = false;
                return [
                    'status' => 'conflict',
                    'codigo' => 'profesional_inactivo',
                    'httpCode' => 409,
                    'resultado' => false,
                    'error' => 'El profesional seleccionado se encuentra inactivo.'
                ];
            }

            // 2. Lectura actual con bloqueo compartido sobre servicios en orden ascendente de ID (`LOCK IN SHARE MODE`)
            $serviciosPorId = $this->bloquearYCargarServiciosCatalogo($db, $idServicios);

            $duracionTotalMinutos = 0;
            foreach ($idServicios as $sid) {
                $sidInt = (int)$sid;
                if (!isset($serviciosPorId[$sidInt])) {
                    $db->rollback();
                    $inTransaction = false;
                    return [
                        'status' => 'not_found',
                        'codigo' => 'servicio_inexistente',
                        'httpCode' => 422,
                        'resultado' => false,
                        'error' => 'Uno o más servicios seleccionados no existen o no son válidos'
                    ];
                }

                $duracionServicio = filter_var(
                    $serviciosPorId[$sidInt]->duracion_minutos,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
                );
                if ($duracionServicio === false) {
                    throw new PersistenceException("Duración inválida en catálogo para el servicio ID {$sidInt}.");
                }

                $duracionTotalMinutos += $duracionServicio;
            }

            // 3. Lectura actual de compatibilidad profesional-servicios (`LOCK IN SHARE MODE`)
            $stmtRel = $db->prepare(
                "SELECT servicioId
                 FROM profesionales_servicios
                 WHERE profesionalId = ?
                 ORDER BY servicioId ASC
                 LOCK IN SHARE MODE"
            );
            if (!$stmtRel) {
                throw new PersistenceException("Error al preparar verificación de servicios del profesional: " . $db->error);
            }
            $stmtRel->bind_param('i', $profesionalId);
            if (!$stmtRel->execute()) {
                $err = $stmtRel->error;
                $stmtRel->close();
                throw new PersistenceException("Error al verificar servicios del profesional: " . $err);
            }
            $resRel = $stmtRel->get_result();
            if ($resRel === false) {
                $err = $stmtRel->error;
                $stmtRel->close();
                throw new PersistenceException("Error al leer servicios del profesional: " . $err);
            }
            $serviciosHabilitados = [];
            while ($rowRel = $resRel->fetch_assoc()) {
                $serviciosHabilitados[(int)$rowRel['servicioId']] = true;
            }
            $resRel->free();
            $stmtRel->close();

            foreach ($idServicios as $sid) {
                if (!isset($serviciosHabilitados[(int)$sid])) {
                    $db->rollback();
                    $inTransaction = false;
                    return [
                        'status' => 'invalid',
                        'codigo' => 'servicios_incompatibles',
                        'httpCode' => 422,
                        'resultado' => false,
                        'error' => 'El profesional seleccionado no realiza todos los servicios solicitados.'
                    ];
                }
            }

            // 4. Lectura actual de horarios, descansos y bloqueos del profesional (`LOCK IN SHARE MODE`)
            $horariosDia = $this->cargarHorariosDiaForShare($db, $profesionalId, $diaSemanaIso);
            $descansosDia = $this->cargarDescansosDiaForShare($db, $profesionalId, $diaSemanaIso);
            $bloqueosFecha = $this->cargarBloqueosFechaForShare($db, $profesionalId, $fecha);

            $partesInicio = explode(':', HorarioProfesional::normalizarHora($horaInicio));
            $inicioMin = ((int)$partesInicio[0] * 60) + (int)$partesInicio[1];
            $finMin = $inicioMin + $duracionTotalMinutos;

            // El intervalo completo [inicioMin, finMin) debe caber dentro de un mismo turno libre continuo del día
            if ($finMin > 1440 || !$this->cabeEnAgendaDelDia($inicioMin, $finMin, $diaSemanaIso, $fecha, $horariosDia, $descansosDia, $bloqueosFecha)) {
                $db->rollback();
                $inTransaction = false;
                return [
                    'status' => 'invalid',
                    'codigo' => 'fuera_de_horario',
                    'httpCode' => 422,
                    'resultado' => false,
                    'error' => 'El intervalo solicitado no está disponible en el horario del profesional o cruza un descanso o bloqueo.'
                ];
            }

            // 5. Lectura actual con bloqueo exclusivo sobre citas del profesional en la fecha (`FOR UPDATE`)
            $citasExistentes = $this->cargarCitasProfesionalEnFechaForUpdate($db, $profesionalId, $fecha);
            foreach ($citasExistentes as $citaExistente) {
                [$ocupInicio, $ocupFin] = $this->resolverIntervaloCitaOcupada($citaExistente);
                // Semántica semiabierta [inicio, fin): hay solapamiento si y solo si A_inicio < B_fin && B_inicio < A_fin
                if ($inicioMin < $ocupFin && $ocupInicio < $finMin) {
                    $db->rollback();
                    $inTransaction = false;
                    return [
                        'status' => 'conflict',
                        'codigo' => 'conflicto_ocupacion',
                        'httpCode' => 409,
                        'resultado' => false,
                        'error' => 'El horario seleccionado ya está ocupado por otra reserva de este profesional.'
                    ];
                }
            }

            // 6. Persistir cita y snapshot histórico de servicios dentro de la misma transacción
            $horaInicioFormateada = sprintf('%02d:%02d', intdiv($inicioMin, 60), $inicioMin % 60);
            $horaFinFormateada = sprintf('%02d:%02d', intdiv($finMin, 60), $finMin % 60);

            $cita = new Cita([
                'fecha' => $fecha,
                'hora' => $horaInicioFormateada,
                'hora_inicio' => $horaInicioFormateada,
                'hora_fin' => $horaFinFormateada,
                'duracion_total_minutos' => (string)$duracionTotalMinutos,
                'usuarioId' => $usuarioId,
                'profesionalId' => $profesionalId,
            ]);

            $idCita = $this->createCita($cita, $db);

            $serviciosSnapshot = [];
            foreach ($idServicios as $sid) {
                $sidInt = (int)$sid;
                $servCatalogo = $serviciosPorId[$sidInt];
                $citaServicio = new CitaServicio([
                    'citaId' => $idCita,
                    'servicioId' => $sidInt,
                    'nombre_servicio' => (string)$servCatalogo->nombre,
                    'precio_servicio' => (string)$servCatalogo->precio,
                    'duracion_minutos' => (string)$servCatalogo->duracion_minutos,
                ]);
                $this->createCitaServicio($citaServicio, $db);
                $serviciosSnapshot[] = [
                    'servicioId' => $sidInt,
                    'nombre' => (string)$servCatalogo->nombre,
                    'precio' => (string)$servCatalogo->precio,
                    'duracion_minutos' => (int)$servCatalogo->duracion_minutos,
                ];
            }

            // Registrar evento Outbox en la misma conexión y transacción antes del commit
            $notifRepo = new NotificacionCitaRepository($db);
            $ahoraUtc = gmdate('Y-m-d H:i:s');
            $claveConfirmacion = "cita:{$idCita}:confirmacion";
            $snapshotParaOutbox = $notifRepo->obtenerSnapshotCompletoCitaEnConexion($idCita, $db);
            if ($snapshotParaOutbox !== null) {
                $emailCliente = trim((string)($snapshotParaOutbox['cliente_email'] ?? ''));
                $nombreCliente = trim((string)($snapshotParaOutbox['cliente_nombre'] ?? 'Cliente AppSalon'));
                $asunto = "Confirmación de tu cita #{$idCita} en AppSalon";

                $notifRepo->registrarEventoOutboxEnConexion([
                    'cita_id' => $idCita,
                    'cita_referencia_id' => $idCita,
                    'usuario_id' => $usuarioId,
                    'tipo' => NotificacionCita::TIPO_CONFIRMACION,
                    'clave_idempotencia' => $claveConfirmacion,
                    'destinatario_email' => $emailCliente,
                    'destinatario_nombre' => $nombreCliente,
                    'asunto' => $asunto,
                    'payload_json' => $snapshotParaOutbox,
                ], $ahoraUtc, $db);
            }

            if (!$db->commit()) {
                throw new PersistenceException("Fallo al confirmar la transacción de la reserva con profesional.");
            }
            $inTransaction = false;

            return [
                'status' => 'ok',
                'codigo' => 'reserva_creada',
                'httpCode' => 200,
                'id' => $idCita,
                'profesionalId' => $profesionalId,
                'fecha' => $fecha,
                'hora' => $horaInicioFormateada,
                'hora_inicio' => $horaInicioFormateada,
                'hora_fin' => $horaFinFormateada,
                'duracion_total_minutos' => $duracionTotalMinutos,
                'servicios' => $serviciosSnapshot,
                'cita' => $cita,
            ];
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try {
                    $db->rollback();
                } catch (\Throwable $rollbackError) {
                    error_log("[CitaRepository::crearReservaConProfesionalAtomica] Error adicional en rollback: " . $rollbackError->getMessage());
                }
            }

            if ($e instanceof PersistenceException) {
                error_log("[CitaRepository::crearReservaConProfesionalAtomica] " . $e->getMessage());
                throw $e;
            }

            error_log("[CitaRepository::crearReservaConProfesionalAtomica] Rollback ejecutado por error: " . $e->getMessage());
            throw new PersistenceException("No se pudo procesar la reserva. Operación revertida.", 0, $e);
        }
    }

    /**
     * Comprueba, DENTRO de la transacción del llamador y con los mismos bloqueos y reglas que una reserva real
     * (pasos 4 y 5 de `crearReservaConProfesionalAtomica`), que el intervalo `[inicioMin, finMin)` cabe en el
     * horario del profesional, no cruza descansos ni bloqueos y no se solapa con otra cita vigente.
     * La usa la reasignación asistida de citas para no duplicar la lógica de disponibilidad.
     *
     * El llamador debe haber bloqueado antes la fila del profesional (`FOR UPDATE`), como hace la reserva.
     *
     * @param int|null $excluirCitaId Cita que se está moviendo (no cuenta como ocupación).
     * @return array{codigo:string,error:string}|null null si el hueco es válido
     * @throws PersistenceException
     */
    public function validarHuecoEnTransaccion(mysqli $db, int $profesionalId, string $fecha, int $inicioMin, int $finMin, ?int $excluirCitaId = null): ?array
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new \DateTimeZone('America/Guayaquil'));
        if (!$dt) {
            throw new PersistenceException('Fecha de cita inválida para validar el hueco.');
        }
        $diaSemanaIso = (int)$dt->format('N');

        $horariosDia = $this->cargarHorariosDiaForShare($db, $profesionalId, $diaSemanaIso);
        $descansosDia = $this->cargarDescansosDiaForShare($db, $profesionalId, $diaSemanaIso);
        $bloqueosFecha = $this->cargarBloqueosFechaForShare($db, $profesionalId, $fecha);
        if ($finMin > 1440 || !$this->cabeEnAgendaDelDia($inicioMin, $finMin, $diaSemanaIso, $fecha, $horariosDia, $descansosDia, $bloqueosFecha)) {
            return [
                'codigo' => 'fuera_de_horario',
                'error' => 'El intervalo solicitado no está disponible en el horario del profesional o cruza un descanso o bloqueo.',
            ];
        }

        foreach ($this->cargarCitasProfesionalEnFechaForUpdate($db, $profesionalId, $fecha) as $citaExistente) {
            if ($excluirCitaId !== null && (int)$citaExistente->id === $excluirCitaId) {
                continue;
            }
            [$ocupInicio, $ocupFin] = $this->resolverIntervaloCitaOcupada($citaExistente);
            if ($inicioMin < $ocupFin && $ocupInicio < $finMin) {
                return [
                    'codigo' => 'conflicto_ocupacion',
                    'error' => 'El horario seleccionado ya está ocupado por otra reserva de este profesional.',
                ];
            }
        }

        return null;
    }

    /**
     * Lee y bloquea (`LOCK IN SHARE MODE`) los servicios solicitados en orden ascendente de ID.
     *
     * @param mysqli $db
     * @param array<int, int> $idServicios
     * @return array<int, Servicio> Mapa indexado por ID de servicio.
     * @throws PersistenceException
     */
    private function bloquearYCargarServiciosCatalogo(mysqli $db, array $idServicios): array
    {
        $idsOrdenados = array_values(array_unique(array_map('intval', $idServicios)));
        sort($idsOrdenados);

        if (empty($idsOrdenados)) {
            return [];
        }

        $stmt = $db->prepare("SELECT id, nombre, precio, duracion_minutos FROM servicios WHERE id = ? LIMIT 1 LOCK IN SHARE MODE");
        if (!$stmt) {
            throw new PersistenceException("Error al preparar bloqueo de servicio en catálogo: " . $db->error);
        }

        $mapa = [];
        foreach ($idsOrdenados as $sid) {
            $stmt->bind_param('i', $sid);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar lectura actual de servicio: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener servicio bloqueado: " . $err);
            }
            $fila = $res->fetch_assoc();
            $res->free();

            if ($fila) {
                $mapa[(int)$fila['id']] = new Servicio($fila);
            }
        }

        $stmt->close();
        return $mapa;
    }

    /**
     * @return array<int, HorarioProfesional>
     * @throws PersistenceException
     */
    private function cargarHorariosDiaForShare(mysqli $db, int $profesionalId, int $diaSemanaIso): array
    {
        $stmt = $db->prepare(
            "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin
             FROM horarios_profesionales
             WHERE profesionalId = ? AND dia_semana = ?
             ORDER BY hora_inicio ASC, id ASC
             LOCK IN SHARE MODE"
        );
        if (!$stmt) {
            throw new PersistenceException("Error al preparar lectura actual de horarios: " . $db->error);
        }
        $stmt->bind_param('ii', $profesionalId, $diaSemanaIso);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al ejecutar lectura actual de horarios: " . $err);
        }
        $res = $stmt->get_result();
        if ($res === false) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al leer horarios bloqueados: " . $err);
        }
        $items = [];
        while ($fila = $res->fetch_assoc()) {
            $items[] = new HorarioProfesional($fila);
        }
        $res->free();
        $stmt->close();
        return $items;
    }

    /**
     * @return array<int, DescansoProfesional>
     * @throws PersistenceException
     */
    private function cargarDescansosDiaForShare(mysqli $db, int $profesionalId, int $diaSemanaIso): array
    {
        $stmt = $db->prepare(
            "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin, motivo
             FROM descansos_profesionales
             WHERE profesionalId = ? AND dia_semana = ?
             ORDER BY hora_inicio ASC, id ASC
             LOCK IN SHARE MODE"
        );
        if (!$stmt) {
            throw new PersistenceException("Error al preparar lectura actual de descansos: " . $db->error);
        }
        $stmt->bind_param('ii', $profesionalId, $diaSemanaIso);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al ejecutar lectura actual de descansos: " . $err);
        }
        $res = $stmt->get_result();
        if ($res === false) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al leer descansos bloqueados: " . $err);
        }
        $items = [];
        while ($fila = $res->fetch_assoc()) {
            $items[] = new DescansoProfesional($fila);
        }
        $res->free();
        $stmt->close();
        return $items;
    }

    /**
     * @return array<int, BloqueoProfesional>
     * @throws PersistenceException
     */
    private function cargarBloqueosFechaForShare(mysqli $db, int $profesionalId, string $fecha): array
    {
        $stmt = $db->prepare(
            "SELECT id, profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo
             FROM bloqueos_profesionales
             WHERE profesionalId = ? AND fecha_inicio <= ? AND fecha_fin >= ?
             ORDER BY fecha_inicio ASC, hora_inicio ASC, id ASC
             LOCK IN SHARE MODE"
        );
        if (!$stmt) {
            throw new PersistenceException("Error al preparar lectura actual de bloqueos: " . $db->error);
        }
        $stmt->bind_param('iss', $profesionalId, $fecha, $fecha);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al ejecutar lectura actual de bloqueos: " . $err);
        }
        $res = $stmt->get_result();
        if ($res === false) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al leer bloqueos en fecha: " . $err);
        }
        $items = [];
        while ($fila = $res->fetch_assoc()) {
            $items[] = new BloqueoProfesional($fila);
        }
        $res->free();
        $stmt->close();
        return $items;
    }

    /**
     * @return array<int, Cita>
     * @throws PersistenceException
     */
    private function cargarCitasProfesionalEnFechaForUpdate(mysqli $db, int $profesionalId, string $fecha): array
    {
        $stmt = $db->prepare(
            "SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId,
                    estado, cancelado_por, cancelado_at, motivo_cancelacion, estado_pago
             FROM citas
             WHERE profesionalId = ? AND fecha = ? AND estado IN ('confirmada', 'en_servicio', 'completada')
             ORDER BY COALESCE(hora_inicio, hora) ASC, id ASC
             FOR UPDATE"
        );
        if (!$stmt) {
            throw new PersistenceException("Error al preparar bloqueo de citas existentes: " . $db->error);
        }
        $stmt->bind_param('is', $profesionalId, $fecha);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al ejecutar bloqueo de citas existentes: " . $err);
        }
        $res = $stmt->get_result();
        if ($res === false) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al leer citas existentes bloqueadas: " . $err);
        }
        $citas = [];
        while ($fila = $res->fetch_assoc()) {
            $citas[] = new Cita($fila);
        }
        $res->free();
        $stmt->close();
        return $citas;
    }

    /**
     * Verifica si el intervalo `[inicioMin, finMin)` cabe íntegramente dentro de alguna ventana libre
     * continua de los turnos del día tras restar descansos y bloqueos.
     *
     * @param int $inicioMin
     * @param int $finMin
     * @param int $diaSemanaIso
     * @param string $fecha
     * @param array<int, HorarioProfesional> $horariosDia
     * @param array<int, DescansoProfesional> $descansosDia
     * @param array<int, BloqueoProfesional> $bloqueosFecha
     */
    private function cabeEnAgendaDelDia(
        int $inicioMin,
        int $finMin,
        int $diaSemanaIso,
        string $fecha,
        array $horariosDia,
        array $descansosDia,
        array $bloqueosFecha
    ): bool {
        if ($inicioMin >= $finMin || empty($horariosDia)) {
            return false;
        }

        $restricciones = [];
        foreach ($descansosDia as $descanso) {
            if ((int)$descanso->dia_semana !== $diaSemanaIso) {
                continue;
            }
            $dInicio = $this->horaAMinutosInterno((string)$descanso->hora_inicio);
            $dFin = $this->horaAMinutosInterno((string)$descanso->hora_fin);
            if ($dInicio < $dFin) {
                $restricciones[] = ['inicio' => $dInicio, 'fin' => $dFin];
            }
        }

        foreach ($bloqueosFecha as $bloqueo) {
            if ($fecha < (string)$bloqueo->fecha_inicio || $fecha > (string)$bloqueo->fecha_fin) {
                continue;
            }
            if ($bloqueo->esDiaCompleto()) {
                return false;
            }
            $bInicio = $this->horaAMinutosInterno((string)$bloqueo->hora_inicio);
            $bFin = $this->horaAMinutosInterno((string)$bloqueo->hora_fin);
            if ($bInicio < $bFin) {
                $restricciones[] = ['inicio' => $bInicio, 'fin' => $bFin];
            }
        }

        foreach ($horariosDia as $horario) {
            if ((int)$horario->dia_semana !== $diaSemanaIso) {
                continue;
            }
            $turnoInicio = $this->horaAMinutosInterno((string)$horario->hora_inicio);
            $turnoFin = $this->horaAMinutosInterno((string)$horario->hora_fin);
            if ($turnoInicio >= $turnoFin) {
                continue;
            }

            $ventanas = $this->restarRestricciones($turnoInicio, $turnoFin, $restricciones);
            foreach ($ventanas as $ventana) {
                if ($inicioMin >= $ventana['inicio'] && $finMin <= $ventana['fin']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array{inicio: int, fin: int}> $restricciones
     * @return array<int, array{inicio: int, fin: int}>
     */
    private function restarRestricciones(int $turnoInicio, int $turnoFin, array $restricciones): array
    {
        $ventanasLibres = [['inicio' => $turnoInicio, 'fin' => $turnoFin]];

        foreach ($restricciones as $r) {
            $rInicio = $r['inicio'];
            $rFin = $r['fin'];
            if ($rInicio >= $rFin) {
                continue;
            }

            $nuevas = [];
            foreach ($ventanasLibres as $v) {
                if ($rFin <= $v['inicio'] || $rInicio >= $v['fin']) {
                    $nuevas[] = $v;
                    continue;
                }
                if ($rInicio > $v['inicio']) {
                    $nuevas[] = ['inicio' => $v['inicio'], 'fin' => $rInicio];
                }
                if ($rFin < $v['fin']) {
                    $nuevas[] = ['inicio' => $rFin, 'fin' => $v['fin']];
                }
            }
            $ventanasLibres = $nuevas;
            if (empty($ventanasLibres)) {
                break;
            }
        }

        return $ventanasLibres;
    }

    /**
     * @return array{0: int, 1: int}
     * @throws PersistenceException
     */
    private function resolverIntervaloCitaOcupada(Cita $cita): array
    {
        $horaInicioStr = ($cita->hora_inicio !== null && trim((string)$cita->hora_inicio) !== '')
            ? (string)$cita->hora_inicio
            : (string)$cita->hora;

        $normInicio = HorarioProfesional::normalizarHora($horaInicioStr);
        if ($normInicio === '') {
            throw new PersistenceException("Hora de inicio inválida en cita existente ID {$cita->id}.");
        }
        $inicioMin = $this->horaAMinutosInterno($normInicio);

        if ($cita->hora_fin !== null && trim((string)$cita->hora_fin) !== '') {
            $normFin = HorarioProfesional::normalizarHora((string)$cita->hora_fin);
            if ($normFin === '') {
                throw new PersistenceException("Hora de fin inválida en cita existente ID {$cita->id}.");
            }
            $finMin = $this->horaAMinutosInterno($normFin);
        } elseif ($cita->duracion_total_minutos !== null) {
            $dur = filter_var(
                $cita->duracion_total_minutos,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
            );
            if ($dur === false) {
                throw new PersistenceException("Duración ocupada inválida en cita existente ID {$cita->id}.");
            }
            $finMin = $inicioMin + $dur;
        } else {
            throw new PersistenceException("Cita ocupada ID {$cita->id} sin intervalo de fin definido.");
        }

        if ($inicioMin >= $finMin) {
            throw new PersistenceException("Intervalo ocupado corrupto en cita existente ID {$cita->id}.");
        }

        return [$inicioMin, $finMin];
    }

    private function horaAMinutosInterno(string $hora): int
    {
        $norm = HorarioProfesional::normalizarHora($hora);
        $partes = explode(':', $norm);
        return ((int)$partes[0] * 60) + (int)$partes[1];
    }

    /**
     * Elimina una cita y sus vínculos en `citasservicios` dentro de una transacción atómica
     * sobre una única conexión MySQL.
     * Si la cita pertenece a un profesional, adquiere primero el bloqueo `FOR UPDATE` sobre
     * `profesionales` siguiendo el orden global de bloqueos.
     *
     * @param int $citaId ID entero positivo de la cita a eliminar.
     * @return bool True si la cita existía y fue eliminada, false si el registro no existía.
     * @throws PersistenceException Si ocurre un fallo SQL o de transacción.
     */
    /** ¿La cita tiene alguna solicitud o decisión de regularización de la atención? (auditoría que impide eliminarla). */
    public function tieneRegularizaciones(int $citaId): bool
    {
        $db = $this->resolveDb();
        $stmt = $db->prepare('SELECT 1 FROM citas_regularizaciones WHERE cita_id = ? LIMIT 1');
        if (!$stmt) throw new PersistenceException('No se pudo comprobar la auditoría de la cita.');
        $stmt->bind_param('i', $citaId);
        if (!$stmt->execute()) { $stmt->close(); throw new PersistenceException('No se pudo comprobar la auditoría de la cita.'); }
        $hay = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
        return $hay;
    }

    /** Motivo por el que la cita no puede eliminarse físicamente (`regularizacion` | `cobros` | `auditoria`) o null si puede. */
    public function motivoProteccionEliminacion(int $citaId): ?string
    {
        if ($this->tieneRegularizaciones($citaId)) return 'regularizacion';
        if ($this->tieneRegistrosFinancieros($citaId)) return 'cobros';
        // Auditoría de la agenda (inicio, finalización, ausencia, reprogramación…), reasignaciones y puntos de fidelidad: sus claves
        // foráneas son CASCADE (se perderían con la cita) o SET NULL (quedarían sin cita); se conservan.
        foreach ([['agenda_eventos', 'citaId'], ['citas_reasignaciones', 'cita_id'], ['fidelidad_movimientos', 'cita_id']] as [$tabla, $col]) {
            if ($this->existeFila($tabla, $col, $citaId)) return 'auditoria';
        }
        return null;
    }

    private function existeFila(string $tabla, string $columna, int $citaId): bool
    {
        $stmt = $this->resolveDb()->prepare("SELECT 1 FROM {$tabla} WHERE {$columna} = ? LIMIT 1");
        if (!$stmt) throw new PersistenceException('No se pudo comprobar el historial de la cita.');
        $stmt->bind_param('i', $citaId);
        if (!$stmt->execute()) { $stmt->close(); throw new PersistenceException('No se pudo comprobar el historial de la cita.'); }
        $hay = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
        return $hay;
    }

    /** ¿La cita tiene pagos, comprobantes o eventos de cobro? (evidencia financiera sin FK por diseño: no se elimina). */
    public function tieneRegistrosFinancieros(int $citaId): bool
    {
        $db = $this->resolveDb();
        foreach ([['pagos', 'cita_referencia_id'], ['pagos', 'cita_id'], ['cobros_devoluciones', 'cita_id'], ['comprobantes', 'cita_id'], ['cobros_eventos', 'cita_id']] as [$tabla, $col]) {
            $stmt = $db->prepare("SELECT 1 FROM {$tabla} WHERE {$col} = ? LIMIT 1");
            if (!$stmt) throw new PersistenceException('No se pudo comprobar los cobros de la cita.');
            $stmt->bind_param('i', $citaId);
            if (!$stmt->execute()) { $stmt->close(); throw new PersistenceException('No se pudo comprobar los cobros de la cita.'); }
            $hay = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
            if ($hay) return true;
        }
        return false;
    }

    /**
     * Elimina físicamente una cita SIN evidencia (ver motivoProteccionEliminacion). El orden de bloqueo es el de las demás
     * mutaciones de la agenda (profesional → cita): lo contrario produce interbloqueos con los cambios de estado. Si la cita cambia de
     * profesional entre la lectura y el bloqueo, se reintenta (acotado).
     */
    public function eliminarCitaAtomica(int $citaId): bool
    {
        for ($intento = 0; $intento < 3; $intento++) {
            $r = $this->eliminarCitaAtomicaUnaVez($citaId);
            if ($r !== null) return $r;
        }
        throw new PersistenceException('La cita cambió de profesional repetidamente durante la eliminación.');
    }

    /** @return bool|null null = reintentar (la cita cambió de profesional entre la lectura y el bloqueo) */
    private function eliminarCitaAtomicaUnaVez(int $citaId): ?bool
    {
        if ($citaId <= 0) {
            return false;
        }

        $db = $this->resolveDb();

        // Lectura previa FUERA de la transacción (autocommit): así la transacción no fija su instantánea antes de bloquear la cita y las
        // comprobaciones de evidencia posteriores ven lo que se confirmó mientras esperaba el candado.
        $stmtPre = $db->prepare("SELECT id, profesionalId FROM citas WHERE id = ? LIMIT 1");
        if (!$stmtPre) {
            throw new PersistenceException("Fallo al preparar lectura previa de cita: " . $db->error);
        }
        $stmtPre->bind_param('i', $citaId);
        if (!$stmtPre->execute()) {
            $err = $stmtPre->error;
            $stmtPre->close();
            throw new PersistenceException("Fallo al ejecutar lectura previa de cita: " . $err);
        }
        $resPre = $stmtPre->get_result();
        if ($resPre === false) {
            $err = $stmtPre->error;
            $stmtPre->close();
            throw new PersistenceException("Fallo al obtener lectura previa de cita: " . $err);
        }
        $filaPre = $resPre->fetch_assoc();
        $resPre->free();
        $stmtPre->close();

        if (!$filaPre) {
            return false;
        }


        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar la transacción de eliminación.");
            }
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::eliminarCitaAtomica] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::eliminarCitaAtomica] Fallo al iniciar transacción: " . $e->getMessage());
            throw new PersistenceException("No se pudo iniciar la transacción de eliminación.", 0, $e);
        }

        try {
            if ($filaPre['profesionalId'] !== null && (int)$filaPre['profesionalId'] > 0) {
                $profId = (int)$filaPre['profesionalId'];
                $stmtLockProf = $db->prepare("SELECT id FROM profesionales WHERE id = ? LIMIT 1 FOR UPDATE");
                if (!$stmtLockProf) {
                    throw new PersistenceException("Fallo al preparar bloqueo de profesional en eliminación: " . $db->error);
                }
                $stmtLockProf->bind_param('i', $profId);
                if (!$stmtLockProf->execute()) {
                    $err = $stmtLockProf->error;
                    $stmtLockProf->close();
                    throw new PersistenceException("Fallo al adquirir bloqueo de profesional en eliminación: " . $err);
                }
                if ($resLockProf = $stmtLockProf->get_result()) {
                    $resLockProf->free();
                }
                $stmtLockProf->close();
            }

            // Bloqueo de la cita (después del profesional, como en mutarAgenda) y verificación de que sigue siendo del mismo profesional.
            $stmtLockCita = $db->prepare("SELECT profesionalId FROM citas WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$stmtLockCita) {
                throw new PersistenceException("Fallo al preparar bloqueo de cita en eliminación: " . $db->error);
            }
            $stmtLockCita->bind_param('i', $citaId);
            if (!$stmtLockCita->execute()) {
                $err = $stmtLockCita->error;
                $stmtLockCita->close();
                throw new PersistenceException("Fallo al bloquear la cita en eliminación: " . $err);
            }
            $filaLock = $stmtLockCita->get_result()->fetch_assoc();
            $stmtLockCita->close();
            if (!$filaLock) {
                $db->rollback();
                return false;
            }
            if ((int)($filaLock['profesionalId'] ?? 0) !== (int)($filaPre['profesionalId'] ?? 0)) {
                $db->rollback();
                return null;
            }

            // Con la cita bloqueada (los cobros y las regularizaciones la bloquean también), la comprobación y la eliminación son
            // atómicas: ningún cobro puede colarse entre la guarda y el DELETE. Es la guarda autoritativa; la del servicio solo da un mensaje antes.
            $motivoProteccion = $this->motivoProteccionEliminacion($citaId);
            if ($motivoProteccion !== null) {
                throw new CitaProtegidaException($motivoProteccion);
            }

            $stmtServicios = $db->prepare("DELETE FROM citasservicios WHERE citaId = ?");
            if (!$stmtServicios) {
                throw new PersistenceException("Fallo al preparar eliminación en citasservicios: " . $db->error);
            }

            $stmtServicios->bind_param('i', $citaId);
            if (!$stmtServicios->execute()) {
                $err = $stmtServicios->error;
                $stmtServicios->close();
                throw new PersistenceException("Fallo al ejecutar eliminación en citasservicios: " . $err);
            }
            $stmtServicios->close();

            $stmtCita = $db->prepare("DELETE FROM citas WHERE id = ? LIMIT 1");
            if (!$stmtCita) {
                throw new PersistenceException("Fallo al preparar eliminación en citas: " . $db->error);
            }

            $stmtCita->bind_param('i', $citaId);
            if (!$stmtCita->execute()) {
                $err = $stmtCita->error;
                $stmtCita->close();
                throw new PersistenceException("Fallo al ejecutar eliminación en citas: " . $err);
            }

            $afectadasCita = $stmtCita->affected_rows;
            $stmtCita->close();

            if ($afectadasCita <= 0) {
                $db->rollback();
                return false;
            }

            if (!$db->commit()) {
                throw new PersistenceException("Fallo al confirmar la transacción de eliminación.");
            }

            return true;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log("[CitaRepository::eliminarCitaAtomica] Error adicional en rollback: " . $rollbackError->getMessage());
            }

            if ($e instanceof PersistenceException || $e instanceof CitaProtegidaException) {
                throw $e;
            }

            error_log("[CitaRepository::eliminarCitaAtomica] Rollback ejecutado por error: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al eliminar la cita.", 0, $e);
        }
    }

    /**
     * Cancela lógicamente una cita preservando el registro de la cita, sus registros en `citasservicios`
     * con sus snapshots históricos, y cualquier registro en `pagos`.
     * Operación transaccional con bloqueo pesimista e idempotente ante llamadas repetidas.
     *
     * @param int $citaId Identificador entero positivo de la cita.
     * @param string $actor Actor que cancela ('cliente', 'admin', 'sistema').
     * @param string|null $motivo Motivo opcional de la cancelación.
     * @param string|null $fechaHoraCancelacion Fecha/hora de cancelación (YYYY-MM-DD HH:MM:SS) o null para tiempo actual.
     * @return bool True si la cita fue cancelada o ya estaba cancelada (idempotencia), false si no existe.
     * @throws PersistenceException Si ocurre un fallo SQL o de conexión.
     */
    public function cancelarCitaLogica(
        int $citaId,
        string $actor = 'cliente',
        ?string $motivo = null,
        ?string $fechaHoraCancelacion = null
    ): bool {
        if ($citaId <= 0) {
            return false;
        }

        $db = $this->resolveDb();

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar la transacción de cancelación.");
            }
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::cancelarCitaLogica] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::cancelarCitaLogica] Fallo al iniciar transacción: " . $e->getMessage());
            throw new PersistenceException("No se pudo iniciar la transacción de cancelación.", 0, $e);
        }

        try {
            $stmt = $db->prepare("SELECT id, estado, profesionalId FROM citas WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$stmt) {
                throw new PersistenceException("Fallo al preparar bloqueo de cita para cancelación: " . $db->error);
            }
            $stmt->bind_param('i', $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Fallo al bloquear cita para cancelación: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Fallo al obtener resultado de cita bloqueada: " . $err);
            }
            $fila = $res->fetch_assoc();
            $res->free();
            $stmt->close();

            if (!$fila) {
                $db->rollback();
                return false;
            }

            // Idempotencia: Si ya está cancelada, devolver éxito sin duplicar acciones
            if (isset($fila['estado']) && $fila['estado'] === 'cancelada') {
                $db->commit();
                return true;
            }

            if ($fila['estado'] !== 'confirmada') {
                throw new PersistenceException('Esta cita ya no permite cancelación.');
            }

            $actorValido = in_array($actor, ['cliente', 'admin', 'sistema'], true) ? $actor : 'cliente';
            $canceladoAt = $fechaHoraCancelacion ?? date('Y-m-d H:i:s');
            $motivoValido = ($motivo !== null && trim($motivo) !== '') ? mb_substr(trim($motivo), 0, 255) : null;

            $stmtUpdate = $db->prepare(
                "UPDATE citas 
                 SET estado = 'cancelada', 
                     cancelado_por = ?, 
                     cancelado_at = ?, 
                     motivo_cancelacion = ? 
                 WHERE id = ?"
            );
            if (!$stmtUpdate) {
                throw new PersistenceException("Fallo al preparar actualización de cancelación: " . $db->error);
            }
            $stmtUpdate->bind_param('sssi', $actorValido, $canceladoAt, $motivoValido, $citaId);
            if (!$stmtUpdate->execute()) {
                $err = $stmtUpdate->error;
                $stmtUpdate->close();
                throw new PersistenceException("Fallo al ejecutar cancelación lógica: " . $err);
            }
            $stmtUpdate->close();

            // Registrar evento Outbox de cancelación en la misma conexión y transacción antes del commit
            $notifRepo = new NotificacionCitaRepository($db);
            $ahoraUtc = gmdate('Y-m-d H:i:s');
            $claveCancelacion = "cita:{$citaId}:cancelacion";
            $snapshotParaOutbox = $notifRepo->obtenerSnapshotCompletoCitaEnConexion($citaId, $db);
            if ($snapshotParaOutbox !== null) {
                $snapshotParaOutbox['tipo_notificacion'] = NotificacionCita::TIPO_CANCELACION;
                $snapshotParaOutbox['cancelado_por'] = $actorValido;
                $snapshotParaOutbox['cancelado_at'] = $canceladoAt;
                $snapshotParaOutbox['motivo_cancelacion'] = $motivoValido;

                $emailCliente = trim((string)($snapshotParaOutbox['cliente_email'] ?? ''));
                $nombreCliente = trim((string)($snapshotParaOutbox['cliente_nombre'] ?? 'Cliente AppSalon'));
                $usuarioIdCita = isset($snapshotParaOutbox['usuario_id']) && (int)$snapshotParaOutbox['usuario_id'] > 0
                    ? (int)$snapshotParaOutbox['usuario_id']
                    : null;
                $asunto = "Cancelación de tu cita #{$citaId} — AppSalon";

                $notifRepo->registrarEventoOutboxEnConexion([
                    'cita_id' => $citaId,
                    'cita_referencia_id' => $citaId,
                    'usuario_id' => $usuarioIdCita,
                    'tipo' => NotificacionCita::TIPO_CANCELACION,
                    'clave_idempotencia' => $claveCancelacion,
                    'destinatario_email' => $emailCliente,
                    'destinatario_nombre' => $nombreCliente,
                    'asunto' => $asunto,
                    'payload_json' => $snapshotParaOutbox,
                ], $ahoraUtc, $db);
            }

            if (!$db->commit()) {
                throw new PersistenceException("Fallo al confirmar transacción de cancelación lógica.");
            }

            return true;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log("[CitaRepository::cancelarCitaLogica] Error adicional en rollback: " . $rollbackError->getMessage());
            }

            if ($e instanceof PersistenceException) {
                throw $e;
            }

            error_log("[CitaRepository::cancelarCitaLogica] Error inesperado en cancelación: " . $e->getMessage());
            throw new PersistenceException("Fallo en la persistencia al cancelar lógicamente la cita.", 0, $e);
        }
    }

    /**
     * Consulta las citas y sus servicios asociados para una fecha específica (`AAAA-MM-DD`)
     * destinadas al panel de administración.
     * Utiliza `COALESCE` para priorizar los datos históricos capturados al reservar
     * (`nombre_servicio`, `precio_servicio`, `duracion_minutos`) frente a modificaciones
     * o eliminaciones posteriores en el catálogo `servicios`, manteniendo compatibilidad
     * con citas históricas previas donde dichas columnas son `NULL`.
     *
     * @param string $fecha Fecha en formato `AAAA-MM-DD`.
     * @return array<int, AdminCita> Lista de filas hidratadas como AdminCita.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findAdminCitasByFecha(string $fecha): array
    {
        $db = $this->resolveDb();

        $consulta = "SELECT citas.id, citas.hora, citas.hora_inicio, citas.hora_fin, ";
        $consulta .= " citas.duracion_total_minutos, citas.profesionalId, ";
        $consulta .= " citas.estado, citas.cancelado_por, citas.cancelado_at, citas.motivo_cancelacion, citas.estado_pago, ";
        $consulta .= " profesionales.nombre as profesional_nombre, ";
        $consulta .= " CONCAT(usuarios.nombre, ' ', usuarios.apellido) as cliente, ";
        $consulta .= " usuarios.email, usuarios.telefono, ";
        $consulta .= " COALESCE(citasservicios.nombre_servicio, servicios.nombre) as servicio, ";
        $consulta .= " COALESCE(citasservicios.precio_servicio, servicios.precio) as precio, ";
        $consulta .= " COALESCE(citasservicios.duracion_minutos, servicios.duracion_minutos) as duracion_minutos ";
        $consulta .= " FROM citas ";
        $consulta .= " LEFT OUTER JOIN usuarios ON citas.usuarioId = usuarios.id ";
        $consulta .= " LEFT OUTER JOIN profesionales ON citas.profesionalId = profesionales.id ";
        $consulta .= " LEFT OUTER JOIN citasservicios ON citasservicios.citaId = citas.id ";
        $consulta .= " LEFT OUTER JOIN servicios ON servicios.id = citasservicios.servicioId ";
        $consulta .= " WHERE citas.fecha = ? AND citas.estado = 'confirmada' ";
        $consulta .= " ORDER BY citas.id ASC, citasservicios.id ASC ";

        try {
            $stmt = $db->prepare($consulta);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta administrativa de citas: " . $db->error);
            }

            $stmt->bind_param('s', $fecha);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta administrativa de citas: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados administrativos de citas: " . $err);
            }

            $citas = [];
            while ($fila = $resultado->fetch_assoc()) {
                $citas[] = new AdminCita($fila);
            }

            $resultado->free();
            $stmt->close();

            return $citas;
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::findAdminCitasByFecha] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::findAdminCitasByFecha] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar citas administrativas.", 0, $e);
        }
    }

    /**
     * Consulta las citas pertenecientes a un cliente específico (`usuarioId`), agrupando sus servicios
     * con el snapshot histórico (`COALESCE(citasservicios.*, servicios.*)`) e identificando explícitamente
     * el profesional asignado o la condición de cita histórica sin profesional (`profesionalId = NULL`).
     *
     * @param int $usuarioId Identificador entero positivo del cliente.
     * @param bool $soloConfirmadas Si true, restringe la consulta a citas activas (estado = 'confirmada').
     * @return array<int, array<string, mixed>> Lista de citas agrupadas del cliente.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findCitasClienteByUsuarioId(int $usuarioId, bool $soloConfirmadas = true): array
    {
        if ($usuarioId <= 0) {
            return [];
        }

        $db = $this->resolveDb();

        $condEstado = $soloConfirmadas ? " AND citas.estado = 'confirmada'" : "";
        $sql = "SELECT citas.id, citas.fecha, citas.hora, citas.hora_inicio, citas.hora_fin,
                       citas.duracion_total_minutos, citas.usuarioId, citas.profesionalId,
                       citas.estado, citas.cancelado_por, citas.cancelado_at, citas.motivo_cancelacion, citas.estado_pago,
                       profesionales.nombre AS profesional_nombre,
                       citasservicios.id AS cita_servicio_id,
                       citasservicios.servicioId AS servicio_id,
                       COALESCE(citasservicios.nombre_servicio, servicios.nombre) AS servicio_nombre,
                       COALESCE(citasservicios.precio_servicio, servicios.precio) AS servicio_precio,
                       COALESCE(citasservicios.duracion_minutos, servicios.duracion_minutos) AS servicio_duracion
                FROM citas
                LEFT OUTER JOIN profesionales ON citas.profesionalId = profesionales.id
                LEFT OUTER JOIN citasservicios ON citasservicios.citaId = citas.id
                LEFT OUTER JOIN servicios ON servicios.id = citasservicios.servicioId
                WHERE citas.usuarioId = ?{$condEstado}
                ORDER BY citas.fecha DESC, COALESCE(citas.hora_inicio, citas.hora) DESC, citas.id DESC, citasservicios.id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de citas del cliente: " . $db->error);
            }

            $stmt->bind_param('i', $usuarioId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de citas del cliente: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener citas del cliente: " . $err);
            }

            $agrupadas = [];
            while ($fila = $resultado->fetch_assoc()) {
                $citaId = (int)$fila['id'];
                if (!isset($agrupadas[$citaId])) {
                    $profId = ($fila['profesionalId'] !== null && ctype_digit((string)$fila['profesionalId']) && (int)$fila['profesionalId'] > 0)
                        ? (int)$fila['profesionalId']
                        : null;
                    $horaNorm = HorarioProfesional::normalizarHora((string)($fila['hora'] ?? ''));
                    $horaInicioNorm = ($fila['hora_inicio'] !== null && trim((string)$fila['hora_inicio']) !== '')
                        ? HorarioProfesional::normalizarHora((string)$fila['hora_inicio'])
                        : null;
                    $horaFinNorm = ($fila['hora_fin'] !== null && trim((string)$fila['hora_fin']) !== '')
                        ? HorarioProfesional::normalizarHora((string)$fila['hora_fin'])
                        : null;
                    $durTotal = ($fila['duracion_total_minutos'] !== null && ctype_digit((string)$fila['duracion_total_minutos']))
                        ? (int)$fila['duracion_total_minutos']
                        : null;

                    $agrupadas[$citaId] = [
                        'id' => $citaId,
                        'usuarioId' => (int)$fila['usuarioId'],
                        'fecha' => (string)$fila['fecha'],
                        'hora' => $horaNorm !== '' ? $horaNorm : (string)$fila['hora'],
                        'hora_inicio' => $horaInicioNorm !== '' ? $horaInicioNorm : null,
                        'hora_fin' => $horaFinNorm !== '' ? $horaFinNorm : null,
                        'duracion_total_minutos' => $durTotal,
                        'profesionalId' => $profId,
                        'profesional_nombre' => ($profId !== null && isset($fila['profesional_nombre']) && trim((string)$fila['profesional_nombre']) !== '')
                            ? trim((string)$fila['profesional_nombre'])
                            : null,
                        'es_historica_sin_profesional' => ($profId === null),
                        'estado' => (string)($fila['estado'] ?? 'confirmada'),
                        'cancelado_por' => $fila['cancelado_por'] ?? null,
                        'cancelado_at' => $fila['cancelado_at'] ?? null,
                        'motivo_cancelacion' => $fila['motivo_cancelacion'] ?? null,
                        'estado_pago' => (string)($fila['estado_pago'] ?? 'pendiente'),
                        'servicios' => [],
                        'total' => 0.0,
                        'total_formateado' => '0.00',
                    ];
                }

                if ($fila['cita_servicio_id'] !== null) {
                    $precioNum = is_numeric($fila['servicio_precio'] ?? null) ? (float)$fila['servicio_precio'] : 0.0;
                    $durServicio = ($fila['servicio_duracion'] !== null && ctype_digit((string)$fila['servicio_duracion']))
                        ? (int)$fila['servicio_duracion']
                        : null;

                    $agrupadas[$citaId]['servicios'][] = [
                        'id' => ($fila['servicio_id'] !== null && ctype_digit((string)$fila['servicio_id']))
                            ? (int)$fila['servicio_id']
                            : null,
                        'nombre' => (string)($fila['servicio_nombre'] ?? 'Servicio histórico'),
                        'precio' => number_format($precioNum, 2, '.', ''),
                        'duracion_minutos' => $durServicio,
                    ];

                    $agrupadas[$citaId]['total'] = round($agrupadas[$citaId]['total'] + $precioNum, 2);
                    $agrupadas[$citaId]['total_formateado'] = number_format($agrupadas[$citaId]['total'], 2, '.', '');
                }
            }

            $resultado->free();
            $stmt->close();

            return array_values($agrupadas);
        } catch (PersistenceException $e) {
            error_log("[CitaRepository::findCitasClienteByUsuarioId] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[CitaRepository::findCitasClienteByUsuarioId] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar las citas del cliente.", 0, $e);
        }
    }
    /**
     * Solo para pruebas de concurrencia (patrón de Idempotencia::$observadorTrasReserva): se invoca con la cita ya bloqueada y leída,
     * antes de validar y escribir, para ensanchar la ventana de carrera de forma determinista. Nulo en producción.
     * @var (callable(): void)|null
     */
    public static $observadorTrasBloqueoAgenda = null;

    /** Operaciones de agenda: bloqueo de profesional antes de cita, igual que una reserva. */
    public function mutarAgenda(int $id, int $actorId, bool $gestiona, bool $profesionalPropio, string $operacion, array $datos, string $ahora): array
    {
        $db = $this->resolveDb();
        $error = static fn(int $code, string $msg): array => ['resultado' => false, 'httpCode' => $code, 'error' => $msg];
        try {
            $previa = $this->findById($id);
            if (!$previa) return $error(404, 'Cita no encontrada.');
            if (!$db->begin_transaction()) throw new PersistenceException('No se pudo iniciar la operación.');
            $prof = null;
            if ($previa->profesionalId) {
                $stmt = $db->prepare("SELECT p.id,p.usuarioId,IF(p.colaborador_id IS NULL OR EXISTS(SELECT 1 FROM colaboradores c WHERE c.id=p.colaborador_id AND c.rol='profesional' AND c.estado='activo'),p.activo,0) activo FROM profesionales p WHERE p.id=? FOR UPDATE");
                if (!$stmt) throw new PersistenceException('No se pudo bloquear el profesional.');
                $pid = (int)$previa->profesionalId;
                $stmt->bind_param('i', $pid); if (!$stmt->execute()) throw new PersistenceException('Fallo de escritura de agenda.');
                $prof = $stmt->get_result()->fetch_assoc(); $stmt->close();
            }
            $stmt = $db->prepare('SELECT * FROM citas WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $id); if (!$stmt->execute()) throw new PersistenceException('Fallo de escritura de agenda.');
            $fila = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$fila) { $db->rollback(); return $error(404, 'Cita no encontrada.'); }
            if ((string)$fila['profesionalId'] !== (string)$previa->profesionalId) { $db->rollback(); return $error(409, 'La cita cambió. Vuelve a intentarlo.'); }
            if (self::$observadorTrasBloqueoAgenda !== null) { (self::$observadorTrasBloqueoAgenda)(); }
            $esCliente = (int)$fila['usuarioId'] === $actorId;
            $esProfesional = $profesionalPropio && $prof && (int)$prof['usuarioId'] === $actorId;
            if (!$gestiona && !(($operacion === 'reprogramar' && $esCliente) || ($operacion === 'estado' && $esProfesional))) {
                $db->rollback(); return $error(403, 'No tienes permiso para modificar esta cita.');
            }
            $anterior = $fila['estado'];
            $antes = json_encode(['estado' => $anterior, 'fecha' => $fila['fecha'], 'hora' => $fila['hora_inicio'] ?: $fila['hora']], JSON_THROW_ON_ERROR);
            $mensajeOk = 'Cita actualizada correctamente.';
            $datosEvento = [];
            if ($operacion === 'estado') {
                $nuevo = $datos['estado'] ?? '';
                $accion = \Classes\ReglaAtencion::accionDeTransicion($nuevo);
                // Idempotencia: repetir la transición ya aplicada (doble clic, reintento, proceso que perdió la carrera) no escribe nada.
                if ($nuevo === $anterior) {
                    if (!$db->commit()) throw new PersistenceException('No se pudo confirmar la operación.');
                    return ['resultado' => true, 'httpCode' => 200, 'idempotente' => true, 'estado' => $anterior, 'accion' => $accion, 'cita_id' => $id, 'mensaje' => 'Estado ya actualizado.'];
                }
                // Regla única (estado + tiempo) compartida con la agenda: lo que el botón ofrece es lo que se acepta aquí.
                $zona = new \DateTimeZone(\Services\CitaService::TIMEZONE);
                $ahoraDt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $ahora, $zona);
                $validacion = \Classes\ReglaAtencion::validarTransicion((string)$anterior, (string)$nuevo, (string)$fila['fecha'], (string)($fila['hora_inicio'] ?: $fila['hora']), $ahoraDt);
                if (!$validacion['ok']) {
                    $db->rollback();
                    return $error($validacion['httpCode'], $validacion['error']) + ['estado_actual' => $anterior];
                }
                // Compare-and-set: además del bloqueo FOR UPDATE, la escritura exige que el estado siga siendo el leído.
                $columnas = ['en_servicio' => ['atencion_inicio_at', 'atencion_inicio_por'], 'completada' => ['atencion_fin_at', 'atencion_fin_por'], 'no_asistio' => ['ausencia_at', 'ausencia_por']][$nuevo];
                $stmt = $db->prepare("UPDATE citas SET estado = ?, {$columnas[0]} = ?, {$columnas[1]} = ? WHERE id = ? AND estado = ?");
                $stmt->bind_param('ssiis', $nuevo, $ahora, $actorId, $id, $anterior); if (!$stmt->execute()) throw new PersistenceException('Fallo de escritura de agenda.');
                $filas = $stmt->affected_rows; $stmt->close();
                if ($filas !== 1) { $db->rollback(); return $error(409, 'La cita cambió mientras se procesaba. Vuelve a abrirla para ver su estado actual.'); }
                $despues = json_encode(['estado' => $nuevo, 'accion' => $accion, 'origen' => $gestiona ? 'gestion' : 'profesional_propio'], JSON_THROW_ON_ERROR);
                $mensajeOk = ['iniciar' => 'Atención iniciada.', 'finalizar' => 'Atención finalizada: la cita quedó como atendida.', 'ausencia' => 'Ausencia registrada.'][$accion] ?? $mensajeOk;
                $datosEvento = ['estado' => $nuevo, 'accion' => $accion];
            } elseif ($operacion === 'reprogramar') {
                if ($anterior !== 'confirmada') { $db->rollback(); return $error(409, 'Sólo se reprograman citas confirmadas.'); }
                // Una cita CONFIRMADA de un día anterior (pendiente de revisión) sí se reprograma, solo por la gestión: es el camino cuando el
                // cliente llega otro día. El historial conserva el horario original (antes_json) y la fecha real del cambio (creado_en).
                $pendienteRevision = $gestiona && (string)$fila['fecha'] < substr($ahora, 0, 10);
                if (($fila['fecha'] . ' ' . ($fila['hora_inicio'] ?: $fila['hora'])) <= $ahora && !$pendienteRevision) { $db->rollback(); return $error(422, 'La cita ya comenzó.'); }
                if (!$prof || (int)$prof['activo'] !== 1 || !(int)$fila['duracion_total_minutos']) { $db->rollback(); return $error(422, 'Esta cita histórica requiere gestión personalizada del salón.'); }
                $fecha = $datos['fecha']; $hora = $datos['hora']; $dia = (int)$datos['dia'];
                if ($fecha === $fila['fecha'] && $hora === substr($fila['hora_inicio'] ?: $fila['hora'], 0, 5)) { if (!$db->commit()) throw new PersistenceException('No se pudo confirmar la operación.'); return ['resultado' => true, 'httpCode' => 200, 'mensaje' => 'Horario ya actualizado.']; }
                $inicioMin = $this->horaAMinutosInterno($hora);
                $finMin = $inicioMin + (int)$fila['duracion_total_minutos'];
                if ($finMin >= 1440 || !$this->cabeEnAgendaDelDia($inicioMin, $finMin, $dia, $fecha,
                    $this->cargarHorariosDiaForShare($db, $pid, $dia), $this->cargarDescansosDiaForShare($db, $pid, $dia), $this->cargarBloqueosFechaForShare($db, $pid, $fecha))) {
                    $db->rollback(); return $error(409, 'La duración histórica no cabe en el horario del profesional.');
                }
                foreach ($this->cargarCitasProfesionalEnFechaForUpdate($db, $pid, $fecha) as $ocupada) {
                    if ((int)$ocupada->id === $id) continue;
                    [$oi, $of] = $this->resolverIntervaloCitaOcupada($ocupada);
                    if ($inicioMin < $of && $finMin > $oi) { $db->rollback(); return $error(409, 'El horario ya está ocupado.'); }
                }
                $fin = sprintf('%02d:%02d:00', intdiv($finMin, 60), $finMin % 60);
                $stmt = $db->prepare('UPDATE citas SET fecha = ?, hora = ?, hora_inicio = ?, hora_fin = ? WHERE id = ?');
                $stmt->bind_param('ssssi', $fecha, $hora, $hora, $fin, $id); if (!$stmt->execute()) throw new PersistenceException('Fallo de escritura de agenda.'); $stmt->close();
                $despues = json_encode(['fecha' => $fecha, 'hora' => $hora, 'hora_fin' => $fin] + ($pendienteRevision ? ['desde_pendiente_revision' => true] : []), JSON_THROW_ON_ERROR);
                // Outbox durable dentro de la misma transacción; no se envía SMTP bajo bloqueo.
                $stmt = $db->prepare("UPDATE notificaciones_citas SET estado = 'descartada', ultimo_error = 'Horario reemplazado por reprogramación', proximo_intento_at = NULL WHERE cita_id = ? AND tipo IN ('recordatorio', 'confirmacion') AND estado IN ('pendiente', 'fallido', 'reintentando')");
                $stmt->bind_param('i', $id); if (!$stmt->execute()) throw new PersistenceException('No se pudo reemplazar el recordatorio.'); $stmt->close();
                $notificaciones = new NotificacionCitaRepository($db);
                $snapshot = $notificaciones->obtenerSnapshotCompletoCitaEnConexion($id, $db);
                if ($snapshot) {
                    $snapshot['tipo_notificacion'] = 'confirmacion';
                    $snapshot['reprogramada'] = true;
                    $snapshot['horario_anterior'] = json_decode($antes, true);
                    $notificaciones->registrarEventoOutboxEnConexion([
                        'cita_id' => $id, 'cita_referencia_id' => $id, 'usuario_id' => (int)$fila['usuarioId'],
                        'tipo' => 'confirmacion', 'clave_idempotencia' => 'cita:' . $id . ':reprogramacion:' . bin2hex(random_bytes(16)),
                        'destinatario_email' => $snapshot['cliente_email'], 'destinatario_nombre' => $snapshot['cliente_nombre'],
                        'asunto' => 'Nuevo horario de tu cita #' . $id . ' — AppSalon', 'payload_json' => $snapshot,
                    ], gmdate('Y-m-d H:i:s'), $db);
                }

            } else { $db->rollback(); return $error(422, 'Operación no permitida.'); }
            $stmt = $db->prepare('INSERT INTO agenda_eventos (citaId, actorId, tipo, antes_json, despues_json, creado_en) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iissss', $id, $actorId, $operacion, $antes, $despues, $ahora); if (!$stmt->execute()) throw new PersistenceException('Fallo de escritura de agenda.'); $stmt->close();
            if (!$db->commit()) throw new PersistenceException('No se pudo confirmar la operación.');
            return ['resultado' => true, 'httpCode' => 200, 'idempotente' => false, 'cita_id' => $id, 'mensaje' => $mensajeOk] + $datosEvento;
        } catch (\Throwable $e) {
            try { $db->rollback(); } catch (\Throwable $rollbackError) { error_log('[Agenda] Conexión perdida durante rollback.'); }
            error_log('[Agenda] Operación revertida: ' . $e->getMessage());
            return $error(500, 'No se pudo actualizar la agenda. No se aplicaron cambios.');
        }
    }

    /**
     * Lista limitada de un período; el alcance profesional se aplica en SQL. `$estado` filtra por estado de la cita (null = todos).
     * Incluye quién inició, finalizó o registró la ausencia (nombres de las cuentas, no ids sueltos).
     */
    public function listarAgenda(string $desde, string $hasta, ?int $profesionalUsuario = null, ?string $estado = null): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT c.*, CONCAT(u.nombre, ' ', u.apellido) cliente, p.nombre profesional_nombre,
                (SELECT GROUP_CONCAT(COALESCE(cs.nombre_servicio, s.nombre) SEPARATOR ', ') FROM citasservicios cs LEFT JOIN servicios s ON s.id = cs.servicioId WHERE cs.citaId = c.id) servicios,
                CONCAT(ui.nombre, ' ', ui.apellido) atencion_inicio_por_nombre, CONCAT(uf.nombre, ' ', uf.apellido) atencion_fin_por_nombre, CONCAT(ua.nombre, ' ', ua.apellido) ausencia_por_nombre,
                CONCAT(ur.nombre, ' ', ur.apellido) regularizada_por_nombre,
                (SELECT rg.id FROM citas_regularizaciones rg WHERE rg.cita_id = c.id AND rg.estado = 'solicitada' LIMIT 1) solicitud_abierta_id
                FROM citas c JOIN usuarios u ON u.id = c.usuarioId LEFT JOIN profesionales p ON p.id = c.profesionalId
                LEFT JOIN usuarios ui ON ui.id = c.atencion_inicio_por LEFT JOIN usuarios uf ON uf.id = c.atencion_fin_por LEFT JOIN usuarios ua ON ua.id = c.ausencia_por
                LEFT JOIN usuarios ur ON ur.id = c.regularizada_por
                WHERE c.fecha BETWEEN ? AND ?";
        $tipos = 'ss'; $valores = [$desde, $hasta];
        if ($profesionalUsuario !== null) { $sql .= ' AND p.usuarioId = ?'; $tipos .= 'i'; $valores[] = $profesionalUsuario; }
        if ($estado !== null) { $sql .= ' AND c.estado = ?'; $tipos .= 's'; $valores[] = $estado; }
        $sql .= ' ORDER BY c.fecha, c.hora, c.id LIMIT 500';
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new PersistenceException('No se pudo consultar la agenda.');
        $stmt->bind_param($tipos, ...$valores);
        if (!$stmt->execute()) throw new PersistenceException('No se pudo consultar la agenda.');
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
    }

    /**
     * Citas «pendientes de revisión»: confirmadas o en servicio cuyo día ya pasó (`fecha` < $hoy). No se tocan ni se marcan solas.
     * El alcance profesional se aplica en SQL. Orden: la más antigua primero.
     * @return array<int,array<string,mixed>>
     */
    public function listarPendientesRevision(string $hoy, ?int $profesionalUsuario = null, int $limite = 500): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT c.*, CONCAT(u.nombre, ' ', u.apellido) cliente, p.nombre profesional_nombre,
                (SELECT GROUP_CONCAT(COALESCE(cs.nombre_servicio, s.nombre) SEPARATOR ', ') FROM citasservicios cs LEFT JOIN servicios s ON s.id = cs.servicioId WHERE cs.citaId = c.id) servicios,
                CONCAT(ui.nombre, ' ', ui.apellido) atencion_inicio_por_nombre,
                (SELECT rg.id FROM citas_regularizaciones rg WHERE rg.cita_id = c.id AND rg.estado = 'solicitada' LIMIT 1) solicitud_abierta_id
                FROM citas c JOIN usuarios u ON u.id = c.usuarioId LEFT JOIN profesionales p ON p.id = c.profesionalId
                LEFT JOIN usuarios ui ON ui.id = c.atencion_inicio_por
                WHERE c.estado IN ('confirmada', 'en_servicio') AND c.fecha < ?";
        $tipos = 's'; $valores = [$hoy];
        if ($profesionalUsuario !== null) { $sql .= ' AND p.usuarioId = ?'; $tipos .= 'i'; $valores[] = $profesionalUsuario; }
        $sql .= ' ORDER BY c.fecha, c.hora, c.id LIMIT ' . max(1, min(500, $limite));
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new PersistenceException('No se pudo consultar las citas pendientes de revisión.');
        $stmt->bind_param($tipos, ...$valores);
        if (!$stmt->execute()) throw new PersistenceException('No se pudo consultar las citas pendientes de revisión.');
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
    }

    /**
     * Detalle de una cita para el equipo. `$profesionalUsuario` limita a la agenda propia (null = toda la agenda): una cita ajena
     * es indistinguible de una inexistente (devuelve null). Los precios históricos solo se leen si `$conFinanzas`.
     *
     * @return array<string,mixed>|null cita + `servicios` (nombre, duración y, con finanzas, precio histórico) + `historial` (eventos de agenda)
     */
    public function obtenerDetalleAgenda(int $id, ?int $profesionalUsuario, bool $conFinanzas): ?array
    {
        $db = $this->resolveDb();
        $sql = "SELECT c.*, CONCAT(u.nombre, ' ', u.apellido) cliente, p.nombre profesional_nombre,
                CONCAT(ui.nombre, ' ', ui.apellido) atencion_inicio_por_nombre, CONCAT(uf.nombre, ' ', uf.apellido) atencion_fin_por_nombre, CONCAT(ua.nombre, ' ', ua.apellido) ausencia_por_nombre,
                CONCAT(ur.nombre, ' ', ur.apellido) regularizada_por_nombre,
                (SELECT rg.id FROM citas_regularizaciones rg WHERE rg.cita_id = c.id AND rg.estado = 'solicitada' LIMIT 1) solicitud_abierta_id
                FROM citas c JOIN usuarios u ON u.id = c.usuarioId LEFT JOIN profesionales p ON p.id = c.profesionalId
                LEFT JOIN usuarios ui ON ui.id = c.atencion_inicio_por LEFT JOIN usuarios uf ON uf.id = c.atencion_fin_por LEFT JOIN usuarios ua ON ua.id = c.ausencia_por
                LEFT JOIN usuarios ur ON ur.id = c.regularizada_por
                WHERE c.id = ?" . ($profesionalUsuario !== null ? ' AND p.usuarioId = ?' : '');
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new PersistenceException('No se pudo consultar la cita.');
        if ($profesionalUsuario !== null) $stmt->bind_param('ii', $id, $profesionalUsuario); else $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new PersistenceException('No se pudo consultar la cita.');
        $cita = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$cita) return null;
        if (!$conFinanzas) unset($cita['estado_pago']);

        $columnasPrecio = $conFinanzas ? ', cs.precio_servicio' : '';
        $stmt = $db->prepare("SELECT COALESCE(cs.nombre_servicio, s.nombre) nombre, cs.duracion_minutos{$columnasPrecio} FROM citasservicios cs LEFT JOIN servicios s ON s.id = cs.servicioId WHERE cs.citaId = ? ORDER BY cs.id");
        if (!$stmt) throw new PersistenceException('No se pudo consultar los servicios de la cita.');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new PersistenceException('No se pudo consultar los servicios de la cita.');
        $cita['servicios'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $stmt = $db->prepare("SELECT e.tipo, e.antes_json, e.despues_json, e.creado_en, CONCAT(a.nombre, ' ', a.apellido) actor FROM agenda_eventos e LEFT JOIN usuarios a ON a.id = e.actorId WHERE e.citaId = ? ORDER BY e.creado_en, e.id LIMIT 50");
        if (!$stmt) throw new PersistenceException('No se pudo consultar el historial de la cita.');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new PersistenceException('No se pudo consultar el historial de la cita.');
        $cita['historial'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        return $cita;
    }
}
