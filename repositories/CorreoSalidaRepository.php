<?php
namespace Repositories;

use Classes\CargaCifrada;
use Classes\Email;
use Model\ActiveRecord;
use mysqli;

/**
 * Cola (outbox) de correos transaccionales. El evento se inserta en la misma transacción que el hecho
 * de negocio; su clave única impide dos eventos para la misma causa (p. ej. la misma invitación).
 *
 * Estados: pendiente -> enviando -> enviado | pendiente (reintento con retroceso) | fallido (agotó MAX_INTENTOS)
 * | cancelado (obsoleto: invitación revocada, enlace reemplazado...). `enviado` y `cancelado` borran la carga cifrada.
 * `fallido` es TERMINAL: solo se reactiva con reintentar() (comando manual), nunca por el procesamiento normal.
 */
final class CorreoSalidaRepository
{
    public const MAX_INTENTOS = 6;
    /** Duración del arrendamiento de un envío. Si el proceso muere, otro lo retoma al vencer. */
    public const LEASE_SEGUNDOS = 120;
    private const REINTENTOS_BLOQUEO = 3;
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión'); }
        $this->db = $conexion;
    }

    /** Conexión usada por la cola: debe ser la MISMA del hecho de negocio para compartir su transacción. */
    public function conexion(): mysqli
    {
        return $this->db;
    }

    /**
     * @param int|null $usuarioId usuario al que pertenece el evento (permite cancelar por usuario); null en los demás.
     * @return int id del evento (existente si la clave ya estaba registrada)
     *
     * creado_en y proximo_intento se fijan en UTC explícitamente: el worker compara contra UTC_TIMESTAMP() y un DEFAULT
     * CURRENT_TIMESTAMP usaría la zona del servidor MySQL (un servidor en UTC+ retrasaría el primer intento horas).
     */
    public function encolar(string $claveUnica, string $tipo, string $email, string $nombre, array $carga, ?int $usuarioId = null): int
    {
        $cifrada = CargaCifrada::cifrar($carga);
        $s = $this->db->prepare('INSERT INTO correos_salida (clave_unica, tipo, usuario_id, destinatario_email, destinatario_nombre, carga_cifrada, creado_en, proximo_intento) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        if (!$s) { throw new PersistenceException('No se pudo preparar el evento de correo'); }
        $s->bind_param('ssisss', $claveUnica, $tipo, $usuarioId, $email, $nombre, $cifrada);
        $ok = $s->execute();
        $errno = (int)$s->errno;
        $id = (int)$this->db->insert_id;
        $s->close();
        if (!$ok) {
            if ($errno === 1062) {
                // La causa ya tenía su evento: no se crea otro (un solo correo por causa).
                return $this->idPorClave($claveUnica);
            }
            throw new PersistenceException('No se pudo registrar el evento de correo', $errno);
        }
        return $id;
    }

    private function idPorClave(string $clave): int
    {
        $s = $this->db->prepare('SELECT id FROM correos_salida WHERE clave_unica = ?');
        $s->bind_param('s', $clave);
        $s->execute();
        $fila = $s->get_result()->fetch_assoc();
        $s->close();
        return (int)($fila['id'] ?? 0);
    }

    /**
     * Toma el evento con arrendamiento exclusivo. Devuelve null si otro proceso lo tiene, ya se envió, es terminal
     * (fallido/cancelado) o su próximo intento aún no llegó.
     * @return array{id:int,tipo:string,usuario_id:?int,email:string,nombre:string,carga:?array,intentos:int,token:string}|null
     */
    public function tomar(int $id): ?array
    {
        $token = bin2hex(random_bytes(16));
        $lease = self::LEASE_SEGUNDOS;
        $s = $this->db->prepare("UPDATE correos_salida SET estado='enviando', worker_token=?, lease_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$lease} SECOND)
            WHERE id=? AND carga_cifrada IS NOT NULL AND ((estado='pendiente' AND proximo_intento<=UTC_TIMESTAMP()) OR (estado='enviando' AND lease_expires_at<UTC_TIMESTAMP()))");
        if (!$s) { return null; }
        $s->bind_param('si', $token, $id);
        $ok = $s->execute();
        $tomado = $ok && $s->affected_rows === 1;
        $s->close();
        if (!$tomado) { return null; }
        $s = $this->db->prepare('SELECT id, tipo, usuario_id, destinatario_email, destinatario_nombre, carga_cifrada, intentos FROM correos_salida WHERE id=?');
        $s->bind_param('i', $id);
        $s->execute();
        $f = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$f) { return null; }
        return ['id' => (int)$f['id'], 'tipo' => $f['tipo'], 'usuario_id' => $f['usuario_id'] === null ? null : (int)$f['usuario_id'],
            'email' => $f['destinatario_email'], 'nombre' => $f['destinatario_nombre'],
            'carga' => CargaCifrada::descifrar((string)$f['carga_cifrada']), 'intentos' => (int)$f['intentos'], 'token' => $token];
    }

    /** @return bool true si el estado se aplicó (el arrendamiento seguía siendo nuestro) */
    public function marcarEnviado(int $id, string $workerToken): bool
    {
        return (bool)$this->conReintento(function () use ($id, $workerToken): ?bool {
            $s = $this->db->prepare("UPDATE correos_salida SET estado='enviado', carga_cifrada=NULL, enviado_en=UTC_TIMESTAMP(), ultimo_error=NULL, worker_token=NULL, lease_expires_at=NULL, intentos=intentos+1 WHERE id=? AND worker_token=?");
            if (!$s) { return null; }
            $s->bind_param('is', $id, $workerToken);
            $ok = $s->execute();
            $filas = $s->affected_rows;
            $s->close();
            return $ok ? $filas === 1 : null;
        });
    }

    /**
     * Registra un intento fallido: programa el siguiente con retroceso exponencial o, al llegar a MAX_INTENTOS,
     * deja el evento en `fallido` (terminal, visible y reintentable con reintentar()).
     * @return string|null 'pendiente' | 'fallido', o null si el arrendamiento ya no era nuestro
     */
    public function marcarFallido(int $id, string $workerToken, string $error): ?string
    {
        $error = Email::sanearMensajeError($error);
        // MySQL evalúa las asignaciones de izquierda a derecha: tras `intentos=intentos+1`, `intentos` ya es el nuevo valor.
        // Esperas tras el fallo n: LEAST(2^n * 30, 3600) s => 60, 120, 240, 480, 960 s; el 6.º fallo es terminal.
        $aplicado = $this->conReintento(function () use ($id, $workerToken, $error): ?bool {
            $s = $this->db->prepare("UPDATE correos_salida SET intentos=intentos+1, ultimo_error=?, worker_token=NULL, lease_expires_at=NULL,
                estado=IF(intentos>=" . self::MAX_INTENTOS . ",'fallido','pendiente'),
                proximo_intento=DATE_ADD(UTC_TIMESTAMP(), INTERVAL LEAST(POW(2,intentos)*30, 3600) SECOND) WHERE id=? AND worker_token=?");
            if (!$s) { return null; }
            $s->bind_param('sis', $error, $id, $workerToken);
            $ok = $s->execute();
            $filas = $s->affected_rows;
            $s->close();
            return $ok ? $filas === 1 : null;
        });
        return $aplicado ? ($this->estadoPorId($id)['estado'] ?? null) : null;
    }

    /** Fallo NO reintentable (carga ilegible, clave de cifrado cambiada): termina el evento sin consumir reintentos. */
    public function marcarFallidoDefinitivo(int $id, string $workerToken, string $error): bool
    {
        $error = Email::sanearMensajeError($error);
        return (bool)$this->conReintento(function () use ($id, $workerToken, $error): ?bool {
            $s = $this->db->prepare("UPDATE correos_salida SET estado='fallido', intentos=intentos+1, ultimo_error=?, worker_token=NULL, lease_expires_at=NULL WHERE id=? AND worker_token=?");
            if (!$s) { return null; }
            $s->bind_param('sis', $error, $id, $workerToken);
            $ok = $s->execute();
            $filas = $s->affected_rows;
            $s->close();
            return $ok ? $filas === 1 : null;
        });
    }

    /** El evento dejó de ser válido mientras lo tenía este worker (enlace reemplazado, cuenta ya confirmada...). */
    public function marcarCancelado(int $id, string $workerToken, string $motivo): bool
    {
        $motivo = Email::sanearMensajeError($motivo);
        return (bool)$this->conReintento(function () use ($id, $workerToken, $motivo): ?bool {
            $s = $this->db->prepare("UPDATE correos_salida SET estado='cancelado', carga_cifrada=NULL, ultimo_error=?, worker_token=NULL, lease_expires_at=NULL WHERE id=? AND worker_token=?");
            if (!$s) { return null; }
            $s->bind_param('sis', $motivo, $id, $workerToken);
            $ok = $s->execute();
            $filas = $s->affected_rows;
            $s->close();
            return $ok ? $filas === 1 : null;
        });
    }

    /** Reintenta una sentencia ante interbloqueo (1213) o espera de bloqueo agotada (1205). null = no se pudo ejecutar. */
    private function conReintento(callable $operacion): ?bool
    {
        for ($i = 1; $i <= self::REINTENTOS_BLOQUEO; $i++) {
            $r = $operacion();
            if ($r !== null) { return $r; }
            if (!in_array((int)$this->db->errno, [1213, 1205], true) || $i === self::REINTENTOS_BLOQUEO) { break; }
            usleep(random_int(10000, 60000) * $i);
        }
        return null;
    }

    public function cancelar(string $claveUnica): void
    {
        $s = $this->db->prepare("UPDATE correos_salida SET estado='cancelado', carga_cifrada=NULL WHERE clave_unica=? AND estado IN ('pendiente','fallido')");
        $s->bind_param('s', $claveUnica);
        $s->execute();
        $s->close();
    }

    /**
     * Cancela los eventos AÚN NO ENTREGADOS de un usuario y tipo (pendiente, enviando, fallido), p. ej. los enlaces de
     * confirmación que un reenvío acaba de invalidar. Un evento `enviando` cancelado impide que su worker lo marque
     * como enviado, aunque el correo en vuelo pueda llegar (límite documentado). Se invoca dentro de la transacción
     * del reenvío; la serialización entre reenvíos la da el bloqueo de la fila del usuario, no esta sentencia.
     * @return int eventos cancelados
     */
    public function cancelarPorUsuario(int $usuarioId, string $tipo, string $motivo, ?int $exceptoId = null): int
    {
        $motivo = Email::sanearMensajeError($motivo);
        $excepto = $exceptoId ?? 0;
        $s = $this->db->prepare("UPDATE correos_salida SET estado='cancelado', carga_cifrada=NULL, ultimo_error=?, worker_token=NULL, lease_expires_at=NULL
            WHERE usuario_id=? AND tipo=? AND id<>? AND estado IN ('pendiente','enviando','fallido')");
        if (!$s) { throw new PersistenceException('No se pudo preparar la cancelación de eventos'); }
        $s->bind_param('sisi', $motivo, $usuarioId, $tipo, $excepto);
        if (!$s->execute()) {
            $errno = (int)$s->errno;
            $s->close();
            throw new PersistenceException('No se pudieron cancelar los eventos anteriores', $errno);
        }
        $n = max(0, (int)$s->affected_rows);
        $s->close();
        return $n;
    }

    /** @return int[] ids listos para procesar */
    public function listos(int $limite = 20): array
    {
        $limite = max(1, min(100, $limite));
        $r = $this->db->query("SELECT id FROM correos_salida WHERE carga_cifrada IS NOT NULL AND ((estado='pendiente' AND proximo_intento<=UTC_TIMESTAMP()) OR (estado='enviando' AND lease_expires_at<UTC_TIMESTAMP())) ORDER BY id LIMIT {$limite}");
        return $r ? array_map('intval', array_column($r->fetch_all(MYSQLI_ASSOC), 'id')) : [];
    }

    public function estadoPorClave(string $clave): ?array
    {
        $s = $this->db->prepare('SELECT estado, intentos, ultimo_error, enviado_en FROM correos_salida WHERE clave_unica=?');
        $s->bind_param('s', $clave);
        $s->execute();
        $f = $s->get_result()->fetch_assoc();
        $s->close();
        return $f ?: null;
    }

    private function estadoPorId(int $id): ?array
    {
        $s = $this->db->prepare('SELECT estado, intentos FROM correos_salida WHERE id=?');
        $s->bind_param('i', $id);
        $s->execute();
        $f = $s->get_result()->fetch_assoc();
        $s->close();
        return $f ?: null;
    }

    /**
     * Reactiva manualmente eventos `fallido` que conservan su carga: vuelven a `pendiente` con 0 intentos y
     * vencimiento inmediato. No toca enviados, cancelados ni pendientes. `$id` null = todos los fallidos.
     * @return int eventos reactivados
     */
    public function reintentar(?int $id = null): int
    {
        $base = "UPDATE correos_salida SET estado='pendiente', intentos=0, proximo_intento=UTC_TIMESTAMP(), worker_token=NULL, lease_expires_at=NULL
            WHERE estado='fallido' AND carga_cifrada IS NOT NULL";
        if ($id === null) {
            $s = $this->db->prepare($base);
        } else {
            $s = $this->db->prepare($base . ' AND id=?');
            if ($s) { $s->bind_param('i', $id); }
        }
        if (!$s) { throw new PersistenceException('No se pudo preparar el reintento'); }
        if (!$s->execute()) { $s->close(); throw new PersistenceException('No se pudo reactivar el evento'); }
        $n = max(0, (int)$s->affected_rows);
        $s->close();
        return $n;
    }

    /**
     * Diagnóstico operativo SIN datos personales (ni correos, ni nombres, ni cargas): conteos por estado y tipo,
     * antigüedad del evento activo más antiguo y los fallidos con id, tipo, intentos y último error saneado.
     * @return array{por_estado:array<string,array<string,int>>,total:int,pendiente_mas_antiguo_segundos:?int,enviando_vencidos:int,fallidos:list<array<string,mixed>>}
     */
    public function resumen(int $maxFallidos = 50): array
    {
        $porEstado = [];
        $total = 0;
        $r = $this->db->query('SELECT estado, tipo, COUNT(*) n FROM correos_salida GROUP BY estado, tipo ORDER BY estado, tipo');
        foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $f) {
            $porEstado[$f['estado']][$f['tipo']] = (int)$f['n'];
            $total += (int)$f['n'];
        }
        $r = $this->db->query("SELECT TIMESTAMPDIFF(SECOND, MIN(creado_en), UTC_TIMESTAMP()) s FROM correos_salida WHERE estado IN ('pendiente','enviando') AND carga_cifrada IS NOT NULL");
        $edad = $r ? $r->fetch_assoc()['s'] : null;
        $r = $this->db->query("SELECT COUNT(*) n FROM correos_salida WHERE estado='enviando' AND lease_expires_at<UTC_TIMESTAMP()");
        $vencidos = $r ? (int)$r->fetch_assoc()['n'] : 0;
        $maxFallidos = max(1, min(500, $maxFallidos));
        $r = $this->db->query("SELECT id, tipo, intentos, ultimo_error, TIMESTAMPDIFF(SECOND, creado_en, UTC_TIMESTAMP()) edad_segundos, carga_cifrada IS NOT NULL reintentable
            FROM correos_salida WHERE estado='fallido' ORDER BY id LIMIT {$maxFallidos}");
        $fallidos = [];
        foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $f) {
            $fallidos[] = ['id' => (int)$f['id'], 'tipo' => $f['tipo'], 'intentos' => (int)$f['intentos'], 'edad_segundos' => (int)$f['edad_segundos'],
                'reintentable' => (bool)$f['reintentable'], 'ultimo_error' => (string)$f['ultimo_error']];
        }
        return ['por_estado' => $porEstado, 'total' => $total, 'pendiente_mas_antiguo_segundos' => $edad === null ? null : (int)$edad,
            'enviando_vencidos' => $vencidos, 'fallidos' => $fallidos];
    }
}
