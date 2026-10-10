<?php

namespace Services;

use Model\Cita;
use Model\HorarioProfesional;
use Repositories\CitaRepository;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;

/**
 * Servicio de dominio para el cálculo de disponibilidad por profesional (Fases 4A y 4B).
 *
 * Responsabilidades:
 * 1. Exigir un profesional existente y activo (`activo = 1`) que pueda realizar todos los servicios solicitados.
 * 2. Calcular la duración total exclusivamente desde el catálogo (`servicios.duracion_minutos`),
 *    ignorando cualquier duración enviada por el cliente.
 * 3. Evaluar la fecha y los intervalos explícitamente en la zona horaria `America/Guayaquil`
 *    considerando todas las franjas laborales configuradas para el día de la semana.
 * 4. Excluir descansos recurrentes (`descansos_profesionales`), bloqueos puntuales o de rango
 *    (`bloqueos_profesionales`) y reservas existentes (`citas`) del profesional en la fecha consultada.
 * 5. Devolver únicamente intervalos donde quepa la duración combinada completa dentro de una misma
 *    ventana libre continua, sin atravesar descansos, bloqueos, citas existentes ni huecos entre turnos.
 * 6. Emplear semántica de intervalos semiabiertos `[inicio, fin)`, permitiendo que una atención
 *    termine exactamente cuando comienza un descanso, bloqueo, cita existente o fin de turno (`fin == restriccion_inicio`)
 *    y que otra comience exactamente cuando termina una restricción o cita previa (`inicio == restriccion_fin`).
 *
 * Supuesto configurable sobre el paso entre horas de inicio:
 * - `DEFAULT_PASO_MINUTOS = 15`: Valor inicial de 15 minutos entre posibles horas de inicio dentro de
 *   cada ventana libre continua, declarado explícitamente como **supuesto técnico configurable**
 *   (parametrizable por constructor, `setPasoMinutos()`, variable de entorno `AGENDA_PASO_MINUTOS`
 *   o parámetro de consulta) y no como una regla fija confirmada del negocio.
 *
 * - No accede directamente a superglobales HTTP (`$_GET`, `$_POST`, `$_SESSION`).
 */
class DisponibilidadService
{
    public const STATUS_OK = 'ok';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_PROFESSIONAL_NOT_FOUND = 'professional_not_found';
    public const STATUS_PROFESSIONAL_INACTIVE = 'professional_inactive';
    public const STATUS_INCOMPATIBLE_SERVICES = 'incompatible_services';
    public const STATUS_ERROR = 'error';

    public const CODIGO_DISPONIBILIDAD_ENCONTRADA = 'disponibilidad_encontrada';
    public const CODIGO_DISPONIBILIDAD_VACIA = 'disponibilidad_vacia';
    public const CODIGO_SOLICITUD_INVALIDA = 'solicitud_invalida';
    public const CODIGO_PROFESIONAL_NO_ENCONTRADO = 'profesional_no_encontrado';
    public const CODIGO_PROFESIONAL_INACTIVO = 'profesional_inactivo';
    public const CODIGO_SERVICIOS_INCOMPATIBLES = 'servicios_incompatibles';
    public const CODIGO_ERROR_PERSISTENCIA = 'error_persistencia';

    public const TIMEZONE = 'America/Guayaquil';

    /**
     * Supuesto técnico configurable: paso inicial de 15 minutos entre horas de inicio candidatas.
     */
    public const DEFAULT_PASO_MINUTOS = 15;

    public const AVISO_ALCANCE_FASE_4A = 'Disponibilidad calculada según la configuración de agenda y citas registradas del profesional; la confirmación definitiva de una reserva se realiza transaccionalmente al guardar.';

    private ProfesionalRepository $profesionalRepository;
    private ServicioRepository $servicioRepository;
    private ?CitaRepository $citaRepository;
    private int $pasoMinutos;

