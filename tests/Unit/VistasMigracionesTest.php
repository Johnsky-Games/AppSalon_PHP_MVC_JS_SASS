<?php
namespace Tests\Unit;
use Classes\VistasMigraciones;
use PHPUnit\Framework\TestCase;

/** Las vistas se recrean desde las migraciones aplicadas: con una migración posterior que redefine la misma vista rige solo la última; un respaldo histórico recupera la de su época. */
final class VistasMigracionesTest extends TestCase
{
    private string $dir;
    protected function setUp():void
    {
        $this->dir=sys_get_temp_dir().'/vistas_mig_'.bin2hex(random_bytes(4));mkdir($this->dir);
        file_put_contents($this->dir.'/001_a.sql',"-- comentario; con punto y coma\nCREATE TABLE t(id INT);\nCREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_x AS\nSELECT DISTINCT id AS v1 FROM t;\n");
        file_put_contents($this->dir.'/002_b.sql',"CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_x AS\nSELECT DISTINCT id AS v2 FROM t;\nCREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_y AS\nSELECT DISTINCT id AS y1 FROM t;\n");
        file_put_contents($this->dir.'/002_b_rollback.sql',"CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_x AS SELECT 1;\n");
    }

    public function testUnRespaldoHistoricoConSoloLaPrimeraMigracionRecuperaSuDefinicion():void
    {
        $s=VistasMigraciones::sentencias($this->dir,['001_a'=>true]);
        self::assertCount(1,$s);self::assertStringContainsString('v1',$s[0]);self::assertStringNotContainsString('v2',$s[0]);
    }
    public function testConAmbasAplicadasRigeSoloLaUltimaDefinicionDeCadaVista():void
    {
        $s=VistasMigraciones::sentencias($this->dir,['001_a'=>true,'002_b'=>true]);
        self::assertCount(2,$s,'bi_x una sola vez (la de 002) y bi_y');
        self::assertStringContainsString('v2',$s[0]);self::assertStringContainsString('y1',$s[1]);
        foreach($s as $sql)self::assertStringNotContainsString('v1',$sql,'la definición antigua puede apuntar a una columna que ya no existe');
    }
    public function testUnaMigracionPosteriorSinLaAnteriorAplicadaSoloAportaLasSuyas():void
    {
        $s=VistasMigraciones::sentencias($this->dir,['002_b'=>true]);
        self::assertCount(2,$s);self::assertStringContainsString('v2',$s[0]);
    }
    public function testSinMigracionesAplicadasNoSeRecreaNada_YLosRollbackNoSeLeen():void
    {
        self::assertSame([],VistasMigraciones::sentencias($this->dir,[]));
        self::assertSame(['bi_x','bi_y'],VistasMigraciones::nombres($this->dir),'el rollback no aporta definiciones');
    }
    public function testElPatronDelRepositorioEncuentraLasVistasRealesDe057():void
    {
        $n=VistasMigraciones::nombres(__DIR__.'/../../database/migrations');
        foreach(['bi_venta_lineas','bi_libro_lineas','bi_inventario_movimientos'] as $v)self::assertContains($v,$n);
    }

    private function aparte(array $archivos):string
    {
        $d=sys_get_temp_dir().'/vistas_mig_'.bin2hex(random_bytes(4));mkdir($d);
        foreach($archivos as $n=>$c)file_put_contents($d.'/'.$n,$c);
        $this->extra[]=$d;return $d;
    }
    /** @var list<string> */ private array $extra=[];
    protected function tearDown():void {foreach(array_merge([$this->dir],$this->extra) as $d){foreach(glob($d.'/*')?:[] as $f)unlink($f);rmdir($d);}}
    private static function v(string $n,string $cuerpo):string {return "CREATE OR REPLACE SQL SECURITY DEFINER VIEW $n AS\nSELECT DISTINCT $cuerpo;\n";}

    public function testUnaVistaDependienteSeCreaDespuesDeLaQueUsaAunqueUnaMigracionPosteriorRedefinaLaBase():void
    {
        $d=$this->aparte(['001_a.sql'=>self::v('bi_a','id AS v1 FROM t').self::v('bi_b','v1 FROM bi_a'),'002_b.sql'=>self::v('bi_a','id AS v2 FROM t')]);
        $s=VistasMigraciones::sentencias($d,['001_a'=>true,'002_b'=>true]);
        self::assertCount(2,$s);
        self::assertStringContainsString('VIEW bi_a AS',$s[0]);self::assertStringContainsString('v2',$s[0],'primero la base, con su definición vigente');
        self::assertStringContainsString('VIEW bi_b AS',$s[1]);
    }
    public function testUnaMigracionQueRetiraUnaVistaLaExcluyeYUnaPosteriorPuedeRecrearla():void
    {
        $d=$this->aparte(['001_a.sql'=>self::v('bi_x','id AS x1 FROM t').self::v('bi_y','id AS y1 FROM t'),'002_b.sql'=>"DROP VIEW IF EXISTS bi_x;\n",'003_c.sql'=>self::v('bi_x','id AS x3 FROM t')]);
        $conRetiro=VistasMigraciones::sentencias($d,['001_a'=>true,'002_b'=>true]);
        self::assertCount(1,$conRetiro);self::assertStringContainsString('VIEW bi_y AS',$conRetiro[0],'la retirada no se vuelve a crear');
        self::assertCount(2,VistasMigraciones::sentencias($d,['001_a'=>true]),'un respaldo anterior al retiro conserva la vista de su época');
        $recreada=VistasMigraciones::sentencias($d,['001_a'=>true,'002_b'=>true,'003_c'=>true]);
        self::assertCount(2,$recreada);self::assertStringContainsString('x3',implode("\n",$recreada));self::assertStringNotContainsString('x1',implode("\n",$recreada));
        self::assertContains('bi_x',VistasMigraciones::nombres($d),'el nombre sigue siendo conocido para validar manifiestos históricos');
    }
    public function testLaDependenciaCircularEntreVistasSeRechazaConUnErrorClaro():void
    {
        $d=$this->aparte(['001_a.sql'=>self::v('bi_p','v FROM bi_q').self::v('bi_q','v FROM bi_p')]);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Dependencia circular');
        VistasMigraciones::sentencias($d,['001_a'=>true]);
    }
}
