<?php
namespace Repositories;

use Model\ActiveRecord;
use Model\Usuario;
use Services\AuthorizationService;
use mysqli;

class EquipoRepository
{
    private mysqli $db;
    public function __construct(?mysqli $db = null) {
        $connection = $db ?? ActiveRecord::getDB();
        if (!$connection) { throw new PersistenceException('Sin conexión'); }
        $this->db = $connection;
    }
    private function sql(string $sql, string $types = '', array $args = []): \mysqli_stmt
    {
        $s = $this->db->prepare($sql);
        if (!$s) { throw new PersistenceException('No fue posible preparar operación de equipo'); }
        if ($types !== '') { $s->bind_param($types,...$args); }
        if (!$s->execute()) { $s->close(); throw new PersistenceException('No fue posible guardar operación de equipo'); }
        return $s;
    }
    public function listar(): array
    {
        $s=$this->sql("SELECT u.id,u.nombre,u.apellido,u.email,u.confirmado,COALESCE(a.rol,IF(u.admin=1,'propietario','cliente')) rol,COALESCE(a.activo,1) activo,p.id profesional_id,p.nombre profesional FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN profesionales p ON p.usuarioId=u.id WHERE COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))<>'cliente' ORDER BY u.nombre,u.id LIMIT 300");
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
    public function profesionales(): array {
        $s=$this->sql('SELECT id,nombre,usuarioId FROM profesionales ORDER BY nombre LIMIT 300');
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
    /** El candado serializa cambios de propietarios aun si afectan usuarios distintos. */
    public function actualizar(int $actor, int $id, string $rol, bool $activo, ?int $profesionalId = null): void
    {
        if (!isset(AuthorizationService::ROLES[$rol])) { throw new \InvalidArgumentException('Rol no válido'); }
        $this->db->begin_transaction();
        try {
            $lock=$this->sql('SELECT id FROM equipo_mutex WHERE id=1 FOR UPDATE');$lock->get_result()->fetch_assoc();$lock->close();
            $c=(new AuthorizationService($this->db))->contexto($actor);
            if (!$c || $c['rol'] !== 'propietario' || (int)$c['activo'] !== 1) { throw new \DomainException('Solo el propietario puede modificar el equipo'); }
            $target=(new AuthorizationService($this->db))->contexto($id);
            if (!$target) { throw new \DomainException('Usuario no encontrado'); }
            if ($target['rol']==='propietario' && (int)$target['activo']===1 && ($rol!=='propietario' || !$activo)) {
                $count=$this->sql("SELECT COUNT(*) total FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id WHERE COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='propietario' AND COALESCE(a.activo,1)=1 AND u.confirmado=1");
                $total=(int)$count->get_result()->fetch_assoc()['total'];$count->close();
                if ($total<=1) { throw new \DomainException('No puedes desactivar o cambiar el rol del último propietario'); }
            }
            if ($profesionalId!==null) {
                if ($rol!=='profesional') { throw new \DomainException('Solo el rol profesional puede vincularse a una ficha'); }
                $s=$this->sql('SELECT id,usuarioId FROM profesionales WHERE id=? FOR UPDATE','i',[$profesionalId]);
                $p=$s->get_result()->fetch_assoc();$s->close();
                if (!$p || ($p['usuarioId']!==null && (int)$p['usuarioId']!==$id)) { throw new \DomainException('Ficha profesional inexistente o vinculada a otra cuenta'); }
            }
            $s=$this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,?) ON DUPLICATE KEY UPDATE rol=VALUES(rol),activo=VALUES(activo)','isi',[$id,$rol,(int)$activo]);$s->close();
            // La versión también invalida sesiones antiguas después de cambio de rol.
            $admin=$rol==='propietario' || $rol==='gerente' ? 1 : 0;
            $s=$this->sql('UPDATE usuarios SET admin=?,version_sesion=version_sesion+1 WHERE id=?','ii',[$admin,$id]);$s->close();
            $s=$this->sql('UPDATE profesionales SET usuarioId=NULL WHERE usuarioId=?','i',[$id]);$s->close();
            if ($profesionalId!==null) { $s=$this->sql('UPDATE profesionales SET usuarioId=? WHERE id=?','ii',[$id,$profesionalId]);$s->close(); }
            $s=$this->sql("INSERT INTO equipo_auditoria(actor_id,usuario_id,accion,detalle) VALUES(?,?,'acceso_actualizado',?)",'iis',[$actor,$id,$rol . ($activo ? ':activo' : ':inactivo')]);$s->close();
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
    /** Nueva cuenta con contraseña desconocida aleatoria; solo puede entrar al completar enlace de correo. */
    public function crear(int $actor, array $datos): array
    {
        $rol=is_string($datos['rol']??null)?$datos['rol']:'';
        if (!isset(AuthorizationService::ROLES[$rol]) || $rol==='cliente') { throw new \InvalidArgumentException('Selecciona un rol del equipo'); }
        $usuario=new Usuario($datos);
        $usuario->password=bin2hex(random_bytes(24));
        if ($usuario->validarNuevaCuenta()) { throw new \InvalidArgumentException('Revisa nombre, apellido, email y teléfono (7 a 10 dígitos)'); }
        $this->db->begin_transaction();
        try {
            $s=$this->sql('SELECT id FROM equipo_mutex WHERE id=1 FOR UPDATE');$s->get_result()->fetch_assoc();$s->close();
            $c=(new AuthorizationService($this->db))->contexto($actor);
            if (!$c || $c['rol']!=='propietario' || (int)$c['activo']!==1) { throw new \DomainException('Solo el propietario puede crear accesos'); }
            $usuario->admin='0';$usuario->confirmado='1';$usuario->hashPassword();
            $token=$usuario->generarTokenSeguro('recuperacion',24);
            $id=(new UsuarioRepository($this->db))->create($usuario);
            $s=$this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)','is',[$id,$rol]);$s->close();
            $s=$this->sql("INSERT INTO equipo_auditoria(actor_id,usuario_id,accion,detalle) VALUES(?,?,'cuenta_creada',?)",'iis',[$actor,$id,$rol]);$s->close();
            $this->db->commit();return ['id'=>$id,'email'=>$usuario->email,'nombre'=>$usuario->nombre,'token'=>$token];
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function reenviarInvitacion(int $actor,int $id): array
    {
        $c=(new AuthorizationService($this->db))->contexto($actor);
        $target=(new AuthorizationService($this->db))->contexto($id);
        if (!$c || $c['rol']!=='propietario' || (int)$c['activo']!==1 || !$target || $target['rol']==='cliente' || (int)$target['activo']!==1) { throw new \DomainException('No se puede invitar esta cuenta'); }
        $repo=new UsuarioRepository($this->db);$user=$repo->findById($id);
        $token=$repo->issueRecoveryToken($user,24);
        if (!$token) { throw new PersistenceException('No se pudo emitir enlace'); }
        return ['id'=>$id,'email'=>$user->email,'nombre'=>$user->nombre,'token'=>$token];
    }
}
