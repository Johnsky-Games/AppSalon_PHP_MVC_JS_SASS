<?php

namespace Tests\Unit;

use Model\ActiveRecord;
use Model\BloqueoProfesional;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Profesional;
use Model\Servicio;
use PHPUnit\Framework\TestCase;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\CitaService;
use Services\ProfesionalService;

class InMemoryProfesionalRepositoryStub extends ProfesionalRepository
{
    /** @var array<int, Profesional> */
    public array $profesionales = [];
    /** @var array<int, array<int, HorarioProfesional>> */
    public array $horarios = [];
    /** @var array<int, array<int, DescansoProfesional>> */
    public array $descansos = [];
    /** @var array<int, array<int, BloqueoProfesional>> */
    public array $bloqueos = [];

    public bool $throwOnQuery = false;
    public bool $throwOnWrite = false;
    private int $autoId = 1;

    public function __construct()
    {
        parent::__construct(null);
    }

    public function findAll(bool $soloActivos = false): array
    {
        if ($this->throwOnQuery) {
            throw new PersistenceException('Simulated SQL error on findAll: Table profesionales crashed');
        }
        $res = array_values($this->profesionales);
        if ($soloActivos) {
            $res = array_values(array_filter($res, fn(Profesional $p) => $p->estaActivo()));
        }
        return $res;
    }

    public function findById(int $id): ?Profesional
    {
        if ($this->throwOnQuery) {
            throw new PersistenceException('Simulated SQL error on findById');
        }
        return isset($this->profesionales[$id]) ? clone $this->profesionales[$id] : null;
    }

    public function createWithServicios(Profesional $profesional, array $servicioIds): int
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on createWithServicios');
        }
        $id = $this->autoId++;
        $profesional->id = (string)$id;
        $profesional->servicioIds = array_values(array_unique(array_map('intval', $servicioIds)));
        $this->profesionales[$id] = clone $profesional;
        return $id;
    }

    public function updateWithServicios(Profesional $profesional, array $servicioIds): bool
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on updateWithServicios');
        }
        $id = (int)$profesional->id;
        if (!isset($this->profesionales[$id])) {
            return false;
        }
        $profesional->servicioIds = array_values(array_unique(array_map('intval', $servicioIds)));
        $this->profesionales[$id] = clone $profesional;
        return true;
    }

    public function updateActivo(int $id, int $activo): bool
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on updateActivo');
        }
        if (!isset($this->profesionales[$id])) {
            return false;
        }
        $this->profesionales[$id]->activo = (string)$activo;
        return true;
    }

    public function findHorariosByProfesional(int $profesionalId): array
    {
        if ($this->throwOnQuery) {
            throw new PersistenceException('Simulated SQL error on findHorarios');
        }
        return $this->horarios[$profesionalId] ?? [];
    }

    public function replaceHorarios(int $profesionalId, array $horarios): void
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on replaceHorarios');
        }
        $guardados = [];
        foreach ($horarios as $h) {
            $clon = clone $h;
            $clon->id = (string)($this->autoId++);
            $clon->profesionalId = (string)$profesionalId;
            $guardados[] = $clon;
        }
        $this->horarios[$profesionalId] = $guardados;
    }

    public function findDescansosByProfesional(int $profesionalId): array
    {
        if ($this->throwOnQuery) {
            throw new PersistenceException('Simulated SQL error on findDescansos');
        }
        return $this->descansos[$profesionalId] ?? [];
    }

    public function createDescanso(DescansoProfesional $descanso): int
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on createDescanso');
        }
        $id = $this->autoId++;
        $descanso->id = (string)$id;
        $pid = (int)$descanso->profesionalId;
        $this->descansos[$pid][] = clone $descanso;
        return $id;
    }

    public function deleteDescanso(int $profesionalId, int $descansoId): bool
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on deleteDescanso');
        }
        $lista = $this->descansos[$profesionalId] ?? [];
        foreach ($lista as $idx => $d) {
            if ((int)$d->id === $descansoId) {
                unset($this->descansos[$profesionalId][$idx]);
                $this->descansos[$profesionalId] = array_values($this->descansos[$profesionalId]);
                return true;
            }
        }
        return false;
    }

    public function findBloqueosByProfesional(int $profesionalId): array
    {
        if ($this->throwOnQuery) {
            throw new PersistenceException('Simulated SQL error on findBloqueos');
        }
        return $this->bloqueos[$profesionalId] ?? [];
    }

    public function createBloqueo(BloqueoProfesional $bloqueo): int
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on createBloqueo');
        }
        $id = $this->autoId++;
        $bloqueo->id = (string)$id;
        $pid = (int)$bloqueo->profesionalId;
        $this->bloqueos[$pid][] = clone $bloqueo;
        return $id;
    }

    public function deleteBloqueo(int $profesionalId, int $bloqueoId): bool
    {
        if ($this->throwOnWrite) {
            throw new PersistenceException('Simulated SQL error on deleteBloqueo');
        }
        $lista = $this->bloqueos[$profesionalId] ?? [];
        foreach ($lista as $idx => $b) {
            if ((int)$b->id === $bloqueoId) {
                unset($this->bloqueos[$profesionalId][$idx]);
                $this->bloqueos[$profesionalId] = array_values($this->bloqueos[$profesionalId]);
                return true;
            }
        }
        return false;
    }
}

