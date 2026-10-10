<?php

namespace Services;

use Model\Cita;
use Repositories\CitaRepository;
use Repositories\PersistenceException;
use Repositories\ServicioRepository;

/**
 * Servicio de dominio para las reglas de negocio de citas:
 * - Reserva transaccional con profesional (Fase 4B) bajo bloqueo InnoDB (`FOR UPDATE` / `FOR SHARE`),
 *   revalidando profesional activo, compatibilidad de servicios, fecha futura en `America/Guayaquil`,
 *   encaje en turno semanal sin cruzar descansos/bloqueos, ausencia de solapamientos de ocupación
 *   y persistencia del snapshot histórico de servicios (`nombre_servicio`, `precio_servicio`, `duracion_minutos`).
 * - Reserva transaccional compatible con el contrato clásico del frontend cuando no se especifica profesional.
 * - Eliminación con verificación de pertenencia (propietario o administrador), liberando ocupación.
 * - Consulta administrativa por fecha preservando inmutabilidad histórica.
 *
 * Nota: Esta capa nunca accede a `$_SESSION` ni confía en `usuarioId`, `id`, duraciones, precios
 * ni `hora_fin` enviados por el cliente.
 */
class CitaService
{
    public const STATUS_OK = 'ok';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_FORBIDDEN = 'forbidden';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_ERROR = 'error';

    public const TIMEZONE = 'America/Guayaquil';

    private CitaRepository $citaRepository;
    private ServicioRepository $servicioRepository;
    private ?\DateTimeImmutable $ahoraReferencia;

    public function __construct(
        ?CitaRepository $citaRepository = null,
        ?ServicioRepository $servicioRepository = null,
        ?\DateTimeImmutable $ahoraReferencia = null
    ) {
        $this->citaRepository = $citaRepository ?? new CitaRepository();
        // Compartir la misma conexión del repositorio de citas cuando no se inyecta ServicioRepository aparte
        $this->servicioRepository = $servicioRepository ?? new ServicioRepository($this->citaRepository->getDb());
        $this->ahoraReferencia = $ahoraReferencia;
    }

