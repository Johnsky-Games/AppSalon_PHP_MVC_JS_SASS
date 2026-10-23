<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;

/**
 * Control del calendario (063): bi_dim_fecha genera como máximo 100.000 días; un hecho fuera de ese rango se perdería sin error. Las vistas de control
 * lo hacen visible (una fila por columna de fecha vigilada, SIEMPRE) y la prueba exige que la lista vigilada coincida con las columnas de fecha reales.
 */
final class ErpBiControlCalendarioIntegrationTest extends TestCase
{
    private const CONTROL_COMERCIAL='bi_control_calendario';
    private const CONTROL_LABORAL='bi_rh_control_calendario';
    private \mysqli $db;private int $cliente;private int $colaborador;private int $servicio;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('bicontrol');ActiveRecord::setDB($this->db);$this->cliente=R5CajaFixture::usuario($this->db);
        $this->servicio=$this->ins("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio de ensayo',10.00,30)");
        $u=R5CajaFixture::usuario($this->db,'profesional');
        $this->ins("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,'Ana','Privada','profesional','activo','2024-01-01',NOW() FROM usuarios WHERE id=$u");
        $this->colaborador=(int)$this->db->insert_id;
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function n(string $sql):int {return (int)($this->db->query($sql)->fetch_row()[0]??0);}
    private function ins(string $sql):int {self::assertTrue($this->db->query($sql)!==false,$this->db->error);return (int)$this->db->insert_id;}
    /** @return array<string,array<string,mixed>> filas de una vista de control indexadas por «vista.columna» */
    private function filas(string $vista):array
    {
        $r=[];foreach($this->db->query("SELECT * FROM `$vista` ORDER BY vista,columna")->fetch_all(MYSQLI_ASSOC) as $f)$r[$f['vista'].'.'.$f['columna']]=$f;return $r;
    }
    private function cita(string $fecha):int
    {
        $id=$this->ins("INSERT INTO citas(fecha,hora,usuarioId,estado,duracion_total_minutos) VALUES('$fecha','10:00:00',{$this->cliente},'completada',30)");
        $this->ins("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES($id,{$this->servicio},'Servicio',10.00,30)");return $id;
    }

    public function testLaListaVigiladaCoincideConLasColumnasDeFechaDeLasVistasDeHechos():void
    {
        $reales=array_column($this->db->query("SELECT CONCAT(table_name,'.',column_name) c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name LIKE 'bi\\\\_%' AND table_name NOT LIKE 'bi\\\\_dim\\\\_%' AND table_name NOT LIKE '%control\\\\_calendario' AND table_name<>'bi_rh_dim_colaborador' AND table_name<>'bi_ocupacion_profesional_dia' AND data_type IN ('date','datetime','timestamp')")->fetch_all(MYSQLI_ASSOC),'c');
        $vigiladas=array_merge(array_keys($this->filas(self::CONTROL_COMERCIAL)),array_keys($this->filas(self::CONTROL_LABORAL)));
        sort($reales);sort($vigiladas);
        self::assertSame($reales,$vigiladas,'cada columna de fecha de una vista de hechos debe estar vigilada: añadirla a bi_dim_fecha y a las vistas de control');
        self::assertCount(28,$vigiladas);
        $laborales=array_values(array_unique(array_map(static fn($k)=>explode('.',$k)[0],array_keys($this->filas(self::CONTROL_LABORAL)))));sort($laborales);
        self::assertSame(['bi_rh_asistencia','bi_rh_ausencias','bi_rh_comisiones_liquidaciones','bi_rh_comisiones_movimientos'],$laborales,'la vista laboral solo vigila hechos laborales');
        foreach(array_keys($this->filas(self::CONTROL_COMERCIAL)) as $k)self::assertStringNotContainsString('bi_rh_',$k,'la vista comercial no toca hechos laborales');
    }

    public function testSinDatosHayUnaFilaPorColumnaConCerosYTodasEstanBien():void
    {
        foreach([self::CONTROL_COMERCIAL,self::CONTROL_LABORAL] as $v){
            foreach($this->filas($v) as $k=>$f){
                self::assertSame(['0','0','ok'],[$f['filas_con_fecha'],$f['filas_sin_dia'],$f['estado']],"$k sin datos");
                self::assertNull($f['fecha_min']);
            }
        }
    }

    public function testConHechosNormalesTodasLasColumnasTienenSuDiaYElControlLoConfirma():void
    {
        $this->cita('2026-03-15');$this->cita('2026-10-05');
        $this->ins("INSERT INTO erp_ausencias(colaborador_id,desde,hasta,tipo,motivo,estado,solicitante_id,creado_en) VALUES({$this->colaborador},'2026-11-02','2026-11-06','vacaciones','Ensayo','aprobada',1,NOW())");
        $c=$this->filas(self::CONTROL_COMERCIAL);$l=$this->filas(self::CONTROL_LABORAL);
        self::assertSame(['2','2026-03-15','2026-10-05','0','ok'],[$c['bi_atenciones.fecha_programada']['filas_con_fecha'],$c['bi_atenciones.fecha_programada']['fecha_min'],$c['bi_atenciones.fecha_programada']['fecha_max'],$c['bi_atenciones.fecha_programada']['filas_sin_dia'],$c['bi_atenciones.fecha_programada']['estado']]);
        self::assertSame(['1','2026-11-02','0','ok'],[$l['bi_rh_ausencias.desde']['filas_con_fecha'],$l['bi_rh_ausencias.desde']['fecha_min'],$l['bi_rh_ausencias.desde']['filas_sin_dia'],$l['bi_rh_ausencias.desde']['estado']]);
        foreach(array_merge($c,$l) as $k=>$f)self::assertSame('ok',$f['estado'],"$k");
        self::assertSame(0,$this->n("SELECT COALESCE(SUM(filas_sin_dia),0) FROM bi_control_calendario")+$this->n("SELECT COALESCE(SUM(filas_sin_dia),0) FROM bi_rh_control_calendario"));
    }

