<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\{ActiveRecord,CitaServicio};
use Repositories\{ErpRepository,CitaRepository};
use Services\{ErpMaestrosService,ErpVentaService,ServicioService};
final class ErpTarifaIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;
    protected function setUp():void{$this->db=BaseDatosAislada::crear('erp_tarifa');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->owner=R5CajaFixture::usuario($this->db,'propietario');}
    protected function tearDown():void{BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array{return $d+['idem_key'=>R5CajaFixture::clave()];}
    public function testReservaGuardaImpuestoPactadoYUnCambioDeCatalogoNoLoReescribe():void
    {
        $cli=R5CajaFixture::usuario($this->db);$p=R5CajaFixture::profesional($this->db);$c=R5CajaFixture::cita($this->db,$cli,$p,['20']);$sid=(int)$this->r->uno('SELECT servicioId FROM citasservicios WHERE citaId=?',[$c])['servicioId'];$svc=new ErpMaestrosService($this->r);
        $svc->catalogo($this->owner,$this->d(['origen'=>'servicio','origen_id'=>$sid,'precio'=>'10','impuesto'=>'15']));self::assertSame('11.50',$this->r->uno('SELECT precio FROM servicios WHERE id=?',[$sid])['precio']);
        $this->db->begin_transaction();(new CitaRepository($this->db))->createCitaServicio(new CitaServicio(['citaId'=>$c,'servicioId'=>$sid,'nombre_servicio'=>'Nueva tarifa','precio_servicio'=>'11.50','duracion_minutos'=>30]),$this->db);$this->db->commit();
        $svc->catalogo($this->owner,$this->d(['origen'=>'servicio','origen_id'=>$sid,'precio'=>'30','impuesto'=>'12']));$venta=(new ErpVentaService($this->r))->crear($this->owner,$this->d(['cita_id'=>$c]))['recurso_id'];$ls=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id',[$venta]);
        self::assertSame(2000,(int)$ls[0]['total_centavos']);self::assertSame(0,(int)$ls[0]['impuesto_centavos'],'La línea anterior sin desglose conserva su precio histórico sin inventar impuesto.');
        self::assertSame(1000,(int)$ls[1]['precio_centavos']);self::assertSame(150,(int)$ls[1]['impuesto_centavos']);self::assertSame(1150,(int)$ls[1]['total_centavos']);
        $res=(new ServicioService(new \Repositories\ServicioRepository($this->db)))->actualizar($sid,['nombre'=>'Nuevo nombre','precio'=>'50','duracion_minutos'=>'30']);self::assertSame(ServicioService::STATUS_INVALID,$res['status']);self::assertSame('33.60',$this->r->uno('SELECT precio FROM servicios WHERE id=?',[$sid])['precio']);
        $this->expectException(\Repositories\PrecioServicioGestionadoException::class);(new \Repositories\ServicioRepository($this->db))->update(new \Model\Servicio(['id'=>$sid,'nombre'=>'Intento directo','precio'=>'50','duracion_minutos'=>'30']));
    }
}
