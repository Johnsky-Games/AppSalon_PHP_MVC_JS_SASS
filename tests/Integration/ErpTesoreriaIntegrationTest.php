<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpContabilidadService,ErpTesoreriaService,ErpException};

/**
 * Tesorería bancaria. Libro del banco (1.1.02), importes en USD:
 *  A1 03-02 +100,00 · A2 03-05 +50,00 · A3 03-10 −30,00 · A4 03-12 +25,00 (en tránsito) · A5 03-15 +10,00 · A6 03-15 +10,00 · A7 03-28 −1,50 (comisión).
 * Extracto de marzo (saldo inicial 0,00): 03-03 +100,00 · 03-06 +50,00 · 03-11 −30,00 · 03-16 +10,00 · 03-16 +10,00 · 03-20 −1,50 → saldo final 138,50.
 * A4 aparece en el extracto de abril (04-02 +25,00): saldo final 163,50 = libro 163,50.
 */
final class ErpTesoreriaIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private array $a=[];
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('tesoreria');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->cont()->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function tes():ErpTesoreriaService {return new ErpTesoreriaService($this->r);}
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    /** Asiento del banco (1.1.02) contra otra cuenta; monto con signo en USD. */
    private function banco(string $fecha,string $monto,string $contra='4.1.01',string $concepto='Movimiento de ensayo'):int
    {
        $ing=!str_starts_with($monto,'-');$m=ltrim($monto,'-');
        return $this->cont()->asiento($this->owner,$this->d(['fecha'=>$fecha,'concepto'=>$concepto,'lineas'=>[['cuenta'=>'1.1.02',$ing?'debe':'haber'=>$m],['cuenta'=>$contra,$ing?'haber':'debe'=>$m]]]))['recurso_id'];
    }
    private function lineaBanco(int $asiento):int {return (int)$this->r->uno('SELECT l.id FROM erp_cont_lineas l JOIN erp_cont_cuentas c ON c.id=l.cuenta_id WHERE l.asiento_id=? AND c.codigo=?',[$asiento,'1.1.02'])['id'];}
    private function linea(int $extracto,int $nro):int {return (int)$this->r->uno('SELECT id FROM erp_tes_extracto_lineas WHERE extracto_id=? AND nro=?',[$extracto,$nro])['id'];}
    private function libro():void
    {
        $this->a=['A1'=>$this->banco('2026-03-02','100.00'),'A2'=>$this->banco('2026-03-05','50.00'),'A3'=>$this->banco('2026-03-10','-30.00','1.1.01','Retiro a caja'),'A4'=>$this->banco('2026-03-12','25.00'),
            'A5'=>$this->banco('2026-03-15','10.00'),'A6'=>$this->banco('2026-03-15','10.00')];
    }
    private const MARZO="fecha;descripcion;referencia;monto\n2026-03-03;Depósito;REF1;100.00\n2026-03-06;Transferencia recibida;;50.00\n2026-03-11;Retiro;;-30.00\n2026-03-16;Cobro A;;10.00\n2026-03-16;Cobro B;;10.00\n2026-03-20;Comisión bancaria;;-1.50\n";
    private function cuenta():int {return $this->tes()->cuentaCrear($this->owner,$this->d(['cuenta'=>'1.1.02','nombre'=>'Cuenta corriente','entidad'=>'Banco de ensayo','ultimos_digitos'=>'1234']))['recurso_id'];}
    private function marzo(int $cuenta):int
    {
        return $this->tes()->importar($this->owner,$this->d(['tes_cuenta_id'=>$cuenta,'desde'=>'2026-03-01','hasta'=>'2026-03-31','saldo_inicial'=>'0.00','saldo_final'=>'138.50','extracto'=>self::MARZO]))['recurso_id'];
    }

    public function testPermisosYValidacionDeLaCuentaBancaria():void
    {
        $d=fn(array $x)=>$this->d($x+['nombre'=>'Corriente','entidad'=>'Banco']);
        $this->rechazo(fn()=>$this->tes()->cuentaCrear($this->finanzas,$d(['cuenta'=>'1.1.02'])),403);// configurar es del propietario
        $this->rechazo(fn()=>$this->tes()->cuentaCrear($this->owner,$d(['cuenta'=>'9.9.99'])),404);
        $this->rechazo(fn()=>$this->tes()->cuentaCrear($this->owner,$d(['cuenta'=>'4.1.01'])),422);// no es de activo
        $this->rechazo(fn()=>$this->tes()->cuentaCrear($this->owner,$d(['cuenta'=>'1.1.02','ultimos_digitos'=>'1234567890'])),422);// el número completo no se guarda
        $id=$this->cuenta();self::assertGreaterThan(0,$id);
        $this->rechazo(fn()=>$this->tes()->cuentaCrear($this->owner,$d(['cuenta'=>'1.1.02'])),409);
        $this->rechazo(fn()=>$this->tes()->importar($this->recepcion,$this->d(['tes_cuenta_id'=>$id,'desde'=>'2026-03-01','hasta'=>'2026-03-31','saldo_inicial'=>'0','saldo_final'=>'138.50','extracto'=>self::MARZO])),403);
        $b=$this->tes()->cuentas()[0];self::assertSame('1234',$b['ultimos_digitos']);
    }
    public function testImportarValidaCuadreFechasContinuidadYEsIdempotentePorContenido():void
    {
        $c=$this->cuenta();$base=['tes_cuenta_id'=>$c,'desde'=>'2026-03-01','hasta'=>'2026-03-31','saldo_inicial'=>'0.00','saldo_final'=>'138.50','extracto'=>self::MARZO];
        $e=$this->rechazo(fn()=>$this->tes()->importar($this->owner,$this->d(['saldo_final'=>'138.51']+$base)),422);self::assertStringContainsString('no cuadra',$e->getMessage());
        $this->rechazo(fn()=>$this->tes()->importar($this->owner,$this->d(['hasta'=>'2026-03-15']+$base)),422);// líneas fuera del período
        $this->rechazo(fn()=>$this->tes()->importar($this->owner,$this->d(['extracto'=>"2026-03-03;Mal;;1,5"]+$base)),422);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_tes_extractos')['n'],'Un rechazo no deja extracto a medias');
        $id=$this->marzo($c);
        self::assertSame($id,$this->marzo($c),'El mismo contenido con otra clave devuelve el extracto ya importado');
        self::assertSame(6,(int)$this->r->uno('SELECT n_lineas n FROM erp_tes_extractos WHERE id=?',[$id])['n']);
        // El siguiente debe empezar después y con el saldo inicial igual al final anterior.
        $sig=['tes_cuenta_id'=>$c,'extracto'=>"2026-04-02;Depósito;;25.00"];
        $this->rechazo(fn()=>$this->tes()->importar($this->owner,$this->d($sig+['desde'=>'2026-03-31','hasta'=>'2026-04-30','saldo_inicial'=>'138.50','saldo_final'=>'163.50'])),409);// solapa
        $e=$this->rechazo(fn()=>$this->tes()->importar($this->owner,$this->d($sig+['desde'=>'2026-04-01','hasta'=>'2026-04-30','saldo_inicial'=>'100.00','saldo_final'=>'125.00'])),409);self::assertStringContainsString('no coincide',$e->getMessage());
        self::assertGreaterThan($id,$this->tes()->importar($this->owner,$this->d($sig+['desde'=>'2026-04-01','hasta'=>'2026-04-30','saldo_inicial'=>'138.50','saldo_final'=>'163.50']))['recurso_id']);
        // Los extractos son de solo anexar.
        foreach(["UPDATE erp_tes_extractos SET saldo_final_centavos=0","DELETE FROM erp_tes_extractos","UPDATE erp_tes_extracto_lineas SET monto_centavos=1","DELETE FROM erp_tes_extracto_lineas"] as $sql)self::assertFalse($this->db->query($sql),$sql);
    }
    public function testLoAutomaticoSoloVinculaLoInequivocoYLoAmbiguoSeDecideAMano():void
    {
        $this->libro();$c=$this->cuenta();$x=$this->marzo($c);
        $s=$this->tes()->sugerencias($x)['pares'];self::assertCount(6,$s);
        self::assertCount(2,$s[3]['candidatos'],'Dos cobros iguales el mismo día: ambiguo');
        self::assertCount(0,$s[5]['candidatos'],'La comisión aún no está en el libro');
        $this->rechazo(fn()=>$this->tes()->conciliarAutomatico($this->recepcion,$this->d(['extracto_id'=>$x])),403);
        $this->tes()->conciliarAutomatico($this->finanzas,$this->d(['extracto_id'=>$x]));
        $v=$this->r->q("SELECT metodo,cont_linea_id FROM erp_tes_conciliaciones WHERE estado='vigente' ORDER BY extracto_linea_id");
        self::assertCount(3,$v);self::assertSame(['automatica'],array_unique(array_column($v,'metodo')));
        self::assertSame([$this->lineaBanco($this->a['A1']),$this->lineaBanco($this->a['A2']),$this->lineaBanco($this->a['A3'])],array_map('intval',array_column($v,'cont_linea_id')));
        // Repetirlo no cambia nada.
        $this->tes()->conciliarAutomatico($this->finanzas,$this->d(['extracto_id'=>$x]));self::assertSame(3,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_tes_conciliaciones WHERE estado='vigente'")['n']);
    }
    public function testConciliacionManualExigeImporteExactoMismaCuentaYNotaSiLasFechasSeAlejan():void
    {
        $this->libro();$c=$this->cuenta();$x=$this->marzo($c);$this->tes()->conciliarAutomatico($this->owner,$this->d(['extracto_id'=>$x]));
        $cobroA=$this->linea($x,4);$cobroB=$this->linea($x,5);$comision=$this->linea($x,6);
        $m=fn(int $l,int $cl,string $nota='')=>$this->tes()->conciliar($this->finanzas,$this->d(['extracto_linea_id'=>$l,'cont_linea_id'=>$cl]+($nota!==''?['nota'=>$nota]:[])));
        $e=$this->rechazo(fn()=>$m($cobroA,$this->lineaBanco($this->a['A4'])),409);self::assertStringContainsString('coincidir exactamente',$e->getMessage());// 25,00 frente a 10,00
        $e=$this->rechazo(fn()=>$m($cobroA,$this->lineaBanco($this->a['A1'])),409);// A1 ya está conciliada con otra partida
        $caja=(int)$this->r->uno('SELECT l.id FROM erp_cont_lineas l JOIN erp_cont_cuentas c ON c.id=l.cuenta_id WHERE l.asiento_id=? AND c.codigo=?',[$this->a['A3'],'1.1.01'])['id'];
        $e=$this->rechazo(fn()=>$m($cobroA,$caja),422);self::assertStringContainsString('no pertenece',$e->getMessage());
        $m($cobroA,$this->lineaBanco($this->a['A5']));$m($cobroB,$this->lineaBanco($this->a['A6']));
        $this->rechazo(fn()=>$m($cobroA,$this->lineaBanco($this->a['A6'])),409);// la línea del extracto ya está conciliada
        // La comisión se registra con otra fecha: sin nota no se concilia; con nota sí.
        $a7=$this->banco('2026-03-28','-1.50','1.1.01','Comisión bancaria');
        $e=$this->rechazo(fn()=>$m($comision,$this->lineaBanco($a7)),422);self::assertStringContainsString('difieren',$e->getMessage());
        $id=$m($comision,$this->lineaBanco($a7),'Comisión del banco contabilizada al cierre del mes')['recurso_id'];self::assertGreaterThan(0,$id);
        self::assertSame(0,count(array_filter($this->tes()->sugerencias($x)['pares'])),'Todo el extracto de marzo quedó conciliado');
        $evento=$this->r->uno("SELECT datos_json FROM erp_eventos WHERE tipo='tes_conciliacion_manual' ORDER BY id DESC LIMIT 1");self::assertStringNotContainsString('Comisión',$evento['datos_json']);
    }
    public function testInformeExplicaLaDiferenciaYQuedaConciliadaConElExtractoSiguiente():void
    {
        $this->libro();$c=$this->cuenta();$x=$this->marzo($c);$this->tes()->conciliarAutomatico($this->owner,$this->d(['extracto_id'=>$x]));
        $i=$this->tes()->informe($c);
        // Antes de registrar la comisión ni conciliar los cobros: banco 138,50; libro 165,00 (100+50−30+25+10+10).
        self::assertSame(13850,$i['saldo_banco']);self::assertSame(16500,$i['saldo_libro']);self::assertSame(-2650,$i['diferencia']);self::assertSame(0,$i['diferencia_apertura']);
        self::assertSame(-150+2000,$i['extracto_sin_registrar_total']);self::assertSame(2500+2000,$i['libro_sin_extracto_total']);
        self::assertTrue($i['identidad_cumple']);self::assertFalse($i['conciliada']);
        // Se registra la comisión y se concilia todo marzo.
        $a7=$this->banco('2026-03-20','-1.50','1.1.01','Comisión bancaria');
        $this->tes()->conciliar($this->owner,$this->d(['extracto_linea_id'=>$this->linea($x,6),'cont_linea_id'=>$this->lineaBanco($a7)]));
        foreach([[4,'A5'],[5,'A6']] as [$n,$a])$this->tes()->conciliar($this->owner,$this->d(['extracto_linea_id'=>$this->linea($x,$n),'cont_linea_id'=>$this->lineaBanco($this->a[$a])]));
        $i=$this->tes()->informe($c);self::assertSame(-2500,$i['diferencia']);self::assertSame(2500,$i['libro_sin_extracto_total']);self::assertCount(1,$i['libro_sin_extracto']);self::assertSame([],$i['extracto_sin_registrar']);self::assertTrue($i['identidad_cumple']);self::assertFalse($i['conciliada']);
        // La partida en tránsito aparece en el extracto de abril, 21 días después: se concilia con nota.
        $y=$this->tes()->importar($this->owner,$this->d(['tes_cuenta_id'=>$c,'desde'=>'2026-04-01','hasta'=>'2026-04-30','saldo_inicial'=>'138.50','saldo_final'=>'163.50','extracto'=>"2026-04-02;Depósito en tránsito;;25.00"]))['recurso_id'];
        $this->tes()->conciliarAutomatico($this->owner,$this->d(['extracto_id'=>$y]));self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_tes_conciliaciones c JOIN erp_tes_extracto_lineas x ON x.id=c.extracto_linea_id WHERE x.extracto_id=?",[$y])['n'],'Fuera de la ventana no se sugiere nada');
        $this->tes()->conciliar($this->owner,$this->d(['extracto_linea_id'=>$this->linea($y,1),'cont_linea_id'=>$this->lineaBanco($this->a['A4']),'nota'=>'Depósito en tránsito acreditado en abril']));
        $i=$this->tes()->informe($c);
        self::assertSame(16350,$i['saldo_banco']);self::assertSame(16350,$i['saldo_libro']);self::assertSame(0,$i['diferencia']);self::assertTrue($i['conciliada']);self::assertSame('2026-04-30',$i['corte']);
    }
    public function testDeshacerConservaLaHistoriaYPermiteVolverAConciliar():void
    {
        $this->libro();$c=$this->cuenta();$x=$this->marzo($c);$this->tes()->conciliarAutomatico($this->owner,$this->d(['extracto_id'=>$x]));
        $v=$this->r->uno("SELECT id,extracto_linea_id,cont_linea_id FROM erp_tes_conciliaciones WHERE estado='vigente' ORDER BY id LIMIT 1");
        $this->rechazo(fn()=>$this->tes()->deshacer($this->recepcion,$this->d(['conciliacion_id'=>$v['id'],'motivo'=>'Error de importe'])),403);
        $this->rechazo(fn()=>$this->tes()->deshacer($this->owner,$this->d(['conciliacion_id'=>$v['id'],'motivo'=>'no'])),422);
        $this->tes()->deshacer($this->owner,$this->d(['conciliacion_id'=>$v['id'],'motivo'=>'Se vinculó con la partida equivocada']));
        $this->rechazo(fn()=>$this->tes()->deshacer($this->owner,$this->d(['conciliacion_id'=>$v['id'],'motivo'=>'Otra vez el mismo'])),404);
        self::assertSame('deshecha',$this->r->uno('SELECT estado FROM erp_tes_conciliaciones WHERE id=?',[$v['id']])['estado']);
        // Vuelve a ser candidata y puede conciliarse otra vez; la fila deshecha se conserva.
        $this->tes()->conciliar($this->owner,$this->d(['extracto_linea_id'=>$v['extracto_linea_id'],'cont_linea_id'=>$v['cont_linea_id']]));
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_tes_conciliaciones WHERE extracto_linea_id=?',[$v['extracto_linea_id']])['n']);
        // Las filas no se borran ni se reescriben (solo vigente → deshecha con su motivo).
        foreach(["DELETE FROM erp_tes_conciliaciones","UPDATE erp_tes_conciliaciones SET metodo='manual'","UPDATE erp_tes_conciliaciones SET estado='vigente' WHERE estado='deshecha'"] as $sql)self::assertFalse($this->db->query($sql),$sql);
        // La base impide dos vínculos vigentes sobre la misma partida aunque se salte el servicio.
        self::assertFalse($this->db->query("INSERT INTO erp_tes_conciliaciones(extracto_linea_id,cont_linea_id,metodo,creado_por,creado_en) VALUES(".(int)$v['extracto_linea_id'].",".(int)$v['cont_linea_id'].",'manual',1,NOW())"));
    }

    // ---- Interfaz ----
    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-tes'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/contabilidad';
    }
    private function pagina(array $get):string
    {
        $_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::contabilidad(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function estado(callable $f):int {$e=200;ob_start();try{$f();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;}
    private function post(array $p):int
    {
        $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';$_POST=$p+['idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-tes','vista'=>'tesoreria'];
        return $this->estado(fn()=>\Controllers\ErpController::accion());
    }
    public function testLaPantallaDeTesoreriaSeUsaDeExtremoAExtremoYRespetaLosPermisos():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->libro();$this->sesion($this->owner);
            $h=$this->pagina(['vista'=>'tesoreria']);self::assertStringContainsString('Tesorería bancaria',$h);self::assertStringContainsString('Aún no hay cuentas bancarias',$h);self::assertStringContainsString('Registrar cuenta bancaria',$h);self::assertStringContainsString('1.1.02',$h);
            self::assertSame(303,$this->post(['accion'=>'tes_cuenta','cuenta'=>'1.1.02','nombre'=>'Cuenta corriente','entidad'=>'Banco de ensayo','ultimos_digitos'=>'1234']));
            $c=(int)$this->r->uno('SELECT id FROM erp_tes_cuentas')['id'];
            $h=$this->pagina(['vista'=>'tesoreria','banco'=>(string)$c]);self::assertStringContainsString('Cuenta corriente',$h);self::assertStringContainsString('····1234',$h);self::assertStringContainsString('Importar extracto',$h);self::assertStringContainsString('Registrar cuenta bancaria',$h);
            self::assertSame(303,$this->post(['accion'=>'tes_extracto','banco'=>(string)$c,'tes_cuenta_id'=>(string)$c,'desde'=>'2026-03-01','hasta'=>'2026-03-31','saldo_inicial'=>'0.00','saldo_final'=>'138.50','extracto'=>self::MARZO]));
            $x=(int)$this->r->uno('SELECT id FROM erp_tes_extractos')['id'];
            $h=$this->pagina(['vista'=>'tesoreria','banco'=>(string)$c,'extracto'=>(string)$x]);self::assertStringContainsString('Comisión bancaria',$h);self::assertStringContainsString('Conciliar lo inequívoco',$h);self::assertStringContainsString('Conciliar con este asiento',$h);
            self::assertSame(303,$this->post(['accion'=>'tes_conciliar_auto','banco'=>(string)$c,'extracto'=>(string)$x,'extracto_id'=>(string)$x]));
            self::assertSame(3,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_tes_conciliaciones WHERE estado='vigente'")['n']);
            $h=$this->pagina(['vista'=>'tesoreria','banco'=>(string)$c,'extracto'=>(string)$x]);self::assertStringContainsString('Vínculos vigentes',$h);self::assertStringContainsString('automatica',$h);self::assertStringContainsString('Pendiente de conciliar',$h);
            // Una descripción maliciosa del extracto se escapa al mostrarse.
            $this->post(['accion'=>'tes_extracto','banco'=>(string)$c,'tes_cuenta_id'=>(string)$c,'desde'=>'2026-04-01','hasta'=>'2026-04-30','saldo_inicial'=>'138.50','saldo_final'=>'139.50','extracto'=>"2026-04-02;<script>alert(1)</script>;;1.00"]);
            $y=(int)$this->r->uno('SELECT MAX(id) n FROM erp_tes_extractos')['n'];$h=$this->pagina(['vista'=>'tesoreria','banco'=>(string)$c,'extracto'=>(string)$y]);self::assertStringNotContainsString('<script>alert(1)',$h);self::assertStringContainsString('&lt;script&gt;',$h);
            // Finanzas importa y concilia pero no registra cuentas; gerente solo lee; recepción no entra.
            $this->sesion($this->finanzas);$h=$this->pagina(['vista'=>'tesoreria','banco'=>(string)$c]);self::assertStringContainsString('Importar extracto',$h);self::assertStringNotContainsString('Registrar cuenta bancaria',$h);
            $gerente=R5CajaFixture::usuario($this->db,'gerente');$this->sesion($gerente);$h=$this->pagina(['vista'=>'tesoreria','banco'=>(string)$c]);self::assertStringContainsString('Conciliación de Cuenta corriente',$h);self::assertStringNotContainsString('Importar extracto',$h);
            $antes=(int)$this->r->uno('SELECT COUNT(*) n FROM erp_tes_extractos')['n'];
            $this->sesion($this->recepcion);self::assertSame(403,$this->estado(fn()=>\Controllers\ErpController::contabilidad(new \MVC\Router())));
            $this->post(['accion'=>'tes_extracto','tes_cuenta_id'=>(string)$c,'desde'=>'2026-05-01','hasta'=>'2026-05-31','saldo_inicial'=>'139.50','saldo_final'=>'140.50','extracto'=>"2026-05-02;Intento;;1.00"]);self::assertSame(403,http_response_code());
            self::assertSame($antes,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_tes_extractos')['n'],'Recepción no importa extractos');
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
