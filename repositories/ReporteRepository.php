<?php

namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/**
 * Repositorio para consultas de reportes administrativos (Fase 6B):
 * - Consulta reservas por rango de fechas, profesional y servicio empleando
 *   exclusivamente sentencias preparadas (`prepare` + `bind_param` + `execute`).
 * - Proyecta los importes, nombres y duraciones históricos capturados al reservar
 *   (`COALESCE(citasservicios.*, servicios.*)`), preservando la exactitud histórica
 *   incluso cuando un servicio fue editado o eliminado del catálogo (`ON DELETE SET NULL`).
 * - Obtiene horarios semanales, descansos y bloqueos vigentes para el cálculo de
 *   minutos disponibles de turno y porcentaje de ocupación por profesional.
 */
class ReporteRepository
{
    private ?mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db;
    }

    public function getDb(): mysqli
    {
        return $this->resolveDb();
    }

    private function resolveDb(): mysqli
    {
        try {
            $db = $this->db ?? ActiveRecord::getDB();
        } catch (\Throwable $e) {
            error_log('[ReporteRepository] Error al resolver conexión DB: ' . $e->getMessage());
            throw new PersistenceException('Conexión a base de datos no inicializada o inválida.', 0, $e);
        }

        if (!$db instanceof mysqli) {
            throw new PersistenceException('Conexión a base de datos no inicializada o inválida.');
        }

        return $db;
    }

    /**
     * Lista todos los profesionales registrados en el catálogo para selectores y métricas de ocupación.
     *
     * @return array<int, array{id: int, nombre: string, activo: int}>
     * @throws PersistenceException
     */
    public function listarProfesionalesCatalogo(): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT id, nombre, activo
                FROM profesionales
                ORDER BY nombre ASC, id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de profesionales para reportes: ' . $db->error);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de profesionales para reportes: ' . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al obtener profesionales para reportes: ' . $err);
            }

            $profesionales = [];
            while ($fila = $res->fetch_assoc()) {
                $profesionales[] = [
                    'id' => (int)$fila['id'],
                    'nombre' => (string)$fila['nombre'],
                    'activo' => (int)$fila['activo'],
                ];
            }
            $stmt->close();

            return $profesionales;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[ReporteRepository::listarProfesionalesCatalogo] Excepción inesperada: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar profesionales para reportes.', 0, $e);
        }
    }

    /**
     * Lista todos los servicios activos en el catálogo para el filtro administrativo.
     *
     * @return array<int, array{id: int, nombre: string, precio: float, duracion_minutos: int}>
     * @throws PersistenceException
     */
    public function listarServiciosCatalogo(): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT id, nombre, precio, duracion_minutos
                FROM servicios
                ORDER BY nombre ASC, id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de servicios para reportes: ' . $db->error);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de servicios para reportes: ' . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al obtener servicios para reportes: ' . $err);
            }

            $servicios = [];
            while ($fila = $res->fetch_assoc()) {
                $servicios[] = [
                    'id' => (int)$fila['id'],
                    'nombre' => (string)$fila['nombre'],
                    'precio' => round((float)$fila['precio'], 2),
                    'duracion_minutos' => (int)$fila['duracion_minutos'],
                ];
            }
            $stmt->close();

            return $servicios;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[ReporteRepository::listarServiciosCatalogo] Excepción inesperada: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar servicios para reportes.', 0, $e);
        }
    }

    /**
     * Obtiene los horarios semanales configurados (todos o de un profesional específico).
     *
     * @param int|null $profesionalId
     * @return array<int, array{id: int, profesionalId: int, dia_semana: int, hora_inicio: string, hora_fin: string}>
     * @throws PersistenceException
     */
    public function obtenerHorariosProfesionales(?int $profesionalId = null): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin
                FROM horarios_profesionales";
        if ($profesionalId !== null) {
            $sql .= " WHERE profesionalId = ?";
        }
        $sql .= " ORDER BY profesionalId ASC, dia_semana ASC, hora_inicio ASC, id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de horarios para reportes: ' . $db->error);
            }

            if ($profesionalId !== null) {
                $stmt->bind_param('i', $profesionalId);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de horarios para reportes: ' . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al obtener horarios para reportes: ' . $err);
            }

            $horarios = [];
            while ($fila = $res->fetch_assoc()) {
                $horarios[] = [
                    'id' => (int)$fila['id'],
                    'profesionalId' => (int)$fila['profesionalId'],
                    'dia_semana' => (int)$fila['dia_semana'],
                    'hora_inicio' => (string)$fila['hora_inicio'],
                    'hora_fin' => (string)$fila['hora_fin'],
                ];
            }
            $stmt->close();

            return $horarios;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[ReporteRepository::obtenerHorariosProfesionales] Excepción inesperada: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar horarios para reportes.', 0, $e);
        }
    }

    /**
     * Obtiene los descansos semanales configurados (todos o de un profesional específico).
     *
     * @param int|null $profesionalId
     * @return array<int, array{id: int, profesionalId: int, dia_semana: int, hora_inicio: string, hora_fin: string, motivo: ?string}>
     * @throws PersistenceException
     */
    public function obtenerDescansosProfesionales(?int $profesionalId = null): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT id, profesionalId, dia_semana, hora_inicio, hora_fin, motivo
                FROM descansos_profesionales";
        if ($profesionalId !== null) {
            $sql .= " WHERE profesionalId = ?";
        }
        $sql .= " ORDER BY profesionalId ASC, dia_semana ASC, hora_inicio ASC, id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de descansos para reportes: ' . $db->error);
            }

            if ($profesionalId !== null) {
                $stmt->bind_param('i', $profesionalId);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de descansos para reportes: ' . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al obtener descansos para reportes: ' . $err);
            }

            $descansos = [];
            while ($fila = $res->fetch_assoc()) {
                $descansos[] = [
                    'id' => (int)$fila['id'],
                    'profesionalId' => (int)$fila['profesionalId'],
                    'dia_semana' => (int)$fila['dia_semana'],
                    'hora_inicio' => (string)$fila['hora_inicio'],
                    'hora_fin' => (string)$fila['hora_fin'],
                    'motivo' => $fila['motivo'] !== null ? (string)$fila['motivo'] : null,
                ];
            }
            $stmt->close();

            return $descansos;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[ReporteRepository::obtenerDescansosProfesionales] Excepción inesperada: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar descansos para reportes.', 0, $e);
        }
    }

    /**
     * Obtiene los bloqueos vigentes que se cruzan con el rango `[fechaInicio, fechaFin]`.
     *
     * @param string $fechaInicio
     * @param string $fechaFin
     * @param int|null $profesionalId
     * @return array<int, array{id: int, profesionalId: int, fecha_inicio: string, fecha_fin: string, hora_inicio: ?string, hora_fin: ?string, motivo: ?string}>
     * @throws PersistenceException
     */
    public function obtenerBloqueosEnRango(string $fechaInicio, string $fechaFin, ?int $profesionalId = null): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT id, profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo
                FROM bloqueos_profesionales
                WHERE fecha_inicio <= ? AND fecha_fin >= ?";
        if ($profesionalId !== null) {
            $sql .= " AND profesionalId = ?";
        }
        $sql .= " ORDER BY profesionalId ASC, fecha_inicio ASC, id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de bloqueos para reportes: ' . $db->error);
            }

            if ($profesionalId !== null) {
                $stmt->bind_param('ssi', $fechaFin, $fechaInicio, $profesionalId);
            } else {
                $stmt->bind_param('ss', $fechaFin, $fechaInicio);
            }

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de bloqueos para reportes: ' . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al obtener bloqueos para reportes: ' . $err);
            }

            $bloqueos = [];
            while ($fila = $res->fetch_assoc()) {
                $bloqueos[] = [
                    'id' => (int)$fila['id'],
                    'profesionalId' => (int)$fila['profesionalId'],
                    'fecha_inicio' => (string)$fila['fecha_inicio'],
                    'fecha_fin' => (string)$fila['fecha_fin'],
                    'hora_inicio' => $fila['hora_inicio'] !== null ? (string)$fila['hora_inicio'] : null,
                    'hora_fin' => $fila['hora_fin'] !== null ? (string)$fila['hora_fin'] : null,
                    'motivo' => $fila['motivo'] !== null ? (string)$fila['motivo'] : null,
                ];
            }
            $stmt->close();

            return $bloqueos;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[ReporteRepository::obtenerBloqueosEnRango] Excepción inesperada: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar bloqueos para reportes.', 0, $e);
        }
    }

    /**
     * Consulta las filas de citas y servicios asociados en `[fechaInicio, fechaFin]`
     * proyectando datos históricos (`COALESCE(citasservicios.*, servicios.*)`).
     *
     * @param string $fechaInicio Fecha inicial `YYYY-MM-DD`.
     * @param string $fechaFin Fecha final `YYYY-MM-DD`.
     * @param string|null $filtroProfesional `null` (todos), `'sin_profesional'` (`profesionalId IS NULL`) o ID entero en string.
     * @param int|null $servicioId ID entero positivo de servicio para filtrar citas que incluyan dicho servicio.
     * @return array<int, array<string, mixed>>
     * @throws PersistenceException
     */
    public function obtenerFilasReservasEnRango(
        string $fechaInicio,
        string $fechaFin,
        ?string $filtroProfesional = null,
        ?int $servicioId = null
    ): array {
        $db = $this->resolveDb();

        $sql = "SELECT
                    c.id AS cita_id,
                    c.fecha,
                    c.hora,
                    c.hora_inicio,
                    c.hora_fin,
                    c.duracion_total_minutos AS cita_duracion_total_minutos,
                    c.usuarioId AS usuario_id,
                    c.profesionalId AS profesional_id,
                    p.nombre AS profesional_nombre,
                    p.activo AS profesional_activo,
                    u.nombre AS cliente_nombre,
                    u.apellido AS cliente_apellido,
                    u.email AS cliente_email,
                    u.telefono AS cliente_telefono,
                    cs.id AS citasservicio_id,
                    cs.servicioId AS servicio_id,
                    s.id AS servicio_catalogo_id,
                    COALESCE(cs.nombre_servicio, s.nombre) AS servicio_nombre,
                    COALESCE(cs.precio_servicio, s.precio) AS servicio_precio,
                    COALESCE(cs.duracion_minutos, s.duracion_minutos) AS servicio_duracion_minutos
                FROM citas c
                LEFT JOIN profesionales p ON p.id = c.profesionalId
                LEFT JOIN usuarios u ON u.id = c.usuarioId
                LEFT JOIN citasservicios cs ON cs.citaId = c.id
                LEFT JOIN servicios s ON s.id = cs.servicioId
                WHERE c.fecha >= ? AND c.fecha <= ?";

        $tipos = 'ss';
        $params = [$fechaInicio, $fechaFin];

        if ($filtroProfesional === 'sin_profesional') {
            $sql .= " AND c.profesionalId IS NULL";
        } elseif ($filtroProfesional !== null && ctype_digit($filtroProfesional) && (int)$filtroProfesional > 0) {
            $sql .= " AND c.profesionalId = ?";
            $tipos .= 'i';
            $params[] = (int)$filtroProfesional;
        }

        if ($servicioId !== null && $servicioId > 0) {
            $sql .= " AND EXISTS (
                        SELECT 1
                        FROM citasservicios cs_f
                        WHERE cs_f.citaId = c.id AND cs_f.servicioId = ?
                      )";
            $tipos .= 'i';
            $params[] = $servicioId;
        }

        $sql .= " ORDER BY c.fecha ASC, COALESCE(c.hora_inicio, c.hora) ASC, c.id ASC, cs.id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de reservas para reportes: ' . $db->error);
            }

            $stmt->bind_param($tipos, ...$params);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de reservas para reportes: ' . $err);
            }

            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al obtener resultados de reservas para reportes: ' . $err);
            }

            $filas = [];
            while ($fila = $res->fetch_assoc()) {
                $filas[] = $fila;
            }
            $stmt->close();

            return $filas;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[ReporteRepository::obtenerFilasReservasEnRango] Excepción inesperada: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar reservas para reportes.', 0, $e);
        }
    }
}
