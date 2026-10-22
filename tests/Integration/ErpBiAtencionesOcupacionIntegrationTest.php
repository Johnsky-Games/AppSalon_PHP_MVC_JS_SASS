<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ReporteRepository;
use Services\ReporteService;

/**
 * Modelo de lectura BI, atenciones y ocupación (059): las vistas se reconcilian con Services\ReporteService (mismos minutos reservados,
 * mismos minutos disponibles netos de descansos y bloqueos sin restar dos veces, sin división por cero) y con las tablas de origen.
 * Semana de ensayo: lunes 2026-10-05 a domingo 2026-10-11 (fechas fijas: el reloj no interviene).
 */
final class ErpBiAtencionesOcupacionIntegrationTest extends TestCase
{
    private const VISTAS=['bi_atenciones','bi_atencion_servicios','bi_ocupacion_profesional_dia'];
    private \mysqli $db;private int $usuario;private int $profA;private int $profB;private int $srv1;private int $srv2;
    /** @var array<string,int> */ private array $citas=[];
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('biocupa');ActiveRecord::setDB($this->db);$this->usuario=R5CajaFixture::usuario($this->db);
        $this->srv1=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Corte',20.00,20)");
        $this->srv2=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Peinado',25.00,25)");
        $this->profA=$this->ins("INSERT INTO profesionales(nombre,activo) VALUES('Profesional A',1)");
        $this->profB=$this->ins("INSERT INTO profesionales(nombre,activo) VALUES('Profesional B inactivo',0)");
        $a=$this->profA;$b=$this->profB;
        // A: lunes 09:00-18:00 con descanso 13:00-14:00 y bloqueo 13:30-15:00 (solape de 30 min: se descuentan 120, no 150) = 420.
        $this->ins("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($a,1,'09:00','18:00')");
        $this->ins("INSERT INTO descansos_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($a,1,'13:00','14:00')");
        $this->ins("INSERT INTO bloqueos_profesionales(profesionalId,fecha_inicio,fecha_fin,hora_inicio,hora_fin) VALUES($a,'2026-10-05','2026-10-05','13:30','15:00')");
        // A: martes 09:00-13:00 con bloqueo de día completo (sin horas) = 0.
        $this->ins("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($a,2,'09:00','13:00')");
        $this->ins("INSERT INTO bloqueos_profesionales(profesionalId,fecha_inicio,fecha_fin) VALUES($a,'2026-10-06','2026-10-06')");
        // A: miércoles con dos turnos 09:00-12:00 y 14:00-17:00 = 360.
        $this->ins("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($a,3,'09:00','12:00')");
        $this->ins("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($a,3,'14:00','17:00')");
        // A: jueves y viernes 09:00-12:00; un bloqueo de varios días 10:00-11:00 = 120 cada día.
        $this->ins("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($a,4,'09:00','12:00')");
        $this->ins("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($a,5,'09:00','12:00')");
        $this->ins("INSERT INTO bloqueos_profesionales(profesionalId,fecha_inicio,fecha_fin,hora_inicio,hora_fin) VALUES($a,'2026-10-08','2026-10-09','10:00','11:00')");
        // B (inactivo): lunes 09:00-18:00 sin restricciones; su disponibilidad cuenta 0 igual que en el reporte.
        $this->ins("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES($b,1,'09:00','18:00')");

