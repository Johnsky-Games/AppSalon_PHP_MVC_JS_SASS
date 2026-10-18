<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\ErpRepository;
use Services\{ErpPrepagoService,ErpCajaService,ErpMaestrosService,ErpVentaService,ErpPagoService,ErpDevolucionService,ErpEntregaService,ErpInformeService,ErpException,CobroCitaService,CobroRechazado};
final class ErpPrepagoIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $contacto;private int $prepago;private int $sesion;private int $producto;private int $catalogo;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('prepagos');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->owner,$this->d(['nombre'=>'Beneficiario de ensayo','tipo'=>'cliente']))['recurso_id'];
        $this->prepago=(new ErpPrepagoService($this->r))->crear($this->owner,$this->d(['contacto_id'=>$this->contacto,'tipo'=>'regalo','nombre'=>'Regalo de ensayo']))['recurso_id'];
        $this->sesion=(new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']))['recurso_id'];
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('PRE','Producto de ensayo','unidad',10000)");$this->producto=$this->r->id();
        $this->catalogo=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'20','impuesto'=>'0']))['recurso_id'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function svc():ErpPrepagoService {return new ErpPrepagoService($this->r);}
    private function cargar(string $monto='50',array $extra=[]):array
    {return $this->svc()->cargar($this->owner,$this->d($extra+['prepago_id'=>$this->prepago,'metodo'=>'efectivo','monto'=>$monto,'recibido'=>$monto]));}
    private function venta(?int $contacto=null):int
    {
        $s=new ErpVentaService($this->r);$v=$s->crear($this->owner,$this->d(['contacto_id'=>$contacto??$this->contacto]))['recurso_id'];
        $s->agregar($this->owner,$this->d(['venta_id'=>$v,'catalogo_id'=>$this->catalogo,'cantidad'=>'1']));
        $s->confirmar($this->owner,$this->d(['venta_id'=>$v,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]));return $v;
    }
    public function testCargaNoEsUnaVentaNiUnaComisionYElCambioNoAumentaSaldo():void
    {
        $d=$this->d(['prepago_id'=>$this->prepago,'metodo'=>'efectivo','monto'=>'50','recibido'=>'60']);
        $a=$this->svc()->cargar($this->owner,$d);$b=$this->svc()->cargar($this->owner,$d);self::assertSame($a['recurso_id'],$b['recurso_id']);self::assertTrue($b['repetida']);
        self::assertSame(5000,$this->svc()->saldo($this->prepago));self::assertSame(5000,(new ErpCajaService($this->r))->esperado($this->sesion));
        self::assertSame(1000,(int)$this->r->uno('SELECT cambio_centavos FROM erp_prepago_cargas')['cambio_centavos']);
        foreach(['erp_ventas','pagos','erp_comision_movimientos'] as $t)self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM '.$t)['n']);
        self::assertFalse($this->db->query("INSERT INTO erp_prepago_cargas(prepago_id,clave_operacion,huella,monto_centavos,metodo,estado,registrado_por,creado_en) VALUES({$this->prepago},REPEAT('a',64),REPEAT('b',64),100,'efectivo','confirmada',{$this->owner},NOW())"));self::assertSame(3819,$this->db->errno,'el efectivo exige recibido y cambio incluso ante SQL directo');
    }
    public function testAplicacionParcialNoRecibeDineroOtraVezYEsIdempotente():void
    {
        $this->cargar();$v=$this->venta();$d=$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'15']);
        $this->svc()->aplicar($this->owner,$d);self::assertTrue($this->svc()->aplicar($this->owner,$d)['repetida']);
        self::assertSame(3500,$this->svc()->saldo($this->prepago));self::assertSame(500,(new ErpVentaService($this->r))->resumen($v)['saldo']);
        self::assertSame(5000,(new ErpCajaService($this->r))->esperado($this->sesion));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_recibos')['n']);
        self::assertSame('prepago',$this->r->uno('SELECT metodo_pago FROM pagos')['metodo_pago']);
    }
    public function testDevolverVentaRestituyeSaldoYStockSinPagarEfectivo():void
    {
        $this->cargar();$v=$this->venta();$this->svc()->aplicar($this->owner,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'20']));
        (new ErpEntregaService($this->r))->entregar($this->owner,$this->d(['venta_id'=>$v]));
        $l=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v]);
        $d=$this->d(['venta_id'=>$v,'linea_id'=>$l['id'],'cantidad'=>'1','motivo'=>'Devolución completa de ensayo','reintegrar_stock'=>'1']);
        $s=new ErpDevolucionService($this->r);$s->devolver($this->owner,$d);$s->devolver($this->owner,$d);
        self::assertSame(5000,$this->svc()->saldo($this->prepago));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
        self::assertSame(10000,(int)$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=?',[$this->producto])['stock_milesimas']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_prepago_movimientos WHERE tipo='restitucion'")['n']);
        self::assertSame(0,(new ErpVentaService($this->r))->resumen($v)['saldo']);
    }
    public function testNoPuedeAplicarSaldoDeOtroBeneficiario():void
    {
        $this->cargar();$otro=(new ErpMaestrosService($this->r))->contacto($this->owner,$this->d(['nombre'=>'Otra persona','tipo'=>'cliente']))['recurso_id'];$v=$this->venta($otro);
        try{$this->svc()->aplicar($this->owner,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'20']));self::fail('Beneficiario distinto');}
        catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(5000,$this->svc()->saldo($this->prepago));self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
    public function testTransferenciaPendienteNoDaSaldoYVerificacionSeHaceUnaVez():void
    {
        $this->cargar('50',['metodo'=>'transferencia','referencia'=>'BANCO-0001']);self::assertSame(0,$this->svc()->saldo($this->prepago));
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
        $c=$this->r->uno('SELECT id FROM erp_prepago_cargas');$d=$this->d(['prepago_id'=>$this->prepago,'carga_id'=>$c['id'],'decision'=>'confirmar','nota'=>'Verificado en extracto de ensayo']);
        $this->svc()->verificar($this->owner,$d);$this->svc()->verificar($this->owner,$d);
        self::assertSame(5000,$this->svc()->saldo($this->prepago));self::assertSame(0,(new ErpCajaService($this->r))->esperado($this->sesion));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
    }
    public function testTransferenciaRechazadaLiberaReferenciaSinCambiarSaldo():void
    {
        $this->cargar('50',['metodo'=>'transferencia','referencia'=>'BANCO-0002']);$c=$this->r->uno('SELECT id FROM erp_prepago_cargas');
        $this->svc()->verificar($this->owner,$this->d(['prepago_id'=>$this->prepago,'carga_id'=>$c['id'],'decision'=>'rechazar','nota'=>'No se encontró el ingreso']));
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_transferencia_referencias')['n']);
        $this->cargar('10',['metodo'=>'transferencia','referencia'=>'BANCO-0002']);self::assertSame(0,$this->svc()->saldo($this->prepago));
    }
    public function testReembolsoNoSuperaSaldoNiCargaYRegistraUnSoloEgreso():void
    {
        $this->cargar();$v=$this->venta();$this->svc()->aplicar($this->owner,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'20']));
        $c=$this->r->uno('SELECT id FROM erp_prepago_cargas');$d=$this->d(['prepago_id'=>$this->prepago,'carga_id'=>$c['id'],'monto'=>'30','motivo'=>'Saldo no utilizado de ensayo']);
        $this->svc()->reembolsar($this->owner,$d);$this->svc()->reembolsar($this->owner,$d);self::assertSame(0,$this->svc()->saldo($this->prepago));
        self::assertSame(2000,(new ErpCajaService($this->r))->esperado($this->sesion));
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
        $this->expectException(ErpException::class);$this->svc()->reembolsar($this->owner,$this->d(['prepago_id'=>$this->prepago,'carga_id'=>$c['id'],'monto'=>'0.01','motivo'=>'Intento de exceder saldo']));
    }
    public function testReferenciaNoFinanciaVentaYCargaDePrepago():void
    {
        $this->cargar('50',['metodo'=>'transferencia','referencia'=>'BANCO-0003']);$v=$this->venta();
        try{(new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$v,'metodo'=>'transferencia','monto'=>'20','referencia'=>'BANCO-0003']));self::fail('Referencia duplicada');}
        catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_prepago_cargas')['n']);
    }
    public function testReferenciaDeCajaHistoricaTampocoFinanciaPrepago():void
    {
        $u=R5CajaFixture::usuario($this->db);$c=R5CajaFixture::cita($this->db,$u,null,['20']);
        (new CobroCitaService($this->r))->cobrar($this->owner,$this->d(['cita_id'=>$c,'metodo'=>'transferencia','monto'=>'20','referencia'=>'BANCO-0004']));
        try{$this->cargar('50',['metodo'=>'transferencia','referencia'=>'BANCO-0004']);self::fail('Referencia duplicada');}
        catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_prepago_cargas')['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
    public function testLibroNoAdmiteEditarNiBorrarYBiSeparaSaldoDeCaja():void
    {
        $this->cargar();foreach(['UPDATE erp_prepago_movimientos SET monto_centavos=1','DELETE FROM erp_prepago_movimientos'] as $sql){
            self::assertFalse($this->db->query($sql));self::assertSame(1644,$this->db->errno);
        }
        $hoy=substr($this->r::ahora(),0,10);$bi=(new ErpInformeService($this->r))->obtener($this->owner,['desde'=>$hoy,'hasta'=>$hoy]);
        self::assertSame(5000,(int)$bi['prepagos']['saldo']);self::assertSame(5000,(int)$bi['caja']['ingresos']);self::assertSame(0,(int)$bi['ventas']['bruto']);
    }
    private function competir(string $tabla,int $id,array $operaciones):array
    {
        if(!in_array($tabla,['erp_prepagos','erp_ventas'],true))throw new \LogicException('Tabla de barrera');
        $holder=new \mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),BaseDatosAislada::nombre($this->db),(int)getenv('DB_PORT'));
        $holder->begin_transaction();$holder->query("SELECT id FROM $tabla WHERE id=$id FOR UPDATE");$workers=[];$resultados=[];
        try {
            foreach($operaciones as [$accion,$datos]){
                $pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php',$accion,(string)$this->owner,json_encode($datos)],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));
                self::assertIsResource($p);stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));
            }
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}usleep(400000);$holder->commit();
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        } finally {$holder->rollback();$holder->close();foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        return $resultados;
    }
    public function testDosVentasNoGastanDosVecesElMismoSaldo():void
    {
        $this->cargar('30');$a=$this->venta();$b=$this->venta();$ops=[];
        foreach([$a,$b] as $v)$ops[]=['aplicar',$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'20'])];
        $res=$this->competir('erp_prepagos',$this->prepago,$ops);self::assertCount(1,array_filter($res,fn($r)=>$r['ok']),json_encode($res));
        self::assertSame(1000,$this->svc()->saldo($this->prepago));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
    public function testCobroYPrepagoSimultaneosNoSuperanElSaldoDeLaVenta():void
    {
        $this->cargar('50');$v=$this->venta();$ops=[['aplicar',$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'15'])],['cobrar',$this->d(['venta_id'=>$v,'metodo'=>'efectivo','monto'=>'15','recibido'=>'15'])]];
        $res=$this->competir('erp_ventas',$v,$ops);self::assertCount(1,array_filter($res,fn($r)=>$r['ok']),json_encode($res));
        self::assertSame(500,(new ErpVentaService($this->r))->resumen($v)['saldo']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
    public function testCajaHistoricaNoReutilizaReferenciaDePrepago():void
    {
        $this->cargar('20',['metodo'=>'transferencia','referencia'=>'BANCO-0005']);$u=R5CajaFixture::usuario($this->db);$c=R5CajaFixture::cita($this->db,$u,null,['20']);
        try{(new CobroCitaService($this->r))->cobrar($this->owner,$this->d(['cita_id'=>$c,'metodo'=>'transferencia','monto'=>'20','referencia'=>'BANCO-0005']));self::fail('Referencia duplicada');}
        catch(CobroRechazado $e){self::assertSame(409,$e->http());}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
    public function testGerenteNoAutoverificaYFinanzasSiPuedeVerificarConSuCaja():void
    {
        $g=R5CajaFixture::usuario($this->db,'gerente');$f=R5CajaFixture::usuario($this->db,'finanzas');$cajas=new ErpCajaService($this->r);
        $cajas->abrir($g,$this->d(['fondo'=>'0']));$sid=$cajas->abrir($f,$this->d(['fondo'=>'0']))['recurso_id'];
        $this->svc()->cargar($g,$this->d(['prepago_id'=>$this->prepago,'metodo'=>'transferencia','monto'=>'20','referencia'=>'BANCO-0006']));
        $c=$this->r->uno('SELECT id FROM erp_prepago_cargas');$d=$this->d(['prepago_id'=>$this->prepago,'carga_id'=>$c['id'],'decision'=>'confirmar','nota'=>'Recepción bancaria verificada']);
        try{$this->svc()->verificar($g,$d);self::fail('Autoverificación de gerente');}catch(ErpException $e){self::assertSame(403,$e->getCode());}
        $this->svc()->verificar($f,$d);self::assertSame(2000,$this->svc()->saldo($this->prepago));self::assertSame(0,$cajas->esperado($sid));
        self::assertSame($sid,(int)$this->r->uno('SELECT sesion_id FROM erp_caja_movimientos')['sesion_id']);
    }
    public function testMembresiaVinculadaPuedeUsarSuPrepagoEnUnaCita():void
    {
        $u=R5CajaFixture::usuario($this->db);$this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','recepcion')",[$u]);
        (new ErpMaestrosService($this->r))->vincularCliente($this->owner,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$u]));
        $this->cargar();$c=R5CajaFixture::cita($this->db,$u,null,['20'],'completada');$s=new ErpVentaService($this->r);$v=$s->crear($this->owner,$this->d(['cita_id'=>$c]))['recurso_id'];
        $s->confirmar($this->owner,$this->d(['venta_id'=>$v,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]));
        $this->svc()->aplicar($this->owner,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'20']));
        self::assertSame('pagado',$this->r->uno('SELECT estado_pago FROM citas WHERE id=?',[$c])['estado_pago']);self::assertSame(3000,$this->svc()->saldo($this->prepago));
        $hoy=substr($this->r::ahora(),0,10);$g=(new \Repositories\CrmRepository($this->db))->gastoDeCliente($u,$hoy,$hoy);self::assertSame(2000,$g['cobros_real']);
    }

    public function testComprobanteCongelaEmpresaBeneficiarioYCambio():void
    {
        $this->cargar('50',['recibido'=>'60']);$mov=$this->r->uno("SELECT id FROM erp_prepago_movimientos WHERE tipo='carga'")['id'];
        $antes=$this->svc()->comprobante($this->owner,(int)$mov);
        $this->r->q("UPDATE erp_empresa SET nombre='Otra razón social' WHERE id=1");$this->r->q("UPDATE erp_contactos SET nombre='Otro nombre' WHERE id=?",[$this->contacto]);
        self::assertSame($antes,$this->svc()->comprobante($this->owner,(int)$mov));
        self::assertSame(1000,(int)$antes['contenido']['carga']['cambio_centavos']);self::assertSame(5000,(int)$antes['contenido']['saldo_despues']);
        $u=R5CajaFixture::usuario($this->db,'profesional');$this->expectException(ErpException::class);$this->svc()->comprobante($u,(int)$mov);
    }

    public function testComisionDeServicioPrepagoEsperaAtencionYSeRevierteSinEfectivo():void
    {
        $u=R5CajaFixture::usuario($this->db);$this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','recepcion')",[$u]);
        (new ErpMaestrosService($this->r))->vincularCliente($this->owner,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$u]));
        $proUsuario=R5CajaFixture::usuario($this->db,'profesional');
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado) SELECT id,email,nombre,apellido,'profesional','activo' FROM usuarios WHERE id=?",[$proUsuario]);$col=$this->r->id();
        $prof=R5CajaFixture::profesional($this->db);$this->r->q('UPDATE profesionales SET colaborador_id=?,usuarioId=? WHERE id=?',[$col,$proUsuario,$prof]);
        $com=new \Services\ErpComisionService($this->r);$com->configurar($this->owner,$this->d(['colaborador_id'=>$col,'porcentaje'=>'10','desde'=>'2020-01-01']));
        $this->cargar();$c=R5CajaFixture::cita($this->db,$u,$prof,['20'],'confirmada');$s=new ErpVentaService($this->r);$v=$s->crear($this->owner,$this->d(['cita_id'=>$c]))['recurso_id'];
        $s->confirmar($this->owner,$this->d(['venta_id'=>$v,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]));
        $this->svc()->aplicar($this->owner,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v,'monto'=>'20']));self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_comision_movimientos')['n']);
        $this->r->q("UPDATE citas SET estado='completada' WHERE id=?",[$c]);$com->recalcular($this->owner,$this->d(['venta_id'=>$v]));
        self::assertSame(200,(int)$this->r->uno('SELECT SUM(monto_centavos) n FROM erp_comision_movimientos')['n']);
        $linea=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v])['id'];
        (new ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$v,'linea_id'=>$linea,'cantidad'=>'1','motivo'=>'Devolución del servicio de ensayo']));
        self::assertSame(0,(int)$this->r->uno('SELECT SUM(monto_centavos) n FROM erp_comision_movimientos')['n']);self::assertSame(5000,$this->svc()->saldo($this->prepago));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
    }

    public function testReenvioTrasVencerLaCacheNoVuelveACargarDinero():void
    {
        $d=$this->d(['prepago_id'=>$this->prepago,'metodo'=>'efectivo','monto'=>'50','recibido'=>'60']);
        $this->svc()->cargar($this->owner,$d);(new ErpCajaService($this->r))->cerrar($this->owner,$this->d(['contado'=>'50','motivo'=>'Cierre de caja de ensayo']));
        $this->r->q("UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE scope='erp.prepago_cargar'");
        $eventos=(int)$this->r->uno('SELECT COUNT(*) n FROM erp_eventos')['n'];
        self::assertTrue($this->svc()->cargar($this->owner,$d)['repetida']);
        self::assertSame(5000,$this->svc()->saldo($this->prepago));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_prepago_cargas')['n']);
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);self::assertSame($eventos,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_eventos')['n']);
        $this->r->q("UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE scope='erp.prepago_cargar'");$d['monto']='60';
        try{$this->svc()->cargar($this->owner,$d);self::fail('Clave de carga reutilizada con otros datos');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
    }

}
