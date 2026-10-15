<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Services\CobroCalculo;

/** Aritmética del cobro en centavos enteros y reglas de saldo/estado (funciones puras). */
final class CobroCalculoTest extends TestCase
{
    /** @dataProvider decimales */
    public function testCentavosDesdeDecimalDeBaseDeDatos(mixed $entrada, int $esperado): void
    {
        $this->assertSame($esperado, CobroCalculo::centavos($entrada));
    }

    public static function decimales(): array
    {
        return [['15.25', 1525], ['15.2', 1520], ['15', 1500], ['0.07', 7], ['0.00', 0], [15.25, 1525], [0.1 + 0.2, 30], ['19.99', 1999], ['9999.99', 999999], [3, 300], ['15.250', 1525]];
    }

    public function testSumaDeVariosPreciosNoAcumulaErrorDeFloat(): void
    {
        $total = 0; foreach (array_fill(0, 100, '0.10') as $p) { $total += CobroCalculo::centavos($p); }
        $this->assertSame(1000, $total);
    }

    /** @dataProvider importesInvalidos */
    public function testImportesEscritosPorPersonasInvalidos(mixed $v): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CobroCalculo::centavosEntrada($v);
    }

    public static function importesInvalidos(): array
    {
        return [[''], ['0'], ['0.00'], ['-1'], ['+1'], ['1e2'], ['1,50'], ['1.505'], ['.5'], ['5.'], [' '], ['abc'], ['١٢'], ['99999999'], [null], [[]], [1.5], ['10 USD']];
    }

    public function testImportesValidos(): void
    {
        $this->assertSame(1250, CobroCalculo::centavosEntrada('12.5'));
        $this->assertSame(1, CobroCalculo::centavosEntrada('0.01'));
        $this->assertSame(1000000, CobroCalculo::centavosEntrada(' 10000.00 '));
        $this->assertSame(500, CobroCalculo::centavosEntrada(5));
        $this->assertSame(1000, CobroCalculo::centavosEntrada("10\n"), 'los espacios laterales se recortan');
    }

    public function testFormatoYPlano(): void
    {
        $this->assertSame('1,234.50', CobroCalculo::formato(123450));
        $this->assertSame('0.05', CobroCalculo::formato(5));
        $this->assertSame('-3.07', CobroCalculo::formato(-307));
        $this->assertSame('1234.50', CobroCalculo::plano(123450));
        $this->assertSame('0.00', CobroCalculo::plano(0));
    }

    public function testSaldoYDisponible(): void
    {
        $this->assertSame(3550, CobroCalculo::saldo(3550, 0, 0));
        $this->assertSame(2550, CobroCalculo::saldo(3550, 1000, 0));
        $this->assertSame(3550, CobroCalculo::saldo(3550, 1000, 1000), 'lo devuelto vuelve a ser saldo');
        $this->assertSame(0, CobroCalculo::disponible(3550, 3550, 0, 0));
        $this->assertSame(1550, CobroCalculo::disponible(3550, 1000, 0, 1000), 'lo declarado por transferencia reserva saldo');
        $this->assertSame(0, CobroCalculo::disponible(1000, 0, 0, 5000), 'nunca negativo');
    }

    /** @dataProvider estados */
    public function testEstadoFinanciero(int $total, int $cobrado, int $devuelto, int $pendiente, string $esperado): void
    {
        $this->assertSame($esperado, CobroCalculo::estado($total, $cobrado, $devuelto, $pendiente));
        $this->assertContains($esperado, CobroCalculo::ESTADOS);
    }

    public static function estados(): array
    {
        return [
            'nada' => [3550, 0, 0, 0, 'pendiente'], 'anticipo' => [3550, 1000, 0, 0, 'parcial'], 'pagado exacto' => [3550, 3550, 0, 0, 'pagado'],
            'transferencia declarada' => [3550, 0, 0, 3550, 'por_verificar'], 'parcial y declarada' => [3550, 1000, 0, 2550, 'por_verificar'],
            'todo devuelto' => [3550, 3550, 3550, 0, 'reembolsado'], 'devolución parcial' => [3550, 3550, 1000, 0, 'parcial'],
            'devuelto y nueva declarada' => [3550, 1000, 1000, 500, 'por_verificar'], 'cita sin importe' => [0, 0, 0, 0, 'pendiente'],
            'pagado gana a pendiente' => [1000, 1000, 0, 500, 'pagado'], 'anticipo devuelto' => [3550, 1000, 1000, 0, 'reembolsado'],
        ];
    }

    public function testCambioDelEfectivo(): void
    {
        $this->assertSame(1000, CobroCalculo::cambio(2000, 1000));
        $this->assertSame(0, CobroCalculo::cambio(1000, 1000));
        $this->expectException(\InvalidArgumentException::class);
        CobroCalculo::cambio(999, 1000);
    }

    public function testDevolvibleNuncaEsNegativo(): void
    {
        $this->assertSame(3000, CobroCalculo::devolvible(4000, 1000));
        $this->assertSame(0, CobroCalculo::devolvible(4000, 4000));
        $this->assertSame(0, CobroCalculo::devolvible(4000, 9000));
    }

    public function testProporcionalRedondeaMediaHaciaArribaSinSuperarElTotal(): void
    {
        $this->assertSame(250, CobroCalculo::proporcional(1000, 1000, 4000));
        $this->assertSame(1000, CobroCalculo::proporcional(1000, 4000, 4000));
        $this->assertSame(0, CobroCalculo::proporcional(1000, 0, 4000));
        $this->assertSame(1, CobroCalculo::proporcional(1, 1, 2), '0.5 sube');
        $acum = 0; $pedazos = [1, 1, 1];
        $dev = 0; foreach ($pedazos as $p) { $dev += $p; $acum = CobroCalculo::proporcional(2, $dev, 3); }
        $this->assertSame(2, $acum, 'tres devoluciones de un tercio suman exactamente el total');
        $this->expectException(\InvalidArgumentException::class);
        CobroCalculo::proporcional(10, 5, 4);
    }

    public function testReferenciaDeTransferencia(): void
    {
        $r = CobroCalculo::referenciaNormalizada("  bco   123-ab  ");
        $this->assertSame('bco 123-ab', $r['visible']);
        $this->assertSame('BCO123AB', $r['unica']);
        $this->assertSame(CobroCalculo::referenciaNormalizada('BCO-123 AB')['unica'], $r['unica'], 'misma transferencia escrita distinto');
        foreach (['', '   ', 'abc', '---', "ab\x00cd", "ab\x07cd", str_repeat('a', 81), null, 12345] as $malo) {
            try { CobroCalculo::referenciaNormalizada($malo); $this->fail('Debía rechazar ' . var_export($malo, true)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
