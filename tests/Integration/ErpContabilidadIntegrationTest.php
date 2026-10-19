<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpContabilidadService,ErpMaestrosService,ErpException};

/** Libro de partida doble: inmutable, balanceado, con períodos, identidad permanente por hecho económico y reversos. */
final class ErpContabilidadIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $cliente;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('contabilidad');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->cliente=(new ErpMaestrosService($this->r))->contacto($this->owner,$this->d(['nombre'=>'Cliente contable','tipo'=>'cliente']))['recurso_id'];
        $this->svc()->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function svc():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function cuenta(string $codigo):int {return (int)$this->r->uno('SELECT id FROM erp_cont_cuentas WHERE codigo=?',[$codigo])['id'];}
    private function asiento(string $fecha,string $debe,string $haber,string $monto,array $extra=[],?int $actor=null):int
    {
        return $this->svc()->asiento($actor??$this->owner,$this->d($extra+['fecha'=>$fecha,'concepto'=>'Asiento de ensayo','lineas'=>[['cuenta'=>$debe,'debe'=>$monto],['cuenta'=>$haber,'haber'=>$monto]]]))['recurso_id'];
    }
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    /** Ejecuta SQL directo (sin pasar por el servicio) y devuelve el mensaje con el que la base lo rechaza. */
    private function sqlFalla(string $sql):string
    {$ok=$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}
    private function saldo(string $codigo,string $hasta='2099-12-31'):int
    {
        foreach($this->svc()->saldos('2000-01-01',$hasta) as $s)if($s['codigo']===$codigo)return (int)$s['debe']-(int)$s['haber'];self::fail('Cuenta '.$codigo);
    }

    public function testNoSeRegistraSinInicioContableNiAntesDeEl():void
    {
        $this->db->query('UPDATE erp_cont_config SET inicio_contable=NULL WHERE id=1');
        $this->rechazo(fn()=>$this->asiento('2026-02-01','1.1.01','4.1.01','10.00'),409);
        $this->db->query("UPDATE erp_cont_config SET inicio_contable='2026-01-01' WHERE id=1");
        $this->rechazo(fn()=>$this->asiento('2025-12-31','1.1.01','4.1.01','10.00'),409);
        $this->asiento('2026-01-01','1.1.01','4.1.01','10.00');
        $this->rechazo(fn()=>$this->svc()->inicio($this->owner,$this->d(['fecha'=>'2025-06-01'])),409);
    }
    public function testAsientoBalanceadoSeRegistraConNumeroConsecutivoYAuditoria():void
    {
        $a=$this->asiento('2026-02-03','1.1.01','4.1.01','45.50');$b=$this->asiento('2026-02-04','1.1.02','4.1.02','10.00');
        $fa=$this->r->uno('SELECT * FROM erp_cont_asientos WHERE id=?',[$a]);$fb=$this->r->uno('SELECT * FROM erp_cont_asientos WHERE id=?',[$b]);
        self::assertSame([1,2],[(int)$fa['numero'],(int)$fb['numero']]);self::assertSame(4550,(int)$fa['total_centavos']);self::assertSame('manual',$fa['tipo']);
        self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='cont_asiento_registrado'")['n']);self::assertSame([],$this->svc()->integridad());
        self::assertSame(4550,$this->saldo('1.1.01'));self::assertSame(-4550,$this->saldo('4.1.01'));
    }
    public function testSeRechazaLoQueNoEsPartidaDobleValida():void
    {
        $l=fn(array ...$x)=>$x;
        $casos=[
            'desbalanceado'=>[['cuenta'=>'1.1.01','debe'=>'10.00'],['cuenta'=>'4.1.01','haber'=>'9.99']],
            'una sola línea'=>[['cuenta'=>'1.1.01','debe'=>'10.00']],
            'debe y haber a la vez'=>[['cuenta'=>'1.1.01','debe'=>'10.00','haber'=>'10.00'],['cuenta'=>'4.1.01','haber'=>'10.00']],
            'sin valor'=>[['cuenta'=>'1.1.01'],['cuenta'=>'4.1.01','haber'=>'10.00']],
            'cuenta de agrupación'=>[['cuenta'=>'1.1','debe'=>'10.00'],['cuenta'=>'4.1.01','haber'=>'10.00']],
            'cuenta inexistente'=>[['cuenta'=>'9.9.99','debe'=>'10.00'],['cuenta'=>'4.1.01','haber'=>'10.00']],
        ];
        foreach($casos as $nombre=>$lineas){try{$this->svc()->asiento($this->owner,$this->d(['fecha'=>'2026-02-03','concepto'=>$nombre,'lineas'=>$lineas]));self::fail($nombre);}catch(ErpException|\InvalidArgumentException $e){self::assertContains($e->getCode(),[0,404,422],$nombre.': '.$e->getMessage());}}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);self::assertSame(0,(int)$this->r->uno('SELECT ultimo FROM erp_cont_secuencia')['ultimo'],'Un rechazo no consume número');
        // Cuenta desactivada: tampoco admite movimientos.
        $this->svc()->cuenta($this->owner,$this->d(['codigo'=>'6.1.07','nombre'=>'Gasto de ensayo','tipo'=>'gasto','padre'=>'6']));
        $this->svc()->cuentaEstado($this->owner,$this->d(['cuenta_id'=>$this->cuenta('6.1.07'),'activa'=>'0']));
        $this->rechazo(fn()=>$this->asiento('2026-02-03','6.1.07','1.1.01','1.00'),422);
    }
    public function testElLibroEsInmutableYLaBaseLoGarantiza():void
    {
        $a=$this->asiento('2026-02-03','1.1.01','4.1.01','45.50');
        foreach(["UPDATE erp_cont_asientos SET concepto='x' WHERE id=$a","DELETE FROM erp_cont_asientos WHERE id=$a","UPDATE erp_cont_lineas SET debe_centavos=1 WHERE asiento_id=$a AND nro=1","DELETE FROM erp_cont_lineas WHERE asiento_id=$a"] as $sql)self::assertStringContainsString('reverso',$this->sqlFalla($sql));
        self::assertStringContainsString('movimientos',$this->sqlFalla("UPDATE erp_cont_cuentas SET codigo='9.9' WHERE codigo='1.1.01'"));
        self::assertStringContainsString('desactivan',$this->sqlFalla("DELETE FROM erp_cont_cuentas WHERE codigo='6.1.06'"));
        // Con movimientos el nombre y el estado sí pueden ajustarse; el código y el tipo no.
        $this->r->q("UPDATE erp_cont_cuentas SET nombre='Caja principal' WHERE codigo='1.1.01'");self::assertSame('Caja principal',$this->r->uno("SELECT nombre FROM erp_cont_cuentas WHERE codigo='1.1.01'")['nombre']);
    }
    public function testUnAsientoNoPuedeExistirIncompletoNiDesbalanceadoPorSqlDirecto():void
    {
        $p=$this->r->uno("SELECT id FROM erp_cont_periodos LIMIT 1");$this->asiento('2026-02-03','1.1.01','4.1.01','1.00');$periodo=(int)$this->r->uno('SELECT periodo_id FROM erp_cont_asientos LIMIT 1')['periodo_id'];
        $cab="INSERT INTO erp_cont_asientos(numero,fecha,periodo_id,tipo,origen,huella,concepto,total_centavos,n_lineas,creado_por,creado_en) VALUES(99,'2026-02-10',$periodo,'manual','prueba:desbalance','".str_repeat('a',64)."','x',500,2,1,NOW())";
        $this->r->q($cab);$id=$this->r->id();$c1=$this->cuenta('1.1.01');$c2=$this->cuenta('4.1.01');
        $this->r->q("INSERT INTO erp_cont_lineas(asiento_id,nro,cuenta_id,debe_centavos,haber_centavos) VALUES($id,1,$c1,500,0)");
        self::assertStringContainsString('balanceado',$this->sqlFalla("INSERT INTO erp_cont_lineas(asiento_id,nro,cuenta_id,debe_centavos,haber_centavos) VALUES($id,2,$c2,0,499)"));
        $this->r->q("INSERT INTO erp_cont_lineas(asiento_id,nro,cuenta_id,debe_centavos,haber_centavos) VALUES($id,2,$c2,0,500)");
        self::assertStringContainsString('fuera del asiento',$this->sqlFalla("INSERT INTO erp_cont_lineas(asiento_id,nro,cuenta_id,debe_centavos,haber_centavos) VALUES($id,3,$c2,500,0)"));
        self::assertStringContainsString('período abierto',$this->sqlFalla("INSERT INTO erp_cont_asientos(numero,fecha,periodo_id,tipo,origen,huella,concepto,total_centavos,n_lineas,creado_por,creado_en) VALUES(100,'2027-05-10',$periodo,'manual','prueba:periodo','".str_repeat('b',64)."','x',500,2,1,NOW())"));
        // Un asiento con menos líneas que las anunciadas (cabecera sin cerrar) lo detecta el control de integridad.
        $this->r->q("INSERT INTO erp_cont_asientos(numero,fecha,periodo_id,tipo,origen,huella,concepto,total_centavos,n_lineas,creado_por,creado_en) VALUES(101,'2026-02-11',$periodo,'manual','prueba:incompleto','".str_repeat('c',64)."','x',500,2,1,NOW())");
        self::assertNotSame([],$this->svc()->integridad());
    }
    public function testElMismoHechoNoDuplicaSuAsientoYOtroContenidoSeRechaza():void
    {
        $lineas=[['cuenta_id'=>$this->cuenta('1.1.01'),'debe'=>1000,'haber'=>0],['cuenta_id'=>$this->cuenta('4.1.01'),'debe'=>0,'haber'=>1000]];
        $a=\Classes\Transaccion::ejecutar($this->db,fn()=>$this->svc()->publicar(0,['fecha'=>'2026-03-01','tipo'=>'automatico','origen'=>'evento:77','concepto'=>'Cobro del evento 77','lineas'=>$lineas]));
        $b=\Classes\Transaccion::ejecutar($this->db,fn()=>$this->svc()->publicar(0,['fecha'=>'2026-03-01','tipo'=>'automatico','origen'=>'evento:77','concepto'=>'Cobro del evento 77','lineas'=>$lineas]));
        self::assertSame($a,$b);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);self::assertSame(1,(int)$this->r->uno('SELECT ultimo FROM erp_cont_secuencia')['ultimo']);
        $otro=$lineas;$otro[0]['debe']=2000;$otro[1]['haber']=2000;
        $this->rechazo(fn()=>\Classes\Transaccion::ejecutar($this->db,fn()=>$this->svc()->publicar(0,['fecha'=>'2026-03-01','tipo'=>'automatico','origen'=>'evento:77','concepto'=>'Cobro del evento 77','lineas'=>$otro])),409);
        // Reenvío de un formulario con la clave de idempotencia ya vencida: el origen permanente lo contiene.
        $d=$this->d(['fecha'=>'2026-03-02','concepto'=>'Manual','lineas'=>[['cuenta'=>'1.1.01','debe'=>'5.00'],['cuenta'=>'4.1.01','haber'=>'5.00']]]);
        $this->svc()->asiento($this->owner,$d);$this->r->q('UPDATE operaciones_idempotentes SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');$this->svc()->asiento($this->owner,$d);
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);
    }
    public function testElReversoCorrigeSinBorrarYNoSeRevierteOtraVez():void
    {
        $a=$this->asiento('2026-02-03','1.1.03','4.1.01','30.00',['lineas'=>[['cuenta'=>'1.1.03','debe'=>'30.00','contacto_id'=>$this->cliente],['cuenta'=>'4.1.01','haber'=>'30.00']]]);
        $rv=$this->svc()->reversar($this->owner,$this->d(['asiento_id'=>$a,'motivo'=>'Registrado dos veces','fecha'=>'2026-02-10']))['recurso_id'];
        $f=$this->r->uno('SELECT * FROM erp_cont_asientos WHERE id=?',[$rv]);self::assertSame('reverso',$f['tipo']);self::assertSame($a,(int)$f['reversa_de_id']);
        self::assertSame(0,$this->saldo('1.1.03'));self::assertSame(0,$this->saldo('4.1.01'));self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'],'El original permanece');
        self::assertSame($cliente=$this->cliente,(int)$this->r->uno('SELECT contacto_id FROM erp_cont_lineas WHERE asiento_id=? AND contacto_id IS NOT NULL',[$rv])['contacto_id'],'El reverso conserva el tercero');
        // Otra clave y otro motivo no crean un segundo reverso del mismo asiento; un reverso no se revierte.
        self::assertSame($rv,$this->svc()->reversar($this->owner,$this->d(['asiento_id'=>$a,'motivo'=>'Otra vez']))['recurso_id']);self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);
        $this->rechazo(fn()=>$this->svc()->reversar($this->owner,$this->d(['asiento_id'=>$rv,'motivo'=>'Revertir el reverso'])),409);
        $d=$this->svc()->diario('2026-01-01','2026-12-31');self::assertNotNull($d['asientos'][0]['reversado_por']);
    }
    public function testPeriodoCerradoBloqueaEscrituraYSeCierraYReabreEnOrden():void
    {
        $this->asiento('2026-01-10','1.1.01','4.1.01','10.00');$this->asiento('2026-02-10','1.1.01','4.1.01','10.00');
        $this->rechazo(fn()=>$this->svc()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>2])),409);
        $this->svc()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>1]));
        $e=$this->rechazo(fn()=>$this->asiento('2026-01-20','1.1.01','4.1.01','10.00'),409);self::assertStringContainsString('2026-01',$e->getMessage());
        $this->svc()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>2]));
        $this->rechazo(fn()=>$this->svc()->reabrirPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>1,'motivo'=>'Corregir'])),409);
        $this->svc()->reabrirPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>2,'motivo'=>'Corregir una cuenta']));$this->svc()->reabrirPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>1,'motivo'=>'Corregir otra']));
        $this->asiento('2026-01-20','1.1.01','4.1.01','10.00');
        self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='cont_periodo_reabierto'")['n']);
        // Un reverso de un asiento de un período cerrado se registra en un período abierto.
        $this->svc()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>1]));$a=(int)$this->r->uno('SELECT id FROM erp_cont_asientos ORDER BY id LIMIT 1')['id'];
        $this->svc()->reversar($this->owner,$this->d(['asiento_id'=>$a,'motivo'=>'Ajuste posterior','fecha'=>'2026-02-15']));
    }
    public function testNoSeCierraUnPeriodoConHechosSinContabilizar():void
    {
        $this->r->q("INSERT INTO erp_eventos(tipo,entidad,entidad_id,actor_id,datos_json,creado_en) VALUES('pago_confirmado','venta',1,1,'{}','2026-01-15 10:00:00')");$ev=$this->r->id();
        $this->r->q("INSERT INTO erp_integraciones_outbox(destino,evento_id,referencia,payload_json,estado,actualizado_en) VALUES('contabilidad',?,?,'{}','pendiente',NOW())",[$ev,'ERP-'.$ev.'-contabilidad']);
        $e=$this->rechazo(fn()=>$this->svc()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>1])),409);self::assertStringContainsString('sin contabilizar',$e->getMessage());
        $this->r->q("UPDATE erp_integraciones_outbox SET estado='confirmado' WHERE evento_id=?",[$ev]);$this->svc()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>1]));
    }
    public function testCierreDeEjercicioTrasladaElResultadoYLosEstadosCuadran():void
    {
        $this->asiento('2026-01-10','1.1.01','4.1.01','500.00');$this->asiento('2026-01-12','5.1.01','1.1.04','120.00');$this->asiento('2026-02-01','6.1.05','1.1.01','80.00');
        $res=$this->svc()->estadoResultados('2026-01-01','2026-12-31');self::assertSame([50000,12000,8000],array_values($res['totales']));self::assertSame(38000,$res['utilidad_bruta']);self::assertSame(30000,$res['resultado']);
        $bg=$this->svc()->balanceGeneral('2026-12-31');self::assertTrue($bg['cuadra']);self::assertSame(30000,$bg['resultado_sin_cerrar']);
        $this->rechazo(fn()=>$this->svc()->cerrarEjercicio($this->owner,$this->d(['anio'=>2026])),409);
        for($m=1;$m<=11;$m++)$this->svc()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>$m]));
        $c=$this->svc()->cerrarEjercicio($this->owner,$this->d(['anio'=>2026]))['recurso_id'];$f=$this->r->uno('SELECT * FROM erp_cont_asientos WHERE id=?',[$c]);self::assertSame('cierre',$f['tipo']);self::assertSame('2026-12-31',$f['fecha']);self::assertSame('cierre:2026',$f['origen']);
        self::assertSame($c,$this->svc()->cerrarEjercicio($this->owner,$this->d(['anio'=>2026]))['recurso_id'],'Cerrar dos veces no duplica el cierre');
        $res2=$this->svc()->estadoResultados('2026-01-01','2026-12-31');self::assertSame(30000,$res2['resultado'],'El estado de resultados no cuenta el asiento de cierre');
        $bg=$this->svc()->balanceGeneral('2026-12-31');self::assertTrue($bg['cuadra']);self::assertSame(0,$bg['resultado_sin_cerrar']);self::assertSame(-30000,$this->saldo('3.1.03'));
        self::assertTrue($this->svc()->balanceComprobacion('2026-12-31')['cuadra']);
    }
    public function testMayorYAuxiliarPorTercero():void
    {
        $cxc=fn(string $f,string $m,bool $cobro)=>$this->svc()->asiento($this->owner,$this->d(['fecha'=>$f,'concepto'=>'Cuenta por cobrar','lineas'=>$cobro?[['cuenta'=>'1.1.01','debe'=>$m],['cuenta'=>'1.1.03','haber'=>$m,'contacto_id'=>$this->cliente]]:[['cuenta'=>'1.1.03','debe'=>$m,'contacto_id'=>$this->cliente],['cuenta'=>'4.1.01','haber'=>$m]]]));
        $cxc('2026-01-05','100.00',false);$cxc('2026-01-20','40.00',true);$cxc('2026-02-02','25.00',false);
        $m=$this->svc()->mayor($this->cuenta('1.1.03'),'2026-01-15','2026-03-01');
        self::assertSame(10000,$m['saldo_inicial']);self::assertSame([6000,8500],array_column($m['movimientos'],'saldo'));self::assertSame(8500,$m['saldo_final']);
        $aux=$this->svc()->auxiliar('cxc','2026-12-31');self::assertSame(8500,$aux['total']);self::assertSame('Cliente contable',$aux['filas'][0]['nombre']);
        self::assertSame(6000,$this->svc()->auxiliar('cxc','2026-01-31')['total']);
    }
    public function testPermisosSegregadosPorFuncion():void
    {
        $this->rechazo(fn()=>$this->asiento('2026-02-03','1.1.01','4.1.01','1.00',[],$this->recepcion),403);
        $this->asiento('2026-02-03','1.1.01','4.1.01','1.00',[],$this->finanzas);
        foreach([fn()=>$this->svc()->cerrarPeriodo($this->finanzas,$this->d(['anio'=>2026,'mes'=>2])),fn()=>$this->svc()->cuenta($this->finanzas,$this->d(['codigo'=>'6.1.08','nombre'=>'x','tipo'=>'gasto','padre'=>'6'])),fn()=>$this->svc()->rol($this->finanzas,$this->d(['rol'=>'caja','cuenta'=>'1.1.02'])),fn()=>$this->svc()->inicio($this->finanzas,$this->d(['fecha'=>'2026-01-01']))] as $f)$this->rechazo($f,403);
        $this->rechazo(fn()=>$this->svc()->reversar($this->recepcion,$this->d(['asiento_id'=>1,'motivo'=>'x'])),403);
    }
    public function testRolesYPlanDeCuentasConReglas():void
    {
        $this->rechazo(fn()=>$this->svc()->cuenta($this->owner,$this->d(['codigo'=>'1.1.99','nombre'=>'Mal tipo','tipo'=>'gasto','padre'=>'1.1'])),422);
        $this->rechazo(fn()=>$this->svc()->cuenta($this->owner,$this->d(['codigo'=>'7.7','nombre'=>'Fuera de jerarquía','tipo'=>'activo','padre'=>'1.1'])),422);
        $this->rechazo(fn()=>$this->svc()->cuenta($this->owner,$this->d(['codigo'=>'1.1.01','nombre'=>'Duplicada','tipo'=>'activo','padre'=>'1.1'])),409);
        $this->svc()->cuenta($this->owner,$this->d(['codigo'=>'1.1.07','nombre'=>'Banco Pichincha','tipo'=>'activo','padre'=>'1.1']));
        $this->rechazo(fn()=>$this->svc()->rol($this->owner,$this->d(['rol'=>'banco','cuenta'=>'4.1.01'])),422);
        $this->svc()->rol($this->owner,$this->d(['rol'=>'banco','cuenta'=>'1.1.07']));self::assertSame($this->cuenta('1.1.07'),$this->svc()->rolCuenta('banco'));
        $this->rechazo(fn()=>$this->svc()->cuentaEstado($this->owner,$this->d(['cuenta_id'=>$this->cuenta('1.1.07'),'activa'=>'0'])),409);
        $this->asiento('2026-02-03','1.1.01','4.1.01','5.00');$this->rechazo(fn()=>$this->svc()->cuentaEstado($this->owner,$this->d(['cuenta_id'=>$this->cuenta('1.1.01'),'activa'=>'0'])),409);
    }
    public function testDosProcesosNoRepitenNumeroNiRevierteDosVecesElMismoAsiento():void
    {
        $a=$this->asiento('2026-02-03','1.1.01','4.1.01','5.00');
        $trabajos=[];foreach([0,1,2] as $i)$trabajos[]=['cont_asiento',['fecha'=>'2026-02-0'.(4+$i),'concepto'=>'Competencia','lineas'=>[['cuenta'=>'1.1.01','debe'=>'1.00'],['cuenta'=>'4.1.01','haber'=>'1.00']]]];
        $trabajos[]=['cont_reverso',['asiento_id'=>$a,'motivo'=>'Concurrente 1','fecha'=>'2026-02-10']];$trabajos[]=['cont_reverso',['asiento_id'=>$a,'motivo'=>'Concurrente 2','fecha'=>'2026-02-10']];
        $r=$this->enParalelo($trabajos);
        self::assertCount(5,array_filter($r,fn($x)=>$x['ok']),json_encode($r));
        $numeros=array_map('intval',array_column($this->r->q('SELECT numero FROM erp_cont_asientos ORDER BY numero'),'numero'));self::assertSame(range(1,count($numeros)),$numeros,'Numeración continua y sin repetidos');
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos WHERE reversa_de_id=?',[$a])['n']);self::assertSame([],$this->svc()->integridad());
        self::assertTrue($this->svc()->balanceComprobacion('2026-12-31')['cuadra']);
    }
    private function enParalelo(array $trabajos):array
    {
        $workers=[];$res=[];
        try{
            foreach($trabajos as [$accion,$datos]){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php',$accion,(string)$this->owner,json_encode(['idem_key'=>R5CajaFixture::clave()]+$datos)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],30);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);$j=json_decode((string)$linea,true);self::assertIsArray($j,$err.(string)$linea);$res[]=$j;}
        }finally{foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        return $res;
    }

    // ---- Interfaz y rutas ----
    private function sesion(int $usuario,string $portal='equipo'):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>$portal,'version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-cont'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/contabilidad';
    }
    private function pagina(array $get):string
    {
        $_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::contabilidad(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function estado(callable $f):int {$e=200;ob_start();try{$f();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;}
    public function testPantallasContablesRenderizanYElFormularioRegistraYReversaConAuditoria():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->sesion($this->owner);
            foreach(['resumen','diario','mayor','balances','conciliacion','plan','periodos','asiento'] as $vista)self::assertStringContainsString('Contabilidad propia',$this->pagina(['vista'=>$vista]),$vista);
            $_SERVER['REQUEST_METHOD']='POST';$_POST=['accion'=>'cont_asiento','vista'=>'asiento','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-cont','fecha'=>'2026-03-05','concepto'=>'Aporte inicial de capital','lineas'=>[['cuenta'=>'1.1.02','debe'=>'250.00'],['cuenta'=>'3.1.01','haber'=>'250.00'],['cuenta'=>'','debe'=>'','haber'=>'']]];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);self::assertSame(25000,$this->saldo('1.1.02'));
            $html=$this->pagina(['vista'=>'diario','desde'=>'2026-01-01','hasta'=>'2026-12-31']);self::assertStringContainsString('Aporte inicial de capital',$html);self::assertStringContainsString('Reversar este asiento',$html);
            $a=(int)$this->r->uno('SELECT id FROM erp_cont_asientos')['id'];$_SERVER['REQUEST_METHOD']='POST';$_POST=['accion'=>'cont_reverso','vista'=>'diario','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-cont','asiento_id'=>(string)$a,'motivo'=>'Error de captura','fecha'=>'2026-03-06'];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));self::assertSame(0,$this->saldo('1.1.02'));
            self::assertSame(0,substr_count($this->pagina(['vista'=>'diario','desde'=>'2026-01-01','hasta'=>'2026-12-31']),'Reversar este asiento'),'El original ya figura reversado y un reverso no se revierte: ninguno ofrece el botón');
            $b=$this->pagina(['vista'=>'balances','desde'=>'2026-01-01','hasta'=>'2026-12-31']);self::assertStringContainsString('El balance cuadra',$b);
            // Gerente: lee; finanzas: lee y registra, pero no configura; recepción y portal cliente: nada.
            $gerente=R5CajaFixture::usuario($this->db,'gerente');$this->sesion($gerente);$_SERVER['REQUEST_METHOD']='GET';$h=$this->pagina(['vista'=>'plan']);self::assertStringNotContainsString('Nueva cuenta',$h);self::assertStringNotContainsString('Asiento manual',$this->pagina(['vista'=>'resumen']));
            $this->sesion($this->finanzas);$f=$this->pagina(['vista'=>'resumen']);self::assertStringContainsString('Asiento manual',$f);self::assertStringNotContainsString('Definir inicio',$f);
            $this->sesion($this->recepcion);$_SERVER['REQUEST_METHOD']='GET';self::assertSame(403,$this->estado(fn()=>\Controllers\ErpController::contabilidad(new \MVC\Router())));
            $this->sesion($this->owner,'cliente');self::assertContains($this->estado(fn()=>\Controllers\ErpController::contabilidad(new \MVC\Router())),[302,303,403],'El portal de cliente no entra a la contabilidad aunque la cuenta sea propietaria');self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE concepto='x'")['n']);
            $this->sesion($this->owner,'cliente');$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';$_POST=['accion'=>'cont_asiento','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-cont','fecha'=>'2026-03-05','concepto'=>'x','lineas'=>[]];self::assertContains($this->estado(fn()=>\Controllers\ErpController::accion()),[302,303,403]);self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'],'Solo el asiento original y su reverso');
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
