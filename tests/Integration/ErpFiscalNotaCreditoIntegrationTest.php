<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpFiscalService,ErpFiscalCorreoService,ErpVentaService,ErpMaestrosService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpDevolucionService,ErpException};
use Services\Fiscal\{SriNotaCreditoCalculo,SriRide,SriXml};
use Interfaces\SriTransporteInterface;
use Tests\Fixtures\Sri\Fixtures as F;

/** Nota de crédito fiscal de extremo a extremo (pruebas del integrador): factura autorizada → devoluciones → NC → evidencia y límites. */
final class ErpFiscalNotaCreditoIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $perfil;private int $venta;private int $linea;private int $producto;
    private mixed $appKeyAnterior;private mixed $appKeyEnvAnterior;
    protected function setUp():void
    {
        $this->appKeyAnterior=getenv('APP_KEY');$this->appKeyEnvAnterior=$_ENV['APP_KEY']??null;putenv('APP_KEY=testing-fiscal-master-key-32-characters');$_ENV['APP_KEY']='testing-fiscal-master-key-32-characters';
        $this->db=BaseDatosAislada::crear('fiscal_nc');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->actor=R5CajaFixture::usuario($this->db,'propietario');
        [$p12,$password]=F::certificado();$this->perfil=$this->fiscal()->perfil($this->actor,$this->d(['emisor'=>F::emisor(),'titular_confirmado'=>'1']),$p12,$password)['recurso_id'];
        F::parametros($this->db,$this->actor);
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('NC-1','Champú','unidad',10000,500)");$this->producto=(int)$this->db->insert_id;
        $c=(new ErpMaestrosService($this->r))->catalogo($this->actor,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'10.00','impuesto'=>'15.00','tipo'=>'venta']))['recurso_id'];
        $ventas=new ErpVentaService($this->r);$this->venta=$ventas->crear($this->actor,$this->d())['recurso_id'];$ventas->agregar($this->actor,$this->d(['venta_id'=>$this->venta,'catalogo_id'=>$c,'cantidad'=>'3']));
        $ventas->confirmar($this->actor,$this->d(['venta_id'=>$this->venta,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$this->venta])['version']]));
        (new ErpCajaService($this->r))->abrir($this->actor,$this->d(['fondo'=>'0']));(new ErpPagoService($this->r))->cobrar($this->actor,$this->d(['venta_id'=>$this->venta,'monto'=>'34.50','recibido'=>'34.50','metodo'=>'efectivo']));
        (new ErpEntregaService($this->r))->entregar($this->actor,$this->d(['venta_id'=>$this->venta]));
        $this->linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$this->venta])['id'];
    }
    protected function tearDown():void {try{BaseDatosAislada::destruir($this->db);}finally{putenv($this->appKeyAnterior===false?'APP_KEY':'APP_KEY='.$this->appKeyAnterior);if($this->appKeyEnvAnterior===null)unset($_ENV['APP_KEY']);else $_ENV['APP_KEY']=$this->appKeyEnvAnterior;}}
    private function fiscal():ErpFiscalService {return new ErpFiscalService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function respuesta(string $clave,string $xml):string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante><claveAccesoConsultada>'.$clave.'</claveAccesoConsultada><numeroComprobantes>1</numeroComprobantes><autorizaciones><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>'.$clave.'</numeroAutorizacion><fechaAutorizacion>2026-10-19T10:00:00-05:00</fechaAutorizacion><ambiente>PRUEBAS</ambiente><comprobante><![CDATA['.$xml.']]></comprobante><mensajes/></autorizacion></autorizaciones></RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
    }
    private function transporte(array $respuestas):SriTransporteInterface
    {return new class($respuestas,$this->db) implements SriTransporteInterface {public array $peticiones=[];public function __construct(private array $respuestas,private \mysqli $db){}public function post(string $url,string $xml):string {if(\Classes\Transaccion::nivel($this->db)!==0)throw new \LogicException('Red bajo transacción SQL');$this->peticiones[]=[$url,$xml];$r=array_shift($this->respuestas);if($r instanceof \Throwable)throw $r;if(!is_string($r))throw new \LogicException('Petición inesperada');return $r;}};}
    private function autorizar(int $id):void
    {
        $d=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$id]);$this->r->q('UPDATE erp_fiscal_documentos SET proximo_intento=NULL,lease_hasta=NULL WHERE id=?',[$id]);
        $this->fiscal()->procesar($this->transporte([$this->respuesta($d['clave_acceso'],$d['xml_firmado'])]));self::assertSame('autorizada',$this->r->uno('SELECT estado FROM erp_fiscal_documentos WHERE id=?',[$id])['estado']);
    }
    private function factura(bool $autorizar=true):int
    {
        $id=$this->fiscal()->factura($this->actor,$this->d(['perfil_id'=>$this->perfil,'venta_id'=>$this->venta,'comprador'=>F::factura()['comprador'],'categorias_iva'=>[(string)$this->linea=>'4'],'pagos'=>[['forma'=>'01','total_centavos'=>3450]]]))['recurso_id'];
        if($autorizar)$this->autorizar($id);return $id;
    }
    private function devolver(string $cantidad,bool $stock=true):int
    {
        (new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$this->venta,'linea_id'=>$this->linea,'cantidad'=>$cantidad,'motivo'=>'Producto devuelto por la clienta','reintegrar_stock'=>$stock?'1':'0']));
        return (int)$this->r->uno('SELECT MAX(id) n FROM erp_devoluciones')['n'];
    }
    private function nc(int $factura,array $devs,array $extra=[]):array {return $this->fiscal()->notaCredito($this->actor,$this->d(['factura_id'=>$factura,'devolucion_ids'=>$devs,'motivo'=>'Devolución de producto']+$extra));}
    /** Foto de los hechos NO fiscales: la NC no debe tocarlos. */
    private function hechos():array
    {
        $q=fn(string $s)=>json_encode($this->r->q($s));
        return [$q('SELECT id,estado,monto,reembolsado_at FROM pagos ORDER BY id'),$q('SELECT * FROM cobros_devoluciones ORDER BY id'),$q('SELECT * FROM caja_movimientos ORDER BY id'),$q('SELECT id,stock_milesimas,costo_centavos FROM inventario_productos ORDER BY id'),$q('SELECT * FROM inventario_movimientos ORDER BY id'),$q('SELECT * FROM erp_devoluciones ORDER BY id'),$q('SELECT * FROM erp_venta_lineas ORDER BY id'),$q('SELECT COUNT(*) n FROM comisiones_movimientos')];
    }

    public function testNotaDeCreditoParcialSeFirmaYAutorizaSinTocarDineroInventarioNiComisiones():void
    {
        $f=$this->factura();$dev=$this->devolver('1');$antes=$this->hechos();
        $id=$this->nc($f,[$dev])['recurso_id'];
        $nc=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$id]);$fa=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$f]);
        self::assertSame('04',$nc['tipo']);self::assertSame('pendiente',$nc['estado']);self::assertSame($f,(int)$nc['documento_origen_id']);
        self::assertSame($fa['numero'],$nc['numero'],'Factura y nota comparten serie y secuencial 1: la identidad incluye el tipo');
        self::assertNotSame($fa['clave_acceso'],$nc['clave_acceso']);self::assertSame('04',substr($nc['clave_acceso'],8,2));
        self::assertTrue(F::verificar($nc['xml_firmado']));self::assertSame(hash('sha256',$nc['xml_firmado']),$nc['xml_sha256']);
        $xp=new \DOMXPath(SriXml::validar($nc['xml_firmado']));
        self::assertSame($fa['numero'],$xp->evaluate('string(/notaCredito/infoNotaCredito/numDocModificado)'));self::assertSame('01',$xp->evaluate('string(/notaCredito/infoNotaCredito/codDocModificado)'));
        self::assertSame('11.50',$xp->evaluate('string(/notaCredito/infoNotaCredito/valorModificacion)'));self::assertSame('10.00',$xp->evaluate('string(/notaCredito/infoNotaCredito/totalSinImpuestos)'));
        self::assertSame('1.000',$xp->evaluate('string(/notaCredito/detalles/detalle/cantidad)'));self::assertSame('1.50',$xp->evaluate('string(/notaCredito/detalles/detalle/impuestos/impuesto/valor)'));
        self::assertSame($antes,$this->hechos(),'La nota de crédito fiscal no mueve dinero, inventario, devoluciones ni comisiones');
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_nc_devoluciones WHERE documento_id=?',[$id])['n']);
        self::assertSame(1150,(int)$this->r->uno('SELECT total_centavos t FROM erp_fiscal_nc_lineas WHERE documento_id=?',[$id])['t']);
        $ev=$this->r->uno("SELECT * FROM erp_eventos WHERE tipo='fiscal_nota_credito_preparada'");self::assertSame($this->actor,(int)$ev['actor_id']);
        // Secuencias separadas por tipo y el worker la procesa como cualquier comprobante.
        self::assertSame(1,(int)$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE documento='04'")['ultimo']);self::assertSame(1,(int)$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE documento='01'")['ultimo']);
        $this->autorizar($id);
        $ride=$this->fiscal()->ride($this->actor,$id);self::assertSame('04',$ride['tipo']);self::assertSame('11.50',$ride['factura']['importeTotal']);self::assertSame($fa['numero'],$ride['sustento']['numero']);self::assertSame('Devolución de producto',$ride['sustento']['motivo']);
        $pdf=$this->fiscal()->pdf($this->actor,$id);self::assertStringStartsWith('%PDF-',$pdf['contenido']);
    }
    public function testReintentoConLaMismaClaveNoDuplicaYOtraClaveConLosMismosDatosTampoco():void
    {
        $f=$this->factura();$dev=$this->devolver('1');$d=$this->d(['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'Devolución de producto']);
        $id=$this->fiscal()->notaCredito($this->actor,$d)['recurso_id'];$this->r->q('UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
        self::assertSame($id,$this->fiscal()->notaCredito($this->actor,$d)['recurso_id'],'La clave de operación permanente reconoce el reintento');
        try{$this->nc($f,[$dev]);self::fail('La devolución ya fue acreditada.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE tipo='04'")['n']);self::assertSame(1,(int)$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE documento='04'")['ultimo']);
    }
    public function testLimitesAcumuladosPorLineaYPorFactura():void
    {
        $f=$this->factura();$d1=$this->devolver('1');$d2=$this->devolver('1');$d3=$this->devolver('1');
        $n1=$this->nc($f,[$d1])['recurso_id'];$n2=$this->nc($f,[$d2,$d3])['recurso_id'];
        self::assertSame([1150,2300],array_map('intval',array_column($this->r->q('SELECT total_centavos FROM erp_fiscal_nc_lineas ORDER BY documento_id'),'total_centavos')));
        self::assertSame(3450,(int)$this->r->uno("SELECT SUM(l.total_centavos) t FROM erp_fiscal_nc_lineas l")['t'],'Entre todas las notas se acredita exactamente lo facturado');
        // No queda nada por devolver comercialmente ni por acreditar fiscalmente.
        try{(new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$this->venta,'linea_id'=>$this->linea,'cantidad'=>'1','motivo'=>'Sobrante','reintegrar_stock'=>'0']));self::fail('Supera lo facturado.');}catch(ErpException){}
        try{$this->nc($f,[$d1]);self::fail('Ya acreditada.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertNotSame($n1,$n2);self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE tipo='04'")['n']);
        $this->autorizar($n1);$this->autorizar($n2);
    }
    public function testNoSePuedeAcreditarUnaFacturaSinAutorizacionNiDevolucionesAjenasNiSinPermiso():void
    {
        $f=$this->factura(false);$dev=$this->devolver('1');
        try{$this->nc($f,[$dev]);self::fail('Sin autorización del SRI.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $this->autorizar($f);
        try{$this->nc($f,[$dev+999]);self::fail('Devolución inexistente.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        try{$this->nc($f,[]);self::fail('Sin devoluciones.');}catch(ErpException){}
        $cliente=R5CajaFixture::usuario($this->db);$recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        foreach([$cliente,$recepcion] as $sin){try{$this->fiscal()->notaCredito($sin,$this->d(['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'x']));self::fail('Permiso.');}catch(ErpException $e){self::assertSame(403,$e->getCode());}}
        self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE tipo='04'")['n']);self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_secuencias WHERE documento='04'")['n']);
    }
    public function testElEsquemaConservaUnaFacturaPorVentaPeroPermiteNotasYNumeroPorTipo():void
    {
        $f=$this->factura();$dev=$this->devolver('1');$n=$this->nc($f,[$dev])['recurso_id'];
        $nota=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$n]);$fa=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$f]);
        $insertar=fn(string $tipo,?int $origen,string $numero,string $clave,string $op)=>$this->r->q("INSERT INTO erp_fiscal_documentos(perfil_id,tipo,documento_origen_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en) SELECT perfil_id,'$tipo',".($origen??'NULL').",venta_id,'$op',huella,'$clave','$numero',snapshot_json,xml_firmado,xml_sha256,creado_por,creado_en,actualizado_en FROM erp_fiscal_documentos WHERE id=$f");
        foreach([['01',null,'001-002-000000099'],['04',$f,$nota['numero']],['01',null,$fa['numero']]] as $i=>[$t,$o,$num]){
            try{$insertar($t,$o,$num,str_pad((string)$i,49,'7'),str_pad((string)$i,64,'a'));self::fail('Debe rechazarse el caso '.$i);}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertContains($e->getCode(),[1062,3819],$e->getMessage());}
        }
        try{$insertar('04',null,'001-002-000000098',str_pad('9',49,'9'),str_pad('b',64,'b'));self::fail('Una NC exige documento de origen.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(3819,$e->getCode());}
        try{$this->r->q("UPDATE erp_fiscal_documentos SET tipo='01',documento_origen_id=NULL WHERE id=?",[$n]);self::fail('Tipo inmutable.');}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertContains($e->getCode(),[1644,3819,1062]);}
        $guard=require __DIR__.'/../../database/migrations/040_erp_fiscal_notas_credito.guard.php';self::assertNotEmpty($guard($this->db),'La reversión de 040 se niega si existen notas de crédito');
        foreach(['erp_fiscal_nc_devoluciones','erp_fiscal_nc_lineas'] as $t){foreach(["UPDATE $t SET documento_id=documento_id","DELETE FROM $t"] as $sql){try{$this->r->q($sql);self::fail($sql);}catch(\Repositories\PersistenceException|\mysqli_sql_exception $e){self::assertSame(1644,$e->getCode());}}}
    }
    public function testDosProcesosNoAcreditanDosVecesLaMismaDevolucion():void
    {
        $f=$this->factura();$dev=$this->devolver('1');$workers=[];$resultados=[];
        $datos=['factura_id'=>$f,'devolucion_ids'=>[$dev],'motivo'=>'Devolución de producto'];
        try{
            foreach([0,1] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php','nota_credito',(string)$this->actor,json_encode($this->d($datos))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],30);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        }finally{foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        self::assertSame(1,count(array_filter($resultados,fn($r)=>$r['ok'])),json_encode($resultados));
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE tipo='04'")['n']);self::assertSame(1,(int)$this->r->uno("SELECT ultimo FROM erp_fiscal_secuencias WHERE documento='04'")['ultimo']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_fiscal_nc_devoluciones')['n']);
    }
    public function testEntregaPorCorreoDeLaNotaUsaXmlYPdfAutorizadosDeLaNotaYNoDeLaFactura():void
    {
        $f=$this->factura();$dev=$this->devolver('1');$n=$this->nc($f,[$dev])['recurso_id'];$servicio=new ErpFiscalCorreoService($this->r);
        $datos=$this->d(['documento_id'=>$n,'email'=>'compradora@ensayo.test','confirmar_destinatario'=>'1']);
        try{$servicio->programar($this->actor,$datos);self::fail('Falta autorización de la nota.');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $this->autorizar($n);$anterior=\Classes\Email::getTransport();$capturados=[];
        \Classes\Email::setTransport(function($mail)use(&$capturados){self::assertSame(0,\Classes\Transaccion::nivel($this->db));$capturados[]=$mail;return true;});
        try{
            $servicio->programar($this->actor,$datos);$cola=$this->r->uno('SELECT * FROM correos_salida');self::assertCount(0,$capturados);
            $carga=\Classes\CargaCifrada::descifrar($cola['carga_cifrada']);$nota=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=?',[$n]);
            self::assertStringContainsString('<notaCredito',base64_decode($carga['xml']));self::assertStringContainsString($nota['clave_acceso'],base64_decode($carga['xml']));self::assertStringStartsWith('%PDF-',base64_decode($carga['pdf']));
            (new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository($this->db)))->despachar((int)$cola['id']);
            self::assertCount(1,$capturados);$m=$capturados[0];self::assertStringContainsString('Nota de crédito',$m->Subject);self::assertStringContainsString('SIN VALIDEZ TRIBUTARIA',$m->AltBody);
            self::assertSame(['nota-credito-'.$nota['numero'].'-autorizada.xml','nota-credito-'.$nota['numero'].'-ride.pdf'],array_map(fn($a)=>$a[1],$m->getAttachments()));
            self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos WHERE estado=\'completado\' AND monto<>34.50')['n']);
        }finally{\Classes\Email::setTransport($anterior);\Classes\Email::limpiarEmailsEnviados();}
    }

    public function testFichasRideYPermisosHttpDeLaNota():void
    {
        $f=$this->factura();$d1=$this->devolver('1');$d2=$this->devolver('1');$n=$this->nc($f,[$d1])['recurso_id'];$this->autorizar($n);
        $anterior=['sesion'=>$_SESSION??[],'get'=>$_GET,'server'=>$_SERVER];
        $pagina=function(int $usuario,callable $metodo,array $get,string $uri)use($f){
            $ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
            $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion']];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']=$uri;$_GET=$get;
            ob_start();try{$metodo();$h=ob_get_contents();}finally{ob_end_clean();}return $h;
        };
        try{
            if(session_status()===PHP_SESSION_NONE)session_start();
            $router=new \MVC\Router();
            $html=$pagina($this->actor,fn()=>\Controllers\ErpController::fiscal($router),['id'=>(string)$f],'/gestion/facturacion');
            self::assertStringContainsString('Notas de crédito',$html);self::assertStringContainsString('name="devolucion_ids[]"',$html,'ofrece solo las devoluciones aún sin acreditar');self::assertStringContainsString('name="devolucion_ids[]" value="'.$d2.'"',$html);self::assertStringNotContainsString('name="devolucion_ids[]" value="'.$d1.'"',$html);
            $html=$pagina($this->actor,fn()=>\Controllers\ErpController::fiscal($router),['id'=>(string)$n],'/gestion/facturacion');
            self::assertStringContainsString('Nota de crédito de prueba',$html);self::assertStringContainsString('no devuelve dinero',$html);self::assertStringContainsString('Devolución de producto',$html);
            $html=$pagina($this->actor,fn()=>\Controllers\ErpController::fiscalRide($router),['id'=>(string)$n],'/gestion/facturacion/ride');
            self::assertStringContainsString('NOTA DE CRÉDITO',$html);self::assertStringContainsString('Comprobante que se modifica',$html);self::assertStringContainsString('VALOR MODIFICADO',$html);self::assertStringNotContainsString('Formas de pago pactadas',$html);
            $lista=$pagina($this->actor,fn()=>\Controllers\ErpController::fiscal($router),['desde'=>date('Y-m-01'),'hasta'=>date('Y-m-d'),'estado'=>''],'/gestion/facturacion');
            self::assertStringContainsString('Nota de crédito',$lista);self::assertStringContainsString('<td>Factura</td>',$lista);
            // Un cliente no entra a la facturación (permiso verificado en el servidor, no por ocultar el enlace).
            $cliente=R5CajaFixture::usuario($this->db);$cualquiera=false;
            try{$pagina($cliente,fn()=>\Controllers\ErpController::fiscal($router),['id'=>(string)$n],'/gestion/facturacion');}catch(\Throwable $e){$cualquiera=true;}
            $_SESSION['id']=$cliente;
            try{$this->fiscal()->ride($cliente,$n);self::fail('403');}catch(ErpException $e){self::assertSame(403,$e->getCode());}
        }finally{$_SESSION=$anterior['sesion'];$_GET=$anterior['get'];$_SERVER=$anterior['server'];}
    }

    /** @dataProvider casosDeRedondeo */
    public function testElCalculoNuncaAcreditaMasQueLoFacturadoYLaSumaCuadraConLaUltimaPorcion(int $cantidad,int $precio,int $descuento,int $pb,array $porciones):void
    {
        $original=\Services\ErpCalculo::linea($cantidad,$precio,$descuento,$pb)['total'];$previa=0;$total=0;
        foreach($porciones as $q){
            $c=SriNotaCreditoCalculo::linea(['cantidad'=>$cantidad,'precio'=>$precio,'descuento'=>$descuento,'impuesto_pb'=>$pb],$previa,$q,$total);
            self::assertGreaterThan(0,$c['total']);self::assertSame($c['base']+$c['iva'],$c['total']);$previa+=$q;$total+=$c['total'];self::assertLessThanOrEqual($original,$total);
        }
        self::assertSame(array_sum($porciones),$previa);self::assertLessThanOrEqual(1,$original-$total,'La última porción cierra la línea con a lo sumo 1 centavo de diferencia por debajo');
    }
    public static function casosDeRedondeo():array
    {
        return ['unidades iguales'=>[3000,1000,0,1500,[1000,1000,1000]],'con descuento impar'=>[3000,3333,100,1500,[1000,2000]],'fraccion'=>[2500,1999,37,1500,[500,500,1500]],'cantidad fina'=>[7000,1234,251,1200,[1000,1000,1000,1000,1000,1000,1000]],'tarifa cero'=>[3000,999,0,0,[1000,1000,1000]],'una sola'=>[1000,1,0,1500,[1000]]];
    }
    public function testElCalculoRechazaSuperarCantidadOTotal():void
    {
        $l=['cantidad'=>2000,'precio'=>1000,'descuento'=>0,'impuesto_pb'=>1500];
        $this->expectException(ErpException::class);SriNotaCreditoCalculo::linea($l,1000,1500,1150);
    }
}
