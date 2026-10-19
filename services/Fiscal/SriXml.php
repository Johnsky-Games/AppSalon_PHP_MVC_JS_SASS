<?php
namespace Services\Fiscal;

/** Parser cerrado para XML de comprobantes y respuestas; ningún dato controla rutas XSD. */
final class SriXml
{
    public static function cargar(string $xml):\DOMDocument
    {
        if(strlen($xml)>5*1024*1024||!mb_check_encoding($xml,'UTF-8')||preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$xml))throw new \InvalidArgumentException('XML no permitido.');
        $anterior=libxml_use_internal_errors(true);
        try{
            $doc=new \DOMDocument('1.0','UTF-8');$doc->preserveWhiteSpace=true;
            if(!$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype||!$doc->documentElement)throw new \InvalidArgumentException('XML no válido.');
            return $doc;
        }finally{libxml_clear_errors();libxml_use_internal_errors($anterior);}
    }

    public static function validar(string $xml):\DOMDocument
    {
        $doc=self::cargar($xml);$raiz=$doc->documentElement;
        $archivos=['factura'=>'factura_V2.1.0.xsd','notaCredito'=>'NotaCredito_V1.1.0.xsd'];
        $versiones=['factura'=>'2.1.0','notaCredito'=>'1.1.0'];
        if(!isset($archivos[$raiz->tagName])||$raiz->namespaceURI||$raiz->getAttribute('id')!=='comprobante'||$raiz->getAttribute('version')!==$versiones[$raiz->tagName])throw new \InvalidArgumentException('Esquema SRI no admitido.');
        $anterior=libxml_use_internal_errors(true);
        try{if(!$doc->schemaValidate(dirname(__DIR__,2).'/resources/sri/'.$archivos[$raiz->tagName]))throw new \InvalidArgumentException('El comprobante no cumple el XSD oficial del SRI.');}
        finally{libxml_clear_errors();libxml_use_internal_errors($anterior);}
        $xp=new \DOMXPath($doc);$clave=$xp->evaluate('string(/*/infoTributaria/claveAcceso)');SriClaveAcceso::validar($clave);
        $campos=['ambiente'=>substr($clave,23,1),'tipoEmision'=>'1','ruc'=>substr($clave,10,13),'codDoc'=>$raiz->tagName==='factura'?'01':'04','estab'=>substr($clave,24,3),'ptoEmi'=>substr($clave,27,3),'secuencial'=>substr($clave,30,9)];
        foreach($campos as $k=>$v)if($xp->evaluate('string(/*/infoTributaria/'.$k.')')!==$v)throw new \InvalidArgumentException('La cabecera no corresponde a su clave de acceso.');
        if(substr($clave,8,2)!==$campos['codDoc'])throw new \InvalidArgumentException('Tipo de comprobante distinto de su clave.');
        $fecha=substr($clave,0,2).'/'.substr($clave,2,2).'/'.substr($clave,4,4);
        if($xp->evaluate('string(/*/*[2]/fechaEmision)')!==$fecha)throw new \InvalidArgumentException('Fecha distinta de su clave de acceso.');
        return $doc;
    }
}
