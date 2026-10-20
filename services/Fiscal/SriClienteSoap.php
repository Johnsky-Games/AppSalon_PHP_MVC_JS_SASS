<?php
namespace Services\Fiscal;

use Interfaces\SriTransporteInterface;

/** Recepción y autorización son pasos distintos. El cliente no reserva secuencias ni altera ventas. */
final class SriClienteSoap
{
    private const SOAP='http://schemas.xmlsoap.org/soap/envelope/';
    private const REC='http://ec.gob.sri.ws.recepcion';
    private const AUT='http://ec.gob.sri.ws.autorizacion';
    public function __construct(private string $ambiente='1',private SriTransporteInterface $transporte=new SriTransporteHttps())
    {if(!in_array($ambiente,['1','2'],true))throw new \InvalidArgumentException('Ambiente SRI no válido.');}

    public function recibir(string $firmado):array
    {
        $doc=SriXml::validar($firmado);$clave=$doc->getElementsByTagName('claveAcceso')->item(0)->textContent;$this->clave($clave);
        if($doc->getElementsByTagNameNS(SriFirmaXades::DS,'Signature')->length!==1)throw new \InvalidArgumentException('Falta la firma electrónica única del comprobante.');
        [$soap,$op]=$this->sobre(self::REC,'validarComprobante');$n=$soap->createElement('xml');$n->appendChild($soap->createTextNode(base64_encode($firmado)));$op->appendChild($n);
        [$xp,$r]=$this->respuesta($this->transporte->post($this->url('Recepcion'),$soap->saveXML()),self::REC,'validarComprobanteResponse','RespuestaRecepcionComprobante');
        $estado=$this->valor($xp,$r,'estado');if(!in_array($estado,['RECIBIDA','DEVUELTA'],true))throw new \RuntimeException('Estado de recepción SRI desconocido.');
        foreach($xp->query('./comprobantes/comprobante/claveAcceso',$r) as $c)if(!hash_equals($clave,$c->textContent))throw new \RuntimeException('La recepción corresponde a otra clave.');
        return ['estado'=>$estado,'clave'=>$clave,'mensajes'=>$this->mensajes($xp,$r),'autorizado'=>false];
    }

    /** El XML esperado debe ser exactamente el enviado y persistido antes del primer intento. */
    public function consultar(string $clave,string $firmadoEsperado):array
    {
        $this->clave($clave);$doc=SriXml::validar($firmadoEsperado);
        if($doc->getElementsByTagNameNS(SriFirmaXades::DS,'Signature')->length!==1||$doc->getElementsByTagName('claveAcceso')->item(0)->textContent!==$clave)throw new \InvalidArgumentException('El comprobante esperado no corresponde a la clave firmada.');
        [$soap,$op]=$this->sobre(self::AUT,'autorizacionComprobante');$n=$soap->createElement('claveAccesoComprobante',$clave);$op->appendChild($n);
        [$xp,$r]=$this->respuesta($this->transporte->post($this->url('Autorizacion'),$soap->saveXML()),self::AUT,'autorizacionComprobanteResponse','RespuestaAutorizacionComprobante');
        if($this->valor($xp,$r,'claveAccesoConsultada')!==$clave)throw new \RuntimeException('La autorización responde a otra clave.');
        $numero=$this->valor($xp,$r,'numeroComprobantes');$autorizaciones=$xp->query('./autorizaciones/*[local-name()="autorizacion" and (namespace-uri()="" or namespace-uri()="'.self::AUT.'")]',$r);
        if(!ctype_digit($numero)||(int)$numero!==$autorizaciones->length)throw new \RuntimeException('Cantidad de autorizaciones inconsistente.');
        if($numero==='0')return ['estado'=>'EN_PROCESO','clave'=>$clave,'autorizado'=>false,'mensajes'=>[]];
        if($autorizaciones->length!==1)throw new \RuntimeException('La respuesta contiene múltiples autorizaciones; requiere revisión.');
        $a=$autorizaciones->item(0);$estado=$this->valor($xp,$a,'estado');
        if(!in_array($estado,['AUTORIZADO','NO AUTORIZADO'],true))throw new \RuntimeException('Estado de autorización SRI desconocido.');
        $resultado=['estado'=>$estado,'clave'=>$clave,'autorizado'=>$estado==='AUTORIZADO','mensajes'=>$this->mensajes($xp,$a)];
        if($estado==='AUTORIZADO'){
            $aut=$this->valor($xp,$a,'numeroAutorizacion');$fecha=$this->valor($xp,$a,'fechaAutorizacion');$amb=$this->valor($xp,$a,'ambiente');$xml=$this->valor($xp,$a,'comprobante');
            $resultado+=['numero_autorizacion'=>$aut,'fecha_autorizacion'=>$fecha,'ambiente'=>$amb,'xml'=>$xml];
            SriAutorizacion::validar($resultado,$firmadoEsperado);
        }
        return $resultado;
    }
    private function clave(string $c):void {SriClaveAcceso::validar($c);if($c[23]!==$this->ambiente)throw new \InvalidArgumentException('La clave pertenece a otro ambiente SRI.');}
    private function url(string $servicio):string {return 'https://'.($this->ambiente==='1'?'celcer':'cel').'.sri.gob.ec/comprobantes-electronicos-ws/'.$servicio.'ComprobantesOffline';}
    private function sobre(string $ns,string $nombre):array {$d=new \DOMDocument('1.0','UTF-8');$e=$d->createElementNS(self::SOAP,'soap:Envelope');$d->appendChild($e);$b=$d->createElementNS(self::SOAP,'soap:Body');$e->appendChild($b);$o=$d->createElementNS($ns,'sri:'.$nombre);$b->appendChild($o);return [$d,$o];}
    private function respuesta(string $xml,string $ns,string $op,string $nombre):array
    {
        $d=SriXml::cargar($xml);$xp=new \DOMXPath($d);$xp->registerNamespace('soap',self::SOAP);$xp->registerNamespace('sri',$ns);
        $body=$xp->query('/soap:Envelope/soap:Body');if($body->length!==1||$xp->query('./*',$body->item(0))->length!==1)throw new \RuntimeException('Sobre SOAP SRI no válido.');
        $r=$xp->query('/soap:Envelope/soap:Body/sri:'.$op.'/'.$nombre);
        if($r->length!==1)throw new \RuntimeException('Respuesta SOAP SRI no reconocida.');return [$xp,$r->item(0)];
    }
    private function valor(\DOMXPath $xp,\DOMNode $n,string $campo):string {$v=$xp->query('./'.$campo,$n);if($v->length!==1)throw new \RuntimeException('Campo SRI ausente o duplicado.');return $v->item(0)->textContent;}
    private function mensajes(\DOMXPath $xp,\DOMNode $r):array
    {
        $salida=[];foreach($xp->query('.//*[local-name()="mensaje"]/*[local-name()="identificador"]/..',$r) as $m){if(count($salida)>=100)throw new \RuntimeException('Demasiados mensajes SRI.');$fila=[];foreach(['identificador','mensaje','informacionAdicional','tipo'] as $campo){$n=$xp->query('./*[local-name()="'.$campo.'"]',$m);$fila[$campo]=$n->length?mb_substr($n->item(0)->textContent,0,1000):'';}$salida[]=$fila;}return $salida;
    }
}
