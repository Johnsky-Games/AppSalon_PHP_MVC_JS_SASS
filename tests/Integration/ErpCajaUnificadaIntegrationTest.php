<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';
require_once __DIR__.'/R5CajaFixture.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpCajaService,FinanzasService,CobroCitaService,ComisionService,CobroRechazado};

final class ErpCajaUnificadaIntegrationTest extends TestCase
{
    private \mysqli $db;
    private ErpRepository $r;
    private int $actor;
    private int $cliente;
    private ErpCajaService $caja;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('arqueo_unificado');
        ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->actor=R5CajaFixture::usuario($this->db,'propietario');
        $this->cliente=R5CajaFixture::usuario($this->db);$this->caja=new ErpCajaService($this->r);
    }
    protected function tearDown():void { BaseDatosAislada::destruir($this->db); }
    private function d(array $d=[]):array { return $d+['idem_key'=>R5CajaFixture::clave()]; }
    private function abrir(string $fondo='0'):int { return $this->caja->abrir($this->actor,$this->d(['fondo'=>$fondo]))['recurso_id']; }
    private function manual(string $tipo,string $monto,string $metodo='efectivo'):array
    { return ['tipo'=>$tipo,'monto'=>$monto,'metodo'=>$metodo,'concepto'=>'Movimiento de ensayo','idempotencia'=>R5CajaFixture::clave()]; }
    private function cobro(?int $prof=null,string $monto='20'):array
    {
        $c=R5CajaFixture::cita($this->db,$this->cliente,$prof,[$monto],'completada');
        return (new CobroCitaService($this->r))->cobrar($this->actor,$this->d(['cita_id'=>$c,'metodo'=>'efectivo','monto'=>$monto,'recibido'=>'50']));
    }
    public function testCobroDevolucionYCambioSeIncluyenUnaVezEnElArqueo():void
    {
        $id=$this->abrir('10');$p=$this->cobro();self::assertSame(3000,$this->caja->esperado($id));
        $svc=new CobroCitaService($this->r);$d=$this->d(['pago_id'=>$p['pago_id'],'monto'=>'5','motivo'=>'Devolución parcial']);
        $a=$svc->devolver($this->actor,$d);$b=$svc->devolver($this->actor,$d);
        self::assertSame($a['caja_id'],$b['caja_id']);self::assertTrue($b['repetida']);
        self::assertSame(2500,$this->caja->esperado($id));
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_movimientos WHERE sesion_id=?',[$id])['n']);
        $this->caja->cerrar($this->actor,$this->d(['contado'=>'25','motivo'=>'Arqueo conciliado']));
        self::assertSame([2500,0],array_map('intval',array_values($this->r->uno('SELECT esperado_centavos,diferencia_centavos FROM erp_caja_sesiones WHERE id=?',[$id]))));
    }
    public function testHistoriaSinSesionYReintentoNoSeReasignanNiContaminanLaReapertura():void
    {
        $svc=new FinanzasService($this->r);$antes=$this->manual('ingreso','8');$a=$svc->registrar($this->actor,$antes);
        $id=$this->abrir('8');$svc->registrar($this->actor,$antes);
        self::assertSame(800,$this->caja->esperado($id));self::assertNull($this->r->uno('SELECT * FROM erp_caja_movimientos WHERE caja_id=?',[$a['id']]));
        $d=$this->manual('ingreso','3');$b=$svc->registrar($this->actor,$d);$svc->registrar($this->actor,$d);
        $this->caja->cerrar($this->actor,$this->d(['contado'=>'11','motivo'=>'Cierre conciliado']));
        $nuevo=$this->abrir('11');$svc->registrar($this->actor,$d);
        self::assertSame(1100,$this->caja->esperado($nuevo));
        self::assertSame($id,(int)$this->r->uno('SELECT sesion_id FROM erp_caja_movimientos WHERE caja_id=?',[$b['id']])['sesion_id']);
        self::assertSame(1100,(int)$this->r->uno('SELECT esperado_centavos FROM erp_caja_sesiones WHERE id=?',[$id])['esperado_centavos']);
    }
    public function testTransferenciaSoloSeVinculaAlVerificarseYNoAumentaEfectivo():void
    {
        $id=$this->abrir('10');$c=R5CajaFixture::cita($this->db,$this->cliente,null,['20'],'completada');$svc=new CobroCitaService($this->r);
        $p=$svc->cobrar($this->actor,$this->d(['cita_id'=>$c,'metodo'=>'transferencia','monto'=>'20','referencia'=>'BANCO-ENSAYO-2026']));
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_movimientos')['n']);
        $svc->verificar($this->actor,$this->d(['pago_id'=>$p['pago_id'],'nota'=>'Abono verificado']));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_movimientos')['n']);
        self::assertSame(1000,$this->caja->esperado($id));
        $svc->devolver($this->actor,$this->d(['pago_id'=>$p['pago_id'],'monto'=>'20','motivo'=>'Reintegro bancario']));
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_movimientos')['n']);self::assertSame(1000,$this->caja->esperado($id));
    }
    public function testNoUsaLaCajaDeOtroOperador():void
    {
        $id=$this->abrir('4');$otro=R5CajaFixture::usuario($this->db,'finanzas');
        (new FinanzasService($this->r))->registrar($otro,$this->manual('ingreso','9'));
        self::assertSame(400,$this->caja->esperado($id));self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_movimientos')['n']);
    }
    public function testComisionPagadaSeDescuentaUnaVezDelEfectivo():void
    {
        $id=$this->abrir();$prof=R5CajaFixture::profesional($this->db,1000);$this->cobro($prof);
        $svc=new ComisionService($this->r);$d=['profesional_id'=>$prof,'hasta'=>'2030-01-02','metodo'=>'efectivo','comprobante'=>'Recibido de ensayo','idempotencia'=>R5CajaFixture::clave()];
        $a=$svc->liquidar($this->actor,$d);$b=$svc->liquidar($this->actor,$d);
        self::assertSame($a['id'],$b['id']);self::assertTrue($b['idempotente']);
        self::assertSame(1800,$this->caja->esperado($id));self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_movimientos')['n']);
    }
    public function testDevolucionSinEfectivoRevierteTodoElIntento():void
    {
        $p=$this->cobro();$id=$this->abrir('0');
        try {(new CobroCitaService($this->r))->devolver($this->actor,$this->d(['pago_id'=>$p['pago_id'],'monto'=>'5','motivo'=>'Ensayo sin fondo']));self::fail('Debe exigir efectivo');}
        catch(CobroRechazado $e){self::assertSame(422,$e->http());self::assertStringContainsString('efectivo esperado',$e->getMessage());}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM cobros_devoluciones')['n']);
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);self::assertSame(0,$this->caja->esperado($id));
        self::assertSame('completado',$this->r->uno('SELECT estado FROM pagos WHERE id=?',[$p['pago_id']])['estado']);
    }
    public function testEgresoManualSinFondoNoSeGuarda():void
    {
        $id=$this->abrir('2');
        try {(new FinanzasService($this->r))->registrar($this->actor,$this->manual('egreso','3'));self::fail('Debe exigir efectivo');}
        catch(\InvalidArgumentException $e){self::assertStringContainsString('efectivo esperado',$e->getMessage());}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);self::assertSame(200,$this->caja->esperado($id));
    }
    /** Dos procesos distintos esperan el mismo candado de sesión en MySQL. */
    private function competir(int $sesion,array $operaciones):array
    {
        $holder=new \mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASS'),BaseDatosAislada::nombre($this->db),(int)getenv('DB_PORT'));
        $holder->begin_transaction();$holder->query("SELECT id FROM erp_caja_sesiones WHERE id=$sesion FOR UPDATE");
        $workers=[];$resultados=[];
        try {
            foreach($operaciones as [$accion,$datos]) {
                $pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_arqueo_worker.php',$accion,(string)$this->actor,json_encode($datos)],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));
                self::assertIsResource($p);stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));
            }
            foreach($workers as [,$pipes]) {fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            usleep(400000);$holder->commit();
            foreach($workers as [$p,$pipes]) {
                $linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                self::assertSame(0,proc_close($p),$err.(string)$linea);$r=json_decode((string)$linea,true);self::assertIsArray($r);$resultados[]=$r;
            }
        } finally {
            $holder->rollback();$holder->close();
            foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}
        }
        return $resultados;
    }
    public function testRetirosSimultaneosNoGastanDosVecesElMismoFondo():void
    {
        $id=$this->abrir('7');$r=$this->competir($id,[['registrar',$this->manual('egreso','5')],['registrar',$this->manual('egreso','5')]]);
        self::assertCount(1,array_filter($r,fn($v)=>$v['ok']),json_encode([$r,$this->r->q('SELECT * FROM caja_movimientos'),$this->r->q('SELECT * FROM erp_caja_movimientos')]));self::assertSame(200,$this->caja->esperado($id));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM caja_movimientos')['n']);
    }
    public function testRetiroAntiguoYPdvCompartenElMismoLimite():void
    {
        $id=$this->abrir('7');$r=$this->competir($id,[['registrar',$this->manual('egreso','5')],['movimiento',$this->d(['tipo'=>'egreso','monto'=>'5','motivo'=>'Retiro desde PDV'])]]);
        self::assertCount(1,array_filter($r,fn($v)=>$v['ok']),json_encode([$r,$this->r->q('SELECT * FROM caja_movimientos'),$this->r->q('SELECT * FROM erp_caja_movimientos')]));self::assertSame(200,$this->caja->esperado($id));
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_movimientos')['n']);
    }
    public function testCierreConcurrenteNoRecibeMovimientosTardios():void
    {
        $id=$this->abrir('10');$r=$this->competir($id,[['registrar',$this->manual('ingreso','3')],['cerrar',$this->d(['contado'=>'10','motivo'=>'Arqueo concurrente de ensayo'])]]);
        self::assertTrue($r[0]['ok']&&$r[1]['ok'],json_encode($r));
        $c=$this->r->uno('SELECT * FROM erp_caja_sesiones WHERE id=?',[$id]);self::assertSame('cerrada',$c['estado']);
        self::assertSame($this->caja->esperado($id),(int)$c['esperado_centavos']);
        $nuevo=$this->abrir('10');self::assertSame(1000,$this->caja->esperado($nuevo));
        self::assertSame((int)$c['esperado_centavos'],$this->caja->esperado($id));
    }

}
