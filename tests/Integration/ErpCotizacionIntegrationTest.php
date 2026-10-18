<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\ErpRepository;
use Services\{ErpVentaService,ErpMaestrosService,ErpCotizacionService,ErpInformeService,ErpException};
final class ErpCotizacionIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $venta;private int $catalogo;
    protected function setUp():void{
        $this->db=BaseDatosAislada::crear('erp_cotizacion');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->owner=R5CajaFixture::usuario($this->db,'propietario');
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('COT','Producto cotizado','unidad',5000)");$p=$this->r->id();
        $this->catalogo=(new ErpMaestrosService($this->r))->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$p,'precio'=>'10','impuesto'=>'15']))['recurso_id'];$s=new ErpVentaService($this->r);$this->venta=$s->crear($this->owner,$this->d())['recurso_id'];$s->agregar($this->owner,$this->d(['venta_id'=>$this->venta,'catalogo_id'=>$this->catalogo,'cantidad'=>'2']));
    }
    protected function tearDown():void{BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array{return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function emitir():int{(new ErpCotizacionService($this->r))->emitir($this->owner,$this->d(['venta_id'=>$this->venta,'vence_en'=>'2030-01-01','condiciones'=>'Oferta de prueba']));return (int)$this->r->uno('SELECT MAX(id) id FROM erp_cotizaciones')['id'];}
    public function testCotizacionNoReservaYCierraMismosImportesAlAceptar():void
    {
        $q=$this->emitir();self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_stock_reservas')['n']);$svc=new ErpCotizacionService($this->r);
        $d=$this->d(['venta_id'=>$this->venta,'cotizacion_id'=>$q,'referencia_aceptacion'=>'Comprador aceptó en recepción']);$svc->aceptar($this->owner,$d);$svc->aceptar($this->owner,$d);
        self::assertSame('aceptada',$svc->obtener($this->owner,$q)['estado']);self::assertSame(2000,(int)$this->r->uno('SELECT cantidad_milesimas FROM erp_stock_reservas')['cantidad_milesimas']);
        $bi=(new ErpInformeService($this->r))->obtener($this->owner,[]);self::assertSame(2300,(int)$bi['ventas']['bruto']);self::assertSame(2300,(int)$bi['cxc']['saldo']);self::assertSame(0,(int)$bi['caja']['ingresos']);
    }
    public function testCambiarConceptosExigeCotizacionNueva():void
    {
        $q=$this->emitir();(new ErpVentaService($this->r))->agregar($this->owner,$this->d(['venta_id'=>$this->venta,'catalogo_id'=>$this->catalogo,'cantidad'=>'1']));
        self::assertSame(2300,(int)(new ErpCotizacionService($this->r))->obtener($this->owner,$q)['contenido']['resumen']['total']);
        $this->expectException(ErpException::class);(new ErpCotizacionService($this->r))->aceptar($this->owner,$this->d(['venta_id'=>$this->venta,'cotizacion_id'=>$q,'referencia_aceptacion'=>'Referencia']));
    }
}
