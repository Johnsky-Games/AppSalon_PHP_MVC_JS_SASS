<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\ErpRepository;
use Services\{ErpMaestrosService,ErpVentaService,ErpPromocionService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpDevolucionService,ErpComisionService,ErpIntegracionService,ErpException};
final class ErpReglasIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $actor;private int $col;private int $catalogo;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('erp_reglas');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->actor=R5CajaFixture::usuario($this->db,'propietario');
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado) SELECT id,email,nombre,apellido,'propietario','activo' FROM usuarios WHERE id=?",[$this->actor]);$this->col=$this->r->id();
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('REG-1','Producto','unidad',20000,300)");$p=$this->r->id();
        $this->catalogo=(new ErpMaestrosService($this->r))->catalogo($this->actor,$this->d(['origen'=>'producto','origen_id'=>$p,'precio'=>'10','impuesto'=>'15','tipo'=>'venta']))['recurso_id'];
    }
    protected function tearDown():void{BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array{return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function venta(string $qty='1'):int
    {$s=new ErpVentaService($this->r);$id=$s->crear($this->actor,$this->d())['recurso_id'];$s->agregar($this->actor,$this->d(['venta_id'=>$id,'catalogo_id'=>$this->catalogo,'cantidad'=>$qty]));return $id;}
    private function confirmar(int $id):void{(new ErpVentaService($this->r))->confirmar($this->actor,$this->d(['venta_id'=>$id,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$id])['version']]));}
    public function testCuponConUltimoUsoYTotalConImpuesto():void
    {
        $promo=new ErpPromocionService($this->r);$hoy=substr($this->r::ahora(),0,10);
        $promo->crear($this->actor,$this->d(['codigo'=>'UNICO','nombre'=>'Último cupón','desde'=>$hoy,'hasta'=>$hoy,'porcentaje'=>'10','minimo'=>'5','max_usos'=>'1']));
        $a=$this->venta('2');$b=$this->venta();$promo->aplicar($this->actor,$this->d(['venta_id'=>$a,'codigo'=>'UNICO']));
        self::assertSame(2070,(new ErpVentaService($this->r))->resumen($a)['total']);
        try{$promo->aplicar($this->actor,$this->d(['venta_id'=>$b,'codigo'=>'UNICO']));self::fail('Debe agotar cupón');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        $promo->quitar($this->actor,$this->d(['venta_id'=>$a]));$promo->aplicar($this->actor,$this->d(['venta_id'=>$b,'codigo'=>'UNICO']));$this->confirmar($b);
        self::assertSame('usado',$this->r->uno('SELECT estado FROM erp_promocion_usos WHERE venta_id=?',[$b])['estado']);
    }
    public function testComisionSoloDespuesDeEntregaYReversaTrasLiquidacion():void
    {
        $com=new ErpComisionService($this->r);$com->configurar($this->actor,$this->d(['colaborador_id'=>$this->col,'porcentaje'=>'20','desde'=>'2020-01-01']));
        $id=$this->venta('2');$this->confirmar($id);(new ErpCajaService($this->r))->abrir($this->actor,$this->d(['fondo'=>'100']));
        (new ErpPagoService($this->r))->cobrar($this->actor,$this->d(['venta_id'=>$id,'metodo'=>'efectivo','monto'=>'23']));
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_comision_movimientos')['n']);
        (new ErpEntregaService($this->r))->entregar($this->actor,$this->d(['venta_id'=>$id]));
        self::assertSame(400,(int)$this->r->uno('SELECT SUM(monto_centavos) n FROM erp_comision_movimientos')['n']);
        $com->liquidar($this->actor,$this->d(['colaborador_id'=>$this->col,'hasta'=>substr($this->r::ahora(),0,10),'via'=>'directa']));
        $linea=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$id])['id'];
        (new ErpDevolucionService($this->r))->devolver($this->actor,$this->d(['venta_id'=>$id,'linea_id'=>$linea,'cantidad'=>'1','reintegrar_stock'=>'1','motivo'=>'Devolución sin uso']));
        self::assertSame(-200,(int)$this->r->uno("SELECT SUM(monto_centavos) n FROM erp_comision_movimientos WHERE estado='pendiente'")['n']);
        self::assertSame(400,(int)$this->r->uno('SELECT monto_centavos FROM erp_obligaciones')['monto_centavos']);
        $com->recalcular($this->actor,$this->d(['venta_id'=>$id]));self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_comision_movimientos')['n']);
    }
    public function testTransferenciaNoEsCajaAntesDeVerificarNiPermiteAutoverificar():void
    {
        $fin=R5CajaFixture::usuario($this->db,'finanzas');$id=$this->venta();$this->confirmar($id);$cajas=new ErpCajaService($this->r);$ses=$cajas->abrir($fin,$this->d(['fondo'=>'0']));
        $ps=new ErpPagoService($this->r);$ps->cobrar($fin,$this->d(['venta_id'=>$id,'metodo'=>'transferencia','monto'=>'11.50','referencia'=>'BANCO-101']));
        self::assertSame(0,$cajas->esperado($ses['recurso_id']));self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
        $p=$this->r->uno('SELECT id FROM pagos')['id'];try{$ps->verificar($fin,$this->d(['venta_id'=>$id,'pago_id'=>$p,'decision'=>'confirmar','nota'=>'Verificada']));self::fail('No debe autoverificar');}catch(ErpException $e){self::assertSame(403,$e->getCode());}
        $cajas->abrir($this->actor,$this->d(['fondo'=>'0']));$ps->verificar($this->actor,$this->d(['venta_id'=>$id,'pago_id'=>$p,'decision'=>'confirmar','nota'=>'Comprobado en banco']));
        self::assertSame(0,(new ErpVentaService($this->r))->resumen($id)['saldo']);self::assertSame(0,$cajas->esperado($ses['recurso_id']));
    }
    public function testIntegracionConciliadaTrasRespuestaPerdidaSinNuevoEnvio():void
    {
        $id=$this->venta();$this->confirmar($id);
        $adaptador=new class implements \Interfaces\IntegracionErpInterface {
            public int $envios=0;
            public function consultar(string $r):?array{return ['estado'=>'confirmado','referencia_externa'=>'EXT-'.$r];}
            public function enviar(string $r,array $d):array{$this->envios++;throw new \RuntimeException('No debe enviar');}
        };
        $svc=new ErpIntegracionService($this->r);$r=$svc->procesar(['fiscal'=>$adaptador]);self::assertSame(1,$r['confirmados']);self::assertSame(0,$adaptador->envios);
        self::assertSame(0,$svc->procesar(['fiscal'=>$adaptador])['confirmados']);
    }
}
