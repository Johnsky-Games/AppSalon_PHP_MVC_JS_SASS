<?php

namespace Services;

use Model\BloqueoProfesional;
use Model\DescansoProfesional;
use Model\HorarioProfesional;
use Model\Profesional;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;

/**
 * Servicio de dominio para la gestión del catálogo de profesionales, asignación de servicios
 * y configuración de horarios semanales, descansos y bloqueos de agenda.
 *
 * Opera explícitamente en la zona horaria `America/Guayaquil` y no accede a superglobales
 * (`$_POST`, `$_GET`, `$_SESSION`).
 */
class ProfesionalService
{
    public const STATUS_OK = 'ok';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_ERROR = 'error';

    public const TIMEZONE = 'America/Guayaquil';
    public const MAX_NOMBRE_LENGTH = 120;
    public const MAX_MOTIVO_DESCANSO_LENGTH = 120;
    public const MAX_MOTIVO_BLOQUEO_LENGTH = 160;

    private ProfesionalRepository $profesionalRepository;
    private ServicioRepository $servicioRepository;
    private ?\DateTimeImmutable $ahoraReferencia;

    public function __construct(
        ?ProfesionalRepository $profesionalRepository = null,
        ?ServicioRepository $servicioRepository = null,
        ?\DateTimeImmutable $ahoraReferencia = null
    ) {
        $this->profesionalRepository = $profesionalRepository ?? new ProfesionalRepository();
        $this->servicioRepository = $servicioRepository ?? new ServicioRepository($this->profesionalRepository->getDb());
        $this->ahoraReferencia = $ahoraReferencia;
    }

