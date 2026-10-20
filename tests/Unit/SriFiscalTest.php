<?php
namespace Tests\Unit;
require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use PHPUnit\Framework\TestCase;
use Services\Fiscal\{SriClaveAcceso,SriComprobante,SriXml,SriFirmaXades};
use Tests\Fixtures\Sri\Fixtures as F;

final class SriFiscalTest extends TestCase
{
    public function testEstructuraDe49DigitosYVectorOficialDeModuloOnce():void
    {
        self::assertSame(6,SriClaveAcceso::digito('41261533'));
        self::assertSame('0403201301176815353000110015010000000081234567810',SriClaveAcceso::crear('2013-03-04','01','1768153530001','1','001501','000000008','12345678'));
        SriClaveAcceso::validar('0403201301176815353000110015010000000081234567810');
        $this->expectException(\InvalidArgumentException::class);SriClaveAcceso::validar('0403201301176815353000110015010000000081234567817');
    }
    /** @dataProvider clavesMalas */
    public function testNoNormalizaClavesMalFormadas(array $args):void {$this->expectException(\InvalidArgumentException::class);SriClaveAcceso::crear(...$args);}
    public static function clavesMalas():array
    {return [[['2026-02-30','01','1790012345001','1','001002','000000001','12345678']],[['2026-02-28','01','1790012345001','3','001002','000000001','12345678']],[['2026-02-28','01','1790012345001','1','001002','1','12345678']],[['2026-02-28','01','1790012345001','1','001002','000000000','12345678']],[['2026-02-28','01','1790012345001','1','000002','000000001','12345678']]];}
    public function testFacturaXsdOficialEscapaTextoYConservaPrecioDescuentoEImpuesto():void
    {
        $doc=SriXml::validar(SriComprobante::factura(F::emisor(),F::factura()));$x=new \DOMXPath($doc);
        self::assertSame('Salón de ensayo & compañía',$x->evaluate('string(/factura/infoTributaria/razonSocial)'));
        foreach(['totalSinImpuestos'=>'20.00','totalDescuento'=>'10.00','importeTotal'=>'23.00'] as $campo=>$valor)self::assertSame($valor,$x->evaluate('string(/factura/infoFactura/'.$campo.')'));
        self::assertSame('15.00',$x->evaluate('string(//detalle/impuestos/impuesto/tarifa)'));self::assertSame('3.00',$x->evaluate('string(//detalle/impuestos/impuesto/valor)'));
    }
    public function testMezclaDeIvaYCantidadesFraccionariasConRedondeoPorLinea():void
    {
        $d=F::factura();$d['lineas'][]=['codigo'=>'PROD-2','descripcion'=>'Producto exento','cantidad_milesimas'=>1250,'precio_centavos'=>101,'impuesto_pb'=>0,'codigo_porcentaje'=>'7'];$d['total_centavos']=2426;$d['pagos']=[['forma'=>'01','total_centavos'=>1000],['forma'=>'20','total_centavos'=>1426]];
        $doc=SriXml::validar(SriComprobante::factura(F::emisor(),$d));$x=new \DOMXPath($doc);self::assertSame('24.26',$x->evaluate('string(//infoFactura/importeTotal)'));self::assertSame('1.250',$x->evaluate('string(//detalle[2]/cantidad)'));self::assertSame(2,$x->query('//infoFactura/totalConImpuestos/totalImpuesto')->length);
    }
    public function testNotaCreditoMantieneFacturaDeSustento():void
    {
        $d=F::factura();$d['sustento']=['numero'=>'001-002-000000001','fecha'=>$d['fecha']];$d['motivo']='Devolución de servicio';$doc=SriXml::validar(SriComprobante::notaCredito(F::emisor(),$d));$x=new \DOMXPath($doc);
        self::assertSame('04',$x->evaluate('string(//infoTributaria/codDoc)'));self::assertSame('23.00',$x->evaluate('string(//infoNotaCredito/valorModificacion)'));self::assertSame('001-002-000000001',$x->evaluate('string(//numDocModificado)'));
    }
    /** @dataProvider datosMalos */
    public function testNoEmiteImportesIdentidadesNiCategoriasInconsistentes(string $caso):void
    {
        $d=F::factura();switch($caso){case 'pago':$d['pagos'][0]['total_centavos']=2299;break;case 'total':$d['total_centavos']=2301;break;case 'categoria':$d['lineas'][0]['codigo_porcentaje']='2';break;case 'decimal':$d['lineas'][0]['precio_centavos']=30.00;break;case 'identidad':$d['comprador']['identificacion']='17123';break;case 'anonimo':$d['comprador']=['tipo'=>'07','identificacion'=>'9999999999999','nombre'=>'CONSUMIDOR FINAL'];break;case 'descuento':$d['lineas'][0]['descuento_centavos']=3001;break;}
        $this->expectException(\Throwable::class);SriComprobante::factura(F::emisor(),$d);
    }
    public static function datosMalos():array {return array_map(fn($c)=>[$c],['pago','total','categoria','decimal','identidad','anonimo','descuento']);}
    public function testRechazaEntidadesExternasYDocumentoConClaveAjena():void
    {
        try{SriXml::cargar('<!DOCTYPE factura [<!ENTITY x SYSTEM "file:///etc/passwd">]><factura>&x;</factura>');self::fail('XXE');}catch(\InvalidArgumentException $e){self::assertSame('XML no permitido.',$e->getMessage());}
        $xml=str_replace('<ambiente>1</ambiente>','<ambiente>2</ambiente>',SriComprobante::factura(F::emisor(),F::factura()));$this->expectException(\InvalidArgumentException::class);SriXml::validar($xml);
    }
    public function testFirmaXadesCubreDocumentoPropiedadesYCertificado():void
    {
        [$p12,$pwd]=F::certificado();$firmado=SriFirmaXades::firmar(SriComprobante::factura(F::emisor(),F::factura()),$p12,$pwd);self::assertTrue(F::verificar($firmado));
        foreach(['<importeTotal>23.00</importeTotal>'=>'<importeTotal>24.00</importeTotal>','contenido comprobante'=>'contenido adulterado','<ds:X509SerialNumber>123</ds:X509SerialNumber>'=>'<ds:X509SerialNumber>124</ds:X509SerialNumber>'] as $a=>$b){self::assertStringContainsString($a,$firmado);self::assertFalse(F::verificar(str_replace($a,$b,$firmado)));}
        $this->expectException(\InvalidArgumentException::class);SriFirmaXades::firmar($firmado,$p12,$pwd);
    }
    public function testFirmaNotaCreditoYRechazaClaveIncorrectaYCertificadoVencido():void
    {
        $d=F::factura();$d['sustento']=['numero'=>'001-002-000000001','fecha'=>$d['fecha']];$d['motivo']='Ensayo';[$p12,$pwd]=F::certificado();self::assertTrue(F::verificar(SriFirmaXades::firmar(SriComprobante::notaCredito(F::emisor(),$d),$p12,$pwd)));
        try{SriFirmaXades::firmar(SriComprobante::factura(F::emisor(),F::factura()),$p12,'incorrecta');self::fail('Clave');}catch(\InvalidArgumentException $e){self::assertStringContainsString('PKCS12',$e->getMessage());}
        $this->expectException(\InvalidArgumentException::class);SriFirmaXades::firmar(SriComprobante::factura(F::emisor(),F::factura()),$p12,$pwd,new \DateTimeImmutable('+3 days'));
    }
}