        // Lunes de A: confirmada de 90 min guardados; atendida regularizada (45 min por hora_fin - hora_inicio); no asistió (45 min por servicios); cancelada (60, no cuenta).
        $this->citas['confirmada']=$this->cita('2026-10-05','10:00:00',$a,'confirmada',['hora_inicio'=>'10:00:00','hora_fin'=>'11:30:00','duracion_total_minutos'=>90],[[$this->srv1,'Corte',20,20],[$this->srv2,'Peinado',25,25]]);
        $this->citas['atendida']=$this->cita('2026-10-05','15:00:00',$a,'completada',['hora_inicio'=>'15:00:00','hora_fin'=>'15:45:00','atencion_efectiva_at'=>'2026-10-06 10:00:00'],[[$this->srv1,'Corte',20,20]]);
        $this->citas['no_asistio']=$this->cita('2026-10-05','16:00:00',$a,'no_asistio',[],[[$this->srv1,'Corte',20,20],[$this->srv2,'Peinado',25,25]]);
        $this->citas['cancelada']=$this->cita('2026-10-05','17:00:00',$a,'cancelada',['duracion_total_minutos'=>60],[[$this->srv1,'Corte',20,20]]);
        // Miércoles de A: atendida sin regularizar (la fecha del servicio es la programada), con un servicio ya fuera del catálogo y precio histórico.
        $this->citas['miercoles']=$this->cita('2026-10-07','10:00:00',$a,'completada',['duracion_total_minutos'=>30],[[null,'Servicio retirado',33.33,30]]);
        // Cita sin profesional (histórica) y cita de B (inactivo), ambas el lunes.
        $this->citas['sin_prof']=$this->cita('2026-10-05','12:00:00',null,'confirmada',['duracion_total_minutos'=>30],[[$this->srv1,'Corte',20,20]]);
        $this->citas['de_b']=$this->cita('2026-10-05','11:00:00',$b,'completada',['duracion_total_minutos'=>30],[[$this->srv1,'Corte',20,20]]);
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function ins(string $sql):int {self::assertTrue($this->db->query($sql)!==false,$this->db->error);return (int)$this->db->insert_id;}
    private function n(string $sql):int {return (int)($this->db->query($sql)->fetch_row()[0]??0);}
    /** @param array<string,mixed> $extra @param array<int,array{0:?int,1:string,2:float,3:int}> $servicios */
    private function cita(string $fecha,string $hora,?int $prof,string $estado,array $extra,array $servicios):int
    {
        $cols=['fecha'=>"'$fecha'",'hora'=>"'$hora'",'usuarioId'=>$this->usuario,'profesionalId'=>$prof??'NULL','estado'=>"'$estado'"];
        foreach($extra as $k=>$v)$cols[$k]=is_int($v)?$v:"'$v'";
        $id=$this->ins('INSERT INTO citas('.implode(',',array_keys($cols)).') VALUES('.implode(',',$cols).')');
        foreach($servicios as [$sid,$nombre,$precio,$dur]){
            $retirado=$sid===null;$sid??=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Temporal',1.00,5)");
            $this->ins('INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES('.$id.",$sid,'$nombre',$precio,$dur)");
            if($retirado)self::assertTrue($this->db->query("DELETE FROM servicios WHERE id=$sid")!==false,$this->db->error);// ON DELETE SET NULL: queda la instantánea histórica
        }
        return $id;
    }
    private function fila(string $sql):array {return $this->db->query($sql)->fetch_assoc()??[];}
    private function reporte(array $filtros=[]):array
    {
        $svc=new ReporteService(new ReporteRepository($this->db),new \DateTimeImmutable('2026-10-31 12:00:00',new \DateTimeZone('America/Guayaquil')));
        $r=$svc->consultarReporte($filtros+['fecha_inicio'=>'2026-10-05','fecha_fin'=>'2026-10-11']);
        self::assertSame(200,$r['http_code'],json_encode($r['alertas']??[]));return $r;
    }

    public function testLosMinutosDisponiblesNetosCuadranConElReporteSinRestarDosVecesElSolape():void
    {
        $r=$this->reporte();$porProf=[];foreach($r['resumen_profesionales'] as $p)if($p['profesional_id']!==null)$porProf[$p['profesional_id']]=$p;
        $dia=fn(string $f)=>$this->n("SELECT minutos_disponibles FROM bi_ocupacion_profesional_dia WHERE profesional_id={$this->profA} AND fecha='$f'");
        self::assertSame(420,$dia('2026-10-05'),'lunes: 540 - (13:00-15:00 = 120), no 150');
        self::assertSame(0,$dia('2026-10-06'),'bloqueo de día completo');
        self::assertSame(360,$dia('2026-10-07'),'dos turnos');
        self::assertSame(180-60,$dia('2026-10-08'));self::assertSame(120,$dia('2026-10-09'),'bloqueo de varios días');
        self::assertSame(0,$dia('2026-10-10'),'sábado sin horario');self::assertSame(0,$dia('2026-10-11'));
        foreach([$this->profA,$this->profB] as $p){
            $vista=$this->n("SELECT COALESCE(SUM(minutos_disponibles),0) FROM bi_ocupacion_profesional_dia WHERE profesional_id=$p AND fecha BETWEEN '2026-10-05' AND '2026-10-11'");
            self::assertSame($porProf[$p]['minutos_disponibles'],$vista,"disponibles del profesional $p = ReporteService");
        }
        self::assertSame(0,$porProf[$this->profB]['minutos_disponibles'],'inactivo: 0 disponibles');
        self::assertSame(420+0+360+120+120,$porProf[$this->profA]['minutos_disponibles']);
    }