    /**
     * Permite configurar un reloj de referencia sustituible para pruebas deterministas.
     */
    public function setAhoraReferencia(?\DateTimeImmutable $ahoraReferencia): void
    {
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
     * Retorna el instante actual evaluado en la zona horaria `America/Guayaquil`.
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
     * evaluada explícitamente en `America/Guayaquil`.
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
     * Valida que un identificador sea un entero positivo escalar.
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
     * Extrae y valida la lista desduplicada de IDs de servicios, ignorando cualquier duración,
     * precio o nombre enviado por el cliente.
     *
     * @param mixed $serviciosRaw
     * @return array{status: string, ids?: array<int, int>, error?: string}
     */
    private function extraerIdsServicios($serviciosRaw): array
    {
        if (is_string($serviciosRaw)) {
            $trimmed = trim($serviciosRaw);
            $partes = array_filter(array_map('trim', explode(',', $trimmed)), fn($s) => $s !== '');
            if (empty($partes)) {
                return [
                    'status' => self::STATUS_INVALID,
                    'error' => 'Debes seleccionar al menos un servicio'
                ];
            }

            $ids = [];
            foreach ($partes as $p) {
                $idVal = $this->validarId($p);
                if ($idVal === null) {
                    return [
                        'status' => self::STATUS_INVALID,
                        'error' => 'Uno o más identificadores de servicio son inválidos. Deben ser enteros positivos.'
                    ];
                }
                $ids[] = $idVal;
            }

            return [
                'status' => self::STATUS_OK,
                'ids' => array_values(array_unique($ids))
            ];
        }

        if (is_array($serviciosRaw)) {
            if (empty($serviciosRaw)) {
                return [
                    'status' => self::STATUS_INVALID,
                    'error' => 'Debes seleccionar al menos un servicio'
                ];
            }

            $ids = [];
            foreach ($serviciosRaw as $item) {
                $candidatoId = $item;
                if (is_array($item)) {
                    if (!array_key_exists('id', $item)) {
                        return [
                            'status' => self::STATUS_INVALID,
                            'error' => 'Uno o más identificadores de servicio son inválidos. Deben ser enteros positivos.'
                        ];
                    }
                    $candidatoId = $item['id'];
                } elseif (is_object($item) && isset($item->id)) {
                    $candidatoId = $item->id;
                }

                $idVal = $this->validarId($candidatoId);
                if ($idVal === null) {
                    return [
                        'status' => self::STATUS_INVALID,
                        'error' => 'Uno o más identificadores de servicio son inválidos. Deben ser enteros positivos.'
                    ];
                }
                $ids[] = $idVal;
            }

            return [
                'status' => self::STATUS_OK,
                'ids' => array_values(array_unique($ids))
            ];
        }

        return [
            'status' => self::STATUS_INVALID,
            'error' => 'Debes seleccionar al menos un servicio'
        ];
    }

    /**
     * Determina si la solicitud incluye especificación de profesional (`profesionalId`, `profesional_id` o `profesional`).
     */
    private function contieneParametroProfesional(array $datos): bool
    {
        foreach (['profesionalId', 'profesional_id', 'profesional'] as $clave) {
            if (array_key_exists($clave, $datos) && $datos[$clave] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Valida y ejecuta la creación de una nueva reserva.
     * Si la solicitud incluye `profesionalId` (o `profesional_id` / `profesional`), delega a
     * `reservarConProfesional()` aplicando revalidación transaccional de agenda, bloqueos y ocupación real.
     * Si no incluye profesional, mantiene el flujo clásico compatible con el frontend actual.
     *
     * @param mixed $usuarioIdAutenticado Identificador del usuario autenticado provisto por el controlador.
     * @param array $datos Datos de la solicitud. Ignora cualquier `usuarioId`, `id`, duraciones, precios o `hora_fin` enviados por el cliente.
     * @return array Resultado estructurado con `status`, `httpCode` y `resultado` o `error`.
     */
    public function reservar($usuarioIdAutenticado, array $datos): array
    {
        if ($this->contieneParametroProfesional($datos)) {
            return $this->reservarConProfesional($usuarioIdAutenticado, $datos);
        }

        $usuarioId = $this->validarId($usuarioIdAutenticado);
        if ($usuarioId === null) {
            return [
                'status' => self::STATUS_UNAUTHORIZED,
                'httpCode' => 401,
                'resultado' => false,
                'error' => 'No autenticado'
            ];
        }

        $fecha = is_string($datos['fecha'] ?? null) ? trim($datos['fecha']) : '';
        $hora = is_string($datos['hora'] ?? null) ? trim($datos['hora']) : '';

        // 1. Validar formato estricto de fecha (AAAA-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'Formato de fecha inválido. Se requiere AAAA-MM-DD.'
            ];
        }

        $partesFecha = explode('-', $fecha);
        if (!checkdate((int)$partesFecha[1], (int)$partesFecha[2], (int)$partesFecha[0])) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'La fecha ingresada no corresponde a un día de calendario válido.'
            ];
        }

        // 2. Validar que la fecha sea estrictamente futura (mínimo mañana) en zona horaria America/Guayaquil
        $fechaHoy = $this->obtenerAhora()->format('Y-m-d');
        if ($fecha <= $fechaHoy) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'No se pueden agendar citas para el mismo día ni para fechas pasadas. La reserva debe ser con al menos un día de anticipación.'
            ];
        }

        // 3. Validar exclusión de fines de semana en America/Guayaquil (ISO-8601: 6 = Sábado, 7 = Domingo)
        $diaSemanaIso = $this->obtenerDiaSemanaIso($fecha);
        if (in_array($diaSemanaIso, [6, 7], true)) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'No se puede agendar citas en fines de semana (sábados o domingos).'
            ];
        }

        // 4. Validar formato estricto de hora (HH:MM únicamente, sin segundos)
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'Formato de hora inválido. Se requiere HH:MM.'
            ];
        }

        $partesHora = explode(':', $hora);
        $horaInt = (int)$partesHora[0];
        $minutosInt = (int)$partesHora[1];

        // 5. Horario de atención general: 10:00 a 18:00 horas inclusive (límite superior 18:00)
        if ($horaInt < 10 || $horaInt > 18 || ($horaInt === 18 && $minutosInt > 0)) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'El horario de atención es de 10:00 a 18:00 horas.'
            ];
        }

        // 6. Validar servicios seleccionados: enteros positivos y desduplicación
        $extraccionServicios = $this->extraerIdsServicios($datos['servicios'] ?? null);
        if ($extraccionServicios['status'] !== self::STATUS_OK) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => $extraccionServicios['error']
            ];
        }
        $idServicios = $extraccionServicios['ids'];

        // 7. Comprobar existencia real de cada servicio en la base de datos
        try {
            foreach ($idServicios as $idServicio) {
                if ($this->servicioRepository->findById($idServicio) === null) {
                    return [
                        'status' => self::STATUS_NOT_FOUND,
                        'httpCode' => 422,
                        'resultado' => false,
                        'error' => 'Uno o más servicios seleccionados no existen o no son válidos'
                    ];
                }
            }
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'httpCode' => 500,
                'resultado' => false,
                'error' => 'Servicio de base de datos no disponible'
            ];
        }

        // 8. Construir entidad Cita forzando el usuarioId autenticado (ignorando cualquier usuarioId del cliente)
        $cita = new Cita([
            'fecha' => $fecha,
            'hora' => $hora,
            'usuarioId' => $usuarioId
        ]);

        // 9. Ejecutar persistencia atómica en una única conexión y transacción
        try {
            $idCita = $this->citaRepository->crearReservaAtomica($cita, $idServicios);
            $cita->id = (string)$idCita;

            return [
                'status' => self::STATUS_OK,
                'httpCode' => 200,
                'id' => $idCita,
                'resultado' => [
                    'resultado' => true,
                    'id' => $idCita
                ],
                'cita' => $cita
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'httpCode' => 500,
                'resultado' => false,
                'error' => 'No se pudo procesar la reserva. Operación cancelada.'
            ];
        }
    }

    /**
     * Valida y ejecuta una reserva asignada a un profesional específico dentro de una transacción
     * InnoDB con bloqueo pesimista (`FOR UPDATE` / `FOR SHARE`).
     *
     * Reglas de dominio:
     * - Obtiene `usuarioId` únicamente del argumento verificado por sesión; ignora cualquier `usuarioId`
     *   o `id` enviado en `$datos`.
     * - Calcula duración total, `hora_fin`, nombres y precios exclusivamente desde `servicios`,
     *   ignorando cualquier valor equivalente (`duracion`, `duracion_minutos`, `precio`, `hora_fin`, `fin`)
     *   enviado por el cliente.
     * - Exige fecha estrictamente futura (`fecha > hoy`) evaluada en `America/Guayaquil`.
     * - Revalida dentro de la misma transacción: profesional existente (`404`) y activo (`409`),
     *   servicios existentes y compatibles con el profesional (`422`), encaje completo `[hora_inicio, hora_fin)`
     *   dentro de la jornada del profesional sin cruzar descansos ni bloqueos (`422`), y ausencia de
     *   solapamiento en semántica semiabierta `[inicio, fin)` con otras citas del mismo profesional (`409`).
     *
     * @param mixed $usuarioIdAutenticado
     * @param array $datos
     * @return array
     */
    public function reservarConProfesional($usuarioIdAutenticado, array $datos): array
    {
        $usuarioId = $this->validarId($usuarioIdAutenticado);
        if ($usuarioId === null) {
            return [
                'status' => self::STATUS_UNAUTHORIZED,
                'codigo' => 'no_autenticado',
                'httpCode' => 401,
                'resultado' => false,
                'error' => 'No autenticado'
            ];
        }

        $profesionalIdRaw = $datos['profesionalId'] ?? ($datos['profesional_id'] ?? ($datos['profesional'] ?? null));
        $profesionalId = $this->validarId($profesionalIdRaw);
        if ($profesionalId === null) {
            return [
                'status' => self::STATUS_INVALID,
                'codigo' => 'solicitud_invalida',
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'El identificador del profesional es obligatorio y debe ser un entero positivo.'
            ];
        }

        $fecha = is_string($datos['fecha'] ?? null) ? trim($datos['fecha']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return [
                'status' => self::STATUS_INVALID,
                'codigo' => 'solicitud_invalida',
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'Formato de fecha inválido. Se requiere AAAA-MM-DD.'
            ];
        }

        $partesFecha = explode('-', $fecha);
        if (!checkdate((int)$partesFecha[1], (int)$partesFecha[2], (int)$partesFecha[0])) {
            return [
                'status' => self::STATUS_INVALID,
                'codigo' => 'solicitud_invalida',
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'La fecha ingresada no corresponde a un día de calendario válido.'
            ];
        }

        $fechaHoy = $this->obtenerAhora()->format('Y-m-d');
        if ($fecha <= $fechaHoy) {
            return [
                'status' => self::STATUS_INVALID,
                'codigo' => 'fecha_no_futura',
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'No se pueden agendar citas para el mismo día ni para fechas pasadas. La reserva debe ser con al menos un día de anticipación.'
            ];
        }

        $diaSemanaIso = $this->obtenerDiaSemanaIso($fecha);
        if ($diaSemanaIso === null) {
            return [
                'status' => self::STATUS_INVALID,
                'codigo' => 'solicitud_invalida',
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'La fecha ingresada no corresponde a un día de calendario válido.'
            ];
        }

        // Aceptar `hora` o `hora_inicio` en formato estricto HH:MM; ignorar cualquier `hora_fin` o `duracion` del cliente
        $horaRaw = null;
        if (array_key_exists('hora', $datos) && $datos['hora'] !== null && $datos['hora'] !== '') {
            $horaRaw = $datos['hora'];
        } elseif (array_key_exists('hora_inicio', $datos) && $datos['hora_inicio'] !== null && $datos['hora_inicio'] !== '') {
            $horaRaw = $datos['hora_inicio'];
        } elseif (array_key_exists('inicio', $datos) && $datos['inicio'] !== null && $datos['inicio'] !== '') {
            $horaRaw = $datos['inicio'];
        }

        $hora = is_string($horaRaw) ? trim($horaRaw) : '';
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            return [
                'status' => self::STATUS_INVALID,
                'codigo' => 'solicitud_invalida',
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'Formato de hora inválido. Se requiere HH:MM.'
            ];
        }

        $extraccionServicios = $this->extraerIdsServicios($datos['servicios'] ?? null);
        if ($extraccionServicios['status'] !== self::STATUS_OK) {
            return [
                'status' => self::STATUS_INVALID,
                'codigo' => 'solicitud_invalida',
                'httpCode' => 422,
                'resultado' => false,
                'error' => $extraccionServicios['error']
            ];
        }
        $idServicios = $extraccionServicios['ids'];

        try {
            $resRepo = $this->citaRepository->crearReservaConProfesionalAtomica(
                $usuarioId,
                $profesionalId,
                $fecha,
                $hora,
                $diaSemanaIso,
                $idServicios
            );

            if ($resRepo['status'] !== 'ok') {
                return [
                    'status' => $resRepo['status'],
                    'codigo' => $resRepo['codigo'] ?? 'solicitud_invalida',
                    'httpCode' => (int)($resRepo['httpCode'] ?? 422),
                    'resultado' => false,
                    'error' => $resRepo['error'] ?? 'No fue posible completar la reserva.'
                ];
            }

            $idCita = (int)$resRepo['id'];

            return [
                'status' => self::STATUS_OK,
                'codigo' => 'reserva_creada',
                'httpCode' => 200,
                'id' => $idCita,
                'profesionalId' => $profesionalId,
                'fecha' => $fecha,
                'hora' => $resRepo['hora'],
                'hora_inicio' => $resRepo['hora_inicio'],
                'hora_fin' => $resRepo['hora_fin'],
                'duracion_total_minutos' => $resRepo['duracion_total_minutos'],
                'servicios' => $resRepo['servicios'],
                'resultado' => [
                    'resultado' => true,
                    'id' => $idCita,
                    'profesionalId' => $profesionalId,
                    'fecha' => $fecha,
                    'hora' => $resRepo['hora'],
                    'hora_inicio' => $resRepo['hora_inicio'],
                    'hora_fin' => $resRepo['hora_fin'],
                    'duracion_total_minutos' => $resRepo['duracion_total_minutos'],
                ],
                'cita' => $resRepo['cita']
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'codigo' => 'error_persistencia',
                'httpCode' => 500,
                'resultado' => false,
                'error' => 'No se pudo procesar la reserva. Operación cancelada.'
            ];
        }
    }

    /**
     * Valida permisos y elimina una cita junto con sus registros en `citasservicios`.
     *
     * Distingue:
     * - `STATUS_UNAUTHORIZED` (401): identidad de usuario no válida.
     * - `STATUS_INVALID` (422): identificador de cita inválido.
     * - `STATUS_NOT_FOUND` (404): la cita no existe en base de datos.
     * - `STATUS_FORBIDDEN` (403): el usuario no es propietario de la cita ni administrador.
     * - `STATUS_ERROR` (500): fallo SQL o de transacción.
     * - `STATUS_OK` (200): cita eliminada exitosamente.
     */
    public function eliminar($idCitaRaw, $usuarioIdAutenticado, bool $esAdmin): array
    {
        $usuarioId = $this->validarId($usuarioIdAutenticado);
        if ($usuarioId === null) {
            return [
                'status' => self::STATUS_UNAUTHORIZED,
                'httpCode' => 401,
                'resultado' => false,
                'error' => 'No autenticado'
            ];
        }

        $idCita = $this->validarId($idCitaRaw);
        if ($idCita === null) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'Identificador de cita inválido.'
            ];
        }

        try {
            $cita = $this->citaRepository->findById($idCita);
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'httpCode' => 500,
                'resultado' => false,
                'error' => 'No fue posible consultar la cita debido a un error de base de datos.'
            ];
        }

        if ($cita === null) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'httpCode' => 404,
                'resultado' => false,
                'error' => 'La cita solicitada no existe.'
            ];
        }

        $esPropietario = (int)$cita->usuarioId === $usuarioId;
        if (!$esAdmin && !$esPropietario) {
            return [
                'status' => self::STATUS_FORBIDDEN,
                'httpCode' => 403,
                'resultado' => false,
                'error' => 'No tienes autorización para eliminar esta cita'
            ];
        }

        try {
            $eliminado = $this->citaRepository->eliminarCitaAtomica($idCita);
            if (!$eliminado) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'httpCode' => 404,
                    'resultado' => false,
                    'error' => 'La cita solicitada no existe.'
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'httpCode' => 200,
                'resultado' => true
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'httpCode' => 500,
                'resultado' => false,
                'error' => 'No fue posible eliminar la cita debido a un error de base de datos.'
            ];
        }
    }

    /**
     * Resuelve y valida la fecha solicitada para el listado administrativo.
     * Si no se proporciona o tiene un formato inválido, emplea la fecha actual (`Y-m-d`)
     * informando mediante `fechaValida` / `esValida` si la entrada original era válida.
     */
    public function resolverFechaConsultaAdmin($fechaRaw = null): array
    {
        $hoy = $this->obtenerAhora()->format('Y-m-d');
        if ($fechaRaw === null || $fechaRaw === '') {
            return ['fecha' => $hoy, 'fechaValida' => true, 'esValida' => true];
        }

        if (!is_string($fechaRaw)) {
            return ['fecha' => $hoy, 'fechaValida' => false, 'esValida' => false];
        }

        $fecha = trim($fechaRaw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            return ['fecha' => $hoy, 'fechaValida' => false, 'esValida' => false];
        }

        $partes = explode('-', $fecha);
        if (!checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0])) {
            return ['fecha' => $hoy, 'fechaValida' => false, 'esValida' => false];
        }

        return ['fecha' => $fecha, 'fechaValida' => true, 'esValida' => true];
    }

    /**
     * Consulta las citas administrativas para una fecha dada.
     * Distingue lista vacía (`status => ok`, `citas => []`) de fallo SQL (`status => error`).
     */
    public function consultarCitasAdmin($fechaRaw = null): array
    {
        $infoFecha = $this->resolverFechaConsultaAdmin($fechaRaw);
        $fecha = $infoFecha['fecha'];

        try {
            $citas = $this->citaRepository->findAdminCitasByFecha($fecha);
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'fecha' => $fecha,
                'fechaValida' => $infoFecha['fechaValida'],
                'citas' => $citas,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'fecha' => $fecha,
                'fechaValida' => $infoFecha['fechaValida'],
                'citas' => [],
                'alertas' => [
                    'error' => ['No fue posible consultar las citas para la fecha seleccionada.']
                ]
            ];
        }
    }
}
