<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpFiscalService,ErpFiscalParametroService,ErpPaqueteService,ErpPrepagoService,ErpSuscripcionService,ErpMaestrosService,ErpCajaService,ErpVentaService,ErpDevolucionService,ErpComisionService,ErpException};
use Interfaces\SriTransporteInterface;
use Tests\Fixtures\Sri\Fixtures as F;

/**
 * Cobro anticipado, prepago, paquetes y suscripciones frente a la emisión fiscal. Hechos distintos: recibir dinero (carga), asignar y prestar la
 * sesión (visita), y emitir el comprobante. El comprobante se emite una sola vez, al prestar el servicio, y no mueve caja, saldo ni sesiones.
 */
final class ErpFiscalAnticiposIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $cliente;private int $contacto;private int $servicio;private int $paquete;private int $prepago;private int $sesion;private int $perfil;
    private mixed $appKeyAnterior;private mixed $appKeyEnvAnterior;
    protected function setUp():void
    {
        $this->appKeyAnterior=getenv('APP_KEY');$this->appKeyEnvAnterior=$_ENV['APP_KEY']??null;putenv('APP_KEY=testing-fiscal-master-key-32-characters');$_ENV['APP_KEY']='testing-fiscal-master-key-32-characters';
        $this->db=BaseDatosAislada::crear('fiscal_anticipos');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->actor=R5CajaFixture::usuario($this->db,'propietario');$this->cliente=R5CajaFixture::usuario($this->db);
        $this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$this->cliente]);
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->actor,$this->d(['nombre'=>'Beneficiario de sesiones','tipo'=>'cliente']))['recurso_id'];
        $m->vincularCliente($this->actor,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$this->cliente]));
        $this->r->q("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio del paquete',30,30)");$this->servicio=$this->r->id();
        $plan=$this->paquetes()->plan($this->actor,$this->d(['nombre'=>'Dos sesiones','servicio_id'=>$this->servicio,'sesiones'=>2,'precio'=>'20','impuesto'=>'0']))['recurso_id'];
        $this->paquete=$this->paquetes()->crear($this->actor,$this->d(['plan_id'=>$plan,'contacto_id'=>$this->contacto]))['recurso_id'];
        $this->prepago=(int)$this->r->uno('SELECT prepago_id FROM erp_paquetes WHERE id=?',[$this->paquete])['prepago_id'];
        $this->sesion=(new ErpCajaService($this->r))->abrir($this->actor,$this->d(['fondo'=>'0']))['recurso_id'];
        [$p12,$password]=F::certificado();$this->perfil=$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor(),'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        F::parametros($this->db,$this->actor,['servicios.hecho_generador']);
    }
    protected function tearDown():void {try{BaseDatosAislada::destruir($this->db);}finally{putenv($this->appKeyAnterior===false?'APP_KEY':'APP_KEY='.$this->appKeyAnterior);if($this->appKeyEnvAnterior===null)unset($_ENV['APP_KEY']);else $_ENV['APP_KEY']=$this->appKeyEnvAnterior;}}
    private function paquetes():ErpPaqueteService {return new ErpPaqueteService($this->r);}
    private function fiscal():ErpFiscalService {return new ErpFiscalService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function cargar(int $prepago,string $monto='40'):void {(new ErpPrepagoService($this->r))->cargar($this->actor,$this->d(['prepago_id'=>$prepago,'metodo'=>'efectivo','monto'=>$monto,'recibido'=>$monto]));}
    private function visita(int $paquete,string $fecha='2030-01-02'):array
    {
        $c=R5CajaFixture::cita($this->db,$this->cliente,null,['30.00'],'confirmada',$fecha);$this->r->q('UPDATE citasservicios SET servicioId=? WHERE citaId=?',[$this->servicio,$c]);
        $v=(new ErpVentaService($this->r))->crear($this->actor,$this->d(['cita_id'=>$c]))['recurso_id'];
        return $this->d(['paquete_id'=>$paquete,'venta_id'=>$v,'linea_id'=>$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v])['id'],'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]);
    }
    /** Asigna la sesión y deja la cita atendida (consume la sesión) como lo haría el cierre de la atención. */
    private function atendida(array $d):void
    {
        $this->paquetes()->aplicar($this->actor,$d);$this->r->q("UPDATE citas c JOIN erp_ventas v ON v.cita_id=c.id SET c.estado='completada' WHERE v.id=?",[$d['venta_id']]);
        (new ErpComisionService($this->r))->recalcular($this->actor,$this->d(['venta_id'=>$d['venta_id']]));
    }
    private function eleccion():void
    {
        (new ErpFiscalParametroService($this->r))->registrar($this->actor,$this->d(['clave'=>'servicios.hecho_generador','valor'=>'prestacion','vigente_desde'=>substr($this->r::ahora(),0,10),'consultado_en'=>substr($this->r::ahora(),0,10),'fuente'=>'Ley de Régimen Tributario Interno, art. 61 num. 2 (elección de ensayo)']));
    }
    private function datos(array $d):array
    {
        return $this->d(['perfil_id'=>$this->perfil,'venta_id'=>$d['venta_id'],'comprador'=>F::factura()['comprador'],'categorias_iva'=>[(string)$d['linea_id']=>'0'],'pagos'=>[['forma'=>'20','total_centavos'=>2000]]]);
    }
    private function foto():array
    {
        return ['pagos'=>(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n'],'caja'=>(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n'],'esperado'=>(new ErpCajaService($this->r))->esperado($this->sesion),'saldo'=>(new ErpPrepagoService($this->r))->saldo($this->prepago),'sesiones'=>$this->r->q('SELECT estado FROM erp_paquete_aplicaciones ORDER BY id')];
    }
    private function docs():int {return (int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n'];}
    private function conflicto(callable $f,int $codigo=409):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function transporte(array $respuestas):SriTransporteInterface
    {return new class($respuestas,$this->db) implements SriTransporteInterface {public function __construct(private array $respuestas,private \mysqli $db){}public function post(string $url,string $xml):string {if(\Classes\Transaccion::nivel($this->db)!==0)throw new \LogicException('Red bajo transacción SQL');$r=array_shift($this->respuestas);if($r instanceof \Throwable)throw $r;if(!is_string($r))throw new \LogicException('Petición inesperada');return $r;}};}
    private function autorizar(int $id):void
    {
        $d=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$id]);$this->r->q('UPDATE erp_fiscal_documentos SET proximo_intento=NULL,lease_hasta=NULL');
        $respuesta='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante><claveAccesoConsultada>'.$d['clave_acceso'].'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>'.$d['clave_acceso'].'</numeroAutorizacion><fechaAutorizacion>2026-10-19T10:00:00-05:00</fechaAutorizacion><ambiente>PRUEBAS</ambiente><comprobante><![CDATA['.$d['xml_firmado'].']]></comprobante><mensajes/></autorizacion></autorizaciones></RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
        $this->fiscal()->procesar($this->transporte([$respuesta]));self::assertSame('autorizada',$this->r->uno('SELECT estado FROM erp_fiscal_documentos WHERE id=?',[$id])['estado']);
    }

    public function testRecibirDineroPorAnticipoPrepagoOPaqueteNoGeneraComprobante():void
    {
        $this->cargar($this->prepago);
        // Carga de un saldo nominativo y de un regalo: tampoco hay documento ni evento fiscal; el comprobante nace de la prestación.
        $this->r->q("INSERT INTO erp_prepagos(contacto_id,tipo,nombre,creado_por,creado_en) VALUES(?,'regalo','Regalo',?,?)",[$this->contacto,$this->actor,$this->r::ahora()]);$this->cargar($this->r->id(),'15');
        self::assertSame(0,$this->docs());self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo IN('fiscal_documento_preparado','fiscal_estado')")['n']);
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n'],'El dinero consta en caja como cobro anticipado, sin comprobante');
    }
    public function testSesionPrestadaConSaldoAnticipadoSeFacturaUnaVezSinMoverDineroNiSesiones():void
    {
        $this->cargar($this->prepago);$d=$this->visita($this->paquete);$this->atendida($d);$antes=$this->foto();
        $e=$this->conflicto(fn()=>$this->fiscal()->factura($this->actor,$this->datos($d)));self::assertStringContainsString('servicios.hecho_generador',$e->getMessage(),'Sin la elección registrada del momento del hecho generador no se factura una venta con anticipo');
        self::assertSame(0,$this->docs());
        $this->eleccion();$f=$this->fiscal()->factura($this->actor,$this->datos($d))['recurso_id'];
        $snap=json_decode($this->r->uno('SELECT snapshot_json FROM erp_fiscal_documentos WHERE id=?',[$f])['snapshot_json'],true);
        self::assertContains('servicios.hecho_generador',array_column($snap['parametros'],'clave'));self::assertSame(2000,array_sum(array_column($snap['factura']['pagos'],'total_centavos')));
        self::assertSame($antes,$this->foto(),'Emitir no cobra, no mueve caja, no consume ni restituye sesiones, no toca el saldo');
        $total=(int)$this->r->uno('SELECT total_centavos FROM erp_ventas WHERE id=?',[$d['venta_id']])['total_centavos'];self::assertSame(2000,$total,'Un solo ingreso fiscal: el precio pactado de la sesión');
        // Reintento con otra clave: el comprobante ya existe; no se duplica ni cambia.
        $this->conflicto(fn()=>$this->fiscal()->factura($this->actor,$this->datos($d)));self::assertSame(1,$this->docs());
        // Ni la segunda sesión ni la carga posterior generan un segundo comprobante para la primera venta.
        $this->autorizar($f);self::assertSame(1,$this->docs());
    }
    public function testSesionRestituidaDespuesDeFacturarSeAcreditaConNotaSinMoverDineroNiSaldo():void
    {
        $this->cargar($this->prepago);$d=$this->visita($this->paquete);$this->atendida($d);$this->eleccion();$f=$this->fiscal()->factura($this->actor,$this->datos($d))['recurso_id'];$this->autorizar($f);
        (new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$d['venta_id'],'linea_id'=>$d['linea_id'],'cantidad'=>'1','motivo'=>'Reprogramación de la sesión de ensayo']));
        $dev=(int)$this->r->uno('SELECT MAX(id) n FROM erp_devoluciones')['n'];
        self::assertSame('restituida',$this->r->uno('SELECT estado FROM erp_paquete_aplicaciones')['estado']);$antes=$this->foto();
        $nc=$this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'Sesión restituida al paquete']))['recurso_id'];
        self::assertSame($antes,$this->foto(),'La nota de crédito es un hecho fiscal: el saldo restituido, las sesiones y la caja ya venían de la devolución comercial');
        self::assertSame(2000,(int)$this->r->uno('SELECT SUM(total_centavos) t FROM erp_fiscal_nc_lineas WHERE documento_id=?',[$nc])['t']);
        $this->conflicto(fn()=>$this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'Otra vez'])));self::assertSame(2,$this->docs());
    }
    public function testCicloDeSuscripcionNoFacturaAlAbrirseYSuSesionSigueElMismoCircuito():void
    {
        $s=new ErpSuscripcionService($this->r,new \DateTimeImmutable('2030-01-01 12:00:00',new \DateTimeZone('America/Guayaquil')));
        $id=$s->crear($this->actor,$this->d(['plan_id'=>$this->r->uno('SELECT id FROM erp_paquete_planes')['id'],'contacto_id'=>$this->contacto,'inicio'=>'2030-01-01','renovacion_autorizada'=>'1','consentimiento'=>'Aceptación de ciclos de ensayo por escrito']))['recurso_id'];
        self::assertGreaterThan(0,$id);self::assertSame(0,$this->docs(),'Abrir un ciclo no es prestar ni facturar');
        $ciclo=$this->r->uno('SELECT paquete_id FROM erp_suscripcion_ciclos WHERE suscripcion_id=?',[$id]);$prepago=(int)$this->r->uno('SELECT prepago_id FROM erp_paquetes WHERE id=?',[$ciclo['paquete_id']])['prepago_id'];
        $this->cargar($prepago);self::assertSame(0,$this->docs(),'Cobrar el ciclo por adelantado tampoco');
        $d=$this->visita((int)$ciclo['paquete_id'],'2030-01-02');$this->atendida($d);$this->eleccion();
        $f=$this->fiscal()->factura($this->actor,$this->datos($d))['recurso_id'];self::assertSame(1,$this->docs());self::assertSame((int)$d['venta_id'],(int)$this->r->uno('SELECT venta_id FROM erp_fiscal_documentos WHERE id=?',[$f])['venta_id']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_paquete_aplicaciones WHERE estado='consumida'")['n']);
    }
    public function testElMomentoDeCobroTodaviaNoEstaDisponibleYNoSeRegistraPorError():void
    {
        $e=$this->conflicto(fn()=>(new ErpFiscalParametroService($this->r))->registrar($this->actor,$this->d(['clave'=>'servicios.hecho_generador','valor'=>'cobro','vigente_desde'=>substr($this->r::ahora(),0,10),'consultado_en'=>substr($this->r::ahora(),0,10),'fuente'=>'Elección de ensayo'])),422);
        self::assertStringContainsString('todavía no está implementado',$e->getMessage());
    }
}
