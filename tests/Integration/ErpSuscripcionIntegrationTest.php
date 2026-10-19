<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpSuscripcionService,ErpPaqueteService,ErpPrepagoService,ErpMaestrosService,ErpException};
final class ErpSuscripcionIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $contacto;private int $plan;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('suscripciones');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->actor=R5CajaFixture::usuario($this->db,'propietario');
        $this->contacto=(new ErpMaestrosService($this->r))->contacto($this->actor,$this->d(['nombre'=>'Cliente mensual']))['recurso_id'];
        $this->r->q("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Sesión mensual',30,30)");$servicio=$this->r->id();
        $this->plan=(new ErpPaqueteService($this->r))->plan($this->actor,$this->d(['nombre'=>'Plan mensual','servicio_id'=>$servicio,'sesiones'=>2,'precio'=>'20']))['recurso_id'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function svc(string $fecha='2026-01-31'):ErpSuscripcionService {return new ErpSuscripcionService($this->r,new \DateTimeImmutable($fecha.' 12:00:00',new \DateTimeZone('America/Guayaquil')));}
    private function crear(array $d=[]):int {return $this->svc()->crear($this->actor,$this->d($d+['plan_id'=>$this->plan,'contacto_id'=>$this->contacto,'inicio'=>'2026-01-31','renovacion_autorizada'=>'1','consentimiento'=>'Aceptación de ciclos de ensayo por el cliente']))['recurso_id'];}
    public function testPrimerCicloSinCobroNiDeudaNiSesionesGratis():void
    {
        $id=$this->crear();$c=$this->r->uno('SELECT * FROM erp_suscripcion_ciclos WHERE suscripcion_id=?',[$id]);self::assertSame(['2026-01-31','2026-02-28'],[$c['desde'],$c['hasta']]);
        foreach(['pagos','caja_movimientos','erp_obligaciones','erp_paquete_aplicaciones'] as $t)self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM '.$t)['n']);
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquetes')['n']);
    }
    public function testAceptacionEsObligatoriaYNoDejaCabecerasHuerfanas():void
    {
        try{$this->crear(['renovacion_autorizada'=>'0']);self::fail('Falta aceptación');}catch(ErpException $e){self::assertSame(422,$e->getCode());}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_suscripciones')['n']);self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_prepagos')['n']);
    }
    public function testSupervisorRepetidoAbreUnSoloCicloYNoDebita():void
    {
        $this->crear();self::assertSame(['abiertos'=>1,'fallidos'=>0],$this->svc('2026-02-28')->procesar());self::assertSame(['abiertos'=>0,'fallidos'=>0],$this->svc('2026-02-28')->procesar());
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_suscripcion_ciclos')['n']);self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
    public function testPausaYReactivacionNoCreanCuotasDeMesesOmitidos():void
    {
        $id=$this->crear();$this->svc()->estado($this->actor,$this->d(['suscripcion_id'=>$id,'estado'=>'pausada','motivo'=>'Pausa solicitada por el cliente']));
        self::assertSame(0,$this->svc('2026-06-15')->procesar()['abiertos']);
        $this->svc('2026-06-15')->estado($this->actor,$this->d(['suscripcion_id'=>$id,'estado'=>'activa','motivo'=>'Regreso del cliente']));
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_suscripcion_ciclos')['n']);
        $c=$this->r->uno('SELECT desde,hasta FROM erp_suscripcion_ciclos ORDER BY id DESC LIMIT 1');self::assertSame(['2026-05-31','2026-06-30'],[$c['desde'],$c['hasta']]);
        $e=json_decode($this->r->uno("SELECT datos_json FROM erp_eventos WHERE tipo='suscripcion_estado' ORDER BY id DESC LIMIT 1")['datos_json'],true);self::assertSame(['pausada','activa'],[$e['anterior'],$e['estado']]);
    }
    public function testCancelacionEsFinalYDetieneRenovaciones():void
    {
        $id=$this->crear();$this->svc()->estado($this->actor,$this->d(['suscripcion_id'=>$id,'estado'=>'cancelada','motivo'=>'Fin del contrato a petición del cliente']));
        self::assertSame(0,$this->svc('2026-03-31')->procesar()['abiertos']);
        $this->expectException(ErpException::class);$this->svc()->estado($this->actor,$this->d(['suscripcion_id'=>$id,'estado'=>'activa','motivo'=>'Reactivación no válida']));
    }
    public function testPlanArchivadoConservaElContratoAnteriorYNoSeOfreceParaAltas():void
    {
        $this->crear();(new ErpPaqueteService($this->r))->archivar($this->actor,$this->d(['plan_id'=>$this->plan,'motivo'=>'Nueva versión comercial']));
        self::assertSame(1,$this->svc('2026-02-28')->procesar()['abiertos']);
        $this->expectException(ErpException::class);$this->crear();
    }
    public function testUnContactoInactivoNoBloqueaLosDemasContratos():void
    {
        $this->crear();$m=new ErpMaestrosService($this->r);$otro=$m->contacto($this->actor,$this->d(['nombre'=>'Otro beneficiario mensual']))['recurso_id'];$this->crear(['contacto_id'=>$otro]);
        $this->r->q('UPDATE erp_contactos SET activo=0 WHERE id=?',[$this->contacto]);self::assertSame(['abiertos'=>1,'fallidos'=>1],$this->svc('2026-02-28')->procesar());
        self::assertSame(3,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_suscripcion_ciclos')['n']);
    }
    public function testNoSeAceptanNuevasCargasDeUnCicloVencido():void
    {
        $this->crear();$c=$this->r->uno('SELECT p.prepago_id FROM erp_suscripcion_ciclos c JOIN erp_paquetes p ON p.id=c.paquete_id');
        $this->expectException(ErpException::class);(new ErpPrepagoService($this->r))->cargar($this->actor,$this->d(['prepago_id'=>$c['prepago_id'],'metodo'=>'efectivo','monto'=>'40','recibido'=>'40']));
    }
    public function testClienteNoModificaUnaSuscripcion():void
    {
        $id=$this->crear();$cliente=R5CajaFixture::usuario($this->db);$this->expectException(ErpException::class);$this->svc()->estado($cliente,$this->d(['suscripcion_id'=>$id,'estado'=>'cancelada','motivo'=>'Intento sin permisos']));
    }
    public function testReenvioDeContratoTrasVencerLaCacheNoDuplicaCiclosNiReviveCancelacion():void
    {
        $d=$this->d(['plan_id'=>$this->plan,'contacto_id'=>$this->contacto,'inicio'=>'2026-01-31','renovacion_autorizada'=>'1','consentimiento'=>'Aceptación del contrato original']);$id=$this->svc()->crear($this->actor,$d)['recurso_id'];
        $this->svc()->estado($this->actor,$this->d(['suscripcion_id'=>$id,'estado'=>'cancelada','motivo'=>'Cancelación del cliente']));$this->r->q("UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)");
        self::assertSame($id,$this->svc('2026-06-01')->crear($this->actor,$d)['recurso_id']);self::assertSame('cancelada',$this->r->uno('SELECT estado FROM erp_suscripciones')['estado']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_suscripcion_ciclos')['n']);
    }
}
