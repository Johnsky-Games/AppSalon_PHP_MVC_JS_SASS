<?php
namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/**
 * Lecturas y auditoría del alta de clientes hecha por el equipo (ruta «heredada», origen `recepcion`).
 * La creación de la cuenta sigue en UsuarioRepository::createCliente; aquí solo se buscan posibles homónimos
 * (solo lectura) y se anexan las decisiones del operador (append-only, tabla altas_homonimos_decisiones).
 */
final class AltaClienteRepository
{
    /** Tope de seguridad de filas candidatas leídas por consulta (solo 5 columnas por fila). */
    private const MAX_CANDIDATOS = 200000;
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión a la base de datos.'); }
        $this->db = $conexion;
    }

    private function filas(string $sql, string $types = '', array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar la consulta de homónimos.'); }
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            if (!$stmt->execute()) { $errno = (int)$stmt->errno; $stmt->close(); throw new PersistenceException('No se pudo consultar posibles homónimos (errno ' . $errno . ').'); }
            $res = $stmt->get_result();
            $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $filas;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo en la capa de persistencia de homónimos.', (int)$e->getCode(), $e);
        }
    }

    /**
     * Personas del universo de clientes (misma definición que el CRM). Se comparan en PHP (ver HomonimosService) porque
     * «parecido» no se expresa bien en SQL; el volumen esperado (miles de clientes) lo permite.
     * @return array<int,array{id:int,nombre:string,apellido:string,email:string,telefono:?string}>
     */
    public function candidatos(): array
    {
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'apellido' => (string)$r['apellido'], 'email' => (string)$r['email'], 'telefono' => $r['telefono']],
            $this->filas('SELECT u.id,u.nombre,u.apellido,u.email,u.telefono FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes cl ON cl.usuario_id=u.id WHERE '
                . CrmRepository::CLIENTE_SQL . ' ORDER BY u.id LIMIT ' . self::MAX_CANDIDATOS));
    }

    /** Última visita completada (fecha ≤ hoy) por persona. @param int[] $ids @return array<int,string> */
    public function ultimasVisitas(array $ids, string $hoy): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) { return []; }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = $this->filas("SELECT usuarioId,MAX(fecha) ultima FROM citas WHERE estado='completada' AND fecha<=? AND usuarioId IN ({$marcas}) GROUP BY usuarioId", 's' . str_repeat('i', count($ids)), array_merge([$hoy], $ids));
        return array_column($filas, 'ultima', 'usuarioId');
    }

    /**
     * Anexa la decisión del operador. Debe llamarse dentro de la transacción del alta cuando la decisión es «continuó»,
     * para que o existan la cuenta y su decisión o ninguna.
     * @param array{actor_usuario_id:int,actor_rol:?string,decision:string,coincidencias_ids:int[],criterios:string[],token_hash:?string,usuario_creado_id:?int,clave_hash:string,ip_hash:?string} $d
     * @return bool false si la misma decisión ya estaba registrada para esa clave de operación (doble envío)
     */
    public function registrarDecision(array $d): bool
    {
        $ids = array_values(array_unique(array_map('intval', $d['coincidencias_ids']))); sort($ids);
        $lista = array_values(array_unique($d['criterios'])); sort($lista); $criterios = implode(',', $lista);
        try {
            $stmt = $this->db->prepare('INSERT INTO altas_homonimos_decisiones(ocurrido_en,actor_usuario_id,actor_rol,decision,coincidencias_ids,n_coincidencias,criterios,token_hash,usuario_creado_id,clave_hash,ip_hash) VALUES(UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?)');
            if (!$stmt) { throw new PersistenceException('No se pudo preparar el registro de la decisión.'); }
            $actor = (int)$d['actor_usuario_id']; $rol = $d['actor_rol']; $decision = $d['decision'];
            $json = json_encode($ids, JSON_THROW_ON_ERROR); $n = count($ids); $token = $d['token_hash']; $creado = $d['usuario_creado_id']; $clave = $d['clave_hash']; $ip = $d['ip_hash'];
            $stmt->bind_param('isssississ', $actor, $rol, $decision, $json, $n, $criterios, $token, $creado, $clave, $ip);
            try {
                $ok = $stmt->execute();
                $errno = $ok ? 0 : (int)$stmt->errno;
            } catch (\mysqli_sql_exception $e) { $ok = false; $errno = (int)$e->getCode(); }
            $stmt->close();
            if (!$ok && $errno === 1062) { return false; }
            if (!$ok) { throw new PersistenceException('No se pudo registrar la decisión sobre posibles homónimos (errno ' . $errno . ').'); }
            return true;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo al registrar la decisión sobre posibles homónimos.', (int)$e->getCode(), $e);
        }
    }
}
