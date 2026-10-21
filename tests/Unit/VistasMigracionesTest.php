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
    protected function tearDown():void {foreach(glob($this->dir.'/*')?:[] as $f)unlink($f);rmdir($this->dir);}

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
}
