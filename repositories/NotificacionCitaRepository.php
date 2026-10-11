<?php

namespace Repositories;

use Model\ActiveRecord;
use Model\HorarioProfesional;
use Model\NotificacionCita;
use mysqli;

/**
 * Repositorio para la persistencia transaccional, control de idempotencia y cola de reintentos
 * de notificaciones de citas (`notificaciones_citas`).
 *
 * Reglas:
 * - Exclusivamente sentencias preparadas (`prepare` / `bind_param` / `execute`).
 * - Manejo explícito de errores SQL lanzando `PersistenceException`.
 * - Bloqueo pesimista (`FOR UPDATE`) en `registrarOAdquirirParaEnvio` y `adquirirCandidataParaReintento`
 *   para impedir envíos duplicados ante ejecuciones repetidas o concurrentes.
 */
class NotificacionCitaRepository
{
    private ?mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db;
    }

    public function getDb(): ?mysqli
    {
        return $this->db;
    }

    private function resolveDb(): mysqli
    {
        $db = $this->db ?? ActiveRecord::getDB();
        if (!$db instanceof mysqli) {
            throw new PersistenceException("Conexión a base de datos no inicializada en NotificacionCitaRepository.");
        }
        return $db;
    }

    /**
     * Busca una notificación por su clave primaria `id`.
     *
     * @throws PersistenceException
     */
    public function findById(int $id): ?NotificacionCita
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->resolveDb();
        $sql = "SELECT * FROM notificaciones_citas WHERE id = ? LIMIT 1";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda de notificación por ID: " . $db->error);
            }
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda de notificación por ID: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener notificación por ID: " . $err);
            }
            $fila = $res->fetch_assoc();
            $res->free();
            $stmt->close();

            return $fila ? new NotificacionCita($fila) : null;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::findById] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::findById] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al consultar notificación por ID.", 0, $e);
        }
    }

    /**
     * Busca una notificación por su `clave_idempotencia` única.
     *
     * @throws PersistenceException
     */
    public function findByClaveIdempotencia(string $claveIdempotencia): ?NotificacionCita
    {
        $clave = trim($claveIdempotencia);
        if ($clave === '') {
            return null;
        }

        $db = $this->resolveDb();
        $sql = "SELECT * FROM notificaciones_citas WHERE clave_idempotencia = ? LIMIT 1";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda por clave de idempotencia: " . $db->error);
            }
            $stmt->bind_param('s', $clave);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda por clave de idempotencia: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al leer notificación por clave de idempotencia: " . $err);
            }
            $fila = $res->fetch_assoc();
            $res->free();
            $stmt->close();

            return $fila ? new NotificacionCita($fila) : null;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::findByClaveIdempotencia] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::findByClaveIdempotencia] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al consultar notificación por clave de idempotencia.", 0, $e);
        }
    }

    /**
     * Lista todas las notificaciones asociadas a un `cita_referencia_id` ordenadas por ID ascendente.
     *
     * @return array<int, NotificacionCita>
     * @throws PersistenceException
     */
    public function findByCitaReferenciaId(int $citaReferenciaId): array
    {
        if ($citaReferenciaId <= 0) {
            return [];
        }

        $db = $this->resolveDb();
        $sql = "SELECT * FROM notificaciones_citas WHERE cita_referencia_id = ? ORDER BY id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar listado de notificaciones por cita_referencia_id: " . $db->error);
            }
            $stmt->bind_param('i', $citaReferenciaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar listado de notificaciones por cita_referencia_id: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al leer listado de notificaciones por cita_referencia_id: " . $err);
            }
            $items = [];
            while ($fila = $res->fetch_assoc()) {
                $items[] = new NotificacionCita($fila);
            }
            $res->free();
            $stmt->close();

            return $items;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::findByCitaReferenciaId] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::findByCitaReferenciaId] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al listar notificaciones de la cita.", 0, $e);
        }
    }

    /**
     * Registra una nueva notificación o adquiere una existente bajo bloqueo `FOR UPDATE` por `clave_idempotencia`,
     * dejándola en `estado = 'enviando'` e incrementando `intentos` dentro de una transacción corta antes de despachar por SMTP.
     *
     * @param array<string, mixed> $datos
     * @param string $ahoraUtc Fecha/hora UTC en formato `Y-m-d H:i:s`
     * @param bool $respetarBackoff Si es true, no adquiere filas cuyo `proximo_intento_at > $ahoraUtc`
     * @return array{accion: string, notificacion: NotificacionCita}
     * @throws PersistenceException
     */
    public function registrarOAdquirirParaEnvio(array $datos, string $ahoraUtc, bool $respetarBackoff = false): array
    {
        $db = $this->resolveDb();
        $inTransaction = false;

        $claveIdempotencia = trim((string)($datos['clave_idempotencia'] ?? ''));
        if ($claveIdempotencia === '') {
            throw new PersistenceException("La clave de idempotencia es obligatoria para registrar una notificación.");
        }

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar la transacción de registro de notificación.");
            }
            $inTransaction = true;

            $stmtLock = $db->prepare("SELECT * FROM notificaciones_citas WHERE clave_idempotencia = ? LIMIT 1 FOR UPDATE");
            if (!$stmtLock) {
                throw new PersistenceException("Error al preparar bloqueo por clave de idempotencia: " . $db->error);
            }
            $stmtLock->bind_param('s', $claveIdempotencia);
            if (!$stmtLock->execute()) {
                $err = $stmtLock->error;
                $stmtLock->close();
                throw new PersistenceException("Error al ejecutar bloqueo por clave de idempotencia: " . $err);
            }
            $resLock = $stmtLock->get_result();
            if ($resLock === false) {
                $err = $stmtLock->error;
                $stmtLock->close();
                throw new PersistenceException("Error al leer fila bloqueada por clave de idempotencia: " . $err);
            }
            $existente = $resLock->fetch_assoc();
            $resLock->free();
            $stmtLock->close();

            if ($existente) {
                $notificacion = new NotificacionCita($existente);

                if ($notificacion->estado === NotificacionCita::ESTADO_ENVIADO) {
                    $db->commit();
                    $inTransaction = false;
                    return [
                        'accion' => 'ya_enviado',
                        'notificacion' => $notificacion
                    ];
                }

                if ($notificacion->estado === NotificacionCita::ESTADO_ENVIANDO) {
                    $leaseActivo = $notificacion->lease_expires_at !== null && $notificacion->lease_expires_at > $ahoraUtc;
                    if ($leaseActivo) {
                        $db->commit();
                        $inTransaction = false;
                        return [
                            'accion' => 'en_progreso',
                            'notificacion' => $notificacion
                        ];
                    }
                }

                if ($notificacion->intentos >= $notificacion->max_intentos) {
                    $db->commit();
                    $inTransaction = false;
                    return [
                        'accion' => 'agotado',
                        'notificacion' => $notificacion
                    ];
                }

                if (
                    $respetarBackoff &&
                    $notificacion->proximo_intento_at !== null &&
                    $notificacion->proximo_intento_at > $ahoraUtc
                ) {
                    $db->commit();
                    $inTransaction = false;
                    return [
                        'accion' => 'esperando_backoff',
                        'notificacion' => $notificacion
                    ];
                }

                $nuevosIntentos = $notificacion->intentos + 1;
                $estadoEnviando = NotificacionCita::ESTADO_ENVIANDO;
                $workerToken = trim((string)($datos['worker_token'] ?? ''));
                if ($workerToken === '') {
                    $workerToken = 'worker_' . bin2hex(random_bytes(16));
                }
                $duracionLease = isset($datos['lease_segundos']) && (int)$datos['lease_segundos'] >= 1
                    ? (int)$datos['lease_segundos']
                    : 300;

                $stmtUpd = $db->prepare(
                    "UPDATE notificaciones_citas
                     SET estado = ?, intentos = ?, worker_token = ?,
                         lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = ?
                     WHERE id = ?"
                );
                if (!$stmtUpd) {
                    throw new PersistenceException("Error al preparar adquisición de notificación existente: " . $db->error);
                }
                $idNotif = (int)$notificacion->id;
                $stmtUpd->bind_param('sisisi', $estadoEnviando, $nuevosIntentos, $workerToken, $duracionLease, $ahoraUtc, $idNotif);
                if (!$stmtUpd->execute()) {
                    $err = $stmtUpd->error;
                    $stmtUpd->close();
                    throw new PersistenceException("Error al ejecutar adquisición de notificación existente: " . $err);
                }
                $stmtUpd->close();

                if (!$db->commit()) {
                    throw new PersistenceException("Error al confirmar adquisición de notificación existente.");
                }
                $inTransaction = false;

                $notificacion->estado = $estadoEnviando;
                $notificacion->intentos = $nuevosIntentos;
                $notificacion->worker_token = $workerToken;
                $notificacion->updated_at = $ahoraUtc;

                return [
                    'accion' => 'enviar',
                    'notificacion' => $notificacion
                ];
            }

            // No existe previamente: insertar en estado 'enviando' con intentos = 1
            $citaId = isset($datos['cita_id']) && $datos['cita_id'] !== null && (int)$datos['cita_id'] > 0
                ? (int)$datos['cita_id']
                : null;
            $citaRefId = isset($datos['cita_referencia_id']) && (int)$datos['cita_referencia_id'] > 0
                ? (int)$datos['cita_referencia_id']
                : ($citaId ?? 0);
            $usuarioId = isset($datos['usuario_id']) && $datos['usuario_id'] !== null && (int)$datos['usuario_id'] > 0
                ? (int)$datos['usuario_id']
                : null;
            $tipo = trim((string)($datos['tipo'] ?? NotificacionCita::TIPO_CONFIRMACION));
            $destEmail = mb_substr(trim((string)($datos['destinatario_email'] ?? '')), 0, 60, 'UTF-8');
            $destNombre = mb_substr(trim((string)($datos['destinatario_nombre'] ?? '')), 0, 120, 'UTF-8');
            $asunto = mb_substr(trim((string)($datos['asunto'] ?? '')), 0, 180, 'UTF-8');

            $payloadRaw = $datos['payload_json'] ?? null;
            if (is_array($payloadRaw)) {
                $payloadJson = json_encode($payloadRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
            } elseif (is_string($payloadRaw) && trim($payloadRaw) !== '') {
                $payloadJson = $payloadRaw;
            } else {
                $payloadJson = null;
            }

            $estadoInicial = NotificacionCita::ESTADO_ENVIANDO;
            $intentosInicial = 1;
            $maxIntentos = isset($datos['max_intentos']) && (int)$datos['max_intentos'] >= 1
                ? (int)$datos['max_intentos']
                : NotificacionCita::DEFAULT_MAX_INTENTOS;
            $workerTokenInicial = trim((string)($datos['worker_token'] ?? ''));
            if ($workerTokenInicial === '') {
                $workerTokenInicial = 'worker_' . bin2hex(random_bytes(16));
            }
            $duracionLeaseInicial = isset($datos['lease_segundos']) && (int)$datos['lease_segundos'] >= 1
                ? (int)$datos['lease_segundos']
                : 300;

            $sqlIns = "INSERT INTO notificaciones_citas
                (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia,
                 destinatario_email, destinatario_nombre, asunto, payload_json,
                 estado, intentos, max_intentos, ultimo_error, enviado_at, proximo_intento_at,
                 worker_token, lease_expires_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)";

            $stmtIns = $db->prepare($sqlIns);
            if (!$stmtIns) {
                throw new PersistenceException("Error al preparar inserción de notificación: " . $db->error);
            }
            $stmtIns->bind_param(
                'iiisssssssiisiss',
                $citaId,
                $citaRefId,
                $usuarioId,
                $tipo,
                $claveIdempotencia,
                $destEmail,
                $destNombre,
                $asunto,
                $payloadJson,
                $estadoInicial,
                $intentosInicial,
                $maxIntentos,
                $workerTokenInicial,
                $duracionLeaseInicial,
                $ahoraUtc,
                $ahoraUtc
            );
            if (!$stmtIns->execute()) {
                $err = $stmtIns->error;
                $stmtIns->close();
                throw new PersistenceException("Error al ejecutar inserción de notificación: " . $err);
            }
            $nuevoId = (int)$stmtIns->insert_id;
            $stmtIns->close();

            if (!$db->commit()) {
                throw new PersistenceException("Error al confirmar inserción de notificación.");
            }
            $inTransaction = false;

            $nuevaNotificacion = new NotificacionCita([
                'id' => $nuevoId,
                'cita_id' => $citaId,
                'cita_referencia_id' => $citaRefId,
                'usuario_id' => $usuarioId,
                'tipo' => $tipo,
                'clave_idempotencia' => $claveIdempotencia,
                'destinatario_email' => $destEmail,
                'destinatario_nombre' => $destNombre,
                'asunto' => $asunto,
                'payload_json' => $payloadJson,
                'estado' => $estadoInicial,
                'intentos' => $intentosInicial,
                'max_intentos' => $maxIntentos,
                'created_at' => $ahoraUtc,
                'updated_at' => $ahoraUtc,
            ]);

            return [
                'accion' => 'enviar',
                'notificacion' => $nuevaNotificacion
            ];
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try {
                    $db->rollback();
                } catch (\Throwable $rbErr) {
                    error_log("[NotificacionCitaRepository::registrarOAdquirirParaEnvio] Error en rollback: " . $rbErr->getMessage());
                }
            }
            if ($e instanceof PersistenceException) {
                error_log("[NotificacionCitaRepository::registrarOAdquirirParaEnvio] " . $e->getMessage());
                throw $e;
            }
            error_log("[NotificacionCitaRepository::registrarOAdquirirParaEnvio] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al registrar o adquirir notificación de cita.", 0, $e);
        }
    }

    /**
     * Marca una notificación como enviada exitosamente (`estado = 'enviado'`).
     *
     * @throws PersistenceException
     */
    public function marcarEnviado(int $id, string $enviadoAtUtc): bool
    {
        $db = $this->resolveDb();
        $estado = NotificacionCita::ESTADO_ENVIADO;
        $sql = "UPDATE notificaciones_citas
                SET estado = ?, enviado_at = ?, ultimo_error = NULL, proximo_intento_at = NULL, updated_at = ?
                WHERE id = ?";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar actualización a 'enviado': " . $db->error);
            }
            $stmt->bind_param('sssi', $estado, $enviadoAtUtc, $enviadoAtUtc, $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar actualización a 'enviado': " . $err);
            }
            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas >= 0;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::marcarEnviado] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::marcarEnviado] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al marcar notificación como enviada.", 0, $e);
        }
    }

    /**
     * Marca una notificación como fallida (`estado = 'fallido'`), registrando `ultimo_error`
     * y `proximo_intento_at` para backoff de reintento.
     *
     * @throws PersistenceException
     */
    public function marcarFallido(
        int $id,
        string $mensajeError,
        ?string $proximoIntentoAtUtc,
        string $updatedAtUtc
    ): bool {
        $db = $this->resolveDb();
        $estado = NotificacionCita::ESTADO_FALLIDO;
        $errorTruncado = mb_substr(trim($mensajeError), 0, 250, 'UTF-8');
        $sql = "UPDATE notificaciones_citas
                SET estado = ?, ultimo_error = ?, proximo_intento_at = ?, updated_at = ?
                WHERE id = ?";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar actualización a 'fallido': " . $db->error);
            }
            $stmt->bind_param('ssssi', $estado, $errorTruncado, $proximoIntentoAtUtc, $updatedAtUtc, $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar actualización a 'fallido': " . $err);
            }
            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas >= 0;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::marcarFallido] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::marcarFallido] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al marcar notificación como fallida.", 0, $e);
        }
    }

    /**
     * Registra un evento de notificación de forma durable en el Outbox (`estado = 'pendiente'`),
     * garantizando idempotencia por `clave_idempotencia`.
     *
     * @param array<string, mixed> $datos
     * @param string $ahoraUtc
     * @return NotificacionCita
     * @throws PersistenceException
     */
    public function registrarEventoOutbox(array $datos, string $ahoraUtc): NotificacionCita
    {
        $db = $this->resolveDb();
        $inTransaction = false;

        $claveIdempotencia = trim((string)($datos['clave_idempotencia'] ?? ''));
        if ($claveIdempotencia === '') {
            throw new PersistenceException("La clave de idempotencia es obligatoria para registrar una notificación en Outbox.");
        }

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar la transacción para evento outbox.");
            }
            $inTransaction = true;

            $stmtLock = $db->prepare("SELECT * FROM notificaciones_citas WHERE clave_idempotencia = ? LIMIT 1 FOR UPDATE");
            if (!$stmtLock) {
                throw new PersistenceException("Error al preparar bloqueo por clave de idempotencia: " . $db->error);
            }
            $stmtLock->bind_param('s', $claveIdempotencia);
            if (!$stmtLock->execute()) {
                $err = $stmtLock->error;
                $stmtLock->close();
                throw new PersistenceException("Error al ejecutar bloqueo por clave de idempotencia: " . $err);
            }
            $resLock = $stmtLock->get_result();
            if ($resLock === false) {
                $err = $stmtLock->error;
                $stmtLock->close();
                throw new PersistenceException("Error al leer fila bloqueada por clave de idempotencia: " . $err);
            }
            $existente = $resLock->fetch_assoc();
            $resLock->free();
            $stmtLock->close();

            if ($existente) {
                $db->commit();
                $inTransaction = false;
                return new NotificacionCita($existente);
            }

            $citaId = isset($datos['cita_id']) && $datos['cita_id'] !== null && (int)$datos['cita_id'] > 0
                ? (int)$datos['cita_id']
                : null;
            $citaRefId = isset($datos['cita_referencia_id']) && (int)$datos['cita_referencia_id'] > 0
                ? (int)$datos['cita_referencia_id']
                : ($citaId ?? 0);
            $usuarioId = isset($datos['usuario_id']) && $datos['usuario_id'] !== null && (int)$datos['usuario_id'] > 0
                ? (int)$datos['usuario_id']
                : null;
            $tipo = trim((string)($datos['tipo'] ?? NotificacionCita::TIPO_CONFIRMACION));
            $destEmail = mb_substr(trim((string)($datos['destinatario_email'] ?? '')), 0, 60, 'UTF-8');
            $destNombre = mb_substr(trim((string)($datos['destinatario_nombre'] ?? '')), 0, 120, 'UTF-8');
            $asunto = mb_substr(trim((string)($datos['asunto'] ?? '')), 0, 180, 'UTF-8');

            $payloadRaw = $datos['payload_json'] ?? null;
            if (is_array($payloadRaw)) {
                $payloadJson = json_encode($payloadRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
            } elseif (is_string($payloadRaw) && trim($payloadRaw) !== '') {
                $payloadJson = $payloadRaw;
            } else {
                $payloadJson = null;
            }

            $estadoInicial = NotificacionCita::ESTADO_PENDIENTE;
            $intentosInicial = 0;
            $maxIntentos = isset($datos['max_intentos']) && (int)$datos['max_intentos'] >= 1
                ? (int)$datos['max_intentos']
                : NotificacionCita::DEFAULT_MAX_INTENTOS;

            $sqlIns = "INSERT INTO notificaciones_citas
                (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia,
                 destinatario_email, destinatario_nombre, asunto, payload_json,
                 estado, intentos, max_intentos, ultimo_error, enviado_at, proximo_intento_at,
                 worker_token, lease_expires_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)";

            $stmtIns = $db->prepare($sqlIns);
            if (!$stmtIns) {
                throw new PersistenceException("Error al preparar inserción en outbox: " . $db->error);
            }
            $stmtIns->bind_param(
                'iiisssssssiiss',
                $citaId,
                $citaRefId,
                $usuarioId,
                $tipo,
                $claveIdempotencia,
                $destEmail,
                $destNombre,
                $asunto,
                $payloadJson,
                $estadoInicial,
                $intentosInicial,
                $maxIntentos,
                $ahoraUtc,
                $ahoraUtc
            );
            if (!$stmtIns->execute()) {
                $err = $stmtIns->error;
                $stmtIns->close();
                throw new PersistenceException("Error al ejecutar inserción en outbox: " . $err);
            }
            $nuevoId = (int)$stmtIns->insert_id;
            $stmtIns->close();

            if (!$db->commit()) {
                throw new PersistenceException("Error al confirmar inserción en outbox.");
            }
            $inTransaction = false;

            return new NotificacionCita([
                'id' => $nuevoId,
                'cita_id' => $citaId,
                'cita_referencia_id' => $citaRefId,
                'usuario_id' => $usuarioId,
                'tipo' => $tipo,
                'clave_idempotencia' => $claveIdempotencia,
                'destinatario_email' => $destEmail,
                'destinatario_nombre' => $destNombre,
                'asunto' => $asunto,
                'payload_json' => $payloadJson,
                'estado' => $estadoInicial,
                'intentos' => $intentosInicial,
                'max_intentos' => $maxIntentos,
                'worker_token' => null,
                'lease_expires_at' => null,
                'created_at' => $ahoraUtc,
                'updated_at' => $ahoraUtc,
            ]);
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try {
                    $db->rollback();
                } catch (\Throwable $rbErr) {
                    error_log("[NotificacionCitaRepository::registrarEventoOutbox] Error en rollback: " . $rbErr->getMessage());
                }
            }
            if ($e instanceof PersistenceException) {
                throw $e;
            }
            throw new PersistenceException("Fallo al registrar evento outbox: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Registra un evento outbox dentro de una transacción ya abierta en la misma conexión MySQL,
     * sin abrir transacciones anidadas ni ejecutar commit/rollback propios.
     *
     * @param array<string, mixed> $datos
     * @param string $ahoraUtc
     * @param mysqli $db Conexión con transacción activa
     * @return NotificacionCita
     * @throws PersistenceException
     */
    public function registrarEventoOutboxEnConexion(array $datos, string $ahoraUtc, mysqli $db): NotificacionCita
    {
        $claveIdempotencia = trim((string)($datos['clave_idempotencia'] ?? ''));
        if ($claveIdempotencia === '') {
            throw new PersistenceException("La clave de idempotencia es obligatoria para registrar evento outbox.");
        }

        try {
            $stmtLock = $db->prepare("SELECT * FROM notificaciones_citas WHERE clave_idempotencia = ? LIMIT 1 FOR UPDATE");
            if (!$stmtLock) {
                throw new PersistenceException("Error al preparar bloqueo por clave de idempotencia en transacción activa: " . $db->error);
            }
            $stmtLock->bind_param('s', $claveIdempotencia);
            if (!$stmtLock->execute()) {
                $err = $stmtLock->error;
                $stmtLock->close();
                throw new PersistenceException("Error al ejecutar bloqueo por clave de idempotencia en transacción activa: " . $err);
            }
            $resLock = $stmtLock->get_result();
            if ($resLock === false) {
                $err = $stmtLock->error;
                $stmtLock->close();
                throw new PersistenceException("Error al leer fila bloqueada por clave de idempotencia en transacción activa: " . $err);
            }
            $existente = $resLock->fetch_assoc();
            $resLock->free();
            $stmtLock->close();

            if ($existente) {
                return new NotificacionCita($existente);
            }

            $citaId = isset($datos['cita_id']) && $datos['cita_id'] !== null && (int)$datos['cita_id'] > 0
                ? (int)$datos['cita_id']
                : null;
            $citaRefId = isset($datos['cita_referencia_id']) && (int)$datos['cita_referencia_id'] > 0
                ? (int)$datos['cita_referencia_id']
                : ($citaId ?? 0);
            $usuarioId = isset($datos['usuario_id']) && $datos['usuario_id'] !== null && (int)$datos['usuario_id'] > 0
                ? (int)$datos['usuario_id']
                : null;
            $tipo = trim((string)($datos['tipo'] ?? NotificacionCita::TIPO_CONFIRMACION));
            $destEmail = mb_substr(trim((string)($datos['destinatario_email'] ?? '')), 0, 60, 'UTF-8');
            $destNombre = mb_substr(trim((string)($datos['destinatario_nombre'] ?? '')), 0, 120, 'UTF-8');
            $asunto = mb_substr(trim((string)($datos['asunto'] ?? '')), 0, 180, 'UTF-8');

            $payloadRaw = $datos['payload_json'] ?? null;
            if (is_array($payloadRaw)) {
                $payloadJson = json_encode($payloadRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
            } elseif (is_string($payloadRaw) && trim($payloadRaw) !== '') {
                $payloadJson = $payloadRaw;
            } else {
                $payloadJson = null;
            }

            $estadoInicial = NotificacionCita::ESTADO_PENDIENTE;
            $intentosInicial = 0;
            $maxIntentos = isset($datos['max_intentos']) && (int)$datos['max_intentos'] >= 1
                ? (int)$datos['max_intentos']
                : NotificacionCita::DEFAULT_MAX_INTENTOS;

            $sqlIns = "INSERT INTO notificaciones_citas
                (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia,
                 destinatario_email, destinatario_nombre, asunto, payload_json,
                 estado, intentos, max_intentos, ultimo_error, enviado_at, proximo_intento_at,
                 worker_token, lease_expires_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)";

            $stmtIns = $db->prepare($sqlIns);
            if (!$stmtIns) {
                throw new PersistenceException("Error al preparar inserción en outbox en transacción activa: " . $db->error);
            }
            $stmtIns->bind_param(
                'iiisssssssiiss',
                $citaId,
                $citaRefId,
                $usuarioId,
                $tipo,
                $claveIdempotencia,
                $destEmail,
                $destNombre,
                $asunto,
                $payloadJson,
                $estadoInicial,
                $intentosInicial,
                $maxIntentos,
                $ahoraUtc,
                $ahoraUtc
            );
            if (!$stmtIns->execute()) {
                $err = $stmtIns->error;
                $stmtIns->close();
                throw new PersistenceException("Error al ejecutar inserción en outbox en transacción activa: " . $err);
            }
            $nuevoId = (int)$stmtIns->insert_id;
            $stmtIns->close();

            return new NotificacionCita([
                'id' => $nuevoId,
                'cita_id' => $citaId,
                'cita_referencia_id' => $citaRefId,
                'usuario_id' => $usuarioId,
                'tipo' => $tipo,
                'clave_idempotencia' => $claveIdempotencia,
                'destinatario_email' => $destEmail,
                'destinatario_nombre' => $destNombre,
                'asunto' => $asunto,
                'payload_json' => $payloadJson,
                'estado' => $estadoInicial,
                'intentos' => $intentosInicial,
                'max_intentos' => $maxIntentos,
                'worker_token' => null,
                'lease_expires_at' => null,
                'created_at' => $ahoraUtc,
                'updated_at' => $ahoraUtc,
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof PersistenceException) {
                throw $e;
            }
            throw new PersistenceException("Fallo al registrar evento outbox en transacción activa: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Adquiere de forma atómica una notificación para envío mediante arrendamiento exclusivo (worker lease).
     *
     * Actualiza atómicamente `estado = 'enviando'`, `worker_token = $workerToken`,
     * `lease_expires_at = NOW() + INTERVAL $duracionLeaseSegundos SECOND`, `intentos = intentos + 1`
     * SOLO si `estado IN ('pendiente', 'reintentando', 'fallido')` O (`estado = 'enviando' AND lease_expires_at < NOW()`).
     *
     * @param int $id
     * @param string $workerToken
     * @param int $duracionLeaseSegundos
     * @return bool
     * @throws PersistenceException
     */
    public function adquirirParaEnvio(int $id, string $workerToken, int $duracionLeaseSegundos = 300): bool
    {
        if ($id <= 0 || trim($workerToken) === '') {
            return false;
        }

        $db = $this->resolveDb();
        $duracion = max(1, $duracionLeaseSegundos);
        $estadoEnviando = NotificacionCita::ESTADO_ENVIANDO;

        $sql = "UPDATE notificaciones_citas
                SET estado = ?,
                    worker_token = ?,
                    lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    intentos = intentos + 1,
                    updated_at = NOW()
                WHERE id = ?
                  AND intentos < max_intentos
                  AND (proximo_intento_at IS NULL OR proximo_intento_at <= NOW())
                  AND (
                      estado IN ('pendiente', 'reintentando', 'fallido')
                      OR (estado = 'enviando' AND (lease_expires_at IS NULL OR lease_expires_at < NOW()))
                  )";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar adquisición para envío con lease: " . $db->error);
            }
            $stmt->bind_param('ssii', $estadoEnviando, $workerToken, $duracion, $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar adquisición para envío con lease: " . $err);
            }
            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas > 0;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::adquirirParaEnvio] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::adquirirParaEnvio] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al adquirir notificación con lease.", 0, $e);
        }
    }

    /**
     * Marca una notificación como enviada exitosamente (`estado = 'enviado'`) SOLO si el worker_token
     * coincide con el arrendamiento activo.
     *
     * Si otro worker adquirió la notificación tras expirar el lease, el worker antiguo es rechazado
     * (retorna false) y no puede sobrescribir el registro.
     *
     * @param int $id
     * @param string $workerToken
     * @return bool
     * @throws PersistenceException
     */
    public function marcarComoEnviado(int $id, string $workerToken): bool
    {
        if ($id <= 0 || trim($workerToken) === '') {
            return false;
        }

        $db = $this->resolveDb();
        $estadoEnviado = NotificacionCita::ESTADO_ENVIADO;
        $sql = "UPDATE notificaciones_citas
                SET estado = ?,
                    enviado_at = NOW(),
                    worker_token = NULL,
                    lease_expires_at = NULL,
                    ultimo_error = NULL,
                    proximo_intento_at = NULL,
                    updated_at = NOW()
                WHERE id = ? AND worker_token = ?";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar marcado como enviado con worker token: " . $db->error);
            }
            $stmt->bind_param('sis', $estadoEnviado, $id, $workerToken);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar marcado como enviado con worker token: " . $err);
            }
            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas > 0;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::marcarComoEnviado] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::marcarComoEnviado] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al marcar notificación como enviada.", 0, $e);
        }
    }

    /**
     * Marca una notificación como fallida o reintentando liberando el arrendamiento (`lease_expires_at = NULL`),
     * SOLO si el worker_token coincide con el poseedor actual.
     *
     * @param int $id
     * @param string $workerToken
     * @param string $mensajeError
     * @param string|null $proximoIntentoAt
     * @return bool
     * @throws PersistenceException
     */
    public function marcarComoFallido(
        int $id,
        string $workerToken,
        string $mensajeError,
        ?string $proximoIntentoAt = null
    ): bool {
        if ($id <= 0 || trim($workerToken) === '') {
            return false;
        }

        $db = $this->resolveDb();
        $estado = NotificacionCita::ESTADO_FALLIDO;
        $errorTruncado = mb_substr(trim($mensajeError), 0, 250, 'UTF-8');

        $sql = "UPDATE notificaciones_citas
                SET estado = ?,
                    ultimo_error = ?,
                    proximo_intento_at = ?,
                    lease_expires_at = NULL,
                    updated_at = NOW()
                WHERE id = ? AND worker_token = ?";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar marcado de fallido con worker token: " . $db->error);
            }
            $stmt->bind_param('sssis', $estado, $errorTruncado, $proximoIntentoAt, $id, $workerToken);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar marcado de fallido con worker token: " . $err);
            }
            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas > 0;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::marcarComoFallido] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::marcarComoFallido] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al marcar notificación como fallida.", 0, $e);
        }
    }

    /**
     * Descarta una notificación obsoleta marcándola como `descartada` o `cancelada`,
     * liberando cualquier arrendamiento activo e impidiendo futuros reintentos.
     *
     * @param int $id
     * @param string $motivo
     * @param string|null $workerToken
     * @param string $nuevoEstado
     * @return bool
     * @throws PersistenceException
     */
    public function descartarNotificacion(
        int $id,
        string $motivo,
        ?string $workerToken = null,
        string $nuevoEstado = NotificacionCita::ESTADO_DESCARTADA
    ): bool {
        if ($id <= 0) {
            return false;
        }

        $db = $this->resolveDb();
        $motivoTruncado = mb_substr(trim($motivo), 0, 250, 'UTF-8');

        if ($workerToken !== null && trim($workerToken) !== '') {
            $sql = "UPDATE notificaciones_citas
                    SET estado = ?,
                        ultimo_error = ?,
                        proximo_intento_at = NULL,
                        lease_expires_at = NULL,
                        updated_at = NOW()
                    WHERE id = ? AND (worker_token = ? OR worker_token IS NULL)";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar descarte de notificación: " . $db->error);
            }
            $tok = trim($workerToken);
            $stmt->bind_param('ssis', $nuevoEstado, $motivoTruncado, $id, $tok);
        } else {
            $sql = "UPDATE notificaciones_citas
                    SET estado = ?,
                        ultimo_error = ?,
                        proximo_intento_at = NULL,
                        lease_expires_at = NULL,
                        updated_at = NOW()
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar descarte de notificación: " . $db->error);
            }
            $stmt->bind_param('ssi', $nuevoEstado, $motivoTruncado, $id);
        }

        try {
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar descarte de notificación: " . $err);
            }
            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas >= 0;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::descartarNotificacion] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::descartarNotificacion] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al descartar notificación.", 0, $e);
        }
    }

    /**
     * Busca notificaciones elegibles para envío o reintento:
     * 1. En estado `'pendiente'`, `'reintentando'` o `'fallido'` con backoff cumplido (`<= $ahoraUtc`)
     *    e intentos menores que `max_intentos`.
     * 2. Tareas en `'enviando'` cuyo `lease_expires_at` haya vencido (`<= $ahoraUtc`), recuperando tareas atascadas.
     *
     * @param string $ahoraUtc
     * @param int $limite
     * @return array<int, NotificacionCita>
     * @throws PersistenceException
     */
    public function buscarCandidatasParaReintento(string $ahoraUtc, int $limite = 50): array
    {
        $db = $this->resolveDb();
        $limiteSeguro = max(1, min(500, $limite));

        $sql = "SELECT *
                FROM notificaciones_citas
                WHERE (
                    estado IN ('pendiente', 'reintentando', 'fallido')
                    AND intentos < max_intentos
                    AND (proximo_intento_at IS NULL OR proximo_intento_at <= ?)
                ) OR (
                    estado = 'enviando'
                    AND intentos < max_intentos
                    AND lease_expires_at IS NOT NULL
                    AND lease_expires_at <= ?
                )
                ORDER BY id ASC
                LIMIT ?";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de candidatas a reintento: " . $db->error);
            }
            $stmt->bind_param('ssi', $ahoraUtc, $ahoraUtc, $limiteSeguro);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de candidatas a reintento: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al leer candidatas a reintento: " . $err);
            }
            $items = [];
            while ($fila = $res->fetch_assoc()) {
                $items[] = new NotificacionCita($fila);
            }
            $res->free();
            $stmt->close();

            return $items;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::buscarCandidatasParaReintento] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::buscarCandidatasParaReintento] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al consultar notificaciones pendientes de reintento.", 0, $e);
        }
    }

    /**
     * Adquiere de forma atómica (`FOR UPDATE`) una notificación candidata para reintento:
     * - Si ya fue enviada o está en `'enviando'` o agotó `max_intentos`, devuelve `accion => 'omitir'`.
     * - Si es de tipo `'recordatorio'` y su `cita_id` ya es `NULL` (o la cita fue eliminada en `citas`),
     *   la descarta marcándola como `'fallido'` con `intentos = max_intentos` y devuelve `accion => 'cita_cancelada'`.
     * - En caso contrario, actualiza `estado = 'enviando'` e incrementa `intentos = intentos + 1`,
     *   confirmando la transacción y devolviendo `accion => 'enviar'`.
     *
     * @return array{accion: string, notificacion: ?NotificacionCita}
     * @throws PersistenceException
     */
    public function adquirirCandidataParaReintento(int $id, string $ahoraUtc): array
    {
        $db = $this->resolveDb();
        $inTransaction = false;

        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException("No se pudo iniciar transacción de adquisición de reintento.");
            }
            $inTransaction = true;

            $stmt = $db->prepare("SELECT * FROM notificaciones_citas WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar bloqueo de reintento: " . $db->error);
            }
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar bloqueo de reintento: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al leer bloqueo de reintento: " . $err);
            }
            $fila = $res->fetch_assoc();
            $res->free();
            $stmt->close();

            if (!$fila) {
                $db->commit();
                $inTransaction = false;
                return ['accion' => 'omitir', 'notificacion' => null];
            }

            $notif = new NotificacionCita($fila);

            $esEnviandoConLeaseVencido = ($notif->estado === NotificacionCita::ESTADO_ENVIANDO)
                && ($notif->lease_expires_at === null || $notif->lease_expires_at <= $ahoraUtc);

            if (
                (!in_array($notif->estado, [NotificacionCita::ESTADO_PENDIENTE, NotificacionCita::ESTADO_FALLIDO, NotificacionCita::ESTADO_REINTENTANDO], true) && !$esEnviandoConLeaseVencido) ||
                ($notif->estado === NotificacionCita::ESTADO_ENVIANDO && !$esEnviandoConLeaseVencido) ||
                $notif->intentos >= $notif->max_intentos ||
                ($notif->proximo_intento_at !== null && $notif->proximo_intento_at > $ahoraUtc && !$esEnviandoConLeaseVencido)
            ) {
                $db->commit();
                $inTransaction = false;
                return ['accion' => 'omitir', 'notificacion' => $notif];
            }

            // Si es un recordatorio o confirmación y la cita fue cancelada/eliminada (cita_id = NULL), descartar
            if (
                in_array($notif->tipo, [NotificacionCita::TIPO_RECORDATORIO, NotificacionCita::TIPO_CONFIRMACION], true) &&
                $notif->cita_id === null
            ) {
                $estadoDescartado = NotificacionCita::ESTADO_DESCARTADA;
                $motivo = $notif->tipo === NotificacionCita::TIPO_RECORDATORIO
                    ? 'Recordatorio descartado: la cita fue cancelada o eliminada.'
                    : 'Confirmación descartada: la cita fue cancelada o eliminada.';
                $maxInt = $notif->max_intentos;

                $stmtDesc = $db->prepare(
                    "UPDATE notificaciones_citas
                     SET estado = ?, intentos = ?, ultimo_error = ?, proximo_intento_at = NULL, lease_expires_at = NULL, updated_at = ?
                     WHERE id = ?"
                );
                if ($stmtDesc) {
                    $stmtDesc->bind_param('sissi', $estadoDescartado, $maxInt, $motivo, $ahoraUtc, $id);
                    $stmtDesc->execute();
                    $stmtDesc->close();
                }

                $db->commit();
                $inTransaction = false;

                $notif->estado = $estadoDescartado;
                $notif->intentos = $maxInt;
                $notif->ultimo_error = $motivo;

                return ['accion' => 'cita_cancelada', 'notificacion' => $notif];
            }

            $estadoEnviando = NotificacionCita::ESTADO_ENVIANDO;
            $nuevosIntentos = $notif->intentos + 1;
            $workerToken = 'worker_' . bin2hex(random_bytes(16));

            $stmtUpd = $db->prepare(
                "UPDATE notificaciones_citas
                 SET estado = ?, intentos = ?, worker_token = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL 300 SECOND), updated_at = ?
                 WHERE id = ?"
            );
            if (!$stmtUpd) {
                throw new PersistenceException("Error al preparar transición a 'enviando' en reintento: " . $db->error);
            }
            $stmtUpd->bind_param('sissi', $estadoEnviando, $nuevosIntentos, $workerToken, $ahoraUtc, $id);
            if (!$stmtUpd->execute()) {
                $err = $stmtUpd->error;
                $stmtUpd->close();
                throw new PersistenceException("Error al ejecutar transición a 'enviando' en reintento: " . $err);
            }
            $stmtUpd->close();

            if (!$db->commit()) {
                throw new PersistenceException("Error al confirmar adquisición de reintento.");
            }
            $inTransaction = false;

            $notif->estado = $estadoEnviando;
            $notif->intentos = $nuevosIntentos;
            $notif->updated_at = $ahoraUtc;

            return ['accion' => 'enviar', 'notificacion' => $notif];
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try {
                    $db->rollback();
                } catch (\Throwable $rbErr) {
                    error_log("[NotificacionCitaRepository::adquirirCandidataParaReintento] Error en rollback: " . $rbErr->getMessage());
                }
            }
            if ($e instanceof PersistenceException) {
                error_log("[NotificacionCitaRepository::adquirirCandidataParaReintento] " . $e->getMessage());
                throw $e;
            }
            error_log("[NotificacionCitaRepository::adquirirCandidataParaReintento] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al adquirir notificación para reintento.", 0, $e);
        }
    }

    /**
     * Obtiene el snapshot completo de una cita existente en `citas` junto con los datos del cliente,
     * el profesional asignado y los servicios con su snapshot histórico (`COALESCE`).
     *
     * @return array<string, mixed>|null
     * @throws PersistenceException
     */
    public function obtenerSnapshotCompletoCita(int $citaId): ?array
    {
        return $this->obtenerSnapshotCompletoCitaEnConexion($citaId, $this->resolveDb());
    }

    /**
     * Obtiene el snapshot completo de una cita utilizando una conexión específica (ej. dentro de una transacción activa).
     *
     * @return array<string, mixed>|null
     * @throws PersistenceException
     */
    public function obtenerSnapshotCompletoCitaEnConexion(int $citaId, mysqli $db): ?array
    {
        if ($citaId <= 0) {
            return null;
        }

        $sql = "SELECT c.id AS cita_id, c.fecha, c.hora, c.hora_inicio, c.hora_fin,
                       c.duracion_total_minutos, c.usuarioId AS usuario_id, c.profesionalId AS profesional_id,
                       u.nombre AS cliente_nombre, u.apellido AS cliente_apellido,
                       u.email AS cliente_email, u.telefono AS cliente_telefono,
                       p.nombre AS profesional_nombre,
                       cs.id AS cita_servicio_id, cs.servicioId AS servicio_id,
                       COALESCE(cs.nombre_servicio, s.nombre) AS servicio_nombre,
                       COALESCE(cs.precio_servicio, s.precio) AS servicio_precio,
                       COALESCE(cs.duracion_minutos, s.duracion_minutos) AS servicio_duracion
                FROM citas c
                LEFT JOIN usuarios u ON u.id = c.usuarioId
                LEFT JOIN profesionales p ON p.id = c.profesionalId
                LEFT JOIN citasservicios cs ON cs.citaId = c.id
                LEFT JOIN servicios s ON s.id = cs.servicioId
                WHERE c.id = ?
                ORDER BY cs.id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de snapshot de cita: " . $db->error);
            }
            $stmt->bind_param('i', $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de snapshot de cita: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al leer snapshot de cita: " . $err);
            }

            $snapshot = null;
            while ($fila = $res->fetch_assoc()) {
                if ($snapshot === null) {
                    $snapshot = $this->inicializarEstructuraSnapshotDesdeFila($fila);
                }
                $this->agregarServicioASnapshot($snapshot, $fila);
            }
            $res->free();
            $stmt->close();

            return $snapshot;
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::obtenerSnapshotCompletoCitaEnConexion] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::obtenerSnapshotCompletoCitaEnConexion] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al obtener snapshot completo de la cita.", 0, $e);
        }
    }

    /**
     * Busca todas las citas activas en el rango de fechas `[$fechaDesde, $fechaHasta]`
     * para evaluar la ventana de recordatorios en `America/Guayaquil`.
     *
     * @return array<int, array<string, mixed>>
     * @throws PersistenceException
     */
    public function buscarCitasEnRangoFechasParaRecordatorio(string $fechaDesde, string $fechaHasta): array
    {
        $db = $this->resolveDb();
        $sql = "SELECT c.id AS cita_id, c.fecha, c.hora, c.hora_inicio, c.hora_fin,
                       c.duracion_total_minutos, c.usuarioId AS usuario_id, c.profesionalId AS profesional_id,
                       u.nombre AS cliente_nombre, u.apellido AS cliente_apellido,
                       u.email AS cliente_email, u.telefono AS cliente_telefono,
                       p.nombre AS profesional_nombre,
                       cs.id AS cita_servicio_id, cs.servicioId AS servicio_id,
                       COALESCE(cs.nombre_servicio, s.nombre) AS servicio_nombre,
                       COALESCE(cs.precio_servicio, s.precio) AS servicio_precio,
                       COALESCE(cs.duracion_minutos, s.duracion_minutos) AS servicio_duracion
                FROM citas c
                INNER JOIN usuarios u ON u.id = c.usuarioId
                LEFT JOIN profesionales p ON p.id = c.profesionalId
                LEFT JOIN citasservicios cs ON cs.citaId = c.id
                LEFT JOIN servicios s ON s.id = cs.servicioId
                WHERE c.fecha BETWEEN ? AND ?
                  AND (c.estado IS NULL OR c.estado != 'cancelada')
                ORDER BY c.fecha ASC, COALESCE(c.hora_inicio, c.hora) ASC, c.id ASC, cs.id ASC";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar consulta de citas para recordatorio: " . $db->error);
            }
            $stmt->bind_param('ss', $fechaDesde, $fechaHasta);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar consulta de citas para recordatorio: " . $err);
            }
            $res = $stmt->get_result();
            if ($res === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al leer citas para recordatorio: " . $err);
            }

            $agrupadas = [];
            while ($fila = $res->fetch_assoc()) {
                $cid = (int)$fila['cita_id'];
                if (!isset($agrupadas[$cid])) {
                    $agrupadas[$cid] = $this->inicializarEstructuraSnapshotDesdeFila($fila);
                }
                $this->agregarServicioASnapshot($agrupadas[$cid], $fila);
            }
            $res->free();
            $stmt->close();

            return array_values($agrupadas);
        } catch (PersistenceException $e) {
            error_log("[NotificacionCitaRepository::buscarCitasEnRangoFechasParaRecordatorio] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaRepository::buscarCitasEnRangoFechasParaRecordatorio] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo al consultar citas en ventana de recordatorio.", 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function inicializarEstructuraSnapshotDesdeFila(array $fila): array
    {
        $horaNorm = HorarioProfesional::normalizarHora((string)($fila['hora'] ?? ''));
        $horaInicioNorm = ($fila['hora_inicio'] !== null && trim((string)$fila['hora_inicio']) !== '')
            ? HorarioProfesional::normalizarHora((string)$fila['hora_inicio'])
            : ($horaNorm !== '' ? $horaNorm : (string)($fila['hora'] ?? ''));
        $horaFinNorm = ($fila['hora_fin'] !== null && trim((string)$fila['hora_fin']) !== '')
            ? HorarioProfesional::normalizarHora((string)$fila['hora_fin'])
            : null;
        $durTotal = ($fila['duracion_total_minutos'] !== null && ctype_digit((string)$fila['duracion_total_minutos']))
            ? (int)$fila['duracion_total_minutos']
            : null;
        $profId = ($fila['profesional_id'] !== null && ctype_digit((string)$fila['profesional_id']) && (int)$fila['profesional_id'] > 0)
            ? (int)$fila['profesional_id']
            : null;
        $usuarioId = ($fila['usuario_id'] !== null && ctype_digit((string)$fila['usuario_id']) && (int)$fila['usuario_id'] > 0)
            ? (int)$fila['usuario_id']
            : null;

        $nombre = trim((string)($fila['cliente_nombre'] ?? ''));
        $apellido = trim((string)($fila['cliente_apellido'] ?? ''));
        $nombreCompleto = trim($nombre . ' ' . $apellido);

        return [
            'cita_id' => (int)$fila['cita_id'],
            'fecha' => (string)$fila['fecha'],
            'hora' => $horaNorm !== '' ? $horaNorm : (string)($fila['hora'] ?? ''),
            'hora_inicio' => $horaInicioNorm,
            'hora_fin' => $horaFinNorm !== '' ? $horaFinNorm : null,
            'duracion_total_minutos' => $durTotal,
            'usuario_id' => $usuarioId,
            'cliente_nombre' => $nombreCompleto !== '' ? $nombreCompleto : 'Cliente AppSalon',
            'cliente_email' => trim((string)($fila['cliente_email'] ?? '')),
            'cliente_telefono' => trim((string)($fila['cliente_telefono'] ?? '')),
            'profesional_id' => $profId,
            'profesional_nombre' => ($profId !== null && isset($fila['profesional_nombre']) && trim((string)$fila['profesional_nombre']) !== '')
                ? trim((string)$fila['profesional_nombre'])
                : null,
            'servicios' => [],
            'total' => 0.0,
            'total_formateado' => '0.00',
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $fila
     */
    private function agregarServicioASnapshot(array &$snapshot, array $fila): void
    {
        if ($fila['cita_servicio_id'] === null) {
            return;
        }

        $precioNum = is_numeric($fila['servicio_precio'] ?? null) ? (float)$fila['servicio_precio'] : 0.0;
        $durServicio = ($fila['servicio_duracion'] !== null && ctype_digit((string)$fila['servicio_duracion']))
            ? (int)$fila['servicio_duracion']
            : null;

        $snapshot['servicios'][] = [
            'id' => ($fila['servicio_id'] !== null && ctype_digit((string)$fila['servicio_id']))
                ? (int)$fila['servicio_id']
                : null,
            'nombre' => (string)($fila['servicio_nombre'] ?? 'Servicio'),
            'precio' => number_format($precioNum, 2, '.', ''),
            'duracion_minutos' => $durServicio,
        ];

        $snapshot['total'] = round((float)$snapshot['total'] + $precioNum, 2);
        $snapshot['total_formateado'] = number_format((float)$snapshot['total'], 2, '.', '');
    }
}
