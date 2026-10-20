<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Services\Nomina\IdentificacionTributaria as I;

/** La validación es de FORMA (módulo 10 público); los números se generan aquí con su dígito verificador, no son de personas reales. */
final class IdentificacionTributariaTest extends TestCase
{
    private static function cedula(string $nueve):string
    {
        $s=0;foreach([2,1,2,1,2,1,2,1,2] as $i=>$c){$p=(int)$nueve[$i]*$c;$s+=$p>9?$p-9:$p;}
        return $nueve.((10-($s%10))%10);
    }
    public function testUnaCedulaConSuDigitoVerificadorEsValida():void
    {
        self::assertSame('1710034065',self::cedula('171003406'));// valor calculado a mano: 25 → verificador 5
        foreach(['010203040','170000001','240000007','300000003','090909090'] as $n)self::assertTrue(I::cedulaValida(self::cedula($n)),$n);
    }
    public function testSeRechazaElDigitoProvinciaYTercerDigitoIncorrectos():void
    {
        self::assertFalse(I::cedulaValida('1710034066'),'verificador incorrecto');
        self::assertFalse(I::cedulaValida(self::cedula('250000001')),'provincia 25');
        self::assertFalse(I::cedulaValida(self::cedula('000000001')),'provincia 00');
        self::assertFalse(I::cedulaValida(self::cedula('176000001')),'tercer dígito 6');
        foreach(['','171003406','17100340655','17100340a5',' 1710034065'] as $mala)self::assertFalse(I::cedulaValida($mala),'«'.$mala.'»');
    }
    public function testErrorDistingueElTipoYPasaporte():void
    {
        self::assertNull(I::error('cedula','1710034065'));self::assertNotNull(I::error('cedula','1710034060'));
        self::assertNull(I::error('pasaporte','AB1234567'));self::assertNotNull(I::error('pasaporte','AB 123'));self::assertNotNull(I::error('pasaporte','AB1'));self::assertNotNull(I::error('ruc','1710034065001'));
    }
    public function testNormalizarYEnmascarar():void
    {
        self::assertSame('AB12345',I::normalizar('pasaporte',' ab12345 '));self::assertSame('1710034065',I::normalizar('cedula',' 1710034065 '));
        self::assertSame('••••••4065',I::enmascarar('1710034065'));self::assertSame('•••',I::enmascarar('123'));
    }
}