class InMemoryServicioCatalogoStub extends ServicioRepository
{
    /** @var array<int, Servicio> */
    public array $servicios = [];

    public function __construct(array $servicios = [])
    {
        parent::__construct(null);
        foreach ($servicios as $s) {
            $this->servicios[(int)$s->id] = $s;
        }
    }

    public function findById(int $id): ?Servicio
    {
        return $this->servicios[$id] ?? null;
    }
}

class ProfesionalAgendaServiceTest extends TestCase
{
    public function testEntidadesDeAgendaYProfesionalEstanDesacopladasDeActiveRecord(): void
    {
        $this->assertFalse(is_subclass_of(Profesional::class, ActiveRecord::class));
        $this->assertFalse(is_subclass_of(HorarioProfesional::class, ActiveRecord::class));
        $this->assertFalse(is_subclass_of(DescansoProfesional::class, ActiveRecord::class));
        $this->assertFalse(is_subclass_of(BloqueoProfesional::class, ActiveRecord::class));
    }

    public function testProfesionalSincronizarEditableIgnoraIdYManejaNoEscalares(): void
    {
        $prof = new Profesional([
            'id' => 10,
            'nombre' => 'Ana Torres',
            'activo' => 1
        ]);

        $prof->sincronizarEditable([
            'id' => 999,
            'nombre' => '  Ana María Torres  ',
            'activo' => '0',
            'creado_en' => '2020-01-01 00:00:00'
        ]);

        $this->assertSame('10', $prof->id);
        $this->assertSame('Ana María Torres', $prof->nombre);
        $this->assertSame('0', $prof->activo);
        $this->assertFalse($prof->estaActivo());

        $prof->sincronizar([
            'id' => ['inyeccion'],
            'nombre' => ['array'],
            'activo' => ['array']
        ]);
        $this->assertSame('10', $prof->id);
        $this->assertSame('', $prof->nombre);
        $this->assertSame('', $prof->activo);
    }

