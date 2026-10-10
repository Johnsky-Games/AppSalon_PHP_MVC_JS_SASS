<?php

namespace Tests\Unit;

use Model\BloqueoProfesional;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Profesional;
use Model\Servicio;
use PHPUnit\Framework\TestCase;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\DisponibilidadService;

class DisponibilidadServiceTest extends TestCase
{
    private function crearProfesionalActivo(array $servicioIds = [1, 2]): Profesional
    {
        $prof = new Profesional([
            'id' => 1,
            'nombre' => 'Sofía Andrade',
            'activo' => 1
        ]);
        $prof->servicioIds = $servicioIds;
        return $prof;
    }

    private function crearMapaServiciosCatalogo(): array
    {
        return [
            1 => new Servicio(['id' => 1, 'nombre' => 'Corte Hombre', 'precio' => '80.00', 'duracion_minutos' => 30]),
            2 => new Servicio(['id' => 2, 'nombre' => 'Coloración', 'precio' => '120.00', 'duracion_minutos' => 45]),
            3 => new Servicio(['id' => 3, 'nombre' => 'Barba', 'precio' => '60.00', 'duracion_minutos' => 30]),
        ];
    }

    public function testConfiguracionPorDefectoDePasoYZonaHorariaAmericaGuayaquil(): void
    {
        $profRepo = $this->createMock(ProfesionalRepository::class);
        $servRepo = $this->createMock(ServicioRepository::class);
        $service = new DisponibilidadService($profRepo, $servRepo);

        $this->assertSame(15, DisponibilidadService::DEFAULT_PASO_MINUTOS);
        $this->assertSame(15, $service->getPasoMinutos());
        $this->assertSame('America/Guayaquil', DisponibilidadService::TIMEZONE);
        $this->assertSame('America/Guayaquil', $service->obtenerZonaHoraria()->getName());

        // 2026-10-12 es Lunes (1), 2026-10-13 es Martes (2), 2026-10-18 es Domingo (7) en America/Guayaquil
        $this->assertSame(1, $service->obtenerDiaSemanaIso('2026-10-12'));
        $this->assertSame(2, $service->obtenerDiaSemanaIso('2026-10-13'));
        $this->assertSame(7, $service->obtenerDiaSemanaIso('2026-10-18'));

        $service->setPasoMinutos(20);
        $this->assertSame(20, $service->getPasoMinutos());
    }

    public function testRechazaSolicitudesInvalidasYFechasInvalidas(): void
    {
        $profRepo = $this->createMock(ProfesionalRepository::class);
        $servRepo = $this->createMock(ServicioRepository::class);
        $profRepo->expects($this->never())->method('findById');

        $service = new DisponibilidadService($profRepo, $servRepo);

        // 1. Profesional inválido (ausente, 0, negativo, no escalar)
        foreach ([null, '', '0', -1, ['1'], 'abc'] as $idInvalido) {
            $res = $service->consultar($idInvalido, '2026-10-12', [1]);
            $this->assertSame(DisponibilidadService::STATUS_INVALID, $res['status']);
            $this->assertSame(DisponibilidadService::CODIGO_SOLICITUD_INVALIDA, $res['codigo']);
            $this->assertSame(422, $res['httpCode']);
            $this->assertFalse($res['resultado']);
        }

        // 2. Fechas inválidas (formato incorrecto, día inexistente, no escalar)
        foreach ([null, '', '12-10-2026', '2026/10/12', '2026-02-30', '2026-13-01', ['2026-10-12']] as $fechaInvalida) {
            $res = $service->consultar(1, $fechaInvalida, [1]);
            $this->assertSame(DisponibilidadService::STATUS_INVALID, $res['status']);
            $this->assertSame(DisponibilidadService::CODIGO_SOLICITUD_INVALIDA, $res['codigo']);
            $this->assertSame(422, $res['httpCode']);
        }

        // 3. Servicios inválidos (vacíos, malformados)
        foreach ([null, '', [], '1,,2', '1,abc', [1, 0], [1, -3], [['sin_id' => 1]], [['id' => 'xyz']]] as $serviciosInvalidos) {
            $res = $service->consultar(1, '2026-10-12', $serviciosInvalidos);
            $this->assertSame(DisponibilidadService::STATUS_INVALID, $res['status']);
            $this->assertSame(DisponibilidadService::CODIGO_SOLICITUD_INVALIDA, $res['codigo']);
            $this->assertSame(422, $res['httpCode']);
        }

        // 4. Paso de minutos inválido en consultarDesdeParametros
        foreach (['0', '-15', '1500', 'abc', ['15']] as $pasoInvalido) {
            $res = $service->consultarDesdeParametros([
                'profesionalId' => '1',
                'fecha' => '2026-10-12',
                'servicios' => '1',
                'paso_minutos' => $pasoInvalido
            ]);
            $this->assertSame(DisponibilidadService::STATUS_INVALID, $res['status']);
            $this->assertSame(DisponibilidadService::CODIGO_SOLICITUD_INVALIDA, $res['codigo']);
            $this->assertSame(422, $res['httpCode']);
        }
    }

