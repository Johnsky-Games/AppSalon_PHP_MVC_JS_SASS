<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpFiscalService,ErpFiscalParametroService,ErpVentaService,ErpMaestrosService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpDevolucionService,ErpException};
use Services\Fiscal\{SriComprobante,SriXml};
use Interfaces\SriTransporteInterface;
use Tests\Fixtures\Sri\Fixtures as F;

/**
 * Reparto acumulado del redondeo en notas de crédito parciales sobre una línea de 30 unidades a 0,17 con IVA 15 % (factura de 5,87):
 * cada nota unitaria se redondea sola a 3 centavos de IVA, pero el IVA de la línea es 77 (no 90).
 */
final class ErpFiscalNotaCreditoRedondeoIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $perfil;private int $venta;private int $linea;
    private mixed $appKeyAnterior;private mixed $appKeyEnvAnterior;
    protected function setUp():void
    {
        $this->appKeyAnterior=getenv('APP_KEY');$this->appKeyEnvAnterior=$_ENV['APP_KEY']??null;putenv('APP_KEY=testing-fiscal-master-key-32-characters');$_ENV['APP_KEY']='testing-fiscal-master-key-32-characters';
        $this->db=BaseDatosAislada::crear('fiscal_nc_red');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->actor=R5CajaFixture::usuario($this->db,'propietario');
        [$p12,$password]=F::certificado();$this->perfil=$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor(),'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        F::parametros($this->db,$this->actor);
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('NC-R','Esmalte','unidad',100000,5)");$producto=(int)$this->db->insert_id;
        $c=(new ErpMaestrosService($this->r))->catalogo($this->actor,$this->d(['origen'=>'producto','origen_id'=>$producto,'precio'=>'0.17','impuesto'=>'15.00','tipo'=>'venta']))['recurso_id'];
        $ventas=new ErpVentaService($this->r);$this->venta=$ventas->crear($this->actor,$this->d())['recurso_id'];$ventas->agregar($this->actor,$this->d(['venta_id'=>$this->venta,'catalogo_id'=>$c,'cantidad'=>'30']));
        $ventas->confirmar($this->actor,$this->d(['venta_id'=>$this->venta,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$this->venta])['version']]));
        (new ErpCajaService($this->r))->abrir($this->actor,$this->d(['fondo'=>'0']));(new ErpPagoService($this->r))->cobrar($this->actor,$this->d(['venta_id'=>$this->venta,'monto'=>'5.87','recibido'=>'5.87','metodo'=>'efectivo']));
        (new ErpEntregaService($this->r))->entregar($this->actor,$this->d(['venta_id'=>$this->venta]));
        $this->linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$this->venta])['id'];
    }
    protected function tearDown():void {try{BaseDatosAislada::destruir($this->db);}finally{putenv($this->appKeyAnterior===false?'APP_KEY':'APP_KEY='.$this->appKeyAnterior);if($this->appKeyEnvAnterior===null)unset($_ENV['APP_KEY']);else $_ENV['APP_KEY']=$this->appKeyEnvAnterior;}}
    private function fiscal():ErpFiscalService {return new ErpFiscalService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function hoy():string {return substr($this->r::ahora(),0,10);}
    private function respuesta(string $clave,string $xml):string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante><claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>'.$clave.'</numeroAutorizacion><fechaAutorizacion>2026-10-19T10:00:00-05:00</fechaAutorizacion><ambiente>PRUEBAS</ambiente><comprobante><![CDATA['.$xml.']]></comprobante><mensajes/></autorizacion></autorizaciones></RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
    }
    private function autorizarFactura(int $id):void
    {
        $d=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$id]);$this->r->q('UPDATE erp_fiscal_documentos SET proximo_intento=NULL,lease_hasta=NULL WHERE id=?',[$id]);
        $t=new class([$this->respuesta($d['clave_acceso'],$d['xml_firmado'])],$this->db) implements SriTransporteInterface {public function __construct(private array $respuestas,private \mysqli $db){}public function post(string $url,string $xml):string {if(\Classes\Transaccion::nivel($this->db)!==0)throw new \LogicException('Red bajo transacción SQL');return array_shift($this->respuestas);}};
        $this->fiscal()->procesar($t);self::assertSame('autorizada',$this->r->uno('SELECT estado FROM erp_fiscal_documentos WHERE id=?',[$id])['estado']);
    }
    private function factura():int
    {
        $id=$this->fiscal()->factura($this->actor,$this->d(['perfil_id'=>$this->perfil,'venta_id'=>$this->venta,'comprador'=>F::factura()['comprador'],'categorias_iva'=>[(string)$this->linea=>'4'],'pagos'=>[['forma'=>'01','total_centavos'=>587]]]))['recurso_id'];
        $this->autorizarFactura($id);return $id;
    }
    private function devolver(string $cantidad):int
    {
        (new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$this->venta,'linea_id'=>$this->linea,'cantidad'=>$cantidad,'motivo'=>'Producto devuelto por la clienta','reintegrar_stock'=>'1']));
        return (int)$this->r->uno('SELECT MAX(id) n FROM erp_devoluciones')['n'];
    }
    private function nc(int $factura,array $devs):array {return $this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$factura,'devolucion_ids'=>$devs,'motivo'=>'Devolución de producto']));}
    private function tolerancia(int $centavos):void
    {
        (new ErpFiscalParametroService($this->r))->registrar($this->actor,$this->d(['clave'=>'nota_credito.tolerancia_centavos','valor'=>(string)$centavos,'regimen'=>'*','vigente_desde'=>$this->hoy(),'consultado_en'=>$this->hoy(),'fuente'=>'Ficha técnica comprobantes electrónicos esquema offline, versión 2.34 (ensayo de la prueba, no oficial)','fuente_url'=>'https://www.sri.gob.ec/ficha-tecnica-ensayo']));
    }
    private function sumas():array
    {
        $s=$this->r->uno("SELECT COALESCE(SUM(l.base_centavos),0) b,COALESCE(SUM(l.iva_centavos),0) i,COALESCE(SUM(l.total_centavos),0) t,COALESCE(SUM(l.cantidad_milesimas),0) q,COUNT(*) n FROM erp_fiscal_nc_lineas l JOIN erp_fiscal_documentos x ON x.id=l.documento_id WHERE x.estado NOT IN('rechazada','bloqueada')");
        return ['base'=>(int)$s['b'],'iva'=>(int)$s['i'],'total'=>(int)$s['t'],'cantidad'=>(int)$s['q'],'notas'=>(int)$s['n']];
    }

    public function testSinToleranciaLaNotaQueNoCuadraConSuDetalleSeRechazaYConLaVerificadaLaLineaCierraExacta():void
    {
        $f=$this->factura();$pendientes=[];$emitidas=0;
        for($i=1;$i<=30;$i++){
            $dev=$this->devolver('1');
            try{$id=$this->nc($f,[$dev])['recurso_id'];$emitidas++;
                $snap=json_decode($this->r->uno('SELECT snapshot_json FROM erp_fiscal_documentos WHERE id=?',[$id])['snapshot_json'],true)['nota_credito'];
                self::assertSame(0,$snap['tolerancia_centavos']);foreach($snap['lineas'] as $l)self::assertSame([0,0],[$l['desviacion_iva'],$l['desviacion_detalle']],'En modo estricto cada nota cuadra exactamente con su propio detalle');
            }catch(ErpException $e){
                self::assertSame(409,$e->getCode());self::assertStringContainsString('nota_credito.tolerancia_centavos',$e->getMessage());self::assertStringContainsString('desviación',$e->getMessage());$pendientes[]=$dev;
            }
        }
        self::assertNotEmpty($pendientes,'Con IVA 15 % sobre 0,17 el redondeo acumulado desvía algunas notas unitarias');self::assertGreaterThan(0,$emitidas);
        $parcial=$this->sumas();self::assertLessThanOrEqual(587,$parcial['total']);self::assertLessThanOrEqual(77,$parcial['iva']);self::assertLessThanOrEqual(510,$parcial['base']);
        // Las devoluciones rechazadas siguen pendientes (no quedaron vinculadas a ninguna nota).
        self::assertSame($emitidas,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_nc_devoluciones')['n']);
        // Con la tolerancia verificada y registrada (con fuente y vigencia) se emiten las pendientes y la línea cierra EXACTA.
        $this->tolerancia(1);
        foreach($pendientes as $dev){
            $id=$this->nc($f,[$dev])['recurso_id'];$snap=json_decode($this->r->uno('SELECT snapshot_json FROM erp_fiscal_documentos WHERE id=?',[$id])['snapshot_json'],true);
            self::assertSame(1,$snap['nota_credito']['tolerancia_centavos']);self::assertSame('nota_credito.tolerancia_centavos',$snap['parametros'][0]['clave'],'La instantánea conserva el parámetro usado');
            foreach($snap['nota_credito']['lineas'] as $l)self::assertLessThanOrEqual(1,max(abs($l['desviacion_iva']),abs($l['desviacion_detalle'])));
            SriXml::validar($this->r->uno('SELECT xml_firmado FROM erp_fiscal_documentos WHERE id=?',[$id])['xml_firmado']);
        }
        $fin=$this->sumas();
        self::assertSame(['base'=>510,'iva'=>77,'total'=>587,'cantidad'=>30000],['base'=>$fin['base'],'iva'=>$fin['iva'],'total'=>$fin['total'],'cantidad'=>$fin['cantidad']],'Antes de la corrección: base 506, IVA 90 frente a 77, o rechazo de la última nota');
        self::assertSame(30,$fin['notas']);
        // Nada más que acreditar: ni devolver ni acreditar de nuevo.
        try{$this->devolver('1');self::fail('No queda cantidad');}catch(ErpException){}
        // La auditoría no encuentra nada que corregir.
        $a=$this->fiscal()->auditarRedondeoNotas();self::assertSame(['conforme'=>1,'excedida'=>0,'con_remanente'=>0,'agotada'=>0,'desviada'=>0],$a['resumen']);
    }

    public function testTresProcesosConcurrentesSobreLaMismaLineaAcreditanExactamenteLoFacturado():void
    {
        $f=$this->factura();$this->tolerancia(1);$devs=[$this->devolver('10'),$this->devolver('7'),$this->devolver('13')];$workers=[];$resultados=[];
        try{
            foreach($devs as $dev){$pipes=[];$datos=$this->d(['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'Devolución de producto']);
                $p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php','nota_credito',(string)$this->actor,json_encode($datos)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));
                self::assertIsResource($p);stream_set_timeout($pipes[1],60);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        }finally{foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        self::assertSame(3,count(array_filter($resultados,fn($r)=>$r['ok'])),json_encode($resultados));
        $s=$this->sumas();self::assertSame(['base'=>510,'iva'=>77,'total'=>587,'cantidad'=>30000,'notas'=>3],$s,'Sin importar el orden en que se serializan los procesos, la suma es exactamente lo facturado');
        self::assertSame(3,(int)$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE documento='04'")['ultimo'],'Tres números consecutivos, sin saltos ni duplicados');
        self::assertSame(3,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_nc_devoluciones')['n']);
    }

    /** Inserta una nota con el formato del reparto anterior (cada nota redondeada sola): operación histórica. */
    private function notaHistorica(int $factura,int $n,int $milesimas,int $base,int $iva):void
    {
        $f=$this->r->uno('SELECT perfil_id,venta_id,snapshot_json FROM erp_fiscal_documentos WHERE id=?',[$factura]);$ahora=$this->r::ahora();$snap=json_encode(['emisor'=>json_decode($f['snapshot_json'],true)['emisor'],'nota_credito'=>['historica'=>true]]);
        $this->r->q("INSERT INTO erp_fiscal_documentos(perfil_id,tipo,documento_origen_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) VALUES(?,'04',?,?,?,?,?,?,?,'<x/>',?,?,?,?)",
            [(int)$f['perfil_id'],$factura,(int)$f['venta_id'],hash('sha256','hist-op-'.$n),hash('sha256','hist-hu-'.$n),str_pad((string)$n,49,'9',STR_PAD_LEFT),'001-001-9'.str_pad((string)$n,8,'0',STR_PAD_LEFT),$snap,hash('sha256','hist-x-'.$n),$this->actor,$ahora,$ahora]);$doc=$this->r->id();
        $this->r->q('INSERT INTO erp_fiscal_nc_lineas(documento_id,linea_id,cantidad_milesimas,base_centavos,iva_centavos,total_centavos) VALUES(?,?,?,?,?,?)',[$doc,$this->linea,$milesimas,$base,$iva,$base+$iva]);
    }
    public function testLasNotasHistoricasDelRepartoAnteriorSeDetectanYLaLineaNoSeCierraEnSilencio():void
    {
        $f=$this->factura();
        // Reparto anterior: 29 notas unitarias de 17 de base y 3 de IVA = 20 (el IVA acumulado, 87, ya supera los 77 facturados).
        for($i=1;$i<=29;$i++)$this->notaHistorica($f,$i,1000,17,3);
        $a=$this->fiscal()->auditarRedondeoNotas();
        self::assertSame(['conforme'=>0,'excedida'=>1,'con_remanente'=>0,'agotada'=>0,'desviada'=>0],$a['resumen']);
        $l=$a['lineas'][0];self::assertSame([29000,493,87],[$l['cantidad'],$l['base'],$l['iva']]);self::assertCount(29,$l['notas']);
        // Acreditar la unidad restante exigiría un IVA negativo para cuadrar: se rechaza y no se emite nada.
        $dev=$this->devolver('1');$antes=(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE tipo='04'")['n'];
        try{$this->nc($f,[$dev]);self::fail('La línea con notas del reparto anterior no debe cerrarse en silencio');}
        catch(ErpException $e){self::assertSame(409,$e->getCode());self::assertStringContainsString('repartir el redondeo',$e->getMessage());}
        self::assertSame($antes,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE tipo='04'")['n']);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_nc_devoluciones')['n'],'La devolución queda pendiente, sin vincular');
        // Los documentos históricos no se modifican: son de solo anexar.
        $err=''; $ok=$this->db->query("UPDATE erp_fiscal_nc_lineas SET iva_centavos=iva_centavos+1");self::assertFalse($ok);
    }

    public function testElGeneradorNoEmiteUnaNotaQueNoCuadraConSuDetalleFueraDeLaTolerancia():void
    {
        $linea=['codigo'=>'P1','descripcion'=>'Esmalte','cantidad_milesimas'=>2000,'precio_centavos'=>17,'descuento_centavos'=>0,'impuesto_pb'=>1500,'codigo_porcentaje'=>'4','base_centavos'=>34,'iva_centavos'=>6];// 34 × 15 % = 5,10 → 5; la nota lleva 6
        $base=F::factura();$datos=['fecha'=>$this->hoy(),'secuencial'=>'000000001','codigo_numerico'=>'12345678','comprador'=>$base['comprador'],'lineas'=>[$linea],'motivo'=>'Devolución','sustento'=>['numero'=>'001-001-000000001','fecha'=>$this->hoy()]];
        try{SriComprobante::notaCredito(F::emisor(),$datos);self::fail('Debía rechazar la desviación sin tolerancia');}catch(\InvalidArgumentException $e){self::assertStringContainsString('tolerancia',$e->getMessage());}
        $xml=SriComprobante::notaCredito(F::emisor(),$datos+['tolerancia_centavos'=>1]);SriXml::validar($xml);
        $x=new \DOMXPath(SriXml::cargar($xml));self::assertSame('0.40',$x->evaluate('string(//infoNotaCredito/valorModificacion)'));self::assertSame('0.34',$x->evaluate('string(//infoNotaCredito/totalSinImpuestos)'));self::assertSame('0.06',$x->evaluate('string(//infoNotaCredito//totalImpuesto/valor)'));
        try{SriComprobante::notaCredito(F::emisor(),['lineas'=>[['iva_centavos'=>8]+$linea]]+$datos+['tolerancia_centavos'=>1]);self::fail('Una desviación de 3 centavos excede cualquier tolerancia');}catch(\InvalidArgumentException){}
    }

    public function testLaToleranciaTambienSeAplicaALaSumaDeLasLineasDeUnaMismaTarifa():void
    {
        $linea=['codigo'=>'P1','descripcion'=>'Esmalte','cantidad_milesimas'=>2000,'precio_centavos'=>17,'descuento_centavos'=>0,'impuesto_pb'=>1500,'codigo_porcentaje'=>'4','base_centavos'=>34,'iva_centavos'=>6];// cada línea desvía +1
        $base=F::factura();$datos=['fecha'=>$this->hoy(),'secuencial'=>'000000001','codigo_numerico'=>'12345678','comprador'=>$base['comprador'],'lineas'=>[$linea,['codigo'=>'P2']+$linea],'motivo'=>'Devolución','sustento'=>['numero'=>'001-001-000000001','fecha'=>$this->hoy()]];
        try{SriComprobante::notaCredito(F::emisor(),$datos+['tolerancia_centavos'=>1]);self::fail('Dos líneas con +1 suman +2 en la misma tarifa y exceden la tolerancia de 1');}
        catch(\InvalidArgumentException $e){self::assertStringContainsString('suma de los redondeos',$e->getMessage());}
        SriXml::validar(SriComprobante::notaCredito(F::emisor(),$datos+['tolerancia_centavos'=>2]));
    }

    private function registrarTolerancia(array $extra):void
    {
        (new ErpFiscalParametroService($this->r))->registrar($this->actor,$this->d($extra+['clave'=>'nota_credito.tolerancia_centavos','valor'=>'1','regimen'=>'*','vigente_desde'=>$this->hoy(),'consultado_en'=>$this->hoy()]));
    }
    private function rechazo(callable $f):ErpException {try{$f();}catch(ErpException $e){return $e;}self::fail('Se esperaba un rechazo');}

    public function testUnaToleranciaMayorQueCeroSoloSeRegistraConEvidenciaYElMaximoEsUnCentavo():void
    {
        $e=$this->rechazo(fn()=>$this->registrarTolerancia(['fuente'=>'Valor que me parece razonable']));self::assertStringContainsString('requiere evidencia',$e->getMessage());
        // Un enlace que no es de sri.gob.ec, o una fuente sin ficha técnica con versión, tampoco bastan.
        $this->rechazo(fn()=>$this->registrarTolerancia(['fuente'=>'Ficha técnica esquema offline versión 2.34','fuente_url'=>'https://ejemplo.com/ficha']));
        $this->rechazo(fn()=>$this->registrarTolerancia(['fuente'=>'Ficha técnica esquema offline','fuente_url'=>'https://www.sri.gob.ec/ficha']));
        $this->rechazo(fn()=>$this->registrarTolerancia(['fuente'=>'Guía de un tercero versión 2.34','fuente_url'=>'https://www.sri.gob.ec/ficha']));
        $this->rechazo(fn()=>$this->registrarTolerancia(['fuente'=>'Ficha técnica esquema offline versión 2.34','fuente_url'=>'http://sri.gob.ec.malicioso.net/ficha']));
        // La clave citada debe ser de una nota de esta base.
        $e=$this->rechazo(fn()=>$this->registrarTolerancia(['fuente'=>'Prueba en ambiente de pruebas','nota'=>'Clave '.str_repeat('1',49)]));self::assertStringContainsString('no corresponde a una nota de crédito de esta base',$e->getMessage());
        // 2 centavos ya no es un valor admisible: el reparto acumulado no produce más de 1 por línea.
        $this->rechazo(fn()=>$this->registrarTolerancia(['valor'=>'2','fuente'=>'Ficha técnica esquema offline versión 2.34','fuente_url'=>'https://www.sri.gob.ec/ficha']));
        // Cero (modo estricto) no necesita evidencia.
        $this->registrarTolerancia(['valor'=>'0','fuente'=>'Modo estricto: la nota debe cuadrar exactamente']);
        self::assertSame(0,(int)$this->r->uno("SELECT valor_entero v FROM erp_fiscal_parametros WHERE clave='nota_credito.tolerancia_centavos'")['v']);
    }

    public function testLaEvidenciaDeUnaNotaAutorizadaConDesviacionSeVerificaContraSuXmlFirmado():void
    {
        $f=$this->factura();$this->tolerancia(1);$conDesviacion=null;$exacta=null;$pendiente=null;
        for($i=1;$i<=12;$i++){
            $dev=$this->devolver('1');$id=$this->nc($f,[$dev])['recurso_id'];
            $snap=json_decode($this->r->uno('SELECT snapshot_json FROM erp_fiscal_documentos WHERE id=?',[$id])['snapshot_json'],true)['nota_credito'];
            $max=0;foreach($snap['lineas'] as $l)$max=max($max,abs($l['desviacion_iva']),abs($l['desviacion_detalle']));
            // Se autoriza cada nota elegida apenas se emite (la cola procesa en orden); la pendiente se deja para el final.
            if($max>0&&$conDesviacion===null){$this->autorizarFactura($id);$conDesviacion=$id;}
            elseif($max===0&&$exacta===null){$this->autorizarFactura($id);$exacta=$id;}
            elseif($max>0&&$conDesviacion!==null&&$exacta!==null){$pendiente=$id;break;}
        }
        self::assertNotNull($conDesviacion);self::assertNotNull($pendiente);self::assertNotNull($exacta);
        $clave=fn(int $id)=>$this->r->uno('SELECT clave_acceso FROM erp_fiscal_documentos WHERE id=?',[$id])['clave_acceso'];
        $p=new ErpFiscalParametroService($this->r);
        // Una nota pendiente (aún no autorizada) no demuestra nada.
        $e=$this->rechazo(fn()=>$p->verificarEvidenciaTolerancia(1,'Prueba',null,'Clave '.$clave($pendiente)));self::assertStringContainsString('no está autorizada',$e->getMessage());
        // Autorizadas por el SRI (simulado): la exacta no demuestra que se acepte 1 centavo; la que desvía sí.
        $e=$this->rechazo(fn()=>$p->verificarEvidenciaTolerancia(1,'Prueba',null,'Clave '.$clave($exacta)));self::assertStringContainsString('desviación de 0 centavo',$e->getMessage());
        $p->verificarEvidenciaTolerancia(1,'Prueba controlada en el ambiente de pruebas del SRI',null,'Nota autorizada '.$clave($conDesviacion));
        $ev=$p->evidenciaTolerancia();self::assertSame('acepta',$ev['veredicto']);self::assertSame([$clave($conDesviacion)],array_column($ev['autorizadas_con_desviacion'],'clave_acceso'));self::assertSame(1,$ev['autorizadas_exactas']);
        $m=$p->evidenciaNota($clave($conDesviacion));self::assertSame('autorizada',$m['estado']);self::assertSame('1',$m['ambiente']);self::assertSame(0,$m['no_evaluables']);self::assertSame(1,max($m['desviacion_iva'],$m['desviacion_detalle']));
    }

    private function rechazarNota(int $id,string $identificador,string $texto):void
    {
        $d=$this->r->uno('SELECT clave_acceso FROM erp_fiscal_documentos WHERE id=?',[$id]);$this->r->q('UPDATE erp_fiscal_documentos SET proximo_intento=NULL,lease_hasta=NULL WHERE id=?',[$id]);
        $resp='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante><claveAccesoConsultada>'.$d['clave_acceso'].'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>NO AUTORIZADO</estado><mensajes><mensaje><identificador>'.$identificador.'</identificador><mensaje>'.$texto.'</mensaje><informacionAdicional>detalle de ensayo</informacionAdicional><tipo>ERROR</tipo></mensaje></mensajes></autorizacion></autorizaciones></RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
        $t=new class([$resp],$this->db) implements SriTransporteInterface {public function __construct(private array $respuestas,private \mysqli $db){}public function post(string $url,string $xml):string {return array_shift($this->respuestas);}};
        $this->fiscal()->procesar($t);self::assertSame('rechazada',$this->r->uno('SELECT estado FROM erp_fiscal_documentos WHERE id=?',[$id])['estado']);
    }
    private function notaConDesviacion(int $factura):int
    {
        for($i=1;$i<=12;$i++){
            $id=$this->nc($factura,[$this->devolver('1')])['recurso_id'];
            $snap=json_decode($this->r->uno('SELECT snapshot_json FROM erp_fiscal_documentos WHERE id=?',[$id])['snapshot_json'],true)['nota_credito'];
            foreach($snap['lineas'] as $l)if($l['desviacion_iva']!==0||$l['desviacion_detalle']!==0)return $id;
            $this->autorizarFactura($id);// las exactas se dejan autorizadas para que no estorben la cola
        }
        self::fail('No apareció una nota con desviación');
    }

    public function testElVeredictoEsRechazaSoloSiElSriDevolvioErrorDeDiferenciasYSinEvidenciaEnCualquierOtroCaso():void
    {
        $f=$this->factura();$this->tolerancia(1);$p=new ErpFiscalParametroService($this->r);
        $id=$this->notaConDesviacion($f);
        // Rechazada por otra causa (firma): no dice nada de la tolerancia.
        $this->rechazarNota($id,'39','Firma inválida de ensayo');
        $ev=$p->evidenciaTolerancia();self::assertSame('sin_evidencia',$ev['veredicto']);self::assertCount(1,$ev['rechazadas_con_desviacion']);self::assertFalse($ev['rechazadas_con_desviacion'][0]['error_diferencias']);
        // Rechazada con ERROR EN DIFERENCIAS (52) y una desviación de 1 centavo: evidencia de que NO se acepta.
        $id2=$this->notaConDesviacion($f);$this->rechazarNota($id2,'52','ERROR EN DIFERENCIAS');
        $ev=$p->evidenciaTolerancia();self::assertSame('rechaza',$ev['veredicto']);self::assertSame(2,count($ev['rechazadas_con_desviacion']));self::assertTrue($ev['rechazadas_con_desviacion'][1]['error_diferencias']);
        self::assertSame([],$ev['autorizadas_con_desviacion']);
    }

    public function testLaPruebaControladaEmiteUnaNotaDeUnCentavoSinRegistrarLaToleranciaYSuAutorizacionLaDemuestra():void
    {
        $f=$this->factura();
        // La primera devolución unitaria cuadra exacta: como prueba no demostraría nada y se rechaza.
        $primera=$this->devolver('1');
        $e=$this->rechazo(fn()=>$this->fiscal()->notaCreditoPruebaTolerancia($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$primera],'motivo'=>'Prueba controlada'])));
        self::assertSame(409,$e->getCode());self::assertStringContainsString('no produce ninguna desviación',$e->getMessage());
        $sinPermiso=R5CajaFixture::usuario($this->db,'recepcion');
        $e=$this->rechazo(fn()=>$this->fiscal()->notaCreditoPruebaTolerancia($sinPermiso,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$primera],'motivo'=>'Prueba controlada'])));self::assertSame(403,$e->getCode());
        // Se emiten notas en modo estricto hasta que alguna devolución quede rechazada por desviar.
        $this->autorizarFactura($this->nc($f,[$primera])['recurso_id']);$pendientes=[];
        for($i=1;$i<=12&&count($pendientes)<2;$i++){$dev=$this->devolver('1');try{$this->autorizarFactura($this->nc($f,[$dev])['recurso_id']);}catch(ErpException){$pendientes[]=$dev;}}
        self::assertNotEmpty($pendientes,'El modo estricto rechaza devoluciones que desvían');
        // La prueba con una de esas devoluciones emite UNA nota marcada, con tolerancia efectiva solo para ella.
        $id=null;
        foreach($pendientes as $dev){try{$id=$this->fiscal()->notaCreditoPruebaTolerancia($this->actor,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'Prueba controlada de tolerancia']))['recurso_id'];$usada=$dev;break;}catch(ErpException $e){self::assertSame(409,$e->getCode());}}
        self::assertNotNull($id);
        $snap=json_decode($this->r->uno('SELECT snapshot_json FROM erp_fiscal_documentos WHERE id=?',[$id])['snapshot_json'],true);
        self::assertTrue($snap['prueba_tolerancia']);self::assertSame(1,$snap['nota_credito']['tolerancia_centavos']);self::assertSame([],$snap['parametros']);
        $max=0;foreach($snap['nota_credito']['lineas'] as $l)$max=max($max,abs($l['desviacion_iva']),abs($l['desviacion_detalle']));self::assertSame(1,$max);
        self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_parametros WHERE clave='nota_credito.tolerancia_centavos'")['n'],'La prueba no registra ni cambia la tolerancia');
        // Sin autorización del SRI no hay evidencia; autorizada por el SRI (simulado) demuestra la aceptación y habilita el registro.
        $clave=$this->r->uno('SELECT clave_acceso FROM erp_fiscal_documentos WHERE id=?',[$id])['clave_acceso'];$par=new ErpFiscalParametroService($this->r);
        $e=$this->rechazo(fn()=>$this->registrarTolerancia(['fuente'=>'Prueba controlada en el ambiente de pruebas del SRI','nota'=>'Nota '.$clave]));self::assertStringContainsString('no está autorizada',$e->getMessage());
        $this->autorizarFactura($id);self::assertSame('acepta',$par->evidenciaTolerancia()['veredicto']);
        $this->registrarTolerancia(['fuente'=>'Prueba controlada en el ambiente de pruebas del SRI','nota'=>'Nota de prueba autorizada '.$clave]);
        self::assertSame(1,(int)$this->r->uno("SELECT valor_entero v FROM erp_fiscal_parametros WHERE clave='nota_credito.tolerancia_centavos'")['v']);
        // Con la tolerancia respaldada por evidencia, las devoluciones restantes que el modo estricto rechazaba ya se emiten.
        foreach(array_diff($pendientes,[$usada]) as $dev)self::assertGreaterThan(0,$this->nc($f,[$dev])['recurso_id']);
    }
}
