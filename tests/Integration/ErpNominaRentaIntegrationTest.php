<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\{ErpRepository,OperacionRepository};
use Services\{ErpNominaService,ErpRhService,ErpContabilidadService,ErpException};

/**
 * Retención de renta de relación de dependencia con una tabla de ENSAYO (no oficial): 0 % hasta 1 000; 10 % hasta 2 000; 100 + 20 % en adelante (USD).
 * Los valores esperados están calculados a mano, no con el código probado.
 */
final class ErpNominaRentaIntegrationTest extends TestCase
{
    private const TRAMOS="0;1000;0;0\n1000;2000;0;10\n2000;adelante;100;20";
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $colA;private int $colB;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('nominair');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->colA=$this->colaborador('Ana','Pérez',R5CajaFixture::usuario($this->db,'profesional'),'2024-01-01');$this->colB=$this->colaborador('Beto','Ruiz',R5CajaFixture::usuario($this->db,'profesional'),'2026-10-16');
        $rh=new ErpRhService($this->r);
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'empleado','inicio'=>'2024-01-01','base'=>'600.00','observacion'=>'Contrato de ensayo']));
        $rh->contrato($this->owner,$this->d(['colaborador_id'=>$this->colB,'tipo'=>'empleado','inicio'=>'2026-10-16','base'=>'482.00','observacion'=>'Contrato de ensayo']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        $this->parametros();$this->condiciones();
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function hoy():string {return substr(OperacionRepository::ahora(),0,10);}
    private function svc():ErpNominaService {return new ErpNominaService($this->r);}
    private function colaborador(string $n,string $a,int $usuario,string $ingreso):int
    {
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,?,?,'profesional','activo',?,NOW() FROM usuarios WHERE id=?",[$n,$a,$ingreso,$usuario]);return $this->r->id();
    }
    private function parametros():void
    {
        $v=['sbu_centavos'=>48200,'iess.aporte_personal_pb'=>945,'iess.aporte_patronal_pb'=>1115,'fondos_reserva.pb'=>833,'fondos_reserva.dias_antiguedad'=>365,'recargo.nocturno_pb'=>2500,'recargo.suplementaria_pb'=>5000,'recargo.extraordinaria_pb'=>10000,'jornada.horas_mes'=>240];
        foreach($v as $k=>$n)$this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),1,NOW())",[$k,$n]);
    }
    private function condiciones():void
    {
        $this->svc()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
        $this->svc()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colB,'vigente_desde'=>'2026-10-16','region'=>'costa_galapagos','mensualiza_d13'=>'1','mensualiza_d14'=>'1','fondos_reserva'=>'mensualizado','aporta_iess'=>'1']));
    }
    private function tabla(array $x=[]):int {return $this->svc()->tablaRenta($this->owner,$this->d($x+['anio'=>2026,'vigente_desde'=>'2026-01-01','estado'=>'vigente','tramos'=>self::TRAMOS,'fuente'=>'Tabla de ensayo (no oficial)','consultado_en'=>$this->hoy()]))['recurso_id'];}
    private function novedad(array $x):int
    {
        $id=$this->svc()->novedad($this->finanzas,$this->d($x+['colaborador_id'=>$this->colA,'anio'=>2026,'mes'=>10,'motivo'=>'Registro de ensayo']))['recurso_id'];
        $this->svc()->resolverNovedad($this->owner,$this->d(['novedad_id'=>$id,'decision'=>'aprobar']));return $id;
    }
    private function calcular(int $mes):int {return $this->svc()->calcular($this->finanzas,$this->d(['anio'=>2026,'mes'=>$mes]))['recurso_id'];}
    private function aprobar(int $k):void {$this->svc()->revisar($this->owner,$this->d(['corrida_id'=>$k]));$this->svc()->aprobar($this->owner,$this->d(['corrida_id'=>$k]));}
    private function linea(int $corrida,int $col):array {return $this->r->uno('SELECT * FROM erp_nomina_lineas WHERE corrida_id=? AND colaborador_id=?',[$corrida,$col]);}
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function snap(array $l):array {return json_decode($l['snapshot_json'],true);}

    public function testLaTablaSeRegistraConPermisoValidacionVersionesYNoSeEdita():void
    {
        $this->rechazo(fn()=>$this->svc()->tablaRenta($this->finanzas,$this->d(['anio'=>2026,'vigente_desde'=>'2026-01-01','tramos'=>self::TRAMOS,'fuente'=>'Tabla de ensayo','consultado_en'=>$this->hoy()])),403);
        $e=$this->rechazo(fn()=>$this->tabla(['tramos'=>"0;1000;0;0\n1500;adelante;0;10"]),422);self::assertStringContainsString('donde termina el anterior',$e->getMessage());
        $this->rechazo(fn()=>$this->tabla(['tramos'=>"0;1000;0;0\n1000;2000;0;10"]),422);// el último no puede tener límite
        $this->rechazo(fn()=>$this->tabla(['tramos'=>'']),422);
        $this->rechazo(fn()=>$this->tabla(['consultado_en'=>date('Y-m-d',strtotime($this->hoy().' +3 days'))]),422);
        $this->rechazo(fn()=>$this->tabla(['vigente_desde'=>'2027-01-01']),422);
        $this->rechazo(fn()=>$this->tabla(['fuente'=>'abc']),422);
        $this->rechazo(fn()=>$this->tabla(['fuente_url'=>'http://inseguro.example/x']),422);
        $id=$this->tabla(['tope_gastos'=>'500.00','fuente_url'=>'https://www.sri.gob.ec/ejemplo']);
        $f=$this->r->uno('SELECT * FROM erp_nomina_ir_tablas WHERE id=?',[$id]);self::assertSame(50000,(int)$f['tope_gastos_centavos']);self::assertSame(100000,json_decode($f['tramos_json'],true)[0]['hasta']);
        $this->rechazo(fn()=>$this->tabla(),409);// misma vigencia
        foreach(["UPDATE erp_nomina_ir_tablas SET anio=2025","DELETE FROM erp_nomina_ir_tablas"] as $sql)self::assertFalse($this->db->query($sql),$sql);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='nomina_ir_tabla_registrada'")['n']);
    }
    public function testConTablaVigenteLaRetencionSeCalculaYSeAcumulaMesAMes():void
    {
        $this->tabla();
        // Agosto: 5 meses por delante (ago-dic). Sueldo 600,00; aporte 9,45 % = 56,70. Base = 5 × (600,00 − 56,70) = 2 716,50; impuesto = 100 + 20 % × 716,50 = 243,30 (redondeo); retención = 243,30 / 5.
        $k8=$this->calcular(8);$l=$this->linea($k8,$this->colA);
        self::assertSame(5670,(int)$l['iess_personal_centavos']);self::assertSame(4866,(int)$l['ir_centavos']);self::assertSame(60000-5670-4866,(int)$l['neto_centavos']);
        $s=$this->snap($l)['renta'];self::assertSame('renta-v1',$s['formula']);self::assertSame(271650,$s['base_anual']);self::assertSame(24330,$s['impuesto_tabla']);self::assertTrue($s['sin_declaracion']);self::assertNull($s['declaracion_id']);
        $this->aprobar($k8);
        // Setiembre con un bono de 100,00: 4 meses por delante; acumulado previo 600,00 / 56,70 / 48,66.
        $this->novedad(['tipo'=>'bono','monto'=>'100.00','mes'=>9]);
        $k9=$this->calcular(9);$l=$this->linea($k9,$this->colA);
        self::assertSame(6615,(int)$l['iess_personal_centavos'],'9,45 % de 700,00');
        // Proyección: 600 + 4 × 700 = 3 400,00; IESS: 56,70 + 4 × 66,15 = 321,30; base 3 078,70; impuesto 100 + 20 % × 1 078,70 = 315,74; pendiente 315,74 − 48,66 = 267,08; /4 = 66,77.
        self::assertSame(6677,(int)$l['ir_centavos']);
        $s=$this->snap($l)['renta'];self::assertEquals(['gravado'=>60000,'iess'=>5670,'retenido'=>4866],$s['acumulado_previo']);self::assertSame(31574,$s['impuesto_tabla']);self::assertSame(26708,$s['pendiente']);
        // La nómina aprobada genera la obligación al SRI con lo retenido.
        $this->aprobar($k9);self::assertSame(6677,(int)$this->r->uno("SELECT monto_centavos m FROM erp_obligaciones WHERE id=(SELECT obligacion_ir_id FROM erp_nomina_corridas WHERE id=?)",[$k9])['m']);
    }
    public function testUnaCorridaAnuladaNoCuentaEnLoAcumulado():void
    {
        $this->tabla();$k8=$this->calcular(8);
        $this->svc()->anular($this->owner,$this->d(['corrida_id'=>$k8,'motivo'=>'Error de captura']));
        // Sin agosto: setiembre proyecta 4 meses desde cero: base 4 × 543,30 = 2 173,20; impuesto 100 + 20 % × 173,20 = 134,64; /4 = 33,66.
        $l=$this->linea($this->calcular(9),$this->colA);self::assertSame(3366,(int)$l['ir_centavos']);self::assertEquals(['gravado'=>0,'iess'=>0,'retenido'=>0],$this->snap($l)['renta']['acumulado_previo']);
    }
    public function testUnaRetencionManualConTablaVigenteSeRechazaYConLaTablaDerogadaVuelveALaNovedad():void
    {
        $this->tabla();$n=$this->novedad(['tipo'=>'retencion_ir','monto'=>'5.00']);
        $this->aprobar($this->calcular(8));// agosto no tiene la novedad (es de octubre)
        $e=$this->rechazo(fn()=>$this->calcular(10),409);self::assertStringContainsString('manuales',$e->getMessage());self::assertStringContainsString((string)$n,$e->getMessage());
        // Se deroga la tabla desde hoy: octubre vuelve a la retención manual del contador.
        $this->tabla(['vigente_desde'=>$this->hoy(),'estado'=>'derogado','tramos'=>'']);
        $l=$this->linea($this->calcular(10),$this->colA);self::assertSame(500,(int)$l['ir_centavos']);self::assertArrayNotHasKey('renta',$this->snap($l));
    }
    public function testLaDeclaracionDelTrabajadorReduceLaBaseConTopeYQuedaEnLaInstantanea():void
    {
        $this->rechazo(fn()=>$this->svc()->declaracionRenta($this->finanzas,$this->d(['colaborador_id'=>$this->colA,'anio'=>2026,'gastos'=>'600.00','fuente'=>'Proyección de gastos 2026'])),403);
        $this->rechazo(fn()=>$this->svc()->declaracionRenta($this->owner,$this->d(['colaborador_id'=>$this->colA,'anio'=>2026,'gastos'=>'600.00','fuente'=>'abc'])),422);
        $this->rechazo(fn()=>$this->svc()->declaracionRenta($this->owner,$this->d(['colaborador_id'=>$this->colA,'anio'=>2026,'gastos'=>'-5','fuente'=>'Proyección de gastos 2026'])),422);
        $this->svc()->declaracionRenta($this->owner,$this->d(['colaborador_id'=>$this->colA,'anio'=>2026,'gastos'=>'600.00','fuente'=>'Proyección de gastos 2026']));
        // Con gastos declarados y sin tope registrado en la tabla, no se calcula (no se asume ningún límite legal).
        $this->tabla();$e=$this->rechazo(fn()=>$this->calcular(10),409);self::assertStringContainsString('tope de gastos',$e->getMessage());
        $this->tabla(['vigente_desde'=>$this->hoy(),'tope_gastos'=>'500.00']);
        // Octubre: 3 meses; base = 3 × 543,30 − 500,00 (tope) = 1 129,90; impuesto 10 % × 129,90 = 12,99; retención 12,99 / 3 = 4,33. Sin la declaración: (1 629,90 − 1 000) × 10 % = 62,99 → 21,00.
        $l=$this->linea($this->calcular(10),$this->colA);self::assertSame(433,(int)$l['ir_centavos']);
        $s=$this->snap($l)['renta'];self::assertSame(50000,$s['gastos_aplicados']);self::assertTrue($s['gastos_limitados_al_tope']);self::assertFalse($s['sin_declaracion']);self::assertGreaterThan(0,$s['declaracion_id']);
        // La declaración se corrige con otra nueva; la anterior se conserva.
        $this->svc()->declaracionRenta($this->owner,$this->d(['colaborador_id'=>$this->colA,'anio'=>2026,'gastos'=>'0','fuente'=>'Sin gastos a deducir']));
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_nomina_ir_declaraciones WHERE colaborador_id=?',[$this->colA])['n']);
        self::assertFalse($this->db->query('DELETE FROM erp_nomina_ir_declaraciones'));
    }
    public function testSinTablaLaNominaSigueComoAntesYElIrEsLaNovedadDelContador():void
    {
        $this->novedad(['tipo'=>'retencion_ir','monto'=>'7.00']);$l=$this->linea($this->calcular(10),$this->colA);
        self::assertSame(700,(int)$l['ir_centavos']);self::assertArrayNotHasKey('renta',$this->snap($l));
    }

    // ---- Interfaz ----
    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-ir'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/nomina';$_SERVER['REQUEST_METHOD']='GET';
    }
    private function pagina(array $get):string {$_GET=$get;ob_start();try{\Controllers\ErpController::nomina(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}}
    public function testLaPantallaDeRentaSoloLaVeQuienConfiguraYMuestraTablaYDeclaraciones():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'srv'=>$_SERVER];
        try{
            $this->tabla(['fuente_url'=>'https://www.sri.gob.ec/ejemplo']);$this->svc()->declaracionRenta($this->owner,$this->d(['colaborador_id'=>$this->colA,'anio'=>2026,'otras_deducciones'=>'10.00','fuente'=>'Formulario de ensayo']));
            $this->sesion($this->owner);$h=$this->pagina(['vista'=>'renta']);
            self::assertStringContainsString('Tabla de impuesto a la renta',$h);self::assertStringContainsString('Tabla de ensayo (no oficial)',$h);self::assertStringContainsString('adelante',$h);self::assertStringContainsString('Ana Pérez',$h);self::assertStringContainsString('<textarea',$h);
            $this->sesion($this->finanzas);$f=$this->pagina(['vista'=>'renta']);self::assertStringNotContainsString('Tabla de impuesto a la renta',$f,'Finanzas prepara pero no configura');
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
