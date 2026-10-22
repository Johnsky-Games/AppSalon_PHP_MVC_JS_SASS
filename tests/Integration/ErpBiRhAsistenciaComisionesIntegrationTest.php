<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpCajaService,ErpContabilidadService,ErpMaestrosService,ErpNominaService,ErpRhService,ErpVentaService};

/**
 * Modelo de lectura BI laboral (061): asistencia, jornadas, ausencias y comisiones del personal, con acceso separado del grupo comercial.
 * Cada liquidación de comisiones entra por un solo canal (caja, obligación o nómina) y los dos libros de comisiones se suman sin duplicar.
 */
final class ErpBiRhAsistenciaComisionesIntegrationTest extends TestCase
{
    private const VISTAS=['bi_rh_asistencia','bi_rh_jornadas','bi_rh_ausencias','bi_rh_comisiones_movimientos','bi_rh_comisiones_liquidaciones'];
    private const COMERCIALES=['bi_venta_lineas','bi_libro_lineas','bi_flujo_dinero','bi_atenciones','bi_cxc_movimientos'];
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $colA;private int $colB;private int $venta;private int $linea;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('birh');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');
        $this->colA=$this->colaborador('Ana','Privada',R5CajaFixture::usuario($this->db,'profesional'),'2024-01-01');$this->colB=$this->colaborador('Beto','Reservado',R5CajaFixture::usuario($this->db,'profesional'),'2024-01-01');
        $rh=new ErpRhService($this->r);
        foreach([$this->colA=>'600.00',$this->colB=>'482.00'] as $c=>$base)$rh->contrato($this->owner,$this->d(['colaborador_id'=>$c,'tipo'=>'empleado','inicio'=>'2024-01-01','base'=>$base,'observacion'=>'Contrato de ensayo']));
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        // Una venta real para tener venta y línea con las que enlazar los movimientos de comisión del ERP.
        $cli=R5CajaFixture::usuario($this->db);$this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$cli]);
        $m=new ErpMaestrosService($this->r);$contacto=$m->contacto($this->owner,$this->d(['nombre'=>'Clienta Privada RH','tipo'=>'cliente']))['recurso_id'];
        (new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']));
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('RH','Producto','unidad',100000)");$prod=$this->r->id();
        $cat=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$prod,'precio'=>'20','impuesto'=>'0','tipo'=>'venta']))['recurso_id'];
        $s=new ErpVentaService($this->r);$this->venta=$s->crear($this->owner,$this->d(['contacto_id'=>$contacto]))['recurso_id'];
        $s->agregar($this->owner,$this->d(['venta_id'=>$this->venta,'catalogo_id'=>$cat,'cantidad'=>'1']));
        $s->confirmar($this->owner,$this->d(['venta_id'=>$this->venta,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$this->venta])['version']]));
        $this->linea=(int)$this->r->uno('SELECT id n FROM erp_venta_lineas WHERE venta_id=?',[$this->venta])['n'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function n(string $sql):int {return (int)($this->db->query($sql)->fetch_row()[0]??0);}
    private function ins(string $sql):int {self::assertTrue($this->db->query($sql)!==false,$this->db->error);return (int)$this->db->insert_id;}
    private function colaborador(string $n,string $a,int $usuario,string $ingreso):int
    {
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,?,?,'profesional','activo',?,NOW() FROM usuarios WHERE id=?",[$n,$a,$ingreso,$usuario]);return $this->r->id();
    }
    private function nomina():ErpNominaService {return new ErpNominaService($this->r);}
    /** Valores de ENSAYO (no oficiales), como en la prueba de nómina. */
    private function parametrosNomina():void
    {
        foreach(['sbu_centavos'=>48200,'iess.aporte_personal_pb'=>945,'iess.aporte_patronal_pb'=>1115,'fondos_reserva.pb'=>833,'fondos_reserva.dias_antiguedad'=>365,'recargo.nocturno_pb'=>2500,'recargo.suplementaria_pb'=>5000,'recargo.extraordinaria_pb'=>10000,'jornada.horas_mes'=>240] as $k=>$v)
            $this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),1,NOW())",[$k,$v]);
        $this->nomina()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
        $this->nomina()->condicion($this->owner,$this->d(['colaborador_id'=>$this->colB,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
    }

    public function testLaAsistenciaSeMideEnMinutosSinContarLasAnuladasYLosTurnosNocturnosCaenEnSuDiaDeEntrada():void
    {
        $a=$this->colA;
        $normal=$this->ins("INSERT INTO erp_asistencia(colaborador_id,entrada,salida,descanso_minutos,motivo,creado_por,creado_en) VALUES($a,'2026-10-05 08:00:00','2026-10-05 17:00:00',60,'Registro',{$this->owner},NOW())");
        $anulada=$this->ins("INSERT INTO erp_asistencia(colaborador_id,entrada,salida,descanso_minutos,anulado_en,motivo,creado_por,creado_en) VALUES($a,'2026-10-06 08:00:00','2026-10-06 12:00:00',0,NOW(),'Error de captura',{$this->owner},NOW())");
        $noche=$this->ins("INSERT INTO erp_asistencia(colaborador_id,entrada,salida,descanso_minutos,motivo,creado_por,creado_en) VALUES({$this->colB},'2026-10-07 22:00:00','2026-10-08 06:00:00',0,'Turno nocturno',{$this->owner},NOW())");
        $corto=$this->ins("INSERT INTO erp_asistencia(colaborador_id,entrada,salida,descanso_minutos,motivo,creado_por,creado_en) VALUES($a,'2026-10-09 09:00:00','2026-10-09 09:30:00',45,'Descanso mayor que el turno',{$this->owner},NOW())");
        $f=fn(int $id)=>$this->db->query("SELECT * FROM bi_rh_asistencia WHERE asistencia_id=$id")->fetch_assoc();
        self::assertSame(['480','480'],[$f($normal)['minutos_trabajados'],$f($normal)['minutos_trabajados_vigentes']],'9 h − 60 min de descanso');
        self::assertSame(['240','0','1'],[$f($anulada)['minutos_trabajados'],$f($anulada)['minutos_trabajados_vigentes'],$f($anulada)['anulada']],'la anulada se conserva pero no suma vigentes');
        self::assertSame(['2026-10-07','480'],[$f($noche)['fecha'],$f($noche)['minutos_trabajados']],'el turno nocturno cuenta en el día en que entró');
        self::assertSame('0',$f($corto)['minutos_trabajados'],'un descanso mayor que el turno no da minutos negativos');
        self::assertSame($this->n("SELECT COUNT(*) FROM erp_asistencia"),$this->n("SELECT COUNT(*) FROM bi_rh_asistencia"),'una fila por registro');
        self::assertSame(480+0+480+0,$this->n("SELECT SUM(minutos_trabajados_vigentes) FROM bi_rh_asistencia"));
    }

    public function testJornadasYAusenciasSeLeenSinMotivosNiTextoLibre():void
    {
        $a=$this->colA;
        $this->ins("INSERT INTO erp_jornadas(colaborador_id,dia_semana,inicio,fin,actualizado_por) VALUES($a,1,'09:00:00','18:00:00',{$this->owner})");
        $this->ins("INSERT INTO erp_jornadas(colaborador_id,dia_semana,inicio,fin,actualizado_por) VALUES($a,2,'09:00:00','13:30:00',{$this->owner})");
        self::assertSame([540,270],array_map('intval',array_column($this->db->query("SELECT minutos_programados FROM bi_rh_jornadas ORDER BY dia_semana")->fetch_all(MYSQLI_ASSOC),'minutos_programados')));
        $ap=$this->ins("INSERT INTO erp_ausencias(colaborador_id,desde,hasta,tipo,motivo,estado,solicitante_id,creado_en) VALUES($a,'2026-10-12','2026-10-16','vacaciones','Vacaciones de ensayo con detalle privado','aprobada',{$this->owner},NOW())");
        $so=$this->ins("INSERT INTO erp_ausencias(colaborador_id,desde,hasta,tipo,motivo,estado,solicitante_id,creado_en) VALUES($a,'2026-10-20','2026-10-20','permiso','Cita médica privada','solicitada',{$this->owner},NOW())");
        $f=fn(int $id)=>$this->db->query("SELECT * FROM bi_rh_ausencias WHERE ausencia_id=$id")->fetch_assoc();
        self::assertSame(['5','vacaciones','1'],[$f($ap)['dias_calendario'],$f($ap)['tipo'],$f($ap)['es_aprobada']]);
        self::assertSame(['1','0'],[$f($so)['dias_calendario'],$f($so)['es_aprobada']],'un día; solicitada no es aprobada');
        $texto=json_encode($this->db->query("SELECT * FROM bi_rh_ausencias")->fetch_all(MYSQLI_ASSOC));
        self::assertStringNotContainsString('privad',$texto);
    }

    public function testLosDosLibrosDeComisionesSeSumanSinDuplicarYLosAnuladosNoEntran():void
    {
        $prof=$this->ins("INSERT INTO profesionales(nombre,activo,colaborador_id) VALUES('Profesional heredado',1,{$this->colA})");
        $sinFicha=$this->ins("INSERT INTO profesionales(nombre,activo) VALUES('Profesional sin ficha laboral',1)");
        // Libro del ERP (por colaborador): devengo pendiente, devengo ya liquidado y un ajuste negativo.
        $liq=$this->ins("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES({$this->colA},500,'directa',1,NOW())");
        $m1=$this->ins("INSERT INTO erp_comision_movimientos(venta_id,linea_id,colaborador_id,porcentaje_pb,monto_centavos,creado_en) VALUES({$this->venta},{$this->linea},{$this->colA},1000,1000,NOW())");
        $m2=$this->ins("INSERT INTO erp_comision_movimientos(venta_id,linea_id,colaborador_id,porcentaje_pb,monto_centavos,estado,liquidacion_id,creado_en) VALUES({$this->venta},{$this->linea},{$this->colA},1000,500,'asignada',$liq,NOW())");
        $m3=$this->ins("INSERT INTO erp_comision_movimientos(venta_id,linea_id,colaborador_id,porcentaje_pb,monto_centavos,creado_en) VALUES({$this->venta},{$this->linea},{$this->colB},1000,-200,NOW())");
        // Libro heredado (por profesional): devengo liquidado, reversa negativa pendiente, devengo anulado y uno de un profesional sin ficha.
        $l1=$this->ins("INSERT INTO comisiones_liquidaciones(profesional_id,profesional_nombre,hasta_fecha,monto_centavos,metodo,comprobante,idempotencia,huella) VALUES($prof,'Profesional heredado','2026-10-31',300,'efectivo','C-1','h1',REPEAT('a',64))");
        $this->ins("INSERT INTO comisiones_movimientos(profesional_id,profesional_nombre,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos,estado,liquidacion_id) VALUES($prof,'P','R1','devengo',3000,1000,300,'liquidada',$l1)");
        $this->ins("INSERT INTO comisiones_movimientos(profesional_id,profesional_nombre,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos) VALUES($prof,'P','R2','reversa',1000,1000,-100)");
        $this->ins("INSERT INTO comisiones_movimientos(profesional_id,profesional_nombre,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos,estado) VALUES($prof,'P','R3','devengo',99990,1000,9999,'anulada')");
        $this->ins("INSERT INTO comisiones_movimientos(profesional_id,profesional_nombre,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos) VALUES($sinFicha,'S','R4','devengo',2000,1000,200)");
        $erp=$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM erp_comision_movimientos");
        $her=$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM comisiones_movimientos WHERE estado<>'anulada'");
        self::assertSame($erp+$her,$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_movimientos"),'devengada = ambos libros, sin los anulados');
        self::assertSame(1000+500-200+300-100+200,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_movimientos"));
        self::assertSame($this->n("SELECT COUNT(*) FROM erp_comision_movimientos")+$this->n("SELECT COUNT(*) FROM comisiones_movimientos WHERE estado<>'anulada'"),$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_movimientos"));
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_movimientos WHERE monto_centavos=9999"),'el anulado no entra');
        self::assertSame(2,$this->n("SELECT SUM(liquidada) FROM bi_rh_comisiones_movimientos"),'un liquidado por libro');
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_movimientos WHERE sistema='anticipo_previo' AND colaborador_id IS NULL"),'el profesional heredado sin ficha queda sin colaborador, no se inventa');
        self::assertSame($this->colA,$this->n("SELECT colaborador_id FROM bi_rh_comisiones_movimientos WHERE sistema='anticipo_previo' AND monto_centavos=300"),'el heredado se atribuye al colaborador de su profesional');
        self::assertSame(['erp','erp','erp'],array_column($this->db->query("SELECT sistema FROM bi_rh_comisiones_movimientos WHERE venta_id IS NOT NULL")->fetch_all(MYSQLI_ASSOC),'sistema'));
        // Pendiente por colaborador = lo no liquidado (incluye ajustes negativos que se compensan con devengos futuros).
        self::assertSame(1000,$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_movimientos WHERE colaborador_id={$this->colA} AND sistema='erp' AND NOT liquidada"));
    }

    public function testCadaLiquidacionTieneUnSoloCanalYLaDeNominaNoPuedePagarseTambienDirecto():void
    {
        $this->parametrosNomina();
        $prof=$this->ins("INSERT INTO profesionales(nombre,activo,colaborador_id) VALUES('Profesional heredado',1,{$this->colA})");
        $directa=$this->ins("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES({$this->colA},5000,'directa',1,NOW())");
        $this->ins("INSERT INTO erp_obligaciones(colaborador_id,origen,referencia,monto_centavos,pagado_centavos,vence_en,creado_por,creado_en) VALUES({$this->colA},'comision','COM-ERP-$directa',5000,2000,CURDATE(),1,NOW())");
        $this->db->query("UPDATE erp_comision_liquidaciones SET obligacion_id=(SELECT id FROM erp_obligaciones WHERE referencia='COM-ERP-$directa') WHERE id=$directa");
        $nom=$this->ins("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES({$this->colA},7000,'nomina',1,NOW())");
        $this->ins("INSERT INTO comisiones_liquidaciones(profesional_id,profesional_nombre,hasta_fecha,monto_centavos,metodo,comprobante,idempotencia,huella) VALUES($prof,'Profesional heredado','2026-10-31',300,'efectivo','C-2','h2',REPEAT('b',64))");
        $porCanal=fn(string $c)=>$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_liquidaciones WHERE canal='$c'");
        self::assertSame([5000,7000,300],[$porCanal('obligacion_directa'),$porCanal('nomina'),$porCanal('caja_directa')]);
        self::assertSame($this->n("SELECT COUNT(*) FROM erp_comision_liquidaciones")+$this->n("SELECT COUNT(*) FROM comisiones_liquidaciones"),$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_liquidaciones"),'una fila por liquidación: ninguna se cuenta en dos canales');
        self::assertSame(5000+7000+300,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_liquidaciones"));
        self::assertSame(2000,$this->n("SELECT pagado_centavos FROM bi_rh_comisiones_liquidaciones WHERE liquidacion_id=$directa AND sistema='erp'"),'lo pagado de la obligación');
        self::assertSame(0,$this->n("SELECT pagado_centavos FROM bi_rh_comisiones_liquidaciones WHERE liquidacion_id=$nom AND sistema='erp'"),'la de nómina se paga con la corrida, no aquí');
        self::assertSame(300,$this->n("SELECT pagado_centavos FROM bi_rh_comisiones_liquidaciones WHERE sistema='anticipo_previo'"),'la heredada se pagó desde caja');
        // La corrida de nómina toma solo la liquidada por nómina, una sola vez, y la vista lo refleja.
        $k=$this->nomina()->calcular($this->finanzas,$this->d(['anio'=>2026,'mes'=>10]))['recurso_id'];
        $linea=$this->r->uno('SELECT * FROM erp_nomina_lineas WHERE corrida_id=? AND colaborador_id=?',[$k,$this->colA]);
        self::assertSame(7000,(int)$linea['comisiones_centavos'],'solo la de nómina entra a la línea');
        $v=$this->db->query("SELECT en_corrida_activa,corrida_id FROM bi_rh_comisiones_liquidaciones WHERE liquidacion_id=$nom AND sistema='erp'")->fetch_assoc();
        self::assertSame(['1',(string)$k],[$v['en_corrida_activa'],$v['corrida_id']]);
        self::assertSame($this->n("SELECT SUM(monto_centavos) FROM erp_nomina_comisiones WHERE activa=1"),$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_liquidaciones WHERE en_corrida_activa"),'lo incluido en nómina = vínculos activos');
        self::assertSame(0,$this->n("SELECT en_corrida_activa FROM bi_rh_comisiones_liquidaciones WHERE liquidacion_id=$directa AND sistema='erp'"),'la directa nunca está en una corrida');
        mysqli_report(MYSQLI_REPORT_OFF);
        self::assertFalse($this->db->query("INSERT INTO erp_nomina_comisiones(liquidacion_id,linea_id,monto_centavos) VALUES($directa,{$linea['id']},5000)"),'la base impide pagar por nómina una comisión directa');
        // Liberar la comisión de la corrida (p. ej. al anularla) la deja otra vez fuera de nómina, sin duplicar la fila.
        $this->db->query("UPDATE erp_nomina_comisiones SET activa=0 WHERE liquidacion_id=$nom");
        self::assertSame([0,1],[$this->n("SELECT en_corrida_activa FROM bi_rh_comisiones_liquidaciones WHERE liquidacion_id=$nom AND sistema='erp'"),$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_liquidaciones WHERE liquidacion_id=$nom AND sistema='erp'")]);
    }

    public function testLasVistasLaboralesSonDeSoloLecturaSinDatosPersonalesYSeSeparanDelAccesoComercial():void
    {
        $a=$this->colA;
        $this->ins("INSERT INTO erp_asistencia(colaborador_id,entrada,salida,descanso_minutos,motivo,creado_por,creado_en) VALUES($a,'2026-10-05 08:00:00','2026-10-05 17:00:00',60,'Detalle privado de asistencia',{$this->owner},NOW())");
        $this->ins("INSERT INTO erp_jornadas(colaborador_id,dia_semana,inicio,fin,actualizado_por) VALUES($a,1,'09:00:00','18:00:00',{$this->owner})");
        $this->ins("INSERT INTO erp_ausencias(colaborador_id,desde,hasta,tipo,motivo,estado,solicitante_id,creado_en) VALUES($a,'2026-10-12','2026-10-12','permiso','Detalle privado de ausencia','aprobada',{$this->owner},NOW())");
        $this->ins("INSERT INTO erp_comision_movimientos(venta_id,linea_id,colaborador_id,porcentaje_pb,monto_centavos,creado_en) VALUES({$this->venta},{$this->linea},$a,1000,1000,NOW())");
        $this->ins("INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES($a,500,'nomina',1,NOW())");
        $prohibidas=['nombre','apellido','email','correo','telefono','identificacion','cedula','ruc','direccion','sueldo','salario','base_centavos','motivo','nota','observacion','actor','creado_por','_por','usuario','comprobante','profesional_nombre'];
        foreach(self::VISTAS as $v){
            self::assertSame(1,$this->n("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$v' AND security_type='DEFINER'"));
            $cols=array_column($this->db->query("SELECT column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$v'")->fetch_all(MYSQLI_ASSOC),'c');
            foreach($cols as $c)foreach($prohibidas as $p)self::assertStringNotContainsString($p,strtolower($c),"$v.$c no debe exponer datos personales ni salariales");
            $texto=json_encode($this->db->query("SELECT * FROM `$v`")->fetch_all(MYSQLI_ASSOC));
            foreach(['Ana','Privada','Reservado','Detalle privado','Profesional heredado'] as $s)self::assertStringNotContainsString($s,$texto,"$v no debe contener $s");
        }
        $bd=BaseDatosAislada::nombre($this->db);
        $crear=function(array $vistas)use($bd){
            $u='bi_rh_'.bin2hex(random_bytes(4));$c='Bi'.bin2hex(random_bytes(6));
            $this->db->query("CREATE USER '$u'@'%' IDENTIFIED BY '$c'");foreach($vistas as $v)$this->db->query("GRANT SELECT ON `$bd`.`$v` TO '$u'@'%'");
            mysqli_report(MYSQLI_REPORT_OFF);$bi=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',$u,$c,$bd,(int)(getenv('DB_PORT')?:3306));self::assertSame(0,$bi->connect_errno);return [$u,$bi];
        };
        [$uLab,$lab]=$crear(self::VISTAS);[$uCom,$com]=$crear(self::COMERCIALES);
        try{
            foreach(self::VISTAS as $v)self::assertNotFalse($lab->query("SELECT COUNT(*) FROM `$v`"),"$v legible por el usuario laboral: ".$lab->error);
            foreach(self::COMERCIALES as $v)self::assertFalse($lab->query("SELECT 1 FROM `$v` LIMIT 1"),"el usuario laboral no lee la vista comercial $v");
            foreach(self::VISTAS as $v)self::assertFalse($com->query("SELECT 1 FROM `$v` LIMIT 1"),"el usuario comercial no lee la vista laboral $v");
            foreach(self::COMERCIALES as $v)self::assertNotFalse($com->query("SELECT COUNT(*) FROM `$v`"),"$v legible por el usuario comercial: ".$com->error);
            foreach(['erp_asistencia','erp_jornadas','erp_ausencias','erp_comision_movimientos','erp_comision_liquidaciones','colaboradores','usuarios','erp_contratos','erp_nomina_lineas','comisiones_movimientos'] as $t)self::assertFalse($lab->query("SELECT 1 FROM `$t` LIMIT 1"),"el usuario laboral no lee la tabla $t");
            foreach(["UPDATE bi_rh_asistencia SET descanso_minutos=descanso_minutos+1","DELETE FROM bi_rh_ausencias","UPDATE bi_rh_comisiones_movimientos SET monto_centavos=0"] as $sql)self::assertFalse($lab->query($sql),$sql);
        }finally{mysqli_report(MYSQLI_REPORT_OFF);foreach([$uLab,$uCom] as $u)$this->db->query("DROP USER IF EXISTS '$u'@'%'");}
        $filas=$this->n("SELECT COUNT(*) FROM erp_asistencia");mysqli_report(MYSQLI_REPORT_OFF);
        foreach(["UPDATE bi_rh_asistencia SET descanso_minutos=descanso_minutos+1","UPDATE bi_rh_asistencia SET fecha='2000-01-01'","DELETE FROM bi_rh_asistencia","UPDATE bi_rh_jornadas SET dia_semana=3","DELETE FROM bi_rh_jornadas","UPDATE bi_rh_ausencias SET tipo='licencia_remunerada'","UPDATE bi_rh_ausencias SET estado='rechazada'","DELETE FROM bi_rh_ausencias","UPDATE bi_rh_comisiones_movimientos SET monto_centavos=0","DELETE FROM bi_rh_comisiones_movimientos","UPDATE bi_rh_comisiones_liquidaciones SET monto_centavos=0","DELETE FROM bi_rh_comisiones_liquidaciones"] as $sql)self::assertFalse($this->db->query($sql),"vista no actualizable: $sql");
        mysqli_report(MYSQLI_REPORT_OFF);
        self::assertSame($filas,$this->n("SELECT COUNT(*) FROM erp_asistencia"));
    }
}
