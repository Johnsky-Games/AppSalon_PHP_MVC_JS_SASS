<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Services\{ErpCalculo,ErpException};
use Services\Fiscal\SriNotaCreditoCalculo as C;

/** Reparto acumulado del redondeo entre las notas de crédito de una línea (hallazgo P1 de la revisión de #10). */
final class SriNotaCreditoCalculoTest extends TestCase
{
    private static function linea(int $cantidad,int $precio,int $descuento,int $pb):array {return ['cantidad'=>$cantidad,'precio'=>$precio,'descuento'=>$descuento,'impuesto_pb'=>$pb];}

    /** Acredita la línea en las porciones dadas llevando las sumas reales, como hace el servicio. */
    private static function acreditar(array $l,array $porciones):array
    {
        $prev=0;$tot=0;$base=0;$iva=0;$desc=0;$notas=[];
        foreach($porciones as $q){
            $r=C::linea($l,$prev,$q,$tot,['base'=>$base,'iva'=>$iva]);
            $prev+=$q;$tot+=$r['total'];$base+=$r['base'];$iva+=$r['iva'];$desc+=$r['descuento'];$notas[]=$r;
        }
        return ['cantidad'=>$prev,'total'=>$tot,'base'=>$base,'iva'=>$iva,'descuento'=>$desc,'notas'=>$notas];
    }
    private static function original(array $l):array {$o=ErpCalculo::linea($l['cantidad'],$l['precio'],$l['descuento'],$l['impuesto_pb']);return ['total'=>$o['total'],'base'=>$o['subtotal']-$l['descuento'],'iva'=>$o['impuesto'],'descuento'=>$l['descuento']];}

    public function testCienUnidadesA101CentavosConIva15DevueltasDeAUnaCierranExactoSinRemanente():void
    {
        $l=self::linea(100000,101,0,1500);$r=self::acreditar($l,array_fill(0,100,1000));
        self::assertSame(self::original($l),['total'=>$r['total'],'base'=>$r['base'],'iva'=>$r['iva'],'descuento'=>$r['descuento']]);
        self::assertSame([11615,10100,1515],[$r['total'],$r['base'],$r['iva']],'Antes: 11600 y 15 centavos de IVA inalcanzables con la línea marcada como completa');
    }
    public function testCienUnidadesA4CentavosPermitenDevolverLasCienYNoExcedenLoFacturado():void
    {
        $l=self::linea(100000,4,0,1500);$r=self::acreditar($l,array_fill(0,100,1000));
        self::assertCount(100,$r['notas'],'Antes: la nota 93 se rechazaba por «ya acreditada por completo»');
        self::assertSame([460,400,60],[$r['total'],$r['base'],$r['iva']]);
        $acum=0;foreach($r['notas'] as $n){$acum+=$n['total'];self::assertLessThanOrEqual(460,$acum);}
    }
    public function testElDescuentoNuncaSeAjustaParaCuadrarElIva():void
    {
        // 30 u × 17 c con IVA 5 %: antes la base quedaba en 506 de 510 y el IVA en 30 de 26.
        $l=self::linea(30000,17,0,500);$r=self::acreditar($l,array_fill(0,30,1000));
        self::assertSame([536,510,26,0],[$r['total'],$r['base'],$r['iva'],$r['descuento']]);
        $conDescuento=self::linea(7333,1234,250,1200);$r=self::acreditar($conDescuento,[2000,2333,3000]);
        self::assertSame(self::original($conDescuento),['total'=>$r['total'],'base'=>$r['base'],'iva'=>$r['iva'],'descuento'=>$r['descuento']],'El descuento acreditado suma exactamente el facturado, ni más ni menos');
    }
    /** @dataProvider tarifas */
    public function testTodasLasTarifasCierranExacto(int $pb):void
    {
        $l=self::linea(30000,17,0,$pb);$r=self::acreditar($l,array_fill(0,30,1000));
        self::assertSame(self::original($l),['total'=>$r['total'],'base'=>$r['base'],'iva'=>$r['iva'],'descuento'=>$r['descuento']]);
    }
    public static function tarifas():array {return array_map(fn($pb)=>[$pb],[0,500,800,1200,1300,1400,1500]);}
    public function testCantidadesFraccionariasCierranExacto():void
    {
        $l=self::linea(2500,333,0,1500);$r=self::acreditar($l,[750,1000,750]);
        self::assertSame(self::original($l),['total'=>$r['total'],'base'=>$r['base'],'iva'=>$r['iva'],'descuento'=>$r['descuento']],'Antes: 957 de 958');
    }
    public function testLaDesviacionPorDocumentoEsMedidaYNuncaSuperaUnCentavo():void
    {
        $l=self::linea(30000,17,0,1500);$max=0;$conDesviacion=0;
        foreach(self::acreditar($l,array_fill(0,30,1000))['notas'] as $n){
            self::assertSame($n['base']+$n['descuento'],$n['bruto']);
            $max=max($max,abs($n['desviacion_iva']),abs($n['desviacion_detalle']));$conDesviacion+=($n['desviacion_iva']!==0)?1:0;
        }
        self::assertSame(1,$max);self::assertGreaterThan(0,$conDesviacion,'El redondeo no es aditivo: existen notas cuyo IVA difiere en un centavo de base × tarifa');
    }
    public function testPrimeraNotaDeUnaLineaCuadraExactoConSuPropioDetalle():void
    {
        $r=C::linea(self::linea(30000,17,0,1500),0,1000,0);
        self::assertSame([0,0],[$r['desviacion_iva'],$r['desviacion_detalle']]);
    }
    public function testBarridoDeterministaNoExcedeNiDejaRemanenteNiSuperaUnCentavoDeDesviacion():void
    {
        mt_srand(20261020);$tarifas=[0,500,800,1200,1300,1400,1500];$notas=0;
        for($i=0;$i<4000;$i++){
            $Q=mt_rand(1,60)*(mt_rand(0,4)===0?500:1000);$p=mt_rand(50,99999);$pb=$tarifas[mt_rand(0,6)];
            $bruto=intdiv($Q*$p+500,1000);$D=mt_rand(0,3)===0?mt_rand(0,$bruto):0;$l=self::linea($Q,$p,$D,$pb);$o=self::original($l);
            $k=mt_rand(1,max(1,min(12,intdiv($Q,500))));$cortes=[0,$Q];for($j=1;$j<$k;$j++)$cortes[]=mt_rand(1,max(1,intdiv($Q,500)-1))*500;sort($cortes);$cortes=array_values(array_unique($cortes));
            $prev=0;$tot=0;$b=0;$iva=0;$d=0;
            for($j=1;$j<count($cortes);$j++){
                $q=$cortes[$j]-$cortes[$j-1];$r=C::linea($l,$prev,$q,$tot,['base'=>$b,'iva'=>$iva]);$notas++;
                $prev+=$q;$tot+=$r['total'];$b+=$r['base'];$iva+=$r['iva'];$d+=$r['descuento'];
                self::assertLessThanOrEqual(1,max(abs($r['desviacion_iva']),abs($r['desviacion_detalle'])),"Q=$Q p=$p D=$D pb=$pb");
                self::assertTrue($tot<=$o['total']&&$b<=$o['base']&&$iva<=$o['iva']&&$d<=$o['descuento'],"Excede lo facturado: Q=$Q p=$p D=$D pb=$pb");
            }
            self::assertSame($o,['total'=>$tot,'base'=>$b,'iva'=>$iva,'descuento'=>$d],"Remanente: Q=$Q p=$p D=$D pb=$pb");
        }
        self::assertGreaterThan(10000,$notas);
    }
    public function testRechazaSuperarCantidadOTotalYUnImporteNulo():void
    {
        $l=self::linea(2000,1000,0,1500);
        try{C::linea($l,1000,1500,1150);self::fail('Supera la cantidad');}catch(ErpException $e){self::assertSame(409,$e->getCode());}
        try{C::linea(self::linea(30000,1,0,0),0,1,0);self::fail('Importe cero');}catch(ErpException $e){self::assertStringContainsString('positivo',$e->getMessage());}
    }

