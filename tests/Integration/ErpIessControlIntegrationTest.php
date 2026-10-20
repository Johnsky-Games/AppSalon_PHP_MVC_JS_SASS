<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpNominaService,ErpIessService,ErpRhService,ErpContabilidadService,ErpException};

/**
 * Control de IESS con parámetros de ENSAYO (no oficiales). Colaboradora A: contrato de empleado 2024-01-01 → 2026-09-30; colaborador B: desde 2026-10-16.
 * La planilla de agosto se cuadra contra la obligación al IESS de la corrida aprobada.
 */
final class ErpIessControlIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $colA;private int $colB;private int $kA;private int $kB;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('iess');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->colA=$this->colaborador('Ana','Pérez',R5CajaFixture::usuario($this->db,'profesional'),'2024-01-01');$this->colB=$this->colaborador('Beto','Ruiz',R5CajaFixture::usuario($this->db,'profesional'),'2026-10-16');
        $rh=new ErpRhService($this->r);
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'empleado','inicio'=>'2024-01-01','fin'=>'2026-09-30','base'=>'600.00','observacion'=>'Contrato de ensayo']));
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colB,'tipo'=>'empleado','inicio'=>'2026-10-16','base'=>'482.00','observacion'=>'Contrato de ensayo']));
        $this->kA=(int)$this->r->uno("SELECT id FROM erp_contratos WHERE colaborador_id=?",[$this->colA])['id'];$this->kB=(int)$this->r->uno("SELECT id FROM erp_contratos WHERE colaborador_id=?",[$this->colB])['id'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function iess():ErpIessService {return new ErpIessService($this->r);}
    private function nomina():ErpNominaService {return new ErpNominaService($this->r);}
    private function colaborador(string $n,string $a,int $usuario,string $ingreso):int
    {
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,?,?,'profesional','activo',?,NOW() FROM usuarios WHERE id=?",[$n,$a,$ingreso,$usuario]);return $this->r->id();
    }
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function claves(array $p):array {return array_map(fn($x)=>$x['nombre'].'|'.$x['tipo'].'|'.$x['fecha_evento'],$p);}
    private function aviso(int $contrato,string $tipo,string $ref='AV-001',?int $actor=null):int {return $this->iess()->registrar($actor??$this->finanzas,$this->d(['contrato_id'=>$contrato,'tipo'=>$tipo,'referencia'=>$ref]))['recurso_id'];}

    public function testLosPendientesSalenDeLosContratosYSeVanAlRegistrarElAviso():void
    {
        $esperado=['Ana Pérez|entrada|2024-01-01','Ana Pérez|salida|2026-09-30','Beto Ruiz|entrada|2026-10-16'];
        self::assertSame($esperado,$this->claves($this->iess()->pendientes('2024-01-01','2026-10-31')));
        // El rango acota: solo lo de octubre.
        self::assertSame(['Beto Ruiz|entrada|2026-10-16'],$this->claves($this->iess()->pendientes('2026-10-01','2026-10-31')));
        $id=$this->aviso($this->kA,'entrada','ENT-2024-001');
        self::assertSame(['Ana Pérez|salida|2026-09-30','Beto Ruiz|entrada|2026-10-16'],$this->claves($this->iess()->pendientes('2024-01-01','2026-10-31')));
        $f=$this->r->uno('SELECT * FROM erp_iess_avisos WHERE id=?',[$id]);self::assertSame('2024-01-01',$f['fecha_evento']);self::assertSame('ENT-2024-001',$f['referencia']);self::assertSame('registrado',$f['estado']);
    }
    public function testValidacionesPermisosDuplicadosEIdempotencia():void
    {
        $this->rechazo(fn()=>$this->aviso($this->kA,'entrada','AV-001',$this->recepcion),403);
        $this->rechazo(fn()=>$this->aviso($this->kA,'entrada','AB'),422);// referencia muy corta
        $this->rechazo(fn()=>$this->iess()->registrar($this->finanzas,$this->d(['contrato_id'=>$this->kA,'tipo'=>'otro','referencia'=>'AV-001'])),422);
        $e=$this->rechazo(fn()=>$this->aviso($this->kB,'salida'),409);self::assertStringContainsString('no tiene fecha final',$e->getMessage());
        $this->rechazo(fn()=>$this->iess()->registrar($this->finanzas,$this->d(['contrato_id'=>999999,'tipo'=>'entrada','referencia'=>'AV-001'])),404);
        $clave=R5CajaFixture::clave();
        $a=$this->iess()->registrar($this->finanzas,['contrato_id'=>$this->kA,'tipo'=>'entrada','referencia'=>'AV-001','idem_key'=>$clave])['recurso_id'];
        $b=$this->iess()->registrar($this->finanzas,['contrato_id'=>$this->kA,'tipo'=>'entrada','referencia'=>'AV-001','idem_key'=>$clave])['recurso_id'];self::assertSame($a,$b);
        $this->rechazo(fn()=>$this->aviso($this->kA,'entrada','AV-002'),409);// ya registrado: se anula para corregir
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_iess_avisos')['n']);
    }
    public function testAnularRequiereMotivoLiberaElPendienteYElRegistroNoSeEditaNiSeBorra():void
    {
        $id=$this->aviso($this->kA,'salida','SAL-1');
        $this->rechazo(fn()=>$this->iess()->anular($this->recepcion,$this->d(['aviso_id'=>$id,'motivo'=>'Referencia equivocada'])),403);
        $this->rechazo(fn()=>$this->iess()->anular($this->finanzas,$this->d(['aviso_id'=>$id,'motivo'=>'no'])),422);
        $this->iess()->anular($this->finanzas,$this->d(['aviso_id'=>$id,'motivo'=>'Referencia equivocada']));
        self::assertContains('Ana Pérez|salida|2026-09-30',$this->claves($this->iess()->pendientes('2026-09-01','2026-09-30')));
        $nuevo=$this->aviso($this->kA,'salida','SAL-2');self::assertGreaterThan($id,$nuevo);
        foreach(["UPDATE erp_iess_avisos SET referencia='X-123' WHERE id=$nuevo","UPDATE erp_iess_avisos SET fecha_evento='2026-09-29' WHERE id=$nuevo","UPDATE erp_iess_avisos SET estado='registrado' WHERE id=$id","DELETE FROM erp_iess_avisos WHERE id=$nuevo"] as $sql){
            self::assertFalse($this->db->query($sql),$sql);
        }
    }
    public function testLaPlanillaSaleDeLaCorridaAprobadaYCuadraConLaObligacionAlIess():void
    {
        $s=$this->iess()->planilla(2026,8);self::assertFalse($s['disponible']);
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        foreach(['sbu_centavos'=>48200,'iess.aporte_personal_pb'=>945,'iess.aporte_patronal_pb'=>1115,'fondos_reserva.pb'=>833,'fondos_reserva.dias_antiguedad'=>365,'recargo.nocturno_pb'=>2500,'recargo.suplementaria_pb'=>5000,'recargo.extraordinaria_pb'=>10000,'jornada.horas_mes'=>240] as $k=>$n)
            $this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),?,NOW())",[$k,$n,$this->owner]);
        $this->nomina()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
        $k=$this->nomina()->calcular($this->finanzas,$this->d(['anio'=>2026,'mes'=>8]))['recurso_id'];
        $s=$this->iess()->planilla(2026,8);self::assertFalse($s['disponible'],'Una corrida solo calculada no sirve de base');
        $this->nomina()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->nomina()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));
        $s=$this->iess()->planilla(2026,8);self::assertTrue($s['disponible']);self::assertSame($k,$s['corrida_id']);
        // Sueldo 600,00 (parámetros de ensayo): personal 9,45 % = 5 670, patronal 11,15 % = 6 690 y fondos de reserva acumulados 8,33 % = 4 998 (se depositan al IESS).
        self::assertCount(1,$s['filas']);$f=$s['filas'][0];self::assertSame('Ana Pérez',$f['nombre']);self::assertSame(60000,$f['gravado']);self::assertSame(5670,$f['personal']);self::assertSame(6690,$f['patronal']);self::assertSame(4998,$f['fondos']);self::assertSame(17358,$f['total']);
        self::assertSame(17358,$s['total']);self::assertSame(17358,$s['obligacion']);self::assertTrue($s['cuadra']);
        // Si la obligación no coincide con las líneas, la planilla lo dice.
        $this->db->query("UPDATE erp_obligaciones SET monto_centavos=monto_centavos+1 WHERE origen='iess'");
        self::assertFalse($this->iess()->planilla(2026,8)['cuadra']);
        self::assertFalse($this->iess()->planilla(2026,7)['disponible']);
    }

    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-iess'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/nomina';$_SERVER['REQUEST_METHOD']='GET';
    }
    private function pagina(array $get):string {$_GET=$get;ob_start();try{\Controllers\ErpController::nomina(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}}
    public function testLaPantallaMuestraPendientesAvisosYPlanillaConElAvisoDeQueAppSalonNoPresentaEnElIess():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'srv'=>$_SERVER];
        try{
            $this->aviso($this->kA,'entrada','ENT-2024-009');
            $this->sesion($this->owner);$h=$this->pagina(['vista'=>'iess','desde'=>'2026-09-01','hasta'=>'2026-10-31','anio'=>'2026','mes'=>'8']);
            self::assertStringContainsString('Avisos de entrada y salida pendientes',$h);self::assertStringContainsString('AppSalon no presenta el aviso en el portal del IESS',$h);
            self::assertStringContainsString('Beto Ruiz',$h);self::assertStringContainsString('2026-10-16',$h);self::assertStringContainsString('ENT-2024-009',$h);self::assertStringContainsString('No hay una corrida aprobada de 2026-08',$h);
            self::assertStringContainsString('Registrar aviso',$h);
            // Fechas o mes inválidos caen a valores por defecto sin romper la pantalla.
            self::assertStringContainsString('Avisos registrados',$this->pagina(['vista'=>'iess','desde'=>'no-fecha','hasta'=>'2026-13-45','anio'=>'x','mes'=>'99']));
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
