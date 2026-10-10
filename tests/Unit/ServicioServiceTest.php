<?php

namespace Tests\Unit;

use Model\ActiveRecord;
use Model\Servicio;
use PHPUnit\Framework\TestCase;
use Services\ServicioService;

class ServicioServiceTest extends TestCase
{
    public function testEntidadServicioEstaDesacopladaDeActiveRecord(): void
    {
        $this->assertFalse(
            is_subclass_of(Servicio::class, ActiveRecord::class),
            'Model\\Servicio no debe heredar de ActiveRecord tras la migración a ServicioRepository/ServicioService'
        );
    }

    public function testSincronizarEditableAceptaNombrePrecioYDuracionEIgnoraId(): void
    {
        $servicio = new Servicio([
            'id' => 15,
            'nombre' => 'Corte Inicial',
            'precio' => '80.00',
            'duracion_minutos' => '30'
        ]);

        $servicio->sincronizarEditable([
            'id' => 9999,
            'nombre' => '  Corte Actualizado  ',
            'precio' => ' 120.50 ',
            'duracion_minutos' => ' 45 ',
            'campo_extra' => 'ignorado'
        ]);

        $this->assertSame('15', $servicio->id, 'El id original jamás debe modificarse mediante sincronizarEditable');
        $this->assertSame('Corte Actualizado', $servicio->nombre);
        $this->assertSame('120.50', $servicio->precio);
        $this->assertSame('45', $servicio->duracion_minutos);

        // También verificar alias sincronizar()
        $servicio->sincronizar([
            'id' => 8888,
            'nombre' => 'Barba Premium',
            'precio' => '95.00',
            'duracion_minutos' => '25'
        ]);

        $this->assertSame('15', $servicio->id, 'El alias sincronizar() tampoco debe permitir alterar id');
        $this->assertSame('Barba Premium', $servicio->nombre);
        $this->assertSame('95.00', $servicio->precio);
        $this->assertSame('25', $servicio->duracion_minutos);
    }

    public function testSincronizarEditableManejaEntradasNoEscalaresSinTypeError(): void
    {
        $servicio = new Servicio(['id' => 3, 'nombre' => 'Valido', 'precio' => '50.00', 'duracion_minutos' => '30']);

        $servicio->sincronizarEditable([
            'id' => ['inyeccion_id'],
            'nombre' => ['array_nombre'],
            'precio' => ['array_precio'],
            'duracion_minutos' => ['array_duracion']
        ]);

        $this->assertSame('3', $servicio->id);
        $this->assertSame('', $servicio->nombre);
        $this->assertSame('', $servicio->precio);
        $this->assertSame('', $servicio->duracion_minutos);
    }

    public function testValidarIdAceptaSoloEnterosPositivosEscalares(): void
    {
        $service = new ServicioService();

        $this->assertSame(1, $service->validarId(1));
        $this->assertSame(42, $service->validarId('42'));
        $this->assertSame(100, $service->validarId(' 100 '));

        $this->assertNull($service->validarId(0));
        $this->assertNull($service->validarId('0'));
        $this->assertNull($service->validarId(-5));
        $this->assertNull($service->validarId('-5'));
        $this->assertNull($service->validarId('12.5'));
        $this->assertNull($service->validarId('abc'));
        $this->assertNull($service->validarId(''));
        $this->assertNull($service->validarId(null));
        $this->assertNull($service->validarId(true));
        $this->assertNull($service->validarId(['1']));
    }

