<?php
namespace Tests\Integration;
use Model\ActiveRecord;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\EquipoRepository;
use Services\AuthorizationService;
class EquipoIntegrationTest extends TestCase
{
    private mysqli $db;
    private array $ids=[];
    protected function setUp(): void {
        $this->db=new mysqli(getenv('DB_HOST')?:'appsalon-test-db',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'root',getenv('DB_NAME')?:'appsalon_test',(int)(getenv('DB_PORT')?:3306));
        $this->db->set_charset('utf8mb4');ActiveRecord::setDB($this->db);validar_base_datos_prueba($this->db);
        iniciar_sesion_segura();$_SESSION=[];
    }
    protected function tearDown(): void {
        foreach($this->ids as $id) { $s=$this->db->prepare('DELETE FROM usuarios WHERE id=?');$s->bind_param('i',$id);$s->execute();$s->close(); }
        $_SESSION=[];
    }
    private function cuenta(string $rol): int {
        $email=bin2hex(random_bytes(8)).'@equipo.test';$hash=password_hash('EquipoSeguro123',PASSWORD_BCRYPT);
        $s=$this->db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES('Equipo','Prueba',?,?,'0999999999',0,1)");$s->bind_param('ss',$email,$hash);$s->execute();$id=$this->db->insert_id;$s->close();$this->ids[]=$id;
        $s=$this->db->prepare('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)');$s->bind_param('is',$id,$rol);$s->execute();$s->close();return $id;
    }
    public function testPermisosNoAceptanAdminDeSesionYRevocanInmediatamente(): void {
        $id=$this->cuenta('recepcion');$owner=$this->cuenta('propietario');
        $_SESSION=['login'=>true,'id'=>$id,'admin'=>'1','version_sesion'=>1];
        $this->assertFalse(puede('equipo.gestionar'));$this->assertTrue(puede('agenda.gestionar'),json_encode(['session'=>$_SESSION,'ctx'=>(new AuthorizationService($this->db))->contexto($id)]));
        (new EquipoRepository($this->db))->actualizar($owner,$id,'recepcion',false);
        $this->assertFalse(puede('agenda.gestionar'));
        $this->assertFalse((new AuthorizationService($this->db))->permite($id,'agenda.gestionar'));
    }
    public function testClienteNoPuedeModificarEquipoYVersionAumentaAlCambiarRol(): void {
        $client=$this->cuenta('cliente');$owner=$this->cuenta('propietario');$staff=$this->cuenta('recepcion');$repo=new EquipoRepository($this->db);
        try { $repo->actualizar($client,$staff,'propietario',true);$this->fail('Cliente elevó privilegios'); } catch(\DomainException $e) { $this->assertStringContainsString('propietario',$e->getMessage()); }
        $repo->actualizar($owner,$staff,'finanzas',true);
        $ctx=(new AuthorizationService($this->db))->contexto($staff);
        $this->assertSame('finanzas',$ctx['rol']);$this->assertSame(2,(int)$ctx['version_sesion']);
        $this->assertFalse((new AuthorizationService($this->db))->permite($staff,'agenda.gestionar'));
        $this->assertTrue((new AuthorizationService($this->db))->permite($staff,'finanzas.gestionar'));
    }
    public function testUltimoPropietarioNoPuedeDesactivarseYTransaccionConservaAcceso(): void {
        // Aísla los demás propietarios sólo dentro de transacción y restaura antes de finalizar.
        $owner=$this->cuenta('propietario');
        $other=$this->db->query("SELECT u.id,u.admin,a.rol,a.activo FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id WHERE u.id<>$owner AND (a.rol='propietario' OR (a.usuario_id IS NULL AND u.admin=1))")->fetch_all(MYSQLI_ASSOC);
        foreach($other as $r) { $this->db->query('UPDATE usuarios SET admin=0 WHERE id='.(int)$r['id']);$this->db->query('UPDATE usuario_accesos SET activo=0 WHERE usuario_id='.(int)$r['id']); }
        try {
            try { (new EquipoRepository($this->db))->actualizar($owner,$owner,'cliente',false);$this->fail('Se eliminó último propietario'); } catch(\DomainException $e) { $this->assertStringContainsString('último propietario',$e->getMessage()); }
            $this->assertTrue((new AuthorizationService($this->db))->permite($owner,'equipo.gestionar'));
        } finally {
            foreach($other as $r) { $this->db->query('UPDATE usuarios SET admin='.(int)$r['admin'].' WHERE id='.(int)$r['id']);$this->db->query('UPDATE usuario_accesos SET activo='.(int)($r['activo']??1).' WHERE usuario_id='.(int)$r['id']); }
        }
    }
    public function testRegistroLimitaPresupuestoPorEmailSinCrearCuentasAdicionales(): void {
        $email=bin2hex(random_bytes(8)).'@registro.test';
        $service=new \Services\AuthService(new \Repositories\UsuarioRepository($this->db),fn()=>true);
        $datos=['nombre'=>'María','apellido'=>'Paz','telefono'=>'0999999999','email'=>$email,'password'=>'RegistroSeguro123'];
        $ip='203.0.113.'.random_int(1,254);
        $first=$service->registrar($datos,$ip);
        $this->assertSame(\Services\AuthService::STATUS_OK,$first['status']);$this->ids[]=(int)$first['id'];
        // Contrato deliberadamente cambiado (DEF-13): un correo ya registrado ya NO responde «conflicto»; la respuesta pública es
        // uniforme (OK), no crea cuentas adicionales y sigue consumiendo el mismo presupuesto de intentos.
        $this->assertSame(\Services\AuthService::STATUS_OK,$service->registrar($datos,$ip)['status']);
        $this->assertSame(\Services\AuthService::STATUS_OK,$service->registrar($datos,$ip)['status']);
        $blocked=$service->registrar($datos,$ip);
        $this->assertSame(\Services\AuthService::STATUS_RATE_LIMITED,$blocked['status']);
        $this->assertGreaterThan(0,$blocked['segundos_restantes']);
        $q=$this->db->prepare('SELECT COUNT(*) n FROM usuarios WHERE email=?');$q->bind_param('s',$email);$q->execute();
        $this->assertSame(1,(int)$q->get_result()->fetch_assoc()['n']);$q->close();
        $q=$this->db->prepare("DELETE FROM intentos_login WHERE (tipo='ip_register' AND identificador=?) OR (tipo='email_register' AND identificador=?)");$q->bind_param('ss',$ip,$email);$q->execute();$q->close();
    }
    public function testValidacionNoTruncaSecretosNiAceptaEmailsOTelefonosInvalidos(): void {
        $user=new \Model\Usuario(['nombre'=>'Ana','apellido'=>'Luz','email'=>'email-invalido','telefono'=>'ABC','password'=>str_repeat('x',73)]);
        $errors=$user->validarNuevaCuenta();
        $this->assertGreaterThanOrEqual(3,count($errors['error']));
        $user->sincronizarRegistro(['password'=>'  secreto seguro  ']);
        $this->assertSame('  secreto seguro  ',$user->password);
    }

