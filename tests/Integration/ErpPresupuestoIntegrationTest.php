<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpContabilidadService,ErpException,ErpPresupuestoService};

/**
 * Presupuestos v1 (064): versiones (borrador/revisión/aprobada/reemplazada) inmutables una vez aprobadas, permisos separados para preparar y aprobar,
 * carga CSV con vista previa, comparativo contra lo real (ventas devengadas netas de descuentos y devoluciones, gasto devengado del libro, comisiones como
 * partida propia sin duplicar) y vistas BI de solo lectura. Un presupuesto no genera ventas, cobros, obligaciones ni asientos.
 *
 * Hechos reales (fechas fijas del pasado 2026, sin depender del reloj):
 *  V1 (confirmada 2026-03-10): servicio S1 100,00 − 10,00 desc. + 15 % = base 90,00 + imp. 13,50 (total 103,50); producto P1 20,00 sin impuesto.
 *  V2 (confirmada 2026-04-05): S1 50,00 + 15 % (base 50,00 + imp. 7,50); línea sin servicio ni producto 10,00.
 *  V3 borrador y V4 anulada: no cuentan. V5 de 2025: otro año.
 *  Devolución de la mitad de la línea S1 de V1 el 2026-04-20: 51,75 (base 45,00 + imp. 6,75).
 *  Libro: marzo operativo 300,00, comisiones 80,00, costo de ventas 40,00; abril operativo 120,00 (+ otro de 50,00 reversado).
 */
final class ErpPresupuestoIntegrationTest extends TestCase
{
    private const VISTAS=['bi_presupuesto_real_mes','bi_presupuesto_fotos','bi_presupuesto_foto_lineas','bi_presupuesto_lineas','bi_presupuesto_vs_real','bi_presupuesto_anual','bi_presupuesto_cobros_mes'];
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $gerente;private int $recepcion;
    private int $s1;private int $p1;private int $cuentaOperativo;private int $cuentaComisiones;private int $cuentaCosto;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('presup');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');
        $this->gerente=R5CajaFixture::usuario($this->db,'gerente');$this->recepcion=R5CajaFixture::usuario($this->db,'recepcion');
        $this->s1=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Corte de ensayo',99.99,40)");
        $this->p1=$this->ins("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('PR1','Champú de ensayo','unidad',1000)");
        $this->cuentaOperativo=$this->cuenta('6.1.05');$this->cuentaComisiones=$this->cuenta('6.1.01');$this->cuentaCosto=$this->cuenta('5.1.01');
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function svc():ErpPresupuestoService {return new ErpPresupuestoService($this->r);}
    private function n(string $sql):int {return (int)($this->db->query($sql)->fetch_row()[0]??0);}
    private function ins(string $sql):int {self::assertTrue($this->db->query($sql)!==false,$this->db->error);return (int)$this->db->insert_id;}
    private function cuenta(string $codigo):int {return (int)$this->r->uno('SELECT id FROM erp_cont_cuentas WHERE codigo=?',[$codigo])['id'];}
    private function rechazo(callable $f,?int $codigo=null,string $texto=''):\Throwable
    {
        try{$f();}catch(ErpException|\InvalidArgumentException $e){if($codigo!==null)self::assertSame($codigo,$e->getCode(),$e->getMessage());if($texto!=='')self::assertStringContainsString($texto,$e->getMessage());return $e;}
        self::fail('Se esperaba un rechazo');
    }
    private function sqlFalla(string $sql):string {mysqli_report(MYSQLI_REPORT_OFF);$ok=$this->db->query($sql);self::assertFalse($ok,'La base debía rechazar: '.$sql);return $this->db->error;}

    // ---- hechos reales ----
    private function venta(string $estado,?string $confirmado,string $creado='2026-03-01 09:00:00'):int
    {
        return $this->ins("INSERT INTO erp_ventas(comprador_nombre,comprador_json,estado,creado_por,creado_en,confirmado_en) VALUES('Consumidor final','{}','$estado',{$this->owner},'$creado',".($confirmado?"'$confirmado'":'NULL').")");
    }
    private function linea(int $venta,?int $servicio,?int $producto,int $precio,int $descuento,int $pb,int $imp,int $total):int
    {
        return $this->ins("INSERT INTO erp_venta_lineas(venta_id,servicio_id,producto_id,nombre,cantidad_milesimas,precio_centavos,descuento_centavos,impuesto_pb,impuesto_centavos,total_centavos) VALUES($venta,".($servicio??'NULL').','.($producto??'NULL').",'Concepto',1000,$precio,$descuento,$pb,$imp,$total)");
    }
    private function asiento(string $fecha,string $debe,string $monto,string $haber='1.1.01'):int
    {
        return (new ErpContabilidadService($this->r))->asiento($this->owner,$this->d(['fecha'=>$fecha,'concepto'=>'Hecho de ensayo','lineas'=>[['cuenta'=>$debe,'debe'=>$monto],['cuenta'=>$haber,'haber'=>$monto]]]))['recurso_id'];
    }
    /** @return array{linea:int} */
    private function hechos():array
    {
        $v1=$this->venta('confirmada','2026-03-10 10:00:00');$l1=$this->linea($v1,$this->s1,null,10000,1000,1500,1350,10350);$this->linea($v1,null,$this->p1,2000,0,0,0,2000);
        $v2=$this->venta('confirmada','2026-04-05 10:00:00');$this->linea($v2,$this->s1,null,5000,0,1500,750,5750);$this->linea($v2,null,null,1000,0,0,0,1000);
        $v3=$this->venta('borrador',null);$this->linea($v3,$this->s1,null,99900,0,0,0,99900);
        $v4=$this->venta('anulada','2026-03-12 10:00:00');$l4=$this->linea($v4,$this->s1,null,88800,0,0,0,88800);
        // Venta devuelta por completo y luego anulada: ni la venta ni su devolución cuentan.
        $this->ins("INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,cantidad_entregada_milesimas,reintegrar_stock,motivo,actor_id,creado_en) VALUES($v4,$l4,1000,88800,0,0,'Devuelta y anulada',{$this->owner},'2026-03-13 11:00:00')");
        $v5=$this->venta('confirmada','2025-12-31 23:00:00');$this->linea($v5,$this->s1,null,7700,0,0,0,7700);
        $this->ins("INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,cantidad_entregada_milesimas,reintegrar_stock,motivo,actor_id,creado_en) VALUES($v1,$l1,500,5175,0,0,'Devolución de ensayo',{$this->owner},'2026-04-20 11:00:00')");
        $this->asiento('2026-03-15','6.1.05','300.00');$this->asiento('2026-03-20','6.1.01','80.00','2.1.05');$this->asiento('2026-03-25','5.1.01','40.00');
        $this->asiento('2026-04-02','6.1.05','120.00');$a=$this->asiento('2026-04-10','6.1.05','50.00');
        (new ErpContabilidadService($this->r))->reversar($this->owner,$this->d(['asiento_id'=>$a,'motivo'=>'Error de captura','fecha'=>'2026-04-11']));
        $this->asiento('2026-03-18','1.1.01','999.00','3.1.01');// activo contra patrimonio: nunca es gasto
        return ['linea'=>$l1];
    }
    private function presupuesto(string $escenario='base',int $anio=2026,?int $actor=null):int
    {return $this->svc()->crear($actor??$this->finanzas,$this->d(['anio'=>$anio,'escenario'=>$escenario]))['recurso_id'];}
    private function cifra(int $v,string $concepto,int $mes,string $base,string $imp='',?int $actor=null):void
    {$this->svc()->linea($actor??$this->finanzas,$this->d(['version_id'=>$v,'concepto'=>$concepto,'mes'=>$mes,'base'=>$base,'impuesto'=>$imp]));}
    private function aprobar(int $v):void {$this->svc()->aprobar($this->owner,$this->d(['version_id'=>$v]));}
    /** Presupuesto aprobado con las cifras del comparativo. */
    private function presupuestoAprobado():int
    {
        $v=$this->presupuesto();
        $this->cifra($v,'servicio:'.$this->s1,3,'100.00','15.00');$this->cifra($v,'servicio:'.$this->s1,4,'0','0');$this->cifra($v,'producto:'.$this->p1,3,'25.00','0');
        $this->cifra($v,'gasto:'.$this->cuentaOperativo,3,'250.00');$this->cifra($v,'comisiones:0',3,'100.00');
        $this->aprobar($v);return $v;
    }

