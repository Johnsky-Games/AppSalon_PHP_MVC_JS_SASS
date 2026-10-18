<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\ErpRepository;
use Services\{ErpVentaService,ErpMaestrosService,ErpCajaService};
final class ErpConcurrenciaIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $catalogo;private int $producto;
    protected function setUp():void{
        $this->db=BaseDatosAislada::crear('erp_carrera');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->owner=R5CajaFixture::usuario($this->db,'propietario');
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('RACE','Última unidad','unidad',1000)");$this->producto=$this->r->id();
        $this->catalogo=(new ErpMaestrosService($this->r))->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'10','impuesto'=>'0']))['recurso_id'];
    }
    protected function tearDown():void{BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array{return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function venta():int{$s=new ErpVentaService($this->r);$id=$s->crear($this->owner,$this->d())['recurso_id'];$s->agregar($this->owner,$this->d(['venta_id'=>$id,'catalogo_id'=>$this->catalogo,'cantidad'=>'1']));return $id;}
    private function competir(string $accion,array $datos,string $tabla,int $id):array
    {
        if(!in_array($tabla,['inventario_productos','erp_ventas'],true))throw new \LogicException('Tabla de barrera');
        $holder=new \mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),BaseDatosAislada::nombre($this->db),(int)getenv('DB_PORT'));
        $holder->begin_transaction();$holder->query("SELECT id FROM $tabla WHERE id=$id FOR UPDATE");$workers=[];$resultados=[];
        try{
            foreach($datos as $d){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_erp_worker.php',$accion,(string)$this->owner,json_encode($d)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),$_ENV,['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            usleep(400000);$holder->commit();
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err.(string)$linea);$j=json_decode((string)$linea,true);self::assertIsArray($j);$resultados[]=$j;}
        }finally{$holder->rollback();$holder->close();foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        return $resultados;
    }
    public function testUltimaUnidadSeConfirmaUnaSolaVezEntreVentasDiferentes():void
    {
        $a=$this->venta();$b=$this->venta();$ds=[];foreach([$a,$b] as $v)$ds[]=$this->d(['venta_id'=>$v,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]);
        $res=$this->competir('confirmar',$ds,'inventario_productos',$this->producto);self::assertCount(1,array_filter($res,fn($r)=>$r['ok']),json_encode($res));self::assertSame(1000,(int)$this->r->uno("SELECT SUM(cantidad_milesimas) n FROM erp_stock_reservas WHERE estado='activa'")['n']);
    }
    public function testDosProcesosMismaClaveSoloUnPagoYUnRecibo():void
    {
        $id=$this->venta();(new ErpVentaService($this->r))->confirmar($this->owner,$this->d(['venta_id'=>$id,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$id])['version']]));(new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']));
        $d=$this->d(['venta_id'=>$id,'monto'=>'10','metodo'=>'efectivo','recibido'=>'10']);$res=$this->competir('cobrar',[$d,$d],'erp_ventas',$id);self::assertTrue($res[0]['ok']&&$res[1]['ok'],json_encode($res));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_pago_aplicaciones')['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_recibos')['n']);self::assertSame(1000,(int)$this->r->uno('SELECT SUM(monto_centavos) n FROM caja_movimientos')['n']);
    }
}
