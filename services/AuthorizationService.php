<?php
namespace Services;

use Model\ActiveRecord;
use mysqli;

/** Roles comprobados en servidor; nunca autoriza con el rol enviado por el navegador. */
class AuthorizationService
{
    public const PERMISOS = ['equipo.gestionar','servicios.gestionar','profesionales.gestionar','perfiles.gestionar','agenda.gestionar','agenda.ver_propia',
        'finanzas.gestionar','inventario.gestionar','marketing.gestionar','ajustes.gestionar','reportes.ver'];
    public const ROLES = [
        'propietario' => self::PERMISOS,
        'gerente' => ['servicios.gestionar','profesionales.gestionar','perfiles.gestionar','agenda.gestionar','finanzas.gestionar','inventario.gestionar','marketing.gestionar','ajustes.gestionar','reportes.ver'],
        'recepcion' => ['perfiles.gestionar','agenda.gestionar'],
        'profesional' => ['agenda.ver_propia'],
        'finanzas' => ['finanzas.gestionar','inventario.gestionar','reportes.ver'],
        'cliente' => [],
    ];
    private ?mysqli $db;
    public function __construct(?mysqli $db = null) { $this->db = $db ?? ActiveRecord::getDB(); }
    public function contexto(int $id): ?array
    {
        if ($id <= 0 || !$this->db) { return null; }
        try {
            $stmt = $this->db->prepare("SELECT u.id,u.admin,u.confirmado,u.version_sesion,COALESCE(a.rol,IF(u.admin=1,'propietario','cliente')) rol,COALESCE(a.activo,1) activo,p.id profesional_id FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN profesionales p ON p.usuarioId=u.id WHERE u.id=?");
            if (!$stmt) { return null; }
            $stmt->bind_param('i',$id);
            if (!$stmt->execute()) { $stmt->close(); return null; }
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            return $row ?: null;
        } catch (\Throwable $e) { error_log('AuthorizationService: no se pudo verificar acceso'); return null; }
    }
    public function permite(int $id, string $permiso): bool
    {
        $c = $this->contexto($id);
        return $c !== null && (int)$c['activo'] === 1 && (int)$c['confirmado'] === 1
            && in_array($permiso,self::ROLES[$c['rol']] ?? [],true);
    }
}
