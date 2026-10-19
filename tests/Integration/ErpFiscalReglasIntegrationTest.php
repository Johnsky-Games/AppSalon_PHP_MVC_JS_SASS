<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpFiscalService,ErpFiscalParametroService,ErpVentaService,ErpMaestrosService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpDevolucionService,ErpException};
use Services\Fiscal\SriComprobante;
use Interfaces\SriTransporteInterface;
use Tests\Fixtures\Sri\Fixtures as F;

/** Parámetros fiscales por régimen y vigencia, consumidor final, leyenda de régimen y control previo a transmitir un sustituto. */
final class ErpFiscalReglasIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $recepcion;private int $perfil;private int $venta;private int $linea;
    private mixed $appKeyAnterior;private mixed $appKeyEnvAnterior;
    protected function setUp():void
    {
        $this->appKeyAnterior=getenv('APP_KEY');$this->appKeyEnvAnterior=$_ENV['APP_KEY']??null;putenv('APP_KEY=testing-fiscal-master-key-32-characters');$_ENV['APP_KEY']='testing-fiscal-master-key-32-characters';
        $this->db=BaseDatosAislada::crear('fiscal_reglas');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->actor=R5CajaFixture::usuario($this->db,'propietario');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        [$p12,$password]=F::certificado();$this->perfil=$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor(),'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        F::parametros($this->db,$this->actor);
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('RG-1','Champú','unidad',10000,500)");$producto=(int)$this->db->insert_id;
        $c=(new ErpMaestrosService($this->r))->catalogo($this->actor,$this->d(['origen'=>'producto','origen_id'=>$producto,'precio'=>'10.00','impuesto'=>'15.00','tipo'=>'venta']))['recurso_id'];
        $ventas=new ErpVentaService($this->r);$this->venta=$ventas->crear($this->actor,$this->d())['recurso_id'];$ventas->agregar($this->actor,$this->d(['venta_id'=>$this->venta,'catalogo_id'=>$c,'cantidad'=>'3']));
        $ventas->confirmar($this->actor,$this->d(['venta_id'=>$this->venta,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$this->venta])['version']]));
        (new ErpCajaService($this->r))->abrir($this->actor,$this->d(['fondo'=>'0']));(new ErpPagoService($this->r))->cobrar($this->actor,$this->d(['venta_id'=>$this->venta,'monto'=>'34.50','recibido'=>'34.50','metodo'=>'efectivo']));
        (new ErpEntregaService($this->r))->entregar($this->actor,$this->d(['venta_id'=>$this->venta]));
        $this->linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$this->venta])['id'];
    }
    protected function tearDown():void {try{BaseDatosAislada::destruir($this->db);}finally{putenv($this->appKeyAnterior===false?'APP_KEY':'APP_KEY='.$this->appKeyAnterior);if($this->appKeyEnvAnterior===null)unset($_ENV['APP_KEY']);else $_ENV['APP_KEY']=$this->appKeyEnvAnterior;}}
    private function fiscal():ErpFiscalService {return new ErpFiscalService($this->r);}
    private function par():ErpFiscalParametroService {return new ErpFiscalParametroService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function hoy():string {return substr($this->r::ahora(),0,10);}
    private function registrar(array $d,?int $actor=null):array {return $this->par()->registrar($actor??$this->actor,$this->d($d+['vigente_desde'=>$this->hoy(),'consultado_en'=>$this->hoy(),'fuente'=>'Fuente de ensayo para la prueba']));}
    private function noAutorizado(string $clave):string {return self::SOAP_A.'<claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>NO AUTORIZADO</estado><mensajes><mensaje><identificador>39</identificador><mensaje>Firma inválida de ensayo</mensaje><informacionAdicional>detalle</informacionAdicional><tipo>ERROR</tipo></mensaje></mensajes></autorizacion></autorizaciones>'.self::SOAP_B;}
    private const SOAP_A='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante>';
    private const SOAP_B='</RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
    private const RECIBIDA='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:validarComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.recepcion"><RespuestaRecepcionComprobante><estado>RECIBIDA</estado><comprobantes/></RespuestaRecepcionComprobante></ns2:validarComprobanteResponse></soap:Body></soap:Envelope>';
    private function sinAutorizacion(string $clave):string {return self::SOAP_A.'<claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>0</numeroComprobantes><autorizaciones/>'.self::SOAP_B;}
    private function autorizado(string $clave,string $xml):string {return self::SOAP_A.'<claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>'.$clave.'</numeroAutorizacion><fechaAutorizacion>2026-10-19T10:00:00-05:00</fechaAutorizacion><ambiente>PRUEBAS</ambiente><comprobante><![CDATA['.$xml.']]></comprobante><mensajes/></autorizacion></autorizaciones>'.self::SOAP_B;}
    private function transporte(array $respuestas):SriTransporteInterface
    {return new class($respuestas,$this->db) implements SriTransporteInterface {public array $peticiones=[];public function __construct(private array $respuestas,private \mysqli $db){}public function post(string $url,string $xml):string {if(\Classes\Transaccion::nivel($this->db)!==0)throw new \LogicException('Red bajo transacción SQL');$this->peticiones[]=[$url,$xml];$r=array_shift($this->respuestas);if($r instanceof \Throwable)throw $r;if(!is_string($r))throw new \LogicException('Petición inesperada');return $r;}};}
    private function debido():void {$this->r->q('UPDATE erp_fiscal_documentos SET proximo_intento=NULL,lease_hasta=NULL');$this->r->q('UPDATE erp_fiscal_verificaciones SET proximo_intento=NULL,lease_hasta=NULL');}
    private function doc(int $id):array {return $this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$id]);}
    private function datos(array $extra=[]):array {return $this->d($extra+['perfil_id'=>$this->perfil,'venta_id'=>$this->venta,'comprador'=>F::factura()['comprador'],'categorias_iva'=>[(string)$this->linea=>'4'],'pagos'=>[['forma'=>'01','total_centavos'=>3450]]]);}
    private function factura(array $extra=[]):int {return $this->fiscal()->factura($this->actor,$this->datos($extra))['recurso_id'];}
    private function xml(int $id):\DOMXPath {return new \DOMXPath(\Services\Fiscal\SriXml::cargar($this->doc($id)['xml_firmado']));}
    private function autorizar(int $id):void
    {
        $d=$this->doc($id);$this->debido();$this->fiscal()->procesar($this->transporte([$this->autorizado($d['clave_acceso'],$d['xml_firmado'])]));self::assertSame('autorizada',$this->doc($id)['estado']);
    }
    private function rechazar(int $id):void
    {
        $d=$this->doc($id);$this->debido();$this->fiscal()->procesar($this->transporte([$this->noAutorizado($d['clave_acceso'])]));self::assertSame('rechazada',$this->doc($id)['estado']);
    }
    private function verificado(int $id):void
    {
        $this->fiscal()->solicitarVerificacion($this->actor,$this->d(['documento_id'=>$id]));$this->debido();
        $this->fiscal()->verificar($this->transporte([$this->noAutorizado($this->doc($id)['clave_acceso'])]));
        self::assertSame('confirmado',$this->r->uno('SELECT estado FROM erp_fiscal_verificaciones WHERE documento_id=? ORDER BY id DESC LIMIT 1',[$id])['estado']);
    }
    private function conflicto(callable $f,int $codigo=409):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}

    public function testParametrosSonVersionadosAuditadosYSoloLosRegistraQuienConfigura():void
    {
        $this->conflicto(fn()=>$this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','valor'=>'30.00'],$this->recepcion),403);
        $id=$this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','valor'=>'30.00','fuente_url'=>'https://www.sri.gob.ec/ejemplo','nota'=>'Valor de ensayo'])['recurso_id'];
        $fila=$this->r->uno('SELECT * FROM erp_fiscal_parametros WHERE id=?',[$id]);self::assertSame(3000,(int)$fila['valor_entero']);self::assertSame('*',$fila['regimen']);self::assertSame('vigente',$fila['estado']);
        $ev=$this->r->uno("SELECT * FROM erp_eventos WHERE tipo='fiscal_parametro_registrado' AND entidad_id=?",[$id]);self::assertNotNull($ev);self::assertSame($this->actor,(int)$ev['actor_id']);self::assertStringContainsString('Fuente de ensayo',$ev['datos_json']);
        // La historia no se reescribe: ni SQL directo, ni un duplicado el mismo día, ni una vigencia anterior a la ya registrada.
        foreach(['UPDATE erp_fiscal_parametros SET valor_entero=1 WHERE id='.$id,'DELETE FROM erp_fiscal_parametros WHERE id='.$id] as $sql){try{$this->r->q($sql);self::fail('El historial de parámetros es de solo anexar.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1644,$e->getCode());}}
        $this->conflicto(fn()=>$this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','valor'=>'40.00']));
        $this->conflicto(fn()=>$this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','valor'=>'40.00','vigente_desde'=>'2001-01-01']));
        // Validaciones de dominio.
        foreach([['clave'=>'iva.codigo.4','valor'=>'1200'],['clave'=>'no.existe','valor'=>'1'],['clave'=>'servicios.hecho_generador','valor'=>'cobro'],['clave'=>'regimen.leyenda','valor'=>'Texto','regimen'=>'*'],['clave'=>'servicios.hecho_generador','valor'=>'prestacion','regimen'=>'general'],['clave'=>'sustitucion.verificacion_minutos','valor'=>'5'],['clave'=>'consumidor_final.total_maximo_centavos','valor'=>'0.00','regimen'=>'rimpe_emprendedor']] as $malo)
            $this->conflicto(fn()=>$this->registrar($malo),422);
    }
    public function testResolucionPorRegimenYVigencia():void
    {
        $this->registrar(['clave'=>'regimen.leyenda','regimen'=>'rimpe_emprendedor','valor'=>'CONTRIBUYENTE RÉGIMEN RIMPE']);
        self::assertSame('CONTRIBUYENTE RÉGIMEN RIMPE',$this->par()->resolver('regimen.leyenda','rimpe_emprendedor',$this->hoy())['valor_texto']);
        self::assertNull($this->par()->resolver('regimen.leyenda','rimpe_negocio_popular',$this->hoy()),'Otro régimen no hereda la leyenda');
        self::assertNull($this->par()->resolver('regimen.leyenda','rimpe_emprendedor','2000-01-01'),'Antes de su vigencia no aplica');
        // El valor propio del régimen prevalece sobre el general; derogarlo lo retira sin recurrir al general.
        $this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','regimen'=>'rimpe_negocio_popular','valor'=>'200.00']);
        self::assertSame(20000,(int)$this->par()->resolver('consumidor_final.total_maximo_centavos','rimpe_negocio_popular',$this->hoy())['valor_entero']);
        self::assertSame(5000,(int)$this->par()->resolver('consumidor_final.total_maximo_centavos','general',$this->hoy())['valor_entero'],'Sin versión propia se usa la general');
        $futuro=(new \DateTimeImmutable($this->hoy()))->modify('+10 days')->format('Y-m-d');
        $this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','regimen'=>'rimpe_negocio_popular','estado'=>'derogado','vigente_desde'=>$futuro]);
        self::assertNotNull($this->par()->resolver('consumidor_final.total_maximo_centavos','rimpe_negocio_popular',$this->hoy()));
        self::assertNull($this->par()->resolver('consumidor_final.total_maximo_centavos','rimpe_negocio_popular',$futuro),'Derogado: sin regla para ese régimen');
    }
    public function testElComprobanteEmitidoConservaLaReferenciaDelParametroUsado():void
    {
        $f=$this->factura();$snap=json_decode($this->doc($f)['snapshot_json'],true);
        $claves=array_column($snap['parametros'],'clave');self::assertContains('iva.codigo.4',$claves);
        $ref=array_values(array_filter($snap['parametros'],fn($p)=>$p['clave']==='iva.codigo.4'))[0];self::assertSame(1500,$ref['valor']);self::assertSame('2000-01-01',$ref['vigente_desde']);
        // Una derogación futura no altera el comprobante emitido; la vigente hoy sigue permitiendo emitir.
        $this->registrar(['clave'=>'iva.codigo.4','estado'=>'derogado']);
        $e=$this->conflicto(fn()=>$this->fiscal()->factura($this->actor,$this->datos(['perfil_id'=>$this->perfil])+['idem_key'=>R5CajaFixture::clave()]));
        self::assertStringContainsString('ya tiene un comprobante',$e->getMessage(),'La retransmisión de una factura existente no consulta parámetros: devuelve el conflicto de duplicado, no emite otra');
    }
    public function testFacturaNuevaSinParametroDeIvaVigenteNoSeEmite():void
    {
        $this->registrar(['clave'=>'iva.codigo.4','estado'=>'derogado']);
        $e=$this->conflicto(fn()=>$this->factura());self::assertStringContainsString('iva.codigo.4',$e->getMessage());
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);self::assertNull($this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias WHERE ultimo>0'),'Sin documento no se consume secuencial');
    }
    public function testConsumidorFinalSoloConParametroVigenteYDentroDelMaximo():void
    {
        $cf=['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'Alguien con nombre propio','direccion'=>'No debe viajar'];
        // Total 34,50 contra un máximo de ensayo de 50,00: se emite, con los datos canónicos de consumidor final.
        $f=$this->factura(['comprador'=>$cf]);$x=$this->xml($f);
        self::assertSame('07',$x->evaluate('string(//infoFactura/tipoIdentificacionComprador)'));self::assertSame('9999999999999',$x->evaluate('string(//infoFactura/identificacionComprador)'));self::assertSame('CONSUMIDOR FINAL',$x->evaluate('string(//infoFactura/razonSocialComprador)'));self::assertSame('',$x->evaluate('string(//infoFactura/direccionComprador)'));
        $snap=json_decode($this->doc($f)['snapshot_json'],true);self::assertTrue($snap['factura']['consumidor_final']);self::assertContains('consumidor_final.total_maximo_centavos',array_column($snap['parametros'],'clave'));self::assertTrue(F::verificar($this->doc($f)['xml_firmado']));
    }
    public function testConsumidorFinalSuperiorAlMaximoOSinParametroSeRechazaSinConsumirSecuencial():void
    {
        $cf=['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'x'];
        $this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','valor'=>'30.00']);
        $e=$this->conflicto(fn()=>$this->factura(['comprador'=>$cf]));self::assertStringContainsString('30.00',$e->getMessage());
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);
        self::assertNull($this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE ultimo>0"),'El rechazo no consume número fiscal');
        // El mismo total con comprador identificado sí se emite: el máximo solo limita al consumidor final.
        self::assertGreaterThan(0,$this->factura());
    }
    public function testConsumidorFinalSinParametroParaSuRegimenNoSeEmite():void
    {
        [$p12,$password]=F::certificado();
        $perfil=$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor()+['regimen'=>'rimpe_negocio_popular'],'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        $this->registrar(['clave'=>'regimen.leyenda','regimen'=>'rimpe_negocio_popular','valor'=>'CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE']);
        $this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','regimen'=>'rimpe_negocio_popular','estado'=>'derogado']);
        $e=$this->conflicto(fn()=>$this->fiscal()->factura($this->actor,$this->datos(['perfil_id'=>$perfil,'comprador'=>['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'x']])));self::assertStringContainsString('consumidor_final.total_maximo_centavos',$e->getMessage());
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);
    }
    public function testElGeneradorNuncaProduceConsumidorFinalPorSuCuenta():void
    {
        $d=F::factura();$d['comprador']=['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'CONSUMIDOR FINAL'];
        $this->expectException(\InvalidArgumentException::class);SriComprobante::factura(F::emisor(),$d);
    }
    public function testNotaDeCreditoSobreConsumidorFinalNoSeAdmite():void
    {
        $f=$this->factura(['comprador'=>['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'x']]);$this->autorizar($f);
        (new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$this->venta,'linea_id'=>$this->linea,'cantidad'=>'1','motivo'=>'Producto devuelto','reintegrar_stock'=>'1']));
        $dev=(int)$this->r->uno('SELECT MAX(id) n FROM erp_devoluciones')['n'];
        $e=$this->conflicto(fn()=>$this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'Devolución'])));self::assertStringContainsString('consumidor final',$e->getMessage());
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);
    }
    public function testConsumidorFinalRechazadoSeSustituyeConLasMismasReglas():void
    {
        $f=$this->factura(['comprador'=>['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'x']]);$this->rechazar($f);$this->verificado($f);
        $n=$this->fiscal()->sustituirFactura($this->actor,$this->datos(['documento_id'=>$f,'comprador'=>['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'y']]))['recurso_id'];
        self::assertSame($f,(int)$this->doc($n)['sustituye_a_id']);self::assertSame('CONSUMIDOR FINAL',$this->xml($n)->evaluate('string(//infoFactura/razonSocialComprador)'));
    }
    public function testRegimenRimpeExigeLeyendaParametrizadaYNoAdmiteTextoLibreRepetido():void
    {
        [$p12,$password]=F::certificado();
        $this->conflicto(fn()=>$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor()+['regimen'=>'rimpe_emprendedor','contribuyente_rimpe'=>'Texto libre'],'titular_confirmado'=>'1']),$p12,$password),422);
        $this->conflicto(fn()=>$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor()+['regimen'=>'inventado'],'titular_confirmado'=>'1']),$p12,$password),422);
        $rimpe=$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor()+['regimen'=>'rimpe_emprendedor'],'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        $e=$this->conflicto(fn()=>$this->fiscal()->factura($this->actor,$this->datos(['perfil_id'=>$rimpe])));self::assertStringContainsString('regimen.leyenda',$e->getMessage());
        $this->registrar(['clave'=>'regimen.leyenda','regimen'=>'rimpe_emprendedor','valor'=>'CONTRIBUYENTE RÉGIMEN RIMPE']);
        $f=$this->factura(['perfil_id'=>$rimpe,'idem_key'=>R5CajaFixture::clave()]);
        self::assertSame('CONTRIBUYENTE RÉGIMEN RIMPE',$this->xml($f)->evaluate('string(//infoTributaria/contribuyenteRimpe)'));self::assertTrue(F::verificar($this->doc($f)['xml_firmado']));
        self::assertContains('regimen.leyenda',array_column(json_decode($this->doc($f)['snapshot_json'],true)['parametros'],'clave'));
    }

    // ---- Control previo a transmitir un sustituto ----
    private function sustitutoPendiente():array
    {
        $f=$this->factura();$this->rechazar($f);$this->verificado($f);
        $n=$this->fiscal()->sustituirFactura($this->actor,$this->datos(['documento_id'=>$f,'pagos'=>[['forma'=>'20','total_centavos'=>3450]]]))['recurso_id'];return [$f,$n];
    }
    public function testSustitutoNoSeTransmiteSiElOriginalApareceAutorizadoAntesDeEnviarlo():void
    {
        [$f,$n]=$this->sustitutoPendiente();$o=$this->doc($f);$s=$this->doc($n);$this->debido();
        $t=$this->transporte([$this->sinAutorizacion($s['clave_acceso']),$this->autorizado($o['clave_acceso'],$o['xml_firmado'])]);
        $salida=$this->fiscal()->procesar($t);
        self::assertSame('bloqueada',$this->doc($n)['estado']);self::assertSame(1,$salida['bloqueados']);self::assertCount(2,$t->peticiones,'Solo consultas: el sustituto jamás se recibió en recepción');
        foreach($t->peticiones as [$url])self::assertStringNotContainsString('RecepcionComprobantes',$url);
        $v=$this->r->uno('SELECT * FROM erp_fiscal_verificaciones WHERE documento_id=? ORDER BY id DESC LIMIT 1',[$f]);self::assertSame('autorizado_en_sri',$v['estado']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='fiscal_sustituto_bloqueado' AND entidad_id=?",[$n])['n']);
        self::assertSame('rechazada',$this->doc($f)['estado'],'El estado local del original no se reescribe: queda para conciliación');
        // Un documento bloqueado no vuelve a procesarse ni cambia de estado.
        $t2=$this->transporte([]);$this->debido();$this->fiscal()->procesar($t2);self::assertCount(0,$t2->peticiones);
        try{$this->r->q("UPDATE erp_fiscal_documentos SET estado='pendiente' WHERE id=$n");self::fail('Bloqueado es definitivo.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1644,$e->getCode());}
    }
    public function testSustitutoEsperaSiNoSePuedeReconfirmarElOriginalYLuegoSeTransmite():void
    {
        [$f,$n]=$this->sustitutoPendiente();$o=$this->doc($f);$s=$this->doc($n);$this->debido();
        $t=$this->transporte([$this->sinAutorizacion($s['clave_acceso']),new \RuntimeException('timeout de ensayo')]);$salida=$this->fiscal()->procesar($t);
        self::assertSame('pendiente',$this->doc($n)['estado']);self::assertSame(1,$salida['pendientes']);self::assertCount(2,$t->peticiones,'Sin reconfirmar el original no se transmite');
        $this->debido();
        $t=$this->transporte([$this->sinAutorizacion($s['clave_acceso']),$this->noAutorizado($o['clave_acceso']),self::RECIBIDA]);$this->fiscal()->procesar($t);
        self::assertSame('recibida',$this->doc($n)['estado']);self::assertCount(3,$t->peticiones);
    }
    public function testLaVigenciaDeLaVerificacionSeAjustaPorParametroYAgotaLaVentana():void
    {
        $f=$this->factura();$this->rechazar($f);$this->verificado($f);
        $this->db->query('DROP TRIGGER trg_fiscal_verificacion_fija');
        $hace=fn(int $m)=>date('Y-m-d H:i:s',strtotime($this->r::ahora().' -'.$m.' minutes'));
        $this->r->q('UPDATE erp_fiscal_verificaciones SET verificado_en=? WHERE documento_id=?',[$hace(20),$f]);
        $this->registrar(['clave'=>'sustitucion.verificacion_minutos','valor'=>'15']);
        $e=$this->conflicto(fn()=>$this->fiscal()->sustituirFactura($this->actor,$this->datos(['documento_id'=>$f])));self::assertStringContainsString('15 minutos',$e->getMessage());
        $this->r->q('UPDATE erp_fiscal_verificaciones SET verificado_en=? WHERE documento_id=?',[$hace(10),$f]);
        self::assertGreaterThan(0,$this->fiscal()->sustituirFactura($this->actor,$this->datos(['documento_id'=>$f]))['recurso_id']);
    }

    // ---- Interfaz ----
    private function pagina(array $get):string
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'srv'=>$_SERVER];
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($this->actor);
        $_SESSION=['login'=>true,'id'=>$this->actor,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-reglas'];$_GET=$get;$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/facturacion';
        ob_start();try{\Controllers\ErpController::fiscal(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_SERVER=$anterior['srv'];}
    }
    public function testLaPreparacionOfreceConsumidorFinalConSuMaximoYAvisaDelAnticipo():void
    {
        $html=$this->pagina(['venta_id'=>(string)$this->venta]);
        self::assertStringContainsString('Consumidor final',$html);self::assertStringContainsString('hasta $50.00',$html);
        self::assertStringNotContainsString('saldo anticipado',$html,'Sin prepago aplicado no se muestra el aviso de anticipo');
        $this->registrar(['clave'=>'consumidor_final.total_maximo_centavos','estado'=>'derogado','vigente_desde'=>$this->hoy()]);
        self::assertStringContainsString('no disponible (sin parámetro vigente)',$this->pagina(['venta_id'=>(string)$this->venta]));
    }
    public function testLaFichaDeUnSustitutoBloqueadoExplicaElConflicto():void
    {
        [$f,$n]=$this->sustitutoPendiente();$o=$this->doc($f);$s=$this->doc($n);$this->debido();
        $this->fiscal()->procesar($this->transporte([$this->sinAutorizacion($s['clave_acceso']),$this->autorizado($o['clave_acceso'],$o['xml_firmado'])]));
        $html=$this->pagina(['id'=>(string)$n]);self::assertStringContainsString('no se transmitió',$html);self::assertStringContainsString('bloqueada',$html);
        self::assertStringContainsString('value="bloqueada"',$this->pagina(['desde'=>date('Y-m-01'),'hasta'=>date('Y-m-d')]));
    }
}
