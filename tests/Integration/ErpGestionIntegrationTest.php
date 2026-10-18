<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\ErpRepository;
use Services\{ErpCompraService,ErpMaestrosService,ErpCajaService,ErpRhService,ErpAprobacionService,ErpException};
final class ErpGestionIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;
    protected function setUp():void{$this->db=BaseDatosAislada::crear('erp_gestion');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->owner=R5CajaFixture::usuario($this->db,'propietario');}
    protected function tearDown():void{BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array{return $d+['idem_key'=>R5CajaFixture::clave()];}
    public function testCompraRecepcionFacturaYPagoParcial():void
    {
        $prov=(new ErpMaestrosService($this->r))->contacto($this->owner,$this->d(['nombre'=>'Proveedor','tipo'=>'proveedor']))['recurso_id'];
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad) VALUES('ERP-C','Crema','unidad')");$p=$this->r->id();
        $svc=new ErpCompraService($this->r);$id=$svc->crear($this->owner,$this->d(['proveedor_id'=>$prov]))['recurso_id'];
        $svc->agregar($this->owner,$this->d(['compra_id'=>$id,'producto_id'=>$p,'cantidad'=>'4','costo'=>'5','impuesto'=>'0']));$svc->ordenar($this->owner,$this->d(['compra_id'=>$id,'total_esperado'=>'20']));
        $l=$this->r->uno('SELECT id FROM erp_compra_lineas WHERE compra_id=?',[$id])['id'];$datos=$this->d(['compra_id'=>$id,'linea_id'=>$l,'cantidad'=>'2','referencia'=>'Recepción 1']);$svc->recibir($this->owner,$datos);$svc->recibir($this->owner,$datos);
        self::assertSame(2000,(int)$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=?',[$p])['stock_milesimas']);
        $svc->recibir($this->owner,$this->d(['compra_id'=>$id,'linea_id'=>$l,'cantidad'=>'2','referencia'=>'Recepción 2']));
        $svc->facturar($this->owner,$this->d(['compra_id'=>$id,'monto'=>'20','referencia'=>'FAC-1','vence_en'=>'2030-01-01']));
        $ob=$this->r->uno('SELECT id FROM erp_obligaciones')['id'];(new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'100']));
        $svc->pagar($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>'8','metodo'=>'efectivo','referencia'=>'Pago parcial']));
        self::assertSame(800,(int)$this->r->uno('SELECT pagado_centavos FROM erp_obligaciones')['pagado_centavos']);
        self::assertSame(4000,(int)$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=?',[$p])['stock_milesimas']);
    }
    private function colaborador():array
    {
        $u=R5CajaFixture::usuario($this->db,'profesional');$this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en) SELECT id,email,nombre,apellido,'profesional','activo',NOW() FROM usuarios WHERE id=?",[$u]);$c=$this->r->id();
        $p=R5CajaFixture::profesional($this->db);$this->r->q('UPDATE profesionales SET colaborador_id=?,usuarioId=? WHERE id=?',[$c,$u,$p]);return [$c,$u,$p];
    }
    public function testAusenciaPropiaCreaBloqueoYSeProtege():void
    {
        [$c,$u,$p]=$this->colaborador();$svc=new ErpRhService($this->r);
        $svc->solicitarAusencia($u,$this->d(['colaborador_id'=>999,'desde'=>'2030-02-01','hasta'=>'2030-02-02','motivo'=>'Vacaciones solicitadas']));
        $a=$this->r->uno('SELECT * FROM erp_ausencias');self::assertSame($c,(int)$a['colaborador_id']);
        $svc->resolverAusencia($this->owner,$this->d(['ausencia_id'=>$a['id'],'decision'=>'aprobar']));
        $a=$this->r->uno('SELECT * FROM erp_ausencias');self::assertSame('aprobada',$a['estado']);self::assertNotNull($a['bloqueo_id']);
        $this->expectException(\Repositories\AgendaLaboralException::class);(new \Repositories\ProfesionalRepository($this->db))->deleteBloqueo($p,(int)$a['bloqueo_id']);
    }
    public function testAusenciaNoSeApruebaSobreCitaConfirmada():void
    {
        [$c,$u,$p]=$this->colaborador();$cli=R5CajaFixture::usuario($this->db);R5CajaFixture::cita($this->db,$cli,$p,['20'],'confirmada','2030-02-01');
        $svc=new ErpRhService($this->r);$svc->solicitarAusencia($u,$this->d(['desde'=>'2030-02-01','hasta'=>'2030-02-02','motivo'=>'Permiso']));$a=$this->r->uno('SELECT id FROM erp_ausencias');
        try{$svc->resolverAusencia($this->owner,$this->d(['ausencia_id'=>$a['id'],'decision'=>'aprobar']));self::fail('Debe gestionar citas');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        self::assertSame('solicitada',$this->r->uno('SELECT estado FROM erp_ausencias')['estado']);
    }
    public function testGastoAprobadoGeneraObligacionNoSalidaAutomatica():void
    {
        $recep=R5CajaFixture::usuario($this->db,'recepcion');$svc=new ErpAprobacionService($this->r);
        $id=$svc->solicitar($recep,$this->d(['tipo'=>'gasto','monto'=>'12','motivo'=>'Materiales de limpieza']))['recurso_id'];
        $svc->resolver($this->owner,$this->d(['solicitud_id'=>$id,'decision'=>'aprobar','nota'=>'Dentro del presupuesto']));
        self::assertSame(1200,(int)$this->r->uno('SELECT monto_centavos FROM erp_obligaciones')['monto_centavos']);self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
    }
    public function testCreditoProveedorNoConfundeDeudaConReintegroRecibido():void
    {
        $prov=(new ErpMaestrosService($this->r))->contacto($this->owner,$this->d(['nombre'=>'Proveedor','tipo'=>'proveedor']))['recurso_id'];
        $svc=new ErpCompraService($this->r);$id=$svc->crear($this->owner,$this->d(['proveedor_id'=>$prov]))['recurso_id'];
        $this->r->q("UPDATE erp_compras SET estado='ordenada',total_centavos=2000 WHERE id=?",[$id]);$svc->facturar($this->owner,$this->d(['compra_id'=>$id,'monto'=>'20','referencia'=>'FAC-CRED','vence_en'=>'2030-01-01']));$ob=$this->r->uno('SELECT id FROM erp_obligaciones')['id'];
        (new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'100']));$svc->pagar($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>'20','metodo'=>'efectivo','referencia'=>'Pago realizado']));
        $svc->credito($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>'5','referencia'=>'NC-1','motivo'=>'Descuento del proveedor']));
        self::assertSame(8000,(new ErpCajaService($this->r))->esperado((int)$this->r->uno('SELECT id FROM erp_caja_sesiones')['id']));
        $svc->recuperar($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>'3','metodo'=>'efectivo','referencia'=>'Reintegro recibido']));
        self::assertSame(8300,(new ErpCajaService($this->r))->esperado((int)$this->r->uno('SELECT id FROM erp_caja_sesiones')['id']));
        $this->expectException(ErpException::class);$svc->recuperar($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>'3','metodo'=>'efectivo','referencia'=>'Excede pendiente']));
    }
    public function testAnulacionAsistenciaConservaOriginalYPermiteCorregirElIntervalo():void
    {
        [$c]=$this->colaborador();$svc=new ErpRhService($this->r);$d=['colaborador_id'=>$c,'entrada'=>'2025-01-02T09:00','salida'=>'2025-01-02T18:00','descanso'=>'60','motivo'=>'Registro manual'];$svc->asistencia($this->owner,$this->d($d));$a=$this->r->uno('SELECT id FROM erp_asistencia')['id'];
        $svc->anularAsistencia($this->owner,$this->d(['asistencia_id'=>$a,'motivo'=>'Hora de entrada incorrecta']));$d['entrada']='2025-01-02T10:00';$svc->asistencia($this->owner,$this->d($d));
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_asistencia')['n']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_asistencia WHERE anulado_en IS NULL')['n']);
    }
}
