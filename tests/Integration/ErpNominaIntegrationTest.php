<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpNominaService,ErpRhService,ErpContabilidadService,ErpIntegracionService,ErpCompraService,ErpCajaService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/**
 * Nómina propia: parámetros legales versionados (aquí con VALORES DE ENSAYO, no oficiales), cálculo exacto en centavos, revisión y aprobación
 * segregadas, obligaciones, comisión por nómina sin doble pago, anulación, beneficios acumulados, recibos y contabilización balanceada.
 */
final class ErpNominaIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $colA;private int $colB;private int $usuarioA;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('nomina');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');$this->usuarioA=R5CajaFixture::usuario($this->db,'profesional');
        $this->colA=$this->colaborador('Ana','Pérez',$this->usuarioA,'2024-01-01');$this->colB=$this->colaborador('Beto','Ruiz',R5CajaFixture::usuario($this->db,'profesional'),'2026-10-16');
        $rh=new ErpRhService($this->r);
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'empleado','inicio'=>'2024-01-01','base'=>'600.00','observacion'=>'Contrato de ensayo']));
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colB,'tipo'=>'empleado','inicio'=>'2026-10-16','base'=>'482.00','observacion'=>'Contrato de ensayo']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function svc():ErpNominaService {return new ErpNominaService($this->r);}
    private function colaborador(string $n,string $a,int $usuario,string $ingreso):int
    {
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,?,?,'profesional','activo',?,NOW() FROM usuarios WHERE id=?",[$n,$a,$ingreso,$usuario]);return $this->r->id();
    }
    /** Valores de ENSAYO (no oficiales) insertados por SQL para no depender del servicio probado. */
    private function parametros(array $omitir=[]):void
    {
        $v=['sbu_centavos'=>48200,'iess.aporte_personal_pb'=>945,'iess.aporte_patronal_pb'=>1115,'fondos_reserva.pb'=>833,'fondos_reserva.dias_antiguedad'=>365,'recargo.nocturno_pb'=>2500,'recargo.suplementaria_pb'=>5000,'recargo.extraordinaria_pb'=>10000,'jornada.horas_mes'=>240];
        foreach($v as $k=>$n){if(in_array($k,$omitir,true))continue;$this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),1,NOW())",[$k,$n]);}
    }
    private function condiciones():void
    {
        $this->svc()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
        $this->svc()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colB,'vigente_desde'=>'2026-10-16','region'=>'costa_galapagos','mensualiza_d13'=>'1','mensualiza_d14'=>'1','fondos_reserva'=>'mensualizado','aporta_iess'=>'1']));
    }
    private function novedad(array $x,?int $actor=null):int {return $this->svc()->novedad($actor??$this->finanzas,$this->d($x+['colaborador_id'=>$this->colA,'anio'=>2026,'mes'=>10,'motivo'=>'Registro de ensayo']))['recurso_id'];}
    private function aprobarNovedad(int $id):void {$this->svc()->resolverNovedad($this->owner,$this->d(['novedad_id'=>$id,'decision'=>'aprobar']));}
    private function escenario():void
    {
        $this->parametros();$this->condiciones();
        foreach([['tipo'=>'hora_suplementaria','cantidad'=>'10'],['tipo'=>'hora_nocturna','cantidad'=>'8'],['tipo'=>'bono','monto'=>'20.00'],['tipo'=>'descuento','monto'=>'15.00'],['tipo'=>'retencion_ir','monto'=>'5.00']] as $x)$this->aprobarNovedad($this->novedad($x));
    }
    private function calcular(?int $actor=null):int {return $this->svc()->calcular($actor??$this->finanzas,$this->d(['anio'=>2026,'mes'=>10]))['recurso_id'];}
    private function linea(int $corrida,int $col):array {return $this->r->uno('SELECT * FROM erp_nomina_lineas WHERE corrida_id=? AND colaborador_id=?',[$corrida,$col]);}
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function sqlFalla(string $sql):string {$ok=$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}

    public function testSinParametrosOCondicionesNoSeCalculaYElMensajeDicePorQue():void
    {
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('sbu_centavos',$e->getMessage());
        $this->parametros(['iess.aporte_patronal_pb']);$e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('iess.aporte_patronal_pb',$e->getMessage());self::assertStringNotContainsString('sbu_centavos',$e->getMessage());
        $this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES('iess.aporte_patronal_pb','2000-01-01','vigente',1115,'Valor de ensayo (no oficial)',CURDATE(),1,NOW())");
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('condiciones de nómina',$e->getMessage());
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_nomina_corridas')['n']);
    }
    public function testParametrosYCondicionesSonVersionadosAuditadosYConPermiso():void
    {
        $d=['clave'=>'sbu_centavos','valor'=>'482.00','vigente_desde'=>date('Y-m-d'),'consultado_en'=>date('Y-m-d'),'fuente'=>'Acuerdo ministerial de ensayo','fuente_url'=>'https://www.trabajo.gob.ec/ejemplo'];
        $this->rechazo(fn()=>$this->svc()->parametro($this->finanzas,$this->d($d)),403);
        $id=$this->svc()->parametro($this->owner,$this->d($d))['recurso_id'];self::assertSame(48200,(int)$this->r->uno('SELECT valor_entero FROM erp_nomina_parametros WHERE id=?',[$id])['valor_entero']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='nomina_parametro_registrado'")['n']);
        $this->rechazo(fn()=>$this->svc()->parametro($this->owner,$this->d($d)),409);
        $this->rechazo(fn()=>$this->svc()->parametro($this->owner,$this->d(['clave'=>'iess.aporte_personal_pb','valor'=>'150']+$d)),422);
        $this->rechazo(fn()=>$this->svc()->parametro($this->owner,$this->d(['clave'=>'otro']+$d)),422);
        self::assertStringContainsString('versión',$this->sqlFalla("UPDATE erp_nomina_parametros SET valor_entero=1 WHERE id=$id"));
        $this->svc()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
        $this->rechazo(fn()=>$this->svc()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1'])),409);
    }
    public function testElCalculoEsExactoEnCentavosParaMesCompletoYMesParcial():void
    {
        $this->escenario();$k=$this->calcular();$a=$this->linea($k,$this->colA);$b=$this->linea($k,$this->colB);
        // Ana: 600,00; 10 h suplementarias (+50 %) = 37,50; 8 h nocturnas (+25 %) = 5,00; bono 20,00; sin comisiones.
        $esperadoA=['dias'=>30,'sueldo_centavos'=>60000,'horas_centavos'=>4250,'bonos_centavos'=>2000,'comisiones_centavos'=>0,'ingresos_centavos'=>66250,'gravado_iess_centavos'=>66250,'iess_personal_centavos'=>6261,'iess_patronal_centavos'=>7387,'ir_centavos'=>500,'descuentos_centavos'=>1500,'neto_centavos'=>57989,'d13_prov_centavos'=>5521,'d14_prov_centavos'=>4017,'fondos_acum_centavos'=>5519,'d13_mensual_centavos'=>0,'d14_mensual_centavos'=>0,'fondos_mensual_centavos'=>0];
        foreach($esperadoA as $c=>$v)self::assertSame($v,(int)$a[$c],'Ana · '.$c);
        // Beto: ingresó el 16 de octubre (16 días), con décimos mensualizados y sin fondos de reserva (menos de un año).
        $esperadoB=['dias'=>16,'sueldo_centavos'=>25707,'d13_mensual_centavos'=>2142,'d14_mensual_centavos'=>2142,'fondos_mensual_centavos'=>0,'ingresos_centavos'=>29991,'iess_personal_centavos'=>2429,'neto_centavos'=>27562,'d13_prov_centavos'=>0,'d14_prov_centavos'=>0,'fondos_acum_centavos'=>0];
        foreach($esperadoB as $c=>$v)self::assertSame($v,(int)$b[$c],'Beto · '.$c);
        $snap=json_decode($a['snapshot_json'],true);self::assertSame('nomina-v1',$snap['formula']);self::assertSame(48200,$snap['parametros']['sbu_centavos']['valor']);self::assertCount(5,$snap['novedades']);
        // Las novedades no se consumen al calcular; una novedad sin aprobar no entra.
        self::assertSame(5,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_nomina_novedades WHERE estado='aprobada'")['n']);
    }
    public function testUnaNovedadSinAprobarNoEntraYFinanzasPreparaPeroNoAprueba():void
    {
        $this->parametros();$this->condiciones();$n=$this->novedad(['tipo'=>'bono','monto'=>'100.00']);
        $this->rechazo(fn()=>$this->svc()->resolverNovedad($this->finanzas,$this->d(['novedad_id'=>$n,'decision'=>'aprobar'])),403);
        $k=$this->calcular();self::assertSame(0,(int)$this->linea($k,$this->colA)['bonos_centavos'],'Pendiente: no cuenta');
        $propia=$this->novedad(['tipo'=>'bono','monto'=>'5.00'],$this->owner);$this->svc()->resolverNovedad($this->owner,$this->d(['novedad_id'=>$propia,'decision'=>'aprobar']));
        self::assertSame('aprobada',$this->r->uno('SELECT estado FROM erp_nomina_novedades WHERE id=?',[$propia])['estado'],'El propietario puede aprobar la suya; queda auditado');
        self::assertStringContainsString('novedad',$this->sqlFalla("UPDATE erp_nomina_novedades SET monto_centavos=1 WHERE id=$n"));self::assertStringContainsString('rechazan',$this->sqlFalla("DELETE FROM erp_nomina_novedades WHERE id=$n"));
    }
    public function testRevisionYAprobacionSegregadasGeneranObligacionesYElHechoContable():void
    {
        $this->escenario();$k=$this->calcular();
        $this->rechazo(fn()=>$this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k])),409);
        $this->rechazo(fn()=>$this->svc()->revisar($this->recepcion,$this->d(['corrida_id'=>$k])),403);
        $this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));self::assertSame('revisada',$this->r->uno('SELECT estado FROM erp_nomina_corridas WHERE id=?',[$k])['estado']);
        $this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));
        $c=$this->r->uno('SELECT * FROM erp_nomina_corridas WHERE id=?',[$k]);self::assertSame('aprobada',$c['estado']);$t=json_decode($c['totales_json'],true);
        self::assertSame(57989+27562,$t['neto']);self::assertSame(6261+7387+5519+2429+2866,$t['iess'],'IESS: personal y patronal de ambos más los fondos de reserva acumulados de Ana');
        $obl=$this->r->q("SELECT * FROM erp_obligaciones WHERE origen='nomina' ORDER BY colaborador_id");self::assertCount(2,$obl);self::assertSame(57989,(int)$obl[0]['monto_centavos']);self::assertSame(27562,(int)$obl[1]['monto_centavos']);
        $iess=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=?',[(int)$c['obligacion_iess_id']]);self::assertSame('iess',$iess['origen']);self::assertSame($t['iess'],(int)$iess['monto_centavos']);
        $ir=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=?',[(int)$c['obligacion_ir_id']]);self::assertSame(500,(int)$ir['monto_centavos']);
        self::assertSame(5,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_nomina_novedades WHERE estado='consumida' AND corrida_id=?",[$k])['n']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='nomina_aprobada'")['n']);
        // Reintento de la misma aprobación: no duplica obligaciones ni eventos.
        $this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_obligaciones WHERE origen='nomina'")['n']);
        // Una línea aprobada y la corrida son inmutables.
        $lid=(int)$this->linea($k,$this->colA)['id'];
        foreach(["UPDATE erp_nomina_lineas SET neto_centavos=1 WHERE id=$lid","DELETE FROM erp_nomina_lineas WHERE id=$lid","UPDATE erp_nomina_corridas SET estado='calculada' WHERE id=$k","DELETE FROM erp_nomina_corridas WHERE id=$k"] as $sql)$this->sqlFalla($sql);
        // Un segundo cálculo del mes no es posible mientras la corrida esté activa.
        $this->rechazo(fn()=>$this->calcular(),409);
    }
    public function testElAsientoDeNominaBalanceaSeReversaAlAnularYLasObligacionesSePaganPorSuOrigen():void
    {
        $this->escenario();$k=$this->calcular();$this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));
        $cont=new ErpContabilidadService($this->r);$a=new ErpContabilidadAdaptador($this->r);
        $x=(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);self::assertSame(0,$x['fallidos']);
        $saldo=function(string $rol)use($cont):int{$f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?',[$cont->rolCuenta($rol)]);return (ErpContabilidadService::ROLES[$rol]==='activo'||str_starts_with($rol,'gasto'))?(int)$f['d']-(int)$f['h']:(int)$f['h']-(int)$f['d'];};
        self::assertSame(57989+27562,$saldo('sueldos_por_pagar'));self::assertSame(500,$saldo('ir_por_pagar'));self::assertSame(1500,$saldo('descuentos_nomina'));self::assertSame(5521+4017,$saldo('beneficios_por_pagar'));
        $iess=(int)json_decode($this->r->uno('SELECT totales_json FROM erp_nomina_corridas WHERE id=?',[$k])['totales_json'],true)['iess'];self::assertSame($iess,$saldo('iess_por_pagar'));
        self::assertSame(66250+25707,$saldo('gasto_sueldos'));self::assertSame(7387+2866,$saldo('gasto_aportes'));self::assertSame(5521+4017+5519+2142+2142,$saldo('gasto_beneficios'));
        self::assertTrue($cont->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$cont->integridad());
        $asiento=$this->r->uno("SELECT * FROM erp_cont_asientos WHERE concepto LIKE 'Nómina 2026-10%'");self::assertSame(substr($this->r::ahora(),0,10),$asiento['fecha'],'Aprobada dentro del mes: se asienta el día de la aprobación; aprobada después del cierre del mes, se asienta en su fin de mes');
        // Conciliación: lo debido a los colaboradores concilia con las obligaciones de nómina.
        $o=(int)$this->r->uno("SELECT COALESCE(SUM(monto_centavos-pagado_centavos-ajuste_centavos),0) n FROM erp_obligaciones WHERE origen='nomina'")['n'];self::assertSame($o,$saldo('sueldos_por_pagar'));
        // Pagar una obligación (transferencia) cancela lo debido al colaborador, no a proveedores.
        $caja=new ErpCajaService($this->r);$caja->abrir($this->owner,$this->d(['fondo'=>'0']));
        $ob=(int)$this->r->uno("SELECT id FROM erp_obligaciones WHERE origen='nomina' AND colaborador_id=?",[$this->colA])['id'];
        (new ErpCompraService($this->r))->pagar($this->owner,$this->d(['obligacion_id'=>$ob,'monto'=>'579.89','metodo'=>'transferencia','referencia'=>'TRF-NOM-1']));
        (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);self::assertSame(27562,$saldo('sueldos_por_pagar'));self::assertSame(0,$saldo('proveedores'));
        // Con un pago hecho ya no se anula: se corrige con novedades de ajuste.
        $this->rechazo(fn()=>$this->svc()->anular($this->owner,$this->d(['corrida_id'=>$k,'motivo'=>'Error'])),409);
    }
    public function testAnularAntesDeCualquierPagoLiberaTodoYRevierteElAsiento():void
    {
        $this->escenario();$this->r->q("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES(?,12345,'nomina',1,NOW())",[$this->colA]);$liq=$this->r->id();
        $k=$this->calcular();self::assertSame(12345,(int)$this->linea($k,$this->colA)['comisiones_centavos']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_nomina_comisiones WHERE activa=1 AND liquidacion_id=?',[$liq])['n']);
        $this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));
        $a=new ErpContabilidadAdaptador($this->r);(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);$cont=new ErpContabilidadService($this->r);
        $this->svc()->anular($this->owner,$this->d(['corrida_id'=>$k,'motivo'=>'Se registró mal una novedad']));
        self::assertSame('anulada',$this->r->uno('SELECT estado FROM erp_nomina_corridas WHERE id=?',[$k])['estado']);
        self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_obligaciones WHERE monto_centavos-pagado_centavos-ajuste_centavos>0")['n'],'Las obligaciones quedan canceladas');
        self::assertSame(5,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_nomina_novedades WHERE estado='aprobada'")['n'],'Las novedades vuelven a estar disponibles');
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_nomina_comisiones WHERE activa=1')['n'],'La comisión queda libre');
        (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);
        foreach(['sueldos_por_pagar','iess_por_pagar','ir_por_pagar','beneficios_por_pagar','descuentos_nomina'] as $rol){$f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?',[$cont->rolCuenta($rol)]);self::assertSame((int)$f['d'],(int)$f['h'],$rol.' queda en cero tras el reverso');}
        self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE concepto LIKE '%ómina%'")['n'],'Asiento y reverso; el original permanece');self::assertTrue($cont->balanceComprobacion('2099-12-31')['cuadra']);
        // Recalcular el mes vuelve a tomar la comisión y las novedades.
        $k2=$this->calcular();self::assertSame(12345,(int)$this->linea($k2,$this->colA)['comisiones_centavos']);self::assertNotSame($k,$k2);
    }
    public function testUnaComisionPagadaPorNominaNoPuedeAdemasPagarseDirecto():void
    {
        $this->parametros();$this->condiciones();
        $this->r->q("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES(?,5000,'directa',1,NOW())",[$this->colA]);$directa=$this->r->id();
        $this->r->q("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES(?,7000,'nomina',1,NOW())",[$this->colA]);
        $k=$this->calcular();self::assertSame(7000,(int)$this->linea($k,$this->colA)['comisiones_centavos'],'Solo la liquidada por nómina entra; la directa jamás');
        $lid=(int)$this->linea($k,$this->colA)['id'];self::assertStringContainsString('nómina',$this->sqlFalla("INSERT INTO erp_nomina_comisiones(liquidacion_id,linea_id,monto_centavos) VALUES($directa,$lid,5000)"));
        // La misma comisión no entra en dos corridas activas (índice único funcional por vínculo activo).
        $liq=(int)$this->r->uno("SELECT id FROM erp_comision_liquidaciones WHERE via='nomina'")['id'];self::assertStringContainsString('Duplicate',$this->sqlFalla("INSERT INTO erp_nomina_comisiones(liquidacion_id,linea_id,monto_centavos) VALUES($liq,$lid,7000)"));
    }
    public function testBeneficiosAcumuladosSeLiquidanUnaSolaVezYConcilian():void
    {
        $this->escenario();$k=$this->calcular();$this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));
        $s=$this->svc()->saldosBeneficios();self::assertCount(1,$s,'Solo Ana acumula; Beto mensualiza');self::assertSame([5521,4017],[(int)$s[0]['d13'],(int)$s[0]['d14']]);
        $b=$this->svc()->liquidarBeneficio($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'decimo_tercero']))['recurso_id'];
        $o=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=(SELECT obligacion_id FROM erp_nomina_beneficios WHERE id=?)',[$b]);self::assertSame(5521,(int)$o['monto_centavos']);self::assertSame('nomina_beneficio',$o['origen']);
        $this->rechazo(fn()=>$this->svc()->liquidarBeneficio($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'decimo_tercero'])),409);
        $this->svc()->liquidarBeneficio($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'decimo_cuarto']));self::assertSame([],$this->svc()->saldosBeneficios());
        $this->rechazo(fn()=>$this->svc()->anular($this->owner,$this->d(['corrida_id'=>$k,'motivo'=>'Ya liquidado'])),409);
        $a=new ErpContabilidadAdaptador($this->r);$x=(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>$a],100);self::assertSame(0,$x['fallidos']);
        $cont=new ErpContabilidadService($this->r);$f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?',[$cont->rolCuenta('beneficios_por_pagar')]);self::assertSame(0,(int)$f['h']-(int)$f['d'],'Lo provisionado quedó liquidado');
    }
    public function testReciboSoloParaLaNominaYParaElPropioColaboradorUnaVezAprobada():void
    {
        $this->escenario();$k=$this->calcular();$lid=(int)$this->linea($k,$this->colA)['id'];
        self::assertSame('Ana Pérez',$this->svc()->recibo($this->finanzas,$lid)['nombre']);
        $this->rechazo(fn()=>$this->svc()->recibo($this->usuarioA,$lid),404);$this->rechazo(fn()=>$this->svc()->recibo($this->recepcion,$lid),404);
        $this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));
        self::assertSame($lid,(int)$this->svc()->recibo($this->usuarioA,$lid)['id'],'El colaborador ve su propio recibo');
        $otra=(int)$this->linea($k,$this->colB)['id'];$this->rechazo(fn()=>$this->svc()->recibo($this->usuarioA,$otra),404);
    }
    public function testDescuentosQueDejanSinNetoBloqueanElCalculo():void
    {
        $this->parametros();$this->condiciones();$this->aprobarNovedad($this->novedad(['tipo'=>'descuento','monto'=>'900.00']));
        $e=$this->rechazo(fn()=>$this->calcular(),409);self::assertStringContainsString('neto',$e->getMessage());self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_nomina_corridas')['n']);
    }
    public function testDosProcesosNoApruebanDosVecesLaMismaCorrida():void
    {
        $this->escenario();$k=$this->calcular();$this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));
        $workers=[];$res=[];
        try{
            foreach([0,1] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/concurrent_prepago_worker.php','nomina_aprobar',(string)$this->owner,json_encode(['idem_key'=>R5CajaFixture::clave(),'corrida_id'=>$k])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['DB_NAME'=>BaseDatosAislada::nombre($this->db)]));self::assertIsResource($p);stream_set_timeout($pipes[1],30);$workers[]=[$p,$pipes];self::assertSame("READY\n",fgets($pipes[1]));}
            foreach($workers as [,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]);}
            foreach($workers as [$p,$pipes]){$linea=fgets($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);$j=json_decode((string)$linea,true);self::assertIsArray($j,$err.(string)$linea);$res[]=$j;}
        }finally{foreach($workers as [$p])if(is_resource($p)){proc_terminate($p);proc_close($p);}}
        self::assertCount(2,array_filter($res,fn($x)=>$x['ok']),json_encode($res));
        self::assertSame(2,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_obligaciones WHERE origen='nomina'")['n']);self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='nomina_aprobada'")['n']);
        self::assertSame(5,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_nomina_novedades WHERE estado='consumida'")['n']);
    }

    // ---- Interfaz y rutas ----
    private function sesion(int $usuario,string $portal='equipo'):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>$portal,'version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-nom'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/nomina';$_SERVER['REQUEST_METHOD']='GET';
    }
    private function pagina(string $metodo,array $get):string
    {
        $_GET=$get;ob_start();try{\Controllers\ErpController::$metodo(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function estado(callable $f):int {http_response_code(200);$e=200;ob_start();try{$f();$e=http_response_code()?:200;}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;}
    public function testPantallasYRecibosRespetanPermisosPorRol():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->escenario();$k=$this->calcular();$lid=(int)$this->linea($k,$this->colA)['id'];
            $this->sesion($this->owner);
            foreach(['resumen','novedades','parametros','condiciones','beneficios'] as $v)self::assertStringContainsString('Nómina propia de ensayo',$this->pagina('nomina',['vista'=>$v]),$v);
            $h=$this->pagina('nomina',['vista'=>'corrida','corrida'=>(string)$k]);self::assertStringContainsString('Marcar como revisada',$h);self::assertStringContainsString('Ana Pérez',$h);self::assertStringContainsString('$579.89',$h);
            $rec=$this->pagina('nominaRecibo',['id'=>(string)$lid]);self::assertStringContainsString('Neto a recibir',$rec);self::assertStringContainsString('$579.89',$rec);
            // Finanzas ve y prepara, pero no aprueba ni configura.
            $this->sesion($this->finanzas);$f=$this->pagina('nomina',['vista'=>'corrida','corrida'=>(string)$k]);self::assertStringNotContainsString('Marcar como revisada',$f);self::assertStringNotContainsString('Parámetros legales',$this->pagina('nomina',['vista'=>'resumen']));
            // Recepción y profesional: sin acceso a la nómina; el colaborador solo ve su propio recibo de una corrida aprobada.
            $this->sesion($this->recepcion);self::assertSame(403,$this->estado(fn()=>\Controllers\ErpController::nomina(new \MVC\Router())));
            $this->sesion($this->usuarioA);$_GET=['id'=>(string)$lid];self::assertSame(404,$this->estado(fn()=>\Controllers\ErpController::nominaRecibo(new \MVC\Router())),'Aún no aprobada');
            $this->sesion($this->owner);$this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));
            $this->sesion($this->usuarioA);self::assertStringContainsString('Rol de pagos',$this->pagina('nominaRecibo',['id'=>(string)$lid]));
            $this->sesion($this->usuarioA);self::assertSame(404,$this->estado(function()use($k){$_GET=['id'=>(string)$this->linea($k,$this->colB)['id']];\Controllers\ErpController::nominaRecibo(new \MVC\Router());}),'El recibo de otra persona no se ve');
            // Acciones por formulario: el portal de cliente y recepción no escriben.
            $_SERVER['REQUEST_URI']='/gestion/accion';$this->sesion($this->recepcion);$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';
            $_POST=['accion'=>'nom_calcular','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-nom','anio'=>'2026','mes'=>'9'];self::assertContains($this->estado(fn()=>\Controllers\ErpController::accion()),[302,303,403]);
            self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_nomina_corridas WHERE estado<>'anulada'")['n']);
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
