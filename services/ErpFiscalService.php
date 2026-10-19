<?php
namespace Services;

use Classes\{Secretos,Transaccion};
use Services\Fiscal\{SriAutorizacion,SriClienteSoap,SriComprobante,SriFirmaXades,SriXml};

/** Piloto nativo SRI. CLI autorizado, sin exponer certificados ni aceptar estados fiscales manuales. */
final class ErpFiscalService extends ErpService
{
    public function ride(int $actor, int $id): array
    {
        $archivo = $this->descargar($actor, $id, true);
        return \Services\Fiscal\SriRide::datos($archivo['contenido']);
    }

    public function pdf(int $actor,int $id):array
    {
        $ride=$this->ride($actor,$id);$e=$ride['emisor'];
        return ['contenido'=>\Services\Fiscal\SriRidePdf::generar($ride),'nombre'=>'pruebas-sri-'.$e['estab'].'-'.$e['ptoEmi'].'-'.$e['secuencial'].'-ride.pdf'];
    }

    /** Descarga privada sin contactar al SRI, reservar números ni alterar la venta. */
    public function descargar(int $actor, int $id, bool $autorizado = false): array
    {
        $this->permitir($actor, 'erp.cobrar');
        $doc = $this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?', [$id])
            ?? throw new ErpException('Documento fiscal no encontrado.', 404);
        if (!hash_equals($doc['xml_sha256'], hash('sha256', $doc['xml_firmado']))) {
            throw new ErpException('No se pudo verificar la integridad del XML.', 409);
        }
        $xml = $doc['xml_firmado'];
        if ($autorizado) {
            if ($doc['estado'] !== 'autorizada') {
                throw new ErpException('El comprobante todavía no tiene autorización del SRI.', 409);
            }
            try {
                $respuesta = json_decode($doc['respuesta_json'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($respuesta)) throw new \RuntimeException('Falta la evidencia.');
                $xml = SriAutorizacion::exportar($respuesta, $doc['xml_firmado']);
            } catch (\RuntimeException|\InvalidArgumentException|\JsonException $e) {
                throw new ErpException('No se pudo verificar la evidencia de autorización.', 409);
            }
        }
        $numero = preg_match('/^\d{3}-\d{3}-\d{9}$/D', $doc['numero']) ? $doc['numero'] : (string) $id;
        return ['contenido' => $xml, 'nombre' => 'pruebas-sri-'.($autorizado ? 'autorizado-' : '').$numero.'.xml'];
    }

    private function cli():void {if(PHP_SAPI!=='cli')throw new ErpException('Operación reservada a la consola del piloto fiscal.',403);}
    public function perfil(int $actor,array $d,#[\SensitiveParameter] string $p12,#[\SensitiveParameter] string $password):array
    {
        $this->cli();if(($d['emisor']['ambiente']??null)!=='1'||($d['titular_confirmado']??null)!=='1')throw new ErpException('El piloto exige ambiente de pruebas y confirmar la titularidad del certificado.');
        $d['certificado_sha256']=hash('sha256',$p12);$d['credencial_huella']=hash_hmac('sha256',$password,Secretos::clave('sri-certificado-huella'));
        return $this->ejecutar($actor,'erp.configurar','fiscal_perfil',$d,function()use($actor,$d,$p12,$password){
            $e=$d['emisor'];$permitidas=['ruc','ambiente','establecimiento','punto_emision','razon_social','dir_matriz','obligado_contabilidad','nombre_comercial','dir_establecimiento','contribuyente_especial','agente_retencion','contribuyente_rimpe'];
            if(array_diff(array_keys($e),$permitidas))throw new ErpException('La configuración del emisor contiene campos no admitidos.');
            // Valida estructura y posibilidad de firmar en memoria; no envía ni persiste el documento sintético.
            $prueba=['fecha'=>substr($this->r::ahora(),0,10),'secuencial'=>'000000001','codigo_numerico'=>'00000000','comprador'=>['tipo'=>'05','identificacion'=>'0000000000','nombre'=>'Validación local de configuración'],'lineas'=>[['codigo'=>'CONFIG','descripcion'=>'Validación local','cantidad_milesimas'=>1000,'precio_centavos'=>1,'impuesto_pb'=>0,'codigo_porcentaje'=>'0']],'pagos'=>[['forma'=>'01','total_centavos'=>1]]];
            SriFirmaXades::firmar(SriComprobante::factura($e,$prueba),$p12,$password);
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$secreto=json_encode(['p12'=>base64_encode($p12),'password'=>$password],JSON_THROW_ON_ERROR);
            $cifrado=base64_encode($nonce.sodium_crypto_secretbox($secreto,$nonce,Secretos::clave('sri-certificados')));
            $this->r->q('INSERT INTO erp_fiscal_perfiles(ruc,ambiente,establecimiento,punto_emision,datos_json,certificado_cifrado,certificado_sha256,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?)',[$e['ruc'],'1',$e['establecimiento'],$e['punto_emision'],json_encode($e,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$cifrado,$d['certificado_sha256'],$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'fiscal_perfil_creado','fiscal_perfil',$id,['ruc'=>$e['ruc'],'ambiente'=>'1','certificado_sha256'=>$d['certificado_sha256']]);return $id;
        });
    }

    /** La primera integración prepara facturas de ventas atendidas/entregadas sin devoluciones ni prepagos. */
    public function factura(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','fiscal_factura',$d,function()use($actor,$d){
            $venta=$this->id($d['venta_id']??null);$perfil=$this->id($d['perfil_id']??null);$comprador=$d['comprador']??[];$categorias=$d['categorias_iva']??[];$pagos=$d['pagos']??[];
            $clave=hash('sha256','fiscal.factura:'.$actor.':'.$d['idem_key']);$v=(new ErpVentaService($this->r))->bloquear($venta);
            if(!$pagos&&isset($d['forma_pago']))$pagos=[['forma'=>$d['forma_pago'],'total_centavos'=>(int)$v['total_centavos']]];
            $huella=hash('sha256',json_encode([$venta,$perfil,$comprador,$categorias,$pagos],JSON_THROW_ON_ERROR));$previa=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE venta_id=? AND tipo=\'01\' FOR SHARE',[$venta]);
            if($previa){if($previa['clave_operacion']!==$clave||!hash_equals($previa['huella'],$huella))throw new ErpException('La venta ya tiene un comprobante fiscal; conserva su clave y revisa su estado.',409);return (int)$previa['id'];}
            if($v['estado']!=='confirmada')throw new ErpException('Confirma primero la venta.');
            if($this->r->uno('SELECT id FROM erp_devoluciones WHERE venta_id=? LIMIT 1',[$venta])||$this->r->uno("SELECT pago_id FROM erp_prepago_movimientos WHERE venta_id=? AND tipo='aplicacion' LIMIT 1",[$venta]))throw new ErpException('El piloto no procesa ventas con devolución o aplicación de prepago.');
            $lineas=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id FOR SHARE',[$venta]);$fiscales=[];
            $cita=$v['cita_id']?$this->r->uno('SELECT estado FROM citas WHERE id=?',[(int)$v['cita_id']]):null;
            foreach($lineas as $l){
                if(((int)$l['producto_id']>0&&(int)$l['entregado_milesimas']!==(int)$l['cantidad_milesimas'])||((int)$l['servicio_id']>0&&($cita['estado']??'')!=='completada'))throw new ErpException('El piloto exige todos los servicios atendidos y productos entregados.');
                $fiscales[]=['linea_id'=>(int)$l['id'],'codigo'=>($l['producto_id']?'P':'S').($l['producto_id']?:$l['servicio_id']),'descripcion'=>$l['nombre'],'cantidad_milesimas'=>(int)$l['cantidad_milesimas'],'precio_centavos'=>(int)$l['precio_centavos'],'descuento_centavos'=>(int)$l['descuento_centavos'],'impuesto_pb'=>(int)$l['impuesto_pb'],'codigo_porcentaje'=>$categorias[(string)$l['id']]??null];
            }
            $p=$this->r->uno('SELECT * FROM erp_fiscal_perfiles WHERE id=? FOR SHARE',[$perfil])??throw new ErpException('Perfil fiscal no encontrado.');$e=json_decode($p['datos_json'],true,512,JSON_THROW_ON_ERROR);$serie=$p['establecimiento'].$p['punto_emision'];
            $n=$this->reservarNumero($p,'01');
            $datos=['fecha'=>substr($this->r::ahora(),0,10),'secuencial'=>str_pad((string)$n,9,'0',STR_PAD_LEFT),'codigo_numerico'=>str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),'comprador'=>$comprador,'lineas'=>$fiscales,'pagos'=>$pagos,'total_centavos'=>(int)$v['total_centavos']];
            [$p12,$password]=$this->certificado($p);$xml=SriFirmaXades::firmar(SriComprobante::factura($e,$datos),$p12,$password);$claveAcceso=SriXml::cargar($xml)->getElementsByTagName('claveAcceso')->item(0)->textContent;
            $this->confirmarNumero($p,'01',$n);
            $ahora=$this->r::ahora();$numero=$p['establecimiento'].'-'.$p['punto_emision'].'-'.$datos['secuencial'];$snapshot=json_encode(['emisor'=>$e,'factura'=>$datos],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->r->q('INSERT INTO erp_fiscal_documentos(perfil_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$perfil,$venta,$clave,$huella,$claveAcceso,$numero,$snapshot,$xml,hash('sha256',$xml),$actor,$ahora,$ahora]);$id=$this->r->id();$this->evento($actor,'fiscal_documento_preparado','fiscal_documento',$id,['venta_id'=>$venta,'ambiente'=>'1','numero'=>$numero,'xml_sha256'=>hash('sha256',$xml)]);return $id;
        });
    }
    /** La identidad del contador no depende de la versión de dirección o certificado del emisor; cada tipo de documento lleva el suyo. */
    private function reservarNumero(array $p,string $tipo):int
    {
        $serie=$p['establecimiento'].$p['punto_emision'];
        $this->r->q("INSERT INTO erp_fiscal_secuencias(ruc,ambiente,serie,documento) VALUES(?,'1',?,?) ON DUPLICATE KEY UPDATE ultimo=ultimo",[$p['ruc'],$serie,$tipo]);
        $s=$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE ruc=? AND ambiente='1' AND serie=? AND documento=? FOR UPDATE",[$p['ruc'],$serie,$tipo]);$n=(int)$s['ultimo']+1;
        if($n>999999999)throw new ErpException('Secuencial fiscal agotado.');return $n;
    }
    private function confirmarNumero(array $p,string $tipo,int $n):void
    {
        $this->r->q("UPDATE erp_fiscal_secuencias SET ultimo=? WHERE ruc=? AND ambiente='1' AND serie=? AND documento=?",[$n,$p['ruc'],$p['establecimiento'].$p['punto_emision'],$tipo]);
    }

    /**
     * Nota de crédito fiscal (codDoc 04) sobre una factura AUTORIZADA, a partir de devoluciones comerciales ya registradas.
     * Es un hecho distinto de la devolución de dinero, del reintegro físico y del reverso de comisión: no los ejecuta ni los altera.
     * Cada devolución solo puede acreditarse una vez; la suma de las notas de una línea no supera la cantidad ni el total facturados.
     */
    public function notaCredito(int $actor,array $d):array
    {
        $this->permitir($actor,'erp.devolver');
        return $this->ejecutar($actor,'erp.cobrar','fiscal_nota_credito',$d,function()use($actor,$d){
            $this->permitir($actor,'erp.devolver');
            $fid=$this->id($d['factura_id']??null);$motivo=$this->texto($d['motivo']??'',300);
            $ids=$d['devolucion_ids']??null;if(!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>100)throw new ErpException('Selecciona de 1 a 100 devoluciones.');
            $ids=array_values(array_unique(array_map(fn($v)=>$this->id($v),$ids)));sort($ids);
            $clave=hash('sha256','fiscal.nc:'.$actor.':'.$d['idem_key']);$huella=hash('sha256',json_encode([$fid,$ids,$motivo],JSON_THROW_ON_ERROR));
            // Orden de bloqueo como en las devoluciones: venta antes que el documento fiscal.
            $f0=$this->r->uno("SELECT venta_id FROM erp_fiscal_documentos WHERE id=? AND tipo='01'",[$fid])??throw new ErpException('Factura no encontrada.',404);
            $v=(new ErpVentaService($this->r))->bloquear((int)$f0['venta_id']);
            $f=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[$fid]);
            $previa=$this->r->uno('SELECT id,huella FROM erp_fiscal_documentos WHERE clave_operacion=? FOR SHARE',[$clave]);
            if($previa){if(!hash_equals($previa['huella'],$huella))throw new ErpException('La clave de operación ya se usó con otros datos.',409);return (int)$previa['id'];}
            if($f['estado']!=='autorizada')throw new ErpException('La factura todavía no tiene autorización del SRI; no se puede acreditar.',409);
            $snap=json_decode($f['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$fac=$snap['factura'];$e=$snap['emisor'];
            $marcas=implode(',',array_fill(0,count($ids),'?'));
            $devs=$this->r->q("SELECT * FROM erp_devoluciones WHERE id IN($marcas) AND venta_id=? ORDER BY id FOR SHARE",[...$ids,(int)$f['venta_id']]);
            if(count($devs)!==count($ids))throw new ErpException('Alguna devolución no pertenece a la venta de esta factura.',409);
            $ya=$this->r->uno("SELECT n.documento_id FROM erp_fiscal_nc_devoluciones n WHERE n.devolucion_id IN($marcas) LIMIT 1 FOR SHARE",$ids);
            if($ya)throw new ErpException('Una devolución ya está incluida en la nota de crédito #'.(int)$ya['documento_id'].'.',409);
            // Lecturas bloqueantes (FOR SHARE): tras esperar el candado de la venta se ven las notas confirmadas por otro proceso.
            // Cantidades por línea de venta; las líneas fiscales se relacionan por linea_id (o por posición en facturas anteriores a 040).
            $porLinea=[];foreach($devs as $dev)$porLinea[(int)$dev['linea_id']]=($porLinea[(int)$dev['linea_id']]??0)+(int)$dev['cantidad_milesimas'];
            $lineasVenta=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id FOR SHARE',[(int)$f['venta_id']]);
            if(count($lineasVenta)!==count($fac['lineas']))throw new ErpException('Las líneas de la venta ya no coinciden con la factura.',409);
            $fiscalPorLinea=[];foreach($lineasVenta as $i=>$lv){
                $fl=$fac['lineas'][$i];if(isset($fl['linea_id'])&&(int)$fl['linea_id']!==(int)$lv['id'])throw new ErpException('Las líneas de la venta ya no coinciden con la factura.',409);
                if((int)$fl['cantidad_milesimas']!==(int)$lv['cantidad_milesimas']||(int)$fl['precio_centavos']!==(int)$lv['precio_centavos']||(int)($fl['descuento_centavos']??0)!==(int)$lv['descuento_centavos']||(int)$fl['impuesto_pb']!==(int)$lv['impuesto_pb'])throw new ErpException('Las líneas de la venta ya no coinciden con la factura.',409);
                $fiscalPorLinea[(int)$lv['id']]=$fl;
            }
            $acum=[];foreach($this->r->q("SELECT l.linea_id,SUM(l.cantidad_milesimas) q,SUM(l.total_centavos) t FROM erp_fiscal_nc_lineas l JOIN erp_fiscal_documentos x ON x.id=l.documento_id WHERE x.documento_origen_id=? GROUP BY l.linea_id FOR SHARE",[$fid]) as $a)$acum[(int)$a['linea_id']]=[(int)$a['q'],(int)$a['t']];
            $lineasNc=[];$filasRel=[];$totalNc=0;
            foreach($porLinea as $lineaId=>$q){
                $fl=$fiscalPorLinea[$lineaId]??throw new ErpException('La línea no pertenece a la factura.',409);[$qp,$tp]=$acum[$lineaId]??[0,0];
                $c=Fiscal\SriNotaCreditoCalculo::linea(['cantidad'=>(int)$fl['cantidad_milesimas'],'precio'=>(int)$fl['precio_centavos'],'descuento'=>(int)($fl['descuento_centavos']??0),'impuesto_pb'=>(int)$fl['impuesto_pb']],$qp,$q,$tp);
                $lineasNc[]=['linea_id'=>$lineaId,'codigo'=>$fl['codigo'],'descripcion'=>$fl['descripcion'],'cantidad_milesimas'=>$c['cantidad'],'precio_centavos'=>$c['precio'],'descuento_centavos'=>$c['descuento'],'impuesto_pb'=>(int)$fl['impuesto_pb'],'codigo_porcentaje'=>$fl['codigo_porcentaje']];
                $filasRel[]=[$lineaId,$c['cantidad'],$c['base'],$c['iva'],$c['total']];$totalNc+=$c['total'];
            }
            $facturado=0;foreach($fac['lineas'] as $fl)$facturado+=\Services\ErpCalculo::linea((int)$fl['cantidad_milesimas'],(int)$fl['precio_centavos'],(int)($fl['descuento_centavos']??0),(int)$fl['impuesto_pb'])['total'];
            $previoTotal=(int)$this->r->uno('SELECT COALESCE(SUM(l.total_centavos),0) t FROM erp_fiscal_nc_lineas l JOIN erp_fiscal_documentos x ON x.id=l.documento_id WHERE x.documento_origen_id=? FOR SHARE',[$fid])['t'];
            if($previoTotal+$totalNc>$facturado)throw new ErpException('Las notas de crédito superarían el total de la factura.',409);
            $p=$this->r->uno('SELECT * FROM erp_fiscal_perfiles WHERE id=? FOR SHARE',[(int)$f['perfil_id']])??throw new ErpException('Perfil fiscal no encontrado.');
            $n=$this->reservarNumero($p,'04');
            $datos=['fecha'=>max(substr($this->r::ahora(),0,10),$fac['fecha']),'secuencial'=>str_pad((string)$n,9,'0',STR_PAD_LEFT),'codigo_numerico'=>str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),'comprador'=>$fac['comprador'],'lineas'=>$lineasNc,'motivo'=>$motivo,'sustento'=>['numero'=>$f['numero'],'fecha'=>$fac['fecha']],'total_centavos'=>$totalNc];
            [$p12,$password]=$this->certificado($p);$xml=SriFirmaXades::firmar(SriComprobante::notaCredito($e,$datos),$p12,$password);$claveAcceso=SriXml::cargar($xml)->getElementsByTagName('claveAcceso')->item(0)->textContent;
            $this->confirmarNumero($p,'04',$n);
            $ahora=$this->r::ahora();$numero=$p['establecimiento'].'-'.$p['punto_emision'].'-'.$datos['secuencial'];
            $comercial=array_sum(array_map(fn($x)=>(int)$x['monto_centavos'],$devs));
            $snapshot=json_encode(['emisor'=>$e,'nota_credito'=>$datos+['devoluciones'=>$ids,'monto_comercial_centavos'=>$comercial,'diferencia_redondeo_centavos'=>$comercial-$totalNc]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->r->q("INSERT INTO erp_fiscal_documentos(perfil_id,tipo,documento_origen_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) VALUES(?,'04',?,?,?,?,?,?,?,?,?,?,?,?)",[(int)$f['perfil_id'],$fid,(int)$f['venta_id'],$clave,$huella,$claveAcceso,$numero,$snapshot,$xml,hash('sha256',$xml),$actor,$ahora,$ahora]);$id=$this->r->id();
            foreach($ids as $devId)$this->r->q('INSERT INTO erp_fiscal_nc_devoluciones(documento_id,devolucion_id) VALUES(?,?)',[$id,$devId]);
            foreach($filasRel as [$li,$qq,$b,$iv,$tt])$this->r->q('INSERT INTO erp_fiscal_nc_lineas(documento_id,linea_id,cantidad_milesimas,base_centavos,iva_centavos,total_centavos) VALUES(?,?,?,?,?,?)',[$id,$li,$qq,$b,$iv,$tt]);
            $this->evento($actor,'fiscal_nota_credito_preparada','fiscal_documento',$id,['factura_id'=>$fid,'venta_id'=>(int)$f['venta_id'],'numero'=>$numero,'devoluciones'=>$ids,'total_centavos'=>$totalNc,'monto_comercial_centavos'=>$comercial,'motivo'=>$motivo,'ambiente'=>'1','xml_sha256'=>hash('sha256',$xml)]);
            return $id;
        });
    }
    private function certificado(array $p):array
    {
        $raw=base64_decode($p['certificado_cifrado'],true);if($raw===false||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new ErpException('Certificado cifrado no disponible.');
        $texto=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),Secretos::clave('sri-certificados'));
        if($texto===false)throw new ErpException('No se pudo recuperar el certificado con APP_KEY.');$datos=json_decode($texto,true,512,JSON_THROW_ON_ERROR);$p12=base64_decode($datos['p12'],true);
        if($p12===false||!hash_equals($p['certificado_sha256'],hash('sha256',$p12)))throw new ErpException('El certificado no coincide con su huella.');return [$p12,$datos['password']];
    }

    /** Un lease limita la concurrencia. La consulta y recepción HTTPS siempre ocurren fuera de la transacción. */
    public function procesar(?\Interfaces\SriTransporteInterface $transporte=null):array
    {
        $this->cli();if($transporte!==null&&getenv('APP_ENV')!=='testing')throw new ErpException('El transporte de ensayo exige entorno de pruebas.');$salida=['autorizados'=>0,'rechazados'=>0,'pendientes'=>0];
        for($i=0;$i<20;$i++){
            $doc=Transaccion::ejecutar($this->r->db,function(){
                $ahora=$this->r::ahora();$d=$this->r->uno("SELECT * FROM erp_fiscal_documentos WHERE estado IN('pendiente','recibida') AND (proximo_intento IS NULL OR proximo_intento<=?) AND (lease_hasta IS NULL OR lease_hasta<?) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED",[$ahora,$ahora]);if(!$d)return null;
                $d['lease_token']=bin2hex(random_bytes(16));$this->r->q('UPDATE erp_fiscal_documentos SET lease_token=?,lease_hasta=DATE_ADD(?,INTERVAL 2 MINUTE),intentos=intentos+1 WHERE id=?',[$d['lease_token'],$ahora,(int)$d['id']]);return $d;
            });if(!$doc)break;
            try{
                if(!hash_equals($doc['xml_sha256'],hash('sha256',$doc['xml_firmado'])))throw new \RuntimeException('Contenido fiscal inconsistente.');
                $sri=$transporte?new SriClienteSoap('1',$transporte):new SriClienteSoap('1');$respuesta=$sri->consultar($doc['clave_acceso'],$doc['xml_firmado']);$estado=$doc['estado'];
                if($respuesta['estado']==='AUTORIZADO')$estado='autorizada';elseif($respuesta['estado']==='NO AUTORIZADO')$estado='rechazada';elseif($estado==='pendiente'){
                    $respuesta=$sri->recibir($doc['xml_firmado']);$registrada=(bool)array_filter($respuesta['mensajes'],fn($m)=>in_array($m['identificador'],['43','70'],true));$temporal=(bool)array_filter($respuesta['mensajes'],fn($m)=>$m['identificador']==='50');$estado=$respuesta['estado']==='RECIBIDA'||$registrada?'recibida':($temporal?'pendiente':'rechazada');
                }
                $this->finalizar($doc,$estado,$respuesta);$salida[$estado==='autorizada'?'autorizados':($estado==='rechazada'?'rechazados':'pendientes')]++;
            }catch(\Throwable $e){$this->finalizar($doc,$doc['estado'],['estado'=>'REINTENTAR','mensaje'=>'No se obtuvo una respuesta verificable del SRI. Se consultará la misma clave.']);$salida['pendientes']++;}
        }return $salida;
    }
    private function finalizar(array $doc,string $estado,array $respuesta):void
    {
        Transaccion::ejecutar($this->r->db,function()use($doc,$estado,$respuesta){
            $actual=$this->r->uno('SELECT lease_token FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[(int)$doc['id']]);if(!hash_equals($doc['lease_token'],$actual['lease_token']??''))return;
            $ahora=$this->r::ahora();$this->r->q('UPDATE erp_fiscal_documentos SET estado=?,respuesta_json=?,proximo_intento=DATE_ADD(?,INTERVAL 1 MINUTE),lease_token=NULL,lease_hasta=NULL,actualizado_en=? WHERE id=?',[$estado,json_encode($respuesta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$ahora,$ahora,(int)$doc['id']]);$this->evento(0,'fiscal_estado','fiscal_documento',(int)$doc['id'],['estado'=>$estado,'ambiente'=>'1']);
        });
    }
}
