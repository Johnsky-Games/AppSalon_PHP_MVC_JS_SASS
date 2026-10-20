<?php
namespace Tests\Unit;
require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use PHPUnit\Framework\TestCase;
use Interfaces\SriTransporteInterface;
use Services\Fiscal\{SriClienteSoap,SriComprobante,SriFirmaXades,SriXml,SriTransporteHttps};
use Tests\Fixtures\Sri\Fixtures as F;

final class SriClienteSoapTest extends TestCase
{
    private function firmado():string {[$p,$c]=F::certificado();return SriFirmaXades::firmar(SriComprobante::factura(F::emisor(),F::factura()),$p,$c);}
    private function clave(string $xml):string {return SriXml::cargar($xml)->getElementsByTagName('claveAcceso')->item(0)->textContent;}
    private function transporte(string $respuesta):SriTransporteInterface {return new class($respuesta) implements SriTransporteInterface {public array $peticiones=[];public function __construct(private string $respuesta){}public function post(string $url,string $xml):string {$this->peticiones[]=[$url,$xml];return $this->respuesta;}};}
    private function sobre(string $interior,string $servicio='autorizacionComprobanteResponse',string $ns='autorizacion'):string {return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:'.$servicio.' xmlns:ns2="http://ec.gob.sri.ws.'.$ns.'">'.$interior.'</ns2:'.$servicio.'></soap:Body></soap:Envelope>';}
    private function autorizacion(string $clave,string $xml,string $estado='AUTORIZADO'):string
    {return $this->sobre('<RespuestaAutorizacionComprobante><claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>'.$estado.'</estado><numeroAutorizacion>'.$clave.'</numeroAutorizacion><fechaAutorizacion>2026-10-19T10:30:00-05:00</fechaAutorizacion><ambiente>PRUEBAS</ambiente><comprobante><![CDATA['.$xml.']]></comprobante><mensajes/></autorizacion></autorizaciones></RespuestaAutorizacionComprobante>');}
    public function testRecepcionNoEsAutorizacionYEnviaExactamenteElXmlFirmado():void
    {
        $xml=$this->firmado();$t=$this->transporte($this->sobre('<RespuestaRecepcionComprobante><estado>RECIBIDA</estado><comprobantes/></RespuestaRecepcionComprobante>','validarComprobanteResponse','recepcion'));$r=(new SriClienteSoap('1',$t))->recibir($xml);
        self::assertSame('RECIBIDA',$r['estado']);self::assertFalse($r['autorizado']);self::assertSame('https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline',$t->peticiones[0][0]);$d=SriXml::cargar($t->peticiones[0][1]);self::assertSame($xml,base64_decode($d->getElementsByTagName('xml')->item(0)->textContent,true));
    }
    public function testAutorizacionExigeLaMismaClaveAmbienteYXml():void
    {
        $xml=$this->firmado();$clave=$this->clave($xml);$t=$this->transporte($this->autorizacion($clave,$xml));$r=(new SriClienteSoap('1',$t))->consultar($clave,$xml);
        self::assertTrue($r['autorizado']);self::assertSame($clave,$r['numero_autorizacion']);self::assertSame($xml,$r['xml']);self::assertStringContainsString('<claveAccesoComprobante>'.$clave.'</claveAccesoComprobante>',$t->peticiones[0][1]);
    }
    public function testSinAutorizacionTodaviaMantieneEstadoEnProceso():void
    {
        $xml=$this->firmado();$clave=$this->clave($xml);$t=$this->transporte($this->sobre('<RespuestaAutorizacionComprobante><claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>0</numeroComprobantes><autorizaciones/></RespuestaAutorizacionComprobante>'));$r=(new SriClienteSoap('1',$t))->consultar($clave,$xml);self::assertSame('EN_PROCESO',$r['estado']);self::assertFalse($r['autorizado']);
    }
    public function testNoAutorizadoConservaDiagnosticoSinDeclararExito():void
    {
        $xml=$this->firmado();$clave=$this->clave($xml);$r=(new SriClienteSoap('1',$this->transporte($this->autorizacion($clave,$xml,'NO AUTORIZADO'))))->consultar($clave,$xml);self::assertFalse($r['autorizado']);self::assertSame('NO AUTORIZADO',$r['estado']);
    }
    public function testDevolucionDeRecepcionConservaMensajesYNoAutoriza():void
    {
        $xml=$this->firmado();$clave=$this->clave($xml);$t=$this->transporte($this->sobre('<RespuestaRecepcionComprobante><estado>DEVUELTA</estado><comprobantes><comprobante><claveAcceso>'.$clave.'</claveAcceso><mensajes><mensaje><identificador>43</identificador><mensaje>CLAVE ACCESO REGISTRADA</mensaje><tipo>ERROR</tipo></mensaje></mensajes></comprobante></comprobantes></RespuestaRecepcionComprobante>','validarComprobanteResponse','recepcion'));$r=(new SriClienteSoap('1',$t))->recibir($xml);self::assertSame('43',$r['mensajes'][0]['identificador']);self::assertFalse($r['autorizado']);
    }
    /** @dataProvider respuestasInconsistentes */
    public function testNoConfiaEnRespuestaDeOtraClaveOSinEvidencia(string $caso):void
    {
        $xml=$this->firmado();$clave=$this->clave($xml);$respuesta=$this->autorizacion($clave,$xml);
        switch($caso){case 'clave':$respuesta=str_replace('<claveAccesoConsultada>'.$clave,'<claveAccesoConsultada>'.str_repeat('0',49),$respuesta);break;case 'numero':$respuesta=str_replace('<numeroComprobantes>1','<numeroComprobantes>0',$respuesta);break;case 'ambiente':$respuesta=str_replace('PRUEBAS','PRODUCCIÓN',$respuesta);break;case 'xml':$respuesta=str_replace('Servicio de prueba &amp; cuidado','Servicio cambiado',$respuesta);break;case 'fecha':$respuesta=str_replace('2026-10-19T10:30:00-05:00','sin fecha',$respuesta);break;case 'duplicado':$respuesta=str_replace('<estado>AUTORIZADO</estado>','<estado>AUTORIZADO</estado><estado>AUTORIZADO</estado>',$respuesta);break;case 'namespace':$respuesta=str_replace('http://ec.gob.sri.ws.autorizacion','https://tercero.invalid',$respuesta);break;}
        $this->expectException(\RuntimeException::class);(new SriClienteSoap('1',$this->transporte($respuesta)))->consultar($clave,$xml);
    }
    public static function respuestasInconsistentes():array {return array_map(fn($c)=>[$c],['clave','numero','ambiente','xml','fecha','duplicado','namespace']);}
    public function testNoEnviaDocumentoDeOtroAmbienteNiSinFirma():void
    {
        $t=$this->transporte('');try{(new SriClienteSoap('1',$t))->recibir(SriComprobante::factura(F::emisor(),F::factura()));self::fail('Falta firma');}catch(\InvalidArgumentException){self::assertSame([],$t->peticiones);}
        $this->expectException(\InvalidArgumentException::class);(new SriClienteSoap('2',$t))->recibir($this->firmado());
    }
    public function testTransporteNoAdmiteDestinosArbitrariosNiUrlsConCredenciales():void
    {$this->expectException(\InvalidArgumentException::class);(new SriTransporteHttps())->post('https://celcer.sri.gob.ec@tercero.invalid/comprobantes-electronicos-ws/RecepcionComprobantesOffline','');}
}
