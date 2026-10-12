<?php
/** Uso: APPSALON_OWNER_PASSWORD='contraseña segura' php scripts/crear_propietario.php --email=... --nombre=... --apellido=... */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../includes/app.php';
use Model\ActiveRecord;
use Model\Usuario;
use Repositories\UsuarioRepository;
use Services\AuthorizationService;
$options=getopt('',['email:','nombre:','apellido:']);
$email=$options['email']??'';$password=getenv('APPSALON_OWNER_PASSWORD')?:'';
if (!is_string($email) || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>60 || strlen($password)<12 || strlen($password)>72) {
    fwrite(STDERR,"Indica --email válido y APPSALON_OWNER_PASSWORD de 12 a 72 bytes. No existe contraseña predeterminada.\n");exit(1);
}
$db=ActiveRecord::getDB();
try {
    $db->begin_transaction();
    $mutex=$db->query('SELECT id FROM equipo_mutex WHERE id=1 FOR UPDATE');
    if (!$mutex) { throw new RuntimeException('Aplica primero las migraciones'); }
    $repo=new UsuarioRepository($db);$user=$repo->findByEmail($email);
    if ($user) {
        // CLI no sustituye contraseñas existentes ni confirma una cuenta ajena sin comprobar su secreto.
        if (!password_verify($password,(string)$user->password)) { throw new RuntimeException('La contraseña no coincide con la cuenta existente'); }
        $contexto=(new AuthorizationService($db))->contexto((int)$user->id);
        if ($contexto && $contexto['rol']==='propietario' && (int)$contexto['activo']===1) {
            $db->commit();echo "El propietario ya existe. No se modificó su cuenta.\n";exit(0);
        }
    } else {
        $nombre=$options['nombre']??'Propietario';$apellido=$options['apellido']??'Salón';
        if (!is_string($nombre) || !is_string($apellido) || trim($nombre)==='' || trim($apellido)==='' || strlen($nombre)>60 || strlen($apellido)>60) { throw new RuntimeException('Nombre/apellido no válidos'); }
        $user=new Usuario(['nombre'=>$nombre,'apellido'=>$apellido,'email'=>$email,'password'=>$password,'admin'=>'1','confirmado'=>'1']);
        $user->hashPassword();$repo->create($user);
    }
    $id=(int)$user->id;
    $s=$db->prepare("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,'propietario',1) ON DUPLICATE KEY UPDATE rol='propietario',activo=1");
    if (!$s) { throw new RuntimeException('Error de acceso'); }$s->bind_param('i',$id);if (!$s->execute()) { throw new RuntimeException('Error de acceso'); }$s->close();
    $s=$db->prepare('UPDATE usuarios SET admin=1,confirmado=1,version_sesion=version_sesion+1 WHERE id=?');$s->bind_param('i',$id);if (!$s->execute()) { throw new RuntimeException('Error de cuenta'); }$s->close();
    $s=$db->prepare("INSERT INTO equipo_auditoria(actor_id,usuario_id,accion,detalle) VALUES(NULL,?,'bootstrap_propietario','Provisionado por CLI')");$s->bind_param('i',$id);if (!$s->execute()) { throw new RuntimeException('Error de auditoría'); }$s->close();
    $db->commit();echo "Propietario configurado. Inicia sesión con el email y la contraseña que suministraste.\n";
} catch (Throwable $e) { if($db) $db->rollback();fwrite(STDERR,"No se pudo configurar: ".$e->getMessage()."\n");exit(1); }
