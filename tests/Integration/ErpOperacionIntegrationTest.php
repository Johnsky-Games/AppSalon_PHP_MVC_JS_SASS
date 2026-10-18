<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\ErpRepository;
use Services\{ErpRecursoService,ErpRecursoAgendaService,ErpConsumoService,ErpDocumentoService,ErpException};
final class ErpOperacionIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;
    protected function setUp():void{$this->db=BaseDatosAislada::crear('erp_operacion');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);$this->owner=R5CajaFixture::usuario($this->db,'propietario');}
    protected function tearDown():void{BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array{return $d+['idem_key'=>R5CajaFixture::clave()];}
    public function testCabinaCompartidaBloqueaOtroProfesionalYLiberaCancelacion():void
    {
        $cli=R5CajaFixture::usuario($this->db);$p=R5CajaFixture::profesional($this->db);$c=R5CajaFixture::cita($this->db,$cli,$p,['20'],'confirmada','2030-03-01');
        $sid=(int)$this->r->uno('SELECT servicioId FROM citasservicios WHERE citaId=?',[$c])['servicioId'];
        $svc=new ErpRecursoService($this->r);$svc->crear($this->owner,$this->d(['nombre'=>'Cabina 1','tipo'=>'cabina']));
        // La configuración pública no puede cambiar citas aceptadas; fixture representa una regla ya vigente.
        $this->r->q("INSERT INTO erp_servicio_recursos(servicio_id,tipo,cantidad,desde) VALUES(?,'cabina',1,'2029-01-01')",[$sid]);
        $agenda=new ErpRecursoAgendaService($this->r);$this->db->begin_transaction();$agenda->reservar($c);$this->db->commit();
        $rr=$this->r->uno('SELECT * FROM erp_recurso_reservas');self::assertNotNull($rr);
        self::assertNull($agenda->disponibles('2030-03-01',$rr['inicio'],$rr['fin'],[$sid]));
        self::assertCount(1,$agenda->disponibles('2030-03-01',$rr['fin'],'23:59:00',[$sid]));
        $this->r->q("UPDATE citas SET estado='cancelada' WHERE id=?",[$c]);self::assertCount(1,$agenda->disponibles('2030-03-01',$rr['inicio'],$rr['fin'],[$sid]));
    }
    public function testConsumoRespetaStockYUnaSolaSalidaPorAtencion():void
    {
        $cli=R5CajaFixture::usuario($this->db);$p=R5CajaFixture::profesional($this->db);$c=R5CajaFixture::cita($this->db,$cli,$p,['20'],'completada','2030-03-01');$sid=(int)$this->r->uno('SELECT servicioId FROM citasservicios WHERE citaId=?',[$c])['servicioId'];
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('CON','Champú','litro',1000)");$pid=$this->r->id();$svc=new ErpConsumoService($this->r);
        $svc->receta($this->owner,$this->d(['servicio_id'=>$sid,'producto_id'=>$pid,'cantidad'=>'0.250']));
        $svc->consumir($this->owner,$this->d(['cita_id'=>$c,'motivo'=>'Consumo real']));$svc->consumir($this->owner,$this->d(['cita_id'=>$c,'motivo'=>'Segundo clic']));
        self::assertSame(750,(int)$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=?',[$pid])['stock_milesimas']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_stock_origenes')['n']);
    }
    public function testDocumentoCifradoPrivadoYTitularVersionado():void
    {
        $u=R5CajaFixture::usuario($this->db,'profesional');$otro=R5CajaFixture::usuario($this->db,'profesional');
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado) SELECT id,email,nombre,apellido,'profesional','activo' FROM usuarios WHERE id=?",[$u]);$c=$this->r->id();$svc=new ErpDocumentoService($this->r);
        $datos=['entidad'=>'colaborador','entidad_id'=>$c,'titulo'=>'Contrato','nombre_archivo'=>'contrato.txt','contenido'=>'Evidencia contractual privada de prueba'];$svc->subir($this->owner,$this->d($datos));
        $doc=$this->r->uno('SELECT * FROM erp_documentos');self::assertStringNotContainsString('Evidencia',$doc['contenido_cifrado']);self::assertSame([],$svc->listar($u,'colaborador',$c));
        try{$svc->descargar($u,(int)$doc['id']);self::fail('Privado');}catch(ErpException $e){self::assertSame(403,$e->getCode());}
        $svc->subir($this->owner,$this->d($datos+['reemplaza_id'=>$doc['id'],'visibilidad'=>'titular']));$nuevo=(int)$this->r->uno('SELECT MAX(id) id FROM erp_documentos')['id'];
        self::assertSame($datos['contenido'],$svc->descargar($u,$nuevo)['contenido']);self::assertCount(1,$svc->listar($u,'colaborador',$c));
        $this->expectException(ErpException::class);$svc->descargar($otro,$nuevo);
    }
}
