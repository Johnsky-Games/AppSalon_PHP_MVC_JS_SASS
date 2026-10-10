<?php

namespace Repositories;

use Model\ActiveRecord;
use Model\Servicio;
use mysqli;
use Services\ServicioService;

/**
 * Repositorio para consultas y persistencia del catálogo de servicios (`servicios`).
 * Utiliza exclusivamente sentencias preparadas y manejo explícito de errores SQL.
 */
class ServicioRepository
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
     * Resuelve la conexión activa a MySQL o lanza PersistenceException si no está disponible.
     */
    private function resolveDb(): mysqli
    {
        $db = $this->dbExplicitlyProvided ? $this->db : ($this->db ?? ActiveRecord::getDB());
        if (!$db instanceof mysqli) {
            throw new PersistenceException('No hay conexión activa a la base de datos.');
        }
        return $db;
    }

    /**
     * Obtiene todos los servicios del catálogo.
     *
     * @return array<int, Servicio> Lista de servicios (vacía únicamente si la tabla no tiene filas).
     * @throws PersistenceException Si ocurre un error de conexión o ejecución SQL.
     */
    public function findAll(): array
    {
        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare("SELECT id, nombre, precio, duracion_minutos FROM servicios");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de servicios: " . $db->error);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de servicios: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultados de servicios: " . $err);
            }

            $servicios = [];
            while ($fila = $resultado->fetch_assoc()) {
                $servicios[] = new Servicio($fila);
            }

            $resultado->free();
            $stmt->close();

            return $servicios;
        } catch (PersistenceException $e) {
            error_log("[ServicioRepository::findAll] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ServicioRepository::findAll] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al listar servicios.", 0, $e);
        }
    }

    /**
     * Busca un servicio por su identificador primario.
     *
     * @param int $id Identificador entero positivo.
     * @return Servicio|null Entidad Servicio si existe, o null si no existe registro con ese id.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findById(int $id): ?Servicio
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare("SELECT id, nombre, precio, duracion_minutos FROM servicios WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda de servicio: " . $db->error);
            }

            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda de servicio: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultado de servicio: " . $err);
            }

            $fila = $resultado->fetch_assoc();
            $resultado->free();
            $stmt->close();

            if (!$fila) {
                return null;
            }

            return new Servicio($fila);
        } catch (PersistenceException $e) {
            error_log("[ServicioRepository::findById] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ServicioRepository::findById] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al buscar servicio.", 0, $e);
        }
    }

    /**
     * Inserta un nuevo servicio en la base de datos.
     *
     * @param Servicio $servicio Entidad con nombre, precio y duracion_minutos validados.
     * @return int ID autogenerado por MySQL.
     * @throws PersistenceException Si la preparación o inserción falla.
     */
    public function create(Servicio $servicio): int
    {
        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare("INSERT INTO servicios (nombre, precio, duracion_minutos) VALUES (?, ?, ?)");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar inserción de servicio: " . $db->error);
            }

            $nombre = (string)$servicio->nombre;
            $precio = (string)$servicio->precio;
            $duracion = filter_var(
                $servicio->duracion_minutos,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
            );
            if ($duracion === false) {
                $duracion = ServicioService::DEFAULT_DURACION_MINUTOS;
            }

            $stmt->bind_param('ssi', $nombre, $precio, $duracion);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar inserción de servicio: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $insertId = (int)$db->insert_id;
            $stmt->close();

            if ($afectadas !== 1 || $insertId <= 0) {
                throw new PersistenceException("La inserción del servicio no afectó ninguna fila.");
            }

            $servicio->id = (string)$insertId;
            $servicio->duracion_minutos = (string)$duracion;
            return $insertId;
        } catch (PersistenceException $e) {
            error_log("[ServicioRepository::create] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ServicioRepository::create] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al crear servicio.", 0, $e);
        }
    }

    /**
     * Actualiza nombre, precio y duracion_minutos de un servicio existente identificado por su ID validado.
     *
     * Distingue:
     * - Fallo SQL: lanza PersistenceException.
     * - Actualización con cambios (affected_rows > 0): retorna true.
     * - Actualización sin cambios (affected_rows === 0 y el registro existe): retorna true.
     * - Registro inexistente (affected_rows === 0 y el registro no existe): retorna false.
     *
     * @param Servicio $servicio Entidad con id, nombre, precio y duracion_minutos validados.
     * @return bool True si el registro existe y quedó actualizado, false si el registro no existe.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function update(Servicio $servicio): bool
    {
        $id = filter_var($servicio->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return false;
        }

        $duracion = filter_var(
            $servicio->duracion_minutos,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
        );
        if ($duracion === false) {
            return false;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare("UPDATE servicios SET nombre = ?, precio = ?, duracion_minutos = ? WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar actualización de servicio: " . $db->error);
            }

            $nombre = (string)$servicio->nombre;
            $precio = (string)$servicio->precio;
            $stmt->bind_param('ssii', $nombre, $precio, $duracion, $id);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar actualización de servicio: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            if ($afectadas > 0) {
                return true;
            }

            // Si affected_rows === 0, verificar existencia real para distinguir
            // actualización sin cambios (idempotente) de registro inexistente.
            return $this->findById($id) !== null;
        } catch (PersistenceException $e) {
            error_log("[ServicioRepository::update] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ServicioRepository::update] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al actualizar servicio.", 0, $e);
        }
    }

    /**
     * Elimina un servicio del catálogo por su identificador primario.
     * Conserva intactos los registros históricos en `citas` y `citasservicios`.
     *
     * @param int $id Identificador entero positivo.
     * @return bool True si se eliminó el registro, false si el registro no existía.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare("DELETE FROM servicios WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar eliminación de servicio: " . $db->error);
            }

            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar eliminación de servicio: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas > 0;
        } catch (PersistenceException $e) {
            error_log("[ServicioRepository::delete] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[ServicioRepository::delete] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al eliminar servicio.", 0, $e);
        }
    }
}
