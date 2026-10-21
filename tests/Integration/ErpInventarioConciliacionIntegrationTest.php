<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpContabilidadService,ErpIntegracionService,ErpMaestrosService,ErpVentaService,ErpCajaService,ErpPagoService,ErpEntregaService,ErpCompraService,ErpAprobacionService,ErpInventarioConceptoService,ErpException};
use Services\Contabilidad\{ErpContabilidadAdaptador,InventarioValorizadoConciliacion};

/**
 * Control de conciliación inventario valorizado ↔ libro: solo lectura, con fecha de corte, desglose por origen y estado (pendientes, fallidos, anteriores al inicio contable,
 * ajustes sin clasificar, movimientos heredados, contabilizados después del corte) y partidas que suman exactamente la diferencia. Nunca genera ajustes.
 */
final class ErpInventarioConciliacionIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $contacto;private int $producto;private int $catalogo;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('invconc');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');$cli=R5CajaFixture::usuario($this->db);
        $this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$cli]);
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->owner,$this->d(['nombre'=>'Clienta','tipo'=>'cliente']))['recurso_id'];$m->vincularCliente($this->owner,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$cli]));
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('IC-1','Champú','unidad',0,0)");$this->producto=$this->r->id();
        $this->catalogo=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'10.00','impuesto'=>'15.00','tipo'=>'venta']))['recurso_id'];
        (new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function con():ErpInventarioConceptoService {return new ErpInventarioConceptoService($this->r);}
    private function inv():InventarioValorizadoConciliacion {return new InventarioValorizadoConciliacion($this->r);}
    private function procesar():array {return (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>new ErpContabilidadAdaptador($this->r)],100);}
    private function contabilizar():void {for($i=0;$i<6;$i++){$x=$this->procesar();if(!$x['confirmados'])break;}}
    private function cuenta(string $tipo,int $n=0):string
    {
        $f=$this->r->q("SELECT c.codigo FROM erp_cont_cuentas c WHERE c.tipo=? AND c.imputable=1 AND c.activa=1 AND c.id NOT IN(SELECT cuenta_id FROM erp_cont_roles WHERE rol='inventario') ORDER BY c.codigo",[$tipo]);return $f[$n]['codigo'];
    }
    private function partida(array $inf,string $codigo):?array {foreach($inf['partidas'] as $p)if($p['codigo']===$codigo)return $p;return null;}
    private function clase(array $inf,string $clase):array {foreach($inf['por_origen'] as $o)if($o['clase']===$clase)return $o;self::fail($clase);}
    /** Compra de $cant unidades a $costo recibida (genera su movimiento y su hecho contable). */
    private function recibir(string $cant,string $costo):void
    {
        $c=new ErpCompraService($this->r);$prov=(new ErpMaestrosService($this->r))->contacto($this->owner,$this->d(['nombre'=>'Proveedor '.bin2hex(random_bytes(2)),'tipo'=>'proveedor']))['recurso_id'];
        $compra=$c->crear($this->owner,$this->d(['proveedor_id'=>$prov]))['recurso_id'];$c->agregar($this->owner,$this->d(['compra_id'=>$compra,'producto_id'=>$this->producto,'cantidad'=>$cant,'costo'=>$costo,'impuesto'=>'15.00']));
        $total=number_format((int)$this->r->uno('SELECT total_centavos FROM erp_compras WHERE id=?',[$compra])['total_centavos']/100,2,'.','');$c->ordenar($this->owner,$this->d(['compra_id'=>$compra,'total_esperado'=>$total]));
        $linea=(int)$this->r->uno('SELECT id FROM erp_compra_lineas WHERE compra_id=? ORDER BY id DESC LIMIT 1',[$compra])['id'];$c->recibir($this->owner,$this->d(['compra_id'=>$compra,'linea_id'=>$linea,'cantidad'=>$cant,'referencia'=>'Guía']));
    }
    private function entregar(string $cant):void
    {
        $v=new ErpVentaService($this->r);$id=$v->crear($this->owner,$this->d(['contacto_id'=>$this->contacto]))['recurso_id'];$v->agregar($this->owner,$this->d(['venta_id'=>$id,'catalogo_id'=>$this->catalogo,'cantidad'=>$cant]));
        $v->confirmar($this->owner,$this->d(['venta_id'=>$id,'version'=>(int)$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$id])['version']]));
        $total=number_format($v->resumen($id)['total']/100,2,'.','');(new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$id,'monto'=>$total,'recibido'=>$total,'metodo'=>'efectivo']));
        (new ErpEntregaService($this->r))->entregar($this->owner,$this->d(['venta_id'=>$id]));
    }
    private function concepto(string $nombre,string $nat,?string $cuenta):int
    {
        return $this->con()->crear($this->owner,$this->d(['nombre'=>$nombre,'naturaleza'=>$nat,'cuenta'=>$cuenta??'']))['recurso_id'];
    }
    /** Ajuste de salida de $cant unidades, solicitado y aprobado; con concepto (055) o sin él (como antes de 055). */
    private function ajuste(string $cant,?int $concepto):int
    {
        $a=new ErpAprobacionService($this->r);
        if($concepto){$s=$a->solicitar($this->recepcion,$this->d(['tipo'=>'ajuste_stock','producto_id'=>$this->producto,'movimiento'=>'salida','cantidad'=>$cant,'motivo'=>'Conteo del almacén','concepto_id'=>$concepto]))['recurso_id'];}
        else{$this->db->query("INSERT INTO erp_solicitudes(tipo,datos_json,motivo,solicitante_id,creado_en) VALUES('ajuste_stock','".json_encode(['producto_id'=>$this->producto,'tipo'=>'salida','cantidad'=>$cant,'motivo'=>'Previa a los conceptos'])."','Previa a los conceptos',{$this->recepcion},NOW())");$s=(int)$this->db->insert_id;}
        $a->resolver($this->owner,$this->d(['solicitud_id'=>$s,'decision'=>'aprobar','nota'=>'Revisado']));
        return (int)$this->r->uno('SELECT id FROM inventario_movimientos WHERE idempotencia=?',['erp.ajuste:'.$s])['id'];
    }
    private function movimientoSinOrigen(string $tipo,int $milesimas,int $costo,string $fecha):int
    {
        $this->db->query("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES({$this->producto},NULL,'{$tipo}',{$milesimas},{$milesimas},{$costo},'Módulo heredado','legacy:".bin2hex(random_bytes(4))."','".str_repeat('c',64)."','{$fecha} 10:00:00')");
        return (int)$this->db->insert_id;
    }

    public function testFlujosReales_ComprasVentasYAjustesConciliaSinDiferenciaNiPartidas():void
    {
        $this->recibir('10','5.00');$this->entregar('2');$g=$this->cuenta('gasto');$k=$this->concepto('Merma','merma_o_perdida',$g);$this->ajuste('1',$k);$this->contabilizar();
        $inf=$this->inv()->informe('2099-12-31');
        // Compra 10 × 5,00 = 50,00; venta de 2 al costo 5,00 = −10,00; ajuste de 1 al costo 5,00 = −5,00.
        self::assertSame(3500,$inf['auxiliar']);self::assertSame(3500,$inf['libro']);self::assertSame(0,$inf['diferencia']);self::assertTrue($inf['conciliado']);self::assertTrue($inf['cuadra_partidas']);
        self::assertSame([],$inf['partidas']);self::assertSame(0,$inf['residuo']);self::assertSame(3,$inf['movimientos']);
        self::assertSame([5000,-1000,-500],[$this->clase($inf,'compras')['libro'],$this->clase($inf,'ventas')['libro'],$this->clase($inf,'ajustes')['libro']]);
        self::assertSame($inf['libro'],array_sum(array_column($inf['por_origen'],'libro')));
        // El control resumido de la conciliación general coincide con el informe.
        foreach($this->cont()->conciliacion('2099-12-31')['controles'] as $c)if($c['nombre']==='Inventario valorizado'){self::assertSame(3500,$c['libro']);self::assertSame(3500,$c['auxiliar']);self::assertSame(0,$c['diferencia']);$visto=true;}
        self::assertTrue($visto??false);
    }
    public function testCadaEstadoQueNoEstaEnElLibroApareceTrazableYLasPartidasSumanLaDiferencia():void
    {
        $this->recibir('10','5.00');$this->contabilizar();                                       // contabilizado: 50,00
        $sinCuenta=$this->concepto('Faltante sin cuenta','faltante_de_conteo',null);$this->ajuste('1',$sinCuenta);$x=$this->procesar(); // fallido (−5,00)
        self::assertGreaterThan(0,$x['fallidos']);
        $this->recibir('4','5.00');                                                               // pendiente: la cola aún no corre (20,00 sin contabilizar)
        $hist=$this->ajuste('2',null);                                                           // ajuste anterior a los conceptos: sin clasificar (−10,00)
        $leg=$this->movimientoSinOrigen('entrada',3000,200,'2026-05-10');                          // heredado: sin regla (+6,00)
        $inf=$this->inv()->informe('2099-12-31');
        self::assertSame(5000+2000-500-1000+600,$inf['auxiliar'],'Auxiliar: todo movimiento cuenta, esté o no en el libro');
        self::assertSame(5000,$inf['libro'],'Libro: solo lo contabilizado');
        self::assertSame($inf['libro']-$inf['auxiliar'],$inf['diferencia']);self::assertTrue($inf['cuadra_partidas'],'Las partidas suman exactamente la diferencia');self::assertSame(0,$inf['residuo']);
        // Una entrada sin contabilizar resta a (libro − auxiliar); una salida sin contabilizar suma.
        self::assertSame([-2000,500,1000,-600],[$this->partida($inf,'pendiente')['monto'],$this->partida($inf,'fallido')['monto'],$this->partida($inf,'sin_clasificar')['monto'],$this->partida($inf,'heredado')['monto']]);
        self::assertSame(1,$this->partida($inf,'pendiente')['movimientos']);
        // Trazabilidad: el detalle de cada estado nombra el movimiento, su hecho contable y el estado de la cola.
        $p=$this->inv()->detalle('2099-12-31','pendiente');self::assertCount(1,$p);self::assertStringContainsString('Compras',$p[0]['origen']);self::assertGreaterThan(0,$p[0]['evento_id']);self::assertContains($p[0]['cola'],['sin_configurar','pendiente'],'Aún no procesado por la cola');self::assertSame(2000,$p[0]['valor']);
        $f=$this->inv()->detalle('2099-12-31','fallido');self::assertCount(1,$f);self::assertSame('fallido',$f[0]['cola']);self::assertGreaterThan(0,$f[0]['intentos']);
        $s=$this->inv()->detalle('2099-12-31','sin_clasificar');self::assertSame([$hist],array_column($s,'movimiento_id'));self::assertNull($s[0]['evento_id']);
        $h=$this->inv()->detalle('2099-12-31','heredado');self::assertSame([$leg],array_column($h,'movimiento_id'));
        self::assertSame([],$this->inv()->detalle('2099-12-31','pendiente','ventas'),'El filtro por origen acota el detalle');
    }
    public function testMovimientosAnterioresAlInicioContableSeExplicanContraLaApertura():void
    {
        // Inventario inicial de 5,00 (2 u × 2,50) anterior al inicio contable, recogido por un asiento de apertura.
        $this->movimientoSinOrigen('entrada',2000,250,'2025-12-10');
        $inv=$this->r->uno("SELECT c.codigo FROM erp_cont_roles r JOIN erp_cont_cuentas c ON c.id=r.cuenta_id WHERE r.rol='inventario'")['codigo'];$pat=$this->cuenta('patrimonio');
        $this->cont()->asiento($this->owner,$this->d(['fecha'=>'2026-01-01','concepto'=>'Apertura','apertura'=>'1','lineas'=>[['cuenta'=>$inv,'debe'=>'5.00'],['cuenta'=>$pat,'haber'=>'5.00']]]));
        $inf=$this->inv()->informe('2099-12-31');
        self::assertSame(500,$inf['auxiliar']);self::assertSame(500,$inf['libro']);self::assertSame(0,$inf['diferencia']);
        self::assertSame(500,$this->partida($inf,'apertura')['monto']);self::assertSame(-500,$this->partida($inf,'previo_inicio')['monto'],'La apertura y los movimientos previos se explican entre sí');self::assertTrue($inf['cuadra_partidas']);
        self::assertSame(1,$this->partida($inf,'previo_inicio')['movimientos']);
        // Si la apertura NO recogió ese inventario, la diferencia queda a la vista (no se oculta ni se ajusta).
        $this->movimientoSinOrigen('entrada',1000,300,'2025-11-01');$inf=$this->inv()->informe('2099-12-31');
        self::assertSame(800,$inf['auxiliar']);self::assertSame(500,$inf['libro']);self::assertSame(-300,$inf['diferencia']);self::assertSame(-800,$this->partida($inf,'previo_inicio')['monto']);self::assertTrue($inf['cuadra_partidas']);
    }
    public function testUnMovimientoAnteriorAlInicioContableNoSeMuestraComoPendienteNiFallidoAunqueSuColaNoSeHayaProcesado():void
    {
        // Compra recibida y ajuste con concepto sin cuenta, ambos con hecho contable aún en cola (pendiente y fallido)...
        $this->recibir('4','5.00');$sinCuenta=$this->concepto('Faltante sin cuenta','faltante_de_conteo',null);$aj=$this->ajuste('1',$sinCuenta);
        $compra=(int)$this->r->uno("SELECT movimiento_id m FROM erp_stock_origenes WHERE documento_tipo='compra' ORDER BY id DESC LIMIT 1")['m'];
        $this->db->query("UPDATE erp_integraciones_outbox SET estado='fallido',intentos=2 WHERE evento_id=(SELECT evento_id FROM erp_inv_ajuste_conceptos WHERE movimiento_id={$aj})");
        // ...pero ambos movimientos son anteriores al inicio contable (2026-01-01): su hecho nunca generará asiento, ya están en la apertura.
        $this->db->query("UPDATE inventario_movimientos SET registrado_en='2025-12-05 10:00:00' WHERE id IN({$compra},{$aj})");
        $inf=$this->inv()->informe('2099-12-31');
        self::assertNull($this->partida($inf,'pendiente'));self::assertNull($this->partida($inf,'fallido'),'La cola no se interpreta antes que el inicio contable');
        self::assertSame(2,$this->partida($inf,'previo_inicio')['movimientos']);self::assertSame(-(2000-500),$this->partida($inf,'previo_inicio')['monto']);self::assertTrue($inf['cuadra_partidas']);
        self::assertSame(2,count($this->inv()->detalle('2099-12-31','previo_inicio')));self::assertSame([],$this->inv()->detalle('2099-12-31','fallido'));
    }
    public function testLaFechaDeCorteYLosContabilizadosTardeSeSeparanDelResto():void
    {
        $this->recibir('10','5.00');$k=$this->concepto('Merma','merma_o_perdida',$this->cuenta('gasto'));$mov=$this->ajuste('2',null);
        $this->db->query("UPDATE inventario_movimientos SET registrado_en='2026-03-01 09:00:00' WHERE id={$mov}");
        $this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$k,'motivo'=>'Merma de marzo clasificada ahora']));$this->contabilizar();
        // Al día de hoy todo está contabilizado...
        $hoy=$this->inv()->informe('2099-12-31');self::assertSame(0,$hoy['diferencia']);self::assertSame([],$hoy['partidas']);
        // ...pero al 30 de junio la merma de marzo existía en inventario y su asiento (fecha de la decisión) es posterior: se explica como «contabilizado después del corte».
        $inf=$this->inv()->informe('2026-06-30');
        self::assertSame(-1000,$inf['auxiliar'],'Solo la merma de marzo existía al corte; la compra de hoy no entra');self::assertSame(0,$inf['libro'],'Su asiento lleva la fecha de la decisión, posterior al corte');
        self::assertSame(1000,$inf['diferencia']);self::assertSame(1000,$this->partida($inf,'tardio')['monto']);self::assertSame(1,$this->partida($inf,'tardio')['movimientos']);self::assertTrue($inf['cuadra_partidas']);
        $t=$this->inv()->detalle('2026-06-30','tardio');self::assertSame([$mov],array_column($t,'movimiento_id'));self::assertSame('confirmado',$t[0]['cola']);
    }
    public function testElInformeEsDeSoloLecturaYValidaSusEntradas():void
    {
        $this->recibir('3','5.00');$sinCuenta=$this->concepto('Faltante sin cuenta','faltante_de_conteo',null);$this->ajuste('1',$sinCuenta);$this->procesar();
        $cuenta=fn()=>[(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'],(int)$this->r->uno('SELECT COUNT(*) n FROM erp_eventos')['n'],(int)$this->r->uno('SELECT COUNT(*) n FROM erp_integraciones_outbox')['n'],
            $this->r->uno("SELECT GROUP_CONCAT(estado ORDER BY id) e FROM erp_integraciones_outbox")['e'],(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_ajuste_conceptos')['n'],(int)$this->r->uno('SELECT stock_milesimas s FROM inventario_productos WHERE id='.$this->producto)['s']];
        $antes=$cuenta();$this->inv()->informe('2099-12-31');$this->inv()->detalle('2099-12-31','fallido');$this->cont()->conciliacion('2099-12-31');self::assertSame($antes,$cuenta(),'Consultar no crea asientos, eventos, ajustes ni toca la cola ni las existencias');
        $this->expectException(\InvalidArgumentException::class);$this->inv()->informe('31/12/2099');
    }
    public function testDetalleRechazaEstadosYOrigenesInvalidos():void
    {
        foreach([['x',null],['pendiente','nada']] as [$e,$o]){
            try{$this->inv()->detalle('2099-12-31',$e,$o);self::fail('Debía rechazar');}catch(\InvalidArgumentException){self::assertTrue(true);}
        }
    }
    public function testPantallaMuestraElControlSoloConLecturaYExigePermiso():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->recibir('4','5.00');                                                          // queda pendiente de contabilizar
            $ses=function(int $u){if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($u);$_SESSION=['login'=>true,'id'=>$u,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-ci'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/contabilidad';};
            $pag=function(array $get){$_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::contabilidad(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}};
            $ses($this->finanzas);$h=$pag(['vista'=>'conciliacion']);
            self::assertStringContainsString('Inventario valorizado frente al libro',$h);self::assertStringContainsString('Pendiente de contabilizar',$h);self::assertStringContainsString('solo lectura',$h);self::assertStringContainsString('No genera ajustes',$h);
            $seccion=substr($h,strpos($h,'Inventario valorizado frente al libro'));self::assertStringNotContainsString('<form',$seccion,'El control no ofrece ninguna acción que escriba');
            $d=$pag(['vista'=>'conciliacion','detalle'=>'pendiente']);self::assertStringContainsString('Hecho contable',$d);self::assertStringContainsString('Compras',$d);
            $d=$pag(['vista'=>'conciliacion','detalle'=>'no_existe']);self::assertStringNotContainsString('Hecho contable',$d,'Un estado desconocido se ignora');
            $ses($this->recepcion);$_GET=['vista'=>'conciliacion'];$_SERVER['REQUEST_METHOD']='GET';$estado=200;ob_start();try{\Controllers\ErpController::contabilidad(new \MVC\Router());}catch(\AppTerminationException $x){$estado=$x->getStatusCode();}finally{ob_end_clean();}
            self::assertSame(403,$estado,'Sin contabilidad.ver no se ve el control');
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];}
    }
}
