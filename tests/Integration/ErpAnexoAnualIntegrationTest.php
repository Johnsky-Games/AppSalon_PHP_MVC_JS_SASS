<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpNominaService,ErpNominaAnualService,ErpFiniquitoService,ErpIdentificacionService,ErpRhService,ErpContabilidadService,ErpException};

/**
 * Consolidado anual con parámetros de ENSAYO (no oficiales). Colaboradora A: contrato de 600,00 del 2024-01-01 al 2026-09-30; corridas de agosto y setiembre aprobadas y finiquito aprobado.
 * Valores esperados calculados a mano: sueldo 2 × 60 000; aporte personal 2 × 5 670; patronal 2 × 6 690; fondos acumulados 2 × 4 998; 13.º provisionado 2 × 5 000;
 * 14.º provisionado 2 × 4 017; neto 2 × 54 330; finiquito 10 000 + 8 034 + 82 520 = 100 554.
 */
final class ErpAnexoAnualIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $colA;private int $colB;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('anexo_anual');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->colA=$this->colaborador('Ana','Pérez',R5CajaFixture::usuario($this->db,'profesional'),'2024-01-01');$this->colB=$this->colaborador('Beto','Ruiz',R5CajaFixture::usuario($this->db,'profesional'),'2026-10-16');
        $rh=new ErpRhService($this->r);
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'empleado','inicio'=>'2024-01-01','fin'=>'2026-09-30','base'=>'600.00','observacion'=>'Contrato de ensayo']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        foreach(['sbu_centavos'=>48200,'iess.aporte_personal_pb'=>945,'iess.aporte_patronal_pb'=>1115,'fondos_reserva.pb'=>833,'fondos_reserva.dias_antiguedad'=>365,'recargo.nocturno_pb'=>2500,'recargo.suplementaria_pb'=>5000,'recargo.extraordinaria_pb'=>10000,'jornada.horas_mes'=>240,'vacaciones.dias_anuales'=>15,'vacaciones.dias_base_anio'=>365,'desahucio.pb'=>2500,'despido.meses_hasta_umbral'=>3,'despido.umbral_anios'=>3,'despido.meses_por_anio'=>1,'despido.max_meses'=>25] as $k=>$n)
            $this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),?,NOW())",[$k,$n,$this->owner]);
        $this->nomina()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function nomina():ErpNominaService {return new ErpNominaService($this->r);}
    private function anual():ErpNominaAnualService {return new ErpNominaAnualService($this->r);}
    private function ident():ErpIdentificacionService {return new ErpIdentificacionService($this->r);}
    private function colaborador(string $n,string $a,int $usuario,string $ingreso):int
    {
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,?,?,'profesional','activo',?,NOW() FROM usuarios WHERE id=?",[$n,$a,$ingreso,$usuario]);return $this->r->id();
    }
    private function corrida(int $mes):int
    {
        $k=$this->nomina()->calcular($this->finanzas,$this->d(['anio'=>2026,'mes'=>$mes]))['recurso_id'];
        $this->nomina()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->nomina()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));return $k;
    }
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function fila(array $c,int $col):array {foreach($c['filas'] as $f)if($f['colaborador_id']===$col)return $f;self::fail('Sin fila del colaborador');}

    public function testSinNominasAprobadasElConsolidadoEstaVacioYLosMesesFaltanSeInforman():void
    {
        $c=$this->anual()->consolidado(2026);self::assertSame([],$c['filas']);self::assertSame(range(1,12),$c['controles']['meses_sin_corrida_aprobada']['meses']);self::assertTrue($c['controles']['identificacion']['ok']);
        $this->rechazo(fn()=>$this->anual()->consolidado(1999),422);
    }
    public function testElConsolidadoSumaLoAprobadoYSusControlesCuadran():void
    {
        $this->corrida(8);$this->corrida(9);
        $c=$this->anual()->consolidado(2026);$a=$this->fila($c,$this->colA);
        self::assertSame(2,$a['meses']);self::assertSame(60,$a['dias']);
        foreach(['sueldo'=>120000,'ingresos'=>120000,'base_aportable_iess'=>120000,'iess_personal'=>11340,'iess_patronal'=>13380,'fondos_reserva_acumulado'=>9996,'decimo_tercero_provisionado'=>10000,'decimo_cuarto_provisionado'=>8034,'neto_pagado'=>108660,'impuesto_renta_retenido'=>0,'descuentos'=>0,'finiquito_total'=>0,'utilidades'=>0] as $k=>$v)self::assertSame($v,$a[$k],$k);
        self::assertSame(2,$c['totales']['meses']);self::assertSame(120000,$c['totales']['sueldo']);
        // Cada corrida cuadra con sus obligaciones al IESS y al SRI; falta la identificación de la trabajadora.
        self::assertTrue($c['controles']['conciliacion_iess']['ok']);self::assertTrue($c['controles']['conciliacion_impuesto_renta']['ok']);
        self::assertFalse($c['controles']['identificacion']['ok']);self::assertSame([$this->colA],array_column($c['controles']['identificacion']['sin_identificacion'],'colaborador_id'));
        self::assertSame([1,2,3,4,5,6,7,10,11,12],$c['controles']['meses_sin_corrida_aprobada']['meses']);
        // Una corrida del año anterior no entra.
        self::assertSame([],$this->anual()->consolidado(2025)['filas']);
        // Si una obligación al IESS no coincide con sus líneas, el control lo dice.
        $this->db->query("UPDATE erp_obligaciones SET monto_centavos=monto_centavos+1 WHERE origen='iess' AND referencia LIKE 'IESS-%' ORDER BY id LIMIT 1");
        $d=$this->anual()->consolidado(2026)['controles']['conciliacion_iess'];self::assertFalse($d['ok']);self::assertSame(1,count($d['diferencias']));self::assertSame(1,$d['diferencias'][0]['obligacion']-$d['diferencias'][0]['lineas']);
    }
    public function testElFiniquitoAprobadoEntraEnElAnioDeLaSalida():void
    {
        $this->corrida(8);$this->corrida(9);$fin=new ErpFiniquitoService($this->r);
        $id=$fin->calcular($this->finanzas,$this->d(['colaborador_id'=>$this->colA,'fecha_salida'=>'2026-09-30','causa'=>'renuncia','desahucio'=>'0']))['recurso_id'];
        self::assertSame(0,$this->fila($this->anual()->consolidado(2026),$this->colA)['finiquito_total'],'Un finiquito solo calculado no cuenta');
        $fin->aprobar($this->owner,$this->d(['finiquito_id'=>$id]));
        $a=$this->fila($this->anual()->consolidado(2026),$this->colA);
        foreach(['finiquito_decimo_tercero'=>10000,'finiquito_decimo_cuarto'=>8034,'finiquito_vacaciones'=>82520,'finiquito_desahucio'=>0,'finiquito_indemnizacion'=>0,'finiquito_total'=>100554] as $k=>$v)self::assertSame($v,$a[$k],$k);
        self::assertSame(0,$this->anual()->consolidado(2027)['totales']['finiquito_total']);
    }
    public function testLaIdentificacionSeValidaSeCorrigeSinBorrarYNoSeRepiteEntreTrabajadores():void
    {
        $cedula=function(string $nueve){$s=0;foreach([2,1,2,1,2,1,2,1,2] as $i=>$c){$p=(int)$nueve[$i]*$c;$s+=$p>9?$p-9:$p;}return $nueve.((10-($s%10))%10);};
        $uno=$cedula('171003406');$dos=$cedula('090909090');
        $this->rechazo(fn()=>$this->ident()->declarar($this->recepcion,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'cedula','numero'=>$uno])),403);
        $this->rechazo(fn()=>$this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'cedula','numero'=>'1710034060'])),422);
        $this->rechazo(fn()=>$this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>999999,'tipo'=>'cedula','numero'=>$uno])),404);
        $this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'cedula','numero'=>' '.$uno.' ']));
        self::assertSame(['tipo'=>'cedula','numero'=>$uno],array_intersect_key($this->ident()->vigente($this->colA),['tipo'=>1,'numero'=>1]));
        $this->rechazo(fn()=>$this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'cedula','numero'=>$uno])),409);// ya es la vigente
        $this->rechazo(fn()=>$this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colB,'tipo'=>'cedula','numero'=>$uno])),409);// de otro trabajador
        // Corrección: la nueva rige y la anterior se conserva.
        $this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'cedula','numero'=>$dos,'nota'=>'Corrección: se había digitado la de un familiar']));
        self::assertSame($dos,$this->ident()->vigente($this->colA)['numero']);self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_rh_identificaciones WHERE colaborador_id=?',[$this->colA])['n']);
        // Ahora el documento anterior queda libre para otro trabajador; el pasaporte se normaliza.
        $this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colB,'tipo'=>'pasaporte','numero'=>' ab1234567 ']));
        self::assertSame([$this->colA=>$dos,$this->colB=>'AB1234567'],array_map(fn($x)=>$x['numero'],$this->ident()->vigentes()));
        foreach(["UPDATE erp_rh_identificaciones SET numero='0000000000'","DELETE FROM erp_rh_identificaciones"] as $sql)self::assertFalse($this->db->query($sql),$sql);
        // El evento de auditoría no copia el número.
        $ev=$this->r->q("SELECT datos_json FROM erp_eventos WHERE tipo='rh_identificacion_declarada'");self::assertNotEmpty($ev);foreach($ev as $e){self::assertStringNotContainsString($uno,$e['datos_json']);self::assertStringNotContainsString($dos,$e['datos_json']);}
    }
    public function testConIdentificacionElControlPasaYElCsvNoDejaQueUnaHojaInterpreteFormulas():void
    {
        $this->corrida(8);
        $cedula=function(string $nueve){$s=0;foreach([2,1,2,1,2,1,2,1,2] as $i=>$c){$p=(int)$nueve[$i]*$c;$s+=$p>9?$p-9:$p;}return $nueve.((10-($s%10))%10);};
        $this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'cedula','numero'=>$cedula('171003406')]));
        $c=$this->anual()->consolidado(2026);self::assertTrue($c['controles']['identificacion']['ok']);
        $csv=$this->anual()->csv($c);self::assertStringStartsWith("\xEF\xBB\xBF\"nombre\";",$csv);self::assertStringContainsString('"Pérez Ana";"cedula";"1710034065";"1";"30"',$csv);self::assertStringContainsString('"600.00"',$csv);
        // Un nombre que empieza como fórmula se neutraliza; los importes siguen siendo números.
        $c['filas'][0]['nombre']='=HYPERLINK("http://x")';$linea=explode("\r\n",$this->anual()->csv($c))[1];self::assertStringStartsWith('"\'=HYPERLINK(""http://x"")";',$linea);
    }
    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-anual'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/nomina';$_SERVER['REQUEST_METHOD']='GET';
    }
    private function pagina(array $get,string $accion='nomina'):string {$_GET=$get;ob_start();try{\Controllers\ErpController::$accion(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}}
    public function testLaPantallaAdvierteQueEsUnBorradorYEnmascaraLaIdentificacion():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'srv'=>$_SERVER];
        try{
            $this->corrida(8);$this->sesion($this->owner);
            $cedula=function(string $nueve){$s=0;foreach([2,1,2,1,2,1,2,1,2] as $i=>$c){$p=(int)$nueve[$i]*$c;$s+=$p>9?$p-9:$p;}return $nueve.((10-($s%10))%10);};$ced=$cedula('171003406');
            $h=$this->pagina(['vista'=>'anual','anio'=>'2026']);
            self::assertStringContainsString('Borrador de control, no es el archivo oficial',$h);self::assertStringContainsString('Pérez Ana',$h);self::assertStringContainsString('<em>falta</em>',$h);self::assertStringContainsString('$600.00',$h);
            $this->ident()->declarar($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'cedula','numero'=>$ced]));
            $h=$this->pagina(['vista'=>'anual','anio'=>'2026']);
            self::assertStringContainsString($ced,$h,'Quien configura la nómina ve el número completo');self::assertStringContainsString('Descargar CSV',$h);
            // Un usuario de nómina sin permiso de configurar ve la identificación enmascarada y no puede exportar.
            $this->sesion($this->finanzas);$h=$this->pagina(['vista'=>'anual','anio'=>'2026']);
            self::assertStringNotContainsString($ced,$h);self::assertStringNotContainsString('Descargar CSV',$h);
            // Personal: la ficha RH muestra la identificación enmascarada y ofrece corregirla.
            $this->sesion($this->owner);$p=$this->pagina(['id'=>(string)$this->colA],'personal');
            self::assertStringContainsString('Identificación tributaria',$p);self::assertStringContainsString('••••••4065',$p);self::assertStringNotContainsString($ced,$p);self::assertStringContainsString('Corregir identificación',$p);
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
