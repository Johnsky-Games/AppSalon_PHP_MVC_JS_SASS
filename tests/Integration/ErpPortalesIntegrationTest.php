<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\ErpRepository;
use Controllers\ErpController;
final class ErpPortalesIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private array $estadoAnterior;
    protected function setUp():void{
        $this->estadoAnterior=['sesion'=>$_SESSION??[],'post'=>$_POST,'get'=>$_GET,'servidor'=>$_SERVER];
        $this->db=BaseDatosAislada::crear('erp_portales');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->owner=R5CajaFixture::usuario($this->db,'propietario');
        $this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','recepcion')",[$this->owner]);if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION=[];$_POST=[];$_GET=[];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/accion';
    }
    protected function tearDown():void{$_SESSION=$this->estadoAnterior['sesion'];$_POST=$this->estadoAnterior['post'];$_GET=$this->estadoAnterior['get'];$_SERVER=$this->estadoAnterior['servidor'];http_response_code(200);BaseDatosAislada::destruir($this->db);}
    private function sesion(string $portal):void{$ctx=(new \Services\AuthorizationService($this->db))->contexto($this->owner);$_SESSION=['login'=>true,'id'=>$this->owner,'portal'=>$portal,'version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-erp-portales'];}
    private function ejecutar(callable $fn):int{$status=200;ob_start();try{$fn();}catch(\AppTerminationException $e){$status=$e->getStatusCode();}finally{ob_end_clean();}return $status;}
    public function testPropietarioEnPortalClienteNoPuedeEscribirEnErpAunqueTengaCsrfValido():void
    {
        $this->sesion('cliente');$_SERVER['REQUEST_METHOD']='POST';$_POST=['accion'=>'venta_crear','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-erp-portales'];
        self::assertSame(403,$this->ejecutar(fn()=>ErpController::accion()));self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_ventas')['n']);
        $this->sesion('equipo');self::assertSame(303,$this->ejecutar(fn()=>ErpController::accion()));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_ventas')['n']);
    }
    public function testPortalClienteNoLeeExpedientesNiDescargasLaborales():void
    {
        $this->sesion('cliente');$_SERVER['REQUEST_METHOD']='GET';$_GET=['id'=>'1','entidad'=>'colaborador'];$router=new \MVC\Router();
        foreach(['personal','documentos'] as $metodo)self::assertSame(403,$this->ejecutar(fn()=>ErpController::$metodo($router)),$metodo);
        self::assertSame(403,$this->ejecutar(fn()=>ErpController::documentoDescargar()));
    }
}