    public function testLasNotasDelRepartoAnteriorSeDetectanYNoSeCierranEnSilencio():void
    {
        // Notas emitidas con el cálculo anterior: cada una redondeada por separado (101 de base y 15 de IVA = 116 por unidad).
        $l=self::linea(100000,101,0,1500);$nota=['cantidad'=>1000,'base'=>101,'iva'=>15];
        $a=C::auditar($l,array_fill(0,99,$nota));
        self::assertSame(['desviada',0,-15],[$a['estado'],$a['dif_base'],$a['dif_iva']],'IVA acumulado de 1485 frente a 1500 del objetivo');
        // Cerrar la línea absorbería los 15 centavos en una sola nota que se desvía 15 de su propio detalle: el servicio la bloquea (la tolerancia máxima es 2).
        $cierre=C::linea($l,99000,1000,99*116,['base'=>9999,'iva'=>1485]);
        self::assertSame([101,30,131,15],[$cierre['base'],$cierre['iva'],$cierre['total'],$cierre['desviacion_iva']]);
        $completa=C::auditar($l,array_fill(0,100,$nota));
        self::assertSame(['con_remanente',-15],[$completa['estado'],$completa['dif_total']],'La línea figura completa con 15 centavos de IVA que ya no se pueden acreditar');
        // 100 u × 4 c: el cálculo anterior agotó el total (460) a las 92 unidades y llegó a acreditar más IVA del facturado.
        $b=C::auditar(self::linea(100000,4,0,1500),array_fill(0,92,['cantidad'=>1000,'base'=>4,'iva'=>1]));
        self::assertSame('excedida',$b['estado']);
        // Bajo el reparto actual todo queda conforme.
        $r=self::acreditar($l,array_fill(0,100,1000));
        self::assertSame('conforme',C::auditar($l,array_map(fn($n)=>['cantidad'=>$n['cantidad'],'base'=>$n['base'],'iva'=>$n['iva']],$r['notas']))['estado']);
    }
}