    public function testLosMinutosReservadosYLaOcupacionCuadranConElReporteYLasCanceladasNoOcupan():void
    {
        $r=$this->reporte();$porProf=[];$sinProf=null;foreach($r['resumen_profesionales'] as $p){if($p['profesional_id']===null)$sinProf=$p;else $porProf[$p['profesional_id']]=$p;}
        foreach([$this->profA,$this->profB] as $p){
            $f=$this->fila("SELECT SUM(minutos_reservados) res,SUM(minutos_disponibles) disp,SUM(citas_activas) citas FROM bi_ocupacion_profesional_dia WHERE profesional_id=$p AND fecha BETWEEN '2026-10-05' AND '2026-10-11'");
            self::assertSame($porProf[$p]['minutos_ocupados'],(int)$f['res'],"reservados del profesional $p = ReporteService");
            self::assertSame($porProf[$p]['total_citas'],(int)$f['citas']);
            $esperado=(int)$f['disp']>0?round($f['res']/$f['disp']*100,2):0.0;
            self::assertSame($porProf[$p]['porcentaje_ocupacion'],$esperado,'ocupación = suma reservada / suma disponible, 0 si no hay turno');
        }
        // A: 90 (guardados) + 45 (hora_fin - hora_inicio) + 45 (servicios) + 30 (miércoles); la cancelada de 60 no ocupa.
        self::assertSame(90+45+45+30,$porProf[$this->profA]['minutos_ocupados']);
        self::assertSame(30,$porProf[$this->profB]['minutos_ocupados'],'el inactivo tiene reservas pero 0 disponibles: ocupación indefinida, no infinita');
        // Citas sin profesional: fuera de la vista de ocupación, visibles en bi_atenciones y separadas como en el reporte.
        self::assertSame($sinProf['minutos_ocupados'],$this->n("SELECT COALESCE(SUM(minutos_reservados),0) FROM bi_atenciones WHERE profesional_id IS NULL AND NOT es_cancelada AND fecha_programada BETWEEN '2026-10-05' AND '2026-10-11'"));
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_ocupacion_profesional_dia WHERE profesional_id IS NULL"));
        // Cancelada: se conserva en la vista para medir cancelaciones, pero no suma minutos ocupados.
        self::assertSame(1,$this->n("SELECT citas_canceladas FROM bi_ocupacion_profesional_dia WHERE profesional_id={$this->profA} AND fecha='2026-10-05'"));
        self::assertSame($r['resumen_global']['total_citas_canceladas'],$this->n("SELECT COUNT(*) FROM bi_atenciones WHERE es_cancelada AND fecha_programada BETWEEN '2026-10-05' AND '2026-10-11'"));
        self::assertSame($r['resumen_global']['total_citas_activas'],$this->n("SELECT COUNT(*) FROM bi_atenciones WHERE NOT es_cancelada AND fecha_programada BETWEEN '2026-10-05' AND '2026-10-11'"));
    }

    public function testElServicioRealizadoUsaLaFechaEfectivaYNoAsistenciaYCancelacionSonMetricasSeparadas():void
    {
        $f=fn(string $k)=>$this->fila("SELECT * FROM bi_atenciones WHERE cita_id={$this->citas[$k]}");
        $reg=$f('atendida');self::assertSame('2026-10-05',$reg['fecha_programada']);self::assertSame('2026-10-06',$reg['fecha_servicio_realizado'],'regularizada: la fecha efectiva, no la programada');self::assertSame('1',$reg['fue_regularizada']);
        $ord=$f('miercoles');self::assertSame('2026-10-07',$ord['fecha_servicio_realizado'],'sin regularizar vale la programada');self::assertSame('0',$ord['fue_regularizada']);
        foreach(['confirmada','no_asistio','cancelada'] as $k)self::assertNull($f($k)['fecha_servicio_realizado'],"$k no es servicio realizado");
        $ban=['confirmada'=>[0,0,0,1],'atendida'=>[0,0,1,0],'no_asistio'=>[0,1,0,0],'cancelada'=>[1,0,0,0]];
        foreach($ban as $k=>[$can,$noa,$ate,$pen]){$x=$f($k);self::assertSame([$can,$noa,$ate,$pen],[(int)$x['es_cancelada'],(int)$x['es_no_asistencia'],(int)$x['es_atendida'],(int)$x['es_pendiente_de_atencion']],$k);}
        // Cada cita cae en exactamente un estado; el reporte por fecha de lo realizado coincide con la vista.
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_atenciones WHERE es_cancelada+es_no_asistencia+es_atendida+es_pendiente_de_atencion<>1"));
        $realizado=$this->reporte(['criterio_fecha'=>'realizado']);
        $enVista=$this->n("SELECT COUNT(*) FROM bi_atenciones WHERE fecha_servicio_realizado BETWEEN '2026-10-05' AND '2026-10-11'");
        self::assertSame($realizado['resumen_global']['total_citas_activas'],$enVista,'servicios realizados en la semana: reporte = vista');
        self::assertSame(3,$enVista);
    }

