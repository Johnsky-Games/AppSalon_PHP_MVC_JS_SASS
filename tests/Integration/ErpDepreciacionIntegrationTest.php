<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpDepreciacionService,ErpContabilidadService,ErpIntegracionService,ErpRecursoService,ErpException};
use Services\Contabilidad\ErpContabilidadAdaptador;

/**
 * Depreciación lineal de ensayo (política contable, no tributaria). Activo A: 900,00 adquirido el 2026-01-10, vida 7 meses, sin residual, inicio 2026-01 →
 * cuotas calculadas a mano: 128,57 · 128,57 · 128,57 · 128,58 · 128,57 · 128,57 · 128,57 (suma 900,00).
 */
final class ErpDepreciacionIntegrationTest extends TestCase
{
    private const CUOTAS=[12857,12857,12857,12858,12857,12857,12857];
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $recepcion;private int $cat;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('depreciacion');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        $this->cat=$this->dep()->categoriaCrear($this->owner,$this->d(['nombre'=>'Equipos de ensayo','vida_util_meses'=>'7','residual_pct'=>'0','fuente'=>'Política de ensayo, sin valor oficial']))['recurso_id'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function dep():ErpDepreciacionService {return new ErpDepreciacionService($this->r);}
    private function cont():ErpContabilidadService {return new ErpContabilidadService($this->r);}
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function sqlFalla(string $sql):string {$ok=@$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}
    private function activo(string $codigo='EQ-1',string $costo='900.00',string $fecha='2026-01-10'):int
    {
        return (new ErpRecursoService($this->r))->activo($this->owner,$this->d(['nombre'=>'Equipo '.$codigo,'codigo'=>$codigo,'costo'=>$costo,'referencia_compra'=>'FAC-ensayo','adquirido_en'=>$fecha]))['recurso_id'];
    }
    private function ficha(int $activo,string $inicio='2026-01',array $extra=[],?int $actor=null):int
    {
        return $this->dep()->fichaCrear($actor??$this->owner,$this->d($extra+['activo_id'=>$activo,'categoria_id'=>$this->cat,'inicio'=>$inicio]))['recurso_id'];
    }
    private function mes(int $mes,bool $contabilizar=true):int
    {
        $id=$this->dep()->calcular($this->owner,$this->d(['anio'=>2026,'mes'=>$mes]))['recurso_id'];
        if($contabilizar){$this->dep()->contabilizar($this->owner,$this->d(['corrida_id'=>$id]));$this->asientos();}
        return $id;
    }
    private function asientos():void
    {
        $x=(new ErpIntegracionService($this->r))->procesar(['contabilidad'=>new ErpContabilidadAdaptador($this->r)],100);self::assertSame(0,$x['fallidos']);
    }
    private function saldoRol(string $rol):int
    {
        $f=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=?',[$this->cont()->rolCuenta($rol)]);return (int)$f['d']-(int)$f['h'];
    }
    private function control():array
    {
        foreach($this->cont()->conciliacion('2026-12-31')['controles'] as $c)if($c['nombre']==='Depreciación acumulada')return $c;self::fail('Falta el control');
    }

    public function testPoliticaYFichaValidanYNoSeDuplican():void
    {
        $c=fn(array $x)=>fn()=>$this->dep()->categoriaCrear($this->owner,$this->d($x+['nombre'=>'Otra','vida_util_meses'=>'12','residual_pct'=>'0','fuente'=>'Criterio de ensayo']));
        foreach([['vida_util_meses'=>'0'],['vida_util_meses'=>'601'],['vida_util_meses'=>'abc'],['residual_pct'=>'100'],['residual_pct'=>'1.234'],['fuente'=>'no'],['metodo'=>'acelerado'],['nombre'=>'Equipos de ensayo']] as $malo){
            try{$c($malo)();self::fail(json_encode($malo));}catch(ErpException|\InvalidArgumentException $e){self::assertContains($e->getCode(),[0,409,422],json_encode($malo));}
        }
        self::assertCount(1,$this->dep()->categorias(),'Nada de lo rechazado quedó guardado');
        $a=$this->activo();$f=$this->ficha($a);self::assertGreaterThan(0,$f);
        $this->rechazo(fn()=>$this->ficha($a),409);
        $b=$this->activo('EQ-2','500.00','2026-03-05');
        foreach([['2026-02','antes de adquirir? no: adquirido en marzo'],['2025-12','antes del inicio contable']] as [$mes]){try{$this->ficha($b,$mes);self::fail($mes);}catch(ErpException $e){self::assertContains($e->getCode(),[0,409,422],$mes);}}
        try{$this->ficha($b,'2026-3');self::fail('formato');}catch(ErpException $e){self::assertSame(422,$e->getCode()===0?422:$e->getCode());}
        $ficha=$this->r->uno('SELECT * FROM erp_dep_fichas WHERE activo_id=?',[$a]);
        self::assertSame([90000,0,7,'2026-01-01'],[(int)$ficha['costo_centavos'],(int)$ficha['residual_centavos'],(int)$ficha['vida_util_meses'],$ficha['inicio']]);
        // Cambiar o borrar la política o la ficha después no altera lo ya configurado: la base lo impide.
        self::assertStringContainsString('no se edita',$this->sqlFalla('UPDATE erp_dep_categorias SET vida_util_meses=12'));
        self::assertStringContainsString('no se edita',$this->sqlFalla('UPDATE erp_dep_fichas SET vida_util_meses=12'));
        self::assertStringContainsString('conserva',$this->sqlFalla('DELETE FROM erp_dep_fichas'));
        // Idempotencia: repetir la misma operación con la misma clave no crea otra política.
        $d=$this->d(['nombre'=>'Mobiliario','vida_util_meses'=>'120','residual_pct'=>'10','fuente'=>'Criterio de ensayo']);$x=$this->dep()->categoriaCrear($this->owner,$d);$y=$this->dep()->categoriaCrear($this->owner,$d);
        self::assertSame($x['recurso_id'],$y['recurso_id']);self::assertCount(2,$this->dep()->categorias());
    }
    public function testCorridasPorPeriodoEnOrdenSinSaltosNiDuplicados():void
    {
        $a=$this->activo();$this->ficha($a);
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2099,'mes'=>1])),409);// mes que no ha terminado
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2025,'mes'=>12])),409);// anterior al inicio contable
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2026,'mes'=>2])),409);// salta enero
        $k1=$this->mes(1,false);
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2026,'mes'=>1])),409);// ya hay una corrida vigente
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2026,'mes'=>2])),409);// el borrador de enero está pendiente
        self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_dep_corridas')['n']);
        self::assertSame(self::CUOTAS[0],(int)$this->r->uno('SELECT total_centavos t FROM erp_dep_corridas WHERE id=?',[$k1])['t']);
        $this->dep()->contabilizar($this->owner,$this->d(['corrida_id'=>$k1]));$this->asientos();
        // Contabilizar otra vez una corrida ya contabilizada (con otra clave de idempotencia) no hace nada ni duplica el asiento.
        self::assertSame($k1,$this->dep()->contabilizar($this->owner,$this->d(['corrida_id'=>$k1]))['recurso_id']);
        $this->asientos();self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE concepto LIKE 'Depreciación de activos fijos%'")['n']);
        for($m=2;$m<=7;$m++)$this->mes($m);
        $acum=0;foreach($this->r->q("SELECT l.monto_centavos m,l.acumulado_centavos a,l.valor_libros_centavos v,l.nro_cuota n FROM erp_dep_lineas l JOIN erp_dep_corridas c ON c.id=l.corrida_id WHERE c.estado='contabilizada' ORDER BY c.mes") as $i=>$l){
            $acum+=self::CUOTAS[$i];self::assertSame([self::CUOTAS[$i],$acum,90000-$acum,$i+1],[(int)$l['m'],(int)$l['a'],(int)$l['v'],(int)$l['n']]);
        }
        self::assertSame(90000,$acum);
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2026,'mes'=>8])),409);// totalmente depreciado: nada que calcular
        // El asiento: Dr gasto por depreciación · Cr depreciación acumulada, fechado el último día de cada mes, y el libro cuadra.
        self::assertSame(90000,$this->saldoRol('gasto_depreciacion'));self::assertSame(-90000,$this->saldoRol('depreciacion_acumulada'));
        self::assertSame('2026-01-31',$this->r->uno("SELECT MIN(fecha) f FROM erp_cont_asientos WHERE concepto LIKE 'Depreciación de activos fijos%'")['f']);
        self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$this->cont()->integridad());
        $c=$this->control();self::assertSame([-(-90000),90000,0],[$c['libro'],$c['auxiliar'],$c['diferencia']]);
        $r=$this->dep()->porMes(2026);self::assertCount(7,$r);self::assertSame(90000,array_sum(array_column($r,'total')));
        $act=$this->dep()->activos()[0];self::assertSame([90000,0,'totalmente_depreciado',7],[$act['acumulado'],$act['libros'],$act['estado'],$act['meses_depreciados']]);
        $cuadro=$this->dep()->cuadro($a);self::assertCount(7,$cuadro['filas']);self::assertSame('contabilizada',$cuadro['filas'][6]['estado']);
    }
    public function testActivoAnteriorAlLibroReparteElSaldoEnLosMesesQueLeQuedan():void
    {
        $a=$this->activo('EQ-OLD','1200.00','2025-06-01');
        // Con acumulado inicial se exigen los meses restantes: repartir el saldo en la vida completa de la política lo extendería más allá de lo que le queda.
        try{$this->ficha($a,'2026-01',['acumulado_inicial'=>'600.00']);self::fail('Faltan los meses restantes');}catch(ErpException $e){self::assertStringContainsString('meses de vida',$e->getMessage());}
        try{$this->ficha($a,'2026-01',['acumulado_inicial'=>'600.00','vida_util_meses'=>'8']);self::fail('Más que la política');}catch(ErpException $e){self::assertStringContainsString('no pueden superar',$e->getMessage());}
        $this->ficha($a,'2026-01',['acumulado_inicial'=>'600.00','vida_util_meses'=>'3']);
        $k=$this->mes(1);self::assertSame(20000,(int)$this->r->uno('SELECT total_centavos t FROM erp_dep_corridas WHERE id=?',[$k])['t'],'600,00 por depreciar en 3 meses');
        $this->mes(2);$this->mes(3);$act=$this->dep()->activos()[0];self::assertSame([120000,0,'totalmente_depreciado'],[$act['acumulado'],$act['libros'],$act['estado']]);
        // El acumulado inicial lo trae el asiento de apertura (manual): mientras no esté, el control lo muestra como diferencia en vez de ocultarlo.
        self::assertSame(-60000,$this->control()['diferencia']);
    }
    public function testUnActivoNoEmpiezaEnUnMesYaProcesado():void
    {
        $a=$this->activo();$this->ficha($a);$this->mes(1);
        $b=$this->activo('EQ-LATE','500.00','2026-01-20');
        $e=$this->rechazo(fn()=>$this->ficha($b,'2026-01'),409);self::assertStringContainsString('mes ya procesado',$e->getMessage());
        $this->ficha($b,'2026-02');$this->mes(2);// el mes siguiente incluye a ambos y no se bloquea
        self::assertSame(2,(int)$this->r->uno("SELECT n_lineas n FROM erp_dep_corridas WHERE mes=2 AND estado='contabilizada'")['n']);
    }
    private function estimar(array $d,?int $actor=null):int
    {
        return $this->dep()->estimacionRegistrar($actor??$this->owner,$this->d($d+['criterio'=>'Revisión de la vida útil por el contador (ensayo)']))['recurso_id'];
    }
    public function testCambioDeEstimacionEsProspectivoYNoTocaLoYaDepreciado():void
    {
        $a=$this->activo();$this->ficha($a);$this->mes(1);$this->mes(2);$this->mes(3);// acumulado 38571; quedan 51429
        $antes=$this->r->q("SELECT l.id,l.monto_centavos,l.acumulado_centavos,c.estado FROM erp_dep_lineas l JOIN erp_dep_corridas c ON c.id=l.corrida_id ORDER BY l.id");
        $asientos=(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'];
        // Se cierra marzo: el cambio no necesita abrirlo ni reversar nada.
        foreach([1,2,3] as $m)$this->cont()->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>$m]));
        $this->rechazo(fn()=>$this->estimar(['activo_id'=>$a,'desde'=>'2026-03','vida_meses'=>'4']),409);// marzo ya tiene cuota
        $this->rechazo(fn()=>$this->estimar(['activo_id'=>$a,'desde'=>'2026-05','vida_meses'=>'4']),409);// salta abril: no está al día
        foreach([['vida_meses'=>'0'],['vida_meses'=>'601'],['vida_meses'=>'x'],['residual_pct'=>'100'],['criterio'=>'no']] as $malo){
            try{$this->estimar($malo+['activo_id'=>$a,'desde'=>'2026-04','vida_meses'=>'4']);self::fail(json_encode($malo));}catch(ErpException|\InvalidArgumentException $e){self::assertContains($e->getCode(),[0,409,422],json_encode($malo));}
        }
        $this->rechazo(fn()=>$this->estimar(['activo_id'=>$a,'desde'=>'2026-04','vida_meses'=>'4'],$this->finanzas),403);
        self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_dep_estimaciones')['n'],'Lo rechazado no dejó nada');
        $e=$this->estimar(['activo_id'=>$a,'desde'=>'2026-04','vida_meses'=>'4']);
        $row=$this->r->uno('SELECT * FROM erp_dep_estimaciones WHERE id=?',[$e]);self::assertSame([38571,51429,4],[(int)$row['acumulado_previo_centavos'],(int)$row['base_centavos'],(int)$row['vida_meses']]);
        $this->rechazo(fn()=>$this->estimar(['activo_id'=>$a,'desde'=>'2026-04','vida_meses'=>'3']),409);// ya hay vigencia desde abril
        // Nada de lo anterior cambió: mismas líneas, mismos estados y ningún asiento nuevo ni reverso.
        self::assertSame($antes,$this->r->q("SELECT l.id,l.monto_centavos,l.acumulado_centavos,c.estado FROM erp_dep_lineas l JOIN erp_dep_corridas c ON c.id=l.corrida_id ORDER BY l.id"));
        $this->asientos();self::assertSame($asientos,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);self::assertSame(0,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE tipo='reverso'")['n']);
        // Abril a julio con el saldo (51 429) repartido en 4 meses, calculado a mano: 128,57 · 128,58 · 128,57 · 128,57.
        $nuevas=[];foreach([4,5,6,7] as $m){$k=$this->mes($m);$nuevas[]=(int)$this->r->uno('SELECT total_centavos t FROM erp_dep_corridas WHERE id=?',[$k])['t'];}
        self::assertSame([12857,12858,12857,12857],$nuevas);self::assertSame(90000,array_sum($nuevas)+38571);
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2026,'mes'=>8])),409);
        self::assertSame(90000,$this->saldoRol('gasto_depreciacion'));self::assertSame(0,$this->control()['diferencia']);self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);self::assertSame([],$this->cont()->integridad());
        $cuadro=$this->dep()->cuadro($a)['filas'];self::assertCount(7,$cuadro);self::assertSame('0',(string)$cuadro[6]['libros']);self::assertSame('contabilizada',$cuadro[6]['estado']);
        $ev=$this->r->uno("SELECT actor_id a,datos_json d FROM erp_eventos WHERE tipo='dep_estimacion_registrada'");self::assertSame($this->owner,(int)$ev['a']);self::assertStringNotContainsStringIgnoringCase('idem_key',(string)$ev['d']);
        self::assertStringContainsString('solo se anula',$this->sqlFalla("UPDATE erp_dep_estimaciones SET vida_meses=9 WHERE id=$e"));self::assertStringContainsString('conserva',$this->sqlFalla('DELETE FROM erp_dep_estimaciones'));
    }
    public function testCambioDeEstimacionPuedeCambiarElResidualHaciaAdelante():void
    {
        $a=$this->activo();$this->ficha($a);$this->mes(1);$this->mes(2);// acumulado 25714
        $this->estimar(['activo_id'=>$a,'desde'=>'2026-03','vida_meses'=>'5','residual_pct'=>'10']);// residual 90,00; saldo 552,86 en 5 meses
        $cuotas=[];foreach([3,4,5,6,7] as $m){$k=$this->mes($m);$cuotas[]=(int)$this->r->uno('SELECT total_centavos t FROM erp_dep_corridas WHERE id=?',[$k])['t'];}
        self::assertSame([11057,11057,11058,11057,11057],$cuotas,'55 286 en 5 meses, calculado a mano');
        $act=$this->dep()->activos()[0];self::assertSame([81000,9000,'totalmente_depreciado'],[$act['acumulado'],$act['libros'],$act['estado']],'Queda el residual en libros');
        self::assertSame(0,$this->control()['diferencia']);
        // Con un residual que ya cubre lo depreciado no hay nada que repartir (rechazo, sin dejar fila).
        $b=$this->activo('EQ-2','500.00','2026-01-20');$this->ficha($b,'2026-08');$this->mes(8);
        $e=$this->rechazo(fn()=>$this->estimar(['activo_id'=>$b,'desde'=>'2026-09','vida_meses'=>'3','residual_pct'=>'99.99']),422);self::assertStringContainsString('no queda nada',$e->getMessage());
    }
    public function testAnularUnCambioDeEstimacionNoPuedeTocarDepreciacionesValidas():void
    {
        $a=$this->activo();$this->ficha($a);$this->mes(1);$this->mes(2);$k3=$this->mes(3);
        $e=$this->estimar(['activo_id'=>$a,'desde'=>'2026-04','vida_meses'=>'4']);
        // Marzo es la base de la vigencia de abril: no se anula mientras esta exista.
        $x=$this->rechazo(fn()=>$this->dep()->anular($this->owner,$this->d(['corrida_id'=>$k3,'motivo'=>'Intento de reversar marzo'])),409);self::assertStringContainsString('estimación',$x->getMessage());
        $this->rechazo(fn()=>$this->dep()->estimacionAnular($this->owner,$this->d(['estimacion_id'=>$e,'motivo'=>'x'])),422);
        $this->rechazo(fn()=>$this->dep()->estimacionAnular($this->finanzas,$this->d(['estimacion_id'=>$e,'motivo'=>'Sin permiso de configurar'])),403);
        $this->dep()->estimacionAnular($this->owner,$this->d(['estimacion_id'=>$e,'motivo'=>'Se había registrado por error']));// aún sin cuotas: se puede
        self::assertSame('anulada',$this->r->uno('SELECT estado FROM erp_dep_estimaciones WHERE id=?',[$e])['estado']);
        $abril=$this->mes(4,false);self::assertSame(12858,(int)$this->r->uno('SELECT total_centavos t FROM erp_dep_corridas WHERE id=?',[$abril])['t'],'Sin la vigencia vuelve la cuota original');
        $this->dep()->anular($this->owner,$this->d(['corrida_id'=>$abril,'motivo'=>'Descarte del borrador de abril']));
        $e2=$this->estimar(['activo_id'=>$a,'desde'=>'2026-04','vida_meses'=>'4']);$this->mes(4,false);
        $this->rechazo(fn()=>$this->dep()->estimacionAnular($this->owner,$this->d(['estimacion_id'=>$e2,'motivo'=>'Ya hay cuota de abril'])),409);
        self::assertSame('vigente',$this->r->uno('SELECT estado FROM erp_dep_estimaciones WHERE id=?',[$e2])['estado']);
    }
    public function testAnularReversaEnOrdenInversoYPermiteRecalcular():void
    {
        $a=$this->activo();$this->ficha($a);$k1=$this->mes(1);$k2=$this->mes(2);
        $this->rechazo(fn()=>$this->dep()->anular($this->owner,$this->d(['corrida_id'=>$k1,'motivo'=>'Error de captura'])),409);// febrero depende de enero
        $this->rechazo(fn()=>$this->dep()->anular($this->owner,$this->d(['corrida_id'=>$k2,'motivo'=>'x'])),422);
        $this->dep()->anular($this->owner,$this->d(['corrida_id'=>$k2,'motivo'=>'Cuota de febrero mal calculada']));$this->asientos();
        self::assertSame(self::CUOTAS[0],$this->saldoRol('gasto_depreciacion'),'El reverso devuelve el gasto a lo de enero');
        self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_cont_asientos WHERE tipo='reverso'")['n']);
        $c=$this->control();self::assertSame(0,$c['diferencia']);
        $k2b=$this->mes(2);self::assertNotSame($k2,$k2b);self::assertSame(2,(int)$this->r->uno('SELECT version v FROM erp_dep_corridas WHERE id=?',[$k2b])['v']);
        self::assertSame(self::CUOTAS[0]+self::CUOTAS[1],$this->saldoRol('gasto_depreciacion'));self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);
        // Un borrador se descarta sin asiento.
        $k3=$this->mes(3,false);$antes=(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n'];
        $this->dep()->anular($this->owner,$this->d(['corrida_id'=>$k3,'motivo'=>'Descarte del borrador']));$this->asientos();
        self::assertSame($antes,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos')['n']);
        self::assertStringContainsString('conserva',$this->sqlFalla('DELETE FROM erp_dep_corridas'));self::assertStringContainsString('no se editan',$this->sqlFalla('UPDATE erp_dep_lineas SET monto_centavos=1'));
        self::assertNotSame('',$this->sqlFalla("UPDATE erp_dep_corridas SET total_centavos=1 WHERE id=$k1"));
    }
    public function testBajaSaldaAcumuladoYReconoceLaPerdidaYSePuedeAnular():void
    {
        $a=$this->activo();$this->ficha($a);$this->mes(1);$this->mes(2);$this->mes(3);
        $this->rechazo(fn()=>$this->dep()->bajaRegistrar($this->owner,$this->d(['activo_id'=>$a,'fecha'=>'2099-01-01','motivo'=>'dano'])),422);
        foreach(['venta','regalo',''] as $m){try{$this->dep()->bajaRegistrar($this->owner,$this->d(['activo_id'=>$a,'fecha'=>'2026-04-15','motivo'=>$m]));self::fail($m);}catch(ErpException $e){self::assertContains($e->getCode(),[0,409,422]);}}
        $this->rechazo(fn()=>$this->dep()->bajaRegistrar($this->owner,$this->d(['activo_id'=>$a,'fecha'=>'2026-04-15','motivo'=>'otro'])),422);// «otro» exige nota
        $b=$this->dep()->bajaRegistrar($this->owner,$this->d(['activo_id'=>$a,'fecha'=>'2026-04-15','motivo'=>'dano','nota'=>'Se cayó']))['recurso_id'];$this->asientos();
        $baja=$this->r->uno('SELECT * FROM erp_dep_bajas WHERE id=?',[$b]);$acum=self::CUOTAS[0]+self::CUOTAS[1]+self::CUOTAS[2];
        self::assertSame([90000,$acum,90000-$acum],[(int)$baja['costo_centavos'],(int)$baja['acumulado_centavos'],(int)$baja['valor_libros_centavos']]);
        self::assertSame(0,$this->saldoRol('depreciacion_acumulada'),'La depreciación acumulada del activo queda saldada');self::assertSame(90000-$acum,$this->saldoRol('perdida_baja_activos'));
        self::assertSame('baja',$this->dep()->activos()[0]['estado']);self::assertSame(0,$this->control()['diferencia']);self::assertTrue($this->cont()->balanceComprobacion('2099-12-31')['cuadra']);
        $this->rechazo(fn()=>$this->dep()->bajaRegistrar($this->owner,$this->d(['activo_id'=>$a,'fecha'=>'2026-04-16','motivo'=>'dano'])),409);// ya dado de baja
        $this->rechazo(fn()=>$this->dep()->calcular($this->owner,$this->d(['anio'=>2026,'mes'=>4])),409);// el activo ya no se deprecia: ninguna cuota
        // Anular la baja revierte el asiento y vuelve a dejar el activo en depreciación.
        $this->dep()->bajaAnular($this->owner,$this->d(['baja_id'=>$b,'motivo'=>'Se localizó el equipo']));$this->asientos();
        self::assertSame(-$acum,$this->saldoRol('depreciacion_acumulada'));self::assertSame(0,$this->saldoRol('perdida_baja_activos'));self::assertSame('en_depreciacion',$this->dep()->activos()[0]['estado']);self::assertSame(0,$this->control()['diferencia']);
        // Con una corrida del mes de la baja o posterior ya no se puede dar de baja con esa fecha, y una baja vigente impide anular corridas anteriores.
        $this->mes(4);$this->rechazo(fn()=>$this->dep()->bajaRegistrar($this->owner,$this->d(['activo_id'=>$a,'fecha'=>'2026-04-20','motivo'=>'dano'])),409);
        $b2=$this->dep()->bajaRegistrar($this->owner,$this->d(['activo_id'=>$a,'fecha'=>'2026-05-10','motivo'=>'obsolescencia']))['recurso_id'];
        $k4=(int)$this->r->uno('SELECT id FROM erp_dep_corridas WHERE mes=4')['id'];$this->rechazo(fn()=>$this->dep()->anular($this->owner,$this->d(['corrida_id'=>$k4,'motivo'=>'Intento con baja vigente'])),409);
        self::assertGreaterThan(0,$b2);self::assertSame(1,(int)$this->r->uno("SELECT COUNT(*) n FROM erp_dep_bajas WHERE estado='vigente'")['n']);
        self::assertNotSame('',$this->sqlFalla("UPDATE erp_dep_bajas SET costo_centavos=1 WHERE id=$b2"));self::assertStringContainsString('conserva',$this->sqlFalla('DELETE FROM erp_dep_bajas'));
    }
    public function testPermisosYAuditoria():void
    {
        $a=$this->activo();
        // Finanzas registra y contabiliza, pero no configura; recepción no entra.
        $this->rechazo(fn()=>$this->ficha($a,'2026-01',[],$this->finanzas),403);
        $this->rechazo(fn()=>$this->dep()->categoriaCrear($this->finanzas,$this->d(['nombre'=>'X','vida_util_meses'=>'12','residual_pct'=>'0','fuente'=>'Criterio de ensayo'])),403);
        $this->rechazo(fn()=>$this->dep()->calcular($this->recepcion,$this->d(['anio'=>2026,'mes'=>1])),403);
        $this->ficha($a);
        $k=$this->dep()->calcular($this->finanzas,$this->d(['anio'=>2026,'mes'=>1]))['recurso_id'];$this->dep()->contabilizar($this->finanzas,$this->d(['corrida_id'=>$k]));
        $this->rechazo(fn()=>$this->dep()->anular($this->finanzas,$this->d(['corrida_id'=>$k,'motivo'=>'Sin permiso de configurar'])),403);
        $this->rechazo(fn()=>$this->dep()->bajaRegistrar($this->finanzas,$this->d(['activo_id'=>$a,'fecha'=>'2026-03-01','motivo'=>'dano'])),403);
        self::assertSame('contabilizada',$this->r->uno('SELECT estado FROM erp_dep_corridas WHERE id=?',[$k])['estado'],'El rechazo no cambió nada');
        $this->dep()->anular($this->owner,$this->d(['corrida_id'=>$k,'motivo'=>'Prueba de auditoría']));
        foreach(['dep_categoria_creada','dep_ficha_creada','dep_corrida_calculada','depreciacion_contabilizada','depreciacion_anulada'] as $t){
            $e=$this->r->uno('SELECT * FROM erp_eventos WHERE tipo=? ORDER BY id DESC LIMIT 1',[$t]);self::assertNotNull($e,$t);self::assertGreaterThan(0,(int)$e['actor_id'],$t);
            self::assertStringNotContainsStringIgnoringCase('idem_key',(string)$e['datos_json'],$t);
        }
        self::assertSame($this->finanzas,(int)$this->r->uno("SELECT actor_id a FROM erp_eventos WHERE tipo='depreciacion_contabilizada'")['a']);
    }

    // ---- Interfaz ----
    private function sesion(int $usuario):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>'equipo','version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-dep'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/contabilidad';
    }
    private function pagina(array $get):string
    {
        $_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::contabilidad(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function estado(callable $f):int {$e=200;ob_start();try{$f();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;}
    public function testPestanaActivosFijosSeRenderizaYSusFormulariosOperan():void
    {
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $a=$this->activo();$this->sesion($this->owner);
            $h=$this->pagina(['vista'=>'activos']);self::assertStringContainsString('Depreciación de activos fijos',$h);self::assertStringContainsString('no están implementados',$h);self::assertStringContainsString('Configurar un activo',$h);self::assertStringContainsString('Cambios de estimación',$h);
            $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';
            $_POST=['accion'=>'dep_ficha','vista'=>'activos','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-dep','activo_id'=>(string)$a,'categoria_id'=>(string)$this->cat,'inicio'=>'2026-01','acumulado_inicial'=>''];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));self::assertSame(1,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_dep_fichas')['n']);
            $_POST=['accion'=>'dep_calcular','vista'=>'activos','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-dep','anio'=>'2026','mes'=>'1'];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));$k=(int)$this->r->uno('SELECT id FROM erp_dep_corridas')['id'];
            $h=$this->pagina(['vista'=>'activos','activo'=>(string)$a]);self::assertStringContainsString('Contabilizar',$h);self::assertStringContainsString('Cuadro de depreciación',$h);self::assertStringContainsString('128.57',$h);
            $_SERVER['REQUEST_METHOD']='POST';$_POST=['accion'=>'dep_contabilizar','vista'=>'activos','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-dep','corrida_id'=>(string)$k];
            self::assertSame(303,$this->estado(fn()=>\Controllers\ErpController::accion()));self::assertSame('contabilizada',$this->r->uno('SELECT estado FROM erp_dep_corridas')['estado']);
            $this->asientos();self::assertStringContainsString('Anular y reversar',$this->pagina(['vista'=>'activos']));
            self::assertStringContainsString('Depreciación acumulada',$this->pagina(['vista'=>'conciliacion']));
            // Finanzas ve la pestaña y calcula, pero sin formularios de configuración; recepción recibe 403.
            $this->sesion($this->finanzas);$f=$this->pagina(['vista'=>'activos']);self::assertStringContainsString('Corridas mensuales',$f);self::assertStringNotContainsString('Crear política',$f);self::assertStringNotContainsString('Anular y reversar',$f);
            $_SERVER['REQUEST_METHOD']='POST';$_POST=['accion'=>'dep_baja','vista'=>'activos','idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-dep','activo_id'=>(string)$a,'fecha'=>'2026-03-01','motivo'=>'dano'];
            $this->estado(fn()=>\Controllers\ErpController::accion());self::assertSame(0,(int)$this->r->uno('SELECT COUNT(*) n FROM erp_dep_bajas')['n'],'Finanzas no da de baja activos');
            $this->sesion($this->recepcion);$_SERVER['REQUEST_METHOD']='GET';self::assertSame(403,$this->estado(fn()=>\Controllers\ErpController::contabilidad(new \MVC\Router())));
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
}
