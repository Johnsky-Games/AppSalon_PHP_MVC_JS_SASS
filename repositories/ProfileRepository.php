<?php
namespace Repositories;

use mysqli;

final class ProfileRepository
{
    private ?ConsentimientoRepository $consentimientos = null;

    public function __construct(private mysqli $db, ?ConsentimientoRepository $consentimientos = null) { $this->consentimientos = $consentimientos; }

    private function statement(string $sql, string $types = '', array $values = []): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) { throw new PersistenceException('No fue posible preparar el perfil.'); }
        if ($types !== '') { $stmt->bind_param($types, ...$values); }
        if (!$stmt->execute()) { $stmt->close(); throw new PersistenceException('No fue posible guardar el perfil.'); }
        return $stmt;
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->statement('SELECT u.id, u.nombre, u.apellido, u.telefono, u.email, COALESCE(p.contacto_email,1) contacto_email, COALESCE(p.marketing_email,0) marketing_email, p.consentimiento_marketing_at, p.consentimiento_marketing_version_id consentimiento_version_id, COALESCE(p.recordatorios,1) recordatorios, COALESCE(p.foto_publica,0) foto_publica, f.archivo, EXISTS(SELECT 1 FROM profesionales pr WHERE pr.usuarioId=u.id AND pr.activo=1) es_profesional FROM usuarios u LEFT JOIN perfiles_usuario p ON p.usuarioId=u.id LEFT JOIN perfil_fotos f ON f.usuarioId=u.id WHERE u.id=?', 'i', [$id]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function fotosProfesionalesPublicas(): array
    {
        $stmt = $this->statement('SELECT pr.id,pr.usuarioId,f.archivo FROM profesionales pr JOIN perfiles_usuario p ON p.usuarioId=pr.usuarioId JOIN perfil_fotos f ON f.usuarioId=pr.usuarioId WHERE pr.activo=1 AND p.foto_publica=1');
        $map = []; $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (preg_match('/^[a-f0-9]{64}\.jpg$/D', $row['archivo'])) { $map[(int)$row['id']] = '/perfil/foto?id=' . (int)$row['usuarioId']; }
        }
        $stmt->close(); return $map;
    }

    /**
     * Guarda datos del perfil y preferencias. El consentimiento comercial pasa por ConsentimientoRepository::aplicarDecision
     * (fuente única + versión del texto + historial append-only) en la MISMA transacción: o se guarda todo o nada.
     * Con `$datos['marketing_email']` el valor deseado; `consentimiento_version` es la versión que la persona vio y
     * `reconfirma_consentimiento` indica que aceptó expresamente un texto más nuevo que el que ya había aceptado.
     *
     * @param array{origen?:string,canal?:string,actor_id?:?int,ip_hash?:?string} $meta contexto de auditoría de la decisión
     */
    public function guardar(int $id, array $datos, array $meta = []): void
    {
        \Classes\Transaccion::ejecutar($this->db, function () use ($id, $datos, $meta): void {
            $stmt = $this->statement('SELECT id FROM usuarios WHERE id=? FOR UPDATE', 'i', [$id]);
            $exists = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$exists) { throw new PersistenceException('El perfil ya no existe.'); }
            $stmt = $this->statement('UPDATE usuarios SET nombre=?, apellido=?, telefono=? WHERE id=?', 'sssi', [$datos['nombre'], $datos['apellido'], $datos['telefono'], $id]); $stmt->close();
            // Las columnas de consentimiento NO se escriben aquí: las gobierna aplicarDecision.
            $stmt = $this->statement('INSERT INTO perfiles_usuario (usuarioId,contacto_email,recordatorios,foto_publica) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE contacto_email=VALUES(contacto_email), recordatorios=VALUES(recordatorios), foto_publica=VALUES(foto_publica)', 'iiii', [$id,$datos['contacto_email'],$datos['recordatorios'],$datos['foto_publica']]); $stmt->close();
            if (empty($datos['omitir_consentimiento'])) {
                $this->consentimientos()->aplicarDecision($id, (int)$datos['marketing_email'] === 1, $datos['consentimiento_version'] ?? null, [
                    'origen' => $meta['origen'] ?? 'perfil', 'canal' => $meta['canal'] ?? 'portal_cliente',
                    'actor_id' => $meta['actor_id'] ?? $id, 'ip_hash' => $meta['ip_hash'] ?? null,
                    'reconfirma' => !empty($datos['reconfirma_consentimiento']),
                ]);
            }
        });
    }

    public function consentimientos(): ConsentimientoRepository { return $this->consentimientos ??= new ConsentimientoRepository($this->db); }

    public function guardarFoto(int $id, string $archivo): ?string
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->statement('SELECT id FROM usuarios WHERE id=? FOR UPDATE', 'i', [$id]);
            if (!$stmt->get_result()->fetch_assoc()) { $stmt->close(); throw new PersistenceException('Perfil inexistente.'); } $stmt->close();
            $stmt = $this->statement('SELECT archivo FROM perfil_fotos WHERE usuarioId=?', 'i', [$id]); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
            $stmt = $this->statement('INSERT INTO perfil_fotos(usuarioId,archivo) VALUES (?,?) ON DUPLICATE KEY UPDATE archivo=VALUES(archivo)', 'is', [$id,$archivo]); $stmt->close();
            $this->db->commit(); return $old['archivo'] ?? null;
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function eliminarFoto(int $id): ?string
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->statement('SELECT id FROM usuarios WHERE id=? FOR UPDATE', 'i', [$id]); $stmt->close();
            $stmt = $this->statement('SELECT archivo FROM perfil_fotos WHERE usuarioId=?', 'i', [$id]); $old = $stmt->get_result()->fetch_assoc(); $stmt->close();
            $stmt = $this->statement('DELETE FROM perfil_fotos WHERE usuarioId=?', 'i', [$id]); $stmt->close();
            $this->db->commit(); return $old['archivo'] ?? null;
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