    // ---- reglas del presupuesto ----
    public function testNaceVacioNoGeneraNingunHechoEconomicoYGuardaQuienHizoCadaCosa():void
    {
        $tablas=['erp_ventas','erp_venta_lineas','pagos','caja_movimientos','erp_cont_asientos','erp_cont_lineas','erp_obligaciones','erp_obligacion_pagos','erp_pago_aplicaciones','erp_integraciones_outbox'];
        $antes=array_map(fn($t)=>$this->n("SELECT COUNT(*) FROM $t"),$tablas);
        $v=$this->presupuesto();
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas"),'un presupuesto nuevo está vacío: nada inventado');
        self::assertSame('borrador',$this->r->uno('SELECT estado FROM erp_presupuesto_versiones WHERE id=?',[$v])['estado']);
        $this->cifra($v,'servicio:'.$this->s1,3,'100.00','15.00');$this->cifra($v,'gasto:'.$this->cuentaOperativo,3,'250.00');$this->cifra($v,'comisiones:0',3,'100.00');
        $this->aprobar($v);$rev=$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$this->r->uno('SELECT presupuesto_id n FROM erp_presupuesto_versiones WHERE id=?',[$v])['n'],'motivo'=>'Ajuste']))['recurso_id'];
        $this->cifra($rev,'servicio:'.$this->s1,3,'120.00','18.00');$this->aprobar($rev);
        self::assertSame($antes,array_map(fn($t)=>$this->n("SELECT COUNT(*) FROM $t"),$tablas),'crear, cargar, aprobar y revisar un presupuesto no crea ventas, pagos, caja, obligaciones, asientos ni salidas de integración');
        $c=$this->r->uno("SELECT * FROM erp_presupuesto_cambios WHERE version_id=? AND tipo='servicio' AND accion='modificar'",[$rev]);
        self::assertSame([10000,1500,12000,1800,'manual',$this->finanzas],[(int)$c['base_antes_centavos'],(int)$c['impuesto_antes_centavos'],(int)$c['base_despues_centavos'],(int)$c['impuesto_despues_centavos'],$c['origen'],(int)$c['actor_id']],'valor anterior, nuevo y autor');
        $ap=$this->r->uno('SELECT aprobado_por,aprobado_en,creado_por FROM erp_presupuesto_versiones WHERE id=?',[$rev]);
        self::assertSame([$this->owner,$this->finanzas],[(int)$ap['aprobado_por'],(int)$ap['creado_por']],'quién aprobó y quién preparó');
        self::assertNotNull($ap['aprobado_en']);
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_eventos WHERE tipo='presupuesto_aprobado' AND entidad_id=$rev AND actor_id={$this->owner}"));
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_eventos WHERE tipo='presupuesto_creado' AND actor_id={$this->finanzas}"));
    }

    public function testPermisosSeparadosVerPrepararYAprobar():void
    {
        $this->rechazo(fn()=>$this->svc()->crear($this->gerente,$this->d(['anio'=>2026,'escenario'=>'x'])),403);
        $this->rechazo(fn()=>$this->svc()->crear($this->recepcion,$this->d(['anio'=>2026,'escenario'=>'x'])),403);
        $v=$this->presupuesto();$this->cifra($v,'comisiones:0',1,'10.00');
        $this->rechazo(fn()=>$this->svc()->aprobar($this->finanzas,$this->d(['version_id'=>$v])),403);
        $this->rechazo(fn()=>$this->svc()->aprobar($this->gerente,$this->d(['version_id'=>$v])),403);
        self::assertSame('borrador',$this->r->uno('SELECT estado FROM erp_presupuesto_versiones WHERE id=?',[$v])['estado']);
        $az=new \Services\AuthorizationService($this->db);
        self::assertTrue($az->permite($this->gerente,'presupuestos.ver'));self::assertFalse($az->permite($this->gerente,'presupuestos.editar'));
        self::assertTrue($az->permite($this->finanzas,'presupuestos.editar'));self::assertFalse($az->permite($this->finanzas,'presupuestos.aprobar'));
        self::assertTrue($az->permite($this->owner,'presupuestos.aprobar'));self::assertFalse($az->permite($this->recepcion,'presupuestos.ver'));
    }

    public function testValidacionesBaseImpuestoCerosCuentasYUnicidad():void
    {
        $v=$this->presupuesto();
        $this->rechazo(fn()=>$this->svc()->crear($this->finanzas,$this->d(['anio'=>2026,'escenario'=>'BASE'])),409,'Ya existe');
        $this->rechazo(fn()=>$this->svc()->crear($this->finanzas,$this->d(['anio'=>1999,'escenario'=>'x'])));
        $this->rechazo(fn()=>$this->cifra($v,'servicio:'.$this->s1,3,'100.00',''),null,'impuesto');
        $this->rechazo(fn()=>$this->cifra($v,'servicio:'.$this->s1,3,'','5.00'),null,'base');
        $this->rechazo(fn()=>$this->cifra($v,'gasto:'.$this->cuentaOperativo,3,'10.00','1.00'),null,'sin impuesto');
        $this->rechazo(fn()=>$this->cifra($v,'servicio:'.$this->s1,13,'1.00','0'),null,'mes');
        $this->rechazo(fn()=>$this->cifra($v,'servicio:'.$this->s1,3,'-5.00','0'));
        $this->rechazo(fn()=>$this->cifra($v,'servicio:'.$this->s1,3,'1,50','0'));
        $this->rechazo(fn()=>$this->cifra($v,'servicio:'.$this->s1,3,'1e3','0'));
        $this->rechazo(fn()=>$this->cifra($v,'servicio:9999',3,'1.00','0'),null,'no existe');
        $this->rechazo(fn()=>$this->cifra($v,'gasto:'.$this->cuenta('1.1.01'),3,'1.00'),null,'gasto o costo');
        $this->rechazo(fn()=>$this->cifra($v,'gasto:'.$this->cuentaComisiones,3,'1.00'),null,'Comisiones');
        $this->rechazo(fn()=>$this->cifra($v,'otro',3,'1.00'));
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'),'ningún intento inválido dejó una cifra');
        // El cero es una cifra; quitar es otra cosa; modificar conserva una sola línea por mes y concepto.
        $this->cifra($v,'servicio:'.$this->s1,3,'0','0');
        self::assertSame(1,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE base_centavos=0'));
        $this->cifra($v,'servicio:'.$this->s1,3,'80.50','12.08');
        self::assertSame([8050,1208,1],[(int)$this->n('SELECT base_centavos FROM erp_presupuesto_lineas'),(int)$this->n('SELECT impuesto_centavos FROM erp_presupuesto_lineas'),$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas')]);
        $this->cifra($v,'servicio:'.$this->s1,3,'','');
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'));
        self::assertSame(['crear','modificar','quitar'],array_column($this->r->q('SELECT accion FROM erp_presupuesto_cambios WHERE version_id=? ORDER BY id',[$v]),'accion'),'cada cambio queda en el historial');
        // La base también lo impide aunque se salte el servicio.
        $this->sqlFalla("INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,cuenta_id,base_centavos) VALUES($v,3,'gasto',{$this->cuentaComisiones},100)");
        $this->sqlFalla("INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,cuenta_id,base_centavos) VALUES($v,3,'gasto',{$this->cuenta('1.1.01')},100)");
        $this->sqlFalla("INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,cuenta_id,base_centavos,impuesto_centavos) VALUES($v,3,'gasto',{$this->cuentaOperativo},100,5)");
        $this->sqlFalla("INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,servicio_id,base_centavos) VALUES($v,3,'servicio',NULL,100)");
        $this->sqlFalla("INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,servicio_id,base_centavos) VALUES($v,0,'servicio',{$this->s1},100)");
        $this->sqlFalla("INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,servicio_id,base_centavos) VALUES($v,3,'servicio',{$this->s1},-1)");
    }

    public function testVersionesSeAprueban_SeConservan_YLaRevisionNoTocaLaAprobada():void
    {
        $v1=$this->presupuesto();$p=(int)$this->r->uno('SELECT presupuesto_id n FROM erp_presupuesto_versiones WHERE id=?',[$v1])['n'];
        $this->rechazo(fn()=>$this->aprobar($v1),null,'sin cifras');
        $this->rechazo(fn()=>$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>'x'])),null,'aprobada');
        $this->cifra($v1,'servicio:'.$this->s1,3,'100.00','15.00');$this->cifra($v1,'comisiones:0',3,'50.00');
        $this->aprobar($v1);
        self::assertSame('aprobada',$this->r->uno('SELECT estado FROM erp_presupuesto_versiones WHERE id=?',[$v1])['estado']);
        // Una aprobada no se edita ni por el servicio ni por la base.
        $this->rechazo(fn()=>$this->cifra($v1,'servicio:'.$this->s1,3,'1.00','0'),409);
        $this->rechazo(fn()=>$this->aprobar($v1),409);
        $this->sqlFalla("UPDATE erp_presupuesto_lineas SET base_centavos=1 WHERE version_id=$v1");
        $this->sqlFalla("DELETE FROM erp_presupuesto_lineas WHERE version_id=$v1");
        $this->sqlFalla("INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,servicio_id,base_centavos) VALUES($v1,5,'servicio',{$this->s1},100)");
        $this->sqlFalla("DELETE FROM erp_presupuesto_versiones WHERE id=$v1");
        $this->sqlFalla("UPDATE erp_presupuesto_versiones SET estado='borrador',aprobado_en=NULL,aprobado_por=NULL WHERE id=$v1");
        $this->sqlFalla("UPDATE erp_presupuesto_versiones SET aprobado_por={$this->finanzas} WHERE id=$v1");
        $this->sqlFalla("UPDATE erp_presupuestos SET escenario='otro' WHERE id=$p");
        $this->sqlFalla("DELETE FROM erp_presupuestos WHERE id=$p");
        $this->sqlFalla("UPDATE erp_presupuesto_cambios SET actor_id=1");
        $this->sqlFalla("DELETE FROM erp_presupuesto_cambios");
        // Revisión: copia, una sola versión abierta, y la aprobada sigue vigente.
        $this->rechazo(fn()=>$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>''])));
        $v2=$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>'Subida por temporada alta']))['recurso_id'];
        $v2r=$this->r->uno('SELECT * FROM erp_presupuesto_versiones WHERE id=?',[$v2]);
        self::assertSame(['revision',2,$v1],[$v2r['estado'],(int)$v2r['numero'],(int)$v2r['origen_version_id']]);
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE version_id=$v2"),'la revisión parte de una copia');
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM erp_presupuesto_cambios WHERE version_id=$v2 AND origen='revision' AND accion='crear'"));
        $this->rechazo(fn()=>$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>'otra'])),409,'abierta');
        $this->cifra($v2,'servicio:'.$this->s1,3,'130.00','19.50');
        self::assertSame(10000,$this->n("SELECT base_centavos FROM erp_presupuesto_lineas WHERE version_id=$v1 AND tipo='servicio'"),'la aprobada vigente no cambió mientras se revisa');
        self::assertSame('aprobada',$this->r->uno('SELECT estado FROM erp_presupuesto_versiones WHERE id=?',[$v1])['estado']);
        $this->rechazo(fn()=>$this->svc()->aprobar($this->finanzas,$this->d(['version_id'=>$v2])),403);
        $this->aprobar($v2);
        $estados=array_column($this->r->q('SELECT numero,estado FROM erp_presupuesto_versiones WHERE presupuesto_id=? ORDER BY numero',[$p]),'estado','numero');
        self::assertSame([1=>'reemplazada',2=>'aprobada'],$estados,'la anterior se conserva como reemplazada y solo hay una vigente');
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE version_id=$v1"),'las cifras de la versión reemplazada siguen intactas');
        self::assertSame($this->owner,(int)$this->r->uno('SELECT aprobado_por n FROM erp_presupuesto_versiones WHERE id=?',[$v1])['n'],'quién aprobó la v1 se conserva');
        $this->sqlFalla("UPDATE erp_presupuesto_versiones SET estado='aprobada' WHERE id=$v1");
        // Otra revisión sobre la nueva vigente.
        $v3=$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>'Tercera']))['recurso_id'];
        self::assertSame(3,(int)$this->r->uno('SELECT numero n FROM erp_presupuesto_versiones WHERE id=?',[$v3])['n']);
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_presupuesto_versiones WHERE presupuesto_id=$p AND estado='aprobada'"));
    }

    // ---- plantilla CSV ----
    private function csv(string ...$filas):string {return "mes;tipo;referencia;nombre;base;impuesto\n".implode("\n",$filas)."\n";}
    public function testPlantillaTraeConceptosCifrasActualesYExcluyeLaCuentaDeComisionesDeLosGastos():void
    {
        $v=$this->presupuesto();$this->cifra($v,'servicio:'.$this->s1,3,'100.00','15.00');
        $csv=$this->svc()->plantilla($v);$filas=array_map(fn($l)=>explode(';',$l),array_filter(explode("\n",$csv)));
        self::assertSame(['mes','tipo','referencia','nombre','base','impuesto'],$filas[0]);
        $porClave=[];foreach(array_slice($filas,1) as $f)$porClave[$f[0].'|'.$f[1].'|'.$f[2]]=$f;
        self::assertSame(['100.00','15.00'],[$porClave['3|servicio|'.$this->s1][4],$porClave['3|servicio|'.$this->s1][5]],'trae la cifra actual');
        self::assertSame(['',''],[$porClave['4|servicio|'.$this->s1][4],$porClave['4|servicio|'.$this->s1][5]],'vacío donde no hay presupuesto');
        self::assertArrayHasKey('12|producto|'.$this->p1,$porClave);self::assertArrayHasKey('1|gasto|6.1.05',$porClave);self::assertArrayHasKey('7|comisiones|',$porClave);
        self::assertArrayNotHasKey('1|gasto|6.1.01',$porClave,'la cuenta de comisiones va en su partida, no como gasto');
        // Ida y vuelta: la plantilla descargada, sin tocar, no cambia nada.
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$csv])),null,'no trae cifras nuevas');
    }
    public function testLaPlantillaNeutralizaFormulasYTraeLosConceptosArchivadosQueLaVersionYaPresupuesta():void
    {
        $malo=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('=HYPERLINK(\"http://x\",\"a\")',10.00,30)");
        $v=$this->presupuesto();
        $this->cifra($v,'producto:'.$this->p1,2,'10.00','0');$this->cifra($v,'gasto:'.$this->cuentaOperativo,2,'20.00');
        $this->db->query("UPDATE inventario_productos SET activo=0 WHERE id={$this->p1}");$this->db->query("UPDATE erp_cont_cuentas SET activa=0 WHERE id={$this->cuentaOperativo}");
        $filas=array_map(fn($l)=>explode(';',$l),array_filter(explode("\n",$this->svc()->plantilla($v))));
        $por=[];foreach(array_slice($filas,1) as $f)$por[$f[0].'|'.$f[1].'|'.$f[2]]=$f;
        self::assertSame('10.00',$por['2|producto|'.$this->p1][4],'el producto archivado con cifra sigue en la plantilla');
        self::assertSame('20.00',$por['2|gasto|6.1.05'][4],'la cuenta archivada con cifra sigue en la plantilla');
        $nombre=$por['1|servicio|'.$malo][3];
        self::assertStringStartsWith("'=",$nombre,'una fórmula en el nombre del catálogo se neutraliza');
    }
    public function testLaCuentaDelRolDeComisionesNoSeReasignaConPresupuestosExistentes():void
    {
        $c=new ErpContabilidadService($this->r);
        $c->rol($this->owner,$this->d(['rol'=>'gasto_comisiones','cuenta'=>'6.1.01']));// igual: sin cambio
        $c->rol($this->owner,$this->d(['rol'=>'gasto_comisiones','cuenta'=>'6.1.03']));// sin presupuestos se puede reasignar
        $c->rol($this->owner,$this->d(['rol'=>'gasto_comisiones','cuenta'=>'6.1.01']));
        $v=$this->presupuesto();$this->cifra($v,'comisiones:0',1,'10.00');
        $this->rechazo(fn()=>$c->rol($this->owner,$this->d(['rol'=>'gasto_comisiones','cuenta'=>'6.1.03'])),409,'presupuestos');
        self::assertSame($this->cuentaComisiones,(int)$this->r->uno("SELECT cuenta_id n FROM erp_cont_roles WHERE rol='gasto_comisiones'")['n'],'el rol no cambió');
        $c->rol($this->owner,$this->d(['rol'=>'gasto_comisiones','cuenta'=>'6.1.01']));// la misma cuenta sigue permitida
    }
    public function testVariasDevolucionesParcialesSumanExactamenteElImpuestoDeLaLinea():void
    {
        $srv=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio de centavos',0.07,10)");
        $v=$this->venta('confirmada','2026-05-05 10:00:00');$l=$this->linea($v,$srv,null,6,0,1500,1,7);// total 7 centavos = base 6 + imp. 1
        foreach([[2,'2026-05-06 09:00:00'],[3,'2026-05-07 09:00:00'],[2,'2026-05-08 09:00:00']] as [$monto,$cuando])
            $this->ins("INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,cantidad_entregada_milesimas,reintegrar_stock,motivo,actor_id,creado_en) VALUES($v,$l,300,$monto,0,0,'Parcial',{$this->owner},'$cuando')");
        $r=$this->db->query("SELECT SUM(real_base_centavos) b,SUM(real_impuesto_centavos) i,SUM(devoluciones_impuesto_centavos) di,SUM(ventas_impuesto_centavos) vi FROM bi_presupuesto_real_mes WHERE tipo='servicio' AND objetivo_id=$srv")->fetch_assoc();
        self::assertSame([0,0,1,1],[(int)$r['b'],(int)$r['i'],(int)$r['di'],(int)$r['vi']],'devuelta por completo en tres partes: ni impuesto residual ni base negativa (2+3+2 de 7 con 1 de impuesto)');
        // Parcial real: la mitad exacta se reparte con el acumulado y nunca excede el impuesto de la línea.
        $v2=$this->venta('confirmada','2026-06-05 10:00:00');$l2=$this->linea($v2,$srv,null,10000,0,1500,1500,11500);
        foreach([[3833,'2026-06-06 09:00:00'],[3833,'2026-06-07 09:00:00']] as [$monto,$cuando])
            $this->ins("INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,cantidad_entregada_milesimas,reintegrar_stock,motivo,actor_id,creado_en) VALUES($v2,$l2,333,$monto,0,0,'Parcial',{$this->owner},'$cuando')");
        $di=(int)$this->db->query("SELECT SUM(devoluciones_impuesto_centavos) FROM bi_presupuesto_real_mes WHERE tipo='servicio' AND objetivo_id=$srv AND mes=6")->fetch_row()[0];
        self::assertSame(intdiv(7666*1500*2+11500,2*11500),$di,'impuesto devuelto = redondeo del acumulado (7.666 de 11.500)');
    }
    public function testLaPlantillaDeUnaCuentaArchivadaSeAceptaSinCambiosYNoSeModifica():void
    {
        $v=$this->presupuesto();$this->cifra($v,'gasto:'.$this->cuentaOperativo,2,'20.00');$this->cifra($v,'servicio:'.$this->s1,3,'5.00','0.75');
        $this->db->query("UPDATE erp_cont_cuentas SET activa=0 WHERE id={$this->cuentaOperativo}");
        $csv=$this->svc()->plantilla($v);
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$csv])),null,'no trae cifras nuevas');// ida y vuelta sin tocar: no se rechaza por la cuenta archivada
        $cambiado=preg_replace('/^(3;servicio;'.$this->s1.';[^;]*;)5\.00;0\.75$/m','${1}6.00;0.90',$csv);self::assertNotSame($csv,$cambiado);
        $c=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$cambiado]))['recurso_id'];
        self::assertSame(1,(int)$this->svc()->carga($c)['n_filas'],'cambiar otro concepto con la fila archivada intacta se acepta');
        $tocado=preg_replace('/^(2;gasto;6\.1\.05;[^;]*;)20\.00;$/m','${1}25.00;',$csv);self::assertNotSame($csv,$tocado);
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$tocado])),null,'imputable y activa');
    }
    public function testCargaCsvValidaTodoMuestraVistaPreviaYSoloAlConfirmarAplica():void
    {
        $v=$this->presupuesto();$s=$this->s1;$p=$this->p1;
        $bueno=$this->csv("3;servicio;$s;Corte;100.00;15.00","3;producto;$p;Champú;25.00;0","3;gasto;6.1.05;Operativo;250.00;","3;comisiones;;Comisiones;100.00;","4;servicio;$s;Corte;;");
        $c=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$bueno]))['recurso_id'];
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'),'la vista previa no cambia ninguna cifra');
        $prev=$this->svc()->carga($c);self::assertSame(['previa',4],[$prev['estado'],(int)$prev['n_filas']],'4 filas con cifra; la fila vacía se ignora');
        self::assertSame(['crear'],array_values(array_unique(array_column($prev['filas'],'accion'))));
        $this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c]));
        self::assertSame(4,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'));
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE mes=4"),'una fila sin base ni impuesto no equivale a cero: no crea línea');
        self::assertSame(4,$this->n("SELECT COUNT(*) FROM erp_presupuesto_cambios WHERE version_id=$v AND origen='carga' AND carga_id=$c AND actor_id={$this->finanzas}"));
        self::assertSame('confirmada',$this->svc()->carga($c)['estado']);
        $this->rechazo(fn()=>$this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c])),409);
        $this->sqlFalla("UPDATE erp_presupuesto_cargas SET estado='previa' WHERE id=$c");
        $this->sqlFalla("DELETE FROM erp_presupuesto_cargas WHERE id=$c");
        // Una segunda carga: modifica, pone un cero explícito y se puede descartar.
        $c2=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$this->csv("3;servicio;$s;Corte;110.00;16.50","4;servicio;$s;Corte;0;0","3;comisiones;;Comisiones;100.00;")]))['recurso_id'];
        $f=array_column($this->svc()->carga($c2)['filas'],'accion','mes');self::assertCount(2,$f,'lo que no cambia no entra a la vista previa');
        $this->svc()->descartarCarga($this->finanzas,$this->d(['carga_id'=>$c2]));
        self::assertSame(10000,$this->n("SELECT base_centavos FROM erp_presupuesto_lineas WHERE tipo='servicio' AND mes=3"),'descartar no aplica nada');
        $this->rechazo(fn()=>$this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c2])),409);
        $c3=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$this->csv("3;servicio;$s;Corte;110.00;16.50","4;servicio;$s;Corte;0;0")]))['recurso_id'];
        $this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c3]));
        self::assertSame([11000,1650],[$this->n("SELECT base_centavos FROM erp_presupuesto_lineas WHERE tipo='servicio' AND mes=3"),$this->n("SELECT impuesto_centavos FROM erp_presupuesto_lineas WHERE tipo='servicio' AND mes=3")]);
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE tipo='servicio' AND mes=4 AND base_centavos=0"),'el cero explícito sí crea la línea');
        // Se aplica lo que se vio, no el archivo otra vez; y una versión aprobada entretanto no admite confirmar.
        $c4=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$this->csv("5;comisiones;;Comisiones;10.00;")]))['recurso_id'];
        $this->aprobar($v);
        $this->rechazo(fn()=>$this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c4])),409);
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE mes=5"));
    }
    public function testUnProductoArchivadoConservaSuCifraPeroNoAdmiteCifrasNuevasNiCambios():void
    {
        $v=$this->presupuesto();$p=$this->p1;$this->cifra($v,'producto:'.$p,2,'10.00','0');
        $this->db->query("UPDATE inventario_productos SET activo=0 WHERE id=$p");
        $this->rechazo(fn()=>$this->cifra($v,'producto:'.$p,2,'12.00','0'),null,'archivado');// cambiar
        $this->rechazo(fn()=>$this->cifra($v,'producto:'.$p,5,'12.00','0'),null,'archivado');// nueva
        $csv=$this->svc()->plantilla($v);
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$csv])),null,'no trae cifras nuevas');// ida y vuelta sin tocar
        $tocado=preg_replace('/^(2;producto;'.$p.';[^;]*;)10\.00;0\.00$/m','${1}11.00;0.00',$csv);self::assertNotSame($csv,$tocado);
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$tocado])),null,'archivado');
        $this->cifra($v,'producto:'.$p,2,'','');// quitar siempre se puede
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE tipo='producto'"));
    }
    public function testLosImportesAdmitenHastaElTopeDelEsquemaYNoMas():void
    {
        $v=$this->presupuesto();
        $this->cifra($v,'servicio:'.$this->s1,1,'9999999.99','9999999.99');
        self::assertSame([999999999,999999999],[$this->n("SELECT base_centavos FROM erp_presupuesto_lineas"),$this->n("SELECT impuesto_centavos FROM erp_presupuesto_lineas")],'el tope documentado (9.999.999,99) se puede escribir');
        $this->rechazo(fn()=>$this->cifra($v,'servicio:'.$this->s1,2,'10000000.00','0'));
        $this->cifra($v,'gasto:'.$this->cuentaOperativo,1,'5000000.00');
        $c=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$this->csv("2;gasto;6.1.05;x;9999999.99;")]))['recurso_id'];
        self::assertSame(1,(int)$this->svc()->carga($c)['n_filas'],'la carga CSV admite el mismo tope');
    }
    public function testUnaVistaPreviaObsoletaNoPisaLaEdicionDeOtraPersona():void
    {
        $v=$this->presupuesto();$s=$this->s1;$p=$this->p1;
        $this->cifra($v,'servicio:'.$s,3,'100.00','15.00');$this->cifra($v,'producto:'.$p,3,'25.00','0');
        $csv=$this->csv("3;servicio;$s;x;110.00;16.50","3;producto;$p;x;30.00;0","4;servicio;$s;x;50.00;7.50");
        // Modifica un valor distinto al que se vio.
        $c=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$csv]))['recurso_id'];
        $this->cifra($v,'servicio:'.$s,3,'105.00','15.75');// otra persona edita lo previsualizado
        $this->rechazo(fn()=>$this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c])),409,'cambiaron');
        self::assertSame(10500,$this->n("SELECT base_centavos FROM erp_presupuesto_lineas WHERE tipo='servicio' AND mes=3"),'se conserva la edición de la otra persona');
        self::assertSame(2500,$this->n("SELECT base_centavos FROM erp_presupuesto_lineas WHERE tipo='producto' AND mes=3"),'ni siquiera las filas que sí coincidían');
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE mes=4"));
        self::assertSame('previa',$this->svc()->carga($c)['estado']);
        // Quitada por otra persona.
        $c2=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$this->csv("3;producto;$p;x;30.00;0")]))['recurso_id'];
        $this->cifra($v,'producto:'.$p,3,'','');
        $this->rechazo(fn()=>$this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c2])),409,'cambiaron');
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE tipo='producto'"),'no recrea una línea que otra persona quitó');
        // Creada por otra persona después de previsualizar una cifra nueva.
        $c3=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$this->csv("3;producto;$p;x;40.00;0")]))['recurso_id'];
        $this->cifra($v,'producto:'.$p,3,'7.00','0');
        $this->rechazo(fn()=>$this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c3])),409,'cambiaron');
        // Sin cambios intermedios sí se aplica.
        $this->svc()->descartarCarga($this->finanzas,$this->d(['carga_id'=>$c]));$this->svc()->descartarCarga($this->finanzas,$this->d(['carga_id'=>$c2]));$this->svc()->descartarCarga($this->finanzas,$this->d(['carga_id'=>$c3]));
        $c4=$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$this->csv("3;producto;$p;x;40.00;0")]))['recurso_id'];
        $this->svc()->confirmarCarga($this->finanzas,$this->d(['carga_id'=>$c4]));
        self::assertSame(4000,$this->n("SELECT base_centavos FROM erp_presupuesto_lineas WHERE tipo='producto' AND mes=3"));
    }
    public function testCargaCsvConErroresSeRechazaEnteraConSuLineaYNoGuardaNada():void
    {
        $v=$this->presupuesto();$s=$this->s1;
        $malo=$this->csv("3;servicio;$s;Corte;100.00;15.00","3;servicio;$s;Corte;50.00;7.50","13;servicio;$s;Corte;1.00;0","3;vehiculo;1;x;1.00;0","3;gasto;6.1.01;Comisiones;5.00;","3;gasto;1.1.01;Caja;5.00;","3;gasto;9.9.99;x;5.00;","3;servicio;$s;Corte;10,50;0","3;producto;99999;x;1.00;0","3;servicio;$s;Corte;5.00;","3;comisiones;7;x;1.00;","3;gasto;6.1.05;x;1.00;2.00","1;servicio;$s;Corte;1.00");
        $e=$this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>$malo])),null,'No se guardó nada');
        foreach([3,4,5,6,7,8,9,10,11,12,13,14] as $n)self::assertStringContainsString("Línea $n:",$e->getMessage(),"avisa la línea $n");
        self::assertStringNotContainsString('Línea 2:',$e->getMessage(),'la primera fila es válida');
        self::assertStringContainsString('Comisiones',$e->getMessage());
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_presupuesto_cargas'),'un archivo con errores no deja vista previa');
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'));
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>"mes;tipo\n1;servicio"])),null,'6 columnas');
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>str_repeat('x',ErpPresupuestoService::MAX_BYTES+1)])),null,'excede');
        $this->rechazo(fn()=>$this->svc()->cargar($this->finanzas,$this->d(['version_id'=>$v,'csv'=>"\xff\xfe"])),null,'UTF-8');
        $this->rechazo(fn()=>$this->svc()->cargar($this->gerente,$this->d(['version_id'=>$v,'csv'=>$this->csv("3;servicio;$s;x;1.00;0")])),403);
    }

    // ---- desviación ----
    public function testDesviacionNuncaInventaPorcentajesYCoincideConElRedondeoDeLaBase():void
    {
        self::assertSame(['estado'=>'sin_presupuesto','desviacion'=>null,'pct_centesimas'=>null],ErpPresupuestoService::desviacion(500,null));
        self::assertSame(['estado'=>'presupuesto_cero','desviacion'=>500,'pct_centesimas'=>null],ErpPresupuestoService::desviacion(500,0));
        self::assertSame(['estado'=>'presupuesto_cero','desviacion'=>0,'pct_centesimas'=>null],ErpPresupuestoService::desviacion(0,0),'0 frente a 0 no es 0 %');
        self::assertSame(['estado'=>'con_presupuesto','desviacion'=>-1000,'pct_centesimas'=>-1000],ErpPresupuestoService::desviacion(9000,10000));
        self::assertSame(['estado'=>'con_presupuesto','desviacion'=>0,'pct_centesimas'=>0],ErpPresupuestoService::desviacion(10000,10000));
        foreach([[1,3],[2,3],[1,32],[33,32],[0,7],[50000,8],[7,9],[0,3]] as [$real,$pres]){
            $php=ErpPresupuestoService::desviacion($real,$pres)['pct_centesimas'];
            $sql=$this->n("SELECT ROUND(($real-$pres)*100/$pres,2)*100");
            self::assertSame($sql,$php,"real $real frente a $pres");
        }
    }

    // ---- real frente a presupuesto ----
    /** @return array<string,array> filas del comparativo indexadas por mes|tipo|objetivo */
    private function indexado(array $filas):array {$o=[];foreach($filas as $f)$o[$f['mes'].'|'.$f['tipo'].'|'.$f['objetivo_id']]=$f;return $o;}
    public function testComparativoUsaVentaDevengadaNetaDescuentosDevolucionesGastoDelLibroYComisionesSinDuplicar():void
    {
        $this->hechos();$v=$this->presupuestoAprobado();
        $c=$this->svc()->comparativo($v);$f=$this->indexado($c['filas']);
        $s1m=$f['3|servicio|'.$this->s1];
        self::assertSame([10000,1500,11500],[$s1m['presupuesto_base'],$s1m['presupuesto_impuesto'],$s1m['presupuesto_total']]);
        self::assertSame([9000,1350,10350],[$s1m['real_base'],$s1m['real_impuesto'],$s1m['real_total']],'marzo: venta neta del descuento de 10,00; V3 borrador y V4 anulada no cuentan');
        self::assertSame([-1000,-1000,-1150,-1000],[$s1m['dev_base']['desviacion'],$s1m['dev_base']['pct_centesimas'],$s1m['dev_total']['desviacion'],$s1m['dev_total']['pct_centesimas']]);
        $s1a=$f['4|servicio|'.$this->s1];
        self::assertSame([5000-4500,750-675,575],[$s1a['real_base'],$s1a['real_impuesto'],$s1a['real_total']],'abril: venta de 50,00 menos la devolución de 51,75 (base 45,00 + imp. 6,75) en el mes en que se registró');
        self::assertSame('presupuesto_cero',$s1a['dev_base']['estado']);self::assertSame([500,null],[$s1a['dev_base']['desviacion'],$s1a['dev_base']['pct_centesimas']],'con presupuesto cero hay desviación en dinero y ningún porcentaje');
        $pm=$f['3|producto|'.$this->p1];self::assertSame([2500,2000,-500,-2000],[$pm['presupuesto_base'],$pm['real_base'],$pm['dev_base']['desviacion'],$pm['dev_base']['pct_centesimas']]);
        $sc=$f['4|sin_clasificar|0'];self::assertSame(['sin_presupuesto',1000,null],[$sc['dev_base']['estado'],$sc['real_base'],$sc['presupuesto_base']],'las ventas sin servicio ni producto se informan para que el real cuadre');
        $go=$f['3|gasto|'.$this->cuentaOperativo];self::assertSame([25000,30000,5000,2000],[$go['presupuesto_base'],$go['real_base'],$go['dev_base']['desviacion'],$go['dev_base']['pct_centesimas']]);
        self::assertSame(12000,$f['4|gasto|'.$this->cuentaOperativo]['real_base'],'abril: el asiento reversado resta (120,00 + 50,00 − 50,00)');
        self::assertSame('sin_presupuesto',$f['4|gasto|'.$this->cuentaOperativo]['dev_base']['estado']);
        self::assertSame(4000,$f['3|gasto|'.$this->cuentaCosto]['real_base'],'una cuenta de costo también es gasto devengado');
        self::assertArrayNotHasKey('3|gasto|'.$this->cuenta('1.1.01'),$f,'un asiento a una cuenta de activo no es gasto');
        self::assertArrayNotHasKey('3|gasto|'.$this->cuentaComisiones,$f,'la cuenta de comisiones nunca aparece como gasto');
        $cm=$f['3|comisiones|0'];self::assertSame([10000,8000,-2000,-2000],[$cm['presupuesto_base'],$cm['real_base'],$cm['dev_base']['desviacion'],$cm['dev_base']['pct_centesimas']]);
        // Sin duplicar: gastos totales reales = todo el neto deudor de gasto y costo del libro, una sola vez.
        $libro=$this->n("SELECT SUM(neto_deudor_centavos) FROM bi_libro_lineas WHERE cuenta_tipo IN('gasto','costo')");
        $res=$c['resumen'];
        self::assertSame($libro,$res['gastos_totales']['rb'],'cuentas + comisiones = libro');self::assertSame(30000+4000+12000,$res['gastos']['rb']);self::assertSame(8000,$res['comisiones']['rb']);
        self::assertSame(35000,$res['gastos_totales']['pb']);
        // Resumen de ventas: el real total del año reconcilia con las ventas confirmadas menos devoluciones; el comparable solo cuenta lo presupuestado.
        $ventasTotal=$this->n("SELECT SUM(total_centavos) FROM bi_venta_lineas WHERE fecha_venta BETWEEN '2026-01-01' AND '2026-12-31'")-5175;
        self::assertSame($ventasTotal,$res['ventas']['rt'],'ventas reales con impuestos − devoluciones');
        self::assertSame([12500,11500,'con_presupuesto'],[$res['ventas']['pb'],$res['ventas']['rb_comparable'],$res['ventas']['dev_base']['estado']]);
        self::assertSame(-1000,$res['ventas']['dev_base']['desviacion'],'solo se comparan los conceptos y meses con presupuesto');
        self::assertSame(2025,(int)$this->n("SELECT YEAR(MIN(fecha_venta)) FROM bi_venta_lineas"),'hay una venta de 2025 y no entra al 2026');
        self::assertArrayNotHasKey('12|servicio|'.$this->s1,$f);
        // Consolidado anual por concepto = lo mismo que la vista bi_presupuesto_anual.
        $anual=[];foreach($c['anual'] as $x)$anual[$x['tipo'].'|'.$x['objetivo_id']]=$x;
        $s1=$anual['servicio|'.$this->s1];
        self::assertSame([2,10000,11500,9500,9500,-500,-500],[$s1['meses'],$s1['pb'],$s1['pt'],$s1['rb'],$s1['rb_comparable'],$s1['dev_base']['desviacion'],$s1['dev_base']['pct_centesimas']],'servicio: marzo 100,00 y abril 0 presupuestados; real 90,00 + 5,00');
        self::assertSame(['sin_presupuesto',1000,0],[$anual['sin_clasificar|0']['dev_base']['estado'],$anual['sin_clasificar|0']['rb'],$anual['sin_clasificar|0']['meses']]);
        foreach($this->db->query("SELECT * FROM bi_presupuesto_anual WHERE version_id=$v")->fetch_all(MYSQLI_ASSOC) as $b){
            $x=$anual[$b['tipo'].'|'.$b['objetivo_id']];
            self::assertSame([$x['meses'],$x['rb'],$x['rb_comparable']],[(int)$b['meses_con_presupuesto'],(int)$b['real_base_centavos'],(int)$b['real_base_meses_presupuestados_centavos']],"anual {$b['tipo']} {$b['objetivo_id']}");
            self::assertSame($x['dev_base']['desviacion'],$b['desviacion_base_centavos']===null?null:(int)$b['desviacion_base_centavos']);
        }
        self::assertSame(count($anual),$this->n("SELECT COUNT(*) FROM bi_presupuesto_anual WHERE version_id=$v"));
        // Cobros: bloque aparte, sin presupuesto.
        self::assertSame([],$c['cobros']);
    }
    public function testElCierreDelEjercicioYSuReversoNoSeCuentanComoGasto():void
    {
        $this->hechos();$v=$this->presupuestoAprobado();$c=new ErpContabilidadService($this->r);
        $antes=$this->indexado($this->svc()->comparativo($v)['filas']);
        for($m=1;$m<=11;$m++)$c->cerrarPeriodo($this->owner,$this->d(['anio'=>2026,'mes'=>$m]));
        $cierre=$c->cerrarEjercicio($this->owner,$this->d(['anio'=>2026]))['recurso_id'];
        self::assertGreaterThan(0,$this->n("SELECT COUNT(*) FROM erp_cont_lineas WHERE asiento_id=$cierre AND haber_centavos>0"),'el cierre acredita las cuentas de gasto');
        $desp=$this->indexado($this->svc()->comparativo($v)['filas']);
        self::assertSame(array_keys($antes),array_keys($desp),'el cierre no crea filas de gasto en diciembre');
        foreach($antes as $k=>$f)self::assertSame($f['real_base'],$desp[$k]['real_base'],"el real de $k no cambia con el cierre");
        $c->reversar($this->owner,$this->d(['asiento_id'=>$cierre,'motivo'=>'Reapertura de ensayo','fecha'=>'2026-12-31']));
        $rev=$this->indexado($this->svc()->comparativo($v)['filas']);
        foreach($antes as $k=>$f)self::assertSame($f['real_base'],$rev[$k]['real_base'],"ni su reverso altera $k");
        self::assertSame(54000,(int)$this->n("SELECT COALESCE(SUM(real_base_centavos),0) FROM bi_presupuesto_real_mes WHERE tipo IN('gasto','comisiones')"),'gasto total del año sin cierre: 300+120+40+80 = 540,00');
    }
    public function testUnaRevisionCopiaLaAprobadaAunqueUnaCuentaSeHayaArchivado():void
    {
        $v=$this->presupuestoAprobado();$p=(int)$this->r->uno('SELECT presupuesto_id n FROM erp_presupuesto_versiones WHERE id=?',[$v])['n'];
        $this->db->query("UPDATE erp_cont_cuentas SET activa=0 WHERE id={$this->cuentaOperativo}");
        $rev=$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>'Cuenta archivada']))['recurso_id'];
        self::assertSame($this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE version_id=$v"),$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE version_id=$rev"),'la revisión conserva la línea de la cuenta archivada');
        $this->rechazo(fn()=>$this->cifra($rev,'gasto:'.$this->cuentaOperativo,5,'10.00'),null,'imputable y activa');
        $this->cifra($rev,'gasto:'.$this->cuentaOperativo,3,'','');
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM erp_presupuesto_lineas WHERE version_id=$rev AND tipo='gasto'"),'se puede quitar la línea de la cuenta archivada');
    }
    public function testElComparativoDeUnBorradorUsaLoMismoRealYLaVistaBiCoincideConElServicio():void
    {
        $this->hechos();$v=$this->presupuestoAprobado();$p=(int)$this->r->uno('SELECT presupuesto_id n FROM erp_presupuesto_versiones WHERE id=?',[$v])['n'];
        $rev=$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>'Más ambicioso']))['recurso_id'];
        $this->cifra($rev,'servicio:'.$this->s1,3,'200.00','30.00');
        $aprob=$this->indexado($this->svc()->comparativo($v)['filas']);$borr=$this->indexado($this->svc()->comparativo($rev)['filas']);
        self::assertSame(20000,$borr['3|servicio|'.$this->s1]['presupuesto_base']);self::assertSame(10000,$aprob['3|servicio|'.$this->s1]['presupuesto_base']);
        foreach($aprob as $k=>$fila)self::assertSame([$fila['real_base'],$fila['real_impuesto'],$fila['real_total']],[$borr[$k]['real_base'],$borr[$k]['real_impuesto'],$borr[$k]['real_total']],"el real de $k no depende de la versión");
        // La vista BI usa la versión vigente (la aprobada, no la revisión abierta) y coincide con el servicio fila por fila.
        $vista=[];foreach($this->db->query("SELECT * FROM bi_presupuesto_vs_real WHERE version_id=$v")->fetch_all(MYSQLI_ASSOC) as $x)$vista[$x['mes'].'|'.$x['tipo'].'|'.$x['objetivo_id']]=$x;
        $ka=array_keys($aprob);$kv=array_keys($vista);sort($ka);sort($kv);self::assertSame($ka,$kv,'mismas filas (mes, tipo y concepto)');
        foreach($aprob as $k=>$fila){
            $x=$vista[$k];
            self::assertSame($fila['presupuesto_base'],$x['presupuesto_base_centavos']===null?null:(int)$x['presupuesto_base_centavos'],"presupuesto base $k");
            self::assertSame([$fila['real_base'],$fila['real_impuesto'],$fila['real_total']],[(int)$x['real_base_centavos'],(int)$x['real_impuesto_centavos'],(int)$x['real_total_centavos']],"real $k");
            self::assertSame($fila['dev_base']['estado'],$x['estado_presupuesto'],"estado $k");
            self::assertSame($fila['dev_base']['desviacion'],$x['desviacion_base_centavos']===null?null:(int)$x['desviacion_base_centavos'],"desviación base $k");
            self::assertSame($fila['dev_total']['desviacion'],$x['desviacion_total_centavos']===null?null:(int)$x['desviacion_total_centavos'],"desviación total $k");
            self::assertSame($fila['dev_base']['pct_centesimas'],$x['desviacion_base_pct']===null?null:(int)round((float)$x['desviacion_base_pct']*100),"% base $k");
            self::assertSame($fila['dev_total']['pct_centesimas'],$x['desviacion_total_pct']===null?null:(int)round((float)$x['desviacion_total_pct']*100),"% total $k");
        }
        self::assertSame('presupuesto_cero',$vista['4|servicio|'.$this->s1]['estado_presupuesto']);self::assertNull($vista['4|servicio|'.$this->s1]['desviacion_base_pct'],'sin porcentaje con presupuesto cero');
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_presupuesto_vs_real WHERE version_id=$rev"),'la revisión abierta no sale a BI');
        // Consolidado anual: presupuesto de los meses presupuestados; la desviación compara solo esos meses.
        $anual=$this->db->query("SELECT * FROM bi_presupuesto_anual WHERE tipo='servicio' AND servicio_id={$this->s1}")->fetch_assoc();
        self::assertSame([2,10000,9500,9500],[(int)$anual['meses_con_presupuesto'],(int)$anual['presupuesto_base_centavos'],(int)$anual['real_base_meses_presupuestados_centavos'],(int)$anual['real_base_centavos']]);
        self::assertSame(-500,(int)$anual['desviacion_base_centavos']);self::assertSame(-5.0,(float)$anual['desviacion_base_pct']);
        $sin=$this->db->query("SELECT * FROM bi_presupuesto_anual WHERE tipo='sin_clasificar'")->fetch_assoc();
        self::assertSame([0,null,null],[(int)$sin['meses_con_presupuesto'],$sin['presupuesto_base_centavos'],$sin['desviacion_base_centavos']],'sin presupuesto: ni cifras ni desviación inventadas');
        self::assertSame(1000,(int)$sin['real_base_centavos']);
    }

    /** Con los servicios reales (venta con IVA, cobro en caja y devolución parcial) lo real del presupuesto cuadra con el dinero; el año sale del reloj de la aplicación. */
    public function testConLosServiciosRealesLaVentaNetaYLosCobrosDeCajaCuadranYLosCobrosNoTienenPresupuesto():void
    {
        $anio=(int)substr(ErpRepository::ahora(),0,4);
        $m=new \Services\ErpMaestrosService($this->r);
        $producto=$this->ins("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('PRS','Producto con IVA','unidad',100000)");
        $cat=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$producto,'precio'=>'20','impuesto'=>'15','tipo'=>'venta']))['recurso_id'];
        (new \Services\ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']));
        $ventas=new \Services\ErpVentaService($this->r);
        $venta=$ventas->crear($this->owner,$this->d([]))['recurso_id'];
        $ventas->agregar($this->owner,$this->d(['venta_id'=>$venta,'catalogo_id'=>$cat,'cantidad'=>'2']));
        $ventas->confirmar($this->owner,$this->d(['venta_id'=>$venta,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$venta])['version']]));
        (new \Services\ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$venta,'monto'=>'46.00','metodo'=>'efectivo','recibido'=>'46.00']));
        $linea=(int)$this->r->uno('SELECT id n FROM erp_venta_lineas WHERE venta_id=?',[$venta])['n'];
        (new \Services\ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$venta,'linea_id'=>$linea,'cantidad'=>'1','motivo'=>'Devolución de ensayo','reintegrar_stock'=>'0']));
        $v=$this->presupuesto('base',$anio);$this->cifra($v,'producto:'.$producto,(int)substr(ErpRepository::ahora(),5,2),'40.00','6.00');$this->aprobar($v);
        $c=$this->svc()->comparativo($v);
        $fila=array_values(array_filter($c['filas'],fn($f)=>$f['tipo']==='producto'&&$f['objetivo_id']===$producto));
        self::assertCount(1,$fila);
        self::assertSame([2000,300,2300],[$fila[0]['real_base'],$fila[0]['real_impuesto'],$fila[0]['real_total']],'2 × 20,00 + 15 % menos la devolución de 1 unidad (23,00 con 3,00 de IVA)');
        self::assertSame([-2000,-50],[$fila[0]['dev_base']['desviacion'],(int)round($fila[0]['dev_base']['pct_centesimas']/100)],'presupuesto 40,00 frente a 20,00 reales');
        $cobros=$c['cobros'];self::assertCount(1,$cobros);
        self::assertSame([4600,2300,2300],[(int)$cobros[0]['cobros_centavos'],(int)$cobros[0]['devoluciones_de_cobro_centavos'],(int)$cobros[0]['cobros_netos_centavos']],'cobros de caja con impuestos, menos lo devuelto');
        self::assertSame($fila[0]['real_total'],(int)$cobros[0]['cobros_netos_centavos'],'aquí lo cobrado neto = lo vendido neto con impuestos');
        $vista=$this->db->query("SELECT * FROM bi_presupuesto_cobros_mes WHERE anio=$anio")->fetch_assoc();
        self::assertSame([$anio*100+(int)substr(ErpRepository::ahora(),5,2),2300],[(int)$vista['anio_mes'],(int)$vista['cobros_netos_centavos']]);
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bi_presupuesto_cobros_mes' AND column_name LIKE 'presupuesto%'"),'los cobros no tienen presupuesto ni desviación en esta versión');
    }

    // ---- vistas BI ----
    public function testVistasBiSolo_Lectura_SinPersonalesSinFechasYSoloAprobadasSalenAlBi():void
    {
        $this->hechos();$v1=$this->presupuestoAprobado();$p=(int)$this->r->uno('SELECT presupuesto_id n FROM erp_presupuesto_versiones WHERE id=?',[$v1])['n'];
        $borr=$this->presupuesto('optimista');$this->cifra($borr,'comisiones:0',1,'1.00');
        $rev=$this->svc()->revisar($this->finanzas,$this->d(['presupuesto_id'=>$p,'motivo'=>'Cambio']))['recurso_id'];$this->cifra($rev,'comisiones:0',3,'120.00');$this->aprobar($rev);
        // Un presupuesto a largo plazo (futuro): el calendario de BI debe llegar hasta su último día; si no, sus meses quedarían fuera de la relación.
        $lp=$this->presupuesto('largo plazo',2031);$this->cifra($lp,'comisiones:0',12,'5.00');$this->aprobar($lp);
        self::assertSame('2031-12-31',$this->db->query('SELECT MAX(fecha) FROM bi_dim_fecha')->fetch_row()[0],'el calendario cubre el año presupuestado completo');
        $lineas=$this->db->query("SELECT version_id,version_estado,es_vigente FROM bi_presupuesto_lineas GROUP BY version_id,version_estado,es_vigente ORDER BY version_id")->fetch_all(MYSQLI_ASSOC);
        self::assertSame([[$v1,'reemplazada',0],[$rev,'aprobada',1],[$lp,'aprobada',1]],array_map(fn($x)=>[(int)$x['version_id'],$x['version_estado'],(int)$x['es_vigente']],$lineas),'la reemplazada se conserva (es_vigente 0); el borrador no sale');
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_presupuesto_lineas WHERE version_id=$borr"));
        $prohibidas=['email','correo','telefono','identificacion','apellido','sueldo','salario','motivo','actor','creado_por','aprobado_por','usuario','colaborador','profesional'];
        foreach(self::VISTAS as $vista){
            self::assertSame(1,$this->n("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$vista' AND security_type='DEFINER'"));
            $cols=$this->db->query("SELECT column_name c,data_type t FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$vista'")->fetch_all(MYSQLI_ASSOC);
            foreach($cols as $c){
                foreach($prohibidas as $x)self::assertStringNotContainsString($x,strtolower($c['c']),"$vista.{$c['c']} no debe exponer datos personales");
                self::assertNotContains(strtolower($c['t']),['date','datetime','timestamp'],"$vista.{$c['c']}: sin columnas de fecha; el mes se une con bi_dim_fecha.anio_mes");
            }
        }
        $texto=json_encode($this->db->query("SELECT * FROM bi_presupuesto_lineas")->fetch_all(MYSQLI_ASSOC));
        foreach(['Privada','@example.test','Finanzas','Propietario'] as $s)self::assertStringNotContainsString($s,$texto);
        // Solo lectura.
        $antes=[$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'),$this->n('SELECT COUNT(*) FROM erp_presupuesto_versiones'),$this->n('SELECT COUNT(*) FROM erp_venta_lineas')];
        mysqli_report(MYSQLI_REPORT_OFF);
        foreach(self::VISTAS as $vista){self::assertFalse($this->db->query("UPDATE $vista SET anio=1999"),"UPDATE sobre $vista");self::assertFalse($this->db->query("DELETE FROM $vista"),"DELETE sobre $vista");}
        self::assertSame($antes,[$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'),$this->n('SELECT COUNT(*) FROM erp_presupuesto_versiones'),$this->n('SELECT COUNT(*) FROM erp_venta_lineas')]);
        // anio_mes enlaza con la dimensión de fecha.
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_presupuesto_lineas l LEFT JOIN (SELECT DISTINCT anio_mes FROM bi_dim_fecha) d ON d.anio_mes=l.anio_mes WHERE d.anio_mes IS NULL"),'todo anio_mes presupuestado existe en el calendario');
        // Acceso: el grupo comercial lee las vistas; el laboral no.
        $bd=BaseDatosAislada::nombre($this->db);
        $crear=function(array $vistas)use($bd){
            $u='bi_p_'.bin2hex(random_bytes(4));$c='Bi'.bin2hex(random_bytes(6));
            $this->db->query("CREATE USER '$u'@'%' IDENTIFIED BY '$c'");foreach($vistas as $x)$this->db->query("GRANT SELECT ON `$bd`.`$x` TO '$u'@'%'");
            mysqli_report(MYSQLI_REPORT_OFF);$bi=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',$u,$c,$bd,(int)(getenv('DB_PORT')?:3306));self::assertSame(0,$bi->connect_errno);return [$u,$bi];
        };
        [$uCom,$com]=$crear(array_merge(self::VISTAS,['bi_dim_fecha']));[$uLab,$lab]=$crear(['bi_rh_asistencia','bi_dim_fecha']);
        try{
            foreach(self::VISTAS as $vista){self::assertNotFalse($com->query("SELECT COUNT(*) FROM `$vista`"),"$vista legible por el grupo comercial: ".$com->error);self::assertFalse($lab->query("SELECT 1 FROM `$vista` LIMIT 1"),"el grupo laboral no lee $vista");}
            foreach(['erp_presupuestos','erp_presupuesto_lineas','erp_presupuesto_cambios','erp_ventas','erp_cont_asientos'] as $t){self::assertFalse($com->query("SELECT 1 FROM `$t` LIMIT 1"),"no lee la tabla $t");}
        }finally{mysqli_report(MYSQLI_REPORT_OFF);foreach([$uCom,$uLab] as $u)$this->db->query("DROP USER IF EXISTS '$u'@'%'");}
    }

    // ---- interfaz ----
    public function testLosComponentesDeLaVentaExplicanLaCifraNetaQueSeCompara():void
    {
        $this->hechos();$v=$this->presupuestoAprobado();$c=$this->svc()->comparativo($v);$f=$this->indexado($c['filas']);
        $m=$f['3|servicio|'.$this->s1]['componentes'];
        self::assertSame([10000,1000,9000,0,9000],[$m['bruta'],$m['descuentos'],$m['venta'],$m['devoluciones'],$m['neta']],'marzo: bruta 100,00 − descuento 10,00 = venta 90,00; sin devoluciones');
        $a=$f['4|servicio|'.$this->s1]['componentes'];
        self::assertSame([5000,0,5000,4500,500],[$a['bruta'],$a['descuentos'],$a['venta'],$a['devoluciones'],$a['neta']],'abril: venta 50,00 − devolución de base 45,00 (registrada en abril) = 5,00');
        foreach($c['filas'] as $x){
            if($x['sentido']==='gasto'){self::assertNull($x['componentes'],'los gastos y comisiones no tienen componentes de venta');continue;}
            $k=$x['componentes'];
            self::assertSame($k['venta'],$k['bruta']-$k['descuentos'],"{$x['mes']}|{$x['tipo']}: bruta − descuentos = venta");
            self::assertSame($x['real_base'],$k['venta']-$k['devoluciones']-$k['anulaciones'],"{$x['mes']}|{$x['tipo']}: venta − devoluciones − anulaciones = lo que se compara");
            self::assertSame(0,$k['anulaciones'],'el estado actual no tiene componente de anulaciones: las ventas anuladas simplemente no cuentan');
            self::assertSame($x['real_base'],$k['neta']);
        }
        $r=$c['resumen']['ventas']['componentes'];
        self::assertSame([18000,1000,17000,4500,12500],[$r['bruta'],$r['descuentos'],$r['venta'],$r['devoluciones'],$r['neta']],'año 2026: 180,00 − 10,00 = 170,00 − 45,00 = 125,00 (la venta de 2025 no entra)');
        self::assertSame($c['resumen']['ventas']['rb'],$r['neta']);
        // La vista BI trae la misma bruta; en gastos y comisiones es 0.
        self::assertSame(18000,$this->n("SELECT SUM(ventas_bruta_base_centavos) FROM bi_presupuesto_real_mes WHERE anio=2026 AND tipo IN('servicio','producto','sin_clasificar')"));
        self::assertSame(0,$this->n("SELECT SUM(ventas_bruta_base_centavos) FROM bi_presupuesto_real_mes WHERE tipo IN('gasto','comisiones')"));
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_presupuesto_real_mes WHERE ventas_bruta_base_centavos-descuentos_informativo_centavos<>ventas_base_centavos"),'bruta − descuentos = venta en cada fila de la vista');
    }
    public function testAnularUnaVentaCambiaSuMesHistoricoYUnaDevolucionPosteriorCaeEnSuPropioMes():void
    {
        $this->hechos();$v=$this->presupuestoAprobado();
        $base=$this->indexado($this->svc()->comparativo($v)['filas']);
        $vta=$this->venta('confirmada','2026-03-20 10:00:00');$lin=$this->linea($vta,$this->s1,null,1000,0,0,0,1000);
        $con=$this->indexado($this->svc()->comparativo($v)['filas']);
        self::assertSame($base['3|servicio|'.$this->s1]['real_base']+1000,$con['3|servicio|'.$this->s1]['real_base'],'la venta confirmada de marzo suma en marzo');
        // Una devolución posterior NO reescribe marzo: cae en el mes en que se registra.
        $this->ins("INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,cantidad_entregada_milesimas,reintegrar_stock,motivo,actor_id,creado_en) VALUES($vta,$lin,1000,1000,0,0,'Devolución tardía',{$this->owner},'2026-05-10 09:00:00')");
        $dev=$this->indexado($this->svc()->comparativo($v)['filas']);
        self::assertSame($con['3|servicio|'.$this->s1]['real_base'],$dev['3|servicio|'.$this->s1]['real_base'],'marzo conserva la venta aunque se devuelva en mayo');
        self::assertSame(-1000,$dev['5|servicio|'.$this->s1]['real_base'],'mayo muestra la devolución como real negativo (sin venta propia en mayo)');
        // Anular SÍ cambia el mes histórico: la venta anulada deja de contar en marzo y su devolución en mayo (no hay fecha de anulación en la venta; queda en erp_eventos).
        (new \Services\ErpVentaService($this->r))->anular($this->owner,$this->d(['venta_id'=>$vta,'motivo'=>'Anulación de ensayo']));
        $anu=$this->indexado($this->svc()->comparativo($v)['filas']);
        self::assertSame($base['3|servicio|'.$this->s1]['real_base'],$anu['3|servicio|'.$this->s1]['real_base'],'tras anular, marzo vuelve a su cifra anterior: el comparativo histórico cambia');
        self::assertArrayNotHasKey('5|servicio|'.$this->s1,$anu,'y la devolución de mayo desaparece con la venta');
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_eventos WHERE tipo='venta_anulada' AND entidad='venta' AND entidad_id=$vta"),'la anulación queda en el registro de eventos con su fecha y actor');
    }
    // ---- comparativo por eventos con fecha de corte (066) ----
    private function devolucion(int $venta,int $linea,int $monto,string $cuando):void
    {
        $this->ins("INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,cantidad_entregada_milesimas,reintegrar_stock,motivo,actor_id,creado_en) VALUES($venta,$linea,500,$monto,0,0,'Devolución de ensayo',{$this->owner},'$cuando')");
    }
    private function anulada(int $venta,string $cuando):void
    {
        $this->db->query("UPDATE erp_ventas SET estado='anulada' WHERE id=$venta");
        $this->ins("INSERT INTO erp_eventos(tipo,entidad,entidad_id,actor_id,datos_json,creado_en) VALUES('venta_anulada','venta',$venta,{$this->owner},'{}','$cuando')");
    }
    /** Ventas del mismo año con anulaciones posteriores (servicio S1, sin la venta de otro año): ver la tabla de montos en cada prueba. */
    private function ventasConAnulaciones():void
    {
        // VA: 100,00 − 10,00 desc. + 15 %: base 90,00, imp. 13,50. Devuelta en dos partes (5.175 y 5.175) y anulada después (neto 0 al anular).
        $va=$this->venta('confirmada','2026-01-10 10:00:00');$la=$this->linea($va,$this->s1,null,10000,1000,1500,1350,10350);
        $this->devolucion($va,$la,5175,'2026-02-05 11:00:00');$this->devolucion($va,$la,5175,'2026-03-07 11:00:00');$this->anulada($va,'2026-04-02 09:00:00');
        // VB: 50,00 sin impuesto, devolución parcial de 20,00 el 10/02 y anulada el 03/05 (queda por anular 30,00).
        $vb=$this->venta('confirmada','2026-01-20 10:00:00');$lb=$this->linea($vb,$this->s1,null,5000,0,0,0,5000);
        $this->devolucion($vb,$lb,2000,'2026-02-10 11:00:00');$this->anulada($vb,'2026-05-03 09:00:00');
        // VC: 40,00, nunca anulada (control).
        $vc=$this->venta('confirmada','2026-02-12 10:00:00');$this->linea($vc,$this->s1,null,4000,0,0,0,4000);
        // VD: producto P1, confirmada y anulada el mismo mes.
        $vd=$this->venta('confirmada','2026-03-03 10:00:00');$this->linea($vd,null,$this->p1,1000,0,0,0,1000);$this->anulada($vd,'2026-03-20 09:00:00');
        // VF: anulada SIN evento de anulación (dato antiguo): se trata como anulada en el instante de su confirmación.
        $vf=$this->venta('anulada','2026-03-05 10:00:00');$this->linea($vf,$this->s1,null,2500,0,0,0,2500);
        // VH: anulada el 5/04 y con una devolución (inconsistente) el 9/04, posterior a la anulación: se ignora.
        $vh=$this->venta('confirmada','2026-04-01 10:00:00');$lh=$this->linea($vh,$this->s1,null,1000,0,0,0,1000);$this->anulada($vh,'2026-04-05 09:00:00');$this->devolucion($vh,$lh,400,'2026-04-09 11:00:00');
    }
    /** Suma por mes de un destino, sin los meses en cero (hay filas en cero porque el presupuesto tiene cifras en meses sin movimiento). */
    private function sumaMeses(array $filas,string $tipo,int $obj,string $campo='real_base'):array
    {
        $r=[];foreach($filas as $f)if($f['tipo']===$tipo&&(int)$f['objetivo_id']===$obj)$r[$f['mes']]=($r[$f['mes']]??0)+$f[$campo];ksort($r);return array_filter($r,static fn($x)=>$x!==0);
    }
    public function testElCriterioPorEventosPreservaLoOcurridoAntesDeUnaAnulacionPosteriorYElEstadoActualLoReescribe():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $ev=$this->svc()->comparativo($v,'eventos');$ac=$this->svc()->comparativo($v);
        self::assertSame(['eventos',null],[$ev['criterio'],$ev['corte']]);self::assertSame(['estado_actual',null],[$ac['criterio'],$ac['corte']],'el criterio por defecto sigue siendo el estado actual y queda identificado');
        // S1 por eventos: cada hecho en su mes (ver comentarios de ventasConAnulaciones).
        self::assertSame([1=>14000,2=>-2500,3=>-4500,5=>-3000],$this->sumaMeses($ev['filas'],'servicio',$this->s1),'enero conserva la venta aunque se anule después; la anulación cae en el mes en que ocurrió');
        self::assertSame([1=>1350,2=>-675,3=>-675],$this->sumaMeses($ev['filas'],'servicio',$this->s1,'real_impuesto'),'el impuesto de las devoluciones parciales y totales suma exactamente el de la línea');
        // El estado actual reescribe: solo queda la venta que nunca se anuló.
        self::assertSame([2=>4000],$this->sumaMeses($ac['filas'],'servicio',$this->s1),'por estado actual las ventas anuladas desaparecen de enero y las devoluciones de febrero y marzo');
        // Sin duplicar: sumados todos los eventos del año, coincide con el estado actual.
        self::assertSame(array_sum($this->sumaMeses($ac['filas'],'servicio',$this->s1)),array_sum($this->sumaMeses($ev['filas'],'servicio',$this->s1)),'total anual igual en los dos criterios (misma moneda, sin duplicar importes)');
        self::assertSame(0,array_sum($this->sumaMeses($ev['filas'],'servicio',$this->s1,'real_impuesto')));
        $p1=$this->indexado($ev['filas'])['3|producto|'.$this->p1];
        self::assertSame([1000,1000,0],[$p1['componentes']['venta'],$p1['componentes']['anulaciones'],$p1['real_base']],'venta y anulación del mismo mes: se ven las dos y el neto es 0');
        self::assertSame(0,$this->indexado($ac['filas'])['3|producto|'.$this->p1]['real_base'],'por estado actual esa venta anulada no existe (la fila es solo por el presupuesto)');
        // Cadena de componentes en cada fila: bruta − descuentos = venta; venta − devoluciones − anulaciones = neta.
        foreach($ev['filas'] as $f){
            if($f['componentes']===null)continue;$k=$f['componentes'];
            self::assertSame($k['venta'],$k['bruta']-$k['descuentos'],"{$f['mes']}|{$f['tipo']}: bruta − descuentos");
            self::assertSame($f['real_base'],$k['venta']-$k['devoluciones']-$k['anulaciones'],"{$f['mes']}|{$f['tipo']}: venta − devoluciones − anulaciones");
        }
        $ene=$this->indexado($ev['filas'])['1|servicio|'.$this->s1]['componentes'];
        self::assertSame([15000,1000,14000,0,0,14000],[$ene['bruta'],$ene['descuentos'],$ene['venta'],$ene['devoluciones'],$ene['anulaciones'],$ene['neta']]);
        $abr=$this->indexado($ev['filas'])['5|servicio|'.$this->s1]['componentes'];
        self::assertSame([0,0,0,0,3000,-3000],[$abr['bruta'],$abr['descuentos'],$abr['venta'],$abr['devoluciones'],$abr['anulaciones'],$abr['neta']],'mayo: la anulación resta lo que quedaba de VB (50,00 − 20,00)');
        $r=$ev['resumen']['ventas']['componentes'];
        self::assertSame($r['venta']-$r['devoluciones']-$r['anulaciones'],$r['neta']);
        // La anulación sin evento (dato antiguo) se marca y no queda como ingreso.
        self::assertSame(1,$this->n("SELECT COALESCE(SUM(anulaciones_sin_evento),0) FROM bi_presupuesto_real_eventos WHERE fecha_evento='2026-03-05'"));
        self::assertSame(0,$this->n("SELECT COALESCE(SUM(anulaciones_sin_evento),0) FROM bi_presupuesto_real_eventos WHERE fecha_evento<>'2026-03-05'"));
    }
    public function testLaFechaDeCorteMuestraLoQueSeSabiaALaFechaIncluyendoElDiaDeLaAnulacion():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $acum=fn(string $corte)=>array_sum($this->sumaMeses($this->svc()->comparativo($v,'eventos',$corte)['filas'],'servicio',$this->s1));
        self::assertSame(14000,$acum('2026-01-31'));
        self::assertSame(8000,$acum('2026-04-01'),'antes de las anulaciones de VA (2/04) y VH (5/04): VH ya vendió 10,00');
        self::assertSame(7000,$acum('2026-05-02'),'el día anterior a la anulación de VB sigue contando sus 30,00 netos (VC 40,00 + VB 30,00)');
        self::assertSame(4000,$acum('2026-05-03'),'el corte incluye el día de la anulación');
        self::assertSame(4000,$acum('2026-12-31'));
        $c=$this->svc()->comparativo($v,'eventos','2026-05-02');self::assertSame(['eventos','2026-05-02'],[$c['criterio'],$c['corte']],'el resultado identifica el criterio y el corte');
        self::assertSame(7000,$c['resumen']['ventas']['rb'],'el resumen usa el mismo corte');
        // Entradas no válidas.
        foreach([['otro',null],['estado_actual','2026-05-02'],['eventos','2026-02-30'],['eventos','ayer'],['eventos','2026-5-2']] as [$cr,$co]){
            $e=$this->rechazo(fn()=>$this->svc()->comparativo($v,$cr,$co),422);self::assertInstanceOf(ErpException::class,$e);
        }
    }
    public function testLaFechaDeCorteTambienAplicaAlGastoDevengadoDelLibro():void
    {
        $this->hechos();$v=$this->presupuestoAprobado();
        $f=$this->indexado($this->svc()->comparativo($v,'eventos','2026-03-16')['filas']);
        self::assertSame(30000,$f['3|gasto|'.$this->cuentaOperativo]['real_base'],'solo el asiento del 15/03');
        self::assertSame(0,$f['3|comisiones|0']['real_base'],'las comisiones se asientan el 20/03: aún no (la fila existe solo por su presupuesto)');
        self::assertSame(0,$f['4|gasto|'.$this->cuentaOperativo]['real_base']??0,'abril aún no');
        $todo=$this->indexado($this->svc()->comparativo($v,'eventos')['filas']);$act=$this->indexado($this->svc()->comparativo($v)['filas']);
        foreach(['3|gasto|'.$this->cuentaOperativo,'4|gasto|'.$this->cuentaOperativo,'3|comisiones|0','3|gasto|'.$this->cuentaCosto] as $k)self::assertSame($act[$k]['real_base'],$todo[$k]['real_base'],"$k igual sin corte");
    }
    public function testUnaVentaDeOtroAnioAnuladaEnElAnioSiguienteResta_ElAcumuladoDeLosDosAniosNoSeDuplica():void
    {
        $ve=$this->venta('confirmada','2025-12-28 10:00:00');$le=$this->linea($ve,$this->s1,null,7700,0,0,0,7700);
        $this->devolucion($ve,$le,3000,'2026-01-08 11:00:00');$this->anulada($ve,'2026-02-01 09:00:00');
        $v=$this->presupuestoAprobado();$ev=$this->svc()->comparativo($v,'eventos');$ac=$this->svc()->comparativo($v);
        self::assertSame([1=>-3000,2=>-4700],$this->sumaMeses($ev['filas'],'servicio',$this->s1),'2026 muestra la devolución y la anulación en sus meses; la venta quedó en 2025');
        self::assertSame([],$this->sumaMeses($ac['filas'],'servicio',$this->s1),'el estado actual no muestra nada de esa venta en 2026 (anulada)');
        self::assertSame(7700,$this->n("SELECT SUM(real_base_centavos) FROM bi_presupuesto_real_eventos WHERE anio=2025 AND tipo='servicio'"));
        self::assertSame(0,$this->n("SELECT SUM(real_base_centavos) FROM bi_presupuesto_real_eventos WHERE tipo='servicio'"),'entre los dos años la venta anulada netea 0: ni se duplica ni se pierde');
    }
    public function testUnaAnulacionRealRegistraSuEventoYLaVistaLaUbicaEnEseDia():void
    {
        $vta=$this->venta('confirmada','2026-03-20 10:00:00');$this->linea($vta,$this->s1,null,1000,0,0,0,1000);
        (new \Services\ErpVentaService($this->r))->anular($this->owner,$this->d(['venta_id'=>$vta,'motivo'=>'Anulación de ensayo']));
        $dia=$this->db->query("SELECT DATE(creado_en) FROM erp_eventos WHERE tipo='venta_anulada' AND entidad='venta' AND entidad_id=$vta")->fetch_row()[0];
        self::assertNotNull($dia,'la anulación deja su evento con fecha');
        $f=$this->db->query("SELECT fecha_evento,anulaciones_base_centavos,anulaciones_sin_evento FROM bi_presupuesto_real_eventos WHERE anulaciones_base_centavos>0")->fetch_assoc();
        self::assertSame([$dia,'1000','0'],[$f['fecha_evento'],$f['anulaciones_base_centavos'],$f['anulaciones_sin_evento']],'la anulación cae en el día del evento y no es una estimación');
        self::assertSame(0,$this->n("SELECT SUM(real_base_centavos) FROM bi_presupuesto_real_eventos"));
        $c=$this->db->query("SELECT estado,filas_sin_dia FROM bi_control_calendario WHERE vista='bi_presupuesto_real_eventos' AND columna='fecha_evento'")->fetch_assoc();
        self::assertSame(['ok','0'],[$c['estado'],$c['filas_sin_dia']],'el control del calendario vigila la fecha de la vista de eventos');
    }

    private function sesion(int $usuario,string $portal='equipo'):void
    {
        if(session_status()===PHP_SESSION_NONE)session_start();$ctx=(new \Services\AuthorizationService($this->db))->contexto($usuario);
        $_SESSION=['login'=>true,'id'=>$usuario,'portal'=>$portal,'version_sesion'=>(int)$ctx['version_sesion'],'csrf_token'=>'csrf-pre'];$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['REQUEST_URI']='/gestion/presupuestos';
    }
    private function pagina(array $get):string
    {
        $_GET=$get;$_SERVER['REQUEST_METHOD']='GET';ob_start();try{\Controllers\ErpController::presupuestos(new \MVC\Router());return (string)ob_get_contents();}finally{ob_end_clean();}
    }
    private function post(array $datos):int
    {
        $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/gestion/accion';$_POST=$datos+['idem_key'=>R5CajaFixture::clave(),'csrf_token'=>'csrf-pre'];
        $e=200;ob_start();try{\Controllers\ErpController::accion();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}return $e;
    }
    public function testPantallasFormulariosYPermisosPorRol():void
    {
        $this->hechos();
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->sesion($this->finanzas);
            $h=$this->pagina([]);self::assertStringContainsString('Presupuestos',$h);self::assertStringContainsString('Nuevo presupuesto',$h);
            self::assertSame(303,$this->post(['accion'=>'presupuesto_crear','anio'=>'2026','escenario'=>'base']));
            $v=(int)$this->r->uno('SELECT id FROM erp_presupuesto_versiones')['id'];
            self::assertSame(303,$this->post(['accion'=>'presupuesto_linea','version_id'=>(string)$v,'concepto'=>'servicio:'.$this->s1,'mes'=>'3','base'=>'100.00','impuesto'=>'15.00']));
            self::assertSame(1,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'));
            $h=$this->pagina(['version'=>(string)$v]);
            foreach(['versión 1','Guardar cifra','Descargar plantilla','Validar y ver vista previa','Corte de ensayo','Fuente y fecha de cada métrica','Presupuesto frente a lo real','Historial de cambios'] as $t)self::assertStringContainsString($t,$h,$t);
            self::assertStringNotContainsString('Aprobar esta versión',$h,'finanzas prepara pero no aprueba');
            // Criterio de lo real visible e identificable: estado actual por defecto; por eventos con corte opcional; una entrada no válida se rechaza.
            self::assertStringContainsString('Criterio: estado actual, recalculado hoy',$h);
            $h2=$this->pagina(['version'=>(string)$v,'criterio'=>'eventos','corte'=>'2026-05-02']);
            self::assertStringContainsString('Criterio: por eventos con corte al 2026-05-02',$h2);self::assertStringContainsString('name="corte"',$h2);
            self::assertStringContainsString('Criterio: por eventos, todos los eventos del año',$this->pagina(['version'=>(string)$v,'criterio'=>'eventos']));
            foreach([['criterio'=>'otro'],['criterio'=>'eventos','corte'=>'2026-02-30']] as $mal){
                ob_start();try{$this->pagina(['version'=>(string)$v]+$mal);}catch(\AppTerminationException $x){self::assertSame(422,$x->getStatusCode());}finally{ob_end_clean();}
            }
            // Carga con vista previa desde el formulario.
            self::assertSame(303,$this->post(['accion'=>'presupuesto_cargar','version_id'=>(string)$v,'csv'=>$this->csv("3;comisiones;;Comisiones;100.00;")]));
            $c=(int)$this->r->uno('SELECT id FROM erp_presupuesto_cargas')['id'];self::assertSame(1,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'));
            $h=$this->pagina(['version'=>(string)$v,'carga'=>(string)$c]);self::assertStringContainsString('Confirmar y aplicar',$h);self::assertStringContainsString('Vista previa de la carga',$h);
            self::assertSame(303,$this->post(['accion'=>'presupuesto_carga_confirmar','version_id'=>(string)$v,'carga_id'=>(string)$c]));self::assertSame(2,$this->n('SELECT COUNT(*) FROM erp_presupuesto_lineas'));
            // Finanzas no puede aprobar por la ruta de acciones; el propietario sí.
            $this->post(['accion'=>'presupuesto_aprobar','version_id'=>(string)$v]);self::assertSame('borrador',$this->r->uno('SELECT estado FROM erp_presupuesto_versiones WHERE id=?',[$v])['estado']);
            $this->sesion($this->owner);$h=$this->pagina(['version'=>(string)$v]);self::assertStringContainsString('Aprobar esta versión',$h);
            self::assertSame(303,$this->post(['accion'=>'presupuesto_aprobar','version_id'=>(string)$v]));self::assertSame('aprobada',$this->r->uno('SELECT estado FROM erp_presupuesto_versiones WHERE id=?',[$v])['estado']);
            $h=$this->pagina(['version'=>(string)$v]);self::assertStringContainsString('Abrir revisión',$h);self::assertStringNotContainsString('Guardar cifra',$h,'una versión aprobada no ofrece edición');
            // Gerente consulta sin editar; recepción y el portal cliente, nada.
            $this->sesion($this->gerente);$h=$this->pagina(['version'=>(string)$v]);self::assertStringContainsString('Presupuesto frente a lo real',$h);self::assertStringNotContainsString('Abrir revisión',$h);self::assertStringNotContainsString('Nuevo presupuesto',$this->pagina([]));
            $this->post(['accion'=>'presupuesto_crear','anio'=>'2027','escenario'=>'base']);self::assertSame(1,$this->n('SELECT COUNT(*) FROM erp_presupuestos'),'el gerente no crea presupuestos');
            $this->sesion($this->recepcion);$e=200;ob_start();try{\Controllers\ErpController::presupuestos(new \MVC\Router());}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}self::assertSame(403,$e);
            // Plantilla: descarga solo para quien edita.
            $this->sesion($this->finanzas);$_GET=['version'=>(string)$v];$_SERVER['REQUEST_METHOD']='GET';$e=200;ob_start();try{\Controllers\ErpController::presupuestoPlantilla();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}$salida=(string)ob_get_contents();ob_end_clean();
            self::assertSame(200,$e);self::assertStringContainsString('mes;tipo;referencia;nombre;base;impuesto',$salida);
            $this->sesion($this->gerente);$_GET=['version'=>(string)$v];$e=200;ob_start();try{\Controllers\ErpController::presupuestoPlantilla();}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}self::assertSame(403,$e);
            $this->sesion($this->owner,'cliente');$e=200;ob_start();try{\Controllers\ErpController::presupuestos(new \MVC\Router());}catch(\AppTerminationException $x){$e=$x->getStatusCode();}finally{ob_end_clean();}self::assertContains($e,[302,303,403],'el portal de cliente no entra aunque la cuenta sea propietaria');
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }

    // ---- foto inmutable del comparativo (068) ----
    private function foto(int $id):array {return $this->svc()->foto($id)??self::fail('foto');}
    private function lineasFoto(array $foto):array {$o=[];foreach($foto['lineas'] as $l)$o[(int)$l['mes'].'|'.$l['tipo'].'|'.(int)$l['objetivo_id']]=$l;return $o;}

    public function testLaFotoCongelaVersionCorteComponentesResponsableYFecha():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $id=$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-04-30']))['recurso_id'];
        $f=$this->foto($id);
        self::assertSame([$v,'2026-04-30',1,null,'eventos',ErpPresupuestoService::VERSION_CALCULO,$this->owner],[(int)$f['version_id'],$f['corte'],(int)$f['numero'],$f['corrige_foto_id'],$f['criterio'],(int)$f['version_calculo'],(int)$f['generado_por']]);
        self::assertNotEmpty($f['generado_en']);self::assertTrue($f['integra'],'el contenido coincide con su huella');
        $cmp=$this->svc()->comparativo($v,'eventos','2026-04-30');$idx=$this->indexado($cmp['filas']);$lf=$this->lineasFoto($f);
        self::assertCount(count($idx),$lf,'una línea por mes y concepto del comparativo oficial al corte');self::assertSame((int)$f['n_lineas'],count($lf));
        foreach($idx as $k=>$c){
            self::assertSame([$c['real_base'],$c['real_impuesto']],[(int)$lf[$k]['real_base_centavos'],(int)$lf[$k]['real_impuesto_centavos']],"real $k");
            if($c['componentes']!==null)self::assertSame([$c['componentes']['bruta'],$c['componentes']['descuentos'],$c['componentes']['devoluciones'],$c['componentes']['anulaciones']],
                [(int)$lf[$k]['ventas_bruta_base_centavos'],(int)$lf[$k]['descuentos_base_centavos'],(int)$lf[$k]['devoluciones_base_centavos'],(int)$lf[$k]['anulaciones_base_centavos']],"componentes $k");
            self::assertSame($c['tiene']?$c['presupuesto_base']:null,$lf[$k]['presupuesto_base_centavos']===null?null:(int)$lf[$k]['presupuesto_base_centavos'],"«sin presupuesto» distinto de cero en $k");
        }
        self::assertSame('Corte de ensayo',$lf['3|servicio|'.$this->s1]['nombre'],'el nombre del concepto queda congelado');
        // Histórico incompleto: la venta anulada sin evento se informa, no se inventa fecha.
        self::assertSame(1,$f['limitaciones']['anulaciones_sin_evento']);self::assertStringContainsString('no se inventó una fecha',implode(' ',$f['limitaciones']['notas']));
        self::assertSame(1,(int)array_sum(array_column($f['lineas'],'anulaciones_sin_evento')));
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM erp_eventos WHERE tipo='presupuesto_foto_generada' AND entidad_id=$id"),'queda auditada');
    }
    public function testUnaFotoNoSeEditaNiSeBorraNiAdmiteLineasNuevasYLaHuellaDetectaCambios():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $id=$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-04-30']))['recurso_id'];
        foreach(["UPDATE erp_presupuesto_fotos SET motivo='x' WHERE id=$id","DELETE FROM erp_presupuesto_fotos WHERE id=$id",
            "UPDATE erp_presupuesto_foto_lineas SET real_base_centavos=1 WHERE foto_id=$id","DELETE FROM erp_presupuesto_foto_lineas WHERE foto_id=$id",
            "INSERT INTO erp_presupuesto_foto_lineas(foto_id,mes,tipo,objetivo_id,ventas_bruta_base_centavos,descuentos_base_centavos,ventas_base_centavos,devoluciones_base_centavos,anulaciones_base_centavos,real_base_centavos,real_impuesto_centavos) VALUES($id,12,'servicio',999,0,0,0,0,0,0,0)"] as $sql)
            $this->sqlFalla($sql);
        // La huella delata una alteración hecha por fuera de las reglas (se retira el trigger solo en esta prueba, en una base aislada).
        $this->db->query('DROP TRIGGER trg_presup_foto_linea_no_update');
        $this->db->query("UPDATE erp_presupuesto_foto_lineas SET real_base_centavos=real_base_centavos+1 WHERE foto_id=$id LIMIT 1");
        self::assertFalse($this->foto($id)['integra'],'un cambio en el contenido ya no coincide con la huella');
    }
    public function testUnaAnulacionOUnaDevolucionPosteriorNoAlteranUnaFotoAnterior():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $id=$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-02-28']))['recurso_id'];
        $antes=$this->foto($id);
        // Después de la foto ocurren una anulación nueva y una devolución nueva con fecha posterior al corte.
        $vz=$this->venta('confirmada','2026-02-20 10:00:00');$lz=$this->linea($vz,$this->s1,null,6000,0,0,0,6000);$this->devolucion($vz,$lz,1000,'2026-06-02 10:00:00');$this->anulada($vz,'2026-06-10 09:00:00');
        $despues=$this->foto($id);
        self::assertSame($antes['lineas'],$despues['lineas'],'la foto conserva lo que se informó');self::assertTrue($despues['integra']);
        // El comparativo calculado hoy al mismo corte ve la venta nueva del 20/02 (ocurrió antes del corte); el de estado actual la reescribe: la foto no cambia con ninguno.
        $hoyAlCorte=$this->sumaMeses($this->svc()->comparativo($v,'eventos','2026-02-28')['filas'],'servicio',$this->s1);
        $enFoto=[];foreach($despues['lineas'] as $l)if($l['tipo']==='servicio'&&(int)$l['objetivo_id']===$this->s1&&(int)$l['real_base_centavos']!==0)$enFoto[(int)$l['mes']]=(int)$l['real_base_centavos'];ksort($enFoto);
        self::assertNotSame($hoyAlCorte,$enFoto,'la venta tardía hace que el cálculo de hoy al corte difiera de lo informado: por eso existe la foto');
        self::assertSame([1=>14000,2=>-2500],$enFoto);
    }
    public function testUnaCorreccionGeneraUnaFotoNuevaVinculadaYConservaLaOriginal():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $id1=$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-02-28']))['recurso_id'];
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-02-28'])),409,'Ya existe una foto');
        $this->rechazo(fn()=>$this->svc()->corregirFoto($this->owner,$this->d(['foto_id'=>$id1,'motivo'=>''])));
        $original=$this->foto($id1);
        // Llega una venta con fecha anterior al corte: la corrección la incluye y la original sigue igual.
        $vz=$this->venta('confirmada','2026-02-25 10:00:00');$this->linea($vz,$this->s1,null,6000,0,0,0,6000);
        $id2=$this->svc()->corregirFoto($this->owner,$this->d(['foto_id'=>$id1,'motivo'=>'Venta registrada tarde con fecha de febrero']))['recurso_id'];
        $f2=$this->foto($id2);
        self::assertSame([2,$id1,'2026-02-28','Venta registrada tarde con fecha de febrero'],[(int)$f2['numero'],(int)$f2['corrige_foto_id'],$f2['corte'],$f2['motivo']]);
        self::assertSame($id2,(int)$this->foto($id1)['corregida_por_id']);self::assertNull($f2['corregida_por_id']);
        self::assertSame($original['lineas'],$this->foto($id1)['lineas'],'la original se conserva intacta');self::assertTrue($this->foto($id1)['integra']);self::assertTrue($f2['integra']);
        self::assertSame(6000,(int)$this->lineasFoto($f2)['2|servicio|'.$this->s1]['real_base_centavos']-(int)$this->lineasFoto($original)['2|servicio|'.$this->s1]['real_base_centavos'],'la corrección incluye la venta tardía; la original no');
        // Solo se corrige la foto más reciente de un corte; la corregida no se corrige otra vez.
        $this->rechazo(fn()=>$this->svc()->corregirFoto($this->owner,$this->d(['foto_id'=>$id1,'motivo'=>'Otra vez'])),409,'ya fue corregida');
        $id3=$this->svc()->corregirFoto($this->owner,$this->d(['foto_id'=>$id2,'motivo'=>'Segunda corrección']))['recurso_id'];
        self::assertSame([3,$id2],[(int)$this->foto($id3)['numero'],(int)$this->foto($id3)['corrige_foto_id']]);
        self::assertSame(3,$this->n("SELECT COUNT(*) FROM erp_presupuesto_fotos"),'las tres versiones de la foto se conservan');
        // Si entre tanto se aprobó una versión nueva del presupuesto, la corrección usa la vigente y deja constancia de cuál.
        $pid=(int)$this->r->uno('SELECT presupuesto_id FROM erp_presupuesto_versiones WHERE id=?',[$v])['presupuesto_id'];
        $rev=(int)$this->svc()->revisar($this->owner,$this->d(['presupuesto_id'=>$pid,'motivo'=>'Ajuste']))['recurso_id'];$this->cifra($rev,'servicio:'.$this->s1,5,'10.00','0.00');$this->aprobar($rev);
        $id4=$this->svc()->corregirFoto($this->owner,$this->d(['foto_id'=>$id3,'motivo'=>'Con la versión 2']))['recurso_id'];
        self::assertSame([$rev,$v],[(int)$this->foto($id4)['version_id'],(int)$this->foto($id1)['version_id']],'cada foto conserva la versión del presupuesto con la que se generó');
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-03-31'])),409,'reemplazada');
        // BI: es_vigente marca solo la última del corte.
        $vig=$this->db->query("SELECT foto_numero,es_vigente,version_presupuesto_numero FROM bi_presupuesto_fotos ORDER BY foto_numero")->fetch_all(MYSQLI_ASSOC);
        self::assertSame([1,2,3,4],array_map('intval',array_column($vig,'foto_numero')));self::assertSame([0,0,0,1],array_map('intval',array_column($vig,'es_vigente')));
        self::assertSame(count($this->foto($id4)['lineas']),$this->n("SELECT COUNT(*) FROM bi_presupuesto_foto_lineas WHERE foto_id=$id4"));
        }
    public function testLaFotoValidaCorteVersionYPermiso():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->finanzas,$this->d(['version_id'=>$v,'corte'=>'2026-02-28'])),403);
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2027-02-28'])),null,'año del presupuesto');
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-02-30'])),null,'fecha de corte');
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>''])),null,'fecha de corte');
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-12-31'])),null,'no puede ser futuro');
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>999999,'corte'=>'2026-02-28'])),404);
        $abierta=$this->presupuesto('optimista');$this->cifra($abierta,'servicio:'.$this->s1,3,'10.00','0.00');
        $this->rechazo(fn()=>$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$abierta,'corte'=>'2026-02-28'])),409,'aún no está aprobada');
        $this->rechazo(fn()=>$this->svc()->corregirFoto($this->owner,$this->d(['foto_id'=>999999,'motivo'=>'x'])),404);
        self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_presupuesto_fotos'),'ningún rechazo deja fotos a medias');
        // Una foto sin presupuesto ni movimientos a esa fecha es válida y queda vacía, sin inventar cifras.
        $vacio=$this->presupuesto('vacio',2026);$this->cifra($vacio,'servicio:'.$this->s1,12,'10.00','0.00');$this->aprobar($vacio);
        $f=$this->foto($this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$vacio,'corte'=>'2026-01-05']))['recurso_id']);
        self::assertSame([1,1000,0],[count($f['lineas']),(int)$f['lineas'][0]['presupuesto_base_centavos'],(int)$f['lineas'][0]['real_base_centavos']],'solo la cifra presupuestada de diciembre; sin real a esa fecha');
    }
    public function testLaPantallaGeneraYMuestraFotosYSoloApruebaQuienPuede():void
    {
        $this->ventasConAnulaciones();$v=$this->presupuestoAprobado();
        $anterior=['s'=>$_SESSION??[],'g'=>$_GET,'p'=>$_POST,'srv'=>$_SERVER];
        try{
            $this->sesion($this->finanzas);$h=$this->pagina(['version'=>(string)$v]);
            self::assertStringContainsString('Fotos del comparativo (cierres)',$h);self::assertStringNotContainsString('Generar foto al corte',$h,'finanzas prepara pero no genera cierres');
            $this->post(['accion'=>'presupuesto_foto','version_id'=>(string)$v,'corte'=>'2026-02-28']);self::assertSame(0,$this->n('SELECT COUNT(*) FROM erp_presupuesto_fotos'),'finanzas no puede generar el cierre');
            $this->sesion($this->owner);$h=$this->pagina(['version'=>(string)$v]);self::assertStringContainsString('Generar foto al corte',$h);
            self::assertSame(303,$this->post(['accion'=>'presupuesto_foto','version_id'=>(string)$v,'corte'=>'2026-02-28']));
            $id=(int)$this->r->uno('SELECT id FROM erp_presupuesto_fotos')['id'];
            $h=$this->pagina(['version'=>(string)$v,'foto'=>(string)$id]);
            foreach(['Foto #1 al 2026-02-28','contenido íntegro','Vigente','Limitaciones de esta foto','Generar corrección','Componentes congelados'] as $t)self::assertStringContainsString($t,$h,$t);
            self::assertSame(303,$this->post(['accion'=>'presupuesto_foto_corregir','version_id'=>(string)$v,'foto_id'=>(string)$id,'motivo'=>'Revisión de cierre']));
            $h=$this->pagina(['version'=>(string)$v,'foto'=>(string)$id]);self::assertStringContainsString('Corregida por la foto #2',$h);self::assertStringNotContainsString('Generar corrección',$h);
            // El número que se muestra es el de la foto (por corte), no su identificador de base de datos.
            $this->db->query("ALTER TABLE erp_presupuesto_fotos AUTO_INCREMENT=700");
            $otraV=$this->presupuesto('tercero');$this->cifra($otraV,'servicio:'.$this->s1,3,'10.00','0.00');$this->aprobar($otraV);
            self::assertSame(303,$this->post(['accion'=>'presupuesto_foto','version_id'=>(string)$otraV,'corte'=>'2026-03-31']));
            $f1=(int)$this->r->uno("SELECT id FROM erp_presupuesto_fotos WHERE version_id=$otraV")['id'];
            self::assertSame(303,$this->post(['accion'=>'presupuesto_foto_corregir','version_id'=>(string)$otraV,'foto_id'=>(string)$f1,'motivo'=>'Número distinto del id']));
            $h=$this->pagina(['version'=>(string)$otraV]);self::assertStringContainsString('Corregida por la foto #2',$h);self::assertStringNotContainsString('Corregida por la foto #'.($f1+1),$h);
            // Una foto de otro presupuesto no se muestra desde esta versión.
            $otro=$this->presupuesto('otro');$this->cifra($otro,'servicio:'.$this->s1,3,'10.00','0.00');$this->aprobar($otro);
            $h=$this->pagina(['version'=>(string)$otro,'foto'=>(string)$id]);self::assertStringNotContainsString('Foto #1 al',$h);
        }finally{$_SESSION=$anterior['s'];$_GET=$anterior['g'];$_POST=$anterior['p'];$_SERVER=$anterior['srv'];http_response_code(200);}
    }
    public function testLaHuellaNoDependeDelOrdenDeLineasConConceptosDeIgualNombre():void
    {
        $s2=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Corte de ensayo',50.00,30)");
        $v=$this->presupuesto();$this->cifra($v,'servicio:'.$this->s1,3,'10.00','0.00');$this->cifra($v,'servicio:'.$s2,3,'20.00','0.00');$this->aprobar($v);
        $id=$this->svc()->generarFoto($this->owner,$this->d(['version_id'=>$v,'corte'=>'2026-03-31']))['recurso_id'];
        // Dos servicios con el mismo nombre y mes: el orden de lectura puede variar; la huella se calcula sobre un orden canónico (mes, tipo, objetivo).
        self::assertTrue($this->foto($id)['integra']);
        $this->db->query("ALTER TABLE erp_presupuesto_foto_lineas ADD INDEX idx_orden_ensayo (objetivo_id DESC)");
        self::assertTrue($this->foto($id)['integra'],'un plan de ejecución distinto no cambia la verificación');
    }
}
