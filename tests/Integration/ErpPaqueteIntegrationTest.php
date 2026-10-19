<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpPaqueteService,ErpPrepagoService,ErpMaestrosService,ErpCajaService,ErpVentaService,ErpDevolucionService,ErpPagoService,ErpComisionService,ErpException};

final class ErpPaqueteIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $cliente;private int $contacto;private int $servicio;private int $paquete;private int $prepago;private int $sesion;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('paquetes');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->actor=R5CajaFixture::usuario($this->db,'propietario');$this->cliente=R5CajaFixture::usuario($this->db);
        $this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$this->cliente]);
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->actor,$this->d(['nombre'=>'Beneficiario de sesiones','tipo'=>'cliente']))['recurso_id'];
        $m->vincularCliente($this->actor,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$this->cliente]));
        $this->r->q("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio del paquete',30,30)");$this->servicio=$this->r->id();
        $plan=$this->svc()->plan($this->actor,$this->d(['nombre'=>'Dos sesiones','servicio_id'=>$this->servicio,'sesiones'=>2,'precio'=>'20','impuesto'=>'0']))['recurso_id'];
        $this->paquete=$this->svc()->crear($this->actor,$this->d(['plan_id'=>$plan,'contacto_id'=>$this->contacto]))['recurso_id'];
        $this->prepago=(int)$this->r->uno('SELECT prepago_id FROM erp_paquetes WHERE id=?',[$this->paquete])['prepago_id'];
        $this->sesion=(new ErpCajaService($this->r))->abrir($this->actor,$this->d(['fondo'=>'0']))['recurso_id'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function svc():ErpPaqueteService {return new ErpPaqueteService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function cargar(string $monto='40'):void {(new ErpPrepagoService($this->r))->cargar($this->actor,$this->d(['prepago_id'=>$this->prepago,'metodo'=>'efectivo','monto'=>$monto,'recibido'=>$monto]));}
    private function visita(?int $cliente=null):array
    {
        $c=R5CajaFixture::cita($this->db,$cliente??$this->cliente,null,['30.00']);$this->r->q('UPDATE citasservicios SET servicioId=? WHERE citaId=?',[$this->servicio,$c]);
        $v=(new ErpVentaService($this->r))->crear($this->actor,$this->d(['cita_id'=>$c]))['recurso_id'];
        return $this->d(['paquete_id'=>$this->paquete,'venta_id'=>$v,'linea_id'=>$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v])['id'],'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]);
    }
    private function devuelve(array $d,string $cantidad='1'):void
    {(new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$d['venta_id'],'linea_id'=>$d['linea_id'],'cantidad'=>$cantidad,'motivo'=>'Reprogramación de la sesión de ensayo']));}
    private function saldo():int {return (new ErpPrepagoService($this->r))->saldo($this->prepago);}
    public function testCrearNoCobraNiGeneraSesionesAtendidas():void
    {
        self::assertSame(0,$this->saldo());
        foreach(['pagos','caja_movimientos','erp_paquete_aplicaciones'] as $tabla)self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM '.$tabla)['n']);
    }
    public function testReservaConPrecioPactadoSinDuplicarCajaYConsumoSoloConAtencion():void
    {
        $this->cargar();$d=$this->visita();$this->svc()->aplicar($this->actor,$d);
        self::assertSame(2000,$this->saldo());self::assertSame(4000,(new ErpCajaService($this->r))->esperado($this->sesion));
        $l=$this->r->uno('SELECT * FROM erp_venta_lineas WHERE id=?',[$d['linea_id']]);self::assertSame([3000,1000,2000],[(int)$l['precio_centavos'],(int)$l['descuento_centavos'],(int)$l['total_centavos']]);
        self::assertSame('reservada',$this->r->uno('SELECT estado FROM erp_paquete_aplicaciones')['estado']);
        self::assertSame(0,(new ErpVentaService($this->r))->resumen((int)$d['venta_id'])['saldo']);
        $this->r->q("UPDATE citas c JOIN erp_ventas v ON v.cita_id=c.id SET c.estado='completada' WHERE v.id=?",[$d['venta_id']]);
        (new ErpComisionService($this->r))->recalcular($this->actor,$this->d(['venta_id'=>$d['venta_id']]));
        self::assertSame('consumida',$this->r->uno('SELECT estado FROM erp_paquete_aplicaciones')['estado']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='paquete_sesion_consumida'")['n']);
    }
    public function testSaldoInsuficienteRevierteDescuentoConfirmacionYReserva():void
    {
        $d=$this->visita();try{$this->svc()->aplicar($this->actor,$d);self::fail('No hay financiación');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame('borrador',$this->r->uno('SELECT estado FROM erp_ventas')['estado']);self::assertSame(3000,(int)$this->r->uno('SELECT total_centavos FROM erp_venta_lineas')['total_centavos']);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones')['n']);
    }
    public function testNoSeFinancianMasSesionesQueLasPactadas():void
    {
        $this->cargar();$this->expectException(ErpException::class);$this->cargar('0.01');
    }
    public function testOtraPersonaNoPuedeUsarElPaquete():void
    {
        $this->cargar();$otro=R5CajaFixture::usuario($this->db);$d=$this->visita($otro);
        try{$this->svc()->aplicar($this->actor,$d);self::fail('Beneficiario distinto');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(4000,$this->saldo());self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones')['n']);
    }
    public function testUnServicioDiferenteNoConsumeElPaquete():void
    {
        $this->cargar();$d=$this->visita();$this->r->q("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Otro servicio',30,30)");$otro=$this->r->id();
        $this->r->q('UPDATE erp_venta_lineas SET servicio_id=? WHERE id=?',[$otro,$d['linea_id']]);
        $this->expectException(ErpException::class);$this->svc()->aplicar($this->actor,$d);
    }
    public function testNoSeAcumulaDescuentoManual():void
    {
        $this->cargar();$d=$this->visita();$this->r->q('UPDATE erp_venta_lineas SET descuento_centavos=100 WHERE id=?',[$d['linea_id']]);
        $this->expectException(ErpException::class);$this->svc()->aplicar($this->actor,$d);
    }
    public function testElSaldoDePaqueteNoSeGastaPorElFormularioGenerico():void
    {
        $this->cargar();$d=$this->visita();(new ErpVentaService($this->r))->confirmar($this->actor,$this->d(['venta_id'=>$d['venta_id'],'version'=>$d['version']]));
        $this->expectException(ErpException::class);(new ErpPrepagoService($this->r))->aplicar($this->actor,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$d['venta_id'],'monto'=>'20']));
    }
    public function testReenvioPermanenteNoConsumeUnaSegundaSesion():void
    {
        $this->cargar();$d=$this->visita();$this->svc()->aplicar($this->actor,$d);
        $this->r->q("UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)");
        $this->svc()->aplicar($this->actor,$d);
        self::assertSame(2000,$this->saldo());self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones')['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
        $this->r->q("UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)");$d['version']=(int)$d['version']+1;
        $this->expectException(ErpException::class);$this->svc()->aplicar($this->actor,$d);
    }
    public function testTerceraSesionNoSeReservaAunqueSeReintente():void
    {
        $this->cargar();$this->svc()->aplicar($this->actor,$this->visita());$this->svc()->aplicar($this->actor,$this->visita());
        try{$this->svc()->aplicar($this->actor,$this->visita());self::fail('Agotado');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(0,$this->saldo());self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones')['n']);
    }
    public function testDevolverRestituyeSesionYSaldoSinEgresoDeEfectivo():void
    {
        $this->cargar();$d=$this->visita();$this->svc()->aplicar($this->actor,$d);$this->devuelve($d);
        self::assertSame(4000,$this->saldo());self::assertSame('restituida',$this->r->uno('SELECT estado FROM erp_paquete_aplicaciones')['estado']);
        self::assertSame(4000,(new ErpCajaService($this->r))->esperado($this->sesion));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
        $this->svc()->aplicar($this->actor,$this->visita());self::assertSame(2000,$this->saldo());
    }
    public function testNoSeDevuelveMediaSesion():void
    {
        $this->cargar();$d=$this->visita();$this->svc()->aplicar($this->actor,$d);$this->expectException(ErpException::class);$this->devuelve($d,'0.5');
    }
    public function testReintegroDeSaldoSinUtilizarReduceLasSesionesFinanciadas():void
    {
        $this->cargar();$c=$this->r->uno('SELECT id FROM erp_prepago_cargas');(new ErpPrepagoService($this->r))->reembolsar($this->actor,$this->d(['prepago_id'=>$this->prepago,'carga_id'=>$c['id'],'monto'=>'30','motivo'=>'Devolución de saldo sin utilizar']));
        self::assertSame(1000,$this->saldo());$this->expectException(ErpException::class);$this->svc()->aplicar($this->actor,$this->visita());
    }
    public function testSesionSeRestituyeConOtrosProductosTodaviaSinPagar():void
    {
        $this->cargar();$d=$this->visita();$this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('PAQ','Producto adicional','unidad',5000)");$producto=$this->r->id();
        $a=(new ErpMaestrosService($this->r))->catalogo($this->actor,$this->d(['origen'=>'producto','origen_id'=>$producto,'precio'=>'40','impuesto'=>'0']))['recurso_id'];
        (new ErpVentaService($this->r))->agregar($this->actor,$this->d(['venta_id'=>$d['venta_id'],'catalogo_id'=>$a,'cantidad'=>'1']));$d['version']=$this->r->uno('SELECT version FROM erp_ventas')['version'];
        $this->svc()->aplicar($this->actor,$d);self::assertSame(4000,(new ErpVentaService($this->r))->resumen((int)$d['venta_id'])['saldo']);
        $this->devuelve($d);self::assertSame(4000,$this->saldo());self::assertSame(4000,(new ErpVentaService($this->r))->resumen((int)$d['venta_id'])['saldo']);
    }
    public function testClienteNoConfiguraPlanesNiConsumePorUrlDeEquipo():void
    {
        $this->expectException(ErpException::class);$this->svc()->aplicar($this->cliente,$this->visita());
    }
    private function competir(array $operaciones):array
    {
        $h=new \mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),BaseDatosAislada::nombre($this->db),(int)getenv('DB_PORT'));$h->begin_transaction();$h->query('SELECT id FROM erp_prepagos WHERE id='.$this->prepago.' FOR UPDATE');$workers=[];$resultados=[];
        try {
            foreach($operaciones as $datos){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php','paquete',(string)$this->actor,json_encode($datos)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}usleep(400000);$h->commit();
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        }finally{$h->rollback();$h->close();foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        return $resultados;
    }
    public function testDosVisitasNoAsignanLaMismaSesionFinanciada():void
    {
        $this->cargar('20');$r=$this->competir([$this->visita(),$this->visita()]);self::assertSame(1,count(array_filter($r,static fn($a)=>$a['ok'])));self::assertSame(0,$this->saldo());self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones')['n']);
    }
    public function testDosSolicitudesDeLaMismaVentaNoAsignanDosSesiones():void
    {
        $this->cargar();$a=$this->visita();$b=$a;$b['idem_key']=R5CajaFixture::clave();$r=$this->competir([$a,$b]);self::assertSame(1,count(array_filter($r,static fn($a)=>$a['ok'])));self::assertSame(2000,$this->saldo());self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
    private function dosServicios():array
    {
        $c=R5CajaFixture::cita($this->db,$this->cliente,null,['30.00','30.00']);$this->r->q('UPDATE citasservicios SET servicioId=? WHERE citaId=?',[$this->servicio,$c]);
        $v=(new ErpVentaService($this->r))->crear($this->actor,$this->d(['cita_id'=>$c]))['recurso_id'];$seleccion=[];foreach($this->r->q('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v]) as $l)$seleccion[(int)$l['id']]=$this->paquete;
        return $this->d(['venta_id'=>$v,'paquetes'=>$seleccion,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]);
    }
    public function testDosServiciosDeLaMismaVisitaConsumenDosSesionesUnaSolaVez():void
    {
        $this->cargar();$d=$this->dosServicios();$this->svc()->aplicar($this->actor,$d);self::assertSame(0,$this->saldo());self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones')['n']);
        $this->r->q("UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)");self::assertTrue($this->svc()->aplicar($this->actor,$d)['repetida']);self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
        $linea=(int)array_key_first($d['paquetes']);$this->devuelve(['venta_id'=>$d['venta_id'],'linea_id'=>$linea]);self::assertSame(2000,$this->saldo());self::assertSame(0,(new ErpVentaService($this->r))->resumen((int)$d['venta_id'])['saldo']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_paquete_aplicaciones WHERE estado='reservada'")['n']);
    }
    public function testFaltaDeFinanciacionEnVariasSesionesNoDejaUnaAsignacionParcial():void
    {
        $this->cargar('30');$d=$this->dosServicios();try{$this->svc()->aplicar($this->actor,$d);self::fail('No alcanza para dos sesiones');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(3000,$this->saldo());self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones')['n']);self::assertSame('borrador',$this->r->uno('SELECT estado FROM erp_ventas')['estado']);
    }
    public function testComisionDeSesionPagadaNoEsperaElCobroDeOtrosProductos():void
    {
        $u=R5CajaFixture::usuario($this->db,'profesional');$email=R5CajaFixture::correo($this->db,$u);
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,codigo) VALUES(?,?,'Profesional','Paquete','profesional','activo','COL-PKG')",[$u,$email]);$col=$this->r->id();
        $this->r->q("INSERT INTO profesionales(nombre,activo,usuarioId,colaborador_id) VALUES('Profesional de sesiones',1,?,?)",[$u,$col]);$prof=$this->r->id();
        (new ErpComisionService($this->r))->configurar($this->actor,$this->d(['colaborador_id'=>$col,'porcentaje'=>'10','desde'=>'2020-01-01']));
        $this->cargar();$d=$this->visita();$this->r->q("UPDATE citas c JOIN erp_ventas v ON v.cita_id=c.id SET c.profesionalId=?,c.estado='completada' WHERE v.id=?",[$prof,$d['venta_id']]);
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('COM-P','Producto sin cobrar','unidad',2000)");$producto=$this->r->id();$catalogo=(new ErpMaestrosService($this->r))->catalogo($this->actor,$this->d(['origen'=>'producto','origen_id'=>$producto,'precio'=>'40','impuesto'=>'0']))['recurso_id'];
        (new ErpVentaService($this->r))->agregar($this->actor,$this->d(['venta_id'=>$d['venta_id'],'catalogo_id'=>$catalogo,'cantidad'=>'1']));$d['version']=$this->r->uno('SELECT version FROM erp_ventas')['version'];
        $this->svc()->aplicar($this->actor,$d);self::assertSame(200,(int)$this->r->uno('SELECT SUM(monto_centavos) n FROM erp_comision_movimientos')['n']);
        $this->devuelve($d);self::assertSame(0,(int)$this->r->uno('SELECT SUM(monto_centavos) n FROM erp_comision_movimientos')['n']);
    }
    public function testRespaldoConservaSaldoSesionesContratosYProtecciones():void
    {
        $this->cargar();$d=$this->visita();$this->svc()->aplicar($this->actor,$d);
        $plan=$this->r->uno('SELECT plan_id FROM erp_paquetes WHERE id=?',[$this->paquete])['plan_id'];
        (new \Services\ErpSuscripcionService($this->r))->crear($this->actor,$this->d(['plan_id'=>$plan,'contacto_id'=>$this->contacto,'inicio'=>substr($this->r::ahora(),0,10),'renovacion_autorizada'=>'1','consentimiento'=>'Aceptación mensual conservada en recuperación']));
        $origen=BaseDatosAislada::nombre($this->db);$destino='appsalon_fixture_paquete_restore_'.bin2hex(random_bytes(5)).'_test';$archivo=sys_get_temp_dir().'/paquete_bk_'.bin2hex(random_bytes(8)).'.sql.gz';$restaurada=null;
        $cli=function(array $args):void {$pipes=[];$p=proc_open(array_merge([PHP_BINARY],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out.$err);};
        try {
            $cli(['scripts/backup_db.php','--db='.$origen,'--output='.$archivo,'--gzip']);
            $this->r->q('CREATE DATABASE `'.$destino.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $cli(['scripts/restore_db.php','--db='.$destino,'--input='.$archivo,'--hash='.hash_file('sha256',$archivo)]);
            $restaurada=new \mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),$destino,(int)getenv('DB_PORT'));$r=new ErpRepository($restaurada);
            self::assertSame(2000,(new ErpPrepagoService($r))->saldo($this->prepago));self::assertSame('reservada',$r->uno('SELECT estado FROM erp_paquete_aplicaciones')['estado']);self::assertSame(1,(int)$r->uno('SELECT COUNT(*) n FROM erp_suscripcion_ciclos')['n']);
            self::assertSame($this->r->uno("SELECT comprobante_sha256 FROM erp_prepago_movimientos WHERE tipo='carga'")['comprobante_sha256'],$r->uno("SELECT comprobante_sha256 FROM erp_prepago_movimientos WHERE tipo='carga'")['comprobante_sha256']);
            self::assertFalse($restaurada->query('DELETE FROM erp_paquete_aplicaciones'));self::assertSame(1644,$restaurada->errno);
            self::assertFalse($restaurada->query('UPDATE erp_paquetes SET sesiones=100'));self::assertSame(1644,$restaurada->errno);
            $guard=require __DIR__.'/../../database/migrations/035_erp_paquetes.guard.php';self::assertNotEmpty($guard($restaurada));
        }finally{if($restaurada)$restaurada->close();$this->r->q('DROP DATABASE IF EXISTS `'.$destino.'`');foreach([$archivo,$archivo.'.manifest.json'] as $f)if(is_file($f))unlink($f);}
    }
}
