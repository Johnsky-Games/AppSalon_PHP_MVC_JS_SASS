<?php
namespace Repositories;

use Classes\CargaCifrada;
use Model\ActiveRecord;
use mysqli;

/**
 * Cola (outbox) de correos transaccionales. El evento se inserta en la misma transacción que el hecho
 * de negocio; su clave única impide dos eventos para la misma causa (p. ej. la misma invitación).
 */
final class CorreoSalidaRepository
{
    public const MAX_INTENTOS = 6;
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión'); }
        $this->db = $conexion;
    }

    /** @return int id del evento (existente si la clave ya estaba registrada) */
    public function encolar(string $claveUnica, string $tipo, string $email, string $nombre, array $carga): int
    {
        $cifrada = CargaCifrada::cifrar($carga);
        $s = $this->db->prepare('INSERT INTO correos_salida (clave_unica, tipo, destinatario_email, destinatario_nombre, carga_cifrada) VALUES (?,?,?,?,?)');
        if (!$s) { throw new PersistenceException('No se pudo preparar el evento de correo'); }
        $s->bind_param('sssss', $claveUnica, $tipo, $email, $nombre, $cifrada);
        $ok = $s->execute();
        $errno = (int)$s->errno;
        $id = (int)$this->db->insert_id;
        $s->close();
        if (!$ok) {
            if ($errno === 1062) {
                // La causa ya tenía su evento: no se crea otro (un solo correo por causa).
                return $this->idPorClave($claveUnica);
            }
            throw new PersistenceException('No se pudo registrar el evento de correo');
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
     * Toma el evento con arrendamiento exclusivo. Devuelve null si otro proceso lo tiene, ya se envió
     * o su próximo intento aún no llegó.
     * @return array{id:int,tipo:string,email:string,nombre:string,carga:?array,intentos:int,token:string}|null
     */
    public function tomar(int $id): ?array
    {
        $token = bin2hex(random_bytes(16));
        $s = $this->db->prepare("UPDATE correos_salida SET estado='enviando', worker_token=?, lease_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
            WHERE id=? AND carga_cifrada IS NOT NULL AND ((estado IN ('pendiente','fallido') AND proximo_intento<=UTC_TIMESTAMP()) OR (estado='enviando' AND lease_expires_at<UTC_TIMESTAMP()))");
        $s->bind_param('si', $token, $id);
        $s->execute();
        $tomado = $s->affected_rows === 1;
        $s->close();
        if (!$tomado) { return null; }
        $s = $this->db->prepare('SELECT id, tipo, destinatario_email, destinatario_nombre, carga_cifrada, intentos FROM correos_salida WHERE id=?');
        $s->bind_param('i', $id);
        $s->execute();
        $f = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$f) { return null; }
        return ['id' => (int)$f['id'], 'tipo' => $f['tipo'], 'email' => $f['destinatario_email'], 'nombre' => $f['destinatario_nombre'],
            'carga' => CargaCifrada::descifrar((string)$f['carga_cifrada']), 'intentos' => (int)$f['intentos'], 'token' => $token];
    }

    public function marcarEnviado(int $id, string $workerToken): void
    {
        $s = $this->db->prepare("UPDATE correos_salida SET estado='enviado', carga_cifrada=NULL, enviado_en=UTC_TIMESTAMP(), ultimo_error=NULL, worker_token=NULL, lease_expires_at=NULL, intentos=intentos+1 WHERE id=? AND worker_token=?");
        $s->bind_param('is', $id, $workerToken);
        $s->execute();
        $s->close();
    }

    public function marcarFallido(int $id, string $workerToken, string $error): void
    {
        $error = mb_substr($error, 0, 250, 'UTF-8');
        // MySQL evalúa las asignaciones de izquierda a derecha: tras `intentos=intentos+1`, `intentos` ya es el nuevo valor.
        $s = $this->db->prepare("UPDATE correos_salida SET intentos=intentos+1, ultimo_error=?, worker_token=NULL, lease_expires_at=NULL,
            estado=IF(intentos>=" . self::MAX_INTENTOS . ",'fallido','pendiente'),
            proximo_intento=DATE_ADD(UTC_TIMESTAMP(), INTERVAL LEAST(POW(2,intentos)*30, 3600) SECOND) WHERE id=? AND worker_token=?");
        $s->bind_param('sis', $error, $id, $workerToken);
        $s->execute();
        $s->close();
    }

    public function cancelar(string $claveUnica): void
    {
        $s = $this->db->prepare("UPDATE correos_salida SET estado='cancelado', carga_cifrada=NULL WHERE clave_unica=? AND estado IN ('pendiente','fallido')");
        $s->bind_param('s', $claveUnica);
        $s->execute();
        $s->close();
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
}
