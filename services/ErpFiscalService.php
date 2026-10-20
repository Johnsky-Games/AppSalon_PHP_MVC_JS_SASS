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
            $e=$d['emisor'];$permitidas=['ruc','ambiente','establecimiento','punto_emision','razon_social','dir_matriz','obligado_contabilidad','nombre_comercial','dir_establecimiento','contribuyente_especial','agente_retencion','contribuyente_rimpe','regimen'];
            if(array_diff(array_keys($e),$permitidas))throw new ErpException('La configuración del emisor contiene campos no admitidos.');
            if(isset($e['regimen'])){if(!in_array($e['regimen'],['general','rimpe_emprendedor','rimpe_negocio_popular'],true))throw new ErpException('Régimen no válido: general, rimpe_emprendedor o rimpe_negocio_popular.');if(!empty($e['contribuyente_rimpe']))throw new ErpException('Con régimen declarado la leyenda proviene del parámetro fiscal «regimen.leyenda»; no la repitas en el emisor.');}
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
        return $this->ejecutar($actor,'erp.cobrar','fiscal_factura',$d,fn()=>$this->crearFactura($actor,$d,null));
    }
    /** Cuerpo común de la factura y de su sustitución ($sust = comprobante rechazado, ya verificado y bloqueado). */
    private function crearFactura(int $actor,array $d,?array $sust):int
    {
            $venta=$this->id($d['venta_id']??null);$perfil=$this->id($d['perfil_id']??null);$comprador=$d['comprador']??[];$categorias=$d['categorias_iva']??[];$pagos=$d['pagos']??[];
            $clave=hash('sha256',($sust?'fiscal.factura.sustitucion:'.(int)$sust['id'].':':'fiscal.factura:').$actor.':'.$d['idem_key']);$v=(new ErpVentaService($this->r))->bloquear($venta);
            if(!$pagos&&isset($d['forma_pago']))$pagos=[['forma'=>$d['forma_pago'],'total_centavos'=>(int)$v['total_centavos']]];
            $huella=hash('sha256',json_encode([$venta,$perfil,$comprador,$categorias,$pagos],JSON_THROW_ON_ERROR));$previa=$sust?$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE clave_operacion=? FOR SHARE',[$clave]):$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE venta_id=? AND tipo=\'01\' AND sustituye_a_id IS NULL FOR SHARE',[$venta]);
            if($previa){if($previa['clave_operacion']!==$clave||!hash_equals($previa['huella'],$huella))throw new ErpException('La venta ya tiene un comprobante fiscal; conserva su clave y revisa su estado.',409);return (int)$previa['id'];}
            if($v['estado']!=='confirmada')throw new ErpException('Confirma primero la venta.');
            if($this->r->uno('SELECT id FROM erp_devoluciones WHERE venta_id=? LIMIT 1',[$venta]))throw new ErpException('El piloto no procesa ventas con devolución previa a la factura.');
            $par=new ErpFiscalParametroService($this->r);$fechaEmision=substr($this->r::ahora(),0,10);$usados=[];
            $lineas=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id FOR SHARE',[$venta]);$fiscales=[];
            $cita=$v['cita_id']?$this->r->uno('SELECT estado FROM citas WHERE id=?',[(int)$v['cita_id']]):null;
            foreach($lineas as $l){
                if(((int)$l['producto_id']>0&&(int)$l['entregado_milesimas']!==(int)$l['cantidad_milesimas'])||((int)$l['servicio_id']>0&&($cita['estado']??'')!=='completada'))throw new ErpException('El piloto exige todos los servicios atendidos y productos entregados.');
                $fiscales[]=['linea_id'=>(int)$l['id'],'codigo'=>($l['producto_id']?'P':'S').($l['producto_id']?:$l['servicio_id']),'descripcion'=>$l['nombre'],'cantidad_milesimas'=>(int)$l['cantidad_milesimas'],'precio_centavos'=>(int)$l['precio_centavos'],'descuento_centavos'=>(int)$l['descuento_centavos'],'impuesto_pb'=>(int)$l['impuesto_pb'],'codigo_porcentaje'=>$categorias[(string)$l['id']]??null];
            }
            $p=$this->r->uno('SELECT * FROM erp_fiscal_perfiles WHERE id=? FOR SHARE',[$perfil])??throw new ErpException('Perfil fiscal no encontrado.');$e=json_decode($p['datos_json'],true,512,JSON_THROW_ON_ERROR);$serie=$p['establecimiento'].$p['punto_emision'];
            $regimen=$e['regimen']??'*';$consumidorFinal=($comprador['tipo']??'')==='07';
            // Cobro anticipado: el IVA de servicios se factura al prestarlos (elección del contribuyente, registrada); la carga o el anticipo no genera comprobante.
            if($this->r->uno("SELECT pago_id FROM erp_prepago_movimientos WHERE venta_id=? AND tipo='aplicacion' LIMIT 1",[$venta]))$usados[]=ErpFiscalParametroService::referencia($par->requerir('servicios.hecho_generador','*',$fechaEmision));
            foreach(array_unique(array_filter(array_map('strval',$fiscales?array_column($fiscales,'codigo_porcentaje'):[]),static fn(string $c)=>$c!==''))as $codigo){
                $pi=$par->requerir(ErpFiscalParametroService::claveIva($codigo),$regimen,$fechaEmision);$usados[]=ErpFiscalParametroService::referencia($pi);
            }
            if(in_array($regimen,['rimpe_emprendedor','rimpe_negocio_popular'],true)){$l=$par->requerir('regimen.leyenda',$regimen,$fechaEmision);$usados[]=ErpFiscalParametroService::referencia($l);$e['contribuyente_rimpe']=$l['valor_texto'];}
            if($consumidorFinal){
                $max=$par->requerir('consumidor_final.total_maximo_centavos',$regimen,$fechaEmision);$usados[]=ErpFiscalParametroService::referencia($max);
                if((int)$v['total_centavos']>(int)$max['valor_entero'])throw new ErpException('El total supera el máximo permitido para consumidor final ('.number_format((int)$max['valor_entero']/100,2,'.','').' USD según el parámetro vigente): identifica al comprador.',409);
                $comprador=['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'CONSUMIDOR FINAL'];
            }
            $n=$this->reservarNumero($p,'01');
            $datos=['fecha'=>substr($this->r::ahora(),0,10),'secuencial'=>str_pad((string)$n,9,'0',STR_PAD_LEFT),'codigo_numerico'=>str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),'comprador'=>$comprador,'lineas'=>$fiscales,'pagos'=>$pagos,'total_centavos'=>(int)$v['total_centavos'],'consumidor_final'=>$consumidorFinal];
            [$p12,$password]=$this->certificado($p);$xml=SriFirmaXades::firmar(SriComprobante::factura($e,$datos),$p12,$password);$claveAcceso=SriXml::cargar($xml)->getElementsByTagName('claveAcceso')->item(0)->textContent;
            $this->confirmarNumero($p,'01',$n);
            $ahora=$this->r::ahora();$numero=$p['establecimiento'].'-'.$p['punto_emision'].'-'.$datos['secuencial'];$snapshot=json_encode(['emisor'=>$e,'factura'=>$datos,'parametros'=>$usados],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->r->q('INSERT INTO erp_fiscal_documentos(perfil_id,sustituye_a_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$perfil,$sust?(int)$sust['id']:null,$venta,$clave,$huella,$claveAcceso,$numero,$snapshot,$xml,hash('sha256',$xml),$actor,$ahora,$ahora]);$id=$this->r->id();$this->evento($actor,'fiscal_documento_preparado','fiscal_documento',$id,['venta_id'=>$venta,'ambiente'=>'1','numero'=>$numero,'xml_sha256'=>hash('sha256',$xml)]);return $id;
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
            return $this->crearNotaCredito($actor,$d,null);
        });
    }
    /** Cuerpo común de la nota y de su sustitución ($sust = nota rechazada ya verificada y bloqueada: hereda sus devoluciones). */
    private function crearNotaCredito(int $actor,array $d,?array $sust):int
    {
            $fid=$sust?(int)$sust['documento_origen_id']:$this->id($d['factura_id']??null);$motivo=$this->texto($d['motivo']??'',300);
            $extra=$d['devolucion_ids']??($sust?[]:null);if(!is_array($extra)||!array_is_list($extra)||(!$sust&&!$extra)||count($extra)>100)throw new ErpException('Selecciona de 1 a 100 devoluciones.');
            $extra=array_values(array_unique(array_map(fn($v)=>$this->id($v),$extra)));sort($extra);
            // Una sustituta hereda TODAS las devoluciones de la cadena de notas rechazadas (sin volver a vincularlas) y puede sumar otras pendientes.
            $heredadas=$sust?array_map(fn($x)=>(int)$x['devolucion_id'],$this->r->q('WITH RECURSIVE cadena(id,sustituye_a_id) AS (SELECT id,sustituye_a_id FROM erp_fiscal_documentos WHERE id=? UNION ALL SELECT d.id,d.sustituye_a_id FROM erp_fiscal_documentos d JOIN cadena c ON d.id=c.sustituye_a_id) SELECT n.devolucion_id FROM erp_fiscal_nc_devoluciones n JOIN cadena c ON c.id=n.documento_id ORDER BY n.devolucion_id FOR SHARE',[(int)$sust['id']])):[];
            if(array_intersect($extra,$heredadas))throw new ErpException('Esa devolución ya pertenece a la nota que se sustituye.',409);
            $ids=array_values(array_unique([...$heredadas,...$extra]));sort($ids);
            $clave=hash('sha256',($sust?'fiscal.nc.sustitucion:'.(int)$sust['id'].':':'fiscal.nc:').$actor.':'.$d['idem_key']);$huella=hash('sha256',json_encode([$fid,$ids,$motivo,$sust?(int)$sust['id']:null],JSON_THROW_ON_ERROR));
            // Orden de bloqueo como en las devoluciones: venta antes que el documento fiscal.
            $f0=$this->r->uno("SELECT venta_id FROM erp_fiscal_documentos WHERE id=? AND tipo='01'",[$fid])??throw new ErpException('Factura no encontrada.',404);
            $v=(new ErpVentaService($this->r))->bloquear((int)$f0['venta_id']);
            $f=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[$fid]);
            // Regla del SRI (resolución NAC-DGERCGC25-00000017, según fuentes secundarias; ver docs): una factura a consumidor final no se anula ni se modifica, tampoco con nota de crédito.
            if((json_decode($f['snapshot_json'],true)['factura']['comprador']['tipo']??'')==='07')throw new ErpException('Una factura a consumidor final no admite nota de crédito. Si requiere ajuste, consulta el procedimiento vigente con el SRI.',409);
            $previa=$this->r->uno('SELECT id,huella FROM erp_fiscal_documentos WHERE clave_operacion=? FOR SHARE',[$clave]);
            if($previa){if(!hash_equals($previa['huella'],$huella))throw new ErpException('La clave de operación ya se usó con otros datos.',409);return (int)$previa['id'];}
            if($f['estado']!=='autorizada')throw new ErpException('La factura todavía no tiene autorización del SRI; no se puede acreditar.',409);
            $snap=json_decode($f['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$fac=$snap['factura'];$e=$snap['emisor'];
            $marcas=implode(',',array_fill(0,count($ids),'?'));
            $devs=$this->r->q("SELECT * FROM erp_devoluciones WHERE id IN($marcas) AND venta_id=? ORDER BY id FOR SHARE",[...$ids,(int)$f['venta_id']]);
            if(count($devs)!==count($ids))throw new ErpException('Alguna devolución no pertenece a la venta de esta factura.',409);
            $ya=$extra?$this->r->uno('SELECT n.documento_id FROM erp_fiscal_nc_devoluciones n WHERE n.devolucion_id IN('.implode(',',array_fill(0,count($extra),'?')).') LIMIT 1 FOR SHARE',$extra):null;
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
            $acum=[];foreach($this->r->q("SELECT l.linea_id,SUM(l.cantidad_milesimas) q,SUM(l.total_centavos) t FROM erp_fiscal_nc_lineas l JOIN erp_fiscal_documentos x ON x.id=l.documento_id WHERE x.documento_origen_id=? AND x.estado NOT IN('rechazada','bloqueada') GROUP BY l.linea_id FOR SHARE",[$fid]) as $a)$acum[(int)$a['linea_id']]=[(int)$a['q'],(int)$a['t']];
            $lineasNc=[];$filasRel=[];$totalNc=0;
            foreach($porLinea as $lineaId=>$q){
                $fl=$fiscalPorLinea[$lineaId]??throw new ErpException('La línea no pertenece a la factura.',409);[$qp,$tp]=$acum[$lineaId]??[0,0];
                $c=Fiscal\SriNotaCreditoCalculo::linea(['cantidad'=>(int)$fl['cantidad_milesimas'],'precio'=>(int)$fl['precio_centavos'],'descuento'=>(int)($fl['descuento_centavos']??0),'impuesto_pb'=>(int)$fl['impuesto_pb']],$qp,$q,$tp);
                $lineasNc[]=['linea_id'=>$lineaId,'codigo'=>$fl['codigo'],'descripcion'=>$fl['descripcion'],'cantidad_milesimas'=>$c['cantidad'],'precio_centavos'=>$c['precio'],'descuento_centavos'=>$c['descuento'],'impuesto_pb'=>(int)$fl['impuesto_pb'],'codigo_porcentaje'=>$fl['codigo_porcentaje']];
                $filasRel[]=[$lineaId,$c['cantidad'],$c['base'],$c['iva'],$c['total']];$totalNc+=$c['total'];
            }
            $facturado=0;foreach($fac['lineas'] as $fl)$facturado+=\Services\ErpCalculo::linea((int)$fl['cantidad_milesimas'],(int)$fl['precio_centavos'],(int)($fl['descuento_centavos']??0),(int)$fl['impuesto_pb'])['total'];
            $previoTotal=(int)$this->r->uno('SELECT COALESCE(SUM(l.total_centavos),0) t FROM erp_fiscal_nc_lineas l JOIN erp_fiscal_documentos x ON x.id=l.documento_id WHERE x.documento_origen_id=? AND x.estado NOT IN(\'rechazada\',\'bloqueada\') FOR SHARE',[$fid])['t'];
            if($previoTotal+$totalNc>$facturado)throw new ErpException('Las notas de crédito superarían el total de la factura.',409);
            $p=$this->r->uno('SELECT * FROM erp_fiscal_perfiles WHERE id=? FOR SHARE',[(int)$f['perfil_id']])??throw new ErpException('Perfil fiscal no encontrado.');
            $n=$this->reservarNumero($p,'04');
            $datos=['fecha'=>max(substr($this->r::ahora(),0,10),$fac['fecha']),'secuencial'=>str_pad((string)$n,9,'0',STR_PAD_LEFT),'codigo_numerico'=>str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),'comprador'=>$fac['comprador'],'lineas'=>$lineasNc,'motivo'=>$motivo,'sustento'=>['numero'=>$f['numero'],'fecha'=>$fac['fecha']],'total_centavos'=>$totalNc];
            [$p12,$password]=$this->certificado($p);$xml=SriFirmaXades::firmar(SriComprobante::notaCredito($e,$datos),$p12,$password);$claveAcceso=SriXml::cargar($xml)->getElementsByTagName('claveAcceso')->item(0)->textContent;
            $this->confirmarNumero($p,'04',$n);
            $ahora=$this->r::ahora();$numero=$p['establecimiento'].'-'.$p['punto_emision'].'-'.$datos['secuencial'];
            $comercial=array_sum(array_map(fn($x)=>(int)$x['monto_centavos'],$devs));
            $snapshot=json_encode(['emisor'=>$e,'nota_credito'=>$datos+['devoluciones'=>$ids,'monto_comercial_centavos'=>$comercial,'diferencia_redondeo_centavos'=>$comercial-$totalNc]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->r->q("INSERT INTO erp_fiscal_documentos(perfil_id,tipo,documento_origen_id,sustituye_a_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) VALUES(?,'04',?,?,?,?,?,?,?,?,?,?,?,?,?)",[(int)$f['perfil_id'],$fid,$sust?(int)$sust['id']:null,(int)$f['venta_id'],$clave,$huella,$claveAcceso,$numero,$snapshot,$xml,hash('sha256',$xml),$actor,$ahora,$ahora]);$id=$this->r->id();
            foreach($extra as $devId)$this->r->q('INSERT INTO erp_fiscal_nc_devoluciones(documento_id,devolucion_id) VALUES(?,?)',[$id,$devId]);
            foreach($filasRel as [$li,$qq,$b,$iv,$tt])$this->r->q('INSERT INTO erp_fiscal_nc_lineas(documento_id,linea_id,cantidad_milesimas,base_centavos,iva_centavos,total_centavos) VALUES(?,?,?,?,?,?)',[$id,$li,$qq,$b,$iv,$tt]);
            $this->evento($actor,'fiscal_nota_credito_preparada','fiscal_documento',$id,['factura_id'=>$fid,'venta_id'=>(int)$f['venta_id'],'numero'=>$numero,'devoluciones'=>$ids,'sustituye_a'=>$sust?(int)$sust['id']:null,'total_centavos'=>$totalNc,'monto_comercial_centavos'=>$comercial,'motivo'=>$motivo,'ambiente'=>'1','xml_sha256'=>hash('sha256',$xml)]);
            return $id;
    }

    /**
     * Un comprobante RECHAZADO nunca se edita ni se reutiliza: se conserva con su XML, clave, número y respuesta, y se sustituye por otro.
     * Antes de permitir la sustitución el worker contrasta la clave con el SRI (fuera de la transacción). Solo un rechazo CONFIRMADO,
     * reciente y sin sucesor habilita la sustitución; un comprobante que el SRI reporta autorizado, o una respuesta incierta, la bloquean.
     */
    public function solicitarVerificacion(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.configurar','fiscal_verificacion',$d,function()use($actor,$d){
            $id=$this->id($d['documento_id']??null);
            $doc=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Documento fiscal no encontrado.',404);
            if($doc['estado']!=='rechazada')throw new ErpException('Solo se verifica un comprobante rechazado; este sigue en proceso o fue autorizado.',409);
            if($this->r->uno('SELECT id FROM erp_fiscal_documentos WHERE sustituye_a_id=?',[$id]))throw new ErpException('Este comprobante ya fue sustituido.',409);
            $abierta=$this->r->uno("SELECT id FROM erp_fiscal_verificaciones WHERE documento_id=? AND estado IN('solicitada','incierta') FOR UPDATE",[$id]);if($abierta)return (int)$abierta['id'];
            $this->r->q("INSERT INTO erp_fiscal_verificaciones(documento_id,solicitado_por,solicitado_en) VALUES(?,?,?)",[$id,$actor,$this->r::ahora()]);$vid=$this->r->id();
            $this->evento($actor,'fiscal_verificacion_solicitada','fiscal_documento',$id,['verificacion_id'=>$vid,'numero'=>$doc['numero'],'tipo'=>$doc['tipo'],'ambiente'=>'1']);return $vid;
        });
    }
    private function sustituible(int $id,string $tipo):array
    {
        $doc=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Documento fiscal no encontrado.',404);
        if($doc['tipo']!==$tipo)throw new ErpException('El documento no es del tipo que se quiere sustituir.',409);
        if($doc['estado']!=='rechazada')throw new ErpException('Solo se sustituye un comprobante rechazado por el SRI; este está en proceso o autorizado.',409);
        if($this->r->uno('SELECT id FROM erp_fiscal_documentos WHERE sustituye_a_id=? FOR UPDATE',[$id]))throw new ErpException('Este comprobante ya fue sustituido.',409);
        $v=$this->r->uno('SELECT * FROM erp_fiscal_verificaciones WHERE documento_id=? ORDER BY id DESC LIMIT 1 FOR SHARE',[$id]);
        if(!$v)throw new ErpException('Solicita primero la verificación del estado con el SRI.',409);
        if($v['estado']==='autorizado_en_sri')throw new ErpException('El SRI reporta este comprobante como autorizado: no se puede sustituir. Revisa la conciliación con la administración.',409);
        if($v['estado']!=='confirmado')throw new ErpException('La verificación con el SRI sigue en curso o fue incierta; se reintentará. Aún no se puede sustituir.',409);
        $minutos=(int)((new ErpFiscalParametroService($this->r))->resolver('sustitucion.verificacion_minutos','*',substr($this->r::ahora(),0,10),true)['valor_entero']??self::VERIFICACION_MINUTOS);
        if(!$this->r->uno('SELECT id FROM erp_fiscal_verificaciones WHERE id=? AND verificado_en>=DATE_SUB(?,INTERVAL '.$minutos.' MINUTE)',[(int)$v['id'],$this->r::ahora()]))throw new ErpException('La verificación con el SRI caducó (más de '.$minutos.' minutos); solicítala otra vez.',409);
        return $doc;
    }
    /** Nueva factura que sustituye a una rechazada con datos corregidos (emisor, comprador, categorías IVA, pagos): otro número y otra clave. */
    public function sustituirFactura(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.configurar','fiscal_sustitucion_factura',$d,function()use($actor,$d){
            $this->permitir($actor,'erp.cobrar');$id=$this->id($d['documento_id']??null);
            $r0=$this->r->uno("SELECT venta_id FROM erp_fiscal_documentos WHERE id=? AND tipo='01'",[$id])??throw new ErpException('Factura no encontrada.',404);
            (new ErpVentaService($this->r))->bloquear((int)$r0['venta_id']);$doc=$this->sustituible($id,'01');
            $nuevo=$this->crearFactura($actor,['venta_id'=>(int)$doc['venta_id']]+$d,$doc);
            $this->evento($actor,'fiscal_sustitucion_preparada','fiscal_documento',$nuevo,['sustituye_a'=>$id,'tipo'=>'01','numero_rechazado'=>$doc['numero'],'ambiente'=>'1']);return $nuevo;
        });
    }
    /** Nueva nota que sustituye a una rechazada: hereda sus devoluciones (sin volver a vincularlas) y puede sumar otras pendientes. */
    public function sustituirNotaCredito(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.configurar','fiscal_sustitucion_nc',$d,function()use($actor,$d){
            $this->permitir($actor,'erp.devolver');$id=$this->id($d['documento_id']??null);
            $r0=$this->r->uno("SELECT venta_id FROM erp_fiscal_documentos WHERE id=? AND tipo='04'",[$id])??throw new ErpException('Nota de crédito no encontrada.',404);
            (new ErpVentaService($this->r))->bloquear((int)$r0['venta_id']);$doc=$this->sustituible($id,'04');
            $nuevo=$this->crearNotaCredito($actor,$d,$doc);
            $this->evento($actor,'fiscal_sustitucion_preparada','fiscal_documento',$nuevo,['sustituye_a'=>$id,'tipo'=>'04','numero_rechazado'=>$doc['numero'],'ambiente'=>'1']);return $nuevo;
        });
    }

    /** Verifica con el SRI (consulta de la misma clave, fuera de la transacción) los comprobantes rechazados cuya sustitución se solicitó. */
    public function verificar(?\Interfaces\SriTransporteInterface $transporte=null):int
    {
        $this->cli();if($transporte!==null&&getenv('APP_ENV')!=='testing')throw new ErpException('El transporte de ensayo exige entorno de pruebas.');$hechas=0;
        if(!$this->r->q("SHOW TABLES LIKE 'erp_fiscal_verificaciones'"))return 0;
        for($i=0;$i<20;$i++){
            $v=Transaccion::ejecutar($this->r->db,function(){
                $ahora=$this->r::ahora();$v=$this->r->uno("SELECT * FROM erp_fiscal_verificaciones WHERE estado IN('solicitada','incierta') AND (proximo_intento IS NULL OR proximo_intento<=?) AND (lease_hasta IS NULL OR lease_hasta<?) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED",[$ahora,$ahora]);if(!$v)return null;
                $v['lease_token']=bin2hex(random_bytes(16));$this->r->q('UPDATE erp_fiscal_verificaciones SET lease_token=?,lease_hasta=DATE_ADD(?,INTERVAL 2 MINUTE),intentos=intentos+1 WHERE id=?',[$v['lease_token'],$ahora,(int)$v['id']]);return $v;
            });if(!$v)break;
            $doc=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[(int)$v['documento_id']]);$estado='incierta';$resumen=['estado'=>'REINTENTAR','mensaje'=>'No se obtuvo una respuesta verificable del SRI; se volverá a consultar la misma clave.'];
            try{
                if(!hash_equals($doc['xml_sha256'],hash('sha256',$doc['xml_firmado'])))throw new \RuntimeException('Contenido fiscal inconsistente.');
                $sri=$transporte?new SriClienteSoap('1',$transporte):new SriClienteSoap('1');[$estado,$resumen]=$this->clasificar($doc,$sri);
            }catch(\Throwable $e){}
            Transaccion::ejecutar($this->r->db,function()use($v,$estado,$resumen,$doc){
                $actual=$this->r->uno('SELECT lease_token FROM erp_fiscal_verificaciones WHERE id=? FOR UPDATE',[(int)$v['id']]);if(!hash_equals($v['lease_token'],$actual['lease_token']??''))return;
                $ahora=$this->r::ahora();$cerrada=in_array($estado,['confirmado','autorizado_en_sri'],true);
                $this->r->q('UPDATE erp_fiscal_verificaciones SET estado=?,respuesta_json=?,verificado_en=?,proximo_intento=?,lease_token=NULL,lease_hasta=NULL WHERE id=?',[$estado,json_encode($resumen,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$cerrada?$ahora:null,$cerrada?null:date('Y-m-d H:i:s',strtotime($ahora.' +5 minutes')),(int)$v['id']]);
                $this->evento(0,$estado==='autorizado_en_sri'?'fiscal_conflicto_estado':'fiscal_verificacion_resultado','fiscal_documento',(int)$doc['id'],['verificacion_id'=>(int)$v['id'],'resultado'=>$estado,'sri'=>$resumen['estado'],'ambiente'=>'1']);
            });$hechas++;
        }return $hechas;
    }
    /** Clasifica un comprobante rechazado localmente según lo que el SRI responde ahora a su misma clave: confirmado, autorizado_en_sri (conflicto) o incierta. */
    private function clasificar(array $doc,SriClienteSoap $sri):array
    {
        $r=$sri->consultar($doc['clave_acceso'],$doc['xml_firmado']);$original=json_decode($doc['respuesta_json']??'null',true)['estado']??'';
        $resumen=['estado'=>$r['estado'],'mensajes'=>$r['mensajes']??[]];
        if($r['estado']==='AUTORIZADO')return ['autorizado_en_sri',$resumen+['numero_autorizacion'=>$r['numero_autorizacion'],'fecha_autorizacion'=>$r['fecha_autorizacion']]];
        if($r['estado']==='NO AUTORIZADO'||($r['estado']==='EN_PROCESO'&&$original==='DEVUELTA'))return ['confirmado',$resumen];
        $resumen['mensaje']='El SRI no confirma el rechazo registrado; se volverá a consultar.';return ['incierta',$resumen];
    }
    private function certificado(array $p):array
    {
        $raw=base64_decode($p['certificado_cifrado'],true);if($raw===false||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new ErpException('Certificado cifrado no disponible.');
        $texto=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),Secretos::clave('sri-certificados'));
        if($texto===false)throw new ErpException('No se pudo recuperar el certificado con APP_KEY.');$datos=json_decode($texto,true,512,JSON_THROW_ON_ERROR);$p12=base64_decode($datos['p12'],true);
        if($p12===false||!hash_equals($p['certificado_sha256'],hash('sha256',$p12)))throw new ErpException('El certificado no coincide con su huella.');return [$p12,$datos['password']];
    }

    /** Un lease limita la concurrencia. La consulta y recepción HTTPS siempre ocurren fuera de la transacción. */
    /** Vigencia por defecto de la verificación previa a sustituir; se puede ajustar con el parámetro «sustitucion.verificacion_minutos». Política interna, no una cifra del SRI. */
    public const VERIFICACION_MINUTOS=120;
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
                    // Un sustituto no sale hacia el SRI sin reconfirmar, justo antes de transmitir, que el comprobante sustituido sigue rechazado.
                    if($doc['sustituye_a_id']){
                        $orig=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[(int)$doc['sustituye_a_id']]);[$veredicto,$resumenOriginal]=$this->clasificar($orig,$sri);
                        if($veredicto==='autorizado_en_sri'){$this->bloquearSustituto($doc,$orig,$resumenOriginal);$salida['bloqueados']=($salida['bloqueados']??0)+1;continue;}
                        if($veredicto!=='confirmado'){$this->finalizar($doc,'pendiente',['estado'=>'REINTENTAR','mensaje'=>'Aún no se reconfirma el rechazo del comprobante sustituido; el sustituto no se transmite.']);$salida['pendientes']++;continue;}
                    }
                    $respuesta=$sri->recibir($doc['xml_firmado']);$registrada=(bool)array_filter($respuesta['mensajes'],fn($m)=>in_array($m['identificador'],['43','70'],true));$temporal=(bool)array_filter($respuesta['mensajes'],fn($m)=>$m['identificador']==='50');$estado=$respuesta['estado']==='RECIBIDA'||$registrada?'recibida':($temporal?'pendiente':'rechazada');
                }
                $this->finalizar($doc,$estado,$respuesta);$salida[$estado==='autorizada'?'autorizados':($estado==='rechazada'?'rechazados':'pendientes')]++;
            }catch(\Throwable $e){$this->finalizar($doc,$doc['estado'],['estado'=>'REINTENTAR','mensaje'=>'No se obtuvo una respuesta verificable del SRI. Se consultará la misma clave.']);$salida['pendientes']++;}
        }
        $v=$this->verificar($transporte);if($v)$salida['verificaciones']=$v;
        return $salida;
    }
    /** El sustituido apareció AUTORIZADO antes de transmitir el sustituto: no se transmite. El conflicto queda como evidencia inmutable y requiere conciliación manual. */
    private function bloquearSustituto(array $doc,array $original,array $resumenOriginal):void
    {
        Transaccion::ejecutar($this->r->db,function()use($doc,$original,$resumenOriginal){
            $actual=$this->r->uno('SELECT lease_token FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[(int)$doc['id']]);if(!hash_equals($doc['lease_token'],$actual['lease_token']??''))return;
            $ahora=$this->r::ahora();
            $this->r->q("INSERT INTO erp_fiscal_verificaciones(documento_id,estado,solicitado_por,solicitado_en,respuesta_json,verificado_en) VALUES(?,'autorizado_en_sri',0,?,?,?)",[(int)$original['id'],$ahora,json_encode($resumenOriginal,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$ahora]);
            $this->r->q("UPDATE erp_fiscal_documentos SET estado='bloqueada',respuesta_json=?,lease_token=NULL,lease_hasta=NULL,actualizado_en=? WHERE id=?",[json_encode(['estado'=>'BLOQUEADA','motivo'=>'El comprobante sustituido aparece autorizado en el SRI; este sustituto no se transmitió.','sustituido_id'=>(int)$original['id']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$ahora,(int)$doc['id']]);
            $this->evento(0,'fiscal_conflicto_estado','fiscal_documento',(int)$original['id'],['sustituto_id'=>(int)$doc['id'],'resultado'=>'autorizado_en_sri','momento'=>'previo_a_transmitir','ambiente'=>'1']);
            $this->evento(0,'fiscal_sustituto_bloqueado','fiscal_documento',(int)$doc['id'],['sustituido_id'=>(int)$original['id'],'ambiente'=>'1']);
        });
    }
    private function finalizar(array $doc,string $estado,array $respuesta):void
    {
        Transaccion::ejecutar($this->r->db,function()use($doc,$estado,$respuesta){
            $actual=$this->r->uno('SELECT lease_token FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[(int)$doc['id']]);if(!hash_equals($doc['lease_token'],$actual['lease_token']??''))return;
            $ahora=$this->r::ahora();$this->r->q('UPDATE erp_fiscal_documentos SET estado=?,respuesta_json=?,proximo_intento=DATE_ADD(?,INTERVAL 1 MINUTE),lease_token=NULL,lease_hasta=NULL,actualizado_en=? WHERE id=?',[$estado,json_encode($respuesta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$ahora,$ahora,(int)$doc['id']]);$this->evento(0,'fiscal_estado','fiscal_documento',(int)$doc['id'],['estado'=>$estado,'ambiente'=>'1']);
        });
    }
}
