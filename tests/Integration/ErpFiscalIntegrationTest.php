<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpFiscalService,ErpVentaService,ErpMaestrosService,ErpException};
use Interfaces\SriTransporteInterface;
use Tests\Fixtures\Sri\Fixtures as F;

final class ErpFiscalIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $perfil;private int $venta;private int $linea;private int $cita;
    private mixed $appKeyAnterior;private mixed $appKeyEnvAnterior;
    protected function setUp():void
    {
        $this->appKeyAnterior=getenv('APP_KEY');$this->appKeyEnvAnterior=$_ENV['APP_KEY']??null;putenv('APP_KEY=testing-fiscal-master-key-32-characters');$_ENV['APP_KEY']='testing-fiscal-master-key-32-characters';
        $this->db=BaseDatosAislada::crear('fiscal');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->actor=R5CajaFixture::usuario($this->db,'propietario');$cliente=R5CajaFixture::usuario($this->db);
        [$p12,$password]=F::certificado();$this->perfil=$this->svc()->perfil($this->actor,$this->d(['emisor'=>F::emisor(),'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        $this->cita=R5CajaFixture::cita($this->db,$cliente,null,['20.00'],'completada',substr($this->r::ahora(),0,10));
        $this->venta=(new ErpVentaService($this->r))->crear($this->actor,$this->d(['cita_id'=>$this->cita]))['recurso_id'];
        (new ErpVentaService($this->r))->confirmar($this->actor,$this->d(['venta_id'=>$this->venta,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$this->venta])['version']]));
        $this->linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$this->venta])['id'];
    }
    protected function tearDown():void {try{BaseDatosAislada::destruir($this->db);}finally{putenv($this->appKeyAnterior===false?'APP_KEY':'APP_KEY='.$this->appKeyAnterior);if($this->appKeyEnvAnterior===null)unset($_ENV['APP_KEY']);else $_ENV['APP_KEY']=$this->appKeyEnvAnterior;}}
    private function svc():ErpFiscalService {return new ErpFiscalService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function datos():array {return $this->d(['perfil_id'=>$this->perfil,'venta_id'=>$this->venta,'comprador'=>F::factura()['comprador'],'categorias_iva'=>[(string)$this->linea=>'0'],'pagos'=>[['forma'=>'20','total_centavos'=>2000]]]);}
    private function documento():array {$this->svc()->factura($this->actor,$this->datos());return $this->r->uno('SELECT * FROM erp_fiscal_documentos');}
    private function debido():void {$this->r->q('UPDATE erp_fiscal_documentos SET proximo_intento=NULL,lease_hasta=NULL');}
    private function respuesta(string $clave,?string $xml=null):string
    {
        $a=$xml===null?'':'<autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>'.$clave.'</numeroAutorizacion><fechaAutorizacion>2026-10-19T10:00:00-05:00</fechaAutorizacion><ambiente>PRUEBAS</ambiente><comprobante><![CDATA['.$xml.']]></comprobante><mensajes/></autorizacion>';
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante><claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>'.($xml===null?'0':'1').'</numeroComprobantes><autorizaciones>'.$a.'</autorizaciones></RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
    }
    private function transporte(array $respuestas):SriTransporteInterface
    {return new class($respuestas,$this->db) implements SriTransporteInterface {public array $peticiones=[];public function __construct(private array $respuestas,private \mysqli $db){}public function post(string $url,string $xml):string {if(\Classes\Transaccion::nivel($this->db)!==0)throw new \LogicException('Red bajo transacción SQL');$this->peticiones[]=[$url,$xml];$r=array_shift($this->respuestas);if($r instanceof \Throwable)throw $r;if(!is_string($r))throw new \LogicException('Petición inesperada');return $r;}};}
    public function testPreparaUnaVezSinAutorizarNiDuplicarCobroYReenvioPermanente():void
    {
        $d=$this->datos();$id=$this->svc()->factura($this->actor,$d)['recurso_id'];$this->r->q('UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');self::assertSame($id,$this->svc()->factura($this->actor,$d)['recurso_id']);
        $doc=$this->r->uno('SELECT * FROM erp_fiscal_documentos');self::assertSame('pendiente',$doc['estado']);self::assertSame('001-002-000000001',$doc['numero']);self::assertTrue(F::verificar($doc['xml_firmado']));self::assertSame(hash('sha256',$doc['xml_firmado']),$doc['xml_sha256']);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);self::assertSame(1,(int)$this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);
    }
    public function testOtraClaveParaLaMismaVentaNoCreaOtraFactura():void {$this->documento();$this->expectException(ErpException::class);$this->svc()->factura($this->actor,$this->datos());}
    public function testPerfilNoGuardaClaveNiPkcs12EnClaro():void
    {
        [$p12,$pwd]=F::certificado();$p=$this->r->uno('SELECT * FROM erp_fiscal_perfiles');self::assertStringNotContainsString($pwd,$p['certificado_cifrado']);self::assertStringNotContainsString(base64_encode($p12),$p['certificado_cifrado']);self::assertSame(hash('sha256',$p12),$p['certificado_sha256']);self::assertStringNotContainsString($pwd,json_encode($this->r->q('SELECT datos_json FROM erp_eventos')));
    }
    public function testErrorAntesDeFirmarRevierteSecuenciaYDocumento():void
    {
        $d=$this->datos();$d['pagos'][0]['total_centavos']=1999;try{$this->svc()->factura($this->actor,$d);self::fail('Importes');}catch(\InvalidArgumentException){self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_secuencias')['n']);}
    }
    public function testNoFirmaUnaVisitaSinAtender():void
    {
        $cliente=R5CajaFixture::usuario($this->db);$cita=R5CajaFixture::cita($this->db,$cliente,null,['20.00']);$venta=(new ErpVentaService($this->r))->crear($this->actor,$this->d(['cita_id'=>$cita]))['recurso_id'];
        (new ErpVentaService($this->r))->confirmar($this->actor,$this->d(['venta_id'=>$venta,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$venta])['version']]));
        $d=$this->datos();$d['venta_id']=$venta;$linea=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$venta])['id'];$d['categorias_iva']=[(string)$linea=>'0'];$this->expectException(ErpException::class);$this->svc()->factura($this->actor,$d);
    }
    public function testNoHabilitaProduccionNiPermiteConfigurarARecepcion():void
    {
        [$p,$pwd]=F::certificado();$e=F::emisor();$e['ambiente']='2';try{$this->svc()->perfil($this->actor,$this->d(['emisor'=>$e,'titular_confirmado'=>'1']),$p,$pwd);self::fail('Producción');}catch(ErpException $x){self::assertStringContainsString('pruebas',$x->getMessage());}
        $recepcion=R5CajaFixture::usuario($this->db,'recepcion');$this->expectException(ErpException::class);$this->svc()->perfil($recepcion,$this->d(['emisor'=>F::emisor(),'titular_confirmado'=>'1']),$p,$pwd);
    }
    public function testConsultaAntesDeRecibirYPersisteEstadoSinDarAutorizacionPorRecepcion():void
    {
        $d=$this->documento();$rec='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:validarComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.recepcion"><RespuestaRecepcionComprobante><estado>RECIBIDA</estado><comprobantes/></RespuestaRecepcionComprobante></ns2:validarComprobanteResponse></soap:Body></soap:Envelope>';
        $t=$this->transporte([$this->respuesta($d['clave_acceso']),$rec]);self::assertSame(['autorizados'=>0,'rechazados'=>0,'pendientes'=>1],$this->svc()->procesar($t));self::assertStringContainsString('Autorizacion',$t->peticiones[0][0]);self::assertStringContainsString('Recepcion',$t->peticiones[1][0]);self::assertSame('recibida',$this->r->uno('SELECT estado FROM erp_fiscal_documentos')['estado']);
        $this->debido();$t=$this->transporte([$this->respuesta($d['clave_acceso'],$d['xml_firmado'])]);self::assertSame(1,$this->svc()->procesar($t)['autorizados']);self::assertCount(1,$t->peticiones);self::assertSame('autorizada',$this->r->uno('SELECT estado FROM erp_fiscal_documentos')['estado']);self::assertSame(0,$this->svc()->procesar($t)['pendientes']);self::assertCount(1,$t->peticiones);
    }
    public function testTimeoutNuncaCambiaClaveXmlNiSecuenciaYRecuperaAutorizacionSinReenviar():void
    {
        $d=$this->documento();$t=$this->transporte([new \RuntimeException('timeout de ensayo')]);self::assertSame(1,$this->svc()->procesar($t)['pendientes']);$this->debido();$t=$this->transporte([$this->respuesta($d['clave_acceso'],$d['xml_firmado'])]);self::assertSame(1,$this->svc()->procesar($t)['autorizados']);self::assertCount(1,$t->peticiones);$despues=$this->r->uno('SELECT * FROM erp_fiscal_documentos');self::assertSame($d['xml_firmado'],$despues['xml_firmado']);self::assertSame($d['clave_acceso'],$despues['clave_acceso']);self::assertSame(1,(int)$this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);
    }
    public function testDescargaAutorizadaExigeEvidenciaYNoGeneraMovimientos():void
    {
        $d=$this->documento();
        self::assertSame($d['xml_firmado'],$this->svc()->descargar($this->actor,(int)$d['id'])['contenido']);
        try{$this->svc()->descargar($this->actor,(int)$d['id'],true);self::fail('Pendiente no es autorizado.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $this->svc()->procesar($this->transporte([$this->respuesta($d['clave_acceso'],$d['xml_firmado'])]));
        $antes=$this->r->uno('SELECT COUNT(*) n FROM erp_eventos')['n'];
        $archivo=$this->svc()->descargar($this->actor,(int)$d['id'],true);
        $xml=\Services\Fiscal\SriXml::cargar($archivo['contenido']);
        self::assertSame('pruebas-sri-autorizado-001-002-000000001.xml',$archivo['nombre']);
        self::assertSame($d['clave_acceso'],$xml->getElementsByTagName('numeroAutorizacion')->item(0)->textContent);
        self::assertSame($d['xml_firmado'],$xml->getElementsByTagName('comprobante')->item(0)->textContent);
        self::assertSame($antes,$this->r->uno('SELECT COUNT(*) n FROM erp_eventos')['n']);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
        self::assertSame(1,(int)$this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);
        try{$this->r->q('UPDATE erp_fiscal_documentos SET respuesta_json=NULL');self::fail('Conserve evidencia autorizada.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1644,$e->getCode());}
        $cliente=R5CajaFixture::usuario($this->db);$this->expectException(ErpException::class);$this->expectExceptionCode(403);$this->svc()->descargar($cliente,(int)$d['id'],true);
    }
    public function testControladorEntregaLosDosXmlAlEquipoAutorizado():void
    {
        $datos=$this->datos();$datos['comprador']['nombre']='Comprador <script>alert(1)</script>';$this->svc()->factura($this->actor,$datos);$d=$this->r->uno('SELECT * FROM erp_fiscal_documentos');
        try{$this->svc()->ride($this->actor,(int)$d['id']);self::fail('No hay RIDE autorizado pendiente.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $this->svc()->procesar($this->transporte([$this->respuesta($d['clave_acceso'],$d['xml_firmado'])]));
        $anterior=['sesion'=>$_SESSION??[],'get'=>$_GET,'server'=>$_SERVER];
        try{
            if(session_status()===PHP_SESSION_NONE)session_start();
            $ctx=(new \Services\AuthorizationService($this->db))->contexto($this->actor);
            $_SESSION=['login'=>true,'id'=>$this->actor,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion']];
            $_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_ACCEPT']='application/xml';$_SERVER['REQUEST_URI']='/gestion/facturacion/xml';
            foreach(['firmado'=>false,'autorizado'=>true] as $formato=>$autorizado){
                $_GET=['id'=>(string)$d['id'],'formato'=>$formato];
                ob_start();try{\Controllers\ErpController::fiscalXml();$contenido=ob_get_contents();}finally{ob_end_clean();}
                self::assertSame($this->svc()->descargar($this->actor,(int)$d['id'],$autorizado)['contenido'],$contenido);
            }
            $this->r->q("UPDATE servicios SET nombre='Catálogo cambiado',precio=999 WHERE id IN(SELECT servicioId FROM citasservicios WHERE citaId=?)",[$this->cita]);
            $original=$this->svc()->ride($this->actor,(int)$d['id']);self::assertSame('20.00',$original['factura']['importeTotal']);self::assertStringNotContainsString('Catálogo cambiado',json_encode($original));
            ob_start();try{\Controllers\ErpController::fiscalRide(new \MVC\Router());$html=ob_get_contents();}finally{ob_end_clean();}
            self::assertStringContainsString('SIN VALIDEZ TRIBUTARIA',$html);self::assertStringContainsString('Comprador &lt;script&gt;alert(1)&lt;/script&gt;',$html);self::assertStringNotContainsString('<script>alert(1)</script>',$html);self::assertStringContainsString('data-erp-imprimir',$html);
        }finally{$_SESSION=$anterior['sesion'];$_GET=$anterior['get'];$_SERVER=$anterior['server'];}
    }
    public function testLeaseVigenteNoHacePeticiones():void {$this->documento();$this->r->q('UPDATE erp_fiscal_documentos SET lease_token=?,lease_hasta=DATE_ADD(?,INTERVAL 1 HOUR)',[str_repeat('a',32),$this->r::ahora()]);$t=$this->transporte([]);self::assertSame(0,$this->svc()->procesar($t)['pendientes']);self::assertSame([],$t->peticiones);}
    public function testEntregaFiscalCongelaAdjuntosDeduplicaYReintentaFueraDeLaTransaccion():void
    {
        $doc=$this->documento();$servicio=new \Services\ErpFiscalCorreoService($this->r);
        $datos=$this->d(['documento_id'=>$doc['id'],'email'=>'destino@ensayo.test','confirmar_destinatario'=>'1']);
        try{$servicio->programar($this->actor,$datos);self::fail('Falta autorización.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM correos_salida')['n']);
        $this->svc()->procesar($this->transporte([$this->respuesta($doc['clave_acceso'],$doc['xml_firmado'])]));
        $anterior=\Classes\Email::getTransport();$capturados=[];
        \Classes\Email::setTransport(function($mail)use(&$capturados){self::assertSame(0,\Classes\Transaccion::nivel($this->db));$capturados[]=$mail;return count($capturados)>1;});
        try{
            $servicio->programar($this->actor,$datos);$cola=$this->r->uno('SELECT * FROM correos_salida');
            $carga=\Classes\CargaCifrada::descifrar($cola['carga_cifrada']);self::assertStringStartsWith('%PDF-',base64_decode($carga['pdf']));self::assertStringNotContainsString($carga['pdf'],$cola['carga_cifrada']);
            self::assertCount(0,$capturados,'Programar no toca SMTP.');
            $this->r->q('UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');$servicio->programar($this->actor,$datos);
            $repetido=$datos;$repetido['idem_key']=R5CajaFixture::clave();$repetido['email']='DESTINO@ensayo.test';$servicio->programar($this->actor,$repetido);
            self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM correos_salida')['n']);
            self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='fiscal_entrega_programada'")['n']);
            $worker=new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository($this->db));
            self::assertFalse($worker->despachar((int)$cola['id']));self::assertSame('pendiente',$this->r->uno('SELECT estado FROM correos_salida')['estado']);
            // La reactivación explícita utiliza el mismo evento y los mismos adjuntos.
            $this->r->q("UPDATE correos_salida SET estado='fallido',intentos=6 WHERE id=?",[(int)$cola['id']]);
            $servicio->reintentar($this->actor,$this->d(['documento_id'=>$doc['id'],'correo_id'=>$cola['id']]));
            self::assertTrue($worker->despachar((int)$cola['id']));self::assertFalse($worker->despachar((int)$cola['id']));
            self::assertCount(2,$capturados);self::assertSame($capturados[0]->MessageID,$capturados[1]->MessageID);
            foreach($capturados as $mail){$adjuntos=$mail->getAttachments();self::assertCount(2,$adjuntos);self::assertSame(base64_decode($carga['xml']),$adjuntos[0][0]);self::assertSame(base64_decode($carga['pdf']),$adjuntos[1][0]);self::assertSame('application/xml',$adjuntos[0][4]);self::assertSame('application/pdf',$adjuntos[1][4]);self::assertStringContainsString('SIN VALIDEZ TRIBUTARIA',$mail->AltBody);}
            $final=$this->r->uno('SELECT estado,carga_cifrada FROM correos_salida');self::assertSame('enviado',$final['estado']);self::assertNull($final['carga_cifrada']);
            self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);self::assertSame(1,(int)$this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);
            $guard=require __DIR__.'/../../database/migrations/039_fiscal_correo_adjuntos.guard.php';self::assertNotEmpty($guard($this->db));
        }finally{\Classes\Email::setTransport($anterior);\Classes\Email::limpiarEmailsEnviados();}
    }
    public function testEntregaNoConfiaEnDestinoCambiadoNiPermiteProgramarAUnCliente():void
    {
        $doc=$this->documento();$this->svc()->procesar($this->transporte([$this->respuesta($doc['clave_acceso'],$doc['xml_firmado'])]));$svc=new \Services\ErpFiscalCorreoService($this->r);
        $datos=$this->d(['documento_id'=>$doc['id'],'email'=>'destino@ensayo.test','confirmar_destinatario'=>'1']);$svc->programar($this->actor,$datos);
        $cola=$this->r->uno('SELECT id FROM correos_salida');$this->r->q("UPDATE correos_salida SET destinatario_email='otro@ensayo.test'");
        $previo=\Classes\Email::getTransport();$llamadas=0;\Classes\Email::setTransport(function()use(&$llamadas){$llamadas++;return true;});
        try{self::assertFalse((new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository($this->db)))->despachar((int)$cola['id']));self::assertSame(0,$llamadas);self::assertSame('fallido',$this->r->uno('SELECT estado FROM correos_salida')['estado']);}
        finally{\Classes\Email::setTransport($previo);}
        $cliente=R5CajaFixture::usuario($this->db);$this->expectException(ErpException::class);$this->expectExceptionCode(403);$svc->programar($cliente,$datos);
    }
    /** @dataProvider erroresRecepcion */
    public function testClasificaRecepcionSinPerderClaveNiReenviarLoQueEstaEnProceso(string $codigo,string $estado):void
    {
        $d=$this->documento();$rec='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:validarComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.recepcion"><RespuestaRecepcionComprobante><estado>DEVUELTA</estado><comprobantes><comprobante><claveAcceso>'.$d['clave_acceso'].'</claveAcceso><mensajes><mensaje><identificador>'.$codigo.'</identificador><mensaje>Diagnóstico de ensayo</mensaje><tipo>ERROR</tipo></mensaje></mensajes></comprobante></comprobantes></RespuestaRecepcionComprobante></ns2:validarComprobanteResponse></soap:Body></soap:Envelope>';
        $this->svc()->procesar($this->transporte([$this->respuesta($d['clave_acceso']),$rec]));self::assertSame($estado,$this->r->uno('SELECT estado FROM erp_fiscal_documentos')['estado']);
        if($estado==='recibida'){$this->debido();$t=$this->transporte([$this->respuesta($d['clave_acceso'])]);$this->svc()->procesar($t);self::assertCount(1,$t->peticiones,'El SRI exige consultar sin retransmitir los códigos 43 y 70.');}
        self::assertSame($d['clave_acceso'],$this->r->uno('SELECT clave_acceso FROM erp_fiscal_documentos')['clave_acceso']);
    }
    public static function erroresRecepcion():array {return [['43','recibida'],['70','recibida'],['50','pendiente'],['35','rechazada']];}
    public function testContenidoFiscalYEmisorNoSePuedenReescribirNiBorrar():void
    {
        $d=$this->documento();foreach(['UPDATE erp_fiscal_documentos SET xml_firmado=UPPER(xml_firmado)','DELETE FROM erp_fiscal_documentos','UPDATE erp_fiscal_perfiles SET ruc=ruc','DELETE FROM erp_fiscal_perfiles'] as $sql){try{$resultado=$this->db->query($sql);self::assertFalse($resultado,$sql);self::assertSame(1644,$this->db->errno);}catch(\mysqli_sql_exception $e){self::assertSame(1644,$e->getCode());}}
        $guard=require __DIR__.'/../../database/migrations/037_erp_fiscal_sri.guard.php';self::assertNotEmpty($guard($this->db));self::assertSame($d['xml_firmado'],$this->r->uno('SELECT xml_firmado FROM erp_fiscal_documentos')['xml_firmado']);
    }
    public function testCambioDeVersionDeEmisorNoReiniciaLaNumeracion():void
    {
        $this->documento();[$p,$pwd]=F::certificado();$e=F::emisor();$e['dir_matriz']='Otra dirección del mismo emisor';$perfil=$this->svc()->perfil($this->actor,$this->d(['emisor'=>$e,'titular_confirmado'=>'1']),$p,$pwd)['recurso_id'];
        $cliente=R5CajaFixture::usuario($this->db);$cita=R5CajaFixture::cita($this->db,$cliente,null,['20.00'],'completada');$venta=(new ErpVentaService($this->r))->crear($this->actor,$this->d(['cita_id'=>$cita]))['recurso_id'];(new ErpVentaService($this->r))->confirmar($this->actor,$this->d(['venta_id'=>$venta,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$venta])['version']]));
        $linea=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$venta])['id'];$d=$this->datos();$d['venta_id']=$venta;$d['perfil_id']=$perfil;$d['categorias_iva']=[(string)$linea=>'0'];$id=$this->svc()->factura($this->actor,$d)['recurso_id'];self::assertSame('001-002-000000002',$this->r->uno('SELECT numero FROM erp_fiscal_documentos WHERE id=?',[$id])['numero']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_secuencias')['n']);
    }
    public function testLaBaseImpideRepetirNumeroAunqueUnContadorSeaInconsistente():void
    {
        $original=$this->documento();$this->r->q('UPDATE erp_fiscal_secuencias SET ultimo=0');
        $cliente=R5CajaFixture::usuario($this->db);$cita=R5CajaFixture::cita($this->db,$cliente,null,['20.00'],'completada');$venta=(new ErpVentaService($this->r))->crear($this->actor,$this->d(['cita_id'=>$cita]))['recurso_id'];(new ErpVentaService($this->r))->confirmar($this->actor,$this->d(['venta_id'=>$venta,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$venta])['version']]));
        $linea=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$venta])['id'];$d=$this->datos();$d['venta_id']=$venta;$d['categorias_iva']=[(string)$linea=>'0'];
        try{$this->svc()->factura($this->actor,$d);self::fail('No se debe repetir la numeración fiscal.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1062,$e->getCode());}
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);self::assertSame($original['xml_firmado'],$this->r->uno('SELECT xml_firmado FROM erp_fiscal_documentos')['xml_firmado']);self::assertSame(0,(int)$this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);
    }
    /** @dataProvider identidadesAusentes */
    public function testSqlNoAceptaUnComprobanteSinIdentidadFiscalDelEmisor(string $campo):void
    {
        $original=$this->documento();$venta=(new ErpVentaService($this->r))->crear($this->actor,$this->d())['recurso_id'];
        try{
            $this->r->q("INSERT INTO erp_fiscal_documentos(perfil_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) SELECT perfil_id,?,SHA2('comprobante-sin-emisor',256),huella,REPEAT('0',49),'001-002-999999999',JSON_REMOVE(snapshot_json,?),xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en FROM erp_fiscal_documentos WHERE id=?",[$venta,'$.emisor.'.$campo,(int)$original['id']]);self::fail('Falta identidad fiscal.');
        }catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(3819,$e->getCode());}
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);
    }
    public static function identidadesAusentes():array {return [['ruc'],['ambiente']];}
    public function testDosProcesosNoCreanOtraFacturaDeLaMismaVenta():void
    {
        $datos=$this->datos();$operaciones=[$datos,$datos];$operaciones[1]['idem_key']=R5CajaFixture::clave();$workers=[];$resultados=[];
        try{
            foreach($operaciones as $d){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php','factura',(string)$this->actor,json_encode($d)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        }finally{foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        self::assertSame(1,count(array_filter($resultados,fn($r)=>$r['ok'])));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);self::assertSame(1,(int)$this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);
    }
    public function testDosProcesosNoDuplicanLaEntregaAlMismoDestino():void
    {
        $doc=$this->documento();$this->svc()->procesar($this->transporte([$this->respuesta($doc['clave_acceso'],$doc['xml_firmado'])]));$datos=$this->d(['documento_id'=>$doc['id'],'email'=>'destino@ensayo.test','confirmar_destinatario'=>'1']);$operaciones=[$datos,$datos];$operaciones[1]['idem_key']=R5CajaFixture::clave();$workers=[];$resultados=[];
        try{
            foreach($operaciones as $d){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php','correo_fiscal',(string)$this->actor,json_encode($d)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        }finally{foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        self::assertSame(2,count(array_filter($resultados,fn($r)=>$r['ok'])));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM correos_salida')['n']);self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='fiscal_entrega_programada'")['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos')['n']);self::assertSame(1,(int)$this->r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);
    }
    public function testRespaldoRestauraXmlCertificadoNumeracionYProtecciones():void
    {
        $d=$this->documento();$this->svc()->procesar($this->transporte([$this->respuesta($d['clave_acceso'],$d['xml_firmado'])]));$exportado=$this->svc()->descargar($this->actor,(int)$d['id'],true);(new \Services\ErpFiscalCorreoService($this->r))->programar($this->actor,$this->d(['documento_id'=>$d['id'],'email'=>'respaldo@ensayo.test','confirmar_destinatario'=>'1']));$colaOriginal=$this->r->uno('SELECT * FROM correos_salida');$origen=BaseDatosAislada::nombre($this->db);$destino='appsalon_fixture_fiscal_restore_'.bin2hex(random_bytes(5)).'_test';$archivo=sys_get_temp_dir().'/fiscal_bk_'.bin2hex(random_bytes(8)).'.sql.gz';$restaurada=null;
        $cli=function(array $args):void {$pipes=[];$p=proc_open(array_merge([PHP_BINARY],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out.$err);};
        try{
            $cli(['scripts/backup_db.php','--db='.$origen,'--output='.$archivo,'--gzip']);$this->r->q('CREATE DATABASE `'.$destino.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cli(['scripts/restore_db.php','--db='.$destino,'--input='.$archivo,'--hash='.hash_file('sha256',$archivo)]);
            $restaurada=new \mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),$destino,(int)getenv('DB_PORT'));$r=new ErpRepository($restaurada);self::assertSame($d['xml_sha256'],$r->uno('SELECT xml_sha256 FROM erp_fiscal_documentos')['xml_sha256']);self::assertSame($d['xml_firmado'],$r->uno('SELECT xml_firmado FROM erp_fiscal_documentos')['xml_firmado']);self::assertSame(1,(int)$r->uno('SELECT ultimo FROM erp_fiscal_secuencias')['ultimo']);self::assertSame($this->r->uno('SELECT certificado_cifrado FROM erp_fiscal_perfiles')['certificado_cifrado'],$r->uno('SELECT certificado_cifrado FROM erp_fiscal_perfiles')['certificado_cifrado']);self::assertFalse($restaurada->query('DELETE FROM erp_fiscal_documentos'));self::assertSame(1644,$restaurada->errno);
            self::assertSame(3,(int)$r->uno("SELECT COUNT(*) n FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='erp_fiscal_documentos' AND index_name='uq_fiscal_numero_emisor'")['n']);
            self::assertSame($exportado,(new ErpFiscalService($r))->descargar($this->actor,(int)$d['id'],true));
            $colaRestaurada=$r->uno('SELECT * FROM correos_salida');self::assertSame($colaOriginal['carga_cifrada'],$colaRestaurada['carga_cifrada']);$carga=\Classes\CargaCifrada::descifrar($colaRestaurada['carga_cifrada']);$mail=(new \Services\ErpFiscalCorreoService($r))->preparar($carga,$colaRestaurada['destinatario_email'],$colaRestaurada['destinatario_nombre']);self::assertSame(base64_decode($carga['pdf']),$mail->getAttachments()[1][0]);
            $otra=(new ErpVentaService($r))->crear($this->actor,$this->d())['recurso_id'];
            try{$r->q("INSERT INTO erp_fiscal_documentos(perfil_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) SELECT perfil_id,?,SHA2('numero-restaurado',256),huella,REPEAT('0',49),numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en FROM erp_fiscal_documentos WHERE id=?",[$otra,(int)$d['id']]);self::fail('La restauración debe impedir repetir el número fiscal.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1062,$e->getCode());}
        }finally{if($restaurada)$restaurada->close();$this->r->q('DROP DATABASE IF EXISTS `'.$destino.'`');foreach([$archivo,$archivo.'.manifest.json'] as $f)if(is_file($f))unlink($f);}
    }
}
