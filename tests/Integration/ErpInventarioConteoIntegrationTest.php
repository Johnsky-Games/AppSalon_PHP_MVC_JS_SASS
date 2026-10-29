<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpInventarioConteoService,ErpInventarioConceptoService,ErpContabilidadService,ErpIntegracionService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/**
 * Conteo físico de inventario (migración 070): alcance, responsable y referencia al iniciar; sin contar ≠ contado en cero; diferencias sobre la existencia física del sistema al contar
 * aplicadas como DIFERENCIA (nunca se sobrescribe el stock); reservas; conceptos y motivo; ajustes únicos por el flujo existente con valoración y hecho contable; reversos; historial y protecciones.
 */
final class ErpInventarioConteoIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $gerente;private int $recepcion;private int $p1;private int $p2;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('invconteo');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->gerente=R5CajaFixture::usuario($this->db,'gerente');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('SKU-1','Champú de ensayo','unidad',10000,250)");$this->p1=(int)$this->db->insert_id;
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('SKU-2','Tinte de ensayo','unidad',4000,1000)");$this->p2=(int)$this->db->insert_id;
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function svc():ErpInventarioConteoService {return new ErpInventarioConteoService($this->r);}
    private function con():ErpInventarioConceptoService {return new ErpInventarioConceptoService($this->r);}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function rechazo(callable $f,int $codigo,string $texto=''):ErpException
    {
        try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());if($texto!=='')self::assertStringContainsString($texto,$e->getMessage());return $e;}
        self::fail('Se esperaba un rechazo '.$codigo);
    }
    private function sqlFalla(string $sql):string {$ok=@$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}
    private function n(string $sql):int {return (int)($this->db->query($sql)->fetch_row()[0]??0);}
    private function cuenta(string $tipo,int $n=0):string
    {
        $f=$this->r->q("SELECT c.codigo FROM erp_cont_cuentas c WHERE c.tipo=? AND c.imputable=1 AND c.activa=1 AND c.id NOT IN(SELECT cuenta_id FROM erp_cont_roles WHERE rol='inventario') ORDER BY c.codigo",[$tipo]);
        self::assertGreaterThan($n,count($f));return $f[$n]['codigo'];
    }
    private function concepto(string $nombre,string $naturaleza,string $cuenta):int
    {
        return $this->con()->crear($this->owner,$this->d(['nombre'=>$nombre,'naturaleza'=>$naturaleza,'cuenta'=>$cuenta]))['recurso_id'];
    }
    /** @return array{0:int,1:int} concepto de faltantes (salida) y de sobrantes (entrada) */
    private function conceptos():array
    {
        return [$this->concepto('Faltante de conteo','faltante_de_conteo',$this->cuenta('gasto')),$this->concepto('Sobrante de conteo','sobrante_de_conteo',$this->cuenta('ingreso'))];
    }
    private function stock(int $p):int {return (int)$this->r->uno('SELECT stock_milesimas s FROM inventario_productos WHERE id=?',[$p])['s'];}
    private function procesar():array {return (new ErpIntegracionService($this->r))->procesar(['contabilidad'=>new ErpContabilidadAdaptador($this->r)],100);}
    /** Movimiento de inventario como el de una venta o una compra: cambia el stock y deja su movimiento (id creciente). */
    private function movimiento(int $p,string $tipo,int $qty):void
    {
        $saldo=$this->stock($p)+($tipo==='entrada'?$qty:-$qty);
        $this->db->query("UPDATE inventario_productos SET stock_milesimas=$saldo WHERE id=$p");
        $k='ensayo:'.bin2hex(random_bytes(8));
        $this->db->query("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES($p,{$this->owner},'$tipo',$qty,$saldo,250,'Movimiento de ensayo','$k','".hash('sha256',$k)."',NOW())");
        self::assertSame('',$this->db->error);
    }
    private function reservar(int $p,int $qty):void
    {
        $this->db->query("INSERT INTO erp_ventas(comprador_nombre,comprador_json,estado,creado_por,creado_en) VALUES('Consumidor final','{}','borrador',{$this->owner},NOW())");$v=(int)$this->db->insert_id;
        $this->db->query("INSERT INTO erp_venta_lineas(venta_id,producto_id,nombre,cantidad_milesimas,precio_centavos,total_centavos) VALUES($v,$p,'Línea reservada',$qty,1000,1000)");$l=(int)$this->db->insert_id;
        $this->db->query("INSERT INTO erp_stock_reservas(linea_id,producto_id,cantidad_milesimas,estado,creado_en) VALUES($l,$p,$qty,'activa',NOW())");self::assertSame('',$this->db->error);
    }
    private function iniciar(array $productos=[],string $alcance='seleccion',?int $actor=null):int
    {
        return $this->svc()->iniciar($actor??$this->finanzas,$this->d(['alcance'=>$alcance,'descripcion'=>'Bodega de ensayo','responsable_id'=>$this->finanzas,'productos'=>$productos]))['recurso_id'];
    }
    private function linea(int $conteo,int $producto):int {return (int)$this->r->uno('SELECT id FROM erp_inv_conteo_lineas WHERE conteo_id=? AND producto_id=?',[$conteo,$producto])['id'];}
    private function contar(int $conteo,int $producto,string $cantidad):void
    {
        $this->svc()->contar($this->finanzas,$this->d(['linea_id'=>$this->linea($conteo,$producto),'cantidad'=>$cantidad]));
    }
    private function cerrar(int $conteo):void {$this->svc()->cerrar($this->finanzas,$this->d(['conteo_id'=>$conteo]));}
    private function aprobar(int $conteo,array $extra=[],?int $actor=null):void
    {
        $this->svc()->aprobar($actor??$this->gerente,$this->d(['conteo_id'=>$conteo]+$extra));
    }
    private function linea_an(int $conteo,int $producto):array
    {
        foreach($this->svc()->conteo($conteo)['lineas'] as $l)if((int)$l['producto_id']===$producto)return $l;
        self::fail('línea');
    }

    public function testIniciarRegistraAlcanceResponsableYExistenciasDeReferencia():void
    {
        $this->rechazo(fn()=>$this->svc()->iniciar($this->recepcion,$this->d(['alcance'=>'seleccion','descripcion'=>'x ensayo','responsable_id'=>$this->finanzas,'productos'=>[$this->p1]])),403);
        $this->rechazo(fn()=>$this->svc()->iniciar($this->finanzas,$this->d(['alcance'=>'seleccion','descripcion'=>'Bodega','responsable_id'=>$this->recepcion,'productos'=>[$this->p1]])),422,'responsable');
        $this->rechazo(fn()=>$this->svc()->iniciar($this->finanzas,$this->d(['alcance'=>'seleccion','descripcion'=>'Bodega','responsable_id'=>$this->finanzas,'productos'=>[]])),422,'al menos un producto');
        $this->rechazo(fn()=>$this->svc()->iniciar($this->finanzas,$this->d(['alcance'=>'otro','descripcion'=>'Bodega','responsable_id'=>$this->finanzas])),422,'alcance');
        $this->rechazo(fn()=>$this->svc()->iniciar($this->finanzas,$this->d(['alcance'=>'seleccion','descripcion'=>'Bodega','responsable_id'=>$this->finanzas,'productos'=>[999999]])),404);
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_inv_conteos'),'ningún rechazo deja un conteo a medias');
        $this->movimiento($this->p1,'salida',1000);// stock 9,000 y un movimiento previo
        $maxMov=$this->n('SELECT MAX(id) FROM inventario_movimientos');
        $id=$this->iniciar([$this->p1]);
        $c=$this->r->uno('SELECT * FROM erp_inv_conteos WHERE id=?',[$id]);
        self::assertSame(['en_conteo','seleccion','Bodega de ensayo',$this->finanzas,$this->finanzas,$maxMov],[$c['estado'],$c['alcance'],$c['descripcion'],(int)$c['responsable_id'],(int)$c['iniciado_por'],(int)$c['ultimo_movimiento_inicio']]);
        $l=$this->r->uno('SELECT * FROM erp_inv_conteo_lineas WHERE conteo_id=?',[$id]);
        self::assertSame(['SKU-1','Champú de ensayo',9000,250,$maxMov],[$l['sku'],$l['nombre'],(int)$l['referencia_milesimas'],(int)$l['costo_ref_centavos'],(int)$l['ultimo_mov_ref']],'existencia y costo de referencia al iniciar');
        self::assertNull($l['contado_milesimas']);self::assertNull($l['diferencia_milesimas']);
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_eventos WHERE tipo='inventario_conteo_iniciado' AND entidad_id=$id"),'queda auditado');
        // Todos los activos; los inactivos quedan fuera; un producto ya en un conteo abierto no entra en otro.
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,activo) VALUES('SKU-OFF','Inactivo','unidad',500,0)");
        $this->rechazo(fn()=>$this->iniciar([],'todos_activos'),409,'Champú de ensayo');
        $this->svc()->cancelar($this->finanzas,$this->d(['conteo_id'=>$id,'motivo'=>'Se reinicia el conteo']));
        $todos=$this->iniciar([],'todos_activos');
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM erp_inv_conteo_lineas WHERE conteo_id=$todos"),'solo los productos activos');
    }
    public function testSinContarNoEsLoMismoQueContadoEnCeroYNoGeneraAjuste():void
    {
        [$cf]=$this->conceptos();
        $id=$this->iniciar([$this->p1,$this->p2]);
        $this->rechazo(fn()=>$this->svc()->cerrar($this->finanzas,$this->d(['conteo_id'=>$id])),422,'al menos un producto');
        $this->rechazo(fn()=>$this->svc()->contar($this->finanzas,$this->d(['linea_id'=>$this->linea($id,$this->p1),'cantidad'=>''])),422,'sin contar');
        self::assertNull($this->r->uno('SELECT contado_milesimas c FROM erp_inv_conteo_lineas WHERE id=?',[$this->linea($id,$this->p1)])['c'],'vacío no es cero');
        $this->contar($id,$this->p2,'0');
        $a=$this->linea_an($id,$this->p2);$b=$this->linea_an($id,$this->p1);
        self::assertTrue($a['contada']);self::assertSame(0,(int)$a['contado_milesimas'],'contado en cero');self::assertSame(-4000,(int)$a['diferencia_milesimas']);self::assertSame('salida',$a['tipo']);
        self::assertFalse($b['contada']);self::assertNull($b['tipo']);self::assertSame('sin_contar',$b['conflictos'][0]['codigo']);
        $this->cerrar($id);
        $this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Tinte agotado según el conteo']);
        self::assertSame(0,$this->stock($this->p2),'solo lo contado ajusta el stock');self::assertSame(10000,$this->stock($this->p1),'lo sin contar no se toca');
        self::assertSame(1,$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'),'un ajuste: solo para lo contado');
    }
    public function testLaDiferenciaSeCalculaSobreLaExistenciaDelSistemaAlContarYSeAplicaSinSobrescribirElStock():void
    {
        [$cf]=$this->conceptos();
        $id=$this->iniciar([$this->p1]);// referencia 10,000
        $this->movimiento($this->p1,'salida',2000);// entre el inicio y el conteo: stock 8,000
        $this->contar($id,$this->p1,'7');// se cuentan 7: el sistema decía 8 → faltante de 1 (no de 3 contra la referencia)
        $l=$this->linea_an($id,$this->p1);
        self::assertSame([10000,8000,-1000],[(int)$l['referencia_milesimas'],(int)$l['sistema_al_contar_milesimas'],(int)$l['diferencia_milesimas']]);
        $this->movimiento($this->p1,'entrada',5000);// después del conteo: stock 13,000
        $this->cerrar($id);
        $l=$this->linea_an($id,$this->p1);$cod=array_column($l['conflictos'],'codigo');
        self::assertContains('movimientos_entre_inicio_y_conteo',$cod);self::assertContains('movimientos_posteriores_al_conteo',$cod);
        // Los movimientos posteriores exigen confirmación explícita.
        $this->rechazo(fn()=>$this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante de un frasco']),409,'confirma que lo revisaste');
        self::assertSame(13000,$this->stock($this->p1),'sin confirmar no se aplica nada');
        $this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante de un frasco','confirmar_movimientos'=>'1']);
        self::assertSame(12000,$this->stock($this->p1),'se aplicó la DIFERENCIA (−1) sobre el stock actual (13), no el contado (7)');
    }
    public function testLasReservasNoSonFaltantesPeroBloqueanDejarElStockPorDebajoDeLoReservado():void
    {
        [$cf]=$this->conceptos();
        $this->reservar($this->p1,6000);
        $id=$this->iniciar([$this->p1]);
        $this->contar($id,$this->p1,'10');// todo está físicamente en el estante: la reserva no es faltante
        $l=$this->linea_an($id,$this->p1);self::assertNull($l['tipo']);self::assertSame(0,(int)$l['diferencia_milesimas']);self::assertSame(6000,(int)$l['reservado_actual']);self::assertSame(4000,(int)$l['disponible_actual']);
        $this->contar($id,$this->p1,'5');// 5 < 6 reservadas
        $l=$this->linea_an($id,$this->p1);$bloq=array_filter($l['conflictos'],fn($k)=>$k['bloquea']);
        self::assertSame(['bajo_reservas'],array_column($bloq,'codigo'),'el conflicto se muestra');
        $this->cerrar($id);
        $this->rechazo(fn()=>$this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante grande']),409,'por debajo de lo reservado');
        self::assertSame(10000,$this->stock($this->p1));self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'),'todo o nada');self::assertSame('pendiente',$this->r->uno('SELECT estado FROM erp_inv_conteos WHERE id=?',[$id])['estado']);
        // Rechazar el conteo no cambia el stock; uno nuevo con 7 (≥ 6 reservadas) sí se puede aprobar.
        $this->svc()->rechazar($this->gerente,$this->d(['conteo_id'=>$id,'motivo'=>'Se recuenta con las reservas']));
        self::assertSame(10000,$this->stock($this->p1));
        $id2=$this->iniciar([$this->p1]);$this->contar($id2,$this->p1,'7');$this->cerrar($id2);
        $this->aprobar($id2,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante de 3 unidades']);
        self::assertSame(7000,$this->stock($this->p1));
    }
    public function testAprobarExigeConceptoYMotivoYOtraPersonaYUsaElFlujoExistenteConValoracionYHechoContable():void
    {
        [$cf,$cs]=$this->conceptos();
        $id=$this->iniciar([$this->p1,$this->p2]);$this->contar($id,$this->p1,'9.5');$this->contar($id,$this->p2,'4.25');$this->cerrar($id);
        $this->rechazo(fn()=>$this->svc()->aprobar($this->finanzas,$this->d(['conteo_id'=>$id,'concepto_faltante_id'=>$cf,'concepto_sobrante_id'=>$cs,'motivo'=>'Ajuste del conteo'])),403,'Otra persona');
        $this->rechazo(fn()=>$this->svc()->aprobar($this->recepcion,$this->d(['conteo_id'=>$id,'concepto_faltante_id'=>$cf,'concepto_sobrante_id'=>$cs,'motivo'=>'Ajuste del conteo'])),403);
        $this->rechazo(fn()=>$this->aprobar($id,['concepto_sobrante_id'=>$cs,'motivo'=>'Ajuste del conteo']),422,'Elige el concepto');// falta el de faltantes
        $this->rechazo(fn()=>$this->aprobar($id,['concepto_faltante_id'=>$cs,'concepto_sobrante_id'=>$cs,'motivo'=>'Ajuste del conteo']),422,'es de entrada');// sentido equivocado
        $this->rechazo(fn()=>$this->aprobar($id,['concepto_faltante_id'=>$cf,'concepto_sobrante_id'=>$cs,'motivo'=>'']),422,'motivo');
        $this->rechazo(fn()=>$this->aprobar($id,['concepto_faltante_id'=>$cf,'concepto_sobrante_id'=>$cs,'motivo'=>'cort']),422,'al menos 5');
        self::assertSame([10000,4000],[$this->stock($this->p1),$this->stock($this->p2)],'los rechazos no cambian nada');
        // p1: contado 9,5 vs 10 → faltante de 0,5 · p2: contado 4,25 vs 4 → sobrante de 0,25
        $this->aprobar($id,['concepto_faltante_id'=>$cf,'concepto_sobrante_id'=>$cs,'motivo'=>'Diferencias del conteo de octubre']);
        self::assertSame([9500,4250],[$this->stock($this->p1),$this->stock($this->p2)]);
        $aj=$this->r->q('SELECT a.*,s.estado sol_estado,m.idempotencia,m.cantidad_milesimas mcant FROM erp_inv_conteo_ajustes a JOIN erp_solicitudes s ON s.id=a.solicitud_id JOIN inventario_movimientos m ON m.id=a.movimiento_id WHERE a.conteo_id=? ORDER BY a.id',[$id]);
        self::assertCount(2,$aj);
        self::assertSame(['salida',500,250,125,$cf,'conteo','aprobada'],[$aj[0]['tipo'],(int)$aj[0]['cantidad_milesimas'],(int)$aj[0]['costo_centavos'],(int)$aj[0]['valor_centavos'],(int)$aj[0]['concepto_id'],$aj[0]['rol'],$aj[0]['sol_estado']],'valoración: 0,5 × 2,50 = 1,25');
        self::assertSame(['entrada',250,1000,250,$cs],[$aj[1]['tipo'],(int)$aj[1]['cantidad_milesimas'],(int)$aj[1]['costo_centavos'],(int)$aj[1]['valor_centavos'],(int)$aj[1]['concepto_id']],'valoración: 0,25 × 10,00 = 2,50 → 250');
        foreach($aj as $a)self::assertStringStartsWith('erp.ajuste:',$a['idempotencia'],'el movimiento nace del flujo existente de ajustes aprobados');
        // Mismo concepto y evento contable que cualquier ajuste: clasificación + outbox hacia contabilidad.
        self::assertSame(2,$this->n('SELECT COUNT(*) FROM erp_inv_ajuste_conceptos'));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM erp_cont_asientos WHERE concepto LIKE 'Ajuste de inventario:%'"));
        self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$this->cont()->integridad());
        // Solicitudes con trazabilidad al conteo y a su línea.
        $s=$this->r->uno('SELECT datos_json,solicitante_id FROM erp_solicitudes WHERE id=?',[(int)$aj[0]['solicitud_id']]);$dd=json_decode($s['datos_json'],true);
        self::assertSame([$id,$this->finanzas],[(int)$dd['conteo_id'],(int)$s['solicitante_id']],'la solicitud nombra el conteo y a quien lo envió');
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_eventos WHERE tipo='inventario_conteo_aprobado' AND entidad_id=$id"));
    }
    public function testLaAprobacionRepetidaNoDuplicaAjustesNiMovimientos():void
    {
        [$cf]=$this->conceptos();
        $id=$this->iniciar([$this->p1]);$this->contar($id,$this->p1,'9');$this->cerrar($id);
        $clave=R5CajaFixture::clave();$datos=['conteo_id'=>$id,'concepto_faltante_id'=>$cf,'motivo'=>'Faltante confirmado','idem_key'=>$clave];
        $r1=$this->svc()->aprobar($this->gerente,$datos);
        $movs=$this->n('SELECT COUNT(*) FROM inventario_movimientos');
        $r2=$this->svc()->aprobar($this->gerente,$datos);// reintento del mismo formulario
        self::assertSame($r1['recurso_id'],$r2['recurso_id']);self::assertSame($movs,$this->n('SELECT COUNT(*) FROM inventario_movimientos'),'el reintento no repite movimientos');
        $this->rechazo(fn()=>$this->svc()->aprobar($this->owner,$this->d(['conteo_id'=>$id,'concepto_faltante_id'=>$cf,'motivo'=>'Otra vez aprobado'])),409,'ya fue aprobado');// otra clave
        self::assertSame(9000,$this->stock($this->p1),'el stock se ajustó una sola vez');
        self::assertSame(1,$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'));self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_solicitudes WHERE tipo='ajuste_stock'"));
        // La base también impide un segundo ajuste de la misma línea y rol.
        $a=$this->r->uno('SELECT * FROM erp_inv_conteo_ajustes');
        $this->sqlFalla("INSERT INTO erp_inv_conteo_ajustes(conteo_id,linea_id,rol,solicitud_id,movimiento_id,tipo,cantidad_milesimas,costo_centavos,valor_centavos,concepto_id,creado_por,creado_en) VALUES({$a['conteo_id']},{$a['linea_id']},'conteo',{$a['solicitud_id']}+1,{$a['movimiento_id']}+1,'salida',1,1,1,{$a['concepto_id']},1,NOW())");
    }
    public function testUnConteoAprobadoSeRevierteConAjustesInversosYConservaLosOriginales():void
    {
        [$cf,$cs]=$this->conceptos();
        $id=$this->iniciar([$this->p1,$this->p2]);$this->contar($id,$this->p1,'8');$this->contar($id,$this->p2,'5');$this->cerrar($id);
        $this->aprobar($id,['concepto_faltante_id'=>$cf,'concepto_sobrante_id'=>$cs,'motivo'=>'Diferencias del conteo']);
        self::assertSame([8000,5000],[$this->stock($this->p1),$this->stock($this->p2)]);
        $this->rechazo(fn()=>$this->svc()->revertir($this->gerente,$this->d(['conteo_id'=>$id,'motivo'=>'Error de captura'])),422,'Elige el concepto');
        $this->rechazo(fn()=>$this->svc()->revertir($this->finanzas,$this->d(['conteo_id'=>$id,'concepto_entrada_id'=>$cs,'concepto_salida_id'=>$cf,'motivo'=>'Error de captura'])),403,'Otra persona');
        $this->svc()->revertir($this->gerente,$this->d(['conteo_id'=>$id,'concepto_entrada_id'=>$cs,'concepto_salida_id'=>$cf,'motivo'=>'Error de captura en la bodega']));
        self::assertSame([10000,4000],[$this->stock($this->p1),$this->stock($this->p2)],'el stock vuelve a lo anterior');
        self::assertSame(4,$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'),'los 2 ajustes originales se conservan y se suman los 2 inversos');
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM erp_inv_conteo_ajustes WHERE rol='reverso'"));
        self::assertSame('revertido',$this->r->uno('SELECT estado FROM erp_inv_conteos WHERE id=?',[$id])['estado']);
        $this->rechazo(fn()=>$this->svc()->revertir($this->owner,$this->d(['conteo_id'=>$id,'concepto_entrada_id'=>$cs,'concepto_salida_id'=>$cf,'motivo'=>'Otra vez revertido'])),409,'ya fue revertido');
        self::assertSame(10000,$this->stock($this->p1));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);
        self::assertSame(0,$this->n("SELECT COALESCE(SUM(l.debe_centavos),0)-COALESCE(SUM(l.haber_centavos),0) FROM erp_cont_lineas l WHERE l.cuenta_id=".$this->cont()->rolCuenta('inventario')),'el inventario contable queda en cero tras ajuste y reverso');
        // Un reverso que dejaría el stock por debajo de lo reservado se rechaza sin tocar nada.
        $id2=$this->iniciar([$this->p1]);$this->contar($id2,$this->p1,'12');$this->cerrar($id2);
        $this->aprobar($id2,['concepto_sobrante_id'=>$cs,'motivo'=>'Sobrante de 2 unidades']);self::assertSame(12000,$this->stock($this->p1));
        $this->reservar($this->p1,11000);
        $this->rechazo(fn()=>$this->svc()->revertir($this->gerente,$this->d(['conteo_id'=>$id2,'concepto_salida_id'=>$cf,'motivo'=>'Revertir con reservas'])),409,'reservado');
        self::assertSame(12000,$this->stock($this->p1));
    }
    public function testUnMovimientoOUnaReservaDespuesDelConteoNoSeSobrescribenYUnBloqueoConcurrenteNoDejaAjustesAMedias():void
    {
        [$cf]=$this->conceptos();
        $id=$this->iniciar([$this->p1]);$this->contar($id,$this->p1,'8');$this->cerrar($id);// diferencia −2 sobre 10
        $this->svc()->conteo($id);// la vista previa se calculó con 10 de stock y nada reservado
        $this->reservar($this->p1,9000);// después: una venta reserva 9 → el stock no puede quedar en 8
        $this->rechazo(fn()=>$this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante de 2 unidades','confirmar_movimientos'=>'1']),409,'por debajo de lo reservado');
        self::assertSame(10000,$this->stock($this->p1));self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'));
        // Una sesión concurrente con el producto bloqueado (p. ej. una venta en curso): la aprobación no espera para siempre, falla sin dejar nada a medias y se puede repetir después.
        $this->db->query("UPDATE erp_stock_reservas SET estado='liberada'");
        $b=new \mysqli(getenv('DB_HOST')?:'127.0.0.1',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'root',$this->db->query('SELECT DATABASE()')->fetch_row()[0],(int)(getenv('DB_PORT')?:3306));
        $b->begin_transaction();$b->query("SELECT id FROM inventario_productos WHERE id={$this->p1} FOR UPDATE");
        $this->db->query('SET SESSION innodb_lock_wait_timeout=1');
        $fallo=null;try{$this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante de 2 unidades']);}catch(\Throwable $e){$fallo=$e;}
        $b->rollback();$b->close();$this->db->query('SET SESSION innodb_lock_wait_timeout=50');
        self::assertNotNull($fallo,'con el producto bloqueado por otra sesión la aprobación no se completa');
        self::assertSame([10000,0,'pendiente'],[$this->stock($this->p1),$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'),$this->r->uno('SELECT estado FROM erp_inv_conteos WHERE id=?',[$id])['estado']],'sin ajustes a medias');
        $this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante de 2 unidades']);
        self::assertSame(8000,$this->stock($this->p1));self::assertSame(1,$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'));
    }
    public function testUnValorDeAjusteQueDesbordaSeMuestraComoConflictoQueBloqueaSinRomperLaPantallaNiLaAprobacion():void
    {
        [$cf]=$this->conceptos();
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('SKU-CARO','Producto de costo extremo','unidad',0,999999999)");$caro=(int)$this->db->insert_id;
        self::assertSame('',$this->db->error);
        $id=$this->iniciar([$caro]);$this->contar($id,$caro,'9999999.999');// 9.999.999.999 milésimas × 999.999.999 centavos desborda un entero de 64 bits
        $l=$this->linea_an($id,$caro);// no debe lanzar TypeError: la pantalla de detalle sigue cargando
        self::assertSame(0,$l['valor_centavos']);
        self::assertContains('valor_excede_asiento',array_column($l['conflictos'],'codigo'));
        $this->cerrar($id);
        $this->rechazo(fn()=>$this->svc()->aprobar($this->gerente,$this->d(['conteo_id'=>$id,'concepto_sobrante_id'=>$cf,'motivo'=>'Intento de aprobar'])),409,'excede el máximo');
        self::assertSame(0,$this->stock($caro),'no se aplicó ningún ajuste');
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_inv_conteo_ajustes'));
    }
    public function testNadaSeBorraYElHistorialDeLecturasSeConserva():void
    {
        [$cf]=$this->conceptos();
        $id=$this->iniciar([$this->p1]);$l=$this->linea($id,$this->p1);
        $this->contar($id,$this->p1,'9');$this->movimiento($this->p1,'salida',500);$this->contar($id,$this->p1,'9.2');
        $reg=$this->svc()->registros($l);
        self::assertCount(2,$reg,'cada lectura queda en el historial');self::assertSame([9000,10000],[(int)$reg[0]['contado_milesimas'],(int)$reg[0]['sistema_milesimas']]);self::assertSame([9200,9500],[(int)$reg[1]['contado_milesimas'],(int)$reg[1]['sistema_milesimas']]);
        $this->cerrar($id);
        $this->rechazo(fn()=>$this->svc()->contar($this->finanzas,$this->d(['linea_id'=>$l,'cantidad'=>'3'])),409,'ya no admite');
        $this->aprobar($id,['concepto_faltante_id'=>$cf,'motivo'=>'Faltante de 0,3','confirmar_movimientos'=>'1']);
        foreach(["UPDATE erp_inv_conteos SET descripcion='otro'","UPDATE erp_inv_conteos SET estado='en_conteo'",'DELETE FROM erp_inv_conteos',"UPDATE erp_inv_conteo_lineas SET contado_milesimas=1,contado_en=NOW(),contado_por=1,sistema_al_contar_milesimas=1,reservado_al_contar_milesimas=0,ultimo_mov_al_contar=1","UPDATE erp_inv_conteo_lineas SET referencia_milesimas=1",
            'DELETE FROM erp_inv_conteo_lineas','UPDATE erp_inv_conteo_registros SET contado_milesimas=1','DELETE FROM erp_inv_conteo_registros','UPDATE erp_inv_conteo_ajustes SET valor_centavos=1','DELETE FROM erp_inv_conteo_ajustes'] as $sql)$this->sqlFalla($sql);
        $this->sqlFalla("INSERT INTO erp_inv_conteo_lineas(conteo_id,producto_id,sku,nombre,unidad,referencia_milesimas,costo_ref_centavos) VALUES($id,{$this->p2},'X','X','u',1,1)");
        self::assertSame(9200,$this->stock($this->p1),'el sistema tenía 9,5 al contar y se contaron 9,2: faltante de 0,3');
        // Cancelar y rechazar no tocan el stock.
        $c2=$this->iniciar([$this->p2]);$this->contar($c2,$this->p2,'1');$this->svc()->cancelar($this->finanzas,$this->d(['conteo_id'=>$c2,'motivo'=>'Conteo equivocado']));
        self::assertSame(4000,$this->stock($this->p2));self::assertSame('cancelado',$this->r->uno('SELECT estado FROM erp_inv_conteos WHERE id=?',[$c2])['estado']);
        $this->rechazo(fn()=>$this->svc()->contar($this->finanzas,$this->d(['linea_id'=>$this->linea($c2,$this->p2),'cantidad'=>'2'])),409);
    }
    public function testLaPantallaMuestraConflictosYOperaElFlujoConPermisos():void
    {
        [$cf]=$this->conceptos();
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->sesion($this->recepcion);$e=200;ob_start();try{\Controllers\ErpController::inventarioConteos(new \MVC\Router());}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}
            self::assertContains($e,[302,401,403,404],'recepción no gestiona inventario');
            $this->sesion($this->finanzas);$h=$this->pagina([]);self::assertStringContainsString('Conteos de inventario',$h);self::assertStringContainsString('Iniciar un conteo',$h);
            self::assertSame(303,$this->post(['accion'=>'inv_conteo_iniciar','alcance'=>'seleccion','descripcion'=>'Conteo de pantalla','responsable_id'=>(string)$this->finanzas,'productos'=>[(string)$this->p1]]));
            $id=(int)$this->r->uno('SELECT id FROM erp_inv_conteos')['id'];
            $h=$this->pagina(['conteo'=>(string)$id]);foreach(['Conteo #'.$id,'Champú de ensayo','sin contar','Referencia al iniciar'] as $t)self::assertStringContainsString($t,$h,$t);
            self::assertSame(303,$this->post(['accion'=>'inv_conteo_contar','conteo_id'=>(string)$id,'linea_id'=>(string)$this->linea($id,$this->p1),'cantidad'=>'9']));
            $this->movimiento($this->p1,'entrada',1000);
            $h=$this->pagina(['conteo'=>(string)$id]);self::assertStringContainsString('movimiento(s) después de contar',$h);self::assertStringContainsString('contado',$h);
            self::assertSame(303,$this->post(['accion'=>'inv_conteo_cerrar','conteo_id'=>(string)$id]));
            $h=$this->pagina(['conteo'=>(string)$id]);self::assertStringContainsString('Aprobar el conteo',$h,'finanzas ve la aprobación, pero otra persona debe resolverla');
            $this->rechazo(fn()=>$this->svc()->aprobar($this->finanzas,$this->d(['conteo_id'=>$id,'concepto_faltante_id'=>$cf,'motivo'=>'Mismo usuario'])),403,'Otra persona');
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
    private function sesion(int $usuario,string $portal='equipo'):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>$portal,'version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-pre'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/inventario/conteos';
    }
    private function pagina(array $get):string
    {
        $_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::inventarioConteos(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function post(array $datos):int
    {
        $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';$_POST=$datos+['idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-pre'];
        $e=200;ob_start();try{\Controllers\ErpController::accion();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;
    }
}
