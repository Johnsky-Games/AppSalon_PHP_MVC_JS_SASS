<?php
namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/**
 * Lectura de candidatos y auditoría (solo anexado) del aviso de posibles homónimos al dar de alta un profesional.
 * Equivalente de AltaClienteRepository para fichas de agenda: la comparación de nombres vive en Services\HomonimosService.
 */
final class ProfesionalHomonimosRepository
{
    private const MAX_CANDIDATOS = 20000;
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión a la base de datos.'); }
        $this->db = $conexion;
    }

    /**
     * Todas las fichas (activas o no: un homónimo inactivo sigue siendo un posible duplicado).
     * @return array<int,array{id:int,nombre:string,activo:bool,vinculada:bool}>
     */
    public function candidatos(): array
    {
        try {
            $res = $this->db->query('SELECT id, nombre, activo, (usuarioId IS NOT NULL OR colaborador_id IS NOT NULL) vinculada FROM profesionales ORDER BY id LIMIT ' . self::MAX_CANDIDATOS);
            if (!$res) { throw new PersistenceException('No se pudo consultar posibles homónimos de profesionales.'); }
            return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'activo' => (int)$r['activo'] === 1, 'vinculada' => (int)$r['vinculada'] === 1], $res->fetch_all(MYSQLI_ASSOC));
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo en la capa de persistencia de homónimos de profesionales.', (int)$e->getCode(), $e);
        }
    }

    /**
     * Anexa la decisión. Si es «continuó» debe llamarse dentro de la transacción del alta (o existen ficha y decisión, o ninguna).
     * @param array{actor_usuario_id:int,actor_rol:?string,ambito:string,decision:string,coincidencias_ids:int[],criterios:string[],token_hash:?string,profesional_creado_id:?int,clave_hash:string,ip_hash:?string} $d
     * @return bool false si esa misma decisión ya estaba registrada para la clave de operación (doble envío)
     */
    public function registrarDecision(array $d): bool
    {
        $ids = array_values(array_unique(array_map('intval', $d['coincidencias_ids']))); sort($ids);
        $lista = array_values(array_unique($d['criterios'])); sort($lista); $criterios = implode(',', $lista);
        try {
            $stmt = $this->db->prepare('INSERT INTO profesionales_homonimos_decisiones(ocurrido_en,actor_usuario_id,actor_rol,ambito,decision,coincidencias_ids,n_coincidencias,criterios,token_hash,profesional_creado_id,clave_hash,ip_hash) VALUES(UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?,?)');
            if (!$stmt) { throw new PersistenceException('No se pudo preparar el registro de la decisión.'); }
            $actor = (int)$d['actor_usuario_id']; $rol = $d['actor_rol']; $ambito = $d['ambito']; $decision = $d['decision'];
            $json = json_encode($ids, JSON_THROW_ON_ERROR); $n = count($ids); $token = $d['token_hash']; $creado = $d['profesional_creado_id']; $clave = $d['clave_hash']; $ip = $d['ip_hash'];
            $stmt->bind_param('issssississ', $actor, $rol, $ambito, $decision, $json, $n, $criterios, $token, $creado, $clave, $ip);
            try {
                $ok = $stmt->execute();
                $errno = $ok ? 0 : (int)$stmt->errno;
            } catch (\mysqli_sql_exception $e) { $ok = false; $errno = (int)$e->getCode(); }
            $stmt->close();
            if (!$ok && $errno === 1062) { return false; }
            if (!$ok) { throw new PersistenceException('No se pudo registrar la decisión sobre posibles homónimos (errno ' . $errno . ').', $errno); }
            return true;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo al registrar la decisión sobre posibles homónimos.', (int)$e->getCode(), $e);
        }
    }
}
