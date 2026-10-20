<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpFiscalService,ErpVentaService,ErpMaestrosService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpDevolucionService,ErpException};
use Interfaces\SriTransporteInterface;
use Tests\Fixtures\Sri\Fixtures as F;

/** Corrección de comprobantes rechazados por sustitución (pruebas del integrador): verificación con el SRI, trazabilidad y topes. */
final class ErpFiscalSustitucionIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $perfil;private int $venta;private int $linea;
    private mixed $appKeyAnterior;private mixed $appKeyEnvAnterior;
    protected function setUp():void
    {
        $this->appKeyAnterior=getenv('APP_KEY');$this->appKeyEnvAnterior=$_ENV['APP_KEY']??null;putenv('APP_KEY=testing-fiscal-master-key-32-characters');$_ENV['APP_KEY']='testing-fiscal-master-key-32-characters';
        $this->db=BaseDatosAislada::crear('fiscal_sust');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->actor=R5CajaFixture::usuario($this->db,'propietario');
        [$p12,$password]=F::certificado();$this->perfil=$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor(),'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('SU-1','Champú','unidad',10000,500)");$producto=(int)$this->db->insert_id;
        $c=(new ErpMaestrosService($this->r))->catalogo($this->actor,$this->d(['origen'=>'producto','origen_id'=>$producto,'precio'=>'10.00','impuesto'=>'15.00','tipo'=>'venta']))['recurso_id'];
        $ventas=new ErpVentaService($this->r);$this->venta=$ventas->crear($this->actor,$this->d())['recurso_id'];$ventas->agregar($this->actor,$this->d(['venta_id'=>$this->venta,'catalogo_id'=>$c,'cantidad'=>'3']));
        $ventas->confirmar($this->actor,$this->d(['venta_id'=>$this->venta,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$this->venta])['version']]));
        (new ErpCajaService($this->r))->abrir($this->actor,$this->d(['fondo'=>'0']));(new ErpPagoService($this->r))->cobrar($this->actor,$this->d(['venta_id'=>$this->venta,'monto'=>'34.50','recibido'=>'34.50','metodo'=>'efectivo']));
        (new ErpEntregaService($this->r))->entregar($this->actor,$this->d(['venta_id'=>$this->venta]));
        $this->linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$this->venta])['id'];
    }
    protected function tearDown():void {try{BaseDatosAislada::destruir($this->db);}finally{putenv($this->appKeyAnterior===false?'APP_KEY':'APP_KEY='.$this->appKeyAnterior);if($this->appKeyEnvAnterior===null)unset($_ENV['APP_KEY']);else $_ENV['APP_KEY']=$this->appKeyEnvAnterior;}}
    private function fiscal():ErpFiscalService {return new ErpFiscalService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private const SOAP_A='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante>';
    private const SOAP_B='</RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
    private function sinAutorizacion(string $clave):string {return self::SOAP_A.'<claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>0</numeroComprobantes><autorizaciones/>'.self::SOAP_B;}
    private function autorizado(string $clave,string $xml):string {return self::SOAP_A.'<claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>'.$clave.'</numeroAutorizacion><fechaAutorizacion>2026-10-19T10:00:00-05:00</fechaAutorizacion><ambiente>PRUEBAS</ambiente><comprobante><![CDATA['.$xml.']]></comprobante><mensajes/></autorizacion></autorizaciones>'.self::SOAP_B;}
    private function noAutorizado(string $clave):string {return self::SOAP_A.'<claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>NO AUTORIZADO</estado><mensajes><mensaje><identificador>39</identificador><mensaje>Firma inválida de ensayo</mensaje><informacionAdicional>detalle</informacionAdicional><tipo>ERROR</tipo></mensaje></mensajes></autorizacion></autorizaciones>'.self::SOAP_B;}
    private function devuelta(string $clave):string {return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:validarComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.recepcion"><RespuestaRecepcionComprobante><estado>DEVUELTA</estado><comprobantes><comprobante><claveAcceso>'.$clave.'</claveAcceso><mensajes><mensaje><identificador>35</identificador><mensaje>Devuelta de ensayo</mensaje><tipo>ERROR</tipo></mensaje></mensajes></comprobante></comprobantes></RespuestaRecepcionComprobante></ns2:validarComprobanteResponse></soap:Body></soap:Envelope>';}
    private function transporte(array $respuestas):SriTransporteInterface
    {return new class($respuestas,$this->db) implements SriTransporteInterface {public array $peticiones=[];public function __construct(private array $respuestas,private \mysqli $db){}public function post(string $url,string $xml):string {if(\Classes\Transaccion::nivel($this->db)!==0)throw new \LogicException('Red bajo transacción SQL');$this->peticiones[]=[$url,$xml];$r=array_shift($this->respuestas);if($r instanceof \Throwable)throw $r;if(!is_string($r))throw new \LogicException('Petición inesperada');return $r;}};}
    private function debido():void {$this->r->q('UPDATE erp_fiscal_documentos SET proximo_intento=NULL,lease_hasta=NULL');$this->r->q('UPDATE erp_fiscal_verificaciones SET proximo_intento=NULL,lease_hasta=NULL');}
    private function doc(int $id):array {return $this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$id]);}
    private function facturaPreparada():int
    {
        return $this->fiscal()->factura($this->actor,$this->d(['perfil_id'=>$this->perfil,'venta_id'=>$this->venta,'comprador'=>F::factura()['comprador'],'categorias_iva'=>[(string)$this->linea=>'4'],'pagos'=>[['forma'=>'01','total_centavos'=>3450]]]))['recurso_id'];
    }
    private function autorizar(int $id):void
    {
        $d=$this->doc($id);$this->debido();$this->fiscal()->procesar($this->transporte([$this->autorizado($d['clave_acceso'],$d['xml_firmado'])]));self::assertSame('autorizada',$this->doc($id)['estado']);
    }
    /** Lleva el documento a «rechazada» por el camino indicado (consulta NO AUTORIZADO, o recepción DEVUELTA). */
    private function rechazar(int $id,bool $porRecepcion=false):void
    {
        $d=$this->doc($id);$this->debido();
        $this->fiscal()->procesar($this->transporte($porRecepcion?[$this->sinAutorizacion($d['clave_acceso']),$this->devuelta($d['clave_acceso'])]:[$this->noAutorizado($d['clave_acceso'])]));
        self::assertSame('rechazada',$this->doc($id)['estado']);
    }
    private function verificar(int $id,string|\Throwable $respuesta,bool $solicitar=true):array
    {
        if($solicitar)$this->fiscal()->solicitarVerificacion($this->actor,$this->d(['documento_id'=>$id]));$this->debido();
        $this->fiscal()->verificar($this->transporte([$respuesta]));return $this->r->uno('SELECT * FROM erp_fiscal_verificaciones WHERE documento_id=? ORDER BY id DESC LIMIT 1',[$id]);
    }
    private function datosFactura(int $id,array $extra=[]):array {return $this->d(['documento_id'=>$id,'perfil_id'=>$this->perfil,'comprador'=>F::factura()['comprador'],'categorias_iva'=>[(string)$this->linea=>'4'],'pagos'=>[['forma'=>'20','total_centavos'=>3450]]]+$extra);}
    private function devolver(string $cantidad):int
    {
        (new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$this->venta,'linea_id'=>$this->linea,'cantidad'=>$cantidad,'motivo'=>'Producto devuelto','reintegrar_stock'=>'1']));return (int)$this->r->uno('SELECT MAX(id) n FROM erp_devoluciones')['n'];
    }
    private function conEvidencia(array $d):array {return [$d['xml_firmado'],$d['xml_sha256'],$d['clave_acceso'],$d['numero'],$d['respuesta_json'],$d['estado'],$d['snapshot_json']];}

    public function testFacturaRechazadaSeConservaYSoloSeSustituyeTrasVerificarConElSri():void
    {
        $f=$this->facturaPreparada();$this->rechazar($f);$antes=$this->conEvidencia($this->doc($f));
        try{$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f));self::fail('Sin verificación.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        // El SRI reporta AUTORIZADO aunque localmente figure rechazada: conflicto, nunca sustitución.
        $v=$this->verificar($f,$this->autorizado($this->doc($f)['clave_acceso'],$this->doc($f)['xml_firmado']));self::assertSame('autorizado_en_sri',$v['estado']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='fiscal_conflicto_estado'")['n']);
        try{$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f));self::fail('Autorizado en el SRI.');}catch(ErpException $e){self::assertSame(409,$e->getCode());self::assertStringContainsString('autorizado',$e->getMessage());}
        // Respuesta incierta (red caída): tampoco; se reintenta con la misma clave.
        $v=$this->verificar($f,new \RuntimeException('timeout de ensayo'));self::assertSame('incierta',$v['estado']);
        try{$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f));self::fail('Incierta.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        // El SRI sigue sin autorizarla y la consulta distingue «no autorizado» de «sin registro» cuando fue devuelta en recepción.
        $v=$this->verificar($f,$this->noAutorizado($this->doc($f)['clave_acceso']),false);self::assertSame('confirmado',$v['estado']);
        $nuevo=$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f))['recurso_id'];
        $n=$this->doc($nuevo);$r=$this->doc($f);
        self::assertSame($antes,$this->conEvidencia($r),'El rechazado conserva XML, clave, número, respuesta, estado e instantánea');
        self::assertSame($f,(int)$n['sustituye_a_id']);self::assertSame('01',$n['tipo']);self::assertSame('pendiente',$n['estado']);self::assertNotSame($r['clave_acceso'],$n['clave_acceso']);self::assertNotSame($r['numero'],$n['numero']);self::assertSame('001-002-000000002',$n['numero']);self::assertTrue(F::verificar($n['xml_firmado']));
        self::assertSame(2,(int)$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE documento='01'")['ultimo']);
        // Una sola sustituta por rechazado, y la sustituta autorizada se comporta como cualquier factura.
        try{$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f));self::fail('Ya sustituido.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        try{$this->fiscal()->solicitarVerificacion($this->actor,$this->d(['documento_id'=>$f]));self::fail('Ya sustituido.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $this->autorizar($nuevo);self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='fiscal_sustitucion_preparada'")['n']);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_devoluciones')['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n'],'Sustituir un comprobante no mueve dinero');
    }
    public function testRechazoEnRecepcionSeConfirmaSinRegistroDelSri():void
    {
        $a=$this->facturaPreparada();$this->rechazar($a,true);self::assertSame('DEVUELTA',json_decode($this->doc($a)['respuesta_json'],true)['estado']);
        $v=$this->verificar($a,$this->sinAutorizacion($this->doc($a)['clave_acceso']));self::assertSame('confirmado',$v['estado'],'Devuelta en recepción y sin autorización registrada: rechazo confirmado');
        $this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($a));
    }
    public function testUnNoAutorizadoQueElSriYaNoDevuelveEsIncierto():void
    {
        $a=$this->facturaPreparada();$this->rechazar($a);
        $v=$this->verificar($a,$this->sinAutorizacion($this->doc($a)['clave_acceso']));self::assertSame('incierta',$v['estado'],'Rechazado por autorización y ahora sin registro: no se da por bueno');
        try{$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($a));self::fail('Incierta.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertNotNull($v['proximo_intento'],'Se reintenta la misma clave');
    }
    public function testNoSeSustituyeLoQueNoEstaRechazadoNiSeVerificaLoQueSigueEnProceso():void
    {
        $f=$this->facturaPreparada();
        foreach(['pendiente'] as $e){try{$this->fiscal()->solicitarVerificacion($this->actor,$this->d(['documento_id'=>$f]));self::fail($e);}catch(ErpException $x){self::assertSame(409,$x->getCode());}}
        $this->autorizar($f);
        try{$this->fiscal()->solicitarVerificacion($this->actor,$this->d(['documento_id'=>$f]));self::fail('Autorizada.');}catch(ErpException $x){self::assertSame(409,$x->getCode());}
        try{$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f));self::fail('Autorizada.');}catch(ErpException $x){self::assertSame(409,$x->getCode());}
    }
    public function testLaVerificacionCaducaPermisosYSolicitudUnica():void
    {
        $f=$this->facturaPreparada();$this->rechazar($f);
        $id1=$this->fiscal()->solicitarVerificacion($this->actor,$this->d(['documento_id'=>$f]))['recurso_id'];$id2=$this->fiscal()->solicitarVerificacion($this->actor,$this->d(['documento_id'=>$f]))['recurso_id'];
        self::assertSame($id1,$id2,'Una sola solicitud abierta por comprobante');
        $recepcion=R5CajaFixture::usuario($this->db,'recepcion');$cliente=R5CajaFixture::usuario($this->db);
        foreach([$recepcion,$cliente] as $sin){
            try{$this->fiscal()->solicitarVerificacion($sin,$this->d(['documento_id'=>$f]));self::fail('Permiso.');}catch(ErpException $e){self::assertSame(403,$e->getCode());}
            try{$this->fiscal()->sustituirFactura($sin,$this->datosFactura($f));self::fail('Permiso.');}catch(ErpException $e){self::assertSame(403,$e->getCode());}
            try{$this->fiscal()->sustituirNotaCredito($sin,$this->d(['documento_id'=>$f]));self::fail('Permiso.');}catch(ErpException $e){self::assertSame(403,$e->getCode());}
        }
        $this->debido();$this->fiscal()->verificar($this->transporte([$this->noAutorizado($this->doc($f)['clave_acceso'])]));
        // Una tabla de evidencia: ni se borra ni se reescribe un resultado cerrado.
        foreach(["DELETE FROM erp_fiscal_verificaciones","UPDATE erp_fiscal_verificaciones SET estado='autorizado_en_sri'","UPDATE erp_fiscal_verificaciones SET documento_id=documento_id+1"] as $sql){try{$this->r->q($sql);self::fail($sql);}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1644,$e->getCode());}}
        // Para simular el paso del tiempo se retira la protección SOLO en esta base aislada (la propia protección ya se comprobó arriba).
        $this->db->query('DROP TRIGGER trg_fiscal_verificacion_fija');$this->r->q('UPDATE erp_fiscal_verificaciones SET verificado_en=?',[date('Y-m-d H:i:s',strtotime($this->r::ahora().' -3 hours'))]);
        try{$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f));self::fail('Caducada.');}catch(ErpException $e){self::assertSame(409,$e->getCode());self::assertStringContainsString('caducó',$e->getMessage());}
    }
    public function testNotaRechazadaSeSustituyeHeredandoDevolucionesSinAcreditarlasDosVeces():void
    {
        $f=$this->facturaPreparada();$this->autorizar($f);$d1=$this->devolver('1');$d2=$this->devolver('1');
        $n1=$this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$d1],'motivo'=>'Devolución de producto']))['recurso_id'];$this->rechazar($n1);$antes=$this->conEvidencia($this->doc($n1));
        // Mientras exista el rechazo, la devolución sigue reservada: una nota nueva e independiente no puede volver a acreditarla.
        try{$this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$d1],'motivo'=>'Otra vez']));self::fail('Doble acreditación.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        try{$this->fiscal()->sustituirNotaCredito($this->actor,$this->d(['documento_id'=>$n1,'motivo'=>'Corregida']));self::fail('Sin verificación.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $v=$this->verificar($n1,$this->noAutorizado($this->doc($n1)['clave_acceso']));self::assertSame('confirmado',$v['estado']);
        // No se puede «heredar» otra vez una devolución de la cadena como si fuera adicional.
        try{$this->fiscal()->sustituirNotaCredito($this->actor,$this->d(['documento_id'=>$n1,'devolucion_ids'=>[$d1],'motivo'=>'x']));self::fail('Ya pertenece a la nota.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $s=$this->fiscal()->sustituirNotaCredito($this->actor,$this->d(['documento_id'=>$n1,'devolucion_ids'=>[$d2],'motivo'=>'Devolución de producto corregida']))['recurso_id'];
        $n=$this->doc($s);self::assertSame($antes,$this->conEvidencia($this->doc($n1)));self::assertSame('04',$n['tipo']);self::assertSame($n1,(int)$n['sustituye_a_id']);self::assertSame($f,(int)$n['documento_origen_id']);
        $xp=new \DOMXPath(\Services\Fiscal\SriXml::validar($n['xml_firmado']));self::assertSame('23.00',$xp->evaluate('string(/notaCredito/infoNotaCredito/valorModificacion)'),'La sustituta acredita las dos devoluciones (heredada + adicional)');self::assertSame('2.000',$xp->evaluate('string(/notaCredito/detalles/detalle/cantidad)'));
        self::assertSame([$n1=>[$d1],$s=>[$d2]],$this->vinculos(),'Cada devolución queda vinculada una sola vez; la herencia se resuelve por la cadena');
        self::assertSame(2300,(int)$this->r->uno("SELECT SUM(l.total_centavos) t FROM erp_fiscal_nc_lineas l JOIN erp_fiscal_documentos x ON x.id=l.documento_id WHERE x.estado<>'rechazada'")['t'],'La nota rechazada no cuenta contra los topes');
        try{$this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$d2],'motivo'=>'Doble']));self::fail('Doble acreditación.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        try{$this->fiscal()->sustituirNotaCredito($this->actor,$this->d(['documento_id'=>$n1,'motivo'=>'Segunda sustituta']));self::fail('Ya sustituida.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $this->autorizar($s);
        // Dinero, inventario y comisiones: intactos por la cadena fiscal (las devoluciones comerciales ya ocurrieron una vez).
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_devoluciones')['n']);self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM cobros_devoluciones')['n']);
    }
    /** @return array<int,list<int>> */
    private function vinculos():array {$r=[];foreach($this->r->q('SELECT documento_id,devolucion_id FROM erp_fiscal_nc_devoluciones ORDER BY documento_id,devolucion_id') as $x)$r[(int)$x['documento_id']][]=(int)$x['devolucion_id'];return $r;}
    public function testDosProcesosNoSustituyenDosVecesElMismoRechazo():void
    {
        $f=$this->facturaPreparada();$this->rechazar($f);$this->verificar($f,$this->noAutorizado($this->doc($f)['clave_acceso']));
        $workers=[];$resultados=[];$datos=$this->datosFactura($f);
        try{
            foreach([0,1] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php','sustituir_factura',(string)$this->actor,json_encode(['idem_key'=>R5CajaFixture::clave()]+$datos)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],30);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        }finally{foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        self::assertSame(1,count(array_filter($resultados,fn($r)=>$r['ok'])),json_encode($resultados));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE sustituye_a_id IS NOT NULL')['n']);self::assertSame(2,(int)$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE documento='01'")['ultimo']);
    }
    public function testElEsquemaGarantizaUnaSolaSustitutaYConservaLaFacturaRaizUnica():void
    {
        $f=$this->facturaPreparada();$this->rechazar($f);$this->verificar($f,$this->noAutorizado($this->doc($f)['clave_acceso']));$s=$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f))['recurso_id'];
        $copiar=fn(string $sust,string $num,string $clave,string $op)=>$this->r->q("INSERT INTO erp_fiscal_documentos(perfil_id,sustituye_a_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) SELECT perfil_id,$sust,venta_id,'$op',huella,'$clave','$num',snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en FROM erp_fiscal_documentos WHERE id=$f");
        foreach([['NULL','001-002-000000050',1],[(string)$f,'001-002-000000051',2],[(string)$s,'001-002-000000052',3]] as [$sust,$num,$i]){
            try{if($sust===(string)$s){ $this->r->q("UPDATE erp_fiscal_documentos SET estado='rechazada' WHERE id=$s"); }$copiar($sust,$num,str_pad((string)$i,49,'8'),str_pad((string)$i,64,'c'));if($sust!==(string)$s)self::fail('Debe rechazarse '.$sust);}
            catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertContains($e->getCode(),[1062,1644],$e->getMessage());}
        }
        try{$this->r->q("UPDATE erp_fiscal_documentos SET sustituye_a_id=NULL WHERE id=$s");self::fail('Inmutable.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1644,$e->getCode());}
        $guard=require __DIR__.'/../../database/migrations/041_erp_fiscal_sustitucion.guard.php';self::assertNotEmpty($guard($this->db));
    }
    public function testFichasMuestranVerificacionSustitucionYTrazabilidad():void
    {
        $f=$this->facturaPreparada();$this->rechazar($f);
        $anterior=['sesion'=>$_SESSION??[],'get'=>$_GET,'server'=>$_SERVER];
        $pagina=function(int $id)use(&$router){$ctx=(new \Services\AuthorizationService($this->db))->contexto($this->actor);$_SESSION=['login'=>true,'id'=>$this->actor,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion']];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/gestion/facturacion';$_GET=['id'=>(string)$id];ob_start();try{\Controllers\ErpController::fiscal($router);$h=ob_get_contents();}finally{ob_end_clean();}return $h;};
        try{
            if(session_status()===PHP_SESSION_NONE)session_start();$router=new \MVC\Router();
            $h=$pagina($f);self::assertStringContainsString('Verificar estado con el SRI',$h);self::assertStringNotContainsString('Preparar y firmar factura sustituta',$h);
            $this->verificar($f,$this->noAutorizado($this->doc($f)['clave_acceso']));
            $h=$pagina($f);self::assertStringContainsString('Rechazo confirmado con el SRI',$h);self::assertStringContainsString('Preparar y firmar factura sustituta',$h);
            $s=$this->fiscal()->sustituirFactura($this->actor,$this->datosFactura($f))['recurso_id'];
            $h=$pagina($f);self::assertStringContainsString('Fue sustituido por',$h);self::assertStringNotContainsString('Preparar y firmar factura sustituta',$h);
            $h=$pagina($s);self::assertStringContainsString('Este documento sustituye al',$h);
        }finally{$_SESSION=$anterior['sesion'];$_GET=$anterior['get'];$_SERVER=$anterior['server'];}
    }
}