    public function testValidacionRechazaNombresInvalidosOQueExcedanLongitudDeColumna(): void
    {
        // Vacío o espacios
        $resVacio = ServicioService::validarDatos(['nombre' => '   ', 'precio' => '50.00', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($resVacio['error'] ?? []);

        // No escalar (array)
        $resArray = ServicioService::validarDatos(['nombre' => ['Corte'], 'precio' => '50.00', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($resArray['error'] ?? []);

        // Excede VARCHAR(60) (61 caracteres)
        $nombre61 = str_repeat('a', 61);
        $resLargo = ServicioService::validarDatos(['nombre' => $nombre61, 'precio' => '50.00', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($resLargo['error'] ?? []);

        // Exactamente 60 caracteres UTF-8 (multibyte) es válido
        $nombre60Utf8 = str_repeat('á', 60);
        $resExacto = ServicioService::validarDatos(['nombre' => $nombre60Utf8, 'precio' => '50.00', 'duracion_minutos' => '30']);
        $this->assertEmpty($resExacto);
    }

    public function testValidacionPrecioCorrigeBugHistoricoYValidaRangoYFormatoDecimal(): void
    {
        // Precios válidos en distintos formatos aceptables para DECIMAL(6,2)
        $this->assertEmpty(ServicioService::validarDatos(['nombre' => 'Servicio A', 'precio' => '0.01', 'duracion_minutos' => '30']));
        $this->assertEmpty(ServicioService::validarDatos(['nombre' => 'Servicio B', 'precio' => '80', 'duracion_minutos' => '30']));
        $this->assertEmpty(ServicioService::validarDatos(['nombre' => 'Servicio C', 'precio' => '80.5', 'duracion_minutos' => '45']));
        $this->assertEmpty(ServicioService::validarDatos(['nombre' => 'Servicio D', 'precio' => '9999.99', 'duracion_minutos' => '60']));

        // Entidad Servicio::validar() delega en la misma validación corregida
        $entidadValida = new Servicio(['nombre' => 'Corte', 'precio' => '90.00', 'duracion_minutos' => '30']);
        $this->assertEmpty($entidadValida->validar());

        $entidadInvalida = new Servicio(['nombre' => 'Corte', 'precio' => 'no_numerico', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($entidadInvalida->validar()['error'] ?? []);

        // Rechazo de precio vacío o no escalar
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '', 'duracion_minutos' => '30'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => ['80'], 'duracion_minutos' => '30'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => true, 'duracion_minutos' => '30'])['error'] ?? []);

        // Rechazo de formatos inválidos y notación científica
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => 'abc', 'duracion_minutos' => '30'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '1e2', 'duracion_minutos' => '30'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.123', 'duracion_minutos' => '30'])['error'] ?? []);

        // Rechazo de negativos
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '-10', 'duracion_minutos' => '30'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '-0.01', 'duracion_minutos' => '30'])['error'] ?? []);

        // Política explícita sobre precio cero: 0 y 0.00 son rechazados
        $resCero = ServicioService::validarDatos(['nombre' => 'Corte Gratis', 'precio' => '0', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($resCero['error'] ?? []);
        $resCeroDecimal = ServicioService::validarDatos(['nombre' => 'Corte Gratis', 'precio' => '0.00', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($resCeroDecimal['error'] ?? []);

        // Rechazo de valores que exceden la capacidad de DECIMAL(6,2) (máximo 9999.99)
        $resExceso = ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '10000', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($resExceso['error'] ?? []);
        $resExcesoDecimal = ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '10000.00', 'duracion_minutos' => '30']);
        $this->assertNotEmpty($resExcesoDecimal['error'] ?? []);
    }

    public function testValidacionDuracionEnMinutosExigeEnteroPositivo(): void
    {
        // Valores enteros positivos válidos
        $this->assertEmpty(ServicioService::validarDatos(['nombre' => 'Express', 'precio' => '30.00', 'duracion_minutos' => 1]));
        $this->assertEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => '30']));
        $this->assertEmpty(ServicioService::validarDatos(['nombre' => 'Coloración', 'precio' => '120.00', 'duracion_minutos' => ' 90 ']));

        // Rechazo de ausente o vacío
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => ''])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => '   '])['error'] ?? []);

        // Rechazo de cero y negativos
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => 0])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => '0'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => -15])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => '-30'])['error'] ?? []);

        // Rechazo de decimales, textos y no escalares
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => '30.5'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => 30.5])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => 'treinta'])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => ['30']])['error'] ?? []);
        $this->assertNotEmpty(ServicioService::validarDatos(['nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => true])['error'] ?? []);
    }
}
