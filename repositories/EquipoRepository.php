<?php
namespace Repositories;

use Model\ActiveRecord;
use Classes\Transaccion;
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
    /**
     * Cambia rol y acceso de una cuenta. El candado serializa cambios de propietarios aun si afectan usuarios
     * distintos. Mantiene en la misma transacción la ficha del colaborador y revoca sus sesiones.
     *
     * @param string|null $estadoColaborador activo|suspendido|baja; por defecto deriva de $activo
     */
    public function actualizar(int $actor, int $id, string $rol, bool $activo, ?int $profesionalId = null, ?string $estadoColaborador = null): void
    {
        if (!isset(AuthorizationService::ROLES[$rol])) { throw new \InvalidArgumentException('Rol no válido'); }
        Transaccion::ejecutar($this->db, function () use ($actor, $id, $rol, $activo, $profesionalId, $estadoColaborador): void {
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
            // La ficha solo se desvincula si se vincula otra explícitamente o si la persona deja de ser profesional:
            // guardar el mismo acceso, suspender y reactivar NO debe romper su agenda ni «Mis clientes».
            if ($profesionalId!==null) {
                $s=$this->sql('UPDATE profesionales SET usuarioId=NULL WHERE usuarioId=?','i',[$id]);$s->close();
                $s=$this->sql('UPDATE profesionales SET usuarioId=? WHERE id=?','ii',[$id,$profesionalId]);$s->close();
            } elseif ($rol!=='profesional') {
                $s=$this->sql('UPDATE profesionales SET usuarioId=NULL WHERE usuarioId=?','i',[$id]);$s->close();
            }
            $this->sincronizarColaborador($id,$rol,$activo,$estadoColaborador);
            $s=$this->sql("INSERT INTO equipo_auditoria(actor_id,usuario_id,accion,detalle) VALUES(?,?,'acceso_actualizado',?)",'iis',[$actor,$id,$rol . ($activo ? ':activo' : ':inactivo')]);$s->close();
        });
    }

    /** La ficha laboral refleja el acceso: se crea si faltaba (cuentas heredadas) y nunca duplica la persona. */
    private function sincronizarColaborador(int $usuarioId, string $rol, bool $activo, ?string $estadoColaborador): void
    {
        $estado = $estadoColaborador ?? ($rol === 'cliente' ? 'baja' : ($activo ? 'activo' : 'suspendido'));
        if (!in_array($estado, ['activo', 'suspendido', 'baja'], true)) { throw new \InvalidArgumentException('Estado laboral no válido'); }
        $rolFicha = $rol === 'cliente' ? 'recepcion' : $rol;
        $s=$this->sql("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,telefono,rol,estado,aceptado_en,baja_en)
            SELECT u.id,LOWER(TRIM(u.email)),u.nombre,u.apellido,u.telefono,?,?,UTC_TIMESTAMP(),IF(?='baja',UTC_TIMESTAMP(),NULL) FROM usuarios u WHERE u.id=?
            ON DUPLICATE KEY UPDATE rol=IF(?='cliente',rol,VALUES(rol)), estado=VALUES(estado), baja_en=IF(VALUES(estado)='baja',COALESCE(baja_en,UTC_TIMESTAMP()),NULL)",
            'sssis',[$rolFicha,$estado,$estado,$usuarioId,$rol]);$s->close();
        $s=$this->sql("UPDATE colaboradores SET codigo=CONCAT('COL-',LPAD(id,6,'0')) WHERE usuario_id=? AND codigo IS NULL",'i',[$usuarioId]);$s->close();
    }
}
