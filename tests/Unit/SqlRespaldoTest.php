<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Classes\SqlRespaldo;
final class SqlRespaldoTest extends TestCase
{
    public function testCargaGrandeNoOcultaEstructuraExternaNiActivaLimitesDeRegex():void
    {
        $sql="INSERT INTO `correos_salida` VALUES(1,'".str_repeat('ABCdef1234567890+/',100000)."', 'USE otra; /* texto */', \"doble\"); USE otra";
        self::assertSame("INSERT INTO `correos_salida` VALUES(1,'', '', ''); USE otra",SqlRespaldo::estructura($sql));
    }
    public function testDistingueEscapesComillasDoblesYBackticks():void
    {
        $sql=<<<'SQL'
INSERT INTO `a``b` VALUES('dijo ''hola''','barra\'seguida', "doble""comilla")
SQL;
        self::assertSame("INSERT INTO `a``b` VALUES('','', '')",SqlRespaldo::estructura($sql));
    }
    public function testLiteralAbiertoNoSePuedeEnmascararComoRespaldoValido():void
    {
        $this->expectException(\RuntimeException::class);SqlRespaldo::estructura("INSERT INTO t VALUES('sin fin");
    }
}
