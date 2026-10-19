<?php
namespace Services\Fiscal;

/** Perfil XAdES-BES 1.3.2 exigido por SRI 2.34: comprobante, propiedades y KeyInfo firmados. */
final class SriFirmaXades
{
    public const DS='http://www.w3.org/2000/09/xmldsig#';
    public const ET='http://uri.etsi.org/01903/v1.3.2#';
    public const C14N='http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    public static function firmar(string $xml,string $p12,#[\SensitiveParameter] string $password,?\DateTimeImmutable $ahora=null):string
    {
        $doc=SriXml::validar($xml);
        if($doc->getElementsByTagNameNS(self::DS,'Signature')->length)throw new \InvalidArgumentException('El comprobante ya tiene una firma.');
        $ahora=($ahora??new \DateTimeImmutable())->setTimezone(new \DateTimeZone('America/Guayaquil'));
        $material=[];
        if(strlen($p12)>1024*1024||!openssl_pkcs12_read($p12,$material,$password))throw new \InvalidArgumentException('No se pudo abrir el certificado PKCS12.');
        $cert=openssl_x509_read($material['cert']);$datos=$cert?openssl_x509_parse($cert):false;$key=openssl_pkey_get_private($material['pkey']);
        if(!$cert||!$datos||!$key||!openssl_x509_check_private_key($cert,$key))throw new \InvalidArgumentException('El certificado y su clave no corresponden.');
        $det=openssl_pkey_get_details($key);
        if(!$det||$det['type']!==OPENSSL_KEYTYPE_RSA||$det['bits']<2048)throw new \InvalidArgumentException('La firma SRI exige una clave RSA de al menos 2048 bits.');
        if($ahora->getTimestamp()<$datos['validFrom_time_t']||$ahora->getTimestamp()>=$datos['validTo_time_t'])throw new \InvalidArgumentException('El certificado todavía no está vigente o ha vencido.');
        if(isset($datos['extensions']['keyUsage'])&&!str_contains($datos['extensions']['keyUsage'],'Digital Signature'))throw new \InvalidArgumentException('El certificado no permite firma digital.');
        $der=base64_decode(preg_replace('/-----[^-]+-----|\s/','',$material['cert']),true);
        if($der===false)throw new \InvalidArgumentException('Certificado no válido.');
        $sufijo=bin2hex(random_bytes(16));$signatureId='Signature-'.$sufijo;$propsId='SignedProperties-'.$sufijo;$keyId='Certificate-'.$sufijo;$refId='Reference-'.$sufijo;
        $digestDoc=base64_encode(hash('sha1',$doc->C14N(false,false),true));
        $sig=$doc->createElementNS(self::DS,'ds:Signature');$sig->setAttributeNS('http://www.w3.org/2000/xmlns/','xmlns:etsi',self::ET);$sig->setAttribute('Id',$signatureId);$doc->documentElement->appendChild($sig);
        $si=self::n($sig,'ds:SignedInfo');self::n($si,'ds:CanonicalizationMethod',null,['Algorithm'=>self::C14N]);self::n($si,'ds:SignatureMethod',null,['Algorithm'=>self::DS.'rsa-sha1']);
        $rProps=self::referencia($si,'#'.$propsId,['Type'=>'http://uri.etsi.org/01903#SignedProperties']);
        $rKey=self::referencia($si,'#'.$keyId);
        $rDoc=self::n($si,'ds:Reference',null,['Id'=>$refId,'URI'=>'#comprobante']);$trans=self::n($rDoc,'ds:Transforms');self::n($trans,'ds:Transform',null,['Algorithm'=>self::DS.'enveloped-signature']);self::n($rDoc,'ds:DigestMethod',null,['Algorithm'=>self::DS.'sha1']);self::n($rDoc,'ds:DigestValue',$digestDoc);
        $sv=self::n($sig,'ds:SignatureValue');
        $ki=self::n($sig,'ds:KeyInfo',null,['Id'=>$keyId]);$xd=self::n($ki,'ds:X509Data');self::n($xd,'ds:X509Certificate',base64_encode($der));
        $kv=self::n($ki,'ds:KeyValue');$rsa=self::n($kv,'ds:RSAKeyValue');self::n($rsa,'ds:Modulus',base64_encode($det['rsa']['n']));self::n($rsa,'ds:Exponent',base64_encode($det['rsa']['e']));
        $object=self::n($sig,'ds:Object');$qp=self::n($object,'etsi:QualifyingProperties',null,['Target'=>'#'.$signatureId]);$sp=self::n($qp,'etsi:SignedProperties',null,['Id'=>$propsId]);
        $ssp=self::n($sp,'etsi:SignedSignatureProperties');self::n($ssp,'etsi:SigningTime',$ahora->format('Y-m-d\TH:i:sP'));
        $sc=self::n($ssp,'etsi:SigningCertificate');$c=self::n($sc,'etsi:Cert');$cd=self::n($c,'etsi:CertDigest');self::n($cd,'ds:DigestMethod',null,['Algorithm'=>self::DS.'sha1']);self::n($cd,'ds:DigestValue',base64_encode(hash('sha1',$der,true)));
        $is=self::n($c,'etsi:IssuerSerial');self::n($is,'ds:X509IssuerName',self::issuer($datos['issuer']));self::n($is,'ds:X509SerialNumber',self::decimalHex($datos['serialNumberHex']));
        $sdp=self::n($sp,'etsi:SignedDataObjectProperties');$format=self::n($sdp,'etsi:DataObjectFormat',null,['ObjectReference'=>'#'.$refId]);self::n($format,'etsi:Description','contenido comprobante');self::n($format,'etsi:MimeType','text/xml');
        $rProps->nodeValue=base64_encode(hash('sha1',$sp->C14N(false,false),true));$rKey->nodeValue=base64_encode(hash('sha1',$ki->C14N(false,false),true));
        if(!openssl_sign($si->C14N(false,false),$firma,$key,OPENSSL_ALGO_SHA1))throw new \RuntimeException('No se pudo firmar el comprobante.');
        $sv->nodeValue=base64_encode($firma);
        $salida=$doc->saveXML();SriXml::validar($salida);return $salida;
    }

