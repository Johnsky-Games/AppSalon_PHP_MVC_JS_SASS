<?php

namespace Tests\Unit;

use Controllers\CitaController;
use Model\ActiveRecord;
use Model\AdminCita;
use Model\Cita;
use Model\CitaServicio;
use PHPUnit\Framework\TestCase;
use Services\CitaService;

class CitaServiceTest extends TestCase
{
    public function testEntidadesDeCitasYCitaControllerEstanDesacopladosDeActiveRecord(): void
    {
        $this->assertFalse(
            is_subclass_of(Cita::class, ActiveRecord::class),
            'Model\\Cita no debe heredar de ActiveRecord tras la migración a CitaRepository/CitaService'
        );
        $this->assertFalse(
            is_subclass_of(CitaServicio::class, ActiveRecord::class),
            'Model\\CitaServicio no debe heredar de ActiveRecord'
        );
        $this->assertFalse(
            is_subclass_of(AdminCita::class, ActiveRecord::class),
            'Model\\AdminCita no debe heredar de ActiveRecord'
        );
        $this->assertFalse(
            is_subclass_of(CitaController::class, ActiveRecord::class),
            'Controllers\\CitaController no debe heredar de ActiveRecord'
        );
    }

    public function testCitaSincronizarEditableAceptaSoloFechaYHoraEIgnoraIdYUsuarioId(): void
    {
        $cita = new Cita([
            'id' => 10,
            'fecha' => '2026-10-20',
            'hora' => '11:00',
            'usuarioId' => 5
        ]);

        $cita->sincronizarEditable([
            'id' => 999,
            'usuarioId' => 888,
            'fecha' => ' 2026-10-22 ',
            'hora' => ' 14:30 ',
            'extra' => 'ignorado'
        ]);

        $this->assertSame('10', $cita->id, 'El id de la cita no debe alterarse mediante sincronizarEditable');
        $this->assertSame('5', $cita->usuarioId, 'El usuarioId no debe alterarse mediante sincronizarEditable');
        $this->assertSame('2026-10-22', $cita->fecha);
        $this->assertSame('14:30', $cita->hora);

        $cita->sincronizar([
            'id' => ['inyeccion'],
            'usuarioId' => ['inyeccion'],
            'fecha' => ['array_invalido'],
            'hora' => ['array_invalido']
        ]);

        $this->assertSame('10', $cita->id);
        $this->assertSame('5', $cita->usuarioId);
        $this->assertSame('', $cita->fecha);
        $this->assertSame('', $cita->hora);
    }

    public function testValidarIdAceptaSoloEnterosPositivosEscalares(): void
    {
        $service = new CitaService();

        $this->assertSame(1, $service->validarId(1));
        $this->assertSame(25, $service->validarId('25'));
        $this->assertSame(300, $service->validarId(' 300 '));

        $this->assertNull($service->validarId(0));
        $this->assertNull($service->validarId('0'));
        $this->assertNull($service->validarId(-10));
        $this->assertNull($service->validarId('-10'));
        $this->assertNull($service->validarId('4.5'));
        $this->assertNull($service->validarId('abc'));
        $this->assertNull($service->validarId(''));
        $this->assertNull($service->validarId(null));
        $this->assertNull($service->validarId(true));
        $this->assertNull($service->validarId(['1']));
    }

    public function testResolverFechaConsultaAdminValidaFormatoYCalendarioConFallbackAHoy(): void
    {
        $service = new CitaService();
        $hoy = date('Y-m-d');

        $resValida = $service->resolverFechaConsultaAdmin('2026-11-18');
        $this->assertSame('2026-11-18', $resValida['fecha']);
        $this->assertTrue($resValida['esValida']);

        $resInvalidaCalendario = $service->resolverFechaConsultaAdmin('2026-02-30');
        $this->assertSame($hoy, $resInvalidaCalendario['fecha']);
        $this->assertFalse($resInvalidaCalendario['esValida']);

        $resFormatoInvalido = $service->resolverFechaConsultaAdmin('18/11/2026');
        $this->assertSame($hoy, $resFormatoInvalido['fecha']);
        $this->assertFalse($resFormatoInvalido['esValida']);

        $resArray = $service->resolverFechaConsultaAdmin(['2026-11-18']);
        $this->assertSame($hoy, $resArray['fecha']);
        $this->assertFalse($resArray['esValida']);
    }

    public function testReservarValidaIdentidadFechasDiasLaborablesYHorariosSinConsultarSesionDirectamente(): void
    {
        $service = new CitaService();
        $juevesFuturo = date('Y-m-d', strtotime('next Thursday'));
        $sabadoFuturo = date('Y-m-d', strtotime('next Saturday'));
        $domingoFuturo = date('Y-m-d', strtotime('next Sunday'));

        // Aun si $_SESSION tuviera un id, si el controlador pasa null o inválido se rechaza
        $_SESSION['id'] = 777;
        $resSinAuth = $service->reservar(null, [
            'fecha' => $juevesFuturo,
            'hora' => '11:00',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_UNAUTHORIZED, $resSinAuth['status']);

        // Formato de fecha inválido o no escalar
        $resFechaArray = $service->reservar(1, [
            'fecha' => [$juevesFuturo],
            'hora' => '11:00',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resFechaArray['status']);
        $this->assertStringContainsString('AAAA-MM-DD', $resFechaArray['error']);

        // Fecha de calendario inexistente
        $resFechaInexistente = $service->reservar(1, [
            'fecha' => '2027-02-29',
            'hora' => '11:00',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resFechaInexistente['status']);
        $this->assertStringContainsString('calendario válido', $resFechaInexistente['error']);

        // Mismo día y fecha pasada
        $resHoy = $service->reservar(1, [
            'fecha' => date('Y-m-d'),
            'hora' => '11:00',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resHoy['status']);

        $resPasada = $service->reservar(1, [
            'fecha' => '2020-05-12',
            'hora' => '11:00',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resPasada['status']);

        // Fines de semana (sábado y domingo)
        $resSabado = $service->reservar(1, [
            'fecha' => $sabadoFuturo,
            'hora' => '11:00',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resSabado['status']);
        $this->assertStringContainsString('fines de semana', $resSabado['error']);

        $resDomingo = $service->reservar(1, [
            'fecha' => $domingoFuturo,
            'hora' => '11:00',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resDomingo['status']);

        // Horarios fuera de rango (antes de 10:00 o después de 18:00)
        $resTemprano = $service->reservar(1, [
            'fecha' => $juevesFuturo,
            'hora' => '09:59',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resTemprano['status']);
        $this->assertStringContainsString('10:00 a 18:00', $resTemprano['error']);

        $resTarde = $service->reservar(1, [
            'fecha' => $juevesFuturo,
            'hora' => '18:01',
            'servicios' => '1'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resTarde['status']);

        // Servicios vacíos o con formato inválido
        $resSinServicios = $service->reservar(1, [
            'fecha' => $juevesFuturo,
            'hora' => '11:00',
            'servicios' => ' , '
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resSinServicios['status']);
        $this->assertStringContainsString('seleccionar al menos un servicio', $resSinServicios['error']);

        $resServicioInvalido = $service->reservar(1, [
            'fecha' => $juevesFuturo,
            'hora' => '11:00',
            'servicios' => '1,0,-3'
        ]);
        $this->assertSame(CitaService::STATUS_INVALID, $resServicioInvalido['status']);
        $this->assertStringContainsString('enteros positivos', $resServicioInvalido['error']);
    }
}
