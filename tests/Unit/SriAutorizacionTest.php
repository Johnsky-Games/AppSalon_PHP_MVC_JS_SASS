<?php
namespace Tests\Unit;

require_once __DIR__.'/../fixtures/sri/Fixtures.php';
use PHPUnit\Framework\TestCase;
use Services\Fiscal\{SriAutorizacion, SriComprobante, SriFirmaXades, SriXml};
use Tests\Fixtures\Sri\Fixtures as F;

final class SriAutorizacionTest extends TestCase
{
    private function evidencia(bool $cdata = false): array
    {
        [$p12, $password] = F::certificado();
        $xml = SriComprobante::factura(F::emisor(), F::factura());
        if ($cdata) $xml = str_replace('Servicio de prueba &amp; cuidado', '<![CDATA[Servicio de prueba & cuidado]]>', $xml);
        $xml = SriFirmaXades::firmar($xml, $p12, $password);
        $clave = SriXml::cargar($xml)->getElementsByTagName('claveAcceso')->item(0)->textContent;
        return ['estado'=>'AUTORIZADO', 'autorizado'=>true, 'clave'=>$clave, 'numero_autorizacion'=>$clave,
            'fecha_autorizacion'=>'2026-10-19T10:30:00.997-05:00', 'ambiente'=>'PRUEBAS', 'xml'=>$xml,
            'mensajes'=>[['identificador'=>'60', 'mensaje'=>'Prueba & certificación', 'tipo'=>'ADVERTENCIA']]];
    }

    /** @dataProvider tiposXml */
    public function testExportaLaEvidenciaSinAlterarElComprobanteNiSuFirma(bool $cdata): void
    {
        $r = $this->evidencia($cdata);
        if ($cdata) self::assertStringContainsString(']]>', $r['xml']);
        $d = SriXml::cargar(SriAutorizacion::exportar($r, $r['xml']));
        self::assertSame('autorizacion', $d->documentElement->tagName);
        self::assertSame($r['numero_autorizacion'], $d->getElementsByTagName('numeroAutorizacion')->item(0)->textContent);
        self::assertSame($r['fecha_autorizacion'], $d->getElementsByTagName('fechaAutorizacion')->item(0)->textContent);
        $xml = $d->getElementsByTagName('comprobante')->item(0)->textContent;
        self::assertSame($r['xml'], $xml);
        self::assertTrue(F::verificar($xml));
        self::assertSame('Prueba & certificación', $d->getElementsByTagName('mensaje')->item(0)->getElementsByTagName('mensaje')->item(0)->textContent);
    }
    public static function tiposXml(): array { return [[false], [true]]; }

    /** @dataProvider evidenciasInvalidas */
    public function testRechazaAutorizacionIncompletaAjenaOAlterada(string $campo, mixed $valor): void
    {
        $r = $this->evidencia(); $esperado = $r['xml'];
        $r[$campo] = $valor === 'alterar-xml' ? str_replace('Servicio de prueba &amp; cuidado', 'Concepto alterado', $r['xml']) : $valor;
        $this->expectException(\RuntimeException::class);
        SriAutorizacion::exportar($r, $esperado);
    }
    public static function evidenciasInvalidas(): array
    {
        return [
            ['estado','RECIBIDA'], ['autorizado',false], ['autorizado','true'], ['clave',str_repeat('0',49)],
            ['numero_autorizacion',str_repeat('0',49)], ['ambiente','PRODUCCIÓN'], ['xml',null], ['xml','alterar-xml'],
            ['fecha_autorizacion','2026-02-30T10:30:00-05:00'], ['fecha_autorizacion','2026-10-19T24:00:00-05:00'],
            ['fecha_autorizacion','2026-10-19T10:30:00+99:99'], ['fecha_autorizacion','2026-10-19T10:30:00'],
        ];
    }
}
