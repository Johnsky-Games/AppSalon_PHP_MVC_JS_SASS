<?php
namespace Tests\Unit;
require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use PHPUnit\Framework\TestCase;
use Services\Fiscal\{SriAutorizacion,SriComprobante,SriFirmaXades,SriRide,SriXml};
use Tests\Fixtures\Sri\Fixtures as F;

final class SriRideTest extends TestCase
{
    private function contenedor(?array $factura=null, ?array $emisor=null):string
    {
        [$p,$password]=F::certificado();$xml=SriFirmaXades::firmar(SriComprobante::factura($emisor??F::emisor(),$factura??F::factura()),$p,$password);
        $clave=SriXml::cargar($xml)->getElementsByTagName('claveAcceso')->item(0)->textContent;
        return SriAutorizacion::exportar(['estado'=>'AUTORIZADO','autorizado'=>true,'clave'=>$clave,'numero_autorizacion'=>$clave,'fecha_autorizacion'=>'2026-10-19T10:30:00-05:00','ambiente'=>'PRUEBAS','xml'=>$xml,'mensajes'=>[]],$xml);
    }
    public function testConservaDecimalesIdentidadesDescuentoImpuestoYFormaPactada():void
    {
        $r=SriRide::datos($this->contenedor());
        self::assertSame('Salón de ensayo & compañía',$r['emisor']['razonSocial']);
        self::assertSame('Cliente de ensayo',$r['factura']['razonSocialComprador']);
        self::assertSame('20.00',$r['factura']['totalSinImpuestos']);self::assertSame('10.00',$r['factura']['totalDescuento']);self::assertSame('23.00',$r['factura']['importeTotal']);
        self::assertSame('1.000',$r['lineas'][0]['cantidad']);self::assertSame('30.00',$r['lineas'][0]['precioUnitario']);self::assertSame('Servicio de prueba & cuidado',$r['lineas'][0]['descripcion']);
        self::assertSame([['etiqueta'=>'IVA 15.00%','base'=>'20.00','valor'=>'3.00']],$r['impuestos']);
        self::assertSame('01',$r['pagos'][0]['codigo']);self::assertSame('23.00',$r['pagos'][0]['total']);
        self::assertSame($r['emisor']['claveAcceso'],$r['autorizacion']['numero']);
    }
    public function testNoConfundeTarifaCeroExentoYNoObjetoNiPierdeRegimen():void
    {
        $f=F::factura();$f['lineas']=[];foreach(['0','6','7'] as $codigo)$f['lineas'][]=['codigo'=>'IVA'.$codigo,'descripcion'=>'Categoría '.$codigo,'cantidad_milesimas'=>1000,'precio_centavos'=>100,'impuesto_pb'=>0,'codigo_porcentaje'=>$codigo];
        $f['pagos']=[['forma'=>'20','total_centavos'=>300,'plazo_dias'=>30]];$f['total_centavos']=300;
        $e=F::emisor()+['nombre_comercial'=>'Nombre comercial','dir_establecimiento'=>'Sucursal','agente_retencion'=>'1','contribuyente_especial'=>'123','contribuyente_rimpe'=>'CONTRIBUYENTE RÉGIMEN RIMPE'];
        $r=SriRide::datos($this->contenedor($f,$e));
        self::assertSame(['IVA 0%','No objeto de IVA','Exento de IVA'],array_column($r['impuestos'],'etiqueta'));self::assertSame('30',$r['pagos'][0]['plazo']);self::assertSame('dias',$r['pagos'][0]['unidad']);
        self::assertSame($e['contribuyente_rimpe'],$r['emisor']['contribuyenteRimpe']);self::assertSame('123',$r['factura']['contribuyenteEspecial']);self::assertSame('Sucursal',$r['factura']['dirEstablecimiento']);
    }
    public function testNoAceptaRepresentarRecepcionComoAutorizacion():void
    {
        $xml=str_replace('<estado>AUTORIZADO</estado>','<estado>RECIBIDA</estado>',$this->contenedor());$this->expectException(\InvalidArgumentException::class);SriRide::datos($xml);
    }
}