    public function testLosServiciosConservanElPrecioHistoricoYLaCitaQuedaConciliadaConSusServicios():void
    {
        // El servicio fue retirado del catálogo (servicioId nulo) y se conserva su precio y nombre históricos.
        $s=$this->fila("SELECT * FROM bi_atencion_servicios WHERE cita_id={$this->citas['miercoles']}");
        self::assertSame('Servicio retirado',$s['servicio_nombre']);self::assertSame('1',$s['servicio_fuera_de_catalogo']);self::assertSame('3333',$s['precio_centavos']);self::assertSame('30',$s['duracion_minutos']);
        // Cambiar el precio del catálogo no altera lo vendido.
        $this->db->query("UPDATE servicios SET precio=99.00,duracion_minutos=99 WHERE id={$this->srv1}");
        $c=$this->fila("SELECT * FROM bi_atencion_servicios WHERE cita_id={$this->citas['confirmada']} AND servicio_id={$this->srv1}");
        self::assertSame('2000',$c['precio_centavos']);self::assertSame('20',$c['duracion_minutos']);
        // Importe y cantidad de la cita = suma de sus servicios, y cada servicio aparece una vez.
        self::assertSame($this->n("SELECT COUNT(*) FROM citasservicios"),$this->n("SELECT COUNT(*) FROM bi_atencion_servicios"));
        self::assertSame($this->n("SELECT COUNT(DISTINCT cita_servicio_id) FROM bi_atencion_servicios"),$this->n("SELECT COUNT(*) FROM bi_atencion_servicios"));
        self::assertSame($this->n("SELECT COUNT(*) FROM citas"),$this->n("SELECT COUNT(DISTINCT cita_id) FROM bi_atenciones"));
        self::assertSame($this->n("SELECT COUNT(*) FROM citas"),$this->n("SELECT COUNT(*) FROM bi_atenciones"),'una fila por cita');
        self::assertSame($this->n("SELECT SUM(importe_servicios_centavos) FROM bi_atenciones"),$this->n("SELECT SUM(precio_centavos) FROM bi_atencion_servicios"));
        self::assertSame($this->n("SELECT SUM(cantidad_servicios) FROM bi_atenciones"),$this->n("SELECT COUNT(*) FROM bi_atencion_servicios"));
        // Una cita sin servicios no se pierde ni inventa minutos.
        $sola=$this->ins("INSERT INTO citas(fecha,hora,usuarioId,profesionalId,estado) VALUES('2026-10-08','10:00:00',{$this->usuario},{$this->profA},'confirmada')");
        $f=$this->fila("SELECT * FROM bi_atenciones WHERE cita_id=$sola");self::assertSame('0',$f['minutos_reservados']);self::assertSame('0',$f['cantidad_servicios']);
    }

    public function testElGranoSeConservaYLaCuadriculaCubreTodosLosProfesionalesYDias():void
    {
        $dias=$this->n("SELECT DATEDIFF(GREATEST(MAX(fecha),DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)),MIN(fecha))+1 FROM citas");
        self::assertGreaterThanOrEqual(7,$dias);
        self::assertSame(2*$dias,$this->n("SELECT COUNT(*) FROM bi_ocupacion_profesional_dia"),'una fila por profesional y día, también los días sin citas');
        self::assertSame($this->n("SELECT COUNT(*) FROM bi_ocupacion_profesional_dia"),$this->n("SELECT COUNT(DISTINCT profesional_id,fecha) FROM bi_ocupacion_profesional_dia"));
        // Todas las citas con profesional están en la cuadrícula: ningún minuto reservado se pierde.
        self::assertSame($this->n("SELECT COALESCE(SUM(minutos_reservados),0) FROM bi_atenciones WHERE profesional_id IS NOT NULL AND NOT es_cancelada"),
            $this->n("SELECT COALESCE(SUM(minutos_reservados),0) FROM bi_ocupacion_profesional_dia"));
        // Con dos horarios idénticos el turno cuenta dos veces, igual que el reporte (no hay validación en la base); no se deduplica.
        $this->db->query("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES({$this->profA},5,'09:00','12:00')");
        $r=$this->reporte();$a=0;foreach($r['resumen_profesionales'] as $p)if($p['profesional_id']===$this->profA)$a=$p['minutos_disponibles'];
        self::assertSame($a,$this->n("SELECT SUM(minutos_disponibles) FROM bi_ocupacion_profesional_dia WHERE profesional_id={$this->profA} AND fecha BETWEEN '2026-10-05' AND '2026-10-11'"));
    }

