<?php

namespace Repositories;

use Model\ActiveRecord;
use Model\AdminCita;
use Model\Cita;
use Model\CitaServicio;
use mysqli;

/**
 * Repositorio para consultas y persistencia transaccional de `citas` y `citasservicios`,
 * incluyendo la proyección administrativa por fecha.
 *
 * Nota arquitectónica:
 * La tabla intermedia `citasservicios` referencia actualmente `servicioId` sin snapshot de
 * `nombre` ni `precio`; la conservación histórica inmutable de nombres y precios pactados
 * al momento de la reserva queda documentada como pendiente separado de evolución de esquema.
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
            $stmt = $db->prepare("SELECT id, fecha, hora, usuarioId FROM citas WHERE id = ? LIMIT 1");
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
     * Inserta un registro en la tabla `citas` sobre la conexión provista.
     *
     * @throws PersistenceException Si falla la preparación o ejecución SQL.
     */
    public function createCita(Cita $cita, ?mysqli $dbOverride = null): int
    {
        $db = $dbOverride ?? $this->resolveDb();

        try {
            $stmt = $db->prepare("INSERT INTO citas (fecha, hora, usuarioId) VALUES (?, ?, ?)");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción de cita: " . $db->error);
            }

            $fecha = (string)$cita->fecha;
            $hora = (string)$cita->hora;
            $usuarioId = (int)$cita->usuarioId;

            $stmt->bind_param('ssi', $fecha, $hora, $usuarioId);
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
     * Inserta un vínculo de servicio en la tabla `citasservicios` sobre la conexión provista.
     *
     * @throws PersistenceException Si falla la preparación o ejecución SQL.
     */
    public function createCitaServicio(CitaServicio $citaServicio, ?mysqli $dbOverride = null): int
    {
        $db = $dbOverride ?? $this->resolveDb();

        try {
            $stmt = $db->prepare("INSERT INTO citasservicios (citaId, servicioId) VALUES (?, ?)");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción en citasservicios: " . $db->error);
            }

            $citaId = (int)$citaServicio->citaId;
            $servicioId = (int)$citaServicio->servicioId;

            $stmt->bind_param('ii', $citaId, $servicioId);
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
     * Comprueba inicio, cada escritura y commit; ante cualquier fallo ejecuta rollback.
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
            $idCita = $this->createCita($cita, $db);

            foreach ($idServicios as $idServicio) {
                $citaServicio = new CitaServicio([
                    'citaId' => $idCita,
                    'servicioId' => (int)$idServicio
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
     * Elimina una cita y sus vínculos en `citasservicios` dentro de una transacción atómica
     * sobre una única conexión MySQL.
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
     *
     * @param string $fecha Fecha en formato `AAAA-MM-DD`.
     * @return array<int, AdminCita> Lista de filas hidratadas como AdminCita.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findAdminCitasByFecha(string $fecha): array
    {
        $db = $this->resolveDb();

        $consulta = "SELECT citas.id, citas.hora, CONCAT(usuarios.nombre, ' ', usuarios.apellido) as cliente, ";
        $consulta .= " usuarios.email, usuarios.telefono, servicios.nombre as servicio, servicios.precio ";
        $consulta .= " FROM citas ";
        $consulta .= " LEFT OUTER JOIN usuarios ON citas.usuarioId = usuarios.id ";
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
}
