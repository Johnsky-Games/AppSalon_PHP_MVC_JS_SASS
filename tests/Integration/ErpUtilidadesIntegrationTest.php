<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\{ErpRepository,OperacionRepository};
use Services\{ErpNominaService,ErpUtilidadesService,ErpRhService,ErpContabilidadService,ErpIntegracionService,ErpCajaService,ErpCompraService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/**
 * Utilidades del ejercicio 2025 con porcentajes de ENSAYO (no oficiales): 10 % por días y 5 % por cargas. Ingresos 15 000,00 − gastos 5 000,00 = base 10 000,00.
 * Ana trabajó 365 días y declara 2 cargas; Beto, 184 días y ninguna. Valores calculados aparte: por días 66 485 / 33 515 (mayor resto); por cargas 50 000 para Ana → 116 485 y 33 515 (total 150 000).
 */
final class ErpUtilidadesIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $colA;private int $colB;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('utilidades');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->colA=$this->colaborador('Ana','Pérez',R5CajaFixture::usuario($this->db,'profesional'),'2024-01-01');$this->colB=$this->colaborador('Beto','Ruiz',R5CajaFixture::usuario($this->db,'profesional'),'2025-07-01');
        $rh=new ErpRhService($this->r);
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'empleado','inicio'=>'2024-01-01','fin'=>'2026-09-30','base'=>'600.00','observacion'=>'Contrato de ensayo']));
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colB,'tipo'=>'empleado','inicio'=>'2025-07-01','base'=>'482.00','observacion'=>'Contrato de ensayo']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2025-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function svc():ErpUtilidadesService {return new ErpUtilidadesService($this->r);}
    private function colaborador(string $n,string $a,int $usuario,string $ingreso):int
    {
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,?,?,'profesional','activo',?,NOW() FROM usuarios WHERE id=?",[$n,$a,$ingreso,$usuario]);return $this->r->id();
    }
    private function parametros(array $extra=[]):void
    {
        foreach(['utilidades.pb_trabajadores'=>1000,'utilidades.pb_cargas'=>500]+$extra as $k=>$n)$this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),1,NOW())",[$k,$n]);
    }
    private function asiento(string $fecha,string $debe,string $haber,string $monto):void
    {
        (new ErpContabilidadService($this->r))->asiento($this->owner,$this->d(['fecha'=>$fecha,'concepto'=>'Movimiento de ensayo','lineas'=>[['cuenta'=>$debe,'debe'=>$monto],['cuenta'=>$haber,'haber'=>$monto]]]));
    }
    private function libro():void {$this->asiento('2025-06-30','1.1.01','4.1.01','15000.00');$this->asiento('2025-08-15','6.1.05','1.1.01','5000.00');}
    private function cargas(int $col,int $n):void {$this->svc()->cargas($this->owner,$this->d(['colaborador_id'=>$col,'anio'=>2025,'cargas'=>(string)$n,'fuente'=>'Declaración de cargas de ensayo']));}
    private function calcular(array $x=[],?int $actor=null):int {return $this->svc()->calcular($actor??$this->finanzas,$this->d($x+['anio'=>2025]))['recurso_id'];}
    private function fila(int $id):array {return $this->r->uno('SELECT * FROM erp_nomina_utilidades WHERE id=?',[$id]);}
    private function lineas(int $id):array {$o=[];foreach($this->r->q('SELECT * FROM erp_nomina_utilidades_lineas WHERE utilidad_id=?',[$id]) as $l)$o[(int)$l['colaborador_id']]=$l;return $o;}
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}

    public function testSinLasCondicionesPreviasNoSeCalculaYElMensajeDicePorQue():void
    {
        $this->rechazo(fn()=>$this->calcular([],$this->recepcion),403);
        $e=$this->rechazo(fn()=>$this->calcular(['anio'=>(int)substr(OperacionRepository::ahora(),0,4)]),409);self::assertStringContainsString('aún no termina',$e->getMessage());
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('utilidades.pb_trabajadores',$e->getMessage());self::assertStringContainsString('utilidades.pb_cargas',$e->getMessage());
        $this->parametros();
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('no hay utilidades',$e->getMessage(),'Sin movimientos la base es cero');
        $this->libro();$this->rechazo(fn()=>$this->calcular(['ajuste_mas'=>'100.00']),422);// ajuste sin respaldo
        $this->asiento('2025-09-01','6.1.05','1.1.01','20000.00');$e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('no hay utilidades',$e->getMessage(),'Con pérdida tampoco');
        $this->rechazo(fn()=>$this->svc()->cargas($this->finanzas,$this->d(['colaborador_id'=>$this->colA,'anio'=>2025,'cargas'=>'2','fuente'=>'Declaración de ensayo'])),403);
        $this->rechazo(fn()=>$this->svc()->cargas($this->owner,$this->d(['colaborador_id'=>$this->colA,'anio'=>2025,'cargas'=>'31','fuente'=>'Declaración de ensayo'])),422);
    }
    public function testElRepartoSaleDelLibroSeApruebaYGeneraUnaObligacionPorTrabajadorYElAsiento():void
    {
        $this->parametros();$this->libro();$this->cargas($this->colA,2);
        $id=$this->calcular();$u=$this->fila($id);
        self::assertSame(1000000,(int)$u['resultado_contable_centavos']);self::assertSame(1000000,(int)$u['base_centavos']);self::assertSame(100000,(int)$u['pozo_trabajo_centavos']);self::assertSame(50000,(int)$u['pozo_cargas_centavos']);self::assertSame(150000,(int)$u['total_centavos']);self::assertSame(0,(int)$u['excedente_centavos']);
        $l=$this->lineas($id);self::assertSame(365,(int)$l[$this->colA]['dias']);self::assertSame(184,(int)$l[$this->colB]['dias']);self::assertSame(116485,(int)$l[$this->colA]['monto_centavos']);self::assertSame(33515,(int)$l[$this->colB]['monto_centavos']);
        $s=json_decode($u['snapshot_json'],true);self::assertSame('utilidades-v1',$s['formula']);self::assertSame(1000,$s['parametros']['utilidades.pb_trabajadores']['valor']);
        $this->rechazo(fn()=>$this->calcular(),409);// ya hay un reparto vigente del ejercicio
        $this->rechazo(fn()=>$this->svc()->aprobar($this->finanzas,$this->d(['utilidad_id'=>$id])),403);
        $this->svc()->aprobar($this->owner,$this->d(['utilidad_id'=>$id]));self::assertSame('aprobada',$this->fila($id)['estado']);
        self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_obligaciones WHERE origen='utilidades'")['n']);
        $o=$this->r->uno("SELECT monto_centavos m FROM erp_obligaciones WHERE origen='utilidades' AND colaborador_id=?",[$this->colA]);self::assertSame(116485,(int)$o['m']);
        $this->svc()->aprobar($this->owner,$this->d(['utilidad_id'=>$id]));self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_obligaciones WHERE origen='utilidades'")['n'],'Aprobar otra vez no duplica');
        $cont=new ErpContabilidadService($this->r);$a=new ErpContabilidadAdaptador($this->r);
        self::assertSame(0,(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100)['fallidos']);
        $saldo=function(string $rol)use($cont):int{$f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?',[$cont->rolCuenta($rol)]);return (ErpContabilidadService::ROLES[$rol]==='activo'||str_starts_with($rol,'gasto'))?(int)$f['d']-(int)$f['h']:(int)$f['h']-(int)$f['d'];};
        self::assertSame(150000,$saldo('gasto_utilidades'));self::assertSame(150000,$saldo('sueldos_por_pagar'));
        $as=$this->r->uno("SELECT fecha FROM erp_cont_asientos WHERE concepto LIKE 'Participación de trabajadores%'");self::assertSame('2025-12-31',$as['fecha'],'El gasto se asienta al cierre del ejercicio que lo genera');
        self::assertSame(850000,$cont->estadoResultados('2025-01-01','2025-12-31')['resultado']);self::assertTrue($cont->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$cont->integridad());
        $conc=(int)$this->r->uno("SELECT COALESCE(SUM(monto_centavos-pagado_centavos-ajuste_centavos),0) n FROM erp_obligaciones WHERE origen='utilidades'")['n'];self::assertSame($conc,$saldo('sueldos_por_pagar'));
        foreach(["UPDATE erp_nomina_utilidades SET total_centavos=1","DELETE FROM erp_nomina_utilidades","UPDATE erp_nomina_utilidades_lineas SET monto_centavos=1","DELETE FROM erp_nomina_utilidades_lineas","UPDATE erp_nomina_cargas SET cargas=9","DELETE FROM erp_nomina_cargas"] as $sql)self::assertFalse($this->db->query($sql),$sql);
        // Anular antes de pagar revierte todo; el reparto se puede recalcular con la misma base (el gasto de utilidades no entra en la base).
        $this->svc()->anular($this->owner,$this->d(['utilidad_id'=>$id,'motivo'=>'Ajuste pendiente del contador']));
        self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_obligaciones WHERE origen='utilidades' AND monto_centavos-pagado_centavos-ajuste_centavos>0")['n']);
        (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);self::assertSame(0,$saldo('gasto_utilidades'));self::assertSame(0,$saldo('sueldos_por_pagar'));
        $id2=$this->calcular();self::assertSame(1000000,(int)$this->fila($id2)['base_centavos']);self::assertNotSame($id,$id2);
    }
    public function testSinCuentaAsignadaAlRolDeGastoNoSeCalculaYElMensajeDicePorQue():void
    {
        $this->parametros();$this->libro();
        self::assertSame('6.1.90',$this->r->uno("SELECT c.codigo FROM erp_cont_roles r JOIN erp_cont_cuentas c ON c.id=r.cuenta_id WHERE r.rol='gasto_utilidades'")['codigo'],'La migración siembra la cuenta y su rol');
        $this->r->q("DELETE FROM erp_cont_roles WHERE rol='gasto_utilidades'");
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('gasto_utilidades',$e->getMessage());
    }
    public function testElTopeEnSbuDejaUnExcedenteSinPagarYLosAjustesModificanLaBase():void
    {
        $this->parametros(['utilidades.tope_sbu'=>2,'sbu_centavos'=>48200]);$this->libro();$this->cargas($this->colA,2);
        $u=$this->fila($this->calcular());$l=$this->lineas((int)$u['id']);
        self::assertSame(96400,(int)$u['tope_centavos']);self::assertSame(96400,(int)$l[$this->colA]['monto_centavos']);self::assertSame(20085,(int)$l[$this->colA]['excedente_centavos']);self::assertSame(33515,(int)$l[$this->colB]['monto_centavos']);
        self::assertSame(96400+33515,(int)$u['total_centavos']);self::assertSame(20085,(int)$u['excedente_centavos']);
        // Con ajustes del contador (con respaldo): +500,00 y −100,00 → base 10 400,00; 10 % = 1 040,00.
        $this->svc()->anular($this->owner,$this->d(['utilidad_id'=>(int)$u['id'],'motivo'=>'Se agregan ajustes']));
        $u2=$this->fila($this->calcular(['ajuste_mas'=>'500.00','ajuste_menos'=>'100.00','nota'=>'Conciliación tributaria del contador']));
        self::assertSame(1040000,(int)$u2['base_centavos']);self::assertSame(104000,(int)$u2['pozo_trabajo_centavos']);self::assertSame(52000,(int)$u2['pozo_cargas_centavos']);
    }
    public function testSiElLibroCambiaOHayHechosPendientesNoSeApruebaYConPagoNoSeAnula():void
    {
        $this->parametros();$this->libro();$id=$this->calcular();
        $this->asiento('2025-10-01','1.1.01','4.1.01','100.00');
        $e=$this->rechazo(fn()=>$this->svc()->aprobar($this->owner,$this->d(['utilidad_id'=>$id])),409);self::assertStringContainsString('cambió',$e->getMessage());
        $this->svc()->anular($this->owner,$this->d(['utilidad_id'=>$id,'motivo'=>'Cambió el libro']));
        $id2=$this->calcular();$this->svc()->aprobar($this->owner,$this->d(['utilidad_id'=>$id2]));
        (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>new ErpContabilidadAdaptador($this->r)],100);
        $caja=new ErpCajaService($this->r);$caja->abrir($this->owner,$this->d(['fondo'=>'0']));
        $ob=$this->r->uno("SELECT id,monto_centavos m FROM erp_obligaciones WHERE origen='utilidades' AND colaborador_id=?",[$this->colB]);
        (new ErpCompraService($this->r))->pagar($this->owner,$this->d(['obligacion_id'=>(int)$ob['id'],'monto'=>number_format(((int)$ob['m'])/100,2,'.',''),'metodo'=>'transferencia','referencia'=>'TRF-UTIL-1']));
        $this->rechazo(fn()=>$this->svc()->anular($this->owner,$this->d(['utilidad_id'=>$id2,'motivo'=>'Intento tardío'])),409);
    }

    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-ut'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/nomina';$_SERVER['REQUEST_METHOD']='GET';
    }
    private function pagina(array $get):string {$_GET=$get;ob_start();try{\Controllers\ErpController::nomina(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}}
    public function testLaPantallaMuestraElRepartoYSoloQuienApruebaVeLosBotones():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'srv'=>$_SERVER];
        try{
            $this->parametros();$this->libro();$this->cargas($this->colA,2);$this->calcular();
            $this->sesion($this->owner);$h=$this->pagina(['vista'=>'utilidades']);
            self::assertStringContainsString('Calcular el reparto de utilidades',$h);self::assertStringContainsString('Ejercicio 2025',$h);self::assertStringContainsString('Ana Pérez',$h);self::assertStringContainsString('$1,164.85',$h);self::assertStringContainsString('Aprobar: generar obligaciones y asiento',$h);
            self::assertStringContainsString('utilidades.pb_cargas',$this->pagina(['vista'=>'parametros']));
            $this->sesion($this->finanzas);$f=$this->pagina(['vista'=>'utilidades']);self::assertStringContainsString('Ana Pérez',$f);self::assertStringNotContainsString('Aprobar: generar obligaciones y asiento',$f);
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