    public function testDistingueProfesionalInexistenteInactivoYServiciosIncompatibles(): void
    {
        $catalogo = $this->crearMapaServiciosCatalogo();

        // 1. Profesional inexistente -> 404 professional_not_found
        $profRepoNotFound = $this->createMock(ProfesionalRepository::class);
        $profRepoNotFound->expects($this->once())->method('findById')->with(99)->willReturn(null);
        $servRepo = $this->createMock(ServicioRepository::class);

        $serviceNotFound = new DisponibilidadService($profRepoNotFound, $servRepo);
        $resNotFound = $serviceNotFound->consultar(99, '2026-10-12', [1]);
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_NOT_FOUND, $resNotFound['status']);
        $this->assertSame(DisponibilidadService::CODIGO_PROFESIONAL_NO_ENCONTRADO, $resNotFound['codigo']);
        $this->assertSame(404, $resNotFound['httpCode']);
        $this->assertFalse($resNotFound['resultado']);

        // 2. Profesional inactivo -> 409 professional_inactive
        $profInactivo = new Profesional(['id' => 2, 'nombre' => 'Inactivo', 'activo' => 0]);
        $profInactivo->servicioIds = [1, 2];

        $profRepoInactivo = $this->createMock(ProfesionalRepository::class);
        $profRepoInactivo->expects($this->once())->method('findById')->with(2)->willReturn($profInactivo);

        $serviceInactivo = new DisponibilidadService($profRepoInactivo, $servRepo);
        $resInactivo = $serviceInactivo->consultar(2, '2026-10-12', [1]);
        $this->assertSame(DisponibilidadService::STATUS_PROFESSIONAL_INACTIVE, $resInactivo['status']);
        $this->assertSame(DisponibilidadService::CODIGO_PROFESIONAL_INACTIVO, $resInactivo['codigo']);
        $this->assertSame(409, $resInactivo['httpCode']);
        $this->assertFalse($resInactivo['resultado']);

        // 3. Servicios incompatibles (profesional realiza [1, 2], pero se pide [1, 3]) -> 422 incompatible_services
        $profRepoActivo = $this->createMock(ProfesionalRepository::class);
        $profRepoActivo->method('findById')->with(1)->willReturn($this->crearProfesionalActivo([1, 2]));

        $servRepoCatalogo = $this->createMock(ServicioRepository::class);
        $servRepoCatalogo->method('findById')->willReturnCallback(fn(int $id) => $catalogo[$id] ?? null);

        $serviceIncompatible = new DisponibilidadService($profRepoActivo, $servRepoCatalogo);
        $resIncompatible = $serviceIncompatible->consultar(1, '2026-10-12', [1, 3]);
        $this->assertSame(DisponibilidadService::STATUS_INCOMPATIBLE_SERVICES, $resIncompatible['status']);
        $this->assertSame(DisponibilidadService::CODIGO_SERVICIOS_INCOMPATIBLES, $resIncompatible['codigo']);
        $this->assertSame(422, $resIncompatible['httpCode']);
        $this->assertSame([3], $resIncompatible['servicios_incompatibles']);
        $this->assertFalse($resIncompatible['resultado']);

