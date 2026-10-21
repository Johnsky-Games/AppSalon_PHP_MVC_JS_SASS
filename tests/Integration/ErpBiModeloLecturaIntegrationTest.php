<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpContabilidadService,ErpIntegracionService,ErpMaestrosService,ErpVentaService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpCompraService};
use Services\Contabilidad\{ErpContabilidadAdaptador,InventarioValorizadoConciliacion};

/**
 * Modelo de lectura para BI (057): las vistas bi_* son de solo lectura, no exponen datos personales y sus totales cuadran
 * con las tablas de origen y con los controles de conciliación existentes (ventas ↔ origen, libro ↔ partida doble, inventario ↔ auxiliar).
 */
final class ErpBiModeloLecturaIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $contacto;private int $producto;private int $catalogo;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('bimodelo');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');
        $cli=R5CajaFixture::usuario($this->db,'cliente');$this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$cli]);
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->owner,$this->d(['nombre'=>'Clienta Privada','tipo'=>'cliente']))['recurso_id'];$m->vincularCliente($this->owner,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$cli]));
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('BI-1','Champú','unidad',0,0)");$this->producto=$this->r->id();
        $this->catalogo=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'10.00','impuesto'=>'15.00','tipo'=>'venta']))['recurso_id'];
        (new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function n(string $sql):int {return (int)($this->r->uno($sql)['n']??0);}
    private function procesar():void {for($i=0;$i<6;$i++){$x=(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>new ErpContabilidadAdaptador($this->r)]);if(!$x['confirmados'])break;}}
    private function recibir(string $cant,string $costo):void
    {
        $c=new ErpCompraService($this->r);$prov=(new ErpMaestrosService($this->r))->contacto($this->owner,$this->d(['nombre'=>'Proveedor '.bin2hex(random_bytes(2)),'tipo'=>'proveedor']))['recurso_id'];
        $compra=$c->crear($this->owner,$this->d(['proveedor_id'=>$prov]))['recurso_id'];$c->agregar($this->owner,$this->d(['compra_id'=>$compra,'producto_id'=>$this->producto,'cantidad'=>$cant,'costo'=>$costo,'impuesto'=>'15.00']));
        $total=number_format((int)$this->r->uno('SELECT total_centavos FROM erp_compras WHERE id=?',[$compra])['total_centavos']/100,2,'.','');$c->ordenar($this->owner,$this->d(['compra_id'=>$compra,'total_esperado'=>$total]));
        $linea=(int)$this->r->uno('SELECT id FROM erp_compra_lineas WHERE compra_id=? ORDER BY id DESC LIMIT 1',[$compra])['id'];$c->recibir($this->owner,$this->d(['compra_id'=>$compra,'linea_id'=>$linea,'cantidad'=>$cant,'referencia'=>'Guía']));
    }
    private function vender(string $cant):int
    {
        $v=new ErpVentaService($this->r);$id=$v->crear($this->owner,$this->d(['contacto_id'=>$this->contacto]))['recurso_id'];$v->agregar($this->owner,$this->d(['venta_id'=>$id,'catalogo_id'=>$this->catalogo,'cantidad'=>$cant]));
        $v->confirmar($this->owner,$this->d(['venta_id'=>$id,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$id])['version']]));
        $total=number_format($v->resumen($id)['total']/100,2,'.','');(new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$id,'monto'=>$total,'recibido'=>$total,'metodo'=>'efectivo']));
        (new ErpEntregaService($this->r))->entregar($this->owner,$this->d(['venta_id'=>$id]));
        return $id;
    }
    private function flujo():int {$this->recibir('10','4.00');$id=$this->vender('3');$this->vender('1');$this->procesar();return $id;}

    public function testLasVistasExistenYSonDeSoloLectura():void
    {
        foreach(['bi_venta_lineas','bi_libro_lineas','bi_inventario_movimientos'] as $v){
            self::assertSame(1,$this->n("SELECT COUNT(*) n FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$v' AND security_type='INVOKER'"),"$v existe y es SQL SECURITY INVOKER");
        }
        $this->flujo();
        $huella=fn()=>[$this->n("SELECT COALESCE(SUM(total_centavos),0) n FROM erp_venta_lineas"),$this->n("SELECT COUNT(*) n FROM erp_cont_lineas"),$this->n("SELECT COALESCE(SUM(costo_centavos),0) n FROM inventario_movimientos")];
        foreach(['bi_venta_lineas'=>"UPDATE bi_venta_lineas SET total_centavos=0",'bi_libro_lineas'=>"DELETE FROM bi_libro_lineas",'bi_inventario_movimientos'=>"UPDATE bi_inventario_movimientos SET costo_centavos=0"] as $v=>$sql){
            $antes=$huella();
            try{$rechazada=($this->db->query($sql)===false);}catch(\mysqli_sql_exception){$rechazada=true;}
            self::assertTrue($rechazada,"$v rechaza escrituras (no es actualizable)");
            self::assertSame($antes,$huella(),"$v: las tablas de origen quedan intactas");
        }
    }

    public function testNoExponenDatosPersonalesNiIdentificacion():void
    {
        $this->flujo();
        $prohibidas=['comprador','contacto','nombre','email','correo','telefono','identificacion','ruc','cedula','direccion','json'];
        foreach(['bi_venta_lineas','bi_libro_lineas','bi_inventario_movimientos'] as $v){
            $cols=array_column($this->db->query("SELECT column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$v'")->fetch_all(MYSQLI_ASSOC),'c');
            self::assertNotEmpty($cols);
            foreach($cols as $c)foreach($prohibidas as $p)if($p==='nombre'&&$c==='cuenta_nombre')continue;else self::assertStringNotContainsString($p,strtolower($c),"$v.$c no debe exponer datos personales");
        }
        $texto=json_encode($this->db->query("SELECT * FROM bi_venta_lineas")->fetch_all(MYSQLI_ASSOC));
        self::assertStringNotContainsString('Clienta Privada',$texto);
    }

    public function testVentasCuadranConSuOrigenYSoloEntranLasConfirmadas():void
    {
        $this->flujo();
        $borrador=(new ErpVentaService($this->r));$b=$borrador->crear($this->owner,$this->d(['contacto_id'=>$this->contacto]))['recurso_id'];$borrador->agregar($this->owner,$this->d(['venta_id'=>$b,'catalogo_id'=>$this->catalogo,'cantidad'=>'2']));
        $origen=$this->r->uno("SELECT COUNT(*) n, COALESCE(SUM(l.total_centavos),0) t, COALESCE(SUM(l.impuesto_centavos),0) i FROM erp_venta_lineas l JOIN erp_ventas v ON v.id=l.venta_id WHERE v.estado='confirmada'");
        $vista=$this->r->uno("SELECT COUNT(*) n, COALESCE(SUM(total_centavos),0) t, COALESCE(SUM(impuesto_centavos),0) i, COALESCE(SUM(neto_sin_impuesto_centavos),0) neto FROM bi_venta_lineas");
        self::assertSame((int)$origen['n'],(int)$vista['n']);self::assertSame(2,(int)$vista['n'],'solo las dos ventas confirmadas, no el borrador');
        self::assertSame((int)$origen['t'],(int)$vista['t']);self::assertSame((int)$origen['i'],(int)$vista['i']);
        self::assertSame((int)$vista['t'],(int)$vista['neto']+(int)$vista['i'],'neto + impuesto = total');
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_venta_lineas WHERE venta_id=$b"),'el borrador no aparece');
    }

    public function testElLibroCuadraPartidaDobleYConElSaldoDeLaCuentaDeInventario():void
    {
        $this->flujo();
        $t=$this->r->uno("SELECT COALESCE(SUM(debe_centavos),0) d, COALESCE(SUM(haber_centavos),0) h, COALESCE(SUM(neto_deudor_centavos),0) n FROM bi_libro_lineas");
        self::assertGreaterThan(0,(int)$t['d']);self::assertSame((int)$t['d'],(int)$t['h'],'debe = haber en toda la vista');self::assertSame(0,(int)$t['n']);
        self::assertSame($this->n("SELECT COUNT(*) n FROM erp_cont_lineas"),$this->n("SELECT COUNT(*) n FROM bi_libro_lineas"),'una fila por línea contable, sin duplicar por los cruces');
        $ids=(new InventarioValorizadoConciliacion($this->r))->informe('2099-12-31');
        $codigoInv=$this->r->uno("SELECT c.codigo FROM erp_cont_roles rc JOIN erp_cont_cuentas c ON c.id=rc.cuenta_id WHERE rc.rol='inventario'")['codigo']??null;
        self::assertNotNull($codigoInv);
        $saldo=(int)$this->r->uno("SELECT COALESCE(SUM(neto_deudor_centavos),0) n FROM bi_libro_lineas WHERE cuenta_codigo=?",[$codigoInv])['n'];
        self::assertSame((int)$ids['libro'],$saldo,'el saldo de inventario según la vista = el del control de conciliación');
    }

    public function testElInventarioValorizadoDeLaVistaCuadraConElAuxiliarDelControl():void
    {
        $this->flujo();
        $inf=(new InventarioValorizadoConciliacion($this->r))->informe('2099-12-31');
        $vista=(int)$this->r->uno("SELECT COALESCE(SUM(valor_con_signo_centavos),0) n FROM bi_inventario_movimientos")['n'];
        self::assertSame((int)$inf['auxiliar'],$vista,'Σ valor con signo de la vista = auxiliar valorizado del control');
        self::assertSame($this->n("SELECT COUNT(*) n FROM inventario_movimientos"),$this->n("SELECT COUNT(*) n FROM bi_inventario_movimientos"));
        $stock=(int)$this->r->uno("SELECT stock_milesimas s FROM inventario_productos WHERE id=?",[$this->producto])['s'];
        self::assertSame($stock,(int)$this->r->uno("SELECT SUM(cantidad_con_signo_milesimas) n FROM bi_inventario_movimientos WHERE producto_id=?",[$this->producto])['n'],'las cantidades con signo suman la existencia');
    }

    public function testConsultarLasVistasNoModificaNadaDelNegocio():void
    {
        $this->flujo();
        $tablas=['erp_ventas','erp_venta_lineas','erp_cont_asientos','erp_cont_lineas','inventario_movimientos','inventario_productos','erp_eventos'];
        $huella=fn()=>array_map(fn($t)=>$this->n("SELECT COUNT(*) n FROM $t").':'.($this->r->uno("CHECKSUM TABLE $t")['Checksum']??''),$tablas);
        $antes=$huella();
        foreach(['bi_venta_lineas','bi_libro_lineas','bi_inventario_movimientos'] as $v){$this->db->query("SELECT * FROM $v")->fetch_all();}
        self::assertSame($antes,$huella());
    }
}
