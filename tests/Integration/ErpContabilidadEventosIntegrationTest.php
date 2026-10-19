<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpContabilidadService,ErpIntegracionService,ErpMaestrosService,ErpVentaService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpDevolucionService,ErpCompraService,ErpPrepagoService,ErpPaqueteService,ErpComisionService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/** Los hechos económicos del ERP se contabilizan una sola vez, por separado, y los saldos concilian con los auxiliares del negocio. */
final class ErpContabilidadEventosIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $contacto;private int $usuarioCliente;private int $producto;private int $catalogo;private int $sesion;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('cont_eventos');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->usuarioCliente=R5CajaFixture::usuario($this->db);
        $this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$this->usuarioCliente]);
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->owner,$this->d(['nombre'=>'Clienta contable','tipo'=>'cliente']))['recurso_id'];$m->vincularCliente($this->owner,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$this->usuarioCliente]));
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('CT-1','Champú','unidad',0,0)");$this->producto=$this->r->id();
        $this->catalogo=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'10.00','impuesto'=>'15.00','tipo'=>'venta']))['recurso_id'];
        $this->sesion=(new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']))['recurso_id'];
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    /** Procesa la cola contable hasta vaciarla y devuelve el resultado del último pase. */
    private function contabilizar(bool $esperaPendientes=false):array
    {
        $a=new ErpContabilidadAdaptador($this->r);$res=['confirmados'=>0,'fallidos'=>0];
        for($i=0;$i<6;$i++){$x=(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);$res['confirmados']+=$x['confirmados'];$res['fallidos']+=$x['fallidos'];if(!$x['confirmados'])break;}
        $res['prestaciones']=$a->prestaciones();
        if(!$esperaPendientes)self::assertSame(0,$this->pendientesDeContabilizar(),'Todo hecho económico debe quedar contabilizado: '.json_encode($this->r->q("SELECT referencia,estado FROM erp_integraciones_outbox WHERE destino='contabilidad' AND estado<>'confirmado'")));
        return $res;
    }
    private function saldo(string $rol,?int $contacto=null):int
    {
        $c=$this->cont();$cuenta=$c->rolCuenta($rol);$tipo=ErpContabilidadService::ROLES[$rol];
        $f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?'.($contacto?' AND contacto_id='.$contacto:''),[$cuenta]);
        return (in_array($tipo,['activo','costo','gasto'],true)?1:-1)*((int)$f['d']-(int)$f['h']);
    }
    private function pendientesDeContabilizar():int {return (int)$this->r->uno("SELECT COUNT(*) n FROM erp_integraciones_outbox WHERE destino='contabilidad' AND estado<>'confirmado'")['n'];}
    private function compraDeProductos(string $cantidad,string $costo,string $impuesto='15.00'):int
    {
        $c=new ErpCompraService($this->r);$prov=(new ErpMaestrosService($this->r))->contacto($this->owner,$this->d(['nombre'=>'Proveedor contable','tipo'=>'proveedor']))['recurso_id'];
        $compra=$c->crear($this->owner,$this->d(['proveedor_id'=>$prov]))['recurso_id'];$c->agregar($this->owner,$this->d(['compra_id'=>$compra,'producto_id'=>$this->producto,'cantidad'=>$cantidad,'costo'=>$costo,'impuesto'=>$impuesto]));
        $total=number_format((int)$this->r->uno('SELECT total_centavos FROM erp_compras WHERE id=?',[$compra])['total_centavos']/100,2,'.','');
        $c->ordenar($this->owner,$this->d(['compra_id'=>$compra,'total_esperado'=>$total]));return $compra;
    }
    private function ventaDeProductos(string $cantidad,bool $cobrar=true,bool $entregar=true):int
    {
        $v=new ErpVentaService($this->r);$id=$v->crear($this->owner,$this->d(['contacto_id'=>$this->contacto]))['recurso_id'];$v->agregar($this->owner,$this->d(['venta_id'=>$id,'catalogo_id'=>$this->catalogo,'cantidad'=>$cantidad]));
        $v->confirmar($this->owner,$this->d(['venta_id'=>$id,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$id])['version']]));
        if($cobrar){$total=number_format($v->resumen($id)['total']/100,2,'.','');(new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$id,'monto'=>$total,'recibido'=>$total,'metodo'=>'efectivo']));}
        if($entregar)(new ErpEntregaService($this->r))->entregar($this->owner,$this->d(['venta_id'=>$id]));
        return $id;
    }
    /** Compra 10 unidades a 5,00 + 15 %, recibidas, facturadas y pagadas parcialmente. */
    private function abastecer():int
    {
        $c=new ErpCompraService($this->r);$compra=$this->compraDeProductos('10','5.00');$linea=(int)$this->r->uno('SELECT id FROM erp_compra_lineas WHERE compra_id=?',[$compra])['id'];
        $c->recibir($this->owner,$this->d(['compra_id'=>$compra,'linea_id'=>$linea,'cantidad'=>'10','referencia'=>'Guía de ensayo']));
        $c->facturar($this->owner,$this->d(['compra_id'=>$compra,'monto'=>'57.50','referencia'=>'FAC-001','vence_en'=>'2026-12-31']));return $compra;
    }

    public function testCompraSeSeparaEnRecepcionFacturaYPagoYElSaldoConcilia():void
    {
        $compra=$this->abastecer();$r=$this->contabilizar();self::assertSame(['confirmados'=>2,'fallidos'=>0,'prestaciones'=>0],$r);
        self::assertSame(5000,$this->saldo('inventario'),'Recibido al costo, sin IVA');self::assertSame(750,$this->saldo('iva_compras'),'IVA pagado estimado con la proporción de la orden');self::assertSame(5750,$this->saldo('proveedores'));
        self::assertSame(0,$this->saldo('recepciones_por_facturar'),'Recepción y factura cuadran por completo');
        $ob=(int)$this->r->uno('SELECT id FROM erp_obligaciones WHERE compra_id=?',[$compra])['id'];
        (new ErpCompraService($this->r))->pagar($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>'20.00','metodo'=>'transferencia','referencia'=>'TRF-1']));$this->contabilizar();
        self::assertSame(3750,$this->saldo('proveedores'));self::assertSame(-2000,$this->saldo('banco'),'La transferencia sale del banco, no de la caja física');
        $o=$this->r->uno('SELECT monto_centavos-pagado_centavos-ajuste_centavos s FROM erp_obligaciones WHERE id=?',[$ob]);
        self::assertSame((int)$o['s'],$this->saldo('proveedores'),'La cuenta de proveedores concilia con las obligaciones');
        $aux=$this->cont()->auxiliar('proveedores','2099-12-31');self::assertSame(3750,$aux['total']);self::assertSame('Proveedor contable',$aux['filas'][0]['nombre']);
        // Devolver al proveedor lo recibido reduce inventario contra lo recibido por facturar; el crédito del proveedor lo compensa.
        $c=new ErpCompraService($this->r);$linea=(int)$this->r->uno('SELECT id FROM erp_compra_lineas WHERE compra_id=?',[$compra])['id'];
        $c->devolver($this->owner,$this->d(['compra_id'=>$compra,'linea_id'=>$linea,'cantidad'=>'2','motivo'=>'Producto defectuoso']));$this->contabilizar();
        self::assertSame(4000,$this->saldo('inventario'));self::assertSame(-1000,$this->saldo('recepciones_por_facturar'));
        self::assertSame([],$this->cont()->integridad());self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame(0,$this->pendientesDeContabilizar());
    }
    public function testVentaDeProductoSeparaCobroIngresoYCostoYLaCuentaPorCobrarQuedaEnCero():void
    {
        $this->abastecer();$venta=$this->ventaDeProductos('3',true,false);$this->contabilizar();
        self::assertSame(-3450,$this->saldo('cxc',$this->contacto),'Cobrado y aún no entregado: saldo a favor del cliente, sin ingreso');self::assertSame(0,$this->saldo('ingreso_productos'));self::assertSame(3450,$this->saldo('caja'));
        (new ErpEntregaService($this->r))->entregar($this->owner,$this->d(['venta_id'=>$venta]));$this->contabilizar();
        self::assertSame(0,$this->saldo('cxc',$this->contacto));
        self::assertSame(3000,$this->saldo('ingreso_productos'));self::assertSame(450,$this->saldo('iva_ventas'));self::assertSame(1500,$this->saldo('costo_ventas'),'Costo de 3 unidades a 5,00');self::assertSame(3500,$this->saldo('inventario'));
        $res=(new ErpVentaService($this->r))->resumen($venta);self::assertSame(0,$res['saldo']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE origen LIKE 'outbox:%' AND concepto LIKE 'Costo de los productos%'")['n'],'El costo es un asiento propio, distinto del ingreso');
        // Reprocesar los mismos eventos no duplica nada.
        $n=(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'];$this->r->q("UPDATE erp_integraciones_outbox SET estado='pendiente',proximo_intento=NULL,lease_token=NULL WHERE destino='contabilidad'");$this->contabilizar();
        self::assertSame($n,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);
    }
    public function testDevolucionReversaIngresoYCostoYElReembolsoEsUnHechoAparte():void
    {
        $this->abastecer();$venta=$this->ventaDeProductos('3');$this->contabilizar();$linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$venta])['id'];
        (new ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$venta,'linea_id'=>$linea,'cantidad'=>'1','motivo'=>'Producto devuelto','reintegrar_stock'=>'1']));$this->contabilizar();
        self::assertSame(2000,$this->saldo('ingreso_productos')+$this->saldo('devoluciones_ventas'),'Ingreso neto de 2 unidades');self::assertSame(300,$this->saldo('iva_ventas'));self::assertSame(1000,$this->saldo('costo_ventas'));self::assertSame(4000,$this->saldo('inventario'));
        self::assertSame(0,$this->saldo('cxc',$this->contacto));self::assertSame(2300,$this->saldo('caja'),'Se devolvieron 11,50 en efectivo');
        $n=$this->r->q("SELECT concepto FROM erp_cont_asientos WHERE concepto LIKE '%evolución%' OR concepto LIKE 'Reintegro%' ORDER BY id");
        self::assertCount(3,$n,'Reverso de ingreso, reintegro de existencias y devolución de dinero: tres hechos');
        self::assertSame([],$this->cont()->integridad());
    }
    public function testDevolverLoNoEntregadoSoloDevuelveElAnticipoSinTocarElIngreso():void
    {
        $this->abastecer();$venta=$this->ventaDeProductos('2',true,false);$this->contabilizar();$linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$venta])['id'];
        (new ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$venta,'linea_id'=>$linea,'cantidad'=>'2','motivo'=>'No lo quiere']));$this->contabilizar();
        self::assertSame(0,$this->saldo('ingreso_productos'));self::assertSame(0,$this->saldo('devoluciones_ventas'));self::assertSame(0,$this->saldo('iva_ventas'));self::assertSame(0,$this->saldo('cxc',$this->contacto));self::assertSame(0,$this->saldo('caja'));
    }
    public function testServicioPrestadoReconoceElIngresoUnaSolaVezAunqueElSupervisorRepita():void
    {
        $c=R5CajaFixture::cita($this->db,$this->usuarioCliente,null,['30.00']);$v=new ErpVentaService($this->r);$venta=$v->crear($this->owner,$this->d(['cita_id'=>$c]))['recurso_id'];
        $v->confirmar($this->owner,$this->d(['venta_id'=>$venta,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$venta])['version']]));
        (new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$venta,'monto'=>'30.00','recibido'=>'30.00','metodo'=>'efectivo']));
        $r=$this->contabilizar();self::assertSame(0,$r['prestaciones'],'Sin atención no hay ingreso');self::assertSame(0,$this->saldo('ingreso_servicios'));
        $this->r->q("UPDATE citas SET estado='completada',atencion_fin_at=? WHERE id=?",[$this->r::ahora(),$c]);
        $r=$this->contabilizar();self::assertSame(1,$r['prestaciones']);self::assertSame(3000,$this->saldo('ingreso_servicios'));self::assertSame(0,$this->saldo('cxc'));
        self::assertSame(0,$this->contabilizar()['prestaciones'],'Segundo pase: nada nuevo');self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE origen=?",['prestacion:v'.$venta])['n']);
        // Devolver el servicio ya prestado reversa el ingreso; el dinero devuelto es otro hecho.
        $linea=(int)$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$venta])['id'];
        (new ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$venta,'linea_id'=>$linea,'cantidad'=>'1','motivo'=>'Servicio no conforme']));$this->contabilizar();
        self::assertSame(0,$this->saldo('ingreso_servicios')+$this->saldo('devoluciones_ventas'));self::assertSame(0,$this->saldo('cxc'));self::assertSame(0,$this->saldo('caja'));
    }
    public function testPaqueteConAnticipoSeContabilizaComoAnticipoAplicacionYPrestacion():void
    {
        $this->r->q("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio del paquete',30,30)");$servicio=$this->r->id();
        $paquetes=new ErpPaqueteService($this->r);$plan=$paquetes->plan($this->owner,$this->d(['nombre'=>'Dos sesiones','servicio_id'=>$servicio,'sesiones'=>2,'precio'=>'20','impuesto'=>'0']))['recurso_id'];
        $paquete=$paquetes->crear($this->owner,$this->d(['plan_id'=>$plan,'contacto_id'=>$this->contacto]))['recurso_id'];$prepago=(int)$this->r->uno('SELECT prepago_id FROM erp_paquetes WHERE id=?',[$paquete])['prepago_id'];
        (new ErpPrepagoService($this->r))->cargar($this->owner,$this->d(['prepago_id'=>$prepago,'metodo'=>'efectivo','monto'=>'40','recibido'=>'40']));$this->contabilizar();
        self::assertSame(4000,$this->saldo('anticipos_clientes',$this->contacto));self::assertSame(4000,$this->saldo('caja'));self::assertSame(0,$this->saldo('ingreso_servicios'),'Recibir dinero no es vender');
        $c=R5CajaFixture::cita($this->db,$this->usuarioCliente,null,['30.00']);$this->r->q('UPDATE citasservicios SET servicioId=? WHERE citaId=?',[$servicio,$c]);$venta=(new ErpVentaService($this->r))->crear($this->owner,$this->d(['cita_id'=>$c]))['recurso_id'];
        $paquetes->aplicar($this->owner,$this->d(['paquete_id'=>$paquete,'venta_id'=>$venta,'linea_id'=>$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$venta])['id'],'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$venta])['version']]));
        $this->contabilizar();self::assertSame(2000,$this->saldo('anticipos_clientes',$this->contacto),'La sesión aplicó 20,00 del anticipo');self::assertSame(-2000,$this->saldo('cxc',$this->contacto),'Aplicado antes de prestar: saldo a favor');
        $this->r->q("UPDATE citas SET estado='completada',atencion_fin_at=? WHERE id=?",[$this->r::ahora(),$c]);(new ErpComisionService($this->r))->recalcular($this->owner,$this->d(['venta_id'=>$venta]));$this->contabilizar();
        self::assertSame(0,$this->saldo('cxc',$this->contacto));self::assertSame(2000,$this->saldo('ingreso_servicios'),'El ingreso es el precio pactado de la sesión');
        // Reintegrar el saldo sin utilizar sale de caja y cancela el anticipo.
        $carga=(int)$this->r->uno('SELECT id FROM erp_prepago_cargas WHERE prepago_id=?',[$prepago])['id'];
        (new ErpPrepagoService($this->r))->reembolsar($this->owner,$this->d(['prepago_id'=>$prepago,'carga_id'=>$carga,'monto'=>'20','motivo'=>'Saldo sin utilizar']));$this->contabilizar();
        self::assertSame(0,$this->saldo('anticipos_clientes',$this->contacto));self::assertSame(2000,$this->saldo('caja'));
        $prep=(new ErpPrepagoService($this->r))->saldo($prepago);self::assertSame($prep,$this->saldo('anticipos_clientes',$this->contacto),'El anticipo concilia con el saldo del prepago');
        self::assertSame([],$this->cont()->integridad());
    }
    public function testSinReglaOSinInicioElEventoNoSePierdeNiSeInventa():void
    {
        $this->r->q("INSERT INTO erp_eventos(tipo,entidad,entidad_id,actor_id,datos_json,creado_en) VALUES('evento_raro','venta',1,1,'{}',NOW())");$ev=$this->r->id();
        $this->r->q("INSERT INTO erp_integraciones_outbox(destino,evento_id,referencia,payload_json,estado,actualizado_en) VALUES('contabilidad',?,?,?, 'pendiente',NOW())",[$ev,'ERP-'.$ev.'-contabilidad',json_encode(['esquema'=>1,'evento_id'=>$ev,'tipo'=>'evento_raro','entidad'=>'venta','entidad_id'=>1,'creado_en'=>date('Y-m-d H:i:s'),'datos'=>[]])]);
        $r=$this->contabilizar(true);self::assertSame(1,$r['fallidos']);self::assertSame('fallido',$this->r->uno('SELECT estado FROM erp_integraciones_outbox WHERE evento_id=?',[$ev])['estado']);self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);
        // Un hecho anterior al inicio contable queda confirmado sin asiento y consta como tal.
        $this->r->q("INSERT INTO erp_eventos(tipo,entidad,entidad_id,actor_id,datos_json,creado_en) VALUES('pago_confirmado','venta',1,1,'{}','2025-06-01 10:00:00')");$ev2=$this->r->id();
        $this->r->q("INSERT INTO erp_integraciones_outbox(destino,evento_id,referencia,payload_json,estado,actualizado_en) VALUES('contabilidad',?,?,?, 'pendiente',NOW())",[$ev2,'ERP-'.$ev2.'-contabilidad',json_encode(['esquema'=>1,'evento_id'=>$ev2,'tipo'=>'pago_confirmado','entidad'=>'venta','entidad_id'=>1,'creado_en'=>'2025-06-01 10:00:00','datos'=>['pago_id'=>1,'importe'=>100]])]);
        $this->contabilizar(true);$f=$this->r->uno('SELECT estado,respuesta_json FROM erp_integraciones_outbox WHERE evento_id=?',[$ev2]);self::assertSame('confirmado',$f['estado']);self::assertStringContainsString('previo-a-inicio-contable',$f['respuesta_json']);
    }
    public function testUnPeriodoCerradoNoRecibeContabilizacionesDirectasYElHechoTardioEsperaALaReapertura():void
    {
        $this->abastecer();$this->contabilizar();
        $mes=(int)date('n');for($m=1;$m<=$mes;$m++)$this->cont()->cerrarPeriodo($this->owner,$this->d(['anio'=>(int)date('Y'),'mes'=>$m]));
        $venta=$this->ventaDeProductos('1');$r=$this->contabilizar(true);self::assertGreaterThan(0,$r['fallidos'],'El período de hoy está cerrado: el hecho espera, no se pierde');self::assertGreaterThan(0,$this->pendientesDeContabilizar());
        $this->cont()->reabrirPeriodo($this->owner,$this->d(['anio'=>(int)date('Y'),'mes'=>$mes,'motivo'=>'Contabilizar un hecho pendiente']));$this->r->q("UPDATE erp_integraciones_outbox SET proximo_intento=NULL WHERE destino='contabilidad'");$this->contabilizar();$diag=[];foreach($this->r->q("SELECT * FROM erp_integraciones_outbox WHERE destino='contabilidad' AND estado<>'confirmado'") as $f){try{(new ErpContabilidadAdaptador($this->r))->enviar($f['referencia'],json_decode($f['payload_json'],true));$diag[]='ok?';}catch(\Throwable $e){$diag[]=$f['referencia'].': '.$e->getMessage();}}
        self::assertSame(0,$this->pendientesDeContabilizar(),json_encode($diag,JSON_UNESCAPED_UNICODE));self::assertSame([],$this->cont()->integridad());
        self::assertSame(0,$this->saldo('cxc',$this->contacto));
        unset($venta);
    }
    public function testReglasDeComisionActivoEInsumosGeneranAsientosBalanceadosYDistintos():void
    {
        $a=new ErpContabilidadAdaptador($this->r);$ahora=$this->r::ahora();
        $this->r->q("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES(?,?,'salida',2000,0,250,'Consumo de ensayo',?,?,?)",[$this->producto,$this->owner,'erp.consumo:9:'.$this->producto,str_repeat('d',64),$ahora]);
        $casos=[
            ['comision_liquidada',7,['monto'=>12345],'gasto_comisiones',12345],
            ['activo_registrado',3,['codigo'=>'EQ-1','costo'=>50000],'activos_fijos',50000],
            ['insumos_consumidos',9,['cita_id'=>1,'receta'=>[['producto_id'=>$this->producto,'cantidad'=>2000]]],'consumo_insumos',500],
        ];
        foreach($casos as $i=>[$tipo,$entidad,$datos,$rol,$esperado]){
            $ref='ERP-'.(900+$i).'-contabilidad';$r=$a->enviar($ref,['esquema'=>1,'evento_id'=>900+$i,'tipo'=>$tipo,'entidad_id'=>$entidad,'creado_en'=>$ahora,'datos'=>$datos]);
            self::assertSame('confirmado',$r['estado']);self::assertSame($esperado,$this->saldo($rol),$tipo);
            self::assertSame($r['referencia_externa'],$a->consultar($ref)['referencia_externa'],'La consulta recupera el asiento sin repetirlo');
        }
        self::assertSame(12345,$this->saldo('comisiones_por_pagar'));self::assertSame(50000,$this->saldo('recepciones_por_facturar'));self::assertSame(-500,$this->saldo('inventario'));
        self::assertSame([],$this->cont()->integridad());self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);
    }
}