        // 4. Servicio inexistente en el catálogo (ID 999) -> 422 solicitud_invalida
        $resServicioInexistente = $serviceIncompatible->consultar(1, '2026-10-12', [1, 999]);
        $this->assertSame(DisponibilidadService::STATUS_INVALID, $resServicioInexistente['status']);
        $this->assertSame(DisponibilidadService::CODIGO_SOLICITUD_INVALIDA, $resServicioInexistente['codigo']);
        $this->assertSame(422, $resServicioInexistente['httpCode']);
    }

    public function testCalculaDuracionTotalDesdeCatalogoIgnorandoDuracionDelCliente(): void
    {
        $catalogo = $this->crearMapaServiciosCatalogo(); // ID 1 = 30 min, ID 2 = 45 min => Total real = 75 min
        $profRepo = $this->createMock(ProfesionalRepository::class);
        $profRepo->method('findById')->with(1)->willReturn($this->crearProfesionalActivo([1, 2]));
        $profRepo->method('findHorariosByProfesionalYDia')->with(1, 1)->willReturn([
            new HorarioProfesional(['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '11:00'])
        ]);
        $profRepo->method('findDescansosByProfesionalYDia')->willReturn([]);
        $profRepo->method('findBloqueosByProfesionalEnFecha')->willReturn([]);

        $servRepo = $this->createMock(ServicioRepository::class);
        $servRepo->method('findById')->willReturnCallback(fn(int $id) => $catalogo[$id] ?? null);

        $service = new DisponibilidadService($profRepo, $servRepo);

        // El cliente intenta falsificar duraciones de 5 minutos tanto en el array de servicios como en parámetros extra
        $res = $service->consultarDesdeParametros([
            'profesionalId' => 1,
            'fecha' => '2026-10-12',
            'duracion' => 5,
            'duracion_minutos' => 5,
            'duracion_total_minutos' => 10,
            'servicios' => [
                ['id' => 1, 'duracion_minutos' => 5, 'duracion' => 5],
                ['id' => 2, 'duracion_minutos' => 5, 'duracion' => 5],
                ['id' => 1, 'duracion_minutos' => 1] // duplicado que debe desduplicarse
            ]
        ]);

        $this->assertSame(DisponibilidadService::STATUS_OK, $res['status']);
        $this->assertTrue($res['disponible']);
        $this->assertSame([1, 2], $res['servicios']);
        $this->assertSame(75, $res['duracion_total_minutos']);

        // En una ventana de 09:00 a 11:00 (120 min) con duración real 75 min y paso 15 min:
        // 09:00-10:15, 09:15-10:30, 09:30-10:45, 09:45-11:00 (4 intervalos)
        $horasInicio = array_column($res['intervalos'], 'inicio');
        $horasFin = array_column($res['intervalos'], 'fin');
        $this->assertSame(['09:00', '09:15', '09:30', '09:45'], $horasInicio);
        $this->assertSame(['10:15', '10:30', '10:45', '11:00'], $horasFin);
    }

    public function testLimitesExactosConDescansosYBloqueosParcialesEnIntervalosSemiabiertos(): void
    {
        $catalogo = [
            1 => new Servicio(['id' => 1, 'nombre' => 'Servicio A', 'precio' => '50.00', 'duracion_minutos' => 25]),
            2 => new Servicio(['id' => 2, 'nombre' => 'Servicio B', 'precio' => '40.00', 'duracion_minutos' => 20]),
        ]; // Duración combinada = 45 min

        $profRepo = $this->createMock(ProfesionalRepository::class);
        $profRepo->method('findById')->with(1)->willReturn($this->crearProfesionalActivo([1, 2]));

        // Lunes 2026-10-12 (dia_semana = 1): turno 09:00-14:00
        $profRepo->method('findHorariosByProfesionalYDia')->with(1, 1)->willReturn([
            new HorarioProfesional(['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '14:00'])
        ]);
        // Descanso 10:30-11:00
        $profRepo->method('findDescansosByProfesionalYDia')->with(1, 1)->willReturn([
            new DescansoProfesional(['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '10:30', 'hora_fin' => '11:00'])
        ]);
        // Bloqueo parcial 12:00-12:45
        $profRepo->method('findBloqueosByProfesionalEnFecha')->with(1, '2026-10-12')->willReturn([
            new BloqueoProfesional([
                'id' => 1,
                'profesionalId' => 1,
                'fecha_inicio' => '2026-10-12',
                'fecha_fin' => '2026-10-12',
                'hora_inicio' => '12:00',
                'hora_fin' => '12:45'
            ])
        ]);

        $servRepo = $this->createMock(ServicioRepository::class);
        $servRepo->method('findById')->willReturnCallback(fn(int $id) => $catalogo[$id] ?? null);

        $service = new DisponibilidadService($profRepo, $servRepo, 15);
        $res = $service->consultar(1, '2026-10-12', '1,2');

        $this->assertSame(DisponibilidadService::STATUS_OK, $res['status']);
        $this->assertSame(45, $res['duracion_total_minutos']);

        $pares = array_map(fn(array $i) => $i['inicio'] . '-' . $i['fin'], $res['intervalos']);
        $esperados = [
            // Ventana [09:00, 10:30) -> termina exactamente en 10:30 (inicio del descanso)
            '09:00-09:45',
            '09:15-10:00',
            '09:30-10:15',
            '09:45-10:30',
            // Ventana [11:00, 12:00) -> empieza en 11:00 (fin del descanso) y termina en 12:00 (inicio del bloqueo)
            '11:00-11:45',
            '11:15-12:00',
            // Ventana [12:45, 14:00) -> empieza en 12:45 (fin del bloqueo) y termina en 14:00 (fin del turno)
            '12:45-13:30',
            '13:00-13:45',
            '13:15-14:00',
        ];

        $this->assertSame($esperados, $pares);
    }

    public function testMultiplesFranjasLaboralesSinAtravesarHuecosEntreTurnos(): void
    {
        $catalogo = $this->crearMapaServiciosCatalogo(); // ID 1 = 30 min, ID 3 = 30 min => 60 min combinados

        $profRepo = $this->createMock(ProfesionalRepository::class);
        $profRepo->method('findById')->with(1)->willReturn($this->crearProfesionalActivo([1, 2, 3]));

        // Martes 2026-10-13 (dia_semana = 2): dos franjas 08:00-09:30 (90 min) y 10:00-11:00 (60 min)
        // Hueco entre turnos: 09:30-10:00
        $profRepo->method('findHorariosByProfesionalYDia')->with(1, 2)->willReturn([
            new HorarioProfesional(['id' => 2, 'profesionalId' => 1, 'dia_semana' => 2, 'hora_inicio' => '10:00', 'hora_fin' => '11:00']),
            new HorarioProfesional(['id' => 1, 'profesionalId' => 1, 'dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '09:30']),
        ]);
        $profRepo->method('findDescansosByProfesionalYDia')->willReturn([]);
        $profRepo->method('findBloqueosByProfesionalEnFecha')->willReturn([]);

        $servRepo = $this->createMock(ServicioRepository::class);
        $servRepo->method('findById')->willReturnCallback(fn(int $id) => $catalogo[$id] ?? null);

        $service = new DisponibilidadService($profRepo, $servRepo, 15);
        $res = $service->consultar(1, '2026-10-13', [1, 3]);

        $this->assertSame(DisponibilidadService::STATUS_OK, $res['status']);
        $this->assertSame(60, $res['duracion_total_minutos']);

        $pares = array_map(fn(array $i) => $i['inicio'] . '-' . $i['fin'], $res['intervalos']);
        $this->assertSame([
            // Primera franja [08:00, 09:30) con 60 min
            '08:00-09:00',
            '08:15-09:15',
            '08:30-09:30',
            // Ningún intervalo atraviesa el hueco [09:30, 10:00)
            // Segunda franja [10:00, 11:00) con 60 min
            '10:00-11:00',
        ], $pares);
    }

    public function testBloqueoDiaCompletoYDiaSinHuecosSuficientesDevuelvenDisponibilidadVacia(): void
    {
        $catalogo = $this->crearMapaServiciosCatalogo(); // ID 1 (30) + ID 2 (45) = 75 min

        $servRepo = $this->createMock(ServicioRepository::class);
        $servRepo->method('findById')->willReturnCallback(fn(int $id) => $catalogo[$id] ?? null);

        // Caso A: Bloqueo de día completo (hora_inicio = null, hora_fin = null)
        $profRepoBloqueado = $this->createMock(ProfesionalRepository::class);
        $profRepoBloqueado->method('findById')->willReturn($this->crearProfesionalActivo([1, 2]));
        $profRepoBloqueado->method('findHorariosByProfesionalYDia')->willReturn([
            new HorarioProfesional(['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '18:00'])
        ]);
        $profRepoBloqueado->method('findDescansosByProfesionalYDia')->willReturn([]);
        $profRepoBloqueado->method('findBloqueosByProfesionalEnFecha')->willReturn([
            new BloqueoProfesional([
                'id' => 10,
                'profesionalId' => 1,
                'fecha_inicio' => '2026-10-12',
                'fecha_fin' => '2026-10-14',
                'hora_inicio' => null,
                'hora_fin' => null,
                'motivo' => 'Vacaciones'
            ])
        ]);

        $serviceBloqueado = new DisponibilidadService($profRepoBloqueado, $servRepo);
        $resBloqueado = $serviceBloqueado->consultar(1, '2026-10-12', [1, 2]);

        $this->assertSame(DisponibilidadService::STATUS_EMPTY, $resBloqueado['status']);
        $this->assertSame(DisponibilidadService::CODIGO_DISPONIBILIDAD_VACIA, $resBloqueado['codigo']);
        $this->assertSame(200, $resBloqueado['httpCode']);
        $this->assertTrue($resBloqueado['resultado']);
        $this->assertFalse($resBloqueado['disponible']);
        $this->assertSame([], $resBloqueado['intervalos']);

        // Caso B: Ventanas libres de 60 min cada una pero la duración combinada requiere 75 min
        $profRepoCorto = $this->createMock(ProfesionalRepository::class);
        $profRepoCorto->method('findById')->willReturn($this->crearProfesionalActivo([1, 2]));
        $profRepoCorto->method('findHorariosByProfesionalYDia')->willReturn([
            new HorarioProfesional(['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '11:30'])
        ]);
        // Descanso de 10:00 a 10:30 divide el turno en dos ventanas de 60 min: [09:00, 10:00) y [10:30, 11:30)
        $profRepoCorto->method('findDescansosByProfesionalYDia')->willReturn([
            new DescansoProfesional(['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '10:30'])
        ]);
        $profRepoCorto->method('findBloqueosByProfesionalEnFecha')->willReturn([]);

        $serviceCorto = new DisponibilidadService($profRepoCorto, $servRepo);
        $resCorto = $serviceCorto->consultar(1, '2026-10-12', [1, 2]); // requiere 75 min

        $this->assertSame(DisponibilidadService::STATUS_EMPTY, $resCorto['status']);
        $this->assertSame(DisponibilidadService::CODIGO_DISPONIBILIDAD_VACIA, $resCorto['codigo']);
        $this->assertFalse($resCorto['disponible']);
        $this->assertSame(75, $resCorto['duracion_total_minutos']);
        $this->assertSame([], $resCorto['intervalos']);
    }

    public function testManejaFallosSqlMediantePersistenceExceptionSinExponerDetalles(): void
    {
        $profRepo = $this->createMock(ProfesionalRepository::class);
        $profRepo->method('findById')->willThrowException(new PersistenceException('SQL secret error: table locked'));
        $servRepo = $this->createMock(ServicioRepository::class);

        $service = new DisponibilidadService($profRepo, $servRepo);
        $res = $service->consultar(1, '2026-10-12', [1]);

        $this->assertSame(DisponibilidadService::STATUS_ERROR, $res['status']);
        $this->assertSame(DisponibilidadService::CODIGO_ERROR_PERSISTENCIA, $res['codigo']);
        $this->assertSame(500, $res['httpCode']);
        $this->assertFalse($res['resultado']);
        $this->assertFalse($res['disponible']);
        $this->assertStringNotContainsString('SQL secret error', $res['error']);
    }
}