    public function __construct(
        ?ProfesionalRepository $profesionalRepository = null,
        ?ServicioRepository $servicioRepository = null,
        ?int $pasoMinutos = null,
        ?CitaRepository $citaRepository = null
    ) {
        $this->profesionalRepository = $profesionalRepository ?? new ProfesionalRepository();
        $db = $this->profesionalRepository->getDb();
        $this->servicioRepository = $servicioRepository ?? new ServicioRepository($db);
        if ($citaRepository !== null) {
            $this->citaRepository = $citaRepository;
        } elseif ($db instanceof \mysqli) {
            $this->citaRepository = new CitaRepository($db);
        } else {
            $this->citaRepository = null;
        }
        $this->pasoMinutos = $this->resolverPasoInicial($pasoMinutos);
    }

    /**
     * Resuelve el paso inicial en minutos desde el argumento del constructor, variable de entorno
     * `AGENDA_PASO_MINUTOS` o el supuesto configurable `DEFAULT_PASO_MINUTOS` (15 min).
     */
    private function resolverPasoInicial(?int $pasoMinutos): int
    {
        if ($pasoMinutos !== null) {
            if ($pasoMinutos >= 1 && $pasoMinutos <= 1440) {
                return $pasoMinutos;
            }
            return self::DEFAULT_PASO_MINUTOS;
        }

        $envPaso = $_ENV['AGENDA_PASO_MINUTOS'] ?? getenv('AGENDA_PASO_MINUTOS');
        if ($envPaso !== false && $envPaso !== null && $envPaso !== '') {
            $validado = $this->validarPasoMinutos($envPaso);
            if ($validado !== null) {
                return $validado;
            }
        }

        return self::DEFAULT_PASO_MINUTOS;
    }

    public function getPasoMinutos(): int
    {
        return $this->pasoMinutos;
    }

    public function setPasoMinutos(int $pasoMinutos): void
    {
        if ($pasoMinutos < 1 || $pasoMinutos > 1440) {
            throw new \InvalidArgumentException('El paso entre horas de inicio debe estar entre 1 y 1440 minutos.');
        }
        $this->pasoMinutos = $pasoMinutos;
    }

