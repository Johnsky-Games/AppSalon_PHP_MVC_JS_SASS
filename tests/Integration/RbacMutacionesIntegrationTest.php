<?php
namespace Tests\Integration;
use AppTerminationException;
use Classes\MockPaymentGateway;
use Controllers\APIController;
use Controllers\PagoController;
use Model\ActiveRecord;
use MVC\Router;
use mysqli;
use PHPUnit\Framework\TestCase;
use Repositories\PagoRepository;
use Services\PagoService;

class RbacMutacionesIntegrationTest extends TestCase
{
    private mysqli $db;
    private array $ids=[];
    private array $citas=[];
    protected function setUp(): void {
        $this->db=new mysqli(getenv('DB_HOST')?:'appsalon-test-db',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'root',getenv('DB_NAME')?:'appsalon_test',(int)(getenv('DB_PORT')?:3306));
        $this->db->set_charset('utf8mb4');ActiveRecord::setDB($this->db);validar_base_datos_prueba($this->db);
        iniciar_sesion_segura();$_SESSION=[];$_GET=[];$_POST=[];
        $_SERVER['HTTP_ACCEPT']='application/json';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/api/';
        unset($_SERVER['CONTENT_TYPE']);
        PagoController::setPagoService(new PagoService(new PagoRepository($this->db),new MockPaymentGateway()));
        APIController::setCitaService(null);
    }
    protected function tearDown(): void {
        foreach($this->citas as $id) { $this->db->query("DELETE FROM pagos WHERE cita_id=$id");$this->db->query("DELETE FROM notificaciones_citas WHERE cita_id=$id");$this->db->query("DELETE FROM citasservicios WHERE citaId=$id");$this->db->query("DELETE FROM citas WHERE id=$id"); }
        foreach($this->ids as $id) { $this->db->query("DELETE FROM usuarios WHERE id=$id"); }
        PagoController::setPagoService(null);APIController::setCitaService(null);$_SESSION=[];$_POST=[];$_GET=[];
    }
    private function cuenta(string $rol): int {
        $email=bin2hex(random_bytes(8)).'@rbac.test';$hash=password_hash('PruebaSegura123',PASSWORD_BCRYPT);
        $s=$this->db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,telefono,confirmado) VALUES('Rbac','Prueba',?,?,'0999999999',1)");$s->bind_param('ss',$email,$hash);$s->execute();$id=(int)$this->db->insert_id;$s->close();$this->ids[]=$id;
        $s=$this->db->prepare('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)');$s->bind_param('is',$id,$rol);$s->execute();$s->close();return $id;
    }
    private function cita(int $cliente): int {
        $this->db->query("INSERT INTO citas(fecha,hora,usuarioId,estado) VALUES(DATE_ADD(CURDATE(),INTERVAL 15 DAY),'11:00:00',$cliente,'confirmada')");$id=(int)$this->db->insert_id;$this->citas[]=$id;return $id;
    }
    private function sesion(int $id): void { $_SESSION=['id'=>$id,'login'=>true,'admin'=>'1','version_sesion'=>1,'csrf_token'=>'rbac-csrf'];$_POST['csrf_token']='rbac-csrf'; }
    private function respuesta(callable $call): array {
        http_response_code(200);ob_start();try {$call();}catch(AppTerminationException $e){}finally {$body=ob_get_clean();}
        return ['http'=>http_response_code(),'json'=>json_decode($body,true),'body'=>$body];
    }
    public function testFinanzasYProfesionalNoCancelanCitasAjenasAunqueSesionIncluyaAdmin(): void {
        $cliente=$this->cuenta('cliente');$cita=$this->cita($cliente);
        foreach(['finanzas','profesional'] as $rol) {
            $staff=$this->cuenta($rol);$this->sesion($staff);$this->assertFalse(puede('agenda.gestionar'));
            $_POST['id']=(string)$cita;
            $result=$this->respuesta(fn()=>APIController::cancelar());
            $this->assertSame(403,$result['http'],$result['body']);$this->assertFalse($result['json']['resultado']);
            $this->assertSame('confirmada',$this->db->query("SELECT estado FROM citas WHERE id=$cita")->fetch_assoc()['estado']);
        }
    }
    public function testProfesionalNoIniciaPagoAjenoConAdminEnSesion(): void {
        $cliente=$this->cuenta('cliente');$cita=$this->cita($cliente);$staff=$this->cuenta('profesional');$this->sesion($staff);
        $_POST+=['cita_id'=>(string)$cita,'metodo_pago'=>'tarjeta_mock','idempotency_key'=>bin2hex(random_bytes(16))];
        $result=$this->respuesta(fn()=>PagoController::iniciar());
        $this->assertSame(403,$result['http'],$result['body']);
        $this->assertSame(0,(int)$this->db->query("SELECT COUNT(*) n FROM pagos WHERE cita_id=$cita")->fetch_assoc()['n']);
    }
    public function testProfesionalNoLeeResultadoDePagoAjenoConAdminEnSesion(): void {
        $cliente=$this->cuenta('cliente');$cita=$this->cita($cliente);
        $ref='RBAC-'.bin2hex(random_bytes(10));$key=bin2hex(random_bytes(16));
        $s=$this->db->prepare("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key) VALUES(?,?,?,?,'mock',50,'USD','pendiente','tarjeta_mock',?)");$s->bind_param('iiiss',$cita,$cita,$cliente,$ref,$key);$this->assertTrue($s->execute());$s->close();
        $staff=$this->cuenta('profesional');$this->sesion($staff);$_GET=['referencia'=>$ref,'format'=>'json'];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/pago/resultado';
        $result=$this->respuesta(fn()=>PagoController::resultado(new Router()));
        $this->assertSame(403,$result['http'],$result['body']);$this->assertArrayNotHasKey('pago',$result['json']);$this->assertStringNotContainsString($ref,$result['body']);
    }
}
