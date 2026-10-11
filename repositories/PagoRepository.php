<?php

namespace Repositories;

use Classes\MockPaymentGateway;
use Model\ActiveRecord;
use Model\Pago;
use mysqli;

/**
 * Repositorio de persistencia para la tabla `pagos` y estado de pago de `citas` (Fase 6C):
 * - Utiliza exclusivamente sentencias preparadas (`prepare` + `bind_param` + `execute`).
 * - Calcula el importe de la cita en el servidor a partir de los snapshots históricos
 *   en `citasservicios` (`COALESCE(cs.precio_servicio, s.precio)`).
 * - Soporta bloqueo pesimista `FOR UPDATE` en InnoDB para garantizar idempotencia
 *   transaccional ante inicios de pago concurrentes o webhooks repetidos.
 * - Sanea cualquier `payload_proveedor` impidiendo almacenar números de tarjeta, CVV o fechas de expiración.
 */
class PagoRepository
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
            error_log('[PagoRepository] Error al resolver conexión DB: ' . $e->getMessage());
            throw new PersistenceException('Conexión a base de datos no inicializada o inválida.', 0, $e);
        }

        if (!$db instanceof mysqli) {
            throw new PersistenceException('Conexión a base de datos no inicializada o inválida.');
        }

        return $db;
    }

    public function beginTransaction(): void
    {
        $db = $this->resolveDb();
        try {
            if (!$db->begin_transaction()) {
                throw new PersistenceException('No fue posible iniciar la transacción de pagos: ' . $db->error);
            }
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Error inesperado al iniciar transacción de pagos.', 0, $e);
        }
    }

    public function commit(): void
    {
        $db = $this->resolveDb();
        try {
            if (!$db->commit()) {
                throw new PersistenceException('No fue posible confirmar la transacción de pagos: ' . $db->error);
            }
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Error inesperado al confirmar transacción de pagos.', 0, $e);
        }
    }

    public function rollback(): void
    {
        try {
            $db = $this->resolveDb();
            $db->rollback();
        } catch (\Throwable $e) {
            error_log('[PagoRepository::rollback] Error durante rollback: ' . $e->getMessage());
        }
    }

    /**
     * Sanea y serializa `payload_proveedor` garantizando que jamás contenga
     * números de tarjeta, CVV ni fechas de expiración.
     *
     * @param mixed $payload
     */
    public static function serializarPayloadSeguro(mixed $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $limpio = MockPaymentGateway::sanearPayloadSinDatosTarjeta($decoded);
                return json_encode($limpio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
            }
            $limpioStr = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[REDACTED_PAN]', $payload) ?? $payload;
            return json_encode(['raw' => $limpioStr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        if (is_array($payload)) {
            $limpio = MockPaymentGateway::sanearPayloadSinDatosTarjeta($payload);
            return json_encode($limpio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return null;
    }

    /**
     * Obtiene la cita y calcula su importe total exclusivamente en el servidor
     * sumando los precios históricos registrados en `citasservicios` (con fallback a `servicios`).
     *
     * @param int $citaId
     * @param bool $forUpdate Si es `true`, adquiere bloqueo `FOR UPDATE` sobre la fila de `citas`.
     * @return array<string, mixed>|null
     * @throws PersistenceException
     */
    public function obtenerCitaConImporteServidor(int $citaId, bool $forUpdate = false): ?array
    {
        $db = $this->resolveDb();
        $sqlCita = "SELECT
                        c.id,
                        c.fecha,
                        c.hora,
                        c.hora_inicio,
                        c.hora_fin,
                        c.duracion_total_minutos,
                        c.usuarioId,
                        c.profesionalId,
                        c.estado_pago,
                        p.nombre AS profesional_nombre,
                        u.nombre AS cliente_nombre,
                        u.apellido AS cliente_apellido,
                        u.email AS cliente_email
                    FROM citas c
                    LEFT JOIN profesionales p ON p.id = c.profesionalId
                    LEFT JOIN usuarios u ON u.id = c.usuarioId
                    WHERE c.id = ?
                    LIMIT 1";
        if ($forUpdate) {
            $sqlCita .= " FOR UPDATE";
        }

        try {
            $stmtCita = $db->prepare($sqlCita);
            if (!$stmtCita) {
                throw new PersistenceException('Error al preparar consulta de cita para pago: ' . $db->error);
            }
            $stmtCita->bind_param('i', $citaId);
            if (!$stmtCita->execute()) {
                $err = $stmtCita->error;
                $stmtCita->close();
                throw new PersistenceException('Error al ejecutar consulta de cita para pago: ' . $err);
            }
            $resCita = $stmtCita->get_result();
            $filaCita = $resCita ? $resCita->fetch_assoc() : null;
            $stmtCita->close();

            if (!$filaCita) {
                return null;
            }

            $sqlServicios = "SELECT
                                cs.id,
                                cs.servicioId,
                                COALESCE(cs.nombre_servicio, s.nombre) AS nombre_servicio,
                                COALESCE(cs.precio_servicio, s.precio) AS precio_servicio,
                                COALESCE(cs.duracion_minutos, s.duracion_minutos) AS duracion_minutos
                             FROM citasservicios cs
                             LEFT JOIN servicios s ON s.id = cs.servicioId
                             WHERE cs.citaId = ?
                             ORDER BY cs.id ASC";

            $stmtSrv = $db->prepare($sqlServicios);
            if (!$stmtSrv) {
                throw new PersistenceException('Error al preparar consulta de servicios de cita para pago: ' . $db->error);
            }
            $stmtSrv->bind_param('i', $citaId);
            if (!$stmtSrv->execute()) {
                $err = $stmtSrv->error;
                $stmtSrv->close();
                throw new PersistenceException('Error al ejecutar consulta de servicios de cita para pago: ' . $err);
            }
            $resSrv = $stmtSrv->get_result();
            $servicios = [];
            $montoTotal = 0.00;
            while ($srv = $resSrv->fetch_assoc()) {
                $precio = isset($srv['precio_servicio']) && is_numeric($srv['precio_servicio'])
                    ? round(max(0.0, (float)$srv['precio_servicio']), 2)
                    : 0.00;
                $montoTotal += $precio;
                $servicios[] = [
                    'id' => (int)$srv['id'],
                    'servicio_id' => $srv['servicioId'] !== null ? (int)$srv['servicioId'] : null,
                    'nombre' => (string)($srv['nombre_servicio'] ?? 'Servicio'),
                    'precio' => $precio,
                    'duracion_minutos' => isset($srv['duracion_minutos']) ? (int)$srv['duracion_minutos'] : 0,
                ];
            }
            $stmtSrv->close();

            return [
                'id' => (int)$filaCita['id'],
                'fecha' => (string)$filaCita['fecha'],
                'hora' => (string)$filaCita['hora'],
                'hora_inicio' => $filaCita['hora_inicio'] !== null ? (string)$filaCita['hora_inicio'] : null,
                'hora_fin' => $filaCita['hora_fin'] !== null ? (string)$filaCita['hora_fin'] : null,
                'duracion_total_minutos' => $filaCita['duracion_total_minutos'] !== null ? (int)$filaCita['duracion_total_minutos'] : null,
                'usuario_id' => $filaCita['usuarioId'] !== null ? (int)$filaCita['usuarioId'] : null,
                'profesional_id' => $filaCita['profesionalId'] !== null ? (int)$filaCita['profesionalId'] : null,
                'profesional_nombre' => $filaCita['profesional_nombre'] !== null ? (string)$filaCita['profesional_nombre'] : null,
                'cliente_nombre' => trim(((string)($filaCita['cliente_nombre'] ?? '')) . ' ' . ((string)($filaCita['cliente_apellido'] ?? ''))),
                'cliente_email' => (string)($filaCita['cliente_email'] ?? ''),
                'estado_pago' => (string)($filaCita['estado_pago'] ?? 'pendiente'),
                'monto_servidor' => round($montoTotal, 2),
                'servicios' => $servicios,
            ];
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::obtenerCitaConImporteServidor] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al calcular importe de cita en servidor.', 0, $e);
        }
    }

    /**
     * Adquiere un bloqueo pesimista `FOR UPDATE` sobre una cita específica.
     * Garantiza el orden estricto de bloqueos (citas -> pagos) para prevenir deadlocks cíclicos.
     */
    public function bloquearCitaPorId(int $citaId): bool
    {
        $db = $this->resolveDb();
        $sql = "SELECT id FROM citas WHERE id = ? FOR UPDATE";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar bloqueo pesimista de cita: ' . $db->error);
            }
            $stmt->bind_param('i', $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar bloqueo pesimista de cita: ' . $err);
            }
            $res = $stmt->get_result();
            $existe = $res && $res->num_rows > 0;
            $stmt->close();

            return $existe;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::bloquearCitaPorId] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al bloquear cita para pago.', 0, $e);
        }
    }

    /**
     * Consulta el identificador de cita asociado a una referencia de pago sin bloqueo exclusivo.
     */
    public function obtenerCitaIdPorReferencia(string $referenciaPago): ?int
    {
        $db = $this->resolveDb();
        $sql = "SELECT cita_id, cita_referencia_id FROM pagos WHERE referencia_pago = ? LIMIT 1";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de cita por referencia de pago: ' . $db->error);
            }
            $stmt->bind_param('s', $referenciaPago);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de cita por referencia de pago: ' . $err);
            }
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$fila) {
                return null;
            }

            if ($fila['cita_id'] !== null && (int)$fila['cita_id'] > 0) {
                return (int)$fila['cita_id'];
            }
            if (isset($fila['cita_referencia_id']) && (int)$fila['cita_referencia_id'] > 0) {
                return (int)$fila['cita_referencia_id'];
            }

            return null;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::obtenerCitaIdPorReferencia] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al obtener cita por referencia de pago.', 0, $e);
        }
    }

    /**
     * Consulta el identificador de cita asociado a un ID de transacción externa sin bloqueo exclusivo.
     */
    public function obtenerCitaIdPorTransaccionExterna(string $transaccionExternaId): ?int
    {
        $db = $this->resolveDb();
        $sql = "SELECT cita_id, cita_referencia_id FROM pagos WHERE transaccion_externa_id = ? LIMIT 1";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de cita por transacción externa: ' . $db->error);
            }
            $stmt->bind_param('s', $transaccionExternaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de cita por transacción externa: ' . $err);
            }
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$fila) {
                return null;
            }

            if ($fila['cita_id'] !== null && (int)$fila['cita_id'] > 0) {
                return (int)$fila['cita_id'];
            }
            if (isset($fila['cita_referencia_id']) && (int)$fila['cita_referencia_id'] > 0) {
                return (int)$fila['cita_referencia_id'];
            }

            return null;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::obtenerCitaIdPorTransaccionExterna] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al obtener cita por transacción externa.', 0, $e);
        }
    }

    public function findById(int $id, bool $forUpdate = false): ?Pago
    {
        return $this->findOneByColumn('id', 'i', $id, $forUpdate);
    }

    public function findByReferencia(string $referenciaPago, bool $forUpdate = false): ?Pago
    {
        return $this->findOneByColumn('referencia_pago', 's', $referenciaPago, $forUpdate);
    }

    public function findByIdempotencyKey(string $idempotencyKey, bool $forUpdate = false): ?Pago
    {
        return $this->findOneByColumn('idempotency_key', 's', $idempotencyKey, $forUpdate);
    }

    public function findByTransaccionExternaId(string $transaccionExternaId, bool $forUpdate = false): ?Pago
    {
        return $this->findOneByColumn('transaccion_externa_id', 's', $transaccionExternaId, $forUpdate);
    }

    private function findOneByColumn(string $columnaPermitida, string $tipoBind, int|string $valor, bool $forUpdate = false): ?Pago
    {
        $columnasValidas = ['id', 'referencia_pago', 'idempotency_key', 'transaccion_externa_id'];
        if (!in_array($columnaPermitida, $columnasValidas, true)) {
            throw new PersistenceException('Columna de búsqueda de pago no permitida.');
        }

        $db = $this->resolveDb();
        $sql = "SELECT * FROM pagos WHERE {$columnaPermitida} = ? LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de pago: ' . $db->error);
            }
            $stmt->bind_param($tipoBind, $valor);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de pago: ' . $err);
            }
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            return $fila ? new Pago($fila) : null;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::findOneByColumn] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar registro de pago.', 0, $e);
        }
    }

    /**
     * Busca si existe un pago con estado `completado` para una cita dada.
     */
    public function findCompletadoPorCitaId(int $citaId, bool $forUpdate = false): ?Pago
    {
        $db = $this->resolveDb();
        $sql = "SELECT * FROM pagos
                WHERE (cita_id = ? OR cita_referencia_id = ?) AND estado = 'completado'
                ORDER BY id DESC
                LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de pago completado: ' . $db->error);
            }
            $stmt->bind_param('ii', $citaId, $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de pago completado: ' . $err);
            }
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            return $fila ? new Pago($fila) : null;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::findCompletadoPorCitaId] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar pago completado por cita.', 0, $e);
        }
    }

    /**
     * Busca si existe un pago activo (estado 'pendiente' o 'procesando') para una cita dada.
     */
    public function findActivoPorCitaId(int $citaId, bool $forUpdate = false): ?Pago
    {
        $db = $this->resolveDb();
        $sql = "SELECT * FROM pagos
                WHERE (cita_id = ? OR cita_referencia_id = ?) AND estado IN ('pendiente', 'procesando')
                ORDER BY id DESC
                LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de pago activo: ' . $db->error);
            }
            $stmt->bind_param('ii', $citaId, $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de pago activo: ' . $err);
            }
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            return $fila ? new Pago($fila) : null;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::findActivoPorCitaId] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar pago activo por cita.', 0, $e);
        }
    }

    /**
     * Busca el último registro de pago asociado a una cita.
     */
    public function findUltimoPorCitaId(int $citaId, bool $forUpdate = false): ?Pago
    {
        $db = $this->resolveDb();
        $sql = "SELECT * FROM pagos
                WHERE cita_id = ? OR cita_referencia_id = ?
                ORDER BY id DESC
                LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar consulta de último pago de cita: ' . $db->error);
            }
            $stmt->bind_param('ii', $citaId, $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar consulta de último pago de cita: ' . $err);
            }
            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            return $fila ? new Pago($fila) : null;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::findUltimoPorCitaId] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al consultar último pago de cita.', 0, $e);
        }
    }

    /**
     * Inserta un nuevo registro en `pagos` asegurando que `payload_proveedor` esté libre
     * de datos sensibles de tarjeta.
     */
    public function create(Pago $pago): int
    {
        $db = $this->resolveDb();
        $sql = "INSERT INTO pagos (
                    cita_id,
                    cita_referencia_id,
                    usuario_id,
                    referencia_pago,
                    proveedor,
                    transaccion_externa_id,
                    monto,
                    moneda,
                    estado,
                    metodo_pago,
                    idempotency_key,
                    payload_proveedor,
                    pagado_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $citaId = $pago->cita_id;
        $citaRefId = $pago->cita_referencia_id > 0 ? $pago->cita_referencia_id : (int)($pago->cita_id ?? 0);
        $usuarioId = $pago->usuario_id;
        $referencia = $pago->referencia_pago;
        $proveedor = $pago->proveedor;
        $txExterna = $pago->transaccion_externa_id;
        $monto = round($pago->monto, 2);
        $moneda = $pago->moneda;
        $estado = $pago->estado;
        $metodoPago = $pago->metodo_pago;
        $idempotencyKey = $pago->idempotency_key;
        $payloadSeguro = self::serializarPayloadSeguro($pago->payload_proveedor);
        $pagadoAt = $pago->pagado_at;

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar inserción de pago: ' . $db->error);
            }

            $stmt->bind_param(
                'iiisssdssssss',
                $citaId,
                $citaRefId,
                $usuarioId,
                $referencia,
                $proveedor,
                $txExterna,
                $monto,
                $moneda,
                $estado,
                $metodoPago,
                $idempotencyKey,
                $payloadSeguro,
                $pagadoAt
            );

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $errno = $stmt->errno;
                $stmt->close();
                throw new PersistenceException('Error al insertar registro de pago: ' . $err, $errno);
            }

            $insertId = (int)$stmt->insert_id;
            $stmt->close();
            $pago->id = $insertId;
            $pago->payload_proveedor = $payloadSeguro;

            return $insertId;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::create] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al registrar pago en base de datos.', (int)$e->getCode(), $e);
        }
    }

    /**
     * Actualiza el estado, transacción externa, fecha de pago y payload sanitizado de un pago.
     *
     * @param int $pagoId
     * @param string $estado
     * @param string|null $transaccionExternaId
     * @param string|null $pagadoAt
     * @param array<string, mixed>|string|null $payloadProveedor
     * @param string|null $metodoPago
     */
    public function actualizarEstadoPago(
        int $pagoId,
        string $estado,
        ?string $transaccionExternaId = null,
        ?string $pagadoAt = null,
        array|string|null $payloadProveedor = null,
        ?string $metodoPago = null
    ): void {
        $db = $this->resolveDb();
        $sql = "UPDATE pagos
                SET estado = ?,
                    transaccion_externa_id = COALESCE(?, transaccion_externa_id),
                    pagado_at = COALESCE(?, pagado_at),
                    payload_proveedor = COALESCE(?, payload_proveedor),
                    metodo_pago = COALESCE(?, metodo_pago)
                WHERE id = ?
                LIMIT 1";

        $payloadSeguro = self::serializarPayloadSeguro($payloadProveedor);

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar actualización de pago: ' . $db->error);
            }
            $stmt->bind_param(
                'sssssi',
                $estado,
                $transaccionExternaId,
                $pagadoAt,
                $payloadSeguro,
                $metodoPago,
                $pagoId
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar actualización de pago: ' . $err);
            }
            $stmt->close();
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::actualizarEstadoPago] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al actualizar estado de pago.', 0, $e);
        }
    }

    /**
     * Actualiza la columna `estado_pago` en la tabla `citas`.
     */
    public function actualizarEstadoPagoCita(int $citaId, string $estadoPago): void
    {
        $db = $this->resolveDb();
        $sql = "UPDATE citas SET estado_pago = ? WHERE id = ? LIMIT 1";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException('Error al preparar actualización de estado_pago en cita: ' . $db->error);
            }
            $stmt->bind_param('si', $estadoPago, $citaId);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException('Error al ejecutar actualización de estado_pago en cita: ' . $err);
            }
            $stmt->close();
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PagoRepository::actualizarEstadoPagoCita] Excepción: ' . $e->getMessage());
            throw new PersistenceException('Error inesperado al actualizar estado_pago en cita.', 0, $e);
        }
    }
}
