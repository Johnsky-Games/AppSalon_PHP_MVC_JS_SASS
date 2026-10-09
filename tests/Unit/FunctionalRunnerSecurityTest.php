<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FunctionalRunnerSecurityTest extends TestCase
{
    public function testValidarBaseDatosFuncionalRechazaBaseNoAutorizadaAntesDeCualquierModificacion(): void
    {
        // Mock de mysqli que simula una base de datos no autorizada (ej. 'appsalon_mvc' de producción o 'appsalon_test' unitaria)
        $basesNoAutorizadas = ['appsalon_mvc', 'appsalon_test', 'produccion', 'appsalon', 'test'];

        foreach ($basesNoAutorizadas as $dbInvalida) {
            $mockDb = $this->createMock(\mysqli::class);
            $mockResult = $this->createMock(\mysqli_result::class);

            $mockResult->expects($this->once())
                ->method('fetch_assoc')
                ->willReturn(['db_actual' => $dbInvalida]);

            $mockDb->expects($this->once())
                ->method('query')
                ->with('SELECT DATABASE() AS db_actual')
                ->willReturn($mockResult);

            // Verificar que no se invoque ninguna sentencia de eliminación ni modificación
            // (si se intentara ejecutar un DELETE tras la validación fallida, fallaría la aserción)
            $bloqueado = false;
            try {
                validar_base_datos_funcional($mockDb);
            } catch (\RuntimeException $e) {
                $bloqueado = true;
                $this->assertStringContainsString('ACCESO DENEGADO', $e->getMessage());
                $this->assertStringContainsString($dbInvalida, $e->getMessage());
                $this->assertStringContainsString('appsalon_func_test', $e->getMessage());
            }

            $this->assertTrue($bloqueado, "La base de datos '{$dbInvalida}' debió ser rechazada con RuntimeException");
        }
    }

    public function testValidarBaseDatosFuncionalAceptaUnicamenteBaseAutorizada(): void
    {
        $basesAutorizadas = ['appsalon_func_test', 'mi_app_func_test'];

        foreach ($basesAutorizadas as $dbValida) {
            $mockDb = $this->createMock(\mysqli::class);
            $mockResult = $this->createMock(\mysqli_result::class);

            $mockResult->expects($this->once())
                ->method('fetch_assoc')
                ->willReturn(['db_actual' => $dbValida]);

            $mockDb->expects($this->once())
                ->method('query')
                ->with('SELECT DATABASE() AS db_actual')
                ->willReturn($mockResult);

            $resultado = validar_base_datos_funcional($mockDb);
            $this->assertSame($dbValida, $resultado);
        }
    }

    public function testOperacionDestructivaQuedaBloqueadaSiValidacionFalla(): void
    {
        $mockDb = $this->createMock(\mysqli::class);
        $mockResult = $this->createMock(\mysqli_result::class);

        $mockResult->method('fetch_assoc')->willReturn(['db_actual' => 'appsalon_mvc']);
        $mockDb->method('query')
            ->willReturnCallback(function (string $sql) use ($mockResult) {
                if ($sql === 'SELECT DATABASE() AS db_actual') {
                    return $mockResult;
                }
                // Si llegara a ejecutarse un DELETE, provocamos fallo deliberado
                if (str_starts_with(strtoupper(trim($sql)), 'DELETE')) {
                    $this->fail("SE INTENTÓ EJECUTAR UNA SENTENCIA DESTRUCTIVA EN BASE NO AUTORIZADA: {$sql}");
                }
                return false;
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ACCESO DENEGADO');

        // Secuencia protegida
        validar_base_datos_funcional($mockDb);

        // Esta línea no debe alcanzarse
        $mockDb->query("DELETE FROM usuarios");
    }
}
