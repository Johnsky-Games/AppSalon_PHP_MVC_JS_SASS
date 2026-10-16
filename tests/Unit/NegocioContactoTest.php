<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Repositories\CrecimientoRepository;
use Services\CrecimientoService;

class NegocioContactoTest extends TestCase
{
    private function datos(array $extras=[]): array
    { return $extras + ['nombre_comercial'=>'Salón de Prueba','contacto'=>'Recepción','beneficio_fidelidad'=>'Servicio de cortesía']; }

    public function testContactoConfiguradoSeConservaCuandoFormularioAnteriorNoLoIncluye(): void
    {
        $repo=$this->createMock(CrecimientoRepository::class);
        $actuales=['email_contacto'=>'soporte@salon.test','telefono'=>'+593 99 000 0000','direccion'=>'Dirección de prueba'];
        $repo->method('ajustes')->willReturn($actuales);
        $repo->expects($this->once())->method('guardarAjustes')->with(7,$this->datos()+$actuales);
        (new CrecimientoService($repo))->guardarAjustes(7,$this->datos());
    }

    public function testCamposOpcionalesPuedenEliminarseExplicitamente(): void
    {
        $repo=$this->createMock(CrecimientoRepository::class);
        $repo->method('ajustes')->willReturn(['email_contacto'=>'soporte@salon.test']);
        $vacios=['email_contacto'=>'','telefono'=>'','direccion'=>''];
        $repo->expects($this->once())->method('guardarAjustes')->with(7,$this->datos()+$vacios);
        (new CrecimientoService($repo))->guardarAjustes(7,$this->datos($vacios));
    }

    public function testCorreoInvalidoNoSeGuardaNiSeMuestraEnCorreos(): void
    {
        $repo=$this->createMock(CrecimientoRepository::class);
        $repo->expects($this->never())->method('guardarAjustes');
        $this->expectException(\InvalidArgumentException::class);
        (new CrecimientoService($repo))->guardarAjustes(7,$this->datos(['email_contacto'=>'no-es-correo']));
    }

    public function testContactoNoAdmiteSaltosParaCabeceras(): void
    {
        $repo=$this->createMock(CrecimientoRepository::class);
        $repo->expects($this->never())->method('guardarAjustes');
        $this->expectException(\InvalidArgumentException::class);
        (new CrecimientoService($repo))->guardarAjustes(7,$this->datos(['telefono'=>"+593 99 000 0000\r\nBcc: otro@salon.test"]));
    }
}
