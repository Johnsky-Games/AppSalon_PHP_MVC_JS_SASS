<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\{ErpRepository,OperacionRepository};
use Services\{ErpNominaService,ErpFiniquitoService,ErpRhService,ErpContabilidadService,ErpIntegracionService,ErpCajaService,ErpCompraService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/**
 * Finiquito con parámetros de ENSAYO (no oficiales). Colaboradora A: ingreso 2024-01-01, contrato de 600,00 terminado el 2026-09-30 → 1 004 días de servicio (3 años computados).
 * Valores esperados calculados aparte: vacaciones 82 520 (41,26 d) o 52 520 (con 15 d gozados); desahucio 45 000; indemnización 3 meses = 180 000; décimos de agosto y setiembre: 2 × 5 000 y 2 × 4 017.
 */
final class ErpFiniquitoIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $colA;private int $colB;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('finiquito');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->colA=$this->colaborador('Ana','Pérez',R5CajaFixture::usuario($this->db,'profesional'),'2024-01-01');$this->colB=$this->colaborador('Beto','Ruiz',R5CajaFixture::usuario($this->db,'profesional'),'2026-10-16');
        $rh=new ErpRhService($this->r);
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'empleado','inicio'=>'2024-01-01','fin'=>'2026-09-30','base'=>'600.00','observacion'=>'Contrato de ensayo']));
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colB,'tipo'=>'empleado','inicio'=>'2026-10-16','base'=>'482.00','observacion'=>'Contrato de ensayo']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        $this->parametros(false);
        $this->nomina()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
        $this->nomina()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colB,'vigente_desde'=>'2026-10-16','region'=>'costa_galapagos','mensualiza_d13'=>'1','mensualiza_d14'=>'1','fondos_reserva'=>'mensualizado','aporta_iess'=>'1']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function hoy():string {return substr(OperacionRepository::ahora(),0,10);}
    private function nomina():ErpNominaService {return new ErpNominaService($this->r);}
    private function fin():ErpFiniquitoService {return new ErpFiniquitoService($this->r);}
    private function colaborador(string $n,string $a,int $usuario,string $ingreso):int
    {
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,?,?,'profesional','activo',?,NOW() FROM usuarios WHERE id=?",[$n,$a,$ingreso,$usuario]);return $this->r->id();
    }
    private function parametros(bool $finiquito=true,bool $nomina=true):void
    {
        $v=$nomina?['sbu_centavos'=>48200,'iess.aporte_personal_pb'=>945,'iess.aporte_patronal_pb'=>1115,'fondos_reserva.pb'=>833,'fondos_reserva.dias_antiguedad'=>365,'recargo.nocturno_pb'=>2500,'recargo.suplementaria_pb'=>5000,'recargo.extraordinaria_pb'=>10000,'jornada.horas_mes'=>240]:[];
        if($finiquito)$v+=['vacaciones.dias_anuales'=>15,'vacaciones.dias_base_anio'=>365,'desahucio.pb'=>2500,'despido.meses_hasta_umbral'=>3,'despido.umbral_anios'=>3,'despido.meses_por_anio'=>1,'despido.max_meses'=>25];
        foreach($v as $k=>$n)$this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),1,NOW())",[$k,$n]);
    }
    private function corrida(int $mes):int
    {
        $k=$this->nomina()->calcular($this->finanzas,$this->d(['anio'=>2026,'mes'=>$mes]))['recurso_id'];
        $this->nomina()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->nomina()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));return $k;
    }
    private function calcular(array $x=[],?int $actor=null):int {return $this->fin()->calcular($actor??$this->finanzas,$this->d($x+['colaborador_id'=>$this->colA,'fecha_salida'=>'2026-09-30','causa'=>'renuncia','desahucio'=>'0']))['recurso_id'];}
    private function fila(int $id):array {return $this->r->uno('SELECT * FROM erp_nomina_finiquitos WHERE id=?',[$id]);}
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}

    public function testSinLasCondicionesPrevias409ConElMensajeQueDicePorQue():void
    {
        $this->rechazo(fn()=>$this->calcular([],$this->recepcion),403);
        $this->rechazo(fn()=>$this->calcular(['fecha_salida'=>date('Y-m-d',strtotime($this->hoy().' +2 days'))]),422);
        $this->rechazo(fn()=>$this->calcular(['causa'=>'inventada']),422);
        // Relación todavía abierta: se termina primero en Recursos Humanos.
        $e=$this->rechazo(fn()=>$this->calcular(['colaborador_id'=>$this->colB,'fecha_salida'=>$this->hoy()]),409);self::assertStringContainsString('Termina primero la relación',$e->getMessage());
        // Parámetros de finiquito ausentes: el mensaje los lista todos y solo ellos.
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('desahucio.pb',$e->getMessage());self::assertStringContainsString('vacaciones.dias_anuales',$e->getMessage());self::assertStringNotContainsString('sbu_centavos',$e->getMessage());
        $this->parametros(true,false);
        // Sin la nómina del mes de salida aprobada.
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('2026-09',$e->getMessage());
        $this->corrida(8);$e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('2026-09',$e->getMessage(),'Agosto aprobado no basta');
        $this->corrida(9);self::assertGreaterThan(0,$this->calcular());
    }
    public function testElCalculoSumaDecimosVacacionesDesahucioEIndemnizacionYAprobarGeneraUnaObligacion():void
    {
        $this->corrida(8);$this->corrida(9);$this->parametros(true,false);
        $id=$this->calcular();$f=$this->fila($id);
        self::assertSame(1004,(int)$f['dias_servicio']);self::assertSame(3,(int)$f['anios_computados']);self::assertSame(10000,(int)$f['d13_centavos']);self::assertSame(8034,(int)$f['d14_centavos']);self::assertSame(82520,(int)$f['vacaciones_centavos']);self::assertSame(0,(int)$f['desahucio_centavos']);self::assertSame(0,(int)$f['indemnizacion_centavos']);self::assertSame(100554,(int)$f['total_centavos']);
        $s=json_decode($f['snapshot_json'],true);self::assertSame('finiquito-v1',$s['formula']);self::assertCount(2,$s['decimo_tercero']);self::assertCount(7,$s['parametros']);self::assertContains('fraccion_de_anio_cuenta_completa',$s['supuestos']);
        $this->rechazo(fn()=>$this->calcular(),409);// ya hay uno vigente
        $this->rechazo(fn()=>$this->fin()->aprobar($this->finanzas,$this->d(['finiquito_id'=>$id])),403);
        $this->fin()->aprobar($this->owner,$this->d(['finiquito_id'=>$id]));$f=$this->fila($id);self::assertSame('aprobado',$f['estado']);
        $o=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=?',[(int)$f['obligacion_id']]);self::assertSame('finiquito',$o['origen']);self::assertSame(100554,(int)$o['monto_centavos']);self::assertSame($this->colA,(int)$o['colaborador_id']);
        self::assertSame(4,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_nomina_finiquito_lineas WHERE finiquito_id=? AND activa=1',[$id])['n']);
        // Idempotencia: aprobar otra vez no duplica.
        $this->fin()->aprobar($this->owner,$this->d(['finiquito_id'=>$id]));self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_obligaciones WHERE origen='finiquito'")['n']);
        // Los décimos incluidos ya no se pueden liquidar por separado (no se pagan dos veces).
        self::assertSame([],$this->nomina()->saldosBeneficios());
        $e=$this->rechazo(fn()=>$this->nomina()->liquidarBeneficio($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'decimo_tercero'])),409);self::assertStringContainsString('No hay beneficios',$e->getMessage());
        // El registro no se edita ni se borra; el vínculo solo se libera.
        foreach(["UPDATE erp_nomina_finiquitos SET total_centavos=1","UPDATE erp_nomina_finiquitos SET d13_centavos=0","DELETE FROM erp_nomina_finiquitos","UPDATE erp_nomina_finiquito_lineas SET monto_centavos=1","DELETE FROM erp_nomina_finiquito_lineas"] as $sql)self::assertFalse($this->db->query($sql),$sql);
    }
    public function testDespidoIntempestivoConDesahuciosOtrosIngresosYDescuentosConRespaldoYAsientoBalanceado():void
    {
        $this->corrida(8);$this->corrida(9);$this->parametros(true,false);
        $this->rechazo(fn()=>$this->calcular(['causa'=>'despido_intempestivo','desahucio'=>'1','otros_ingresos'=>'10.00']),422);// sin respaldo
        $this->rechazo(fn()=>$this->calcular(['causa'=>'fin_plazo','desahucio'=>'1']),409);
        $id=$this->calcular(['causa'=>'despido_intempestivo','desahucio'=>'1','vacaciones_tomadas'=>'15','otros_ingresos'=>'10.00','descuentos'=>'25.00','nota'=>'Bono de cierre y anticipo pendiente']);$f=$this->fila($id);
        self::assertSame(52520,(int)$f['vacaciones_centavos']);self::assertSame(45000,(int)$f['desahucio_centavos']);self::assertSame(180000,(int)$f['indemnizacion_centavos']);self::assertSame(294054,(int)$f['total_centavos']);
        $this->fin()->aprobar($this->owner,$this->d(['finiquito_id'=>$id]));
        $cont=new ErpContabilidadService($this->r);$a=new ErpContabilidadAdaptador($this->r);
        $x=(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);self::assertSame(0,$x['fallidos']);
        $saldo=function(string $rol)use($cont):int{$f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?',[$cont->rolCuenta($rol)]);return (ErpContabilidadService::ROLES[$rol]==='activo'||str_starts_with($rol,'gasto'))?(int)$f['d']-(int)$f['h']:(int)$f['h']-(int)$f['d'];};
        self::assertSame(0,$saldo('beneficios_por_pagar'),'Los décimos provisionados quedan saldados por el finiquito');self::assertSame(2500,$saldo('descuentos_nomina'));
        $o=(int)$this->r->uno("SELECT COALESCE(SUM(monto_centavos-pagado_centavos-ajuste_centavos),0) n FROM erp_obligaciones WHERE origen IN('nomina','finiquito')")['n'];self::assertSame($o,$saldo('sueldos_por_pagar'),'Lo debido a los colaboradores concilia con las obligaciones');
        self::assertTrue($cont->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$cont->integridad());
        // Anular antes de pagar: cancela la obligación, libera los décimos y revierte el asiento.
        $this->fin()->anular($this->owner,$this->d(['finiquito_id'=>$id,'motivo'=>'Fecha de salida mal capturada']));
        self::assertSame('anulado',$this->fila($id)['estado']);self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_nomina_finiquito_lineas WHERE activa=1')['n']);
        (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);
        self::assertSame(0,$saldo('descuentos_nomina'));self::assertSame(18034,$saldo('beneficios_por_pagar'),'La provisión vuelve a estar pendiente');self::assertTrue($cont->balanceComprobacion('2099-12-31')['cuadra']);
        $n=$this->nomina()->saldosBeneficios();self::assertSame(10000,(int)$n[0]['d13']);
        // Se puede recalcular y volver a aprobar.
        $id2=$this->calcular();$this->fin()->aprobar($this->owner,$this->d(['finiquito_id'=>$id2]));self::assertNotSame($id,$id2);
    }
    public function testSiLosDecimosCambianEntreElCalculoYLaAprobacionNoSeApruebaYConPagoNoSeAnula():void
    {
        $this->corrida(8);$this->corrida(9);$this->parametros(true,false);$id=$this->calcular();
        $this->nomina()->liquidarBeneficio($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'decimo_tercero']));
        $e=$this->rechazo(fn()=>$this->fin()->aprobar($this->owner,$this->d(['finiquito_id'=>$id])),409);self::assertStringContainsString('cambiaron',$e->getMessage());
        $this->fin()->anular($this->owner,$this->d(['finiquito_id'=>$id,'motivo'=>'Se liquidó el décimo aparte']));
        $id2=$this->calcular();self::assertSame(0,(int)$this->fila($id2)['d13_centavos']);self::assertSame(8034,(int)$this->fila($id2)['d14_centavos']);
        $this->fin()->aprobar($this->owner,$this->d(['finiquito_id'=>$id2]));
        $caja=new ErpCajaService($this->r);$caja->abrir($this->owner,$this->d(['fondo'=>'0']));
        $ob=(int)$this->fila($id2)['obligacion_id'];$monto=number_format(((int)$this->fila($id2)['total_centavos'])/100,2,'.','');
        (new ErpCompraService($this->r))->pagar($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>$monto,'metodo'=>'transferencia','referencia'=>'TRF-FIN-1']));
        $this->rechazo(fn()=>$this->fin()->anular($this->owner,$this->d(['finiquito_id'=>$id2,'motivo'=>'Intento tardío'])),409);
    }

    // ---- Interfaz ----
    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-fin'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/nomina';$_SERVER['REQUEST_METHOD']='GET';
    }
    private function pagina(array $get):string {$_GET=$get;ob_start();try{\Controllers\ErpController::nomina(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}}
    public function testLaPantallaMuestraElDesgloseYSoloQuienApruebaVeLosBotones():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'srv'=>$_SERVER];
        try{
            $this->corrida(8);$this->corrida(9);$this->parametros(true,false);$this->calcular(['causa'=>'despido_intempestivo','desahucio'=>'1']);
            $this->sesion($this->owner);$h=$this->pagina(['vista'=>'finiquitos']);
            self::assertStringContainsString('Calcular un finiquito',$h);self::assertStringContainsString('Ana Pérez',$h);self::assertStringContainsString('despido_intempestivo',$h);self::assertStringContainsString('Aprobar: generar obligación y asiento',$h);
            self::assertStringContainsString('desahucio.pb',$this->pagina(['vista'=>'parametros']),'Los parámetros de finiquito se registran en la misma pantalla');
            $this->sesion($this->finanzas);$f=$this->pagina(['vista'=>'finiquitos']);self::assertStringContainsString('Ana Pérez',$f);self::assertStringNotContainsString('Aprobar: generar obligación y asiento',$f);
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
