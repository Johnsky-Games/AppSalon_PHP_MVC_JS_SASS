<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpAprobacionService,ErpInventarioConceptoService,ErpContabilidadService,ErpIntegracionService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/**
 * Reclasificación EXPLÍCITA de ajustes de inventario (migración 056): clasificar un ajuste histórico (anterior a 055) y reimputar uno ya clasificado.
 * Reclasificar cambia la imputación contable, nunca el movimiento ni las existencias.
 */
final class ErpInventarioReclasificacionIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $prod;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('invreclas');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        $this->db->query("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,costo_centavos) VALUES('SKU-REC','Insumo de ensayo','unidad',10000,250)");$this->prod=(int)$this->db->insert_id;
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function con(?ErpRepository $r=null):ErpInventarioConceptoService {return new ErpInventarioConceptoService($r??$this->r);}
    private function apr():ErpAprobacionService {return new ErpAprobacionService($this->r);}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function rechazo(callable $f,int $codigo):ErpException {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function sqlFalla(string $sql):string {$ok=@$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}
    private function cuenta(string $tipo,int $n=0):string
    {
        $f=$this->r->q("SELECT c.codigo FROM erp_cont_cuentas c WHERE c.tipo=? AND c.imputable=1 AND c.activa=1 AND c.id NOT IN(SELECT cuenta_id FROM erp_cont_roles WHERE rol='inventario') ORDER BY c.codigo",[$tipo]);
        self::assertGreaterThan($n,count($f),'El plan base debe tener cuentas '.$tipo);return $f[$n]['codigo'];
    }
    private function concepto(string $nombre,string $naturaleza,?string $cuenta):int
    {
        return $this->con()->crear($this->owner,$this->d(['nombre'=>$nombre,'naturaleza'=>$naturaleza,'cuenta'=>$cuenta??'']))['recurso_id'];
    }
    /** Ajuste aprobado ANTES de los conceptos: la solicitud no trae concepto_id, el stock cambia y nada se clasifica ni se contabiliza. */
    private function historico(string $sentido='salida',string $cantidad='2'):int
    {
        $this->db->query("INSERT INTO erp_solicitudes(tipo,datos_json,motivo,solicitante_id,creado_en) VALUES('ajuste_stock','".json_encode(['producto_id'=>$this->prod,'tipo'=>$sentido,'cantidad'=>$cantidad,'motivo'=>'Previa a los conceptos'])."','Previa a los conceptos',{$this->recepcion},NOW())");
        $id=(int)$this->db->insert_id;$this->apr()->resolver($this->owner,$this->d(['solicitud_id'=>$id,'decision'=>'aprobar','nota'=>'Revisado']));
        return (int)$this->r->uno("SELECT id FROM inventario_movimientos WHERE idempotencia=?",['erp.ajuste:'.$id])['id'];
    }
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
    private function asientos():int {return (int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'];}

    public function testClasificarUnAjusteHistoricoNoTocaMovimientoNiExistenciasYSeContabilizaUnaVez():void
    {
        $mov=$this->historico('salida','2');$stock=$this->stock();self::assertSame(8000,$stock);
        $fila=$this->r->uno('SELECT * FROM inventario_movimientos WHERE id=?',[$mov]);
        self::assertSame([$mov],array_map(fn($f)=>(int)$f['id'],$this->con()->historicos()),'Se ofrece como histórico sin clasificar');
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(0,$this->asientos(),'Nada se contabiliza solo');
        $gas=$this->cuenta('gasto');$merma=$this->concepto('Merma de insumos','merma_o_perdida',$gas);
        $this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$merma,'motivo'=>'Merma de insumos de enero']));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame($fila,$this->r->uno('SELECT * FROM inventario_movimientos WHERE id=?',[$mov]),'El movimiento original se conserva');self::assertSame($stock,$this->stock(),'Las existencias no cambian');
        // 2,000 u × 2,50 = 5,00: Dr gasto · Cr inventario, en un solo asiento que conserva la fecha original en su texto.
        self::assertSame(500,$this->saldo($gas));self::assertSame(-500,$this->saldoInventario());
        $a=$this->r->q("SELECT concepto,origen FROM erp_cont_asientos");self::assertCount(1,$a);self::assertStringContainsString('reclasificado, hecho del '.substr($fila['registrado_en'],0,10),$a[0]['concepto']);
        $c=$this->r->uno('SELECT * FROM erp_inv_ajuste_conceptos WHERE movimiento_id=?',[$mov]);self::assertNull($c['solicitud_id']);self::assertSame($this->owner,(int)$c['creado_por']);
        self::assertSame('outbox:ERP-'.(int)$c['evento_id'].'-contabilidad#1',$a[0]['origen'],'Trazable al evento de la clasificación');
        self::assertSame([],$this->con()->historicos(),'Ya no se ofrece');self::assertSame([],$this->cont()->integridad());self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(1,$this->asientos(),'Reprocesar no duplica');
        $this->rechazo(fn()=>$this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$merma,'motivo'=>'Segundo intento distinto'])),409);
    }
    public function testUnAjusteAnteriorAlInicioContableNoGeneraAsientoYSuReimputacionTampoco():void
    {
        $mov=$this->historico('salida','1');$this->db->query("UPDATE inventario_movimientos SET registrado_en='2025-12-10 09:00:00' WHERE id={$mov}");
        $a=$this->concepto('Merma A','merma_o_perdida',$this->cuenta('gasto'));$b=$this->concepto('Merma B','merma_o_perdida',$this->cuenta('gasto',1));
        $this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$a,'motivo'=>'Merma de diciembre clasificada ahora']));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(0,$this->asientos(),'Su efecto ya está en el saldo de apertura');
        $this->con()->reimputar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$b,'esperado_concepto_id'=>$a,'motivo'=>'Mejor imputación para la merma']));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(0,$this->asientos(),'Tampoco la reimputación genera asiento');
        self::assertSame(9000,$this->stock(),'Las existencias no se tocan');
        $o=$this->r->q("SELECT o.estado,o.respuesta_json r FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.destino='contabilidad' AND e.tipo IN('inventario_ajuste','inventario_reimputado')");
        self::assertCount(2,$o);foreach($o as $f){self::assertSame('confirmado',$f['estado']);self::assertStringContainsString('previo-a-inicio-contable',(string)$f['r']);}
    }
    public function testReimputarRevierteElAsientoVigenteYContabilizaElNuevoConTrazabilidad():void
    {
        $g1=$this->cuenta('gasto');$g2=$this->cuenta('gasto',1);$g3=$this->cuenta('gasto',2);
        $a=$this->concepto('Merma A','merma_o_perdida',$g1);$b=$this->concepto('Faltante B','faltante_de_conteo',$g2);$c=$this->concepto('Uso interno C','uso_interno_u_obsequio',$g3);
        $mov=$this->historico('salida','2');
        $this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$a,'motivo'=>'Clasificación inicial del ajuste']));$this->procesar();
        self::assertSame(500,$this->saldo($g1));
        $r1=$this->con()->reimputar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$b,'esperado_concepto_id'=>$a,'motivo'=>'Era un faltante de conteo']))['recurso_id'];
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame(0,$this->saldo($g1),'El asiento original se revirtió');self::assertSame(500,$this->saldo($g2),'Nueva imputación');self::assertSame(-500,$this->saldoInventario(),'El inventario queda igual: solo cambió la imputación');
        self::assertSame(8000,$this->stock());
        $r2=$this->con()->reimputar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$c,'esperado_concepto_id'=>$b,'motivo'=>'Fue uso interno, no faltante']))['recurso_id'];
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);
        self::assertSame(0,$this->saldo($g2));self::assertSame(500,$this->saldo($g3));self::assertSame(-500,$this->saldoInventario());
        // Cadena: original → reverso → nueva; cada paso remite a su evento y la cadena apunta al anterior.
        $cad=$this->r->q('SELECT id,anterior_id,concepto_origen_id,concepto_nuevo_id FROM erp_inv_reimputaciones WHERE movimiento_id=? ORDER BY id',[$mov]);
        self::assertSame([[0,$a,$b],[$r1,$b,$c]],array_map(fn($f)=>[(int)$f['anterior_id'],(int)$f['concepto_origen_id'],(int)$f['concepto_nuevo_id']],$cad));self::assertSame($r2,(int)$cad[1]['id']);
        $ch=$this->con()->cadenas()[0];self::assertCount(3,$ch['pasos']);self::assertSame('Uso interno C',$ch['vigente']['concepto']);
        self::assertCount(1,$ch['pasos'][0]['asientos']);self::assertCount(2,$ch['pasos'][1]['asientos'],'Reverso del original + nueva imputación');self::assertCount(2,$ch['pasos'][2]['asientos']);
        $rev=$this->r->q("SELECT r.numero,o.numero original FROM erp_cont_asientos r JOIN erp_cont_asientos o ON o.id=r.reversa_de_id WHERE r.tipo='reverso' ORDER BY r.id");self::assertCount(2,$rev,'Dos reversos, cada uno ligado a su asiento vigente');
        self::assertSame(5,$this->asientos(),'1 original + 2×(reverso + nueva imputación)');
        self::assertSame([],$this->cont()->integridad());self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(5,$this->asientos(),'Reprocesar no duplica');
        self::assertStringContainsString('no se edita',$this->sqlFalla('UPDATE erp_inv_reimputaciones SET motivo=motivo'));self::assertStringContainsString('se conserva',$this->sqlFalla('DELETE FROM erp_inv_reimputaciones'));
    }
    public function testPermisosIdempotenciaYValidaciones():void
    {
        $gas=$this->cuenta('gasto');$a=$this->concepto('Merma A','merma_o_perdida',$gas);$b=$this->concepto('Merma B','merma_o_perdida',$this->cuenta('gasto',1));
        $ing=$this->concepto('Sobrante','sobrante_de_conteo',$this->cuenta('ingreso'));$mov=$this->historico('salida','1');
        $dato=['movimiento_id'=>$mov,'concepto_id'=>$a,'motivo'=>'Clasificación con permiso'];
        $this->rechazo(fn()=>$this->con()->reclasificar($this->recepcion,$this->d($dato)),403);$this->rechazo(fn()=>$this->con()->reclasificar($this->finanzas,$this->d($dato)),403);
        $this->rechazo(fn()=>$this->con()->reclasificar($this->owner,$this->d(['concepto_id'=>$ing]+$dato)),422);
        $this->rechazo(fn()=>$this->con()->reclasificar($this->owner,$this->d(['motivo'=>'ya']+$dato)),422);
        $this->rechazo(fn()=>$this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>999999]+$dato)),404);
        $this->con()->estado($this->owner,$this->d(['concepto_id'=>$b,'activo'=>'0']));$this->rechazo(fn()=>$this->con()->reclasificar($this->owner,$this->d(['concepto_id'=>$b]+$dato)),409);
        $this->con()->estado($this->owner,$this->d(['concepto_id'=>$b,'activo'=>'1']));
        // Un movimiento que no es un ajuste aprobado no se ofrece ni se acepta.
        $this->db->query("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES({$this->prod},NULL,'salida',1000,7000,250,'Consumo','consumo:1',REPEAT('b',64),NOW())");
        $ajeno=(int)$this->db->insert_id;$this->rechazo(fn()=>$this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$ajeno]+$dato)),422);
        // Idempotencia: la misma clave devuelve el mismo resultado sin duplicar clasificación, evento ni asiento.
        $clave=R5CajaFixture::clave();$p=$this->con()->reclasificar($this->owner,['idem_key'=>$clave]+$dato);$q=$this->con()->reclasificar($this->owner,['idem_key'=>$clave]+$dato);
        self::assertSame($p['recurso_id'],$q['recurso_id']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_ajuste_conceptos')['n']);self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_eventos WHERE tipo='inventario_ajuste'")['n']);
        $this->procesar();$this->procesar();self::assertSame(1,$this->asientos());
        $re=['movimiento_id'=>$mov,'concepto_id'=>$b,'esperado_concepto_id'=>$a,'motivo'=>'Cambio de imputación'];
        $this->rechazo(fn()=>$this->con()->reimputar($this->recepcion,$this->d($re)),403);$this->rechazo(fn()=>$this->con()->reimputar($this->finanzas,$this->d($re)),403);
        $this->rechazo(fn()=>$this->con()->reimputar($this->owner,$this->d(['concepto_id'=>$a]+$re)),422);// ya imputado a ese concepto
        $this->rechazo(fn()=>$this->con()->reimputar($this->owner,$this->d(['concepto_id'=>$ing]+$re)),422);// sentido contrario
        $this->rechazo(fn()=>$this->con()->reimputar($this->owner,$this->d(['esperado_concepto_id'=>$b]+$re)),409);// pantalla desactualizada
        $this->rechazo(fn()=>$this->con()->reimputar($this->owner,$this->d(['motivo'=>'no']+$re)),422);
        $clave=R5CajaFixture::clave();$p=$this->con()->reimputar($this->owner,['idem_key'=>$clave]+$re);$q=$this->con()->reimputar($this->owner,['idem_key'=>$clave]+$re);
        self::assertSame($p['recurso_id'],$q['recurso_id']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_reimputaciones')['n']);
        $this->procesar();$this->procesar();self::assertSame(3,$this->asientos(),'Original + reverso + nueva, una sola vez');self::assertSame(9000,$this->stock());
    }
    public function testSinCuentaOConImputacionVigentePendienteSoloQuedaPendienteLoAfectado():void
    {
        $gas=$this->cuenta('gasto');$sinCuenta=$this->concepto('Faltante sin cuenta','faltante_de_conteo',null);$ok=$this->concepto('Merma con cuenta','merma_o_perdida',$gas);
        $m1=$this->historico('salida','1');$m2=$this->historico('salida','1');
        $this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$m1,'concepto_id'=>$sinCuenta,'motivo'=>'Faltante aún sin cuenta']));
        $this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$m2,'concepto_id'=>$ok,'motivo'=>'Merma con su cuenta lista']));
        $x=$this->procesar();self::assertSame(1,$x['fallidos'],'Solo falla el ajuste cuyo concepto no tiene cuenta');self::assertSame(1,$this->asientos());
        // No se reimputa sobre una imputación que todavía no está contabilizada.
        $this->rechazo(fn()=>$this->con()->reimputar($this->owner,$this->d(['movimiento_id'=>$m1,'concepto_id'=>$this->concepto('Otro faltante','faltante_de_conteo',$this->cuenta('gasto',1)),'esperado_concepto_id'=>$sinCuenta,'motivo'=>'Intento sobre pendiente'])),409);
        $this->con()->asignarCuenta($this->owner,$this->d(['concepto_id'=>$sinCuenta,'cuenta'=>$this->cuenta('gasto',2)]));
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(2,$this->asientos());self::assertSame(8000,$this->stock());
    }
    public function testReimputarAUnConceptoSinCuentaNoDejaElLibroAMediasYSeCompletaAlAsignarla():void
    {
        $g1=$this->cuenta('gasto');$g2=$this->cuenta('gasto',1);
        $a=$this->concepto('Merma A','merma_o_perdida',$g1);$b=$this->concepto('Faltante sin cuenta','faltante_de_conteo',null);
        $mov=$this->historico('salida','2');$this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$a,'motivo'=>'Clasificación inicial del ajuste']));$this->procesar();
        $this->con()->reimputar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$b,'esperado_concepto_id'=>$a,'motivo'=>'Era un faltante, aún sin cuenta']));
        $x=$this->procesar();self::assertSame(1,$x['fallidos'],'Solo falla la reimputación sin cuenta');
        self::assertSame(1,$this->asientos(),'Ni reverso ni nueva imputación a medias: el asiento original sigue intacto');self::assertSame(500,$this->saldo($g1));
        $p=$this->con()->pendientes();self::assertCount(1,$p);self::assertSame('Faltante sin cuenta',$p[0]['concepto']);self::assertStringContainsString('Asigna una cuenta del plan',$p[0]['falta']);
        self::assertSame(8000,$this->stock());
        $this->con()->asignarCuenta($this->owner,$this->d(['concepto_id'=>$b,'cuenta'=>$g2]));
        $x=$this->procesar();self::assertSame(0,$x['fallidos'],'Al asignar la cuenta se reintenta de inmediato');
        self::assertSame(3,$this->asientos());self::assertSame(0,$this->saldo($g1));self::assertSame(500,$this->saldo($g2));self::assertSame(-500,$this->saldoInventario());self::assertSame([],$this->con()->pendientes());
    }
    public function testReimputarSobreUnAsientoVigenteYaReversadoAMano_NoLoReversaDeNuevoNiDejaLaColaFallida():void
    {
        $g1=$this->cuenta('gasto');$g2=$this->cuenta('gasto',1);$a=$this->concepto('Merma A','merma_o_perdida',$g1);$b=$this->concepto('Merma B','merma_o_perdida',$g2);
        $mov=$this->historico('salida','2');$this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$a,'motivo'=>'Clasificación inicial del ajuste']));$this->procesar();
        $orig=(int)$this->r->uno("SELECT id FROM erp_cont_asientos WHERE tipo='automatico'")['id'];
        // Alguien usa «Reversar este asiento» del Diario sobre el asiento vigente: el original ya está anulado a mano.
        $this->cont()->reversar($this->owner,$this->d(['asiento_id'=>$orig,'motivo'=>'Anulado a mano desde el diario']));
        self::assertSame(0,$this->saldo($g1));self::assertSame(2,$this->asientos());
        $this->con()->reimputar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$b,'esperado_concepto_id'=>$a,'motivo'=>'Imputar al concepto correcto']));
        $x=$this->procesar();self::assertSame(0,$x['fallidos'],'La reimputación no intenta reversar de nuevo lo ya reversado');
        self::assertSame(3,$this->asientos(),'Original + reverso manual + nueva imputación; sin segundo reverso');
        self::assertSame(0,$this->saldo($g1));self::assertSame(500,$this->saldo($g2));self::assertSame(-500,$this->saldoInventario(),'El ajuste queda reconocido una sola vez');
        self::assertSame([],$this->cont()->integridad());self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);
        $x=$this->procesar();self::assertSame(3,$this->asientos(),'Reprocesar no duplica');
    }
    public function testLaReimputacionSeSerializaSobreElMovimientoYLaCadenaNoSeBifurca():void
    {
        $a=$this->concepto('Merma A','merma_o_perdida',$this->cuenta('gasto'));$b=$this->concepto('Merma B','merma_o_perdida',$this->cuenta('gasto',1));$c=$this->concepto('Merma C','merma_o_perdida',$this->cuenta('gasto',2));
        $mov=$this->historico('salida','1');$this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$a,'motivo'=>'Clasificación de partida']));$this->procesar();
        // 1) Concurrencia real: mientras otra sesión sostiene el movimiento, la reimputación espera su turno y, vencido el plazo, no deja nada a medias.
        $db2=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'root',BaseDatosAislada::nombre($this->db),(int)(getenv('DB_PORT')?:3306));$db2->set_charset('utf8mb4');$db2->query('SET SESSION innodb_lock_wait_timeout=1');
        try{
            $r2=new ErpRepository($db2);$clave=R5CajaFixture::clave();$datos=['idem_key'=>$clave,'movimiento_id'=>$mov,'concepto_id'=>$b,'esperado_concepto_id'=>$a,'motivo'=>'Intento concurrente'];
            $this->db->begin_transaction();$this->db->query("SELECT id FROM inventario_movimientos WHERE id={$mov} FOR UPDATE");
            $fallo=null;try{$this->con($r2)->reimputar($this->owner,$datos);}catch(\Throwable $e){$fallo=$e;}
            self::assertNotNull($fallo,'Con el movimiento tomado por otra sesión, la reimputación no avanza');self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_reimputaciones')['n']);
            $this->db->rollback();
            // Liberado el movimiento, el mismo intento (misma clave) se completa una sola vez.
            $ok=$this->con($r2)->reimputar($this->owner,$datos);$otra=$this->con($r2)->reimputar($this->owner,$datos);
            self::assertSame($ok['recurso_id'],$otra['recurso_id']);self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_reimputaciones')['n']);
        }finally{$db2->close();}
        // 2) Dos personas ven el mismo estado (imputado a A): la primera ya ganó y la segunda recibe un conflicto en lugar de pisarla.
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);// la imputación ganadora ya está contabilizada: solo la comprobación de estado obsoleto puede frenar a la segunda
        $this->rechazo(fn()=>$this->con()->reimputar($this->owner,$this->d(['movimiento_id'=>$mov,'concepto_id'=>$c,'esperado_concepto_id'=>$a,'motivo'=>'Pantalla desactualizada'])),409);
        // 3) Aun saltándose el servicio, la base no admite dos hijos del mismo estado: la cadena no se bifurca.
        $f=$this->r->uno('SELECT id FROM erp_inv_reimputaciones WHERE movimiento_id=?',[$mov]);
        $ev=(int)$this->r->uno('SELECT MAX(id) m FROM erp_eventos')['m'];
        $this->sqlFalla("INSERT INTO erp_inv_reimputaciones(movimiento_id,anterior_id,concepto_origen_id,concepto_nuevo_id,motivo,evento_id,creado_por,creado_en) VALUES({$mov},0,{$a},{$c},'Bifurcación indebida',{$ev},{$this->owner},NOW())");
        $this->sqlFalla("INSERT INTO erp_inv_reimputaciones(movimiento_id,anterior_id,concepto_origen_id,concepto_nuevo_id,motivo,evento_id,creado_por,creado_en) VALUES({$mov},{$f['id']},{$b},{$b},'Mismo concepto',{$ev}+1,{$this->owner},NOW())");
        $x=$this->procesar();self::assertSame(0,$x['fallidos']);self::assertSame(3,$this->asientos(),'Original + reverso + nueva imputación, sin duplicados');self::assertSame([],$this->cont()->integridad());
    }
    // ---- Pantalla ----
    public function testPantallaMuestraHistoricosYCadenasSegunPermiso():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $a=$this->concepto('Merma A','merma_o_perdida',$this->cuenta('gasto'));$this->concepto('Merma B','merma_o_perdida',$this->cuenta('gasto',1));
            $m1=$this->historico('salida','1');$m2=$this->historico('salida','1');$this->con()->reclasificar($this->owner,$this->d(['movimiento_id'=>$m2,'concepto_id'=>$a,'motivo'=>'Clasificado antes de abrir']));$this->procesar();
            $ses=function(int $u){if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($u);$_SESSION=['login'=>true,'id'=>$u,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-ir'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/contabilidad';};
            $pag=function()use($ses){$_GET=['vista'=>'inventario'];$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::contabilidad(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}};
            $ses($this->owner);$h=$pag();
            self::assertStringContainsString('Ajustes históricos sin clasificar',$h);self::assertStringContainsString('#'.$m1,$h);self::assertStringContainsString('Clasificar ajuste',$h);self::assertStringContainsString('name="movimiento_id"',$h);
            self::assertStringContainsString('Ajustes clasificados e imputación vigente',$h);self::assertStringContainsString('Reimputar ajuste',$h);self::assertStringContainsString('name="esperado_concepto_id"',$h);self::assertStringContainsString('no cambia las existencias',$h);
            $ses($this->finanzas);$f=$pag();self::assertStringContainsString('Ajustes históricos sin clasificar',$f);self::assertStringNotContainsString('Clasificar ajuste',$f);self::assertStringNotContainsString('Reimputar ajuste',$f);
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];}
    }
}