    public function testUnHechoFueraDelTopeDeLaDimensionSeDetectaEnVezDeOcultarse():void
    {
        $this->cita('2026-03-15');
        // Una ausencia en el año 2999 (más de 100.000 días después del hecho más antiguo): la dimensión se queda corta y NO falla.
        $this->ins("INSERT INTO erp_ausencias(colaborador_id,desde,hasta,tipo,motivo,estado,solicitante_id,creado_en) VALUES({$this->colaborador},'2999-01-01','2999-12-31','vacaciones','Dato absurdo','aprobada',1,NOW())");
        self::assertLessThan('2999-12-31',$this->db->query("SELECT MAX(fecha) FROM bi_dim_fecha")->fetch_row()[0],'la dimensión se trunca en silencio');
        $l=$this->filas(self::CONTROL_LABORAL);
        self::assertSame(['1','sin_correspondencia'],[$l['bi_rh_ausencias.hasta']['filas_sin_dia'],$l['bi_rh_ausencias.hasta']['estado']],'el control laboral lo detecta');
        self::assertSame('2999-12-31',$l['bi_rh_ausencias.hasta']['fecha_max']);
        foreach($this->filas(self::CONTROL_COMERCIAL) as $k=>$f)self::assertSame('ok',$f['estado'],"el hecho comercial $k sigue cubierto");
        // Un hecho comercial fuera de tope también se marca, en la vista comercial.
        $this->cita('2998-06-01');
        $c=$this->filas(self::CONTROL_COMERCIAL);
        self::assertSame(['1','sin_correspondencia'],[$c['bi_atenciones.fecha_programada']['filas_sin_dia'],$c['bi_atenciones.fecha_programada']['estado']]);
        self::assertSame('2',$c['bi_atenciones.fecha_programada']['filas_con_fecha'],'cuenta ambas citas, una cubierta y una no');
    }

    public function testElControlEsDeSoloLecturaYLaSeparacionDeAccesoSeMantiene():void
    {
        foreach([self::CONTROL_COMERCIAL,self::CONTROL_LABORAL] as $v){
            self::assertSame(1,$this->n("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$v' AND security_type='DEFINER'"));
            $cols=array_column($this->db->query("SELECT column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$v' ORDER BY ordinal_position")->fetch_all(MYSQLI_ASSOC),'c');
            self::assertSame(['vista','columna','filas_con_fecha','fecha_min','fecha_max','filas_sin_dia','estado'],$cols,'solo agregados: ningún dato de persona');
        }
        $bd=BaseDatosAislada::nombre($this->db);
        $crear=function(string $vista)use($bd){
            $u='bi_c_'.bin2hex(random_bytes(4));$c='Bi'.bin2hex(random_bytes(6));
            $this->db->query("CREATE USER '$u'@'%' IDENTIFIED BY '$c'");$this->db->query("GRANT SELECT ON `$bd`.`$vista` TO '$u'@'%'");
            mysqli_report(MYSQLI_REPORT_OFF);$bi=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',$u,$c,$bd,(int)(getenv('DB_PORT')?:3306));self::assertSame(0,$bi->connect_errno);return [$u,$bi];
        };
        [$uCom,$com]=$crear(self::CONTROL_COMERCIAL);[$uLab,$lab]=$crear(self::CONTROL_LABORAL);
        try{
            self::assertNotFalse($com->query("SELECT COUNT(*) FROM bi_control_calendario"),$com->error);
            self::assertFalse($com->query("SELECT 1 FROM bi_rh_control_calendario LIMIT 1"),'el usuario comercial no lee el control laboral');
            self::assertNotFalse($lab->query("SELECT COUNT(*) FROM bi_rh_control_calendario"),$lab->error);
            self::assertFalse($lab->query("SELECT 1 FROM bi_control_calendario LIMIT 1"),'el usuario laboral no lee el control comercial');
            foreach(['bi_dim_fecha','citas','erp_ausencias','bi_atenciones'] as $t){self::assertFalse($com->query("SELECT 1 FROM `$t` LIMIT 1"),"no lee $t");self::assertFalse($lab->query("SELECT 1 FROM `$t` LIMIT 1"),"no lee $t");}
        }finally{mysqli_report(MYSQLI_REPORT_OFF);foreach([$uCom,$uLab] as $u)$this->db->query("DROP USER IF EXISTS '$u'@'%'");}
        mysqli_report(MYSQLI_REPORT_OFF);
        foreach(["UPDATE bi_control_calendario SET estado='ok'","DELETE FROM bi_control_calendario","UPDATE bi_rh_control_calendario SET filas_sin_dia=0","DELETE FROM bi_rh_control_calendario"] as $sql)self::assertFalse($this->db->query($sql),"vista no actualizable: $sql");
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}
