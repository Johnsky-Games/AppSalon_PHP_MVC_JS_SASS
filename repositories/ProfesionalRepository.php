<?php

namespace Repositories;

use Model\ActiveRecord;
use Model\BloqueoProfesional;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Profesional;
use mysqli;

/**
 * Repositorio para consultas y persistencia de profesionales, su relación con servicios
 * y la configuración de horarios semanales, descansos y bloqueos de agenda.
 * Utiliza exclusivamente sentencias preparadas, transacciones atómicas y PersistenceException.
 */
class ProfesionalRepository
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

    public function getDb(): ?mysqli
    {
        return $this->dbExplicitlyProvided ? $this->db : ($this->db ?? ActiveRecord::getDB());
    }

    private function resolveDb(): mysqli
    {
        $db = $this->getDb();
        if (!$db instanceof mysqli) {
            throw new PersistenceException('No hay conexión activa a la base de datos.');
        }
        return $db;
    }

    /**
     * Lista todos los profesionales (o solo los activos) incluyendo sus servicios asociados.
     *
     * @param bool $soloActivos Si es true, filtra únicamente profesionales con `activo = 1`.
     * @return array<int, Profesional>
     * @throws PersistenceException
     */
    public function findAll(bool $soloActivos = false): array
    {
        $db = $this->resolveDb();

        try {
            $sql = "SELECT id, nombre, activo, creado_en FROM profesionales";
            if ($soloActivos) {
                $sql .= " WHERE activo = 1";
            }
            $sql .= " ORDER BY id ASC";

            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar listado de profesionales: " . $db->error);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar listado de profesionales: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de profesionales: " . $err);
            }

            $profesionales = [];
            $porId = [];
            while ($fila = $res->fetch_assoc()) {
                $prof = new Profesional($fila);
                $profesionales[] = $prof;
                $porId[(int)$prof->id] = $prof;
            }
            $res->free();
            $stmt->close();

            if (!empty($porId)) {
                $this->cargarServiciosParaProfesionales($db, $porId);
            }

            return $profesionales;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findAll] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findAll] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al listar profesionales.", 0, $e);
        }
    }

    /**
     * Busca un profesional por ID e hidrata sus servicios asociados.
     *
     * @param int $id
     * @return Profesional|null
     * @throws PersistenceException
     */
    public function findById(int $id): ?Profesional
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare("SELECT id, nombre, activo, creado_en FROM profesionales WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda de profesional: " . $db->error);
            }

            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda de profesional: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultado de profesional: " . $err);
            }

            $fila = $res->fetch_assoc();
            $res->free();
            $stmt->close();

            if (!$fila) {
                return null;
            }

            $prof = new Profesional($fila);
            $mapa = [(int)$prof->id => $prof];
            $this->cargarServiciosParaProfesionales($db, $mapa);

            return $prof;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findById] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findById] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al buscar profesional.", 0, $e);
        }
    }

    /**
     * Hidrata los IDs y nombres de servicios para un mapa de profesionales indexado por ID.
     *
     * @param mysqli $db
     * @param array<int, Profesional> $porId
     * @throws PersistenceException
     */
    private function cargarServiciosParaProfesionales(mysqli $db, array $porId): void
    {
        $stmt = $db->prepare(
            "SELECT ps.profesionalId, ps.servicioId, s.nombre AS servicioNombre
             FROM profesionales_servicios ps
             INNER JOIN servicios s ON s.id = ps.servicioId
             ORDER BY ps.profesionalId ASC, ps.servicioId ASC"
        );
        if (!$stmt) {
            throw new PersistenceException("Error al preparar consulta de servicios por profesional: " . $db->error);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al ejecutar consulta de servicios por profesional: " . $err);
        }

        $res = $stmt->get_result();
        if ($res === false) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al obtener servicios por profesional: " . $err);
        }

        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['profesionalId'];
            if (isset($porId[$pid])) {
                $porId[$pid]->servicioIds[] = (int)$row['servicioId'];
                $porId[$pid]->serviciosNombres[] = (string)$row['servicioNombre'];
            }
        }

        $res->free();
        $stmt->close();
    }

    /**
     * Crea un profesional y sus relaciones en `profesionales_servicios` dentro de una única transacción.
     *
     * @param Profesional $profesional
     * @param array<int, int> $servicioIds
     * @return int ID autogenerado del profesional.
     * @throws PersistenceException
     */
    public function createWithServicios(Profesional $profesional, array $servicioIds): int
    {
        $db = $this->resolveDb();
        $inTransaction = false;

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar transacción para crear profesional: " . $db->error);
            }
            $inTransaction = true;

            $stmt = $db->prepare("INSERT INTO profesionales (nombre, activo) VALUES (?, ?)");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción de profesional: " . $db->error);
            }

            $nombre = (string)$profesional->nombre;
            $activo = (string)$profesional->activo === '0' ? 0 : 1;
            $stmt->bind_param('si', $nombre, $activo);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar inserción de profesional: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $profesionalId = (int)$db->insert_id;
            $stmt->close();

            if ($afectadas !== 1 || $profesionalId <= 0) {
                throw new PersistenceException("La inserción del profesional no afectó ninguna fila.");
            }

            $this->insertarServiciosProfesional($db, $profesionalId, $servicioIds);

            if (!$db->commit()) {
                throw new PersistenceException("Fallo al confirmar transacción de creación de profesional: " . $db->error);
            }
            $inTransaction = false;

            $profesional->id = (string)$profesionalId;
            $profesional->servicioIds = array_values(array_unique(array_map('intval', $servicioIds)));

            return $profesionalId;
        } catch (PersistenceException $e) {
            if ($inTransaction) {
                @$db->rollback();
            }
            error_log("[ProfesionalRepository::createWithServicios] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            if ($inTransaction) {
                @$db->rollback();
            }
            error_log("[ProfesionalRepository::createWithServicios] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo transaccional al crear profesional.", 0, $e);
        }
    }

    /**
     * Actualiza un profesional y sincroniza su lista de servicios dentro de una única transacción.
     *
     * @param Profesional $profesional
     * @param array<int, int> $servicioIds
     * @return bool True si el profesional existe y fue actualizado, false si no existe.
     * @throws PersistenceException
     */
    public function updateWithServicios(Profesional $profesional, array $servicioIds): bool
    {
        $id = filter_var($profesional->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return false;
        }

        $db = $this->resolveDb();
        $inTransaction = false;

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar transacción para actualizar profesional: " . $db->error);
            }
            $inTransaction = true;

            $stmt = $db->prepare("UPDATE profesionales SET nombre = ?, activo = ? WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar actualización de profesional: " . $db->error);
            }

            $nombre = (string)$profesional->nombre;
            $activo = (string)$profesional->activo === '0' ? 0 : 1;
            $stmt->bind_param('sii', $nombre, $activo, $id);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar actualización de profesional: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            if ($afectadas === 0 && !$this->existeProfesionalPorId($db, $id)) {
                $db->rollback();
                $inTransaction = false;
                return false;
            }

            $stmtDel = $db->prepare("DELETE FROM profesionales_servicios WHERE profesionalId = ?");
            if (!$stmtDel) {
                throw new PersistenceException("Error al preparar limpieza de servicios del profesional: " . $db->error);
            }
            $stmtDel->bind_param('i', $id);
            if (!$stmtDel->execute()) {
                $err = $stmtDel->error;
                $stmtDel->close();
                throw new PersistenceException("Error al limpiar servicios del profesional: " . $err);
            }
            $stmtDel->close();

            $this->insertarServiciosProfesional($db, $id, $servicioIds);

            if (!$db->commit()) {
                throw new PersistenceException("Fallo al confirmar actualización de profesional: " . $db->error);
            }
            $inTransaction = false;

            $profesional->servicioIds = array_values(array_unique(array_map('intval', $servicioIds)));
            return true;
        } catch (PersistenceException $e) {
            if ($inTransaction) {
                @$db->rollback();
            }
            error_log("[ProfesionalRepository::updateWithServicios] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            if ($inTransaction) {
                @$db->rollback();
            }
            error_log("[ProfesionalRepository::updateWithServicios] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo transaccional al actualizar profesional.", 0, $e);
        }
    }

    /**
     * Actualiza el estado activo/inactivo de un profesional conservando referencias históricas.
     *
     * @param int $id
     * @param int $activo 1 o 0.
     * @return bool True si el profesional existe y quedó con el estado solicitado, false si no existe.
     * @throws PersistenceException
     */
    public function updateActivo(int $id, int $activo): bool
    {
        if ($id <= 0) {
            return false;
        }

        $db = $this->resolveDb();
        $estado = $activo === 1 ? 1 : 0;

        try {
            $stmt = $db->prepare("UPDATE profesionales SET activo = ? WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar cambio de estado de profesional: " . $db->error);
            }

            $stmt->bind_param('ii', $estado, $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar cambio de estado de profesional: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            if ($afectadas > 0) {
                return true;
            }

            return $this->existeProfesionalPorId($db, $id);
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::updateActivo] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::updateActivo] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al actualizar estado del profesional.", 0, $e);
        }
    }

    private function existeProfesionalPorId(mysqli $db, int $id): bool
    {
        $stmt = $db->prepare("SELECT id FROM profesionales WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new PersistenceException("Error al preparar verificación de profesional: " . $db->error);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al verificar existencia de profesional: " . $err);
        }
        $res = $stmt->get_result();
        if ($res === false) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al leer existencia de profesional: " . $err);
        }
        $existe = $res->fetch_assoc() !== null;
        $res->free();
        $stmt->close();

        return $existe;
    }

    /**
     * @param mysqli $db
     * @param int $profesionalId
     * @param array<int, int> $servicioIds
     * @throws PersistenceException
     */
    private function insertarServiciosProfesional(mysqli $db, int $profesionalId, array $servicioIds): void
    {
        $idsUnicos = array_values(array_unique(array_map('intval', $servicioIds)));
        if (empty($idsUnicos)) {
            return;
        }

        $stmtIns = $db->prepare("INSERT INTO profesionales_servicios (profesionalId, servicioId) VALUES (?, ?)");
        if (!$stmtIns) {
            throw new PersistenceException("Error al preparar asignación de servicios al profesional: " . $db->error);
        }

        foreach ($idsUnicos as $sid) {
            $stmtIns->bind_param('ii', $profesionalId, $sid);
            if (!$stmtIns->execute()) {
                $err = $stmtIns->error;
                $stmtIns->close();
                throw new PersistenceException("Error al asignar servicio al profesional: " . $err);
            }
            if ($stmtIns->affected_rows !== 1) {
                $stmtIns->close();
                throw new PersistenceException("La asignación del servicio al profesional no afectó ninguna fila.");
            }
        }

        $stmtIns->close();
    }

    /**
     * Obtiene los horarios semanales de un profesional ordenados por día y hora de inicio.
     *
     * @param int $profesionalId
     * @return array<int, HorarioProfesional>
     * @throws PersistenceException
     */
    public function findHorariosByProfesional(int $profesionalId): array
    {
        if ($profesionalId <= 0) {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin
                 FROM horarios_profesionales
                 WHERE profesionalId = ?
                 ORDER BY dia_semana ASC, hora_inicio ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de horarios: " . $db->error);
            }

            $stmt->bind_param('i', $profesionalId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de horarios: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de horarios: " . $err);
            }

            $horarios = [];
            while ($fila = $res->fetch_assoc()) {
                $horarios[] = new HorarioProfesional($fila);
            }
            $res->free();
            $stmt->close();

            return $horarios;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findHorariosByProfesional] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findHorariosByProfesional] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar horarios.", 0, $e);
        }
    }

    /**
     * Obtiene todas las franjas laborales de un profesional para un día específico de la semana ISO-8601 (1..7),
     * ordenadas por hora de inicio.
     *
     * @param int $profesionalId
     * @param int $diaSemana 1 (Lunes) a 7 (Domingo)
     * @return array<int, HorarioProfesional>
     * @throws PersistenceException
     */
    public function findHorariosByProfesionalYDia(int $profesionalId, int $diaSemana): array
    {
        if ($profesionalId <= 0 || $diaSemana < 1 || $diaSemana > 7) {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin
                 FROM horarios_profesionales
                 WHERE profesionalId = ? AND dia_semana = ?
                 ORDER BY hora_inicio ASC, id ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de horarios por día: " . $db->error);
            }

            $stmt->bind_param('ii', $profesionalId, $diaSemana);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de horarios por día: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de horarios por día: " . $err);
            }

            $horarios = [];
            while ($fila = $res->fetch_assoc()) {
                $horarios[] = new HorarioProfesional($fila);
            }
            $res->free();
            $stmt->close();

            return $horarios;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findHorariosByProfesionalYDia] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findHorariosByProfesionalYDia] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar horarios del día.", 0, $e);
        }
    }

    /**
     * Reemplaza atómicamente todos los horarios semanales de un profesional.
     *
     * @param int $profesionalId
     * @param array<int, HorarioProfesional> $horarios
     * @throws PersistenceException
     */
    public function replaceHorarios(int $profesionalId, array $horarios): void
    {
        $db = $this->resolveDb();
        $inTransaction = false;

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar transacción de horarios: " . $db->error);
            }
            $inTransaction = true;

            $stmtDel = $db->prepare("DELETE FROM horarios_profesionales WHERE profesionalId = ?");
            if (!$stmtDel) {
                throw new PersistenceException("Error al preparar limpieza de horarios: " . $db->error);
            }
            $stmtDel->bind_param('i', $profesionalId);
            if (!$stmtDel->execute()) {
                $err = $stmtDel->error;
                $stmtDel->close();
                throw new PersistenceException("Error al limpiar horarios previos: " . $err);
            }
            $stmtDel->close();

            if (!empty($horarios)) {
                $stmtIns = $db->prepare(
                    "INSERT INTO horarios_profesionales (profesionalId, dia_semana, hora_inicio, hora_fin)
                     VALUES (?, ?, ?, ?)"
                );
                if (!$stmtIns) {
                    throw new PersistenceException("Error al preparar inserción de horarios: " . $db->error);
                }

                foreach ($horarios as $h) {
                    $dia = (int)$h->dia_semana;
                    $inicio = (string)$h->hora_inicio;
                    $fin = (string)$h->hora_fin;
                    $stmtIns->bind_param('iiss', $profesionalId, $dia, $inicio, $fin);

                    if (!$stmtIns->execute()) {
                        $err = $stmtIns->error;
                        $stmtIns->close();
                        throw new PersistenceException("Error al insertar horario semanal: " . $err);
                    }
                    if ($stmtIns->affected_rows !== 1) {
                        $stmtIns->close();
                        throw new PersistenceException("La inserción de horario semanal no afectó ninguna fila.");
                    }
                    $h->id = (string)$db->insert_id;
                    $h->profesionalId = (string)$profesionalId;
                }

                $stmtIns->close();
            }

            if (!$db->commit()) {
                throw new PersistenceException("Error al confirmar transacción de horarios: " . $db->error);
            }
            $inTransaction = false;
        } catch (PersistenceException $e) {
            if ($inTransaction) {
                @$db->rollback();
            }
            error_log("[ProfesionalRepository::replaceHorarios] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            if ($inTransaction) {
                @$db->rollback();
            }
            error_log("[ProfesionalRepository::replaceHorarios] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo transaccional al guardar horarios semanales.", 0, $e);
        }
    }

    /**
     * Obtiene los descansos recurrentes de un profesional ordenados por día y hora de inicio.
     *
     * @param int $profesionalId
     * @return array<int, DescansoProfesional>
     * @throws PersistenceException
     */
    public function findDescansosByProfesional(int $profesionalId): array
    {
        if ($profesionalId <= 0) {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin, motivo
                 FROM descansos_profesionales
                 WHERE profesionalId = ?
                 ORDER BY dia_semana ASC, hora_inicio ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de descansos: " . $db->error);
            }

            $stmt->bind_param('i', $profesionalId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de descansos: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de descansos: " . $err);
            }

            $descansos = [];
            while ($fila = $res->fetch_assoc()) {
                $descansos[] = new DescansoProfesional($fila);
            }
            $res->free();
            $stmt->close();

            return $descansos;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findDescansosByProfesional] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findDescansosByProfesional] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar descansos.", 0, $e);
        }
    }

    /**
     * Obtiene los descansos recurrentes de un profesional para un día específico de la semana ISO-8601 (1..7),
     * ordenados por hora de inicio.
     *
     * @param int $profesionalId
     * @param int $diaSemana 1 (Lunes) a 7 (Domingo)
     * @return array<int, DescansoProfesional>
     * @throws PersistenceException
     */
    public function findDescansosByProfesionalYDia(int $profesionalId, int $diaSemana): array
    {
        if ($profesionalId <= 0 || $diaSemana < 1 || $diaSemana > 7) {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin, motivo
                 FROM descansos_profesionales
                 WHERE profesionalId = ? AND dia_semana = ?
                 ORDER BY hora_inicio ASC, id ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de descansos por día: " . $db->error);
            }

            $stmt->bind_param('ii', $profesionalId, $diaSemana);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de descansos por día: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de descansos por día: " . $err);
            }

            $descansos = [];
            while ($fila = $res->fetch_assoc()) {
                $descansos[] = new DescansoProfesional($fila);
            }
            $res->free();
            $stmt->close();

            return $descansos;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findDescansosByProfesionalYDia] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findDescansosByProfesionalYDia] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar descansos del día.", 0, $e);
        }
    }

    /**
     * Inserta un descanso semanal para un profesional.
     *
     * @param DescansoProfesional $descanso
     * @return int ID autogenerado.
     * @throws PersistenceException
     */
    public function createDescanso(DescansoProfesional $descanso): int
    {
        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "INSERT INTO descansos_profesionales (profesionalId, dia_semana, hora_inicio, hora_fin, motivo)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción de descanso: " . $db->error);
            }

            $profId = (int)$descanso->profesionalId;
            $dia = (int)$descanso->dia_semana;
            $inicio = (string)$descanso->hora_inicio;
            $fin = (string)$descanso->hora_fin;
            $motivo = $descanso->motivo;

            $stmt->bind_param('iisss', $profId, $dia, $inicio, $fin, $motivo);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar inserción de descanso: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $insertId = (int)$db->insert_id;
            $stmt->close();

            if ($afectadas !== 1 || $insertId <= 0) {
                throw new PersistenceException("La inserción del descanso no afectó ninguna fila.");
            }

            $descanso->id = (string)$insertId;
            return $insertId;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::createDescanso] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::createDescanso] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al crear descanso.", 0, $e);
        }
    }

    /**
     * Elimina un descanso perteneciente al profesional indicado.
     *
     * @param int $profesionalId
     * @param int $descansoId
     * @return bool True si se eliminó, false si no existía.
     * @throws PersistenceException
     */
    public function deleteDescanso(int $profesionalId, int $descansoId): bool
    {
        if ($profesionalId <= 0 || $descansoId <= 0) {
            return false;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "DELETE FROM descansos_profesionales WHERE id = ? AND profesionalId = ? LIMIT 1"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar eliminación de descanso: " . $db->error);
            }

            $stmt->bind_param('ii', $descansoId, $profesionalId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar eliminación de descanso: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas > 0;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::deleteDescanso] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::deleteDescanso] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al eliminar descanso.", 0, $e);
        }
    }

    /**
     * Obtiene los bloqueos de agenda de un profesional ordenados por fecha y hora de inicio.
     *
     * @param int $profesionalId
     * @return array<int, BloqueoProfesional>
     * @throws PersistenceException
     */
    public function findBloqueosByProfesional(int $profesionalId): array
    {
        if ($profesionalId <= 0) {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo
                 FROM bloqueos_profesionales
                 WHERE profesionalId = ?
                 ORDER BY fecha_inicio ASC, hora_inicio ASC, id ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de bloqueos: " . $db->error);
            }

            $stmt->bind_param('i', $profesionalId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de bloqueos: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de bloqueos: " . $err);
            }

            $bloqueos = [];
            while ($fila = $res->fetch_assoc()) {
                $bloqueos[] = new BloqueoProfesional($fila);
            }
            $res->free();
            $stmt->close();

            return $bloqueos;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findBloqueosByProfesional] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findBloqueosByProfesional] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar bloqueos.", 0, $e);
        }
    }

    /**
     * Obtiene los bloqueos de agenda de un profesional aplicables a una fecha `AAAA-MM-DD`
     * (`fecha_inicio <= ? AND fecha_fin >= ?`).
     *
     * @param int $profesionalId
     * @param string $fecha
     * @return array<int, BloqueoProfesional>
     * @throws PersistenceException
     */
    public function findBloqueosByProfesionalEnFecha(int $profesionalId, string $fecha): array
    {
        if ($profesionalId <= 0 || trim($fecha) === '') {
            return [];
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "SELECT id, profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo
                 FROM bloqueos_profesionales
                 WHERE profesionalId = ? AND fecha_inicio <= ? AND fecha_fin >= ?
                 ORDER BY fecha_inicio ASC, hora_inicio ASC, id ASC"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de bloqueos por fecha: " . $db->error);
            }

            $stmt->bind_param('iss', $profesionalId, $fecha, $fecha);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de bloqueos por fecha: " . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de bloqueos por fecha: " . $err);
            }

            $bloqueos = [];
            while ($fila = $res->fetch_assoc()) {
                $bloqueos[] = new BloqueoProfesional($fila);
            }
            $res->free();
            $stmt->close();

            return $bloqueos;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::findBloqueosByProfesionalEnFecha] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::findBloqueosByProfesionalEnFecha] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al consultar bloqueos en la fecha.", 0, $e);
        }
    }

    /**
     * Inserta un bloqueo por fecha o intervalo para un profesional.
     *
     * @param BloqueoProfesional $bloqueo
     * @return int ID autogenerado.
     * @throws PersistenceException
     */
    public function createBloqueo(BloqueoProfesional $bloqueo): int
    {
        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "INSERT INTO bloqueos_profesionales (profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción de bloqueo: " . $db->error);
            }

            $profId = (int)$bloqueo->profesionalId;
            $fechaInicio = (string)$bloqueo->fecha_inicio;
            $fechaFin = (string)$bloqueo->fecha_fin;
            $horaInicio = $bloqueo->hora_inicio;
            $horaFin = $bloqueo->hora_fin;
            $motivo = $bloqueo->motivo;

            $stmt->bind_param('isssss', $profId, $fechaInicio, $fechaFin, $horaInicio, $horaFin, $motivo);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar inserción de bloqueo: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $insertId = (int)$db->insert_id;
            $stmt->close();

            if ($afectadas !== 1 || $insertId <= 0) {
                throw new PersistenceException("La inserción del bloqueo no afectó ninguna fila.");
            }

            $bloqueo->id = (string)$insertId;
            return $insertId;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::createBloqueo] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::createBloqueo] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al crear bloqueo.", 0, $e);
        }
    }

    /**
     * Elimina un bloqueo perteneciente al profesional indicado.
     *
     * @param int $profesionalId
     * @param int $bloqueoId
     * @return bool True si se eliminó, false si no existía.
     * @throws PersistenceException
     */
    public function deleteBloqueo(int $profesionalId, int $bloqueoId): bool
    {
        if ($profesionalId <= 0 || $bloqueoId <= 0) {
            return false;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare(
                "DELETE FROM bloqueos_profesionales WHERE id = ? AND profesionalId = ? LIMIT 1"
            );
            if (!$stmt) {
                throw new PersistenceException("Error al preparar eliminación de bloqueo: " . $db->error);
            }

            $stmt->bind_param('ii', $bloqueoId, $profesionalId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar eliminación de bloqueo: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas > 0;
        } catch (PersistenceException $e) {
            error_log("[ProfesionalRepository::deleteBloqueo] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ProfesionalRepository::deleteBloqueo] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al eliminar bloqueo.", 0, $e);
        }
    }
}
