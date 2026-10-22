<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpCajaService,ErpDevolucionService,ErpInformeService,ErpMaestrosService,ErpPagoService,ErpPrepagoService,ErpVentaService};

/**
 * Modelo de lectura BI, saldo por cobrar (060): el saldo de cada venta y el total cuadran con ErpVentaService::resumen y con el informe CxC
 * existentes, el saldo a un corte sale de los hechos con su fecha, y los pagos simulados o sin verificar nunca reducen la deuda
 * (docs/BI_SALDO_POR_COBRAR.md).
 */
final class ErpBiSaldoPorCobrarIntegrationTest extends TestCase
{
    private const VISTAS=['bi_cxc_movimientos','bi_saldo_por_cobrar_venta'];
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $contacto;private int $catalogo;private int $prepago;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('bicxc');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$cli=R5CajaFixture::usuario($this->db);
        $this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$cli]);
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->owner,$this->d(['nombre'=>'Clienta Privada Saldo','tipo'=>'cliente']))['recurso_id'];
        $m->vincularCliente($this->owner,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$cli]));
        $this->prepago=(new ErpPrepagoService($this->r))->crear($this->owner,$this->d(['contacto_id'=>$this->contacto,'tipo'=>'regalo','nombre'=>'Regalo de ensayo']))['recurso_id'];
        (new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']));
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('CX','Producto de ensayo','unidad',100000)");$producto=$this->r->id();
        $this->catalogo=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$producto,'precio'=>'20','impuesto'=>'0','tipo'=>'venta']))['recurso_id'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function n(string $sql):int {return (int)($this->r->uno($sql)['n']??0);}
    private function venta(string $cantidad='1',bool $confirmar=true):int
    {
        $s=new ErpVentaService($this->r);$v=$s->crear($this->owner,$this->d(['contacto_id'=>$this->contacto]))['recurso_id'];
        $s->agregar($this->owner,$this->d(['venta_id'=>$v,'catalogo_id'=>$this->catalogo,'cantidad'=>$cantidad]));
        if($confirmar)$s->confirmar($this->owner,$this->d(['venta_id'=>$v,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]));return $v;
    }
    private function efectivo(int $v,string $monto):void {(new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$v,'monto'=>$monto,'metodo'=>'efectivo','recibido'=>$monto]));}
    private function transferencia(int $v,string $monto,string $ref):int
    {
        (new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$v,'monto'=>$monto,'metodo'=>'transferencia','referencia'=>$ref]));
        return (int)$this->r->uno("SELECT p.id FROM pagos p JOIN erp_pago_aplicaciones a ON a.pago_id=p.id WHERE a.venta_id=? AND p.metodo_pago='transferencia' ORDER BY p.id DESC LIMIT 1",[$v])['id'];
    }
    private function devolver(int $v,string $cantidad='1'):void
    {
        $l=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v]);
        (new ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$v,'linea_id'=>$l['id'],'cantidad'=>$cantidad,'motivo'=>'Devolución de ensayo','reintegrar_stock'=>'0']));
    }
    private function inserta(string $sql):int {$this->db->query($sql);return (int)$this->db->insert_id;}
    private function saldo(int $v):int {return (int)(new ErpVentaService($this->r))->resumen($v)['saldo'];}
    /** Saldo de una venta a un corte, calculado con piso en 0 como pide el glosario. */
    private function alCorte(string $corte,?int $venta=null):int
    {
        $donde=$venta===null?'':" AND venta_id=$venta";
        return $this->n("SELECT COALESCE(SUM(GREATEST(0,s)),0) n FROM (SELECT venta_id,SUM(importe_con_signo_centavos) s FROM bi_cxc_movimientos WHERE fecha<='$corte'$donde GROUP BY venta_id) x");
    }

    /** V1 pagada y con una unidad devuelta; V2 parcial; V3 sin cobro; V4 con transferencia sin verificar; V5 con prepago y su devolución. */
    private function escenario():array
    {
        $pre=new ErpPrepagoService($this->r);
        $v1=$this->venta('2');$this->efectivo($v1,'40');$this->devolver($v1,'1');
        $v2=$this->venta('2');$this->efectivo($v2,'15');
        $v3=$this->venta('1');
        $v4=$this->venta('1');$this->transferencia($v4,'20','TRF-PEND-CX1');
        $pre->cargar($this->owner,$this->d(['prepago_id'=>$this->prepago,'metodo'=>'efectivo','monto'=>'50','recibido'=>'50']));
        $v5=$this->venta('1');$pre->aplicar($this->owner,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v5,'monto'=>'12']));
        $borrador=$this->venta('1',false);
        return compact('v1','v2','v3','v4','v5','borrador');
    }

    public function testElSaldoDeCadaVentaYElTotalCuadranConElResumenYElInformeCxcExistentes():void
    {
        $e=$this->escenario();
        foreach(['v1','v2','v3','v4','v5'] as $k){
            self::assertSame($this->saldo($e[$k]),$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e[$k]}"),"$k: saldo = ErpVentaService::resumen");
        }
        // Valores esperados calculados a mano: V1 40 - devolución 20 - cobrado 40 + devuelto 0 → saldo 0 (a favor del cliente 20); V2 40 - 15 = 25; V3 20; V4 20 (la transferencia no cuenta); V5 20 - 12 = 8.
        self::assertSame([0,2500,2000,2000,800],array_map(fn($k)=>$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e[$k]}"),['v1','v2','v3','v4','v5']));
        self::assertSame(0,$this->n("SELECT saldo_a_favor_cliente_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e['v1']}"),'la devolución de V1 también devolvió el dinero: ni deuda ni saldo a favor');
        // Cobro de más (devolución comercial sin devolver el dinero): saldo a favor del cliente, no una deuda negativa.
        $v6=$this->venta('1');$this->efectivo($v6,'20');$linea=(int)$this->r->uno('SELECT id n FROM erp_venta_lineas WHERE venta_id=?',[$v6])['n'];
        $this->inserta("INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,reintegrar_stock,motivo,actor_id,creado_en) VALUES($v6,$linea,1000,2000,0,'Ensayo',{$this->owner},NOW())");
        self::assertSame([0,2000,0],[$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v6"),$this->n("SELECT saldo_a_favor_cliente_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v6"),$this->saldo($v6)]);
        self::assertSame(0,$this->alCorte('2099-12-31',$v6),'el piso en 0 evita deudas negativas');
        self::assertSame(2000,$this->n("SELECT por_verificar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e['v4']}"),'transferencia declarada: visible, pero no es cobro');
        $total=$this->n("SELECT SUM(saldo_por_cobrar_centavos) n FROM bi_saldo_por_cobrar_venta");
        self::assertSame((int)(new ErpInformeService($this->r))->obtener($this->owner,[])['cxc']['saldo'],$total,'total = informe CxC existente');
        self::assertSame($total,$this->alCorte('2099-12-31'),'el saldo reconstruido desde los movimientos coincide con el actual');
        // Solo ventas confirmadas: el borrador no aparece ni como cargo.
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e['borrador']}"));
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE venta_id={$e['borrador']}"));
        // Una venta anulada sale de la cartera.
        $this->db->query("UPDATE erp_ventas SET estado='anulada' WHERE id={$e['v3']}");
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e['v3']}"));
        self::assertSame((int)(new ErpInformeService($this->r))->obtener($this->owner,[])['cxc']['saldo'],$this->n("SELECT SUM(saldo_por_cobrar_centavos) n FROM bi_saldo_por_cobrar_venta"));
    }

    public function testUnaVentaAnuladaConservaSuDeudaHastaLaFechaDeSuAnulacion():void
    {
        $v=$this->venta('1');$borrador=$this->venta('1',false);
        $this->db->query("UPDATE erp_ventas SET confirmado_en='2026-09-01 10:00:00' WHERE id=$v");
        (new ErpVentaService($this->r))->anular($this->owner,$this->d(['venta_id'=>$v,'motivo'=>'Error de captura']));
        (new ErpVentaService($this->r))->anular($this->owner,$this->d(['venta_id'=>$borrador,'motivo'=>'Nunca se confirmó']));
        // El evento es de solo anexado (no se puede fechar a mano): se usa su fecha real y se comparan el día anterior y el mismo día.
        $dia=$this->db->query("SELECT DATE(creado_en) d,DATE_SUB(DATE(creado_en),INTERVAL 1 DAY) antes FROM erp_eventos WHERE tipo='venta_anulada' AND entidad_id=$v")->fetch_assoc();
        self::assertSame(0,$this->alCorte('2026-08-31',$v));
        self::assertSame(2000,$this->alCorte('2026-09-01',$v),'confirmada y aún no anulada: se debía');
        self::assertSame(2000,$this->alCorte($dia['antes'],$v),'el día antes de anularla todavía se debía');
        self::assertSame(0,$this->alCorte($dia['d'],$v),'desde el día de la anulación ya no se debe');
        self::assertSame(0,$this->alCorte('2099-12-31',$v));
        self::assertSame(1,$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE venta_id=$v AND tipo_movimiento='anulacion'"));
        // La cartera actual no la incluye, igual que el informe CxC; una venta anulada sin haberse confirmado nunca fue deuda.
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v"));
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE venta_id=$borrador"));
        self::assertSame((int)(new ErpInformeService($this->r))->obtener($this->owner,[])['cxc']['saldo'],$this->n("SELECT COALESCE(SUM(saldo_por_cobrar_centavos),0) n FROM bi_saldo_por_cobrar_venta"));
    }

    public function testElSaldoAUnCorteSaleDeLosHechosConSuFecha():void
    {
        $v=$this->venta('2');$this->efectivo($v,'15');$this->efectivo($v,'10');$this->devolver($v,'1');
        // Fechas fijas: venta el 1 de septiembre, cobros el 10 y el 20, devolución comercial el 25.
        $this->db->query("UPDATE erp_ventas SET confirmado_en='2026-09-01 10:00:00' WHERE id=$v");
        $pagos=array_column($this->r->q('SELECT pago_id FROM erp_pago_aplicaciones WHERE venta_id=? ORDER BY pago_id',[$v]),'pago_id');
        $this->db->query("UPDATE pagos SET pagado_at='2026-09-10 09:00:00' WHERE id={$pagos[0]}");$this->db->query("UPDATE pagos SET pagado_at='2026-09-20 09:00:00' WHERE id={$pagos[1]}");
        $this->db->query("UPDATE erp_devoluciones SET creado_en='2026-09-25 09:00:00' WHERE venta_id=$v");
        self::assertSame(0,$this->alCorte('2026-08-31',$v),'antes de la venta no hay deuda');
        self::assertSame(4000,$this->alCorte('2026-09-01',$v),'el día de la venta se debe todo');
        self::assertSame(2500,$this->alCorte('2026-09-10',$v),'tras el primer cobro de 15,00');
        self::assertSame(1500,$this->alCorte('2026-09-24',$v),'tras el segundo cobro de 10,00');
        self::assertSame(0,$this->alCorte('2026-09-25',$v),'la devolución comercial de 20,00 cancela el resto');
        self::assertSame($this->saldo($v),$this->alCorte('2099-12-31',$v));
        // Los cobros se miden por su fecha propia, no por la de la venta: un cobro posterior no altera el saldo anterior.
        self::assertSame(4000,$this->alCorte('2026-09-09',$v));
    }

    public function testLosPagosSimuladosYLasTransferenciasSinVerificarNoReducenLaDeudaYLasVerificadasSi():void
    {
        $v=$this->venta('1');
        $mock=$this->inserta("INSERT INTO pagos(cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at) VALUES(0,'MOCK-CX-1','mock',20.00,'USD','completado','tarjeta','mock-cx-1',NOW())");
        $this->inserta("INSERT INTO erp_pago_aplicaciones(pago_id,venta_id,importe_centavos,origen) VALUES($mock,$v,2000,'pdv')");
        $pend=$this->inserta("INSERT INTO pagos(cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,estado_verificacion) VALUES(0,'PEND-CX-1','manual',20.00,'USD','completado','transferencia','pend-cx-1','pendiente_verificacion')");
        $this->inserta("INSERT INTO erp_pago_aplicaciones(pago_id,venta_id,importe_centavos,origen) VALUES($pend,$v,2000,'pdv')");
        self::assertSame(2000,$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v"),'ni el simulado ni el sin verificar son cobro');
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE tipo_movimiento='cobro' AND venta_id=$v"));
        // Una transferencia declarada por el flujo real: pendiente hasta verificarla; al confirmarla pasa a cobro en la fecha de la verificación.
        $v2=$this->venta('1');$p=$this->transferencia($v2,'20','TRF-CONF-CX2');
        self::assertSame(2000,$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v2"));self::assertSame(2000,$this->n("SELECT por_verificar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v2"));
        (new ErpPagoService($this->r))->verificar($this->owner,$this->d(['venta_id'=>$v2,'pago_id'=>$p,'decision'=>'confirmar','nota'=>'Acreditada']));
        self::assertSame(0,$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v2"));self::assertSame(0,$this->n("SELECT por_verificar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v2"));
        self::assertSame($this->saldo($v2),0);
        // Rechazada: vuelve a deber y deja de estar por verificar.
        $v3=$this->venta('1');$p3=$this->transferencia($v3,'20','TRF-RECH-CX3');
        (new ErpPagoService($this->r))->verificar($this->owner,$this->d(['venta_id'=>$v3,'pago_id'=>$p3,'decision'=>'rechazar','nota'=>'No llegó']));
        self::assertSame([2000,0],[$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v3"),$this->n("SELECT por_verificar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v3")]);
    }

    public function testElPrepagoReduceLaDeudaSinSerDineroNuevoYSuRestitucionLaRestablece():void
    {
        $e=$this->escenario();
        $f=$this->r->uno("SELECT tipo_movimiento,metodo,es_aplicacion_de_saldo,importe_centavos FROM bi_cxc_movimientos WHERE venta_id={$e['v5']} AND tipo_movimiento='cobro'");
        self::assertSame(['cobro','prepago',1,1200],[$f['tipo_movimiento'],$f['metodo'],(int)$f['es_aplicacion_de_saldo'],(int)$f['importe_centavos']],'la aplicación reduce lo que se debe y se distingue como saldo aplicado');
        self::assertSame(8,(int)$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e['v5']}")/100);
        // Devolver la unidad de V5: lo aplicado vuelve al saldo de prepago (devolución de cobro con método prepago) y la deuda queda en cero.
        $this->devolver($e['v5'],'1');
        $dev=$this->r->uno("SELECT importe_centavos,metodo,es_aplicacion_de_saldo FROM bi_cxc_movimientos WHERE venta_id={$e['v5']} AND tipo_movimiento='devolucion_de_cobro'");
        self::assertSame([1200,'prepago',1],[(int)$dev['importe_centavos'],$dev['metodo'],(int)$dev['es_aplicacion_de_saldo']]);
        self::assertSame($this->saldo($e['v5']),$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e['v5']}"));
        self::assertSame(0,$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id={$e['v5']}"));
    }

    public function testUnPagoReembolsadoSinDevolucionesRegistradasSeReconocePorSuFechaDeReembolso():void
    {
        // Cobros históricos reembolsados por completo pueden no tener filas en cobros_devoluciones; el resumen los trata como devueltos.
        $v=$this->venta('1');$this->efectivo($v,'20');
        $pago=(int)$this->r->uno('SELECT pago_id n FROM erp_pago_aplicaciones WHERE venta_id=?',[$v])['n'];
        $this->db->query("UPDATE erp_ventas SET confirmado_en='2026-09-01 10:00:00' WHERE id=$v");$this->db->query("UPDATE pagos SET pagado_at='2026-09-02 09:00:00',estado='reembolsado',reembolsado_at='2026-09-05 09:00:00' WHERE id=$pago");
        self::assertSame($this->saldo($v),$this->n("SELECT saldo_por_cobrar_centavos n FROM bi_saldo_por_cobrar_venta WHERE venta_id=$v"));
        self::assertSame(2000,$this->saldo($v));
        self::assertSame(0,$this->alCorte('2026-09-04',$v),'cobrado y aún no reembolsado');self::assertSame(2000,$this->alCorte('2026-09-05',$v),'el reembolso restablece la deuda en su fecha');
        // Con devoluciones parciales registradas, solo el resto se reconoce como reembolso total (sin contar dos veces).
        $this->inserta("INSERT INTO cobros_devoluciones(pago_id,monto_centavos,metodo,motivo,origen,registrado_en) VALUES($pago,500,'efectivo','Parcial','caja','2026-09-03 09:00:00')");
        self::assertSame(2000,$this->n("SELECT SUM(importe_con_signo_centavos) n FROM bi_cxc_movimientos WHERE venta_id=$v AND tipo_movimiento='devolucion_de_cobro'"),'500 de la devolución + 1500 del remanente = 2000');
        self::assertSame($this->saldo($v),$this->alCorte('2099-12-31',$v));
    }

    public function testElReembolsoHistoricoSinFechaNoSeMueveConActualizacionesPosterioresDelPago():void
    {
        $v=$this->venta('1');$this->efectivo($v,'20');
        $pago=(int)$this->r->uno('SELECT pago_id n FROM erp_pago_aplicaciones WHERE venta_id=?',[$v])['n'];
        $this->db->query("UPDATE erp_ventas SET confirmado_en='2026-09-01 10:00:00' WHERE id=$v");
        $this->db->query("UPDATE pagos SET pagado_at='2026-09-02 09:00:00',created_at='2026-09-02 09:00:00',estado='reembolsado',reembolsado_at=NULL WHERE id=$pago");
        // Una actualización posterior cualquiera del pago (conciliación, payload) mueve updated_at a hoy, pero no debe mover el reembolso.
        $this->db->query("UPDATE pagos SET payload_proveedor='{}' WHERE id=$pago");
        self::assertNotSame('2026-09-02',(string)$this->r->uno('SELECT DATE(updated_at) d FROM pagos WHERE id=?',[$pago])['d'],'el ensayo de verdad movió updated_at');
        self::assertSame('2026-09-02',(string)$this->r->uno("SELECT fecha FROM bi_cxc_movimientos WHERE origen_hecho='reembolso_total' AND hecho_id=$pago")['fecha'],'sin fecha de reembolso se usa la del cobro, que no cambia');
        self::assertSame(2000,$this->alCorte('2026-09-02',$v),'con el cobro reembolsado el mismo día la deuda vuelve a existir en esa fecha, no más tarde');
    }

    public function testElGranoSeConservaSinDuplicarHechosAlUnirTablas():void
    {
        $e=$this->escenario();
        // Pagos con el mismo importe en la misma venta no se colapsan.
        $v=$this->venta('2');$this->efectivo($v,'10');$this->efectivo($v,'10');
        self::assertSame(2,$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE venta_id=$v AND tipo_movimiento='cobro' AND importe_centavos=1000"));
        self::assertSame($this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos"),$this->n("SELECT COUNT(DISTINCT origen_hecho,hecho_id) n FROM bi_cxc_movimientos"),'una fila por hecho');
        self::assertSame($this->n("SELECT COUNT(*) n FROM erp_ventas WHERE estado='confirmada'"),$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE tipo_movimiento='venta'"));
        self::assertSame($this->n("SELECT COUNT(*) n FROM erp_ventas WHERE estado='confirmada'"),$this->n("SELECT COUNT(*) n FROM bi_saldo_por_cobrar_venta"));
        self::assertSame($this->n("SELECT COUNT(*) n FROM erp_devoluciones"),$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE tipo_movimiento='devolucion_comercial'"));
        self::assertSame($this->n("SELECT COUNT(*) n FROM erp_pago_aplicaciones a JOIN pagos p ON p.id=a.pago_id WHERE p.estado IN('completado','reembolsado')"),$this->n("SELECT COUNT(*) n FROM bi_cxc_movimientos WHERE tipo_movimiento='cobro'"));
        // Total vendido y cobrado de la vista = origen.
        self::assertSame($this->n("SELECT SUM(total_centavos) n FROM erp_ventas WHERE estado='confirmada'"),$this->n("SELECT SUM(total_original_centavos) n FROM bi_saldo_por_cobrar_venta"));
        self::assertSame($e['v1']>0,true);
    }

    public function testLasVistasSonDeSoloLecturaSinDatosPersonalesYElUsuarioDeBiSoloLasLee():void
    {
        $this->escenario();
        $prohibidas=['comprador','contacto','nombre','email','correo','telefono','identificacion','ruc','cedula','direccion','json','motivo','concepto','referencia','actor','nota'];
        foreach(self::VISTAS as $v){
            self::assertSame(1,$this->n("SELECT COUNT(*) n FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$v' AND security_type='DEFINER'"));
            $cols=array_column($this->db->query("SELECT column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$v'")->fetch_all(MYSQLI_ASSOC),'c');
            foreach($cols as $c)foreach($prohibidas as $p)self::assertStringNotContainsString($p,strtolower($c),"$v.$c no debe exponer datos personales");
            $texto=json_encode($this->db->query("SELECT * FROM `$v`")->fetch_all(MYSQLI_ASSOC));
            self::assertStringNotContainsString('Clienta Privada',$texto);self::assertStringNotContainsString('Devolución de ensayo',$texto);
        }
        $bd=BaseDatosAislada::nombre($this->db);$usuario='bi_x_'.bin2hex(random_bytes(4));$clave='Bi'.bin2hex(random_bytes(6));
        $this->db->query("CREATE USER '$usuario'@'%' IDENTIFIED BY '$clave'");
        try{
            foreach(self::VISTAS as $v)$this->db->query("GRANT SELECT ON `$bd`.`$v` TO '$usuario'@'%'");
            mysqli_report(MYSQLI_REPORT_OFF);
            $bi=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',$usuario,$clave,$bd,(int)(getenv('DB_PORT')?:3306));
            self::assertSame(0,$bi->connect_errno);
            foreach(self::VISTAS as $v)self::assertNotFalse($bi->query("SELECT COUNT(*) FROM `$v`"),"$v legible por el usuario de BI: ".$bi->error);
            foreach(['erp_ventas','pagos','erp_pago_aplicaciones','cobros_devoluciones','erp_devoluciones','erp_contactos'] as $t)self::assertFalse($bi->query("SELECT 1 FROM `$t` LIMIT 1"),"el usuario de BI no lee $t");
            foreach(["UPDATE bi_cxc_movimientos SET importe_centavos=0","DELETE FROM bi_saldo_por_cobrar_venta","UPDATE bi_saldo_por_cobrar_venta SET saldo_por_cobrar_centavos=0"] as $sql)self::assertFalse($bi->query($sql),$sql);
            $bi->close();
        }finally{mysqli_report(MYSQLI_REPORT_OFF);$this->db->query("DROP USER IF EXISTS '$usuario'@'%'");}
        $ventas=$this->n("SELECT COUNT(*) n FROM erp_ventas");mysqli_report(MYSQLI_REPORT_OFF);
        foreach(["UPDATE bi_cxc_movimientos SET importe_centavos=0","DELETE FROM bi_cxc_movimientos","UPDATE bi_saldo_por_cobrar_venta SET saldo_por_cobrar_centavos=0","DELETE FROM bi_saldo_por_cobrar_venta"] as $sql)self::assertFalse($this->db->query($sql),"vista no actualizable: $sql");
        mysqli_report(MYSQLI_REPORT_OFF);
        self::assertSame($ventas,$this->n("SELECT COUNT(*) n FROM erp_ventas"));
    }
}
