<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';
require_once __DIR__.'/R5CajaFixture.php';
use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Repositories\OperacionRepository;
use Repositories\CrmRepository;
use Repositories\ColaboradorRepository;
use Repositories\CitaRepository;
use Services\CobroCitaService;
final class ErpConsistenciaIntegrationTest extends TestCase
{
    private \mysqli $db;
    protected function setUp():void { $this->db=BaseDatosAislada::crear('erp_consistencia'); ActiveRecord::setDB($this->db); }
    protected function tearDown():void { BaseDatosAislada::destruir($this->db); }
    public function testDevolucionesParcialesConciliadasPorFechaSinDuplicarTotal():void
    {
        $db=$this->db;$owner=R5CajaFixture::usuario($db,'propietario');$cli=R5CajaFixture::usuario($db);
        $hoy=(new \DateTimeImmutable('today',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $cita=R5CajaFixture::cita($db,$cli,null,['20.00'],'completada',$hoy);
        $svc=new CobroCitaService(new OperacionRepository($db));
        $p=$svc->cobrar($owner,['cita_id'=>$cita,'metodo'=>'efectivo','monto'=>'20.00','idem_key'=>R5CajaFixture::clave()]);
        $svc->devolver($owner,['pago_id'=>$p['pago_id'],'monto'=>'5.00','motivo'=>'Devolución parcial','idem_key'=>R5CajaFixture::clave()]);
        $repo=new CrmRepository($db);$g=$repo->gastoDeCliente($cli,$hoy,$hoy);
        self::assertSame([2000,500],[$g['cobros_real'],$g['dev_real']]);
        $db->query('UPDATE pagos SET pagado_at=DATE_SUB(pagado_at,INTERVAL 10 DAY) WHERE id='.(int)$p['pago_id']);
        $g=$repo->gastoDeCliente($cli,$hoy,$hoy);self::assertSame([0,500],[$g['cobros_real'],$g['dev_real']]);
        $svc->devolver($owner,['pago_id'=>$p['pago_id'],'monto'=>'saldo','motivo'=>'Resto de la devolución','idem_key'=>R5CajaFixture::clave()]);
        self::assertSame(2000,$repo->gastoDeCliente($cli,$hoy,$hoy)['dev_real']);
    }
    public function testVinculoExistenteConservaFichaYRechazaSegunda():void
    {
        $db=$this->db;$owner=R5CajaFixture::usuario($db,'propietario');$u=R5CajaFixture::usuario($db,'profesional');
        $db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en) SELECT id,email,nombre,apellido,'profesional','activo',NOW() FROM usuarios WHERE id=$u");$c=(int)$db->insert_id;
        $p=R5CajaFixture::profesional($db);$repo=new ColaboradorRepository($db);
        $repo->vincularProfesionalExistente($owner,$c,$p);$repo->vincularProfesionalExistente($owner,$c,$p);
        $f=$db->query("SELECT * FROM profesionales WHERE id=$p")->fetch_assoc();
        self::assertSame($c,(int)$f['colaborador_id']);self::assertSame($u,(int)$f['usuarioId']);self::assertSame(1,(int)$f['activo']);
        $otro=R5CajaFixture::profesional($db);
        $this->expectException(\DomainException::class);$repo->vincularProfesionalExistente($owner,$c,$otro);
    }
    public function testAgendaPaginasConservanOrdenYConteo():void
    {
        $cli=R5CajaFixture::usuario($this->db);
        for($i=0;$i<27;$i++)R5CajaFixture::cita($this->db,$cli,null,['1.00'],'confirmada','2030-01-02');
        $r=new CitaRepository($this->db);self::assertSame(27,$r->contarAgenda('2030-01-02','2030-01-02'));
        $a=$r->listarAgenda('2030-01-02','2030-01-02',null,null,1,25);$b=$r->listarAgenda('2030-01-02','2030-01-02',null,null,2,25);
        self::assertCount(25,$a);self::assertCount(2,$b);self::assertGreaterThan((int)end($a)['id'],(int)$b[0]['id']);
        self::assertSame(0,$r->contarAgenda('2030-01-02','2030-01-02',null,'cancelada'));
    }
}
