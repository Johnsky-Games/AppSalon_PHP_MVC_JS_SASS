<?php
namespace Services\Fiscal;

use Services\ErpCalculo;

/** Generador nacional en USD, IVA por línea y valores exactos del ERP. ICE/exportación requieren otro contrato. */
final class SriComprobante
{
    public const IVA=['0'=>0,'2'=>1200,'3'=>1400,'4'=>1500,'5'=>500,'6'=>0,'7'=>0,'8'=>800,'10'=>1300];

    public static function factura(array $emisor,array $datos):string {return self::crear($emisor,$datos,false);}
    public static function notaCredito(array $emisor,array $datos):string {return self::crear($emisor,$datos,true);}

    private static function crear(array $e,array $d,bool $nc):string
    {
        $tipo=$nc?'04':'01';$fecha=self::fecha($d['fecha']??null);$ruc=self::digitos($e['ruc']??null,13);
        $ambiente=$e['ambiente']??'1';$estab=self::digitos($e['establecimiento']??null,3);$pto=self::digitos($e['punto_emision']??null,3);$seq=self::digitos($d['secuencial']??null,9);
        $clave=SriClaveAcceso::crear($d['fecha'],$tipo,$ruc,$ambiente,$estab.$pto,$seq,self::digitos($d['codigo_numerico']??null,8));
        $b=$d['comprador']??[];$idTipo=$b['tipo']??'';$id=$b['identificacion']??'';
        if(!in_array($idTipo,['04','05','06','07','08'],true)||!is_string($id))throw new \InvalidArgumentException('Selecciona la identificación fiscal del comprador.');
        if($idTipo==='04')self::digitos($id,13);if($idTipo==='05')self::digitos($id,10);
        if(in_array($idTipo,['06','08'],true)&&!preg_match('/^[A-Za-z0-9-]{3,20}$/D',$id))throw new \InvalidArgumentException('Identificación exterior no válida.');
        if($idTipo==='07'&&($id!=='9999999999999'||$nc))throw new \InvalidArgumentException('La nota de crédito exige un comprador identificado.');
        $nombre=self::texto($b['nombre']??null,300);
        // Consumidor final solo cuando el servicio fiscal lo autorizó tras validar el parámetro vigente; nunca de forma implícita.
        if($idTipo==='07'&&($nc||empty($d['consumidor_final'])||$nombre!=='CONSUMIDOR FINAL'))throw new \InvalidArgumentException('Registra un comprador identificado para este circuito fiscal.');
        $lineas=$d['lineas']??null;if(!is_array($lineas)||!array_is_list($lineas)||!count($lineas)||count($lineas)>1000)throw new \InvalidArgumentException('Incluye de 1 a 1000 líneas.');
        $totales=['base'=>0,'descuento'=>0,'iva'=>0,'total'=>0];$impuestos=[];$calculadas=[];
        foreach($lineas as $l){
            if(!is_array($l))throw new \InvalidArgumentException('Línea fiscal no válida.');
            $q=self::entero($l['cantidad_milesimas']??null,1,999999999);$precio=self::entero($l['precio_centavos']??null,0,99999999);$desc=self::entero($l['descuento_centavos']??0,0,99999999);$pb=self::entero($l['impuesto_pb']??null,0,10000);
            $codigo=$l['codigo_porcentaje']??null;
            if(!is_string($codigo)||!array_key_exists($codigo,self::IVA)||self::IVA[$codigo]!==$pb)throw new \InvalidArgumentException('Configura la categoría IVA fiscal de cada línea, incluyendo las tarifas cero.');
            if($nc&&isset($l['base_centavos'])){
                // Nota de crédito: importes del reparto acumulado. Se exige la coherencia del propio documento dentro de la tolerancia registrada (0 = exacta).
                $base=self::entero($l['base_centavos'],0,99999999);$ivaNc=self::entero($l['iva_centavos']??null,0,99999999);$tol=self::entero($d['tolerancia_centavos']??0,0,2);
                if(abs($ivaNc-intdiv($base*$pb+5000,10000))>$tol||abs(($base+$desc)-intdiv($q*$precio+500,1000))>$tol)throw new \InvalidArgumentException('Los importes de la nota de crédito no cuadran con su detalle dentro de la tolerancia registrada.');
                $c=['impuesto'=>$ivaNc,'total'=>$base+$ivaNc];
            }else{$c=ErpCalculo::linea($q,$precio,$desc,$pb);$base=$c['subtotal']-$desc;}
            $calculadas[]=[$l,$q,$precio,$desc,$pb,$codigo,$base,$c['impuesto']];
            $totales['base']+=$base;$totales['descuento']+=$desc;$totales['iva']+=$c['impuesto'];$totales['total']+=$c['total'];
            $impuestos[$codigo]??=['base'=>0,'iva'=>0,'pb'=>$pb];$impuestos[$codigo]['base']+=$base;$impuestos[$codigo]['iva']+=$c['impuesto'];
        }
        if($totales['total']<1||$totales['total']>99999999)throw new \InvalidArgumentException('Total fiscal fuera de rango.');
        if(isset($d['total_centavos'])&&self::entero($d['total_centavos'],1,99999999)!==$totales['total'])throw new \InvalidArgumentException('Los importes fiscales no coinciden con el total de la operación.');
        $doc=new \DOMDocument('1.0','UTF-8');$doc->formatOutput=false;$raiz=$doc->createElement($nc?'notaCredito':'factura');$raiz->setAttribute('id','comprobante');$raiz->setAttribute('version',$nc?'1.1.0':'2.1.0');$doc->appendChild($raiz);
        $it=self::n($raiz,'infoTributaria');self::n($it,'ambiente',$ambiente);self::n($it,'tipoEmision','1');self::n($it,'razonSocial',self::texto($e['razon_social']??null,300));
        if(!empty($e['nombre_comercial']))self::n($it,'nombreComercial',self::texto($e['nombre_comercial'],300));
        foreach(['ruc'=>$ruc,'claveAcceso'=>$clave,'codDoc'=>$tipo,'estab'=>$estab,'ptoEmi'=>$pto,'secuencial'=>$seq,'dirMatriz'=>self::texto($e['dir_matriz']??null,300)] as $k=>$v)self::n($it,$k,$v);
        // El XSD de NC 1.1.0 no contiene estos campos del perfil de factura 2.1.0.
        if(!$nc){if(!empty($e['agente_retencion']))self::n($it,'agenteRetencion',self::texto($e['agente_retencion'],8));if(!empty($e['contribuyente_rimpe']))self::n($it,'contribuyenteRimpe',self::texto($e['contribuyente_rimpe'],100));}
        $info=self::n($raiz,$nc?'infoNotaCredito':'infoFactura');self::n($info,'fechaEmision',$fecha);
        if(!empty($e['dir_establecimiento']))self::n($info,'dirEstablecimiento',self::texto($e['dir_establecimiento'],300));
        $contabilidad=function()use($e,$info){if(!empty($e['contribuyente_especial']))self::n($info,'contribuyenteEspecial',self::texto($e['contribuyente_especial'],13));$ob=$e['obligado_contabilidad']??null;if(!in_array($ob,['SI','NO'],true))throw new \InvalidArgumentException('Configura la obligación contable del emisor.');self::n($info,'obligadoContabilidad',$ob);};
        if(!$nc)$contabilidad();
        self::n($info,'tipoIdentificacionComprador',$idTipo);self::n($info,'razonSocialComprador',$nombre);self::n($info,'identificacionComprador',$id);
        if(!$nc&&!empty($b['direccion']))self::n($info,'direccionComprador',self::texto($b['direccion'],300));
        if($nc){
            $contabilidad();$ref=$d['sustento']??[];if(!preg_match('/^[0-9]{3}-[0-9]{3}-[0-9]{9}$/D',$ref['numero']??''))throw new \InvalidArgumentException('Factura de sustento no válida.');
            $fechaRef=self::fecha($ref['fecha']??null);if($ref['fecha']>$d['fecha'])throw new \InvalidArgumentException('La nota no puede preceder a la factura.');
            self::n($info,'codDocModificado','01');self::n($info,'numDocModificado',$ref['numero']);self::n($info,'fechaEmisionDocSustento',$fechaRef);
        }
        self::n($info,'totalSinImpuestos',self::decimal($totales['base']));
        if($nc){self::n($info,'valorModificacion',self::decimal($totales['total']));self::n($info,'moneda','DOLAR');}else self::n($info,'totalDescuento',self::decimal($totales['descuento']));
        $ti=self::n($info,'totalConImpuestos');ksort($impuestos,SORT_NUMERIC);
        foreach($impuestos as $codigo=>$i){$n=self::n($ti,'totalImpuesto');self::n($n,'codigo','2');self::n($n,'codigoPorcentaje',(string)$codigo);self::n($n,'baseImponible',self::decimal($i['base']));self::n($n,'valor',self::decimal($i['iva']));}
        if($nc)self::n($info,'motivo',self::texto($d['motivo']??null,300));
        else{
            self::n($info,'importeTotal',self::decimal($totales['total']));self::n($info,'moneda','DOLAR');
            $pagos=$d['pagos']??null;if(!is_array($pagos)||!array_is_list($pagos)||!count($pagos)||count($pagos)>20)throw new \InvalidArgumentException('Configura las formas de pago fiscales.');
            $np=self::n($info,'pagos');$suma=0;foreach($pagos as $p){$forma=$p['forma']??'';if(!in_array($forma,['01','15','16','17','18','19','20','21'],true))throw new \InvalidArgumentException('Forma de pago SRI no válida.');$m=self::entero($p['total_centavos']??null,1,99999999);$suma+=$m;$n=self::n($np,'pago');self::n($n,'formaPago',$forma);self::n($n,'total',self::decimal($m));if(isset($p['plazo_dias'])){self::n($n,'plazo',(string)self::entero($p['plazo_dias'],0,3650));self::n($n,'unidadTiempo','dias');}}
            if($suma!==$totales['total'])throw new \InvalidArgumentException('Las formas de pago no cubren el importe fiscal.');
        }
        $nd=self::n($raiz,'detalles');foreach($calculadas as [$l,$q,$precio,$desc,$pb,$codigo,$base,$iva]){
            $n=self::n($nd,'detalle');self::n($n,$nc?'codigoInterno':'codigoPrincipal',self::texto($l['codigo']??null,25));self::n($n,'descripcion',self::texto($l['descripcion']??null,300));self::n($n,'cantidad',self::decimal($q,3));self::n($n,'precioUnitario',self::decimal($precio));self::n($n,'descuento',self::decimal($desc));self::n($n,'precioTotalSinImpuesto',self::decimal($base));
            $ims=self::n($n,'impuestos');$im=self::n($ims,'impuesto');foreach(['codigo'=>'2','codigoPorcentaje'=>$codigo,'tarifa'=>self::decimal($pb),'baseImponible'=>self::decimal($base),'valor'=>self::decimal($iva)] as $k=>$v)self::n($im,$k,$v);
        }
        $xml=$doc->saveXML();SriXml::validar($xml);return $xml;
    }
    private static function n(\DOMElement $p,string $nombre,?string $texto=null):\DOMElement {$n=$p->ownerDocument->createElement($nombre);if($texto!==null)$n->appendChild($p->ownerDocument->createTextNode($texto));$p->appendChild($n);return $n;}
    private static function decimal(int $n,int $dec=2):string {$escala=10**$dec;return intdiv($n,$escala).'.'.str_pad((string)($n%$escala),$dec,'0',STR_PAD_LEFT);}
    private static function entero(mixed $n,int $min,int $max):int {if(!is_int($n)||$n<$min||$n>$max)throw new \InvalidArgumentException('Importe o cantidad fiscal no válido.');return $n;}
    private static function digitos(mixed $n,int $longitud):string {if(!is_string($n)||!preg_match('/^[0-9]{'.$longitud.'}$/D',$n))throw new \InvalidArgumentException('Identificación o secuencia fiscal no válida.');return $n;}
    private static function texto(mixed $s,int $max):string {if(!is_string($s)||trim($s)===''||!mb_check_encoding($s,'UTF-8')||mb_strlen($s)>$max||preg_match('/[\x00-\x1f\x7f]/',$s))throw new \InvalidArgumentException('Texto fiscal no válido.');return trim($s);}
    private static function fecha(mixed $s):string {$d=is_string($s)?\DateTimeImmutable::createFromFormat('!Y-m-d',$s):false;if(!$d||$d->format('Y-m-d')!==$s)throw new \InvalidArgumentException('Fecha fiscal no válida.');return $d->format('d/m/Y');}
}
