<?php
namespace Tests\Unit;
require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use PHPUnit\Framework\TestCase;
use Services\Fiscal\{SriComprobante,SriNotaCreditoEvidencia};
use Tests\Fixtures\Sri\Fixtures as F;

/** Medidor de desviaciones sobre el XML firmado: valores calculados a mano (34 × 15 % = 5,10 → 5; 2,000 × 0,17 = 0,34). */
final class SriNotaCreditoEvidenciaTest extends TestCase
{
    private function xml(int $iva,int $tolerancia):string
    {
        $linea=['codigo'=>'P1','descripcion'=>'Esmalte','cantidad_milesimas'=>2000,'precio_centavos'=>17,'descuento_centavos'=>0,'impuesto_pb'=>1500,'codigo_porcentaje'=>'4','base_centavos'=>34,'iva_centavos'=>$iva];
        $base=F::factura();$fecha=(new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        return SriComprobante::notaCredito(F::emisor(),['fecha'=>$fecha,'secuencial'=>'000000001','codigo_numerico'=>'12345678','comprador'=>$base['comprador'],'lineas'=>[$linea],'motivo'=>'Devolución','sustento'=>['numero'=>'001-001-000000001','fecha'=>$fecha],'tolerancia_centavos'=>$tolerancia]);
    }
    public function testUnaNotaExactaNoDesvia():void
    {
        self::assertSame(['lineas'=>1,'desviacion_iva'=>0,'desviacion_detalle'=>0,'desviacion_tarifa'=>0,'no_evaluables'=>0],SriNotaCreditoEvidencia::medir($this->xml(5,0)));
    }
    public function testUnaNotaConUnCentavoMasDeIvaSeMideComoUno():void
    {
        $m=SriNotaCreditoEvidencia::medir($this->xml(6,1));
        self::assertSame(1,$m['desviacion_iva']);self::assertSame(0,$m['desviacion_detalle']);self::assertSame(0,$m['no_evaluables']);
    }
    public function testUnXmlIlegibleOSinDetalleNoEsEvidencia():void
    {
        $this->expectException(\InvalidArgumentException::class);SriNotaCreditoEvidencia::medir('esto no es xml');
    }
    public function testUnDetalleConImportesNoDecimalesSeMarcaNoEvaluable():void
    {
        $xml='<notaCredito><detalles><detalle><cantidad>x</cantidad><precioUnitario>0.17</precioUnitario><descuento>0.00</descuento><precioTotalSinImpuesto>0.34</precioTotalSinImpuesto><impuestos><impuesto><tarifa>15.00</tarifa><baseImponible>0.34</baseImponible><valor>0.05</valor></impuesto></impuestos></detalle></detalles></notaCredito>';
        $m=SriNotaCreditoEvidencia::medir($xml);self::assertSame(1,$m['no_evaluables']);self::assertSame(0,$m['desviacion_iva']);
    }
    public function testLaSumaPorTarifaSeMideAparteDelIvaDeCadaLinea():void
    {
        // Dos líneas de 0,10 al 15 %: cada una cuadra (0,15 → 0,02 con redondeo comercial) y juntas suman 0,04 frente a 0,03 de la base total 0,20.
        $linea='<detalle><cantidad>1.000</cantidad><precioUnitario>0.10</precioUnitario><descuento>0.00</descuento><precioTotalSinImpuesto>0.10</precioTotalSinImpuesto><impuestos><impuesto><tarifa>15.00</tarifa><baseImponible>0.10</baseImponible><valor>0.02</valor></impuesto></impuestos></detalle>';
        $m=SriNotaCreditoEvidencia::medir('<notaCredito><detalles>'.$linea.$linea.'</detalles></notaCredito>');
        self::assertSame(['lineas'=>2,'desviacion_iva'=>0,'desviacion_detalle'=>0,'desviacion_tarifa'=>1,'no_evaluables'=>0],$m);
    }
}
