<?php
namespace Tests\Fixtures\Sri;

final class Fixtures
{
    public static function emisor():array {return ['ruc'=>'1790012345001','ambiente'=>'1','establecimiento'=>'001','punto_emision'=>'002','razon_social'=>'Salón de ensayo & compañía','dir_matriz'=>'Quito, matriz de ensayo','obligado_contabilidad'=>'NO'];}
    public static function factura():array {return ['fecha'=>substr(\Repositories\OperacionRepository::ahora(),0,10),'secuencial'=>'000000001','codigo_numerico'=>'12345678','comprador'=>['tipo'=>'05','identificacion'=>'1712345678','nombre'=>'Cliente de ensayo','direccion'=>'Dirección & referencia'],'lineas'=>[['codigo'=>'SERV-1','descripcion'=>'Servicio de prueba & cuidado','cantidad_milesimas'=>1000,'precio_centavos'=>3000,'descuento_centavos'=>1000,'impuesto_pb'=>1500,'codigo_porcentaje'=>'4']],'pagos'=>[['forma'=>'01','total_centavos'=>2300]],'total_centavos'=>2300];}
    /**
     * Parámetros SOLO de prueba (no son cifras legales): tarifas de IVA, momento del hecho generador y un máximo arbitrario de consumidor final.
     * Se insertan directamente en SQL para no depender del servicio bajo prueba; una producción real los registra una persona con la fuente oficial.
     */
    public static function parametros(\mysqli $db,int $actor,array $omitir=[],array $extra=[]):void
    {
        $filas=[];foreach(\Services\Fiscal\SriComprobante::IVA as $codigo=>$pb)$filas[]=['iva.codigo.'.$codigo,'*',$pb,null];
        $filas[]=['servicios.hecho_generador','*',null,'prestacion'];$filas[]=['consumidor_final.total_maximo_centavos','*',5000,null];
        foreach($filas as [$clave,$regimen,$entero,$texto]){
            if(in_array($clave,$omitir,true))continue;
            $e=$entero;$t=$texto;$f='Parámetro de ensayo (no oficial)';$a=substr(\Repositories\OperacionRepository::ahora(),0,10);
            $st=$db->prepare("INSERT INTO erp_fiscal_parametros(clave,regimen,vigente_desde,estado,valor_entero,valor_texto,fuente,consultado_en,creado_por,creado_en) VALUES(?,?,'2000-01-01','vigente',?,?,?,?,?,NOW())");
            $st->bind_param('ssisssi',$clave,$regimen,$e,$t,$f,$a,$actor);$st->execute();$st->close();
        }
        foreach($extra as [$clave,$regimen,$desde,$estado,$entero,$texto]){
            $st=$db->prepare("INSERT INTO erp_fiscal_parametros(clave,regimen,vigente_desde,estado,valor_entero,valor_texto,fuente,consultado_en,creado_por,creado_en) VALUES(?,?,?,?,?,?,'Parámetro de ensayo (no oficial)',CURDATE(),?,NOW())");
            $st->bind_param('ssssisi',$clave,$regimen,$desde,$estado,$entero,$texto,$actor);$st->execute();$st->close();
        }
    }
    public static function certificado():array
    {
        static $resultado;if($resultado)return $resultado;
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);$csr=openssl_csr_new(['countryName'=>'EC','organizationName'=>'Solo pruebas automatizadas','commonName'=>'Emisor de ensayo'],$key,['digest_alg'=>'sha256']);$cert=openssl_csr_sign($csr,null,$key,2,['digest_alg'=>'sha256'],123);
        openssl_pkcs12_export($cert,$p12,$key,'clave-sintetica');return $resultado=[$p12,'clave-sintetica'];
    }
    /** Verificador de pruebas separado del firmador: valida firma RSA y cada referencia del XML. */
    public static function verificar(string $xml):bool
    {
        $d=new \DOMDocument();$d->loadXML($xml,LIBXML_NONET);$xp=new \DOMXPath($d);$xp->registerNamespace('ds','http://www.w3.org/2000/09/xmldsig#');
        $sig=$xp->query('/factura/ds:Signature | /notaCredito/ds:Signature')->item(0);if(!$sig)return false;
        $si=$xp->query('./ds:SignedInfo',$sig)->item(0);$cert=$xp->evaluate('string(./ds:KeyInfo/ds:X509Data/ds:X509Certificate)',$sig);$pem="-----BEGIN CERTIFICATE-----\n".chunk_split($cert,64,"\n")."-----END CERTIFICATE-----\n";
        $v=base64_decode($xp->evaluate('string(./ds:SignatureValue)',$sig),true);if($v===false||openssl_verify($si->C14N(),$v,$pem,OPENSSL_ALGO_SHA1)!==1)return false;
        $refs=$xp->query('./ds:Reference',$si);if($refs->length!==3)return false;
        foreach($refs as $r){$id=substr($r->getAttribute('URI'),1);$targets=$xp->query('//*[@Id="'.$id.'" or @id="'.$id.'"]');if($targets->length!==1)return false;$target=$targets->item(0);$expected=$xp->evaluate('string(./ds:DigestValue)',$r);
            if($id==='comprobante'){$sig->parentNode->removeChild($sig);$data=$d->C14N();$d->documentElement->appendChild($sig);}else $data=$target->C14N();
            if(!hash_equals($expected,base64_encode(hash('sha1',$data,true))))return false;
        }return true;
    }
}
