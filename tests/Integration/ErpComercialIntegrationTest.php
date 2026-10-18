<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Repositories\OperacionRepository;
use Services\{ErpVentaService,ErpMaestrosService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpDevolucionService,ErpException,InventarioService,CobroCitaService,CobroRechazado};
final class ErpComercialIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $recepcion;private int $producto;private int $catalogo;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('erp_comercial');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('ERP-1','Champú','unidad',5000,500)");$this->producto=(int)$this->db->insert_id;
        $c=(new ErpMaestrosService($this->r))->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'10.00','impuesto'=>'15.00','tipo'=>'venta']));$this->catalogo=$c['recurso_id'];
    }
    protected function tearDown():void{BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array{return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function venta(string $cantidad='2'):int
    {
        $svc=new ErpVentaService($this->r);$v=$svc->crear($this->owner,$this->d())['recurso_id'];
        $svc->agregar($this->owner,$this->d(['venta_id'=>$v,'catalogo_id'=>$this->catalogo,'cantidad'=>$cantidad]));return $v;
    }
    private function confirmar(int $id):void
    {
        $v=$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$id]);(new ErpVentaService($this->r))->confirmar($this->owner,$this->d(['venta_id'=>$id,'version'=>(int)$v['version']]));
    }
    public function testVentaEntregaDevolucionYCierreConciliadosSinDuplicados():void
    {
        $svc=new ErpVentaService($this->r);$id=$this->venta();self::assertSame(2300,$svc->resumen($id)['total']);$this->confirmar($id);
        $cajas=new ErpCajaService($this->r);$c=$cajas->abrir($this->owner,$this->d(['fondo'=>'10.00']));
        $pago=$this->d(['venta_id'=>$id,'metodo'=>'efectivo','monto'=>'23.00','recibido'=>'30.00']);$ps=new ErpPagoService($this->r);$ps->cobrar($this->owner,$pago);$ps->cobrar($this->owner,$pago);
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_pago_aplicaciones')['n']);self::assertSame(3300,$cajas->esperado($c['recurso_id']));
        $e=new ErpEntregaService($this->r);$e->entregar($this->owner,$this->d(['venta_id'=>$id]));$e->entregar($this->owner,$this->d(['venta_id'=>$id]));
        self::assertSame(3000,(int)$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=?',[$this->producto])['stock_milesimas']);
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM inventario_movimientos')['n']);
        $linea=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$id])['id'];
        $dev=$this->d(['venta_id'=>$id,'linea_id'=>$linea,'cantidad'=>'1','reintegrar_stock'=>'1','motivo'=>'Producto devuelto sin abrir']);
        $ds=new ErpDevolucionService($this->r);$ds->devolver($this->owner,$dev);$ds->devolver($this->owner,$dev);
        $s=$svc->resumen($id);self::assertSame([1150,1150,1150,0],[$s['total'],$s['neto'],$s['devuelto'],$s['saldo']]);
        self::assertSame(4000,(int)$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=?',[$this->producto])['stock_milesimas']);
        $cajas->cerrar($this->owner,$this->d(['contado'=>'21.50','motivo'=>'Arqueo conforme']));self::assertSame(0,(int)$this->r->uno('SELECT diferencia_centavos FROM erp_caja_sesiones')['diferencia_centavos']);
        self::assertGreaterThan(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_integraciones_outbox WHERE estado='sin_configurar'")['n']);
    }
    public function testReservaImpideSobreventaYSalidaManualYLiberacion():void
    {
        $a=$this->venta('3');$b=$this->venta('3');$this->confirmar($a);
        try{$this->confirmar($b);self::fail('No debe sobrevendérse');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        try{(new InventarioService($this->r))->mover($this->owner,['producto_id'=>$this->producto,'tipo'=>'salida','cantidad'=>'3','motivo'=>'Consumo','idempotencia'=>bin2hex(random_bytes(12))]);self::fail('Debe proteger reserva');}catch(\InvalidArgumentException $e){self::assertStringContainsString('reservados',$e->getMessage());}
        (new ErpVentaService($this->r))->anular($this->owner,$this->d(['venta_id'=>$a,'motivo'=>'Cliente cancela sin pagar']));$this->confirmar($b);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_stock_reservas WHERE estado='activa'")['n']);
    }
    public function testRecepcionNoPuedeAplicarDescuentoNiDevolver():void
    {
        $id=$this->venta();$this->expectException(ErpException::class);
        try{(new ErpVentaService($this->r))->agregar($this->recepcion,$this->d(['venta_id'=>$id,'catalogo_id'=>$this->catalogo,'cantidad'=>'1','descuento'=>'1.00','motivo'=>'Prueba']));}catch(ErpException $e){self::assertSame(403,$e->getCode());throw $e;}
    }
    public function testAnticipoImportadoYBloqueoDelCobroAnterior():void
    {
        $cli=R5CajaFixture::usuario($this->db);$cita=R5CajaFixture::cita($this->db,$cli,null,['20.00'],'completada');
        $legacy=new CobroCitaService(new OperacionRepository($this->db));$legacy->cobrar($this->owner,['cita_id'=>$cita,'metodo'=>'efectivo','monto'=>'10.00','idem_key'=>R5CajaFixture::clave()]);
        $svc=new ErpVentaService($this->r);$id=$svc->crear($this->owner,$this->d(['cita_id'=>$cita]))['recurso_id'];
        $s=$svc->resumen($id);self::assertSame([2000,1000,1000],[$s['total'],$s['neto'],$s['saldo']]);
        self::assertSame($id,$svc->crear($this->owner,$this->d(['cita_id'=>$cita]))['recurso_id']);
        try{$legacy->cobrar($this->owner,['cita_id'=>$cita,'metodo'=>'efectivo','monto'=>'10.00','idem_key'=>R5CajaFixture::clave()]);self::fail('No debe cobrar por dos vías');}catch(CobroRechazado $e){self::assertSame(409,$e->http());}
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM pagos')['n']);
    }
}
