<?php
namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/**
 * Auditoría de exportaciones del CRM (tabla crm_exportaciones_auditoria, migración 022).
 *
 * SOLO ANEXA Y CONSULTA: esta clase no tiene (ni debe tener) UPDATE ni DELETE, y la base de datos lo refuerza con triggers
 * que rechazan ambos (SQLSTATE 45000). La fila guarda metadatos (quién, cuándo, filtros normalizados, cuántas filas y
 * columnas, qué columnas, versión de consentimiento, huella de IP y del archivo), jamás el contenido exportado.
 */
final class CrmAuditoriaRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión a la base de datos.'); }
        $this->db = $conexion;
    }

    /**
     * @param array<string,mixed> $fila ocurrido_en se fija aquí (UTC del servidor MySQL).
     * @return int id de la fila de auditoría
     * @throws PersistenceException si no se pudo escribir (el llamador NO debe entregar el archivo)
     */
    public function registrar(array $fila): int
    {
        $sql = 'INSERT INTO crm_exportaciones_auditoria(ocurrido_en,actor_usuario_id,actor_rol,formato,tipo,resultado,motivo,filtros_json,filtros_hash,filas,columnas_n,columnas_lista,truncado,'
            . 'consentimiento_version_id,consentimiento_politica,contenido_sha256,ip_hash) VALUES(UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar el registro de auditoría.'); }
            $actor = $fila['actor_usuario_id'] ?? null; $rol = $fila['actor_rol'] ?? null;
            $formato = (string)($fila['formato'] ?? 'csv'); $tipo = (string)$fila['tipo']; $resultado = (string)$fila['resultado']; $motivo = $fila['motivo'] ?? null;
            $filtrosJson = $fila['filtros_json'] ?? null; $filtrosHash = $fila['filtros_hash'] ?? null;
            $filas = $fila['filas'] ?? null; $colN = $fila['columnas_n'] ?? null; $colLista = $fila['columnas_lista'] ?? null;
            $truncado = (int)($fila['truncado'] ?? 0);
            $versionId = $fila['consentimiento_version_id'] ?? null; $politica = $fila['consentimiento_politica'] ?? null;
            $sha = $fila['contenido_sha256'] ?? null; $ip = $fila['ip_hash'] ?? null;
            $stmt->bind_param('isssssssiisiisss', $actor, $rol, $formato, $tipo, $resultado, $motivo, $filtrosJson, $filtrosHash, $filas, $colN, $colLista, $truncado, $versionId, $politica, $sha, $ip);
            if (!$stmt->execute()) { $errno = (int)$stmt->errno; $stmt->close(); throw new PersistenceException('No se pudo registrar la auditoría de la exportación (errno ' . $errno . ').'); }
            $id = (int)$this->db->insert_id;
            $stmt->close();
            if ($id <= 0) { throw new PersistenceException('La auditoría de la exportación no devolvió identificador.'); }
            return $id;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo al registrar la auditoría de la exportación.', (int)$e->getCode(), $e);
        }
    }

    /**
     * ¿Ya hay una denegación igual (mismo actor, tipo y motivo) en los últimos `$minutos`? Sirve para agrupar los
     * intentos repetidos y que una cuenta con pocos privilegios no pueda llenar de filas la auditoría de solo anexado (QA R2B-06).
     */
    public function hayDenegadaReciente(?int $actorId, string $tipo, ?string $motivo, int $minutos): bool
    {
        $minutos = max(1, $minutos);
        $stmt = $this->db->prepare("SELECT 1 FROM crm_exportaciones_auditoria WHERE resultado='denegada' AND tipo=? AND (actor_usuario_id <=> ?) AND (motivo <=> ?)
            AND ocurrido_en >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$minutos} MINUTE) LIMIT 1");
        if (!$stmt) { return false; }
        $stmt->bind_param('sis', $tipo, $actorId, $motivo);
        $existe = $stmt->execute() && $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $existe;
    }

    /** @return array{0:string,1:string,2:array} WHERE, tipos y parámetros */
    private function donde(array $f): array
    {
        $sql = ' WHERE 1=1'; $t = ''; $p = [];
        if (!empty($f['resultado'])) { $sql .= ' AND a.resultado=?'; $t .= 's'; $p[] = (string)$f['resultado']; }
        if (!empty($f['tipo'])) { $sql .= ' AND a.tipo=?'; $t .= 's'; $p[] = (string)$f['tipo']; }
        if (!empty($f['actor_id'])) { $sql .= ' AND a.actor_usuario_id=?'; $t .= 'i'; $p[] = (int)$f['actor_id']; }
        if (!empty($f['desde_utc'])) { $sql .= ' AND a.ocurrido_en>=?'; $t .= 's'; $p[] = (string)$f['desde_utc']; }
        if (!empty($f['hasta_utc'])) { $sql .= ' AND a.ocurrido_en<?'; $t .= 's'; $p[] = (string)$f['hasta_utc']; }
        return [$sql, $t, $p];
    }

    private function consultar(string $sql, string $t, array $p): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar la consulta de auditoría.'); }
            if ($t !== '') { $stmt->bind_param($t, ...$p); }
            if (!$stmt->execute()) { $errno = (int)$stmt->errno; $stmt->close(); throw new PersistenceException('No se pudo consultar la auditoría (errno ' . $errno . ').'); }
            $res = $stmt->get_result();
            $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $filas;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo al consultar la auditoría de exportaciones.', (int)$e->getCode(), $e);
        }
    }

    public function contar(array $f): int
    {
        [$w, $t, $p] = $this->donde($f);
        return (int)($this->consultar('SELECT COUNT(*) n FROM crm_exportaciones_auditoria a' . $w, $t, $p)[0]['n'] ?? 0);
    }

    /** Más recientes primero. El nombre del actor viene de `usuarios` (puede faltar si la cuenta ya no existe). @return array<int,array<string,mixed>> */
    public function listar(array $f, int $limite, int $offset): array
    {
        $limite = max(1, min(100, $limite)); $offset = max(0, $offset);
        [$w, $t, $p] = $this->donde($f);
        return $this->consultar('SELECT a.id,a.ocurrido_en,a.actor_usuario_id,a.actor_rol,u.nombre actor_nombre,u.apellido actor_apellido,a.formato,a.tipo,a.resultado,a.motivo,a.filtros_json,a.filtros_hash,'
            . 'a.filas,a.columnas_n,a.columnas_lista,a.truncado,a.consentimiento_version_id,cv.version consentimiento_version,a.consentimiento_politica,a.contenido_sha256 '
            . 'FROM crm_exportaciones_auditoria a LEFT JOIN usuarios u ON u.id=a.actor_usuario_id LEFT JOIN consentimiento_versiones cv ON cv.id=a.consentimiento_version_id'
            . $w . ' ORDER BY a.id DESC LIMIT ? OFFSET ?', $t . 'ii', array_merge($p, [$limite, $offset]));
    }
}