    /**
     * Retorna la zona horaria oficial de la agenda (`America/Guayaquil`).
     */
    public function obtenerZonaHoraria(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /**
     * Calcula el día de la semana ISO-8601 (`1 = Lunes` ... `7 = Domingo`) para una fecha `AAAA-MM-DD`
     * evaluada explícitamente en `America/Guayaquil`.
     */
    public function obtenerDiaSemanaIso($fechaRaw): ?int
    {
        if (!is_string($fechaRaw)) {
            return null;
        }
        $fecha = trim($fechaRaw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            return null;
        }
        $partes = explode('-', $fecha);
        if (!checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0])) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $this->obtenerZonaHoraria());
        return $dt ? (int)$dt->format('N') : null;
    }

    /**
     * Valida que un identificador sea un entero positivo escalar (`>= 1`).
     */
    public function validarId($idRaw): ?int
    {
        if (is_int($idRaw)) {
            return ($idRaw >= 1 && $idRaw <= 2147483647) ? $idRaw : null;
        }

        if (!is_string($idRaw)) {
            return null;
        }

        $trimmed = trim($idRaw);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }

        $val = filter_var($trimmed, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 2147483647
            ]
        ]);

        return $val === false ? null : $val;
    }

    /**
     * Valida que el paso entre horas de inicio sea un entero positivo entre 1 y 1440 minutos.
     */
    public function validarPasoMinutos($pasoRaw): ?int
    {
        $val = $this->validarId($pasoRaw);
        if ($val === null || $val > 1440) {
            return null;
        }
        return $val;
    }

    /**
     * Valida que la duración de un servicio en el catálogo sea un entero positivo válido
     * conforme al dominio de `ServicioService` (`1` a `2147483647`).
     * Rechaza valores corruptos (cero, negativos, decimales o no enteros).
     */
    public function validarDuracionCatalogo($duracionRaw): ?int
    {
        return $this->validarId($duracionRaw);
    }

    /**
     * Extrae y valida la lista de identificadores de servicios solicitados.
     * Ignora deliberadamente cualquier propiedad `duracion` o `duracion_minutos` enviada por el cliente.
     *
     * Formatos admitidos:
     * - Cadena CSV de enteros positivos: `"1,2,3"` o `"1"`
     * - Arreglo de enteros positivos o cadenas numéricas: `[1, 2]` o `["1", "2"]`
     * - Arreglo de elementos asociativos con clave `'id'`: `[['id' => 1, 'duracion_minutos' => 999], ...]`
     *
     * @param mixed $serviciosRaw
     * @return array<int, int>|null Lista desduplicada de IDs enteros positivos, o `null` si la entrada es inválida o vacía.
     */
    public function parsearServiciosSolicitados($serviciosRaw): ?array
    {
        if (is_string($serviciosRaw)) {
            $trimmed = trim($serviciosRaw);
            if ($trimmed === '') {
                return null;
            }
            $partes = explode(',', $trimmed);
            $ids = [];
            foreach ($partes as $parte) {
                $idVal = $this->validarId($parte);
                if ($idVal === null) {
                    return null;
                }
                $ids[] = $idVal;
            }
            return empty($ids) ? null : array_values(array_unique($ids));
        }

        if (is_array($serviciosRaw)) {
            if (empty($serviciosRaw)) {
                return null;
            }
            $ids = [];
            foreach ($serviciosRaw as $item) {
                $candidatoId = $item;
                if (is_array($item)) {
                    if (!array_key_exists('id', $item)) {
                        return null;
                    }
                    $candidatoId = $item['id'];
                } elseif (is_object($item) && isset($item->id)) {
                    $candidatoId = $item->id;
                }

                $idVal = $this->validarId($candidatoId);
                if ($idVal === null) {
                    return null;
                }
                $ids[] = $idVal;
            }
            return empty($ids) ? null : array_values(array_unique($ids));
        }

        return null;
    }

    /**
     * Convierte una hora `HH:MM` (o `HH:MM:SS`) a minutos desde las 00:00.
     */
    public static function horaAMinutos(string $hora): int
    {
        $normalizada = HorarioProfesional::normalizarHora($hora);
        $partes = explode(':', $normalizada);
        return ((int)$partes[0] * 60) + (int)$partes[1];
    }

    /**
     * Convierte minutos desde las 00:00 al formato `HH:MM`.
     */
    public static function minutosAHora(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $mins = $minutos % 60;
        return sprintf('%02d:%02d', $horas, $mins);
    }

    /**
     * Resta un conjunto de restricciones `[inicio, fin)` a una ventana laboral `[turnoInicio, turnoFin)`
     * empleando semántica de intervalos semiabiertos `[inicio, fin)`.
     *
     * Dos intervalos semiabiertos `[A, B)` y `[R_inicio, R_fin)` se solapan si y solo si:
     * `A < R_fin && R_inicio < B`.
     * Si `R_fin == A` o `R_inicio == B`, solo se tocan en el borde exacto y NO se solapan.
     *
     * @param int $turnoInicio Minuto de inicio de la franja laboral.
     * @param int $turnoFin Minuto de fin de la franja laboral.
     * @param array<int, array{inicio: int, fin: int}> $restricciones
     * @return array<int, array{inicio: int, fin: int}> Ventanas libres continuas resultantes dentro del turno.
     */
    public function restarRestriccionesDeTurno(int $turnoInicio, int $turnoFin, array $restricciones): array
    {
        if ($turnoInicio >= $turnoFin) {
            return [];
        }

        $ventanasLibres = [
            ['inicio' => $turnoInicio, 'fin' => $turnoFin]
        ];

        foreach ($restricciones as $restriccion) {
            $rInicio = (int)($restriccion['inicio'] ?? 0);
            $rFin = (int)($restriccion['fin'] ?? 0);
            if ($rInicio >= $rFin) {
                continue;
            }

            $nuevasVentanas = [];
            foreach ($ventanasLibres as $ventana) {
                $vInicio = $ventana['inicio'];
                $vFin = $ventana['fin'];

                // Sin solapamiento en semántica [inicio, fin)
                if ($rFin <= $vInicio || $rInicio >= $vFin) {
                    $nuevasVentanas[] = $ventana;
                    continue;
                }

                // Remanente izquierdo [vInicio, rInicio)
                if ($rInicio > $vInicio) {
                    $nuevasVentanas[] = [
                        'inicio' => $vInicio,
                        'fin' => $rInicio
                    ];
                }

                // Remanente derecho [rFin, vFin)
                if ($rFin < $vFin) {
                    $nuevasVentanas[] = [
                        'inicio' => $rFin,
                        'fin' => $vFin
                    ];
                }
            }

            $ventanasLibres = $nuevasVentanas;
            if (empty($ventanasLibres)) {
                break;
            }
        }

        return $ventanasLibres;
    }

    /**
     * Consulta la disponibilidad de un profesional a partir de un arreglo de parámetros
     * (por ejemplo, query string HTTP ya extraído por el controlador).
     * Ignora cualquier parámetro de duración enviado por el cliente (`duracion`, `duracion_minutos`, etc.).
     *
     * @param array $parametros
     * @return array
     */
    public function consultarDesdeParametros(array $parametros): array
    {
        $profesionalIdRaw = null;
        if (array_key_exists('profesionalId', $parametros)) {
            $profesionalIdRaw = $parametros['profesionalId'];
        } elseif (array_key_exists('profesional_id', $parametros)) {
            $profesionalIdRaw = $parametros['profesional_id'];
        } elseif (array_key_exists('profesional', $parametros)) {
            $profesionalIdRaw = $parametros['profesional'];
        }

        $fechaRaw = $parametros['fecha'] ?? null;
        $serviciosRaw = $parametros['servicios'] ?? null;

        $pasoCustom = null;
        if (array_key_exists('paso_minutos', $parametros)) {
            $pasoValidado = $this->validarPasoMinutos($parametros['paso_minutos']);
            if ($pasoValidado === null) {
                return $this->construirRespuestaError(
                    self::STATUS_INVALID,
                    self::CODIGO_SOLICITUD_INVALIDA,
                    422,
                    'El parámetro paso_minutos debe ser un entero positivo entre 1 y 1440.'
                );
            }
            $pasoCustom = $pasoValidado;
        }

        return $this->consultar($profesionalIdRaw, $fechaRaw, $serviciosRaw, $pasoCustom);
    }

    /**
     * Calcula los intervalos disponibles `[inicio, fin)` para un profesional, una fecha `AAAA-MM-DD`
     * y una selección de servicios.
     *
     * @param mixed $profesionalIdRaw Identificador del profesional.
     * @param mixed $fechaRaw Fecha en formato `AAAA-MM-DD` (evaluada en `America/Guayaquil`).
     * @param mixed $serviciosRaw IDs de servicios seleccionados (array o string CSV).
     * @param int|null $pasoMinutosOverride Paso opcional en minutos entre horas de inicio; si es null, usa la configuración del servicio (`DEFAULT_PASO_MINUTOS = 15`).
     * @return array Resultado estructurado diferenciando solicitud inválida, profesional inexistente, profesional inactivo, servicios incompatibles, disponibilidad vacía, disponibilidad con intervalos y fallo SQL.
     */
    public function consultar($profesionalIdRaw, $fechaRaw, $serviciosRaw, ?int $pasoMinutosOverride = null): array
    {
        // 1. Validación estructural de entradas (antes de consultar la base de datos)
        $profesionalId = $this->validarId($profesionalIdRaw);
        if ($profesionalId === null) {
            return $this->construirRespuestaError(
                self::STATUS_INVALID,
                self::CODIGO_SOLICITUD_INVALIDA,
                422,
                'El identificador del profesional es obligatorio y debe ser un entero positivo.'
            );
        }

        if (!is_string($fechaRaw) || trim($fechaRaw) === '') {
            return $this->construirRespuestaError(
                self::STATUS_INVALID,
                self::CODIGO_SOLICITUD_INVALIDA,
                422,
                'La fecha es obligatoria y debe tener el formato AAAA-MM-DD.'
            );
        }

        $fecha = trim($fechaRaw);
        $diaSemanaIso = $this->obtenerDiaSemanaIso($fecha);
        if ($diaSemanaIso === null) {
            return $this->construirRespuestaError(
                self::STATUS_INVALID,
                self::CODIGO_SOLICITUD_INVALIDA,
                422,
                'La fecha indicada no es válida. Use una fecha de calendario real en formato AAAA-MM-DD.'
            );
        }

        $servicioIds = $this->parsearServiciosSolicitados($serviciosRaw);
        if ($servicioIds === null) {
            return $this->construirRespuestaError(
                self::STATUS_INVALID,
                self::CODIGO_SOLICITUD_INVALIDA,
                422,
                'Debe seleccionar al menos un servicio válido mediante identificadores enteros positivos.'
            );
        }

        $pasoEfectivo = $pasoMinutosOverride ?? $this->pasoMinutos;
        if ($pasoEfectivo < 1 || $pasoEfectivo > 1440) {
            return $this->construirRespuestaError(
                self::STATUS_INVALID,
                self::CODIGO_SOLICITUD_INVALIDA,
                422,
                'El paso entre horas de inicio debe ser un entero positivo entre 1 y 1440 minutos.'
            );
        }

        // 2. Consultas al repositorio y validación de reglas de dominio
        try {
            $profesional = $this->profesionalRepository->findById($profesionalId);
            if ($profesional === null) {
                return $this->construirRespuestaError(
                    self::STATUS_PROFESSIONAL_NOT_FOUND,
                    self::CODIGO_PROFESIONAL_NO_ENCONTRADO,
                    404,
                    'El profesional seleccionado no existe.'
                );
            }

            if ((int)$profesional->activo !== 1) {
                return $this->construirRespuestaError(
                    self::STATUS_PROFESSIONAL_INACTIVE,
                    self::CODIGO_PROFESIONAL_INACTIVO,
                    409,
                    'El profesional seleccionado se encuentra inactivo.'
                );
            }

            // Cargar cada servicio desde el catálogo oficial e ignorar duraciones del cliente
            $duracionTotalMinutos = 0;
            foreach ($servicioIds as $sid) {
                $servicio = $this->servicioRepository->findById($sid);
                if ($servicio === null) {
                    return $this->construirRespuestaError(
                        self::STATUS_INVALID,
                        self::CODIGO_SOLICITUD_INVALIDA,
                        422,
                        'Uno o más servicios seleccionados no existen en el catálogo.'
                    );
                }

                $duracionCatalogo = $this->validarDuracionCatalogo($servicio->duracion_minutos);
                if ($duracionCatalogo === null) {
                    throw new PersistenceException("Duración inválida en catálogo para el servicio ID {$sid}.");
                }

                $duracionTotalMinutos += $duracionCatalogo;
            }

            // Verificar que el profesional activo pueda realizar TODOS los servicios solicitados
            $serviciosHabilitados = array_map('intval', $profesional->servicioIds);
            $serviciosIncompatibles = array_values(array_diff($servicioIds, $serviciosHabilitados));
            if (!empty($serviciosIncompatibles)) {
                return [
                    'status' => self::STATUS_INCOMPATIBLE_SERVICES,
                    'codigo' => self::CODIGO_SERVICIOS_INCOMPATIBLES,
                    'httpCode' => 422,
                    'resultado' => false,
                    'disponible' => false,
                    'profesionalId' => $profesionalId,
                    'fecha' => $fecha,
                    'servicios' => $servicioIds,
                    'servicios_incompatibles' => $serviciosIncompatibles,
                    'intervalos' => [],
                    'error' => 'El profesional seleccionado no realiza todos los servicios solicitados.'
                ];
            }

            // Consultar todas las franjas laborales del día, descansos del día, bloqueos y citas existentes
            $horariosDia = $this->profesionalRepository->findHorariosByProfesionalYDia($profesionalId, $diaSemanaIso);
            $descansosDia = $this->profesionalRepository->findDescansosByProfesionalYDia($profesionalId, $diaSemanaIso);
            $bloqueosFecha = $this->profesionalRepository->findBloqueosByProfesionalEnFecha($profesionalId, $fecha);
            $citasOcupadas = $this->citaRepository !== null
                ? $this->citaRepository->findOcupacionByProfesionalEnFecha($profesionalId, $fecha)
                : [];

            $restriccionesCitas = [];
            foreach ($citasOcupadas as $citaOcupada) {
                $intervaloCita = $this->extraerIntervaloOcupadoDeCita($citaOcupada);
                if ($intervaloCita !== null) {
                    $restriccionesCitas[] = $intervaloCita;
                }
            }
        } catch (PersistenceException $e) {
            return $this->construirRespuestaError(
                self::STATUS_ERROR,
                self::CODIGO_ERROR_PERSISTENCIA,
                500,
                'No fue posible consultar la disponibilidad en este momento.'
            );
        }

        // 3. Construir restricciones aplicables al día en America/Guayaquil (descansos + bloqueos + citas existentes)
        $restricciones = [];

        foreach ($descansosDia as $descanso) {
            if ((int)$descanso->dia_semana !== $diaSemanaIso) {
                continue;
            }
            $dInicio = self::horaAMinutos($descanso->hora_inicio);
            $dFin = self::horaAMinutos($descanso->hora_fin);
            if ($dInicio < $dFin) {
                $restricciones[] = ['inicio' => $dInicio, 'fin' => $dFin];
            }
        }

        foreach ($bloqueosFecha as $bloqueo) {
            if ($fecha < (string)$bloqueo->fecha_inicio || $fecha > (string)$bloqueo->fecha_fin) {
                continue;
            }
            if ($bloqueo->esDiaCompleto()) {
                $restricciones[] = ['inicio' => 0, 'fin' => 1440];
            } else {
                $bInicio = self::horaAMinutos((string)$bloqueo->hora_inicio);
                $bFin = self::horaAMinutos((string)$bloqueo->hora_fin);
                if ($bInicio < $bFin) {
                    $restricciones[] = ['inicio' => $bInicio, 'fin' => $bFin];
                }
            }
        }

        foreach ($restriccionesCitas as $restriccionCita) {
            $restricciones[] = $restriccionCita;
        }

        // Ordenar franjas laborales por hora de inicio ascendente
        usort(
            $horariosDia,
            fn(HorarioProfesional $a, HorarioProfesional $b) => self::horaAMinutos($a->hora_inicio) <=> self::horaAMinutos($b->hora_inicio)
        );

        // 4. Evaluar cada franja laboral de manera independiente (sin fusionar turnos ni cruzar huecos)
        $tz = $this->obtenerZonaHoraria();
        $intervalosDisponibles = [];
        $clavesVistas = [];

        foreach ($horariosDia as $horario) {
            if ((int)$horario->dia_semana !== $diaSemanaIso) {
                continue;
            }

            $turnoInicio = self::horaAMinutos($horario->hora_inicio);
            $turnoFin = self::horaAMinutos($horario->hora_fin);
            if ($turnoInicio >= $turnoFin) {
                continue;
            }

            $ventanasLibres = $this->restarRestriccionesDeTurno($turnoInicio, $turnoFin, $restricciones);

            foreach ($ventanasLibres as $ventana) {
                $vInicio = $ventana['inicio'];
                $vFin = $ventana['fin'];

                if (($vFin - $vInicio) < $duracionTotalMinutos) {
                    continue;
                }

                for ($inicioMin = $vInicio; ($inicioMin + $duracionTotalMinutos) <= $vFin; $inicioMin += $pasoEfectivo) {
                    $finMin = $inicioMin + $duracionTotalMinutos;
                    $horaInicioStr = self::minutosAHora($inicioMin);
                    $horaFinStr = self::minutosAHora($finMin);

                    // Validar construcción temporal explícita en America/Guayaquil
                    $dtInicio = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $fecha . ' ' . $horaInicioStr, $tz);
                    if ($dtInicio === false) {
                        continue;
                    }
                    $dtFin = $dtInicio->modify('+' . $duracionTotalMinutos . ' minutes');

                    $inicioFormateado = $dtInicio->format('H:i');
                    $finFormateado = ($finMin === 1440) ? '24:00' : $dtFin->format('H:i');

                    $clave = $inicioFormateado . '-' . $finFormateado;
                    if (isset($clavesVistas[$clave])) {
                        continue;
                    }
                    $clavesVistas[$clave] = true;

                    $intervalosDisponibles[] = [
                        'inicio' => $inicioFormateado,
                        'fin' => $finFormateado,
                        'hora_inicio' => $inicioFormateado,
                        'hora_fin' => $finFormateado,
                        'duracion_minutos' => $duracionTotalMinutos,
                    ];
                }
            }
        }

        $hayDisponibilidad = !empty($intervalosDisponibles);

        return [
            'status' => $hayDisponibilidad ? self::STATUS_OK : self::STATUS_EMPTY,
            'codigo' => $hayDisponibilidad ? self::CODIGO_DISPONIBILIDAD_ENCONTRADA : self::CODIGO_DISPONIBILIDAD_VACIA,
            'httpCode' => 200,
            'resultado' => true,
            'disponible' => $hayDisponibilidad,
            'profesionalId' => $profesionalId,
            'fecha' => $fecha,
            'dia_semana' => $diaSemanaIso,
            'zona_horaria' => self::TIMEZONE,
            'paso_minutos' => $pasoEfectivo,
            'duracion_total_minutos' => $duracionTotalMinutos,
            'servicios' => $servicioIds,
            'intervalos' => $intervalosDisponibles,
            'aviso_alcance' => self::AVISO_ALCANCE_FASE_4A,
        ];
    }

    /**
     * Extrae el intervalo semiabierto `[inicio, fin)` en minutos de una cita ocupada del profesional.
     *
     * @param Cita $cita
     * @return array{inicio: int, fin: int}|null
     * @throws PersistenceException Si los datos de intervalo de la cita son corruptos.
     */
    private function extraerIntervaloOcupadoDeCita(Cita $cita): ?array
    {
        $horaInicioStr = ($cita->hora_inicio !== null && trim((string)$cita->hora_inicio) !== '')
            ? (string)$cita->hora_inicio
            : (string)$cita->hora;

        $normInicio = HorarioProfesional::normalizarHora($horaInicioStr);
        if ($normInicio === '') {
            throw new PersistenceException("Hora de inicio inválida en cita ocupada ID {$cita->id}.");
        }
        $inicioMin = self::horaAMinutos($normInicio);

        if ($cita->hora_fin !== null && trim((string)$cita->hora_fin) !== '') {
            $normFin = HorarioProfesional::normalizarHora((string)$cita->hora_fin);
            if ($normFin === '') {
                throw new PersistenceException("Hora de fin inválida en cita ocupada ID {$cita->id}.");
            }
            $finMin = self::horaAMinutos($normFin);
        } elseif ($cita->duracion_total_minutos !== null) {
            $dur = $this->validarDuracionCatalogo($cita->duracion_total_minutos);
            if ($dur === null) {
                throw new PersistenceException("Duración ocupada inválida en cita ID {$cita->id}.");
            }
            $finMin = $inicioMin + $dur;
        } else {
            throw new PersistenceException("Cita ocupada ID {$cita->id} sin intervalo de fin definido.");
        }

        if ($inicioMin >= $finMin) {
            throw new PersistenceException("Intervalo ocupado inválido en cita ID {$cita->id}.");
        }

        return ['inicio' => $inicioMin, 'fin' => $finMin];
    }

    private function construirRespuestaError(string $status, string $codigo, int $httpCode, string $mensaje): array
    {
        return [
            'status' => $status,
            'codigo' => $codigo,
            'httpCode' => $httpCode,
            'resultado' => false,
            'disponible' => false,
            'intervalos' => [],
            'error' => $mensaje,
        ];
    }
}
