<?php
namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/**
 * Auditoría general de exportaciones (tabla exportaciones_auditoria_general, migración 026).
 *
 * SOLO ANEXA Y CONSULTA: esta clase no tiene (ni debe tener) UPDATE ni DELETE y la base lo refuerza con triggers
 * (SQLSTATE 45000). Guarda metadatos (quién, cuándo, filtros normalizados sin datos personales, filas, columnas, huella
 * del archivo), jamás el contenido exportado.
 */
final class ExportacionAuditoriaRepository
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
     * @throws PersistenceException si no se pudo escribir (el llamador NO debe entregar el archivo)
     */
    public function registrar(array $fila): int
    {
        $sql = 'INSERT INTO exportaciones_auditoria_general(ocurrido_en,origen,actor_usuario_id,actor_rol,formato,resultado,motivo,filtros_json,filtros_hash,filas,columnas_n,columnas_lista,truncado,bytes_entregados,contenido_sha256,ip_hash)'
            . ' VALUES(UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar el registro de auditoría de exportaciones.'); }
            $origen = (string)$fila['origen']; $actor = $fila['actor_usuario_id'] ?? null; $rol = $fila['actor_rol'] ?? null;
            $formato = (string)($fila['formato'] ?? 'csv'); $resultado = (string)$fila['resultado']; $motivo = $fila['motivo'] ?? null;
            $filtrosJson = $fila['filtros_json'] ?? null; $filtrosHash = $fila['filtros_hash'] ?? null;
            $filas = $fila['filas'] ?? null; $colN = $fila['columnas_n'] ?? null; $colLista = $fila['columnas_lista'] ?? null;
            $truncado = (int)($fila['truncado'] ?? 0); $bytes = $fila['bytes_entregados'] ?? null;
            $sha = $fila['contenido_sha256'] ?? null; $ip = $fila['ip_hash'] ?? null;
            $stmt->bind_param('sissssssiisiiss', $origen, $actor, $rol, $formato, $resultado, $motivo, $filtrosJson, $filtrosHash, $filas, $colN, $colLista, $truncado, $bytes, $sha, $ip);
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

    /** ¿Ya hay una denegación igual (mismo actor, origen y motivo) en los últimos `$minutos`? Agrupa los intentos repetidos. */
    public function hayDenegadaReciente(?int $actorId, string $origen, ?string $motivo, int $minutos): bool
    {
        $minutos = max(1, $minutos);
        $stmt = $this->db->prepare("SELECT 1 FROM exportaciones_auditoria_general WHERE resultado='denegada' AND origen=? AND (actor_usuario_id <=> ?) AND (motivo <=> ?)
            AND ocurrido_en >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$minutos} MINUTE) LIMIT 1");
        if (!$stmt) { return false; }
        $stmt->bind_param('sis', $origen, $actorId, $motivo);
        $existe = $stmt->execute() && $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $existe;
    }

    /** Candado de aplicación (GET_LOCK) para que «comprobar» e «insertar» sean una operación frente a ráfagas concurrentes. */
    public function tomarCandado(string $nombre, int $segundos = 3): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, ?) AS ok');
        if (!$stmt) { return false; }
        $stmt->bind_param('si', $nombre, $segundos);
        $ok = $stmt->execute() && (int)($stmt->get_result()->fetch_assoc()['ok'] ?? 0) === 1;
        $stmt->close();
        return $ok;
    }

    public function soltarCandado(string $nombre): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        if ($stmt) { $stmt->bind_param('s', $nombre); $stmt->execute(); $stmt->get_result(); $stmt->close(); }
    }

    /** Más recientes primero (solo lectura). @return array<int,array<string,mixed>> */
    public function listar(?string $origen = null, int $limite = 50, int $offset = 0): array
    {
        $limite = max(1, min(200, $limite)); $offset = max(0, $offset);
        $sql = 'SELECT id,ocurrido_en,origen,actor_usuario_id,actor_rol,formato,resultado,motivo,filtros_json,filas,columnas_n,truncado,bytes_entregados,contenido_sha256 FROM exportaciones_auditoria_general';
        $t = ''; $p = [];
        if ($origen !== null) { $sql .= ' WHERE origen=?'; $t .= 's'; $p[] = $origen; }
        $sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?'; $t .= 'ii'; array_push($p, $limite, $offset);
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar la consulta de auditoría de exportaciones.'); }
            $stmt->bind_param($t, ...$p);
            if (!$stmt->execute()) { $stmt->close(); throw new PersistenceException('No se pudo consultar la auditoría de exportaciones.'); }
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
}