    public function testCrearYActualizarProfesionalValidaServiciosYEvitaSobrescribirId(): void
    {
        $repoProf = new InMemoryProfesionalRepositoryStub();
        $repoServ = new InMemoryServicioCatalogoStub([
            new Servicio(['id' => 1, 'nombre' => 'Corte', 'precio' => '50.00', 'duracion_minutos' => '30']),
            new Servicio(['id' => 2, 'nombre' => 'Barba', 'precio' => '40.00', 'duracion_minutos' => '20'])
        ]);
        $service = new ProfesionalService($repoProf, $repoServ);

        // 1. Rechazo de nombre vacío o servicio inexistente
        $invalidoNombre = $service->crear(['nombre' => '   ', 'servicios' => [1]]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $invalidoNombre['status']);

        $servicioNoExiste = $service->crear(['nombre' => 'Laura Gómez', 'servicios' => [1, 999]]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $servicioNoExiste['status']);
        $this->assertStringContainsString('no existen', $servicioNoExiste['alertas']['error'][0]);

        // 2. Creación válida desduplicando servicios e ignorando id enviado
        $creado = $service->crear([
            'id' => 777,
            'nombre' => 'Laura Gómez',
            'activo' => '1',
            'servicios' => ['1', '2', '1']
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $creado['status']);
        $this->assertSame(1, $creado['id']);
        $this->assertSame([1, 2], $creado['profesional']->servicioIds);

        // 3. Desactivación lógica preservando servicios asociados
        $desactivado = $service->cambiarEstado(1, 0);
        $this->assertSame(ProfesionalService::STATUS_OK, $desactivado['status']);
        $this->assertFalse($repoProf->findById(1)->estaActivo());
        $this->assertSame([1, 2], $repoProf->findById(1)->servicioIds);
    }

    public function testHorariosSemanalesValidaIntervalosRechazaInvertidosYSolapados(): void
    {
        $repoProf = new InMemoryProfesionalRepositoryStub();
        $repoServ = new InMemoryServicioCatalogoStub();
        $service = new ProfesionalService($repoProf, $repoServ);

        $idProf = $service->crear(['nombre' => 'Estilista Uno'])['id'];

        // 1. Rechazo de horario invertido (hora_inicio > hora_fin)
        $invertido = $service->guardarHorariosSemanales($idProf, [
            1 => ['activo' => '1', 'dia_semana' => 1, 'hora_inicio' => '18:00', 'hora_fin' => '09:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $invertido['status']);
        $this->assertStringContainsString('estrictamente anterior', $invertido['alertas']['error'][0]);

        // 2. Rechazo de horario de duración cero (hora_inicio == hora_fin)
        $duracionCero = $service->guardarHorariosSemanales($idProf, [
            2 => ['activo' => '1', 'dia_semana' => 2, 'hora_inicio' => '10:00', 'hora_fin' => '10:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $duracionCero['status']);

        // 3. Rechazo de franjas solapadas dentro del mismo día
        $solapados = $service->guardarHorariosSemanales($idProf, [
            ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '14:00'],
            ['dia_semana' => 1, 'hora_inicio' => '13:30', 'hora_fin' => '18:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $solapados['status']);
        $this->assertStringContainsString('solapados', $solapados['alertas']['error'][0]);

        // 4. Configuración válida de lunes a viernes
        $valido = $service->guardarHorariosSemanales($idProf, [
            1 => ['activo' => '1', 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '18:00'],
            2 => ['activo' => '1', 'dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '18:00'],
            3 => ['activo' => '0', 'dia_semana' => 3, 'hora_inicio' => '09:00', 'hora_fin' => '18:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $valido['status']);
        $this->assertCount(2, $valido['horarios']);
    }

    public function testDescansosYCompatibilidadMutuaConHorariosSemanales(): void
    {
        $repoProf = new InMemoryProfesionalRepositoryStub();
        $repoServ = new InMemoryServicioCatalogoStub();
        $service = new ProfesionalService($repoProf, $repoServ);

        $idProf = $service->crear(['nombre' => 'Estilista Dos'])['id'];
        $service->guardarHorariosSemanales($idProf, [
            1 => ['activo' => '1', 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '18:00']
        ]);

        // 1. Rechazo de descanso en día sin horario laboral configurado (Martes = 2)
        $sinHorario = $service->crearDescanso($idProf, [
            'dia_semana' => 2,
            'hora_inicio' => '13:00',
            'hora_fin' => '14:00',
            'motivo' => 'Almuerzo'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $sinHorario['status']);
        $this->assertStringContainsString('dentro del horario laboral', $sinHorario['alertas']['error'][0]);

        // 2. Rechazo de descanso fuera del rango del horario laboral (17:30 - 18:30)
        $fueraRango = $service->crearDescanso($idProf, [
            'dia_semana' => 1,
            'hora_inicio' => '17:30',
            'hora_fin' => '18:30'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $fueraRango['status']);

        // 3. Rechazo de descanso que cubre el 100% del turno laboral (09:00 - 18:00)
        $cubreTodo = $service->crearDescanso($idProf, [
            'dia_semana' => 1,
            'hora_inicio' => '09:00',
            'hora_fin' => '18:00'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $cubreTodo['status']);
        $this->assertStringContainsString('totalidad', $cubreTodo['alertas']['error'][0]);

        // 4. Creación válida de descanso (13:00 - 14:00)
        $descansoOk = $service->crearDescanso($idProf, [
            'dia_semana' => 1,
            'hora_inicio' => '13:00',
            'hora_fin' => '14:00',
            'motivo' => 'Almuerzo'
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $descansoOk['status']);

        // 5. Rechazo de segundo descanso solapado en el mismo día (13:30 - 14:30)
        $descansoSolapado = $service->crearDescanso($idProf, [
            'dia_semana' => 1,
            'hora_inicio' => '13:30',
            'hora_fin' => '14:30'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $descansoSolapado['status']);
        $this->assertStringContainsString('solapa', $descansoSolapado['alertas']['error'][0]);

        // 6. Rechazo de cambio de horario semanal que dejaría el descanso de 13:00-14:00 fuera de turno
        $horarioIncompatible = $service->guardarHorariosSemanales($idProf, [
            1 => ['activo' => '1', 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '12:30']
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $horarioIncompatible['status']);
        $this->assertStringContainsString('incompatible con el descanso', $horarioIncompatible['alertas']['error'][0]);
    }

    public function testBloqueosYCoherenciaDeZonaHorariaAmericaGuayaquilConCitaService(): void
    {
        // Instante donde en UTC ya es viernes 2026-10-16 02:30:00,
        // pero en America/Guayaquil (UTC-5) todavía es jueves 2026-10-15 21:30:00.
        $instanteUtc = new \DateTimeImmutable('2026-10-16 02:30:00', new \DateTimeZone('UTC'));

        $repoProf = new InMemoryProfesionalRepositoryStub();
        $repoServ = new InMemoryServicioCatalogoStub();
        $profService = new ProfesionalService($repoProf, $repoServ, $instanteUtc);
        $citaService = new CitaService(null, null, $instanteUtc);

        $this->assertSame('America/Guayaquil', $profService->obtenerZonaHoraria()->getName());
        $this->assertSame('America/Guayaquil', $citaService->obtenerZonaHoraria()->getName());
        $this->assertSame('2026-10-15', $profService->obtenerAhora()->format('Y-m-d'));
        $this->assertSame('2026-10-15', $citaService->obtenerAhora()->format('Y-m-d'));
        $this->assertSame(
            $citaService->obtenerDiaSemanaIso('2026-10-15'),
            $profService->obtenerDiaSemanaIso('2026-10-15')
        );
        $this->assertSame(4, $profService->obtenerDiaSemanaIso('2026-10-15')); // Jueves = 4

        $idProf = $profService->crear(['nombre' => 'Estilista Tres'])['id'];

        // 1. Fecha 2026-10-14 es pasada en America/Guayaquil -> rechazada
        $pasado = $profService->crearBloqueo($idProf, [
            'fecha_inicio' => '2026-10-14',
            'fecha_fin' => '2026-10-14',
            'motivo' => 'Fecha pasada'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $pasado['status']);
        $this->assertStringContainsString('America/Guayaquil', $pasado['alertas']['error'][0]);

        // 2. Fecha 2026-10-15 es hoy en America/Guayaquil (aunque en UTC sea 2026-10-16) -> permitida para bloqueo parcial
        $bloqueoHoy = $profService->crearBloqueo($idProf, [
            'fecha_inicio' => '2026-10-15',
            'fecha_fin' => '2026-10-15',
            'hora_inicio' => '15:00',
            'hora_fin' => '17:00',
            'motivo' => 'Permiso médico'
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $bloqueoHoy['status']);

        // 3. Rechazo de bloqueo solapado en la misma fecha y franja (16:00 - 18:00) o bloqueo de día completo en 2026-10-15
        $solapadoParcial = $profService->crearBloqueo($idProf, [
            'fecha_inicio' => '2026-10-15',
            'fecha_fin' => '2026-10-15',
            'hora_inicio' => '16:00',
            'hora_fin' => '18:00'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $solapadoParcial['status']);

        $solapadoDiaCompleto = $profService->crearBloqueo($idProf, [
            'fecha_inicio' => '2026-10-15',
            'fecha_fin' => '2026-10-15'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $solapadoDiaCompleto['status']);

        // 4. Otro intervalo no solapado el mismo día (10:00 - 12:00) sí es compatible
        $otroIntervaloCompatible = $profService->crearBloqueo($idProf, [
            'fecha_inicio' => '2026-10-15',
            'fecha_fin' => '2026-10-15',
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00'
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $otroIntervaloCompatible['status']);

        // 5. Rechazo de rango de fechas invertido y de hora_inicio sin hora_fin
        $fechasInvertidas = $profService->crearBloqueo($idProf, [
            'fecha_inicio' => '2026-10-25',
            'fecha_fin' => '2026-10-20'
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $fechasInvertidas['status']);

        $horaIncompleta = $profService->crearBloqueo($idProf, [
            'fecha_inicio' => '2026-10-25',
            'fecha_fin' => '2026-10-25',
            'hora_inicio' => '10:00',
            'hora_fin' => ''
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $horaIncompleta['status']);
    }

    public function testManejoExplicitoDeFallosDePersistenciaSinFiltrarDetallesSql(): void
    {
        $repoProf = new InMemoryProfesionalRepositoryStub();
        $repoServ = new InMemoryServicioCatalogoStub();
        $service = new ProfesionalService($repoProf, $repoServ);

        $repoProf->throwOnQuery = true;
        $resListar = $service->listar();
        $this->assertSame(ProfesionalService::STATUS_ERROR, $resListar['status']);
        $this->assertStringNotContainsString('crashed', $resListar['alertas']['error'][0]);

        $repoProf->throwOnQuery = false;
        $repoProf->throwOnWrite = true;
        $resCrear = $service->crear(['nombre' => 'Profesional Fallido']);
        $this->assertSame(ProfesionalService::STATUS_ERROR, $resCrear['status']);
    }

    public function testGuardarHorariosRechazaEntradasMalformadasYAdmiteMultiplesFranjasYDesactivacionExplicita(): void
    {
        $repoProf = new InMemoryProfesionalRepositoryStub();
        $repoServ = new InMemoryServicioCatalogoStub();
        $service = new ProfesionalService($repoProf, $repoServ);

        $idProf = $service->crear(['nombre' => 'Estilista Tres'])['id'];

        // 1. Configurar dos franjas el mismo día (Lunes: 08:00-12:00 y 14:00-18:00)
        $dosFranjas = $service->guardarHorariosSemanales($idProf, [
            '1_0' => ['enviado' => '1', 'dia_semana' => 1, 'activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '12:00'],
            '1_1' => ['enviado' => '1', 'dia_semana' => 1, 'activo' => '1', 'hora_inicio' => '14:00', 'hora_fin' => '18:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $dosFranjas['status']);
        $this->assertCount(2, $repoProf->findHorariosByProfesional($idProf));

        // 2. Rechazo de horarios escalar o vacío sin modificar horarios existentes
        $resEscalar = $service->guardarHorariosSemanales($idProf, 'invalido');
        $this->assertSame(ProfesionalService::STATUS_INVALID, $resEscalar['status']);
        $this->assertCount(2, $repoProf->findHorariosByProfesional($idProf));

        $resVacio = $service->guardarHorariosSemanales($idProf, []);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $resVacio['status']);
        $this->assertCount(2, $repoProf->findHorariosByProfesional($idProf));

        // 3. Rechazo de valor inválido en activo sin interpretarlo como desactivación
        $resActivoInvalido = $service->guardarHorariosSemanales($idProf, [
            '1_0' => ['enviado' => '1', 'dia_semana' => 1, 'activo' => 'invalido', 'hora_inicio' => '08:00', 'hora_fin' => '12:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_INVALID, $resActivoInvalido['status']);
        $this->assertCount(2, $repoProf->findHorariosByProfesional($idProf));

        // 4. Desactivación explícita de todos los días mediante envío válido del formulario
        $desactivarTodos = $service->guardarHorariosSemanales($idProf, [
            '1_0' => ['enviado' => '1', 'dia_semana' => 1, 'activo' => '0', 'hora_inicio' => '08:00', 'hora_fin' => '12:00'],
            '2_0' => ['enviado' => '1', 'dia_semana' => 2, 'activo' => '0', 'hora_inicio' => '09:00', 'hora_fin' => '18:00']
        ]);
        $this->assertSame(ProfesionalService::STATUS_OK, $desactivarTodos['status']);
        $this->assertCount(0, $repoProf->findHorariosByProfesional($idProf));
    }
}
