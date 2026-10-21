<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpCajaService,ErpCajaConceptoService,ErpContabilidadService,ErpIntegracionService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/**
 * Movimientos manuales de efectivo clasificados con conceptos configurables (migración 054). Los conceptos y sus cuentas se crean en la prueba con cuentas del plan
 * base existente; el sistema no siembra ninguno.
 */
final class ErpCajaConceptosIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('cajaconceptos');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function caja():ErpCajaService {return new ErpCajaService($this->r);}
    private function con():ErpCajaConceptoService {return new ErpCajaConceptoService($this->r);}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function rechazo(callable $f,int $codigo):ErpException {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function sqlFalla(string $sql):string {$ok=@$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}
    /** Código de una cuenta imputable y activa del plan base de ese tipo que no sea la de caja. */
    private function cuenta(string $tipo,int $n=0):string
    {
        $f=$this->r->q("SELECT c.codigo FROM erp_cont_cuentas c WHERE c.tipo=? AND c.imputable=1 AND c.activa=1 AND c.id NOT IN(SELECT cuenta_id FROM erp_cont_roles WHERE rol='caja') ORDER BY c.codigo",[$tipo]);
        self::assertGreaterThan($n,count($f),'El plan base debe tener cuentas '.$tipo);return $f[$n]['codigo'];
    }
    private function concepto(string $nombre,string $naturaleza,?string $cuenta,?int $actor=null):int
    {
        return $this->con()->crear($actor??$this->owner,$this->d(['nombre'=>$nombre,'naturaleza'=>$naturaleza,'cuenta'=>$cuenta??'']))['recurso_id'];
    }
    private function abrir(int $actor,string $fondo='100'):int {return $this->caja()->abrir($actor,$this->d(['fondo'=>$fondo]))['recurso_id'];}
    private function mov(int $actor,int $concepto,string $monto,string $motivo='Movimiento de ensayo'):int
    {
        return $this->caja()->movimiento($actor,$this->d(['concepto_id'=>$concepto,'monto'=>$monto,'motivo'=>$motivo]))['recurso_id'];
    }
    private function procesar():array {return (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>new ErpContabilidadAdaptador($this->r)],100);}
    private function ultimoMov():int {return (int)$this->r->uno('SELECT MAX(caja_id) m FROM erp_caja_mov_conceptos')['m'];}
    /** Debe − haber de una cuenta por código, contando todo el libro. */
    private function saldo(string $codigo):int
    {
        $f=$this->r->uno('SELECT COALESCE(SUM(l.debe_centavos),0) d,COALESCE(SUM(l.haber_centavos),0) h FROM erp_cont_lineas l JOIN erp_cont_cuentas c ON c.id=l.cuenta_id WHERE c.codigo=?',[$codigo]);return (int)$f['d']-(int)$f['h'];
    }
    private function saldoCaja():int
    {
        $f=$this->r->uno("SELECT COALESCE(SUM(l.debe_centavos),0) d,COALESCE(SUM(l.haber_centavos),0) h FROM erp_cont_lineas l WHERE l.cuenta_id=?",[$this->cont()->rolCuenta('caja')]);return (int)$f['d']-(int)$f['h'];
    }
    private function control():array {foreach($this->cont()->conciliacion('2099-12-31')['controles'] as $c)if($c['nombre']==='Caja en efectivo')return $c;self::fail('Falta el control de caja');}

    public function testSinConceptoNoHayMovimientoManualNiSinPermiso():void
    {
        $k=$this->concepto('Aporte de ensayo','aporte_propietario',$this->cuenta('patrimonio'));
        $this->abrir($this->finanzas);
        $this->rechazo(fn()=>$this->caja()->movimiento($this->finanzas,$this->d(['monto'=>'10','motivo'=>'Sin concepto elegido'])),422);
        $this->rechazo(fn()=>$this->caja()->movimiento($this->finanzas,$this->d(['concepto_id'=>$k,'monto'=>'10','motivo'=>'ok'])),422);// motivo muy corto
        $this->rechazo(fn()=>$this->caja()->movimiento($this->finanzas,$this->d(['concepto_id'=>$k,'tipo'=>'egreso','monto'=>'10','motivo'=>'Sentido distinto al del concepto'])),422);
        $this->rechazo(fn()=>$this->caja()->movimiento($this->finanzas,$this->d(['concepto_id'=>9999,'monto'=>'10','motivo'=>'Concepto inexistente'])),404);
        $this->abrir($this->recepcion);$this->rechazo(fn()=>$this->caja()->movimiento($this->recepcion,$this->d(['concepto_id'=>$k,'monto'=>'10','motivo'=>'Sin permiso de movimientos'])),403);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_mov_conceptos')['n'],'Nada de lo rechazado quedó registrado');
        $this->mov($this->finanzas,$k,'10');self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_mov_conceptos')['n']);
        // Un concepto desactivado no admite movimientos nuevos.
        $this->con()->estado($this->owner,$this->d(['concepto_id'=>$k,'activo'=>'0']));$this->rechazo(fn()=>$this->mov($this->finanzas,$k,'5'),409);
    }
    public function testConceptosValidanNaturalezaCuentaYPermisos():void
    {
        $this->rechazo(fn()=>$this->concepto('Sin permiso','gasto',null,$this->finanzas),403);
        $this->rechazo(fn()=>$this->concepto('Cuenta que no existe','gasto','9.9.99'),404);
        foreach([['gasto','ingreso'],['aporte_propietario','gasto'],['sobrante','gasto'],['faltante','ingreso'],['deposito_a_banco','patrimonio']] as [$nat,$tipo]){
            try{$this->concepto('Mal tipo '.$nat,$nat,$this->cuenta($tipo));self::fail($nat);}catch(ErpException $e){self::assertStringContainsString('exige una cuenta de tipo',$e->getMessage(),$nat);}
        }
        $caja=$this->r->uno("SELECT c.codigo FROM erp_cont_roles r JOIN erp_cont_cuentas c ON c.id=r.cuenta_id WHERE r.rol='caja'")['codigo'];
        $e=$this->rechazo(fn()=>$this->concepto('Depósito a la misma caja','deposito_a_banco',$caja),422);self::assertStringContainsString('misma cuenta de caja',$e->getMessage());
        try{$this->concepto('Naturaleza rara','compra',null);self::fail('naturaleza');}catch(ErpException $e){self::assertContains($e->getCode(),[0,422]);}
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_conceptos')['n'],'Nada de lo rechazado quedó guardado');
        $k=$this->concepto('Gasto menor','gasto',$this->cuenta('gasto'));$this->rechazo(fn()=>$this->concepto('Gasto menor','gasto',null),409);
        $this->rechazo(fn()=>$this->con()->asignarCuenta($this->finanzas,$this->d(['concepto_id'=>$k,'cuenta'=>$this->cuenta('gasto',1)])),403);
        $this->con()->asignarCuenta($this->owner,$this->d(['concepto_id'=>$k,'cuenta'=>$this->cuenta('gasto',1)]));
        self::assertSame(['caja_concepto_creado','caja_concepto_cuenta'],array_column($this->r->q("SELECT tipo FROM erp_eventos WHERE tipo LIKE 'caja_concepto%' AND entidad_id=$k ORDER BY id"),'tipo'));
        self::assertStringContainsString('solo cambian',$this->sqlFalla("UPDATE erp_caja_conceptos SET nombre='otro' WHERE id=$k"));self::assertStringContainsString('desactiva',$this->sqlFalla('DELETE FROM erp_caja_conceptos'));
    }
    public function testCadaNaturalezaSeContabilizaConLaCuentaDeSuConceptoYNoComoIngresoOGastoPorDefecto():void
    {
        $pat=$this->cuenta('patrimonio');$pat2=$this->cuenta('patrimonio',1);$ban=$this->cuenta('activo');$gas=$this->cuenta('gasto');$ing=$this->cuenta('ingreso');
        $cs=[['Aporte del dueño','aporte_propietario',$pat,'50.00'],['Retiro del dueño','retiro_propietario',$pat2,'20.00'],['Depósito al banco','deposito_a_banco',$ban,'30.00'],['Retiro del banco','retiro_de_banco',$ban,'12.00'],['Útiles','gasto',$gas,'5.00'],['Sobrante de arqueo','sobrante',$ing,'2.00'],['Faltante de arqueo','faltante',$this->cuenta('gasto',1),'1.00']];
        $this->abrir($this->owner,'0');$neto=0;
        foreach($cs as [$n,$nat,$cu,$monto]){$k=$this->concepto($n,$nat,$cu);$this->mov($this->owner,$k,$monto);$neto+=(in_array($nat,['aporte_propietario','retiro_de_banco','sobrante'],true)?1:-1)*(int)round($monto*100);}
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame($neto,$this->saldoCaja(),'La caja del libro refleja exactamente los movimientos');
        // Contrapartidas: cada una en la cuenta de SU concepto.
        self::assertSame(-5000,$this->saldo($pat),'El aporte acredita la cuenta de patrimonio de su concepto');self::assertSame(2000,$this->saldo($pat2),'El retiro debita la cuenta de patrimonio de SU concepto');
        self::assertSame(3000-1200,$this->saldo($ban),'Depósito (debe 30,00) menos retiro (haber 12,00) en la cuenta de banco elegida');
        self::assertSame(500,$this->saldo($gas));self::assertSame(-200,$this->saldo($ing));
        $asientos=$this->r->q("SELECT total_centavos,concepto,tipo FROM erp_cont_asientos ORDER BY id");self::assertCount(7,$asientos);self::assertSame('automatico',$asientos[0]['tipo']);
        self::assertSame(0,$this->control()['diferencia'],'El control de caja cuadra con los movimientos manuales contabilizados');
        self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$this->cont()->integridad());
        // Auditoría del movimiento: actor, concepto y sin claves de idempotencia.
        $ev=$this->r->uno("SELECT actor_id a,datos_json d FROM erp_eventos WHERE tipo='caja_movimiento' ORDER BY id LIMIT 1");self::assertSame($this->owner,(int)$ev['a']);self::assertStringContainsString('concepto_id',$ev['d']);self::assertStringNotContainsStringIgnoringCase('idem_key',$ev['d']);
    }
    public function testSinConfiguracionSoloQuedaPendienteLaContabilizacionAfectada():void
    {
        $ok=$this->concepto('Gasto con cuenta','gasto',$this->cuenta('gasto'));$falta=$this->concepto('Depósito sin cuenta','deposito_a_banco',null);
        $this->abrir($this->owner,'100');
        $a=$this->mov($this->owner,$falta,'30.00','Depósito sin cuenta aún');$b=$this->mov($this->owner,$ok,'4.00','Gasto menor del día');
        $x=$this->procesar();self::assertSame(1,$x['fallidos'],'Solo falla el movimiento sin configuración');
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE concepto LIKE 'Caja: Gasto con cuenta'")['n'],'El otro movimiento sí se contabilizó');
        self::assertSame(2,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_mov_conceptos')['n']);self::assertSame(100*100-3000-400,$this->caja()->esperado((int)$this->r->uno('SELECT id FROM erp_caja_sesiones')['id']),'La caja siempre se registró');
        $p=$this->con()->pendientes();self::assertCount(1,$p);self::assertSame($a,$p[0]['movimiento']);self::assertStringContainsString('Asigna una cuenta del plan',$p[0]['falta']);self::assertStringContainsString('Depósito sin cuenta',$p[0]['falta']);
        $probl=array_column($this->con()->conceptos(),'problema','nombre');self::assertNull($probl['Gasto con cuenta']);self::assertNotNull($probl['Depósito sin cuenta']);
        $this->con()->asignarCuenta($this->owner,$this->d(['concepto_id'=>$falta,'cuenta'=>$this->cuenta('activo')]));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame([],$this->con()->pendientes(),'Al completar la configuración se contabiliza sin esperar el reintento');
        self::assertSame(0,$this->control()['diferencia']);self::assertNotSame($a,$b,'Dos movimientos distintos');
    }
    public function testReversoConservaElOriginalYSoloLoHaceQuienTienePermisoUnaVez():void
    {
        $k=$this->concepto('Retiro del dueño','retiro_propietario',$this->cuenta('patrimonio'));$this->abrir($this->owner,'50');
        $m=$this->mov($this->owner,$k,'20.00');$this->procesar();self::assertSame(-2000,$this->saldoCaja());
        $this->abrir($this->finanzas,'0');$this->abrir($this->recepcion,'0');
        $this->rechazo(fn()=>$this->caja()->movimientoReversar($this->recepcion,$this->d(['movimiento_id'=>$m,'motivo'=>'Sin permiso contable'])),403);
        $this->rechazo(fn()=>$this->caja()->movimientoReversar($this->finanzas,$this->d(['movimiento_id'=>$m,'motivo'=>'x'])),422);
        $this->rechazo(fn()=>$this->caja()->movimientoReversar($this->finanzas,$this->d(['movimiento_id'=>999999,'motivo'=>'No existe ese movimiento'])),404);
        $r=$this->caja()->movimientoReversar($this->finanzas,$this->d(['movimiento_id'=>$m,'motivo'=>'Se registró con el concepto equivocado']))['recurso_id'];
        $this->rechazo(fn()=>$this->caja()->movimientoReversar($this->owner,$this->d(['movimiento_id'=>$m,'motivo'=>'Segundo reverso del mismo'])),409);
        $this->rechazo(fn()=>$this->caja()->movimientoReversar($this->owner,$this->d(['movimiento_id'=>$r,'motivo'=>'Reverso de un reverso'])),409);
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame(0,$this->saldoCaja(),'El libro vuelve a cero');self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE tipo='reverso'")['n']);
        self::assertSame('egreso',$this->r->uno('SELECT tipo FROM caja_movimientos WHERE id=?',[$m])['tipo']);self::assertSame('ingreso',$this->r->uno('SELECT tipo FROM caja_movimientos WHERE id=?',[$r])['tipo'],'El original se conserva y el reverso es otro movimiento');
        self::assertSame($this->finanzas,(int)$this->r->uno("SELECT actor_id a FROM erp_eventos WHERE tipo='caja_movimiento_reversado'")['a']);self::assertSame(0,$this->control()['diferencia']);
        self::assertStringContainsString('no se edita',$this->sqlFalla("UPDATE erp_caja_mov_conceptos SET motivo='otro motivo' WHERE caja_id=$m"));self::assertStringContainsString('se conserva',$this->sqlFalla('DELETE FROM erp_caja_mov_conceptos'));
        // Sin caja abierta no hay dónde registrar el movimiento contrario.
        $k2=$this->concepto('Aporte','aporte_propietario',$this->cuenta('patrimonio'));$m2=$this->mov($this->owner,$k2,'3.00');
        $otro=R5CajaFixture::usuario($this->db,'finanzas');$this->rechazo(fn()=>$this->caja()->movimientoReversar($otro,$this->d(['movimiento_id'=>$m2,'motivo'=>'Sin caja abierta propia'])),422);
    }
    public function testReversoDeUnMovimientoAnteriorAlInicioContableSeContabilizaComoMovimientoActual():void
    {
        $k=$this->concepto('Retiro del dueño','retiro_propietario',$this->cuenta('patrimonio'));$this->abrir($this->owner,'50');
        // El original ocurre antes del inicio contable: se confirma sin asiento.
        $this->db->query("UPDATE erp_cont_config SET inicio_contable='2099-01-01' WHERE id=1");
        $m=$this->mov($this->owner,$k,'20.00');$x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'],'El original previo al inicio no tiene asiento');
        $this->db->query("UPDATE erp_cont_config SET inicio_contable='2026-01-01' WHERE id=1");
        $this->caja()->movimientoReversar($this->owner,$this->d(['movimiento_id'=>$m,'motivo'=>'Se registró con el concepto equivocado']));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'],'El reverso mueve el efectivo de hoy y debe quedar en el libro');
        self::assertSame(2000,$this->saldoCaja(),'Entró efectivo hoy: Dr caja');self::assertSame('automatico',$this->r->uno('SELECT tipo FROM erp_cont_asientos')['tipo'],'No es una anulación: no hay asiento que revertir');
        self::assertSame(-2000,$this->saldo($this->cuenta('patrimonio')));
    }
    public function testLosMovimientosAnterioresNoSeReclasificanSolos():void
    {
        $id=$this->abrir($this->owner,'20');
        // Un movimiento manual «histórico»: existe en caja pero nunca se clasificó.
        $hist=$this->caja()->asentar($this->owner,$id,'egreso',700,'efectivo','Movimiento previo a los conceptos','hist:1');
        $k=$this->concepto('Gasto menor','gasto',$this->cuenta('gasto'));$this->mov($this->owner,$k,'1.00');
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertNull($this->r->uno('SELECT caja_id FROM erp_caja_mov_conceptos WHERE caja_id=?',[$hist]),'El histórico no se clasificó');
        self::assertSame(-100,$this->saldoCaja(),'Solo lo clasificado entró al libro');
        self::assertSame(700,$this->control()['diferencia'],'La diferencia del control es exactamente el movimiento histórico sin clasificar');
    }

    // ---- Pantallas ----
    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-cc'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/contabilidad';
    }
    private function pagina(string $metodo,array $get):string
    {
        $_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::$metodo(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function estado(callable $f):int {$e=200;ob_start();try{$f();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;}
    public function testPantallasYFormulariosDeConceptosYMovimiento():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->sesion($this->owner);
            $h=$this->pagina('contabilidad',['vista'=>'caja']);self::assertStringContainsString('Conceptos de caja',$h);self::assertStringContainsString('El sistema no trae conceptos ni códigos de cuenta',$h);
            $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';
            $_POST=['accion'=>'caja_concepto','vista'=>'caja','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-cc','nombre'=>'Gasto por pantalla','naturaleza'=>'gasto','cuenta'=>''];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_conceptos')['n']);
            $h=$this->pagina('contabilidad',['vista'=>'caja']);self::assertStringContainsString('Gasto por pantalla',$h);self::assertStringContainsString('Asigna una cuenta del plan contable',$h);
            $k=(int)$this->r->uno('SELECT id FROM erp_caja_conceptos')['id'];
            $this->abrir($this->owner,'20');$h=$this->pagina('caja',[]);self::assertStringContainsString('Gasto por pantalla',$h);self::assertStringContainsString('name="concepto_id"',$h);
            $_SERVER['REQUEST_METHOD']='POST';$_POST=['accion'=>'caja_movimiento','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-cc','concepto_id'=>(string)$k,'monto'=>'3.00','motivo'=>'Compra de útiles'];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_caja_mov_conceptos')['n']);
            $this->procesar();$h=$this->pagina('contabilidad',['vista'=>'caja']);self::assertStringContainsString('Asigna una cuenta del plan',$h,'Aparece qué debe completarse');
            // Finanzas reversa pero no configura; recepción no entra a contabilidad.
            $this->sesion($this->finanzas);$f=$this->pagina('contabilidad',['vista'=>'caja']);self::assertStringNotContainsString('Crear concepto',$f);self::assertStringNotContainsString('Asignar cuenta',$f);
            $this->sesion($this->recepcion);$_SERVER['REQUEST_METHOD']='GET';self::assertSame(403,$this->estado(fn()=>\Controllers\ErpController::contabilidad(new \MVC\Router())));
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