    public function testCambiosConcurrentesConservanUnPropietarioActivo(): void {
        $owner1=$this->cuenta('propietario');$owner2=$this->cuenta('propietario');
        $otros=$this->db->query("SELECT u.id,u.admin,a.activo FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id WHERE u.id NOT IN ($owner1,$owner2) AND (a.rol='propietario' OR (a.usuario_id IS NULL AND u.admin=1))")->fetch_all(MYSQLI_ASSOC);
        foreach($otros as $r) { $this->db->query('UPDATE usuarios SET admin=0 WHERE id='.(int)$r['id']);$this->db->query('UPDATE usuario_accesos SET activo=0 WHERE usuario_id='.(int)$r['id']); }
        $procesos=[];$pipes=[];
        try {
            foreach([$owner1,$owner2] as $index=>$id) {
                $procesos[$index]=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_equipo_worker.php',(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes[$index]);
                $this->assertIsResource($procesos[$index]);
                stream_set_timeout($pipes[$index][1],10);
                $this->assertSame("READY\n",fgets($pipes[$index][1]),'Worker debe estar listo antes de abrir barrera');
            }
            foreach($pipes as $p) { fwrite($p[0],"GO\n");fflush($p[0]);fclose($p[0]); }
            $resultados=[];
            foreach($procesos as $index=>$proceso) {
                $line=fgets($pipes[$index][1]);$err=stream_get_contents($pipes[$index][2]);
                fclose($pipes[$index][1]);fclose($pipes[$index][2]);
                $this->assertSame(0,proc_close($proceso),$err);unset($procesos[$index]);
                $resultados[]=json_decode((string)$line,true)['resultado']??'inválido';
            }
            sort($resultados);$this->assertSame(['cambiado','protegido'],$resultados);
            $n=(int)$this->db->query("SELECT COUNT(*) n FROM usuario_accesos WHERE usuario_id IN ($owner1,$owner2) AND rol='propietario' AND activo=1")->fetch_assoc()['n'];
            $this->assertSame(1,$n);
        } finally {
            foreach($procesos as $p) { if(is_resource($p)) { proc_terminate($p);proc_close($p); } }
            foreach($otros as $r) { $this->db->query('UPDATE usuarios SET admin='.(int)$r['admin'].' WHERE id='.(int)$r['id']);$this->db->query('UPDATE usuario_accesos SET activo='.(int)($r['activo']??1).' WHERE usuario_id='.(int)$r['id']); }
        }
    }

}
