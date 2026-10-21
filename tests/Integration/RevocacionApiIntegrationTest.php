<?php
namespace Tests\Integration;

use Controllers\APIController;
use Controllers\PagoController;
use Controllers\FinanzasController;
use Controllers\InventarioController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Services\CitaService;
use Services\PagoService;
use Services\ProfesionalService;
use Services\DisponibilidadService;

/** Negative requests must not rely on visiting an HTML page to invalidate an old session. */
final class RevocacionApiIntegrationTest extends TestCase
{
    private \mysqli $db;
    private ?\mysqli $previa = null;
    private int $id;
    private array $server;
    protected function setUp(): void
    {
        $this->previa = ActiveRecord::getDB();
        $this->server = $_SERVER;
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1',getenv('DB_USER') ?: 'root',getenv('DB_PASS') ?: 'root',getenv('DB_NAME') ?: 'appsalon_test',(int)(getenv('DB_PORT') ?: 3306));
        ActiveRecord::setDB($this->db); validar_base_datos_prueba($this->db);
        $email='revoke-'.bin2hex(random_bytes(8)).'@test.local';
        $stmt=$this->db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Revocation','Test',?,'hash','0991234567',0,1)");
        $stmt->bind_param('s',$email);$stmt->execute();$this->id=(int)$this->db->insert_id;$stmt->close();
        $this->db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ({$this->id},'cliente',1)");
        // Si esta prueba corre primero en el proceso, iniciar_sesion_segura() de los controladores arrancaría la sesión y descartaría $_SESSION: se inicia antes.
        iniciar_sesion_segura();
        $_SESSION=['login'=>true,'id'=>$this->id,'admin'=>'0','version_sesion'=>1,'csrf_token'=>'token-valid-qa'];
        $_POST=['csrf_token'=>'token-valid-qa'];$_GET=[];
        $_SERVER['HTTP_ACCEPT']='application/json';$_SERVER['REQUEST_METHOD']='POST';http_response_code(200);
    }
    protected function tearDown(): void
    {
        APIController::setCitaService(null); APIController::setProfesionalService(null); APIController::setDisponibilidadService(null); PagoController::setPagoService(null);
        $this->db->query("DELETE FROM usuarios WHERE id={$this->id}");
        ActiveRecord::setDB($this->previa);
        $this->db->close();
        $_SESSION=[];$_POST=[];$_GET=[];$_SERVER=$this->server;http_response_code(200);
    }
    public static function solicitudesRevocadas(): array
    {
        $casos=[];
        foreach (['profesionales','misCitas','disponibilidad','guardar','pago'] as $accion) {
            foreach (['desactivado','version'] as $razon) { $casos[$accion.'-'.$razon]=[$accion,$razon]; }
        }
        return $casos;
    }
    #[DataProvider('solicitudesRevocadas')]
    public function testApiDirectaRechazaSesionRevocadaAntesDeConsultarOEscribir(string $accion,string $razon): void
    {
        if ($razon==='desactivado') $this->db->query("UPDATE usuario_accesos SET activo=0 WHERE usuario_id={$this->id}");
        else $this->db->query("UPDATE usuarios SET version_sesion=2 WHERE id={$this->id}");
        $citas=$this->createMock(CitaService::class);
        $citas->expects($this->never())->method('reservar');$citas->expects($this->never())->method('consultarCitasCliente');APIController::setCitaService($citas);
        $prof=$this->createMock(ProfesionalService::class);$prof->expects($this->never())->method('consultarParaReserva');APIController::setProfesionalService($prof);
        $disp=$this->createMock(DisponibilidadService::class);$disp->expects($this->never())->method('consultarDesdeParametros');APIController::setDisponibilidadService($disp);
        $pago=$this->createMock(PagoService::class);$pago->expects($this->never())->method('iniciarPago');PagoController::setPagoService($pago);
        $_SERVER['REQUEST_URI']=$accion==='pago'?'/api/pagos/iniciar':($accion==='guardar'?'/api/citas':'/api/'.$accion);
        ob_start();
        try {
            if ($accion==='pago') PagoController::iniciar(); else APIController::{$accion}();
            $this->fail('A revoked session must terminate the direct API request before service access.');
        } catch (\AppTerminationException $e) {
            $this->assertSame(401,$e->getStatusCode());$this->assertSame(401,http_response_code());
        } finally { $salida=(string)ob_get_clean(); }
        $json=json_decode($salida,true);$this->assertIsArray($json);$this->assertFalse($json['resultado']);
        $this->assertFalse($_SESSION['login'] ?? false);
    }
    public static function cajasProtegidas(): array { return [[FinanzasController::class,'caja_movimientos'],[InventarioController::class,'inventario_movimientos']]; }
    #[DataProvider('cajasProtegidas')]
    public function testCsrfInvalidoProduceHttp403SinEscritura(string $controller,string $tabla): void
    {
        $this->db->query("UPDATE usuario_accesos SET rol='propietario' WHERE usuario_id={$this->id}");
        $antes=(int)$this->db->query("SELECT COUNT(*) n FROM {$tabla}")->fetch_assoc()['n'];
        $_POST=['csrf_token'=>'incorrecto'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/admin/operacion';
        ob_start();
        try { $controller::guardar(new Router());$this->fail('CSRF must terminate mutation.'); }
        catch(\AppTerminationException $e){$this->assertSame(403,$e->getStatusCode());$this->assertSame(403,http_response_code());}
        finally {ob_end_clean();}
        $this->assertSame($antes,(int)$this->db->query("SELECT COUNT(*) n FROM {$tabla}")->fetch_assoc()['n']);
    }
}
