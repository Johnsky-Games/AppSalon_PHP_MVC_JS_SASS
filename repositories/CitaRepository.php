<?php

namespace Repositories;

use Model\ActiveRecord;
use Model\AdminCita;
use Model\BloqueoProfesional;
use Model\Cita;
use Model\CitaServicio;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Servicio;
use mysqli;

/**
 * Repositorio para consultas y persistencia transaccional de `citas` y `citasservicios`,
 * incluyendo reservas con profesional bajo bloqueo pesimista InnoDB (`FOR UPDATE` / `FOR SHARE`),
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
                "SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId
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
                "SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId
                 FROM citas
                 WHERE profesionalId = ? AND fecha = ?
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
     * 2. `servicios` (`FOR SHARE`, ordenados por `id ASC`)
     * 3. `profesionales_servicios` (`FOR SHARE`)
     * 4. `horarios_profesionales`, `descansos_profesionales`, `bloqueos_profesionales` (`FOR SHARE`)
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
            $stmtProf = $db->prepare("SELECT id, nombre, activo FROM profesionales WHERE id = ? LIMIT 1 FOR UPDATE");
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

            // 2. Lectura actual con bloqueo compartido sobre servicios en orden ascendente de ID (`FOR SHARE`)
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

            // 3. Lectura actual de compatibilidad profesional-servicios (`FOR SHARE`)
            $stmtRel = $db->prepare(
                "SELECT servicioId
                 FROM profesionales_servicios
                 WHERE profesionalId = ?
                 ORDER BY servicioId ASC
                 FOR SHARE"
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

            // 4. Lectura actual de horarios, descansos y bloqueos del profesional (`FOR SHARE`)
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
     * Lee y bloquea (`FOR SHARE`) los servicios solicitados en orden ascendente de ID.
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

        $stmt = $db->prepare("SELECT id, nombre, precio, duracion_minutos FROM servicios WHERE id = ? LIMIT 1 FOR SHARE");
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
             FOR SHARE"
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
             FOR SHARE"
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
             FOR SHARE"
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
            "SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId
             FROM citas
             WHERE profesionalId = ? AND fecha = ?
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
    public function eliminarCitaAtomica(int $citaId): bool
    {
        if ($citaId <= 0) {
            return false;
        }

        $db = $this->resolveDb();

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
                $db->rollback();
                return false;
            }

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

            if ($e instanceof PersistenceException) {
                throw $e;
            }

            error_log("[CitaRepository::eliminarCitaAtomica] Rollback ejecutado por error: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al eliminar la cita.", 0, $e);
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
        $consulta .= " WHERE fecha = ? ";

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
     * @return array<int, array<string, mixed>> Lista de citas agrupadas del cliente.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findCitasClienteByUsuarioId(int $usuarioId): array
    {
        if ($usuarioId <= 0) {
            return [];
        }

        $db = $this->resolveDb();

        $sql = "SELECT citas.id, citas.fecha, citas.hora, citas.hora_inicio, citas.hora_fin,
                       citas.duracion_total_minutos, citas.usuarioId, citas.profesionalId,
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
                WHERE citas.usuarioId = ?
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
}