    private static function n(\DOMElement $parent,string $nombre,?string $texto=null,array $atributos=[]):\DOMElement
    {
        $d=$parent->ownerDocument;$n=$d->createElementNS(str_starts_with($nombre,'ds:')?self::DS:self::ET,$nombre);foreach($atributos as $k=>$v)$n->setAttribute($k,$v);
        if($texto!==null)$n->appendChild($d->createTextNode($texto));$parent->appendChild($n);return $n;
    }
    private static function referencia(\DOMElement $si,string $uri,array $atributos=[]):\DOMElement
    {
        $r=self::n($si,'ds:Reference',null,['URI'=>$uri]+$atributos);self::n($r,'ds:DigestMethod',null,['Algorithm'=>self::DS.'sha1']);return self::n($r,'ds:DigestValue');
    }
    private static function issuer(array $dn):string
    {
        $partes=[];foreach(array_reverse($dn,true) as $k=>$v)foreach((array)$v as $valor){$valor=preg_replace('/([,"+\\\\<>;=])/','\\\\$1',(string)$valor);if(str_starts_with($valor,' ')||str_starts_with($valor,'#'))$valor='\\'.$valor;if(str_ends_with($valor,' '))$valor=substr($valor,0,-1).'\\ ';$partes[]=$k.'='.$valor;}return implode(',',$partes);
    }
    private static function decimalHex(string $hex):string
    {
        $resultado='0';foreach(str_split($hex) as $c){$acarreo=hexdec($c);$n='';for($i=strlen($resultado)-1;$i>=0;$i--){$v=(int)$resultado[$i]*16+$acarreo;$n=($v%10).$n;$acarreo=intdiv($v,10);}while($acarreo){$n=($acarreo%10).$n;$acarreo=intdiv($acarreo,10);}$resultado=ltrim($n,'0')?:'0';}return $resultado;
    }
}
