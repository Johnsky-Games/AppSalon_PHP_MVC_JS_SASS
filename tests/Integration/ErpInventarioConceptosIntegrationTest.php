<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpAprobacionService,ErpInventarioConceptoService,ErpContabilidadService,ErpIntegracionService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/** Ajustes de inventario aprobados, clasificados con conceptos configurables (migración 055). Las cuentas salen del plan base existente; no se siembra ninguna. */
final class ErpInventarioConceptosIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $prod;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('invconceptos');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('SKU-ENS','Insumo de ensayo','unidad',10000,250)");$this->prod=(int)$this->db->insert_id;
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function con():ErpInventarioConceptoService {return new ErpInventarioConceptoService($this->r);}
    private function apr():ErpAprobacionService {return new ErpAprobacionService($this->r);}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function rechazo(callable $f,int $codigo):ErpException {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function sqlFalla(string $sql):string {$ok=@$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}
    private function cuenta(string $tipo,int $n=0):string
    {
        $f=$this->r->q("SELECT c.codigo FROM erp_cont_cuentas c WHERE c.tipo=? AND c.imputable=1 AND c.activa=1 AND c.id NOT IN(SELECT cuenta_id FROM erp_cont_roles WHERE rol='inventario') ORDER BY c.codigo",[$tipo]);
        self::assertGreaterThan($n,count($f),'El plan base debe tener cuentas '.$tipo);return $f[$n]['codigo'];
    }
    private function concepto(string $nombre,string $naturaleza,?string $cuenta,?int $actor=null):int
    {
        return $this->con()->crear($actor??$this->owner,$this->d(['nombre'=>$nombre,'naturaleza'=>$naturaleza,'cuenta'=>$cuenta??'']))['recurso_id'];
    }
    private function solicitar(?int $concepto,string $sentido,string $cantidad,string $motivo='Conteo físico del almacén'):int
    {
        return $this->apr()->solicitar($this->recepcion,$this->d(['tipo'=>'ajuste_stock','producto_id'=>$this->prod,'movimiento'=>$sentido,'cantidad'=>$cantidad,'motivo'=>$motivo]+($concepto?['concepto_id'=>$concepto]:[])))['recurso_id'];
    }
    private function aprobar(int $solicitud):void {$this->apr()->resolver($this->owner,$this->d(['solicitud_id'=>$solicitud,'decision'=>'aprobar','nota'=>'Revisado']));}
    private function procesar():array {return (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>new ErpContabilidadAdaptador($this->r)],100);}
    private function saldo(string $codigo):int
    {
        $f=$this->r->uno('SELECT COALESCE(SUM(l.debe_centavos),0) d,COALESCE(SUM(l.haber_centavos),0) h FROM erp_cont_lineas l JOIN erp_cont_cuentas c ON c.id=l.cuenta_id WHERE c.codigo=?',[$codigo]);return (int)$f['d']-(int)$f['h'];
    }
    private function saldoInventario():int
    {
        $f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?',[$this->cont()->rolCuenta('inventario')]);return (int)$f['d']-(int)$f['h'];
    }
    private function stock():int {return (int)$this->r->uno('SELECT stock_milesimas s FROM inventario_productos WHERE id=?',[$this->prod])['s'];}

    public function testTodaSolicitudNuevaExigeUnConceptoActivoDeSentidoCoincidente():void
    {
        $merma=$this->concepto('Merma de insumos','merma_o_perdida',$this->cuenta('gasto'));
        $e=$this->rechazo(fn()=>$this->solicitar(null,'salida','1'),422);self::assertStringContainsString('Elige el concepto',$e->getMessage());
        $this->rechazo(fn()=>$this->solicitar(9999,'salida','1'),404);
        $this->rechazo(fn()=>$this->solicitar($merma,'entrada','1'),422);// la merma disminuye stock
        $this->con()->estado($this->owner,$this->d(['concepto_id'=>$merma,'activo'=>'0']));$this->rechazo(fn()=>$this->solicitar($merma,'salida','1'),409);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_solicitudes')['n'],'Nada de lo rechazado quedó solicitado');
        $this->con()->estado($this->owner,$this->d(['concepto_id'=>$merma,'activo'=>'1']));$s=$this->solicitar($merma,'salida','1');self::assertGreaterThan(0,$s);
    }
    public function testConceptosValidanNaturalezaCuentaPermisosYAuditoria():void
    {
        $this->rechazo(fn()=>$this->concepto('Sin permiso','merma_o_perdida',null,$this->finanzas),403);
        $this->rechazo(fn()=>$this->concepto('Cuenta inexistente','merma_o_perdida','9.9.99'),404);
        foreach([['merma_o_perdida','ingreso'],['faltante_de_conteo','patrimonio'],['sobrante_de_conteo','gasto'],['uso_interno_u_obsequio','costo']] as [$nat,$tipo]){
            try{$this->concepto('Mal tipo '.$nat,$nat,$this->cuenta($tipo));self::fail($nat);}catch(ErpException $e){self::assertStringContainsString('exige una cuenta de tipo',$e->getMessage(),$nat);}
        }
        $inv=$this->r->uno("SELECT c.codigo FROM erp_cont_roles r JOIN erp_cont_cuentas c ON c.id=r.cuenta_id WHERE r.rol='inventario'")['codigo'];
        $this->rechazo(fn()=>$this->concepto('Contra el propio inventario','sobrante_de_conteo',$inv),422);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_conceptos')['n'],'Nada de lo rechazado quedó guardado');
        $k=$this->concepto('Faltante','faltante_de_conteo',$this->cuenta('gasto'));$this->rechazo(fn()=>$this->concepto('Faltante','faltante_de_conteo',null),409);
        $this->rechazo(fn()=>$this->con()->asignarCuenta($this->finanzas,$this->d(['concepto_id'=>$k,'cuenta'=>$this->cuenta('gasto',1)])),403);
        $this->con()->asignarCuenta($this->owner,$this->d(['concepto_id'=>$k,'cuenta'=>$this->cuenta('gasto',1)]));
        self::assertSame(['inv_concepto_creado','inv_concepto_cuenta'],array_column($this->r->q("SELECT tipo FROM erp_eventos WHERE tipo LIKE 'inv_concepto%' AND entidad_id=$k ORDER BY id"),'tipo'));
        self::assertStringContainsString('solo cambian',$this->sqlFalla("UPDATE erp_inv_conceptos SET nombre='otro' WHERE id=$k"));self::assertStringContainsString('desactiva',$this->sqlFalla('DELETE FROM erp_inv_conceptos'));
    }
    public function testElAjusteAprobadoSeValorizaConSuCostoYUsaLaCuentaDeSuConcepto():void
    {
        $gas=$this->cuenta('gasto');$ing=$this->cuenta('ingreso');
        $merma=$this->concepto('Merma de insumos','merma_o_perdida',$gas);$sobrante=$this->concepto('Sobrante de conteo','sobrante_de_conteo',$ing);
        $s1=$this->solicitar($merma,'salida','2');$s2=$this->solicitar($sobrante,'entrada','1.5');
        $this->aprobar($s1);$this->aprobar($s2);
        self::assertSame(10000-2000+1500,$this->stock(),'El stock se ajusta al aprobar');
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        // 2,000 u × 2,50 = 5,00 de merma (Dr gasto · Cr inventario); 1,500 u × 2,50 = 3,75 de sobrante (Dr inventario · Cr ingreso).
        self::assertSame(500,$this->saldo($gas));self::assertSame(-375,$this->saldo($ing));self::assertSame(-500+375,$this->saldoInventario());
        self::assertCount(2,$this->r->q("SELECT id FROM erp_cont_asientos WHERE concepto LIKE 'Ajuste de inventario:%'"));
        self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$this->cont()->integridad());
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertCount(2,$this->r->q("SELECT id FROM erp_cont_asientos WHERE concepto LIKE 'Ajuste de inventario:%'"),'Reprocesar no duplica');
        $ev=$this->r->uno("SELECT actor_id a,datos_json d FROM erp_eventos WHERE tipo='inventario_ajuste' ORDER BY id LIMIT 1");self::assertSame($this->owner,(int)$ev['a']);self::assertStringContainsString('concepto_id',$ev['d']);self::assertStringNotContainsStringIgnoringCase('idem_key',$ev['d']);
        self::assertStringContainsString('no se edita',$this->sqlFalla('UPDATE erp_inv_ajuste_conceptos SET concepto_id=concepto_id'));
    }
    public function testTrazabilidadCorreccionYAprobacionRepetidaNoDuplicanNiReescribenElLibro():void
    {
        $gas=$this->cuenta('gasto');$otra=$this->cuenta('gasto',1);$ing=$this->cuenta('ingreso');
        $merma=$this->concepto('Merma de insumos','merma_o_perdida',$gas);$sobrante=$this->concepto('Sobrante de conteo','sobrante_de_conteo',$ing);
        $s=$this->solicitar($merma,'salida','2');$this->aprobar($s);
        // Aprobar de nuevo la misma solicitud no la reaplica: una sola clasificación, un solo movimiento y un solo evento.
        try{$this->aprobar($s);}catch(ErpException $e){self::assertContains($e->getCode(),[404,409,422],$e->getMessage());}
        self::assertSame(8000,$this->stock(),'El stock se ajustó una sola vez');
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_ajuste_conceptos WHERE solicitud_id=?',[$s])['n']);
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='inventario_ajuste'")['n']);
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        // Trazabilidad: solicitud → movimiento → evento → asiento, con el concepto y quien lo creó.
        $c=$this->r->uno('SELECT movimiento_id,concepto_id,evento_id,creado_por FROM erp_inv_ajuste_conceptos WHERE solicitud_id=?',[$s]);
        self::assertSame($merma,(int)$c['concepto_id']);self::assertSame($this->owner,(int)$c['creado_por']);self::assertGreaterThan(0,(int)$c['movimiento_id']);self::assertGreaterThan(0,(int)$c['evento_id']);
        $asientos=$this->r->q("SELECT id,origen FROM erp_cont_asientos WHERE concepto LIKE 'Ajuste de inventario:%'");self::assertCount(1,$asientos);
        self::assertStringContainsString((string)$c['evento_id'],(string)$asientos[0]['origen'],'El asiento remite al evento que lo originó');
        // Cambiar la cuenta del concepto no reescribe el asiento ya publicado (inmutable): el gasto sigue en la cuenta original.
        $this->con()->asignarCuenta($this->owner,$this->d(['concepto_id'=>$merma,'cuenta'=>$otra]));$this->procesar();
        self::assertSame(500,$this->saldo($gas));self::assertSame(0,$this->saldo($otra),'El asiento anterior no se movió de cuenta');
        // Corrección: un ajuste contrario aprobado (el inventario es de solo anexar) deja el stock y la cuenta de inventario como estaban.
        $this->aprobar($this->solicitar($sobrante,'entrada','2'));$x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame(10000,$this->stock(),'La corrección devuelve el stock');self::assertSame(0,$this->saldoInventario(),'Merma 5,00 y sobrante 5,00 se compensan en inventario');
        self::assertSame(500,$this->saldo($gas),'El gasto original se conserva: la corrección no lo borra');self::assertSame(-500,$this->saldo($ing));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertCount(2,$this->r->q("SELECT id FROM erp_cont_asientos WHERE concepto LIKE 'Ajuste de inventario:%'"),'Reprocesar no duplica');
        self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$this->cont()->integridad());
    }
    public function testSinCuentaSoloQuedaPendienteLaContabilizacionDeEseAjuste():void
    {
        $ok=$this->concepto('Merma con cuenta','merma_o_perdida',$this->cuenta('gasto'));$falta=$this->concepto('Faltante sin cuenta','faltante_de_conteo',null);
        $a=$this->solicitar($falta,'salida','1');$b=$this->solicitar($ok,'salida','1');$this->aprobar($a);$this->aprobar($b);
        self::assertSame(10000-2000,$this->stock(),'Ambos ajustes se aplicaron al stock');
        $x=$this->procesar();self::assertSame(1,$x['fallidos'],'Solo falla el ajuste sin configuración');
        self::assertCount(1,$this->r->q("SELECT id FROM erp_cont_asientos WHERE concepto='Ajuste de inventario: Merma con cuenta'"));
        $p=$this->con()->pendientes();self::assertCount(1,$p);self::assertStringContainsString('Asigna una cuenta del plan',$p[0]['falta']);self::assertSame('Faltante sin cuenta',$p[0]['concepto']);
        $this->con()->asignarCuenta($this->owner,$this->d(['concepto_id'=>$falta,'cuenta'=>$this->cuenta('gasto',1)]));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame([],$this->con()->pendientes());self::assertSame(-2*250,$this->saldoInventario(),"Dos ajustes de 1 unidad a 2,50");
    }
    public function testUnAjusteCuyoValorNoCabeEnUnAsientoSeRechazaAntesDeTocarElStock():void
    {
        $k=$this->concepto('Sobrante grande','sobrante_de_conteo',$this->cuenta('ingreso'));
        $this->db->query("UPDATE inventario_productos SET costo_centavos=100000000 WHERE id={$this->prod}");// 1 000 000,00 por unidad
        $s=$this->solicitar($k,'entrada','1000');// 1 000 u × 1 000 000,00 = 1 000 000 000,00 > máximo de un asiento
        $e=$this->rechazo(fn()=>$this->aprobar($s),422);self::assertStringContainsString('excede el máximo de un asiento',$e->getMessage());
        self::assertSame(10000,$this->stock(),'El stock no se tocó');self::assertSame('solicitada',$this->r->uno('SELECT estado FROM erp_solicitudes WHERE id=?',[$s])['estado']);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_ajuste_conceptos')['n']);
        $ok=$this->solicitar($k,'entrada','1');$this->aprobar($ok);self::assertSame(11000,$this->stock(),'Un ajuste que sí cabe se aplica');
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
    }
    public function testLasSolicitudesAnterioresSinConceptoNoSeClasificanNiSeContabilizanSolas():void
    {
        // Solicitud pendiente creada antes de los conceptos (sin concepto_id).
        $this->db->query("INSERT INTO erp_solicitudes(tipo,datos_json,motivo,solicitante_id,creado_en) VALUES('ajuste_stock','".json_encode(['producto_id'=>$this->prod,'tipo'=>'salida','cantidad'=>'1','motivo'=>'Previa a los conceptos'])."','Previa a los conceptos',{$this->recepcion},NOW())");
        $id=(int)$this->db->insert_id;$this->aprobar($id);
        self::assertSame(9000,$this->stock(),'El ajuste se aplicó como antes');self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_ajuste_conceptos')['n'],'No se clasificó');
        self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='inventario_ajuste'")['n']);$this->procesar();self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE concepto LIKE 'Ajuste de inventario:%'")['n']);
    }

    // ---- Pantallas ----
    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-ic'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/contabilidad';
    }
    private function pagina(string $metodo,array $get):string
    {
        $_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::$metodo(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function estado(callable $f):int {$e=200;ob_start();try{$f();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;}
    public function testPantallasYFormularios():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->sesion($this->owner);
            $h=$this->pagina('contabilidad',['vista'=>'inventario']);self::assertStringContainsString('Conceptos de ajuste de inventario',$h);self::assertStringContainsString('El sistema no trae conceptos ni códigos de cuenta',$h);
            $h=$this->pagina('aprobaciones',[]);self::assertStringContainsString('No hay conceptos de ajuste configurados',$h);
            $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';
            $_POST=['accion'=>'inv_concepto','vista'=>'inventario','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-ic','nombre'=>'Merma por pantalla','naturaleza'=>'merma_o_perdida','cuenta'=>''];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_conceptos')['n']);
            $h=$this->pagina('contabilidad',['vista'=>'inventario']);self::assertStringContainsString('Merma por pantalla',$h);self::assertStringContainsString('Asigna una cuenta del plan contable',$h);
            $h=$this->pagina('aprobaciones',[]);self::assertStringContainsString('name="concepto_id"',$h);self::assertStringContainsString('Merma por pantalla',$h);
            $this->sesion($this->finanzas);$f=$this->pagina('contabilidad',['vista'=>'inventario']);self::assertStringNotContainsString('Crear concepto',$f);self::assertStringNotContainsString('Asignar cuenta',$f);
            $this->sesion($this->recepcion);$_SERVER['REQUEST_METHOD']='GET';self::assertSame(403,$this->estado(fn()=>\Controllers\ErpController::contabilidad(new \MVC\Router())));
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