    public function testLasVistasSonDeSoloLecturaSinDatosPersonalesYElUsuarioDeBiSoloLasLee():void
    {
        $prohibidas=['usuario','cliente','nombre_cliente','email','correo','telefono','identificacion','cedula','actor','_por','nota','motivo'];
        foreach(self::VISTAS as $v){
            self::assertSame(1,$this->n("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$v' AND security_type='DEFINER'"));
            $cols=array_column($this->db->query("SELECT column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$v'")->fetch_all(MYSQLI_ASSOC),'c');
            foreach($cols as $c)foreach($prohibidas as $p)self::assertStringNotContainsString($p,strtolower($c),"$v.$c no debe exponer datos personales");
        }
        $texto=json_encode($this->db->query("SELECT * FROM bi_atenciones")->fetch_all(MYSQLI_ASSOC)).json_encode($this->db->query("SELECT * FROM bi_atencion_servicios")->fetch_all(MYSQLI_ASSOC));
        self::assertStringNotContainsString('R5',$texto);self::assertStringNotContainsString('@example.test',$texto);
        $bd=BaseDatosAislada::nombre($this->db);$usuario='bi_a_'.bin2hex(random_bytes(4));$clave='Bi'.bin2hex(random_bytes(6));
        $this->db->query("CREATE USER '$usuario'@'%' IDENTIFIED BY '$clave'");
        try{
            foreach(self::VISTAS as $v)$this->db->query("GRANT SELECT ON `$bd`.`$v` TO '$usuario'@'%'");
            mysqli_report(MYSQLI_REPORT_OFF);
            $bi=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',$usuario,$clave,$bd,(int)(getenv('DB_PORT')?:3306));
            self::assertSame(0,$bi->connect_errno);
            foreach(self::VISTAS as $v)self::assertNotFalse($bi->query("SELECT COUNT(*) FROM `$v`"),"$v legible por el usuario de BI: ".$bi->error);
            foreach(['citas','citasservicios','usuarios','profesionales','horarios_profesionales','bloqueos_profesionales'] as $t)self::assertFalse($bi->query("SELECT 1 FROM `$t` LIMIT 1"),"el usuario de BI no lee $t");
            foreach(["UPDATE bi_atenciones SET estado='x'","DELETE FROM bi_atencion_servicios","UPDATE bi_ocupacion_profesional_dia SET minutos_disponibles=0"] as $sql)self::assertFalse($bi->query($sql),$sql);
            $bi->close();
        }finally{mysqli_report(MYSQLI_REPORT_OFF);$this->db->query("DROP USER IF EXISTS '$usuario'@'%'");}
        $citas=$this->n("SELECT COUNT(*) FROM citas");mysqli_report(MYSQLI_REPORT_OFF);
        foreach(["UPDATE bi_atenciones SET estado='x'","UPDATE bi_atenciones SET profesional_id=NULL","UPDATE bi_atenciones SET fecha_programada='2000-01-01'","DELETE FROM bi_atenciones","UPDATE bi_atencion_servicios SET cita_id=cita_id","UPDATE bi_atencion_servicios SET servicio_id=NULL","UPDATE bi_atencion_servicios SET precio_centavos=0","DELETE FROM bi_atencion_servicios","UPDATE bi_ocupacion_profesional_dia SET minutos_disponibles=0","DELETE FROM bi_ocupacion_profesional_dia"] as $sql)self::assertFalse($this->db->query($sql),"vista no actualizable: $sql");
        mysqli_report(MYSQLI_REPORT_OFF);
        self::assertSame($citas,$this->n("SELECT COUNT(*) FROM citas"));
    }
}