    /**
     * Retorna la zona horaria oficial de la agenda (`America/Guayaquil`).
     */
    public function obtenerZonaHoraria(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /**
     * Retorna la fecha/hora actual evaluada explícitamente en `America/Guayaquil`.
     */
    public function obtenerAhora(): \DateTimeImmutable
    {
        $tz = $this->obtenerZonaHoraria();
        if ($this->ahoraReferencia !== null) {
            return $this->ahoraReferencia->setTimezone($tz);
        }
        return new \DateTimeImmutable('now', $tz);
    }

    /**
     * Calcula el día de la semana ISO-8601 (`1 = Lunes` ... `7 = Domingo`) para una fecha `AAAA-MM-DD`
     * evaluada en `America/Guayaquil`.
     */
    public function obtenerDiaSemanaIso(string $fecha): ?int
    {
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
     * Valida un día de la semana ISO-8601 (`1 = Lunes` ... `7 = Domingo`).
     */
    public function validarDiaSemana($diaRaw): ?int
    {
        if (is_int($diaRaw)) {
            return ($diaRaw >= 1 && $diaRaw <= 7) ? $diaRaw : null;
        }
        if (!is_string($diaRaw)) {
            return null;
        }
        $trimmed = trim($diaRaw);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }
        $val = (int)$trimmed;
        return ($val >= 1 && $val <= 7) ? $val : null;
    }

    /**
     * Valida y normaliza una hora en formato `HH:MM` (00:00 a 23:59).
     */
    public static function validarHora($horaRaw): ?string
    {
        if (!is_string($horaRaw)) {
            return null;
        }
        $normalizada = HorarioProfesional::normalizarHora($horaRaw);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $normalizada) !== 1) {
            return null;
        }
        return $normalizada;
    }

    /**
     * Convierte una hora `HH:MM` a minutos desde las 00:00 (0 a 1439).
     */
    public static function horaAMinutos(string $hora): int
    {
        $partes = explode(':', $hora);
        return ((int)$partes[0] * 60) + (int)$partes[1];
    }

    /**
     * Valida los datos básicos del profesional (`nombre` y `activo`) y la estructura de `servicios`.
     *
     * @param array $datos
     * @return array{errores: array<int, string>, activo: int, servicioIds: array<int, int>}
     */
    public function validarDatosProfesional(array $datos): array
    {
        $errores = [];

        // 1. Nombre
        $rawNombre = $datos['nombre'] ?? '';
        if (!is_string($rawNombre)) {
            $errores[] = 'El nombre del profesional tiene un formato inválido';
        } else {
            $nombre = trim($rawNombre);
            if ($nombre === '') {
                $errores[] = 'El Nombre del Profesional es Obligatorio';
            } elseif (mb_strlen($nombre, 'UTF-8') > self::MAX_NOMBRE_LENGTH) {
                $errores[] = 'El nombre del profesional no puede exceder los 120 caracteres';
            }
        }

        // 2. Estado activo (1 o 0; por defecto 1 si no se envía)
        $activo = 1;
        if (array_key_exists('activo', $datos)) {
            $rawActivo = $datos['activo'];
            if ($rawActivo === 1 || $rawActivo === '1' || $rawActivo === true) {
                $activo = 1;
            } elseif ($rawActivo === 0 || $rawActivo === '0' || $rawActivo === false) {
                $activo = 0;
            } else {
                $errores[] = 'El estado del profesional no es válido';
            }
        }

        // 3. Servicios asociados
        $servicioIds = [];
        if (array_key_exists('servicios', $datos) && $datos['servicios'] !== null && $datos['servicios'] !== '') {
            $rawServicios = $datos['servicios'];
            $listaRaw = [];
            if (is_array($rawServicios)) {
                $listaRaw = $rawServicios;
            } elseif (is_string($rawServicios)) {
                $listaRaw = array_filter(array_map('trim', explode(',', $rawServicios)), fn($s) => $s !== '');
            } else {
                $errores[] = 'La selección de servicios tiene un formato inválido';
            }

            foreach ($listaRaw as $item) {
                $idVal = $this->validarId($item);
                if ($idVal === null) {
                    $errores[] = 'Uno o más identificadores de servicio seleccionados no son válidos';
                    break;
                }
                $servicioIds[] = $idVal;
            }
            $servicioIds = array_values(array_unique($servicioIds));
        }

        return [
            'errores' => $errores,
            'activo' => $activo,
            'servicioIds' => $servicioIds
        ];
    }

    /**
     * Lista todos los profesionales del catálogo.
     */
    public function listar(bool $soloActivos = false): array
    {
        try {
            $profesionales = $this->profesionalRepository->findAll($soloActivos);
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'profesionales' => $profesionales,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'profesionales' => [],
                'alertas' => [
                    'error' => ['No fue posible cargar el catálogo de profesionales.']
                ]
            ];
        }
    }

    /**
     * Obtiene un profesional por su ID validado.
     */
    public function obtenerPorId($idRaw): array
    {
        $id = $this->validarId($idRaw);
        if ($id === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'profesional' => null,
                'alertas' => [
                    'error' => ['El identificador del profesional no es válido.']
                ]
            ];
        }

        try {
            $profesional = $this->profesionalRepository->findById($id);
            if ($profesional === null) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'profesional' => null,
                    'alertas' => [
                        'error' => ['El profesional solicitado no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'profesional' => $profesional,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'profesional' => null,
                'alertas' => [
                    'error' => ['No fue posible consultar el profesional en la base de datos.']
                ]
            ];
        }
    }

    /**
     * Obtiene el profesional junto con sus horarios semanales, descansos y bloqueos.
     */
    public function obtenerAgendaCompleta($idRaw): array
    {
        $consulta = $this->obtenerPorId($idRaw);
        if ($consulta['status'] !== self::STATUS_OK) {
            return [
                'status' => $consulta['status'],
                'resultado' => false,
                'profesional' => null,
                'horarios' => [],
                'descansos' => [],
                'bloqueos' => [],
                'alertas' => $consulta['alertas']
            ];
        }

        $profesional = $consulta['profesional'];
        $id = (int)$profesional->id;

        try {
            $horarios = $this->profesionalRepository->findHorariosByProfesional($id);
            $descansos = $this->profesionalRepository->findDescansosByProfesional($id);
            $bloqueos = $this->profesionalRepository->findBloqueosByProfesional($id);

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'profesional' => $profesional,
                'horarios' => $horarios,
                'descansos' => $descansos,
                'bloqueos' => $bloqueos,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'profesional' => $profesional,
                'horarios' => [],
                'descansos' => [],
                'bloqueos' => [],
                'alertas' => [
                    'error' => ['No fue posible consultar la configuración de agenda del profesional.']
                ]
            ];
        }
    }

    /**
     * Crea un nuevo profesional y vincula los servicios que puede realizar.
     * Ignora cualquier `id` incluido en `$datos`.
     */
    public function crear(array $datos): array
    {
        $profesional = new Profesional();
        $profesional->sincronizarEditable($datos);
        $profesional->id = null;

        $validacion = $this->validarDatosProfesional($datos);
        $profesional->servicioIds = $validacion['servicioIds'];

        if (!empty($validacion['errores'])) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'profesional' => $profesional,
                'alertas' => ['error' => $validacion['errores']]
            ];
        }

        // Comprobar existencia real de cada servicio asociado
        try {
            foreach ($validacion['servicioIds'] as $sid) {
                if ($this->servicioRepository->findById($sid) === null) {
                    return [
                        'status' => self::STATUS_INVALID,
                        'resultado' => false,
                        'id' => null,
                        'profesional' => $profesional,
                        'alertas' => [
                            'error' => ['Uno o más servicios seleccionados no existen en el catálogo']
                        ]
                    ];
                }
            }
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'id' => null,
                'profesional' => $profesional,
                'alertas' => [
                    'error' => ['No fue posible verificar los servicios asociados por un error de base de datos.']
                ]
            ];
        }

        $profesional->nombre = trim((string)$datos['nombre']);
        $profesional->activo = (string)$validacion['activo'];

        try {
            $insertId = $this->profesionalRepository->createWithServicios($profesional, $validacion['servicioIds']);
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'id' => $insertId,
                'profesional' => $profesional,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'id' => null,
                'profesional' => $profesional,
                'alertas' => [
                    'error' => ['No fue posible guardar el profesional debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Actualiza un profesional existente y sincroniza sus servicios asociados.
     * El `id` procede exclusivamente de `$idRaw`, ignorando cualquier `id` en `$datos`.
     */
    public function actualizar($idRaw, array $datos): array
    {
        $id = $this->validarId($idRaw);
        if ($id === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'profesional' => null,
                'alertas' => [
                    'error' => ['El identificador del profesional no es válido.']
                ]
            ];
        }

        try {
            $existente = $this->profesionalRepository->findById($id);
        } catch (PersistenceException $e) {
            $fallback = new Profesional(['id' => (string)$id]);
            $fallback->sincronizarEditable($datos);
            $fallback->id = (string)$id;
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'profesional' => $fallback,
                'alertas' => [
                    'error' => ['No fue posible consultar el profesional debido a un error de base de datos.']
                ]
            ];
        }

        if ($existente === null) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'resultado' => false,
                'profesional' => null,
                'alertas' => [
                    'error' => ['El profesional que intenta actualizar no existe.']
                ]
            ];
        }

        $existente->sincronizarEditable($datos);
        $existente->id = (string)$id;

        $validacion = $this->validarDatosProfesional($datos);
        $existente->servicioIds = $validacion['servicioIds'];

        if (!empty($validacion['errores'])) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'profesional' => $existente,
                'alertas' => ['error' => $validacion['errores']]
            ];
        }

        try {
            foreach ($validacion['servicioIds'] as $sid) {
                if ($this->servicioRepository->findById($sid) === null) {
                    return [
                        'status' => self::STATUS_INVALID,
                        'resultado' => false,
                        'profesional' => $existente,
                        'alertas' => [
                            'error' => ['Uno o más servicios seleccionados no existen en el catálogo']
                        ]
                    ];
                }
            }
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'profesional' => $existente,
                'alertas' => [
                    'error' => ['No fue posible verificar los servicios asociados por un error de base de datos.']
                ]
            ];
        }

        $existente->nombre = trim((string)$datos['nombre']);
        $existente->activo = (string)$validacion['activo'];
        $existente->id = (string)$id;

        try {
            $actualizado = $this->profesionalRepository->updateWithServicios($existente, $validacion['servicioIds']);
            if (!$actualizado) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'profesional' => null,
                    'alertas' => [
                        'error' => ['El profesional que intenta actualizar no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'profesional' => $existente,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'profesional' => $existente,
                'alertas' => [
                    'error' => ['No fue posible actualizar el profesional debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Activa o desactiva un profesional conservando intactas sus referencias históricas.
     */
    public function cambiarEstado($idRaw, $activoRaw): array
    {
        $id = $this->validarId($idRaw);
        if ($id === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'alertas' => [
                    'error' => ['El identificador del profesional no es válido.']
                ]
            ];
        }

        if ($activoRaw === 1 || $activoRaw === '1' || $activoRaw === true) {
            $activo = 1;
        } elseif ($activoRaw === 0 || $activoRaw === '0' || $activoRaw === false) {
            $activo = 0;
        } else {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'alertas' => [
                    'error' => ['El estado solicitado para el profesional no es válido.']
                ]
            ];
        }

        try {
            $actualizado = $this->profesionalRepository->updateActivo($id, $activo);
            if (!$actualizado) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'alertas' => [
                        'error' => ['El profesional solicitado no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'activo' => $activo,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'alertas' => [
                    'error' => ['No fue posible cambiar el estado del profesional debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Valida y guarda la configuración semanal de horarios de un profesional:
     * - Rechaza horarios invertidos o de duración cero (`hora_inicio >= hora_fin`).
     * - Exige que `$horariosRaw` sea un arreglo no vacío de filas válidas.
     * - Rechaza valores inválidos de `activo` sin interpretarlos como días desactivados.
     * - Conserva la posibilidad de desactivar todos los días mediante filas explícitamente inactivas (`activo=0` o `enviado=1` sin `activo`).
     * - Rechaza horarios invertidos o de duración cero (`hora_inicio >= hora_fin`).
     * - Rechaza intervalos solapados dentro del mismo día para el mismo profesional.
     * - Rechaza configuraciones que dejen descansos existentes fuera del horario laboral.
     */
    public function guardarHorariosSemanales($profesionalIdRaw, $horariosRaw): array
    {
        $profesionalId = $this->validarId($profesionalIdRaw);
        if ($profesionalId === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'horarios' => [],
                'alertas' => [
                    'error' => ['El identificador del profesional no es válido.']
                ]
            ];
        }

        if (!is_array($horariosRaw) || empty($horariosRaw)) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'horarios' => [],
                'alertas' => [
                    'error' => ['Debe enviar una estructura válida de horarios semanales.']
                ]
            ];
        }

        try {
            $profesional = $this->profesionalRepository->findById($profesionalId);
            if ($profesional === null) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'horarios' => [],
                    'alertas' => [
                        'error' => ['El profesional solicitado no existe.']
                    ]
                ];
            }
            $descansosExistentes = $this->profesionalRepository->findDescansosByProfesional($profesionalId);
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'horarios' => [],
                'alertas' => [
                    'error' => ['No fue posible consultar la agenda del profesional debido a un error de base de datos.']
                ]
            ];
        }

        $errores = [];
        $entidadesPorDia = [];
        $entidadesPlanas = [];
        $filasNormalizadas = [];

        foreach ($horariosRaw as $clave => $item) {
            if (!is_array($item) || empty($item)) {
                $errores[] = 'Formato de franja horaria inválido.';
                continue;
            }

            $esFilaDirecta = array_key_exists('dia_semana', $item)
                || array_key_exists('hora_inicio', $item)
                || array_key_exists('hora_fin', $item)
                || array_key_exists('activo', $item)
                || array_key_exists('enviado', $item);

            if (!$esFilaDirecta) {
                foreach ($item as $subItem) {
                    if (!is_array($subItem) || empty($subItem)) {
                        $errores[] = 'Formato de franja horaria inválido.';
                        continue;
                    }
                    if (!array_key_exists('dia_semana', $subItem)) {
                        $subItem['dia_semana'] = $clave;
                    }
                    $filasNormalizadas[] = [$clave, $subItem];
                }
            } else {
                $filasNormalizadas[] = [$clave, $item];
            }
        }

        foreach ($filasNormalizadas as [$clave, $item]) {
            $tieneEnviado = array_key_exists('enviado', $item);
            $tieneActivo = array_key_exists('activo', $item);

            if ($tieneEnviado && !in_array($item['enviado'], ['1', 1, true], true)) {
                $errores[] = 'El indicador de envío en la franja horaria no es válido.';
                continue;
            }

            if ($tieneActivo) {
                $rawActivo = $item['activo'];
                if (in_array($rawActivo, ['1', 1, true], true)) {
                    $esActivo = true;
                } elseif (in_array($rawActivo, ['0', 0, false], true)) {
                    $esActivo = false;
                } else {
                    $errores[] = 'El valor del campo activo en el horario no es válido.';
                    continue;
                }
            } elseif ($tieneEnviado) {
                // Fila explícita de formulario con checkbox desmarcado
                $esActivo = false;
            } else {
                // Franja enviada directamente sin indicador activo/enviado
                $esActivo = true;
            }

            $diaRaw = $item['dia_semana'] ?? $clave;
            $dia = $this->validarDiaSemana($diaRaw);
            if ($dia === null) {
                $errores[] = 'El día de la semana indicado en el horario no es válido (debe ser de 1=Lunes a 7=Domingo).';
                continue;
            }

            if (!$esActivo) {
                if (
                    (array_key_exists('hora_inicio', $item) && $item['hora_inicio'] !== null && !is_string($item['hora_inicio'])) ||
                    (array_key_exists('hora_fin', $item) && $item['hora_fin'] !== null && !is_string($item['hora_fin']))
                ) {
                    $errores[] = 'Las horas de la franja horaria tienen un formato inválido.';
                }
                continue;
            }

            $nombreDia = HorarioProfesional::DIAS_SEMANA[$dia];
            $horaInicio = self::validarHora($item['hora_inicio'] ?? null);
            $horaFin = self::validarHora($item['hora_fin'] ?? null);

            if ($horaInicio === null || $horaFin === null) {
                $errores[] = "Debe ingresar horas válidas en formato HH:MM para {$nombreDia}.";
                continue;
            }

            if (self::horaAMinutos($horaInicio) >= self::horaAMinutos($horaFin)) {
                $errores[] = "Horario inválido en {$nombreDia}: la hora de inicio ({$horaInicio}) debe ser estrictamente anterior a la hora de fin ({$horaFin}).";
                continue;
            }

            $horario = new HorarioProfesional([
                'profesionalId' => $profesionalId,
                'dia_semana' => $dia,
                'hora_inicio' => $horaInicio,
                'hora_fin' => $horaFin
            ]);

            $entidadesPorDia[$dia][] = $horario;
            $entidadesPlanas[] = $horario;
        }

        // Comprobar solapamientos entre franjas del mismo día
        foreach ($entidadesPorDia as $dia => $listaDia) {
            usort($listaDia, fn(HorarioProfesional $a, HorarioProfesional $b) => strcmp($a->hora_inicio, $b->hora_inicio));
            $entidadesPorDia[$dia] = $listaDia;

            for ($i = 1, $n = count($listaDia); $i < $n; $i++) {
                $prev = $listaDia[$i - 1];
                $curr = $listaDia[$i];
                if (self::horaAMinutos($curr->hora_inicio) < self::horaAMinutos($prev->hora_fin)) {
                    $nombreDia = HorarioProfesional::DIAS_SEMANA[$dia];
                    $errores[] = "Existen horarios solapados o incompatibles en {$nombreDia} ({$prev->hora_inicio}-{$prev->hora_fin} y {$curr->hora_inicio}-{$curr->hora_fin}).";
                    break;
                }
            }
        }

        // Comprobar compatibilidad con los descansos ya registrados del profesional
        if (empty($errores) && !empty($descansosExistentes)) {
            foreach ($descansosExistentes as $descanso) {
                $franjasDia = $entidadesPorDia[$descanso->dia_semana] ?? [];
                $contenido = false;
                $dInicio = self::horaAMinutos($descanso->hora_inicio);
                $dFin = self::horaAMinutos($descanso->hora_fin);

                foreach ($franjasDia as $h) {
                    $hInicio = self::horaAMinutos($h->hora_inicio);
                    $hFin = self::horaAMinutos($h->hora_fin);
                    if ($hInicio <= $dInicio && $dFin <= $hFin && !($hInicio === $dInicio && $hFin === $dFin)) {
                        $contenido = true;
                        break;
                    }
                }

                if (!$contenido) {
                    $nombreDia = $descanso->nombreDia();
                    $errores[] = "El horario configurado para {$nombreDia} es incompatible con el descanso registrado ({$descanso->hora_inicio} - {$descanso->hora_fin}). Ajuste el horario o elimine el descanso primero.";
                }
            }
        }

        if (!empty($errores)) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'horarios' => $entidadesPlanas,
                'alertas' => ['error' => $errores]
            ];
        }

        // Ordenar por día y hora antes de persistir
        usort($entidadesPlanas, function (HorarioProfesional $a, HorarioProfesional $b): int {
            if ($a->dia_semana !== $b->dia_semana) {
                return $a->dia_semana <=> $b->dia_semana;
            }
            return strcmp($a->hora_inicio, $b->hora_inicio);
        });

        try {
            $this->profesionalRepository->replaceHorarios($profesionalId, $entidadesPlanas);
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'horarios' => $entidadesPlanas,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'horarios' => $entidadesPlanas,
                'alertas' => [
                    'error' => ['No fue posible guardar los horarios semanales debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Valida y registra un descanso semanal para un profesional:
     * - Rechaza horarios invertidos o de duración cero (`hora_inicio >= hora_fin`).
     * - Exige que el descanso esté dentro de una franja laboral activa del mismo día sin cubrir todo el turno.
     * - Rechaza solapamientos con otros descansos existentes del profesional en el mismo día.
     */
    public function crearDescanso($profesionalIdRaw, array $datos): array
    {
        $profesionalId = $this->validarId($profesionalIdRaw);
        if ($profesionalId === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ['El identificador del profesional no es válido.']
                ]
            ];
        }

        $errores = [];
        $dia = $this->validarDiaSemana($datos['dia_semana'] ?? null);
        if ($dia === null) {
            $errores[] = 'El día de la semana para el descanso no es válido.';
        }

        $horaInicio = self::validarHora($datos['hora_inicio'] ?? null);
        $horaFin = self::validarHora($datos['hora_fin'] ?? null);
        if ($horaInicio === null || $horaFin === null) {
            $errores[] = 'Debe ingresar horas de inicio y fin válidas en formato HH:MM para el descanso.';
        } elseif (self::horaAMinutos($horaInicio) >= self::horaAMinutos($horaFin)) {
            $errores[] = 'La hora de inicio del descanso debe ser estrictamente anterior a la hora de fin.';
        }

        $motivo = null;
        if (array_key_exists('motivo', $datos) && $datos['motivo'] !== null && $datos['motivo'] !== '') {
            if (!is_string($datos['motivo'])) {
                $errores[] = 'El motivo del descanso tiene un formato inválido.';
            } else {
                $motivoTrim = trim($datos['motivo']);
                if (mb_strlen($motivoTrim, 'UTF-8') > self::MAX_MOTIVO_DESCANSO_LENGTH) {
                    $errores[] = 'El motivo del descanso no puede exceder los 120 caracteres.';
                } elseif ($motivoTrim !== '') {
                    $motivo = $motivoTrim;
                }
            }
        }

        if (!empty($errores)) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'alertas' => ['error' => $errores]
            ];
        }

        try {
            $profesional = $this->profesionalRepository->findById($profesionalId);
            if ($profesional === null) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'id' => null,
                    'alertas' => [
                        'error' => ['El profesional solicitado no existe.']
                    ]
                ];
            }

            $horarios = $this->profesionalRepository->findHorariosByProfesional($profesionalId);
            $descansosExistentes = $this->profesionalRepository->findDescansosByProfesional($profesionalId);
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ['No fue posible verificar la agenda del profesional debido a un error de base de datos.']
                ]
            ];
        }

        $dInicio = self::horaAMinutos($horaInicio);
        $dFin = self::horaAMinutos($horaFin);
        $nombreDia = HorarioProfesional::DIAS_SEMANA[$dia];

        // 1. Verificar que el descanso esté contenido en un horario laboral activo de ese día
        $dentroDeHorario = false;
        $cubreTodoElTurno = false;
        foreach ($horarios as $h) {
            if ($h->dia_semana !== $dia) {
                continue;
            }
            $hInicio = self::horaAMinutos($h->hora_inicio);
            $hFin = self::horaAMinutos($h->hora_fin);
            if ($hInicio <= $dInicio && $dFin <= $hFin) {
                if ($hInicio === $dInicio && $hFin === $dFin) {
                    $cubreTodoElTurno = true;
                } else {
                    $dentroDeHorario = true;
                }
                break;
            }
        }

        if ($cubreTodoElTurno) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ["El descanso en {$nombreDia} no puede cubrir la totalidad del horario laboral."]
                ]
            ];
        }

        if (!$dentroDeHorario) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ["El descanso en {$nombreDia} ({$horaInicio} - {$horaFin}) debe estar dentro del horario laboral configurado para ese día."]
                ]
            ];
        }

        // 2. Verificar que no se solape con otro descanso existente en el mismo día
        foreach ($descansosExistentes as $ex) {
            if ($ex->dia_semana !== $dia) {
                continue;
            }
            $exInicio = self::horaAMinutos($ex->hora_inicio);
            $exFin = self::horaAMinutos($ex->hora_fin);
            if ($dInicio < $exFin && $exInicio < $dFin) {
                return [
                    'status' => self::STATUS_INVALID,
                    'resultado' => false,
                    'id' => null,
                    'alertas' => [
                        'error' => ["El descanso se solapa con otro descanso existente en {$nombreDia} ({$ex->hora_inicio} - {$ex->hora_fin})."]
                    ]
                ];
            }
        }

        $descanso = new DescansoProfesional([
            'profesionalId' => $profesionalId,
            'dia_semana' => $dia,
            'hora_inicio' => $horaInicio,
            'hora_fin' => $horaFin,
            'motivo' => $motivo
        ]);

        try {
            $insertId = $this->profesionalRepository->createDescanso($descanso);
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'id' => $insertId,
                'descanso' => $descanso,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ['No fue posible guardar el descanso debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Elimina un descanso semanal de un profesional.
     */
    public function eliminarDescanso($profesionalIdRaw, $descansoIdRaw): array
    {
        $profesionalId = $this->validarId($profesionalIdRaw);
        $descansoId = $this->validarId($descansoIdRaw);

        if ($profesionalId === null || $descansoId === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'alertas' => [
                    'error' => ['Identificador de profesional o de descanso inválido.']
                ]
            ];
        }

        try {
            $eliminado = $this->profesionalRepository->deleteDescanso($profesionalId, $descansoId);
            if (!$eliminado) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'alertas' => [
                        'error' => ['El descanso solicitado no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'alertas' => [
                    'error' => ['No fue posible eliminar el descanso debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Valida y crea un bloqueo por fecha completa o por intervalo en zona horaria `America/Guayaquil`:
     * - Soporta `fecha` (bloqueo de un día) o `fecha_inicio` + `fecha_fin` (rango de fechas).
     * - Rechaza fechas calendario inválidas, rangos de fechas invertidos (`fecha_fin < fecha_inicio`)
     *   y fechas pasadas según `America/Guayaquil`.
     * - Si se indican horas (`hora_inicio`, `hora_fin`), exige ambas y valida `hora_inicio < hora_fin`.
     * - Rechaza bloqueos que se solapen en fecha y hora con otro bloqueo existente del mismo profesional.
     */
    public function crearBloqueo($profesionalIdRaw, array $datos): array
    {
        $profesionalId = $this->validarId($profesionalIdRaw);
        if ($profesionalId === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ['El identificador del profesional no es válido.']
                ]
            ];
        }

        $errores = [];
        $fechaUnica = is_string($datos['fecha'] ?? null) ? trim($datos['fecha']) : '';
        $fechaInicio = is_string($datos['fecha_inicio'] ?? null) && trim($datos['fecha_inicio']) !== ''
            ? trim($datos['fecha_inicio'])
            : $fechaUnica;
        $fechaFin = is_string($datos['fecha_fin'] ?? null) && trim($datos['fecha_fin']) !== ''
            ? trim($datos['fecha_fin'])
            : $fechaInicio;

        if ($fechaInicio === '' || $this->obtenerDiaSemanaIso($fechaInicio) === null) {
            $errores[] = 'La fecha de inicio del bloqueo es inválida. Use el formato AAAA-MM-DD.';
        }

        if ($fechaFin === '' || $this->obtenerDiaSemanaIso($fechaFin) === null) {
            $errores[] = 'La fecha de fin del bloqueo es inválida. Use el formato AAAA-MM-DD.';
        }

        $hoyGuayaquil = $this->obtenerAhora()->format('Y-m-d');
        if (empty($errores)) {
            if ($fechaFin < $fechaInicio) {
                $errores[] = 'La fecha de fin del bloqueo no puede ser anterior a la fecha de inicio.';
            } elseif ($fechaInicio < $hoyGuayaquil) {
                $errores[] = 'No se pueden registrar bloqueos en fechas pasadas (zona horaria America/Guayaquil).';
            }
        }

        $rawHoraInicio = is_string($datos['hora_inicio'] ?? null) ? trim($datos['hora_inicio']) : '';
        $rawHoraFin = is_string($datos['hora_fin'] ?? null) ? trim($datos['hora_fin']) : '';
        $horaInicio = null;
        $horaFin = null;

        if ($rawHoraInicio !== '' || $rawHoraFin !== '') {
            if ($rawHoraInicio === '' || $rawHoraFin === '') {
                $errores[] = 'Para un bloqueo por intervalo horario debe especificar tanto la hora de inicio como la hora de fin.';
            } else {
                $horaInicio = self::validarHora($rawHoraInicio);
                $horaFin = self::validarHora($rawHoraFin);
                if ($horaInicio === null || $horaFin === null) {
                    $errores[] = 'Las horas del bloqueo deben tener el formato válido HH:MM.';
                } elseif (self::horaAMinutos($horaInicio) >= self::horaAMinutos($horaFin)) {
                    $errores[] = 'La hora de inicio del bloqueo debe ser estrictamente anterior a la hora de fin.';
                }
            }
        }

        $motivo = null;
        if (array_key_exists('motivo', $datos) && $datos['motivo'] !== null && $datos['motivo'] !== '') {
            if (!is_string($datos['motivo'])) {
                $errores[] = 'El motivo del bloqueo tiene un formato inválido.';
            } else {
                $motivoTrim = trim($datos['motivo']);
                if (mb_strlen($motivoTrim, 'UTF-8') > self::MAX_MOTIVO_BLOQUEO_LENGTH) {
                    $errores[] = 'El motivo del bloqueo no puede exceder los 160 caracteres.';
                } elseif ($motivoTrim !== '') {
                    $motivo = $motivoTrim;
                }
            }
        }

        if (!empty($errores)) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'alertas' => ['error' => $errores]
            ];
        }

        try {
            $profesional = $this->profesionalRepository->findById($profesionalId);
            if ($profesional === null) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'id' => null,
                    'alertas' => [
                        'error' => ['El profesional solicitado no existe.']
                    ]
                ];
            }

            $bloqueosExistentes = $this->profesionalRepository->findBloqueosByProfesional($profesionalId);
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ['No fue posible consultar los bloqueos del profesional debido a un error de base de datos.']
                ]
            ];
        }

        $nuevoMinInicio = $horaInicio !== null ? self::horaAMinutos($horaInicio) : 0;
        $nuevoMinFin = $horaFin !== null ? self::horaAMinutos($horaFin) : 1440;

        foreach ($bloqueosExistentes as $ex) {
            $fechasSolapan = ($fechaInicio <= $ex->fecha_fin) && ($ex->fecha_inicio <= $fechaFin);
            if (!$fechasSolapan) {
                continue;
            }

            $exMinInicio = $ex->hora_inicio !== null ? self::horaAMinutos($ex->hora_inicio) : 0;
            $exMinFin = $ex->hora_fin !== null ? self::horaAMinutos($ex->hora_fin) : 1440;

            if ($nuevoMinInicio < $exMinFin && $exMinInicio < $nuevoMinFin) {
                return [
                    'status' => self::STATUS_INVALID,
                    'resultado' => false,
                    'id' => null,
                    'alertas' => [
                        'error' => ['El bloqueo indicado se solapa con otro bloqueo existente para este profesional.']
                    ]
                ];
            }
        }

        $bloqueo = new BloqueoProfesional([
            'profesionalId' => $profesionalId,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'hora_inicio' => $horaInicio,
            'hora_fin' => $horaFin,
            'motivo' => $motivo
        ]);

        try {
            $insertId = $this->profesionalRepository->createBloqueo($bloqueo);
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'id' => $insertId,
                'bloqueo' => $bloqueo,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'id' => null,
                'alertas' => [
                    'error' => ['No fue posible guardar el bloqueo debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Elimina un bloqueo de agenda de un profesional.
     */
    public function eliminarBloqueo($profesionalIdRaw, $bloqueoIdRaw): array
    {
        $profesionalId = $this->validarId($profesionalIdRaw);
        $bloqueoId = $this->validarId($bloqueoIdRaw);

        if ($profesionalId === null || $bloqueoId === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'alertas' => [
                    'error' => ['Identificador de profesional o de bloqueo inválido.']
                ]
            ];
        }

        try {
            $eliminado = $this->profesionalRepository->deleteBloqueo($profesionalId, $bloqueoId);
            if (!$eliminado) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'alertas' => [
                        'error' => ['El bloqueo solicitado no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'alertas' => [
                    'error' => ['No fue posible eliminar el bloqueo debido a un error de base de datos.']
                ]
            ];
        }
    }
}
