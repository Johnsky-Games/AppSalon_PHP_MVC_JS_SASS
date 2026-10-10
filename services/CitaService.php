<?php

namespace Services;

use Model\Cita;
use Repositories\CitaRepository;
use Repositories\PersistenceException;
use Repositories\ServicioRepository;

/**
 * Servicio de dominio para las reglas de negocio de citas:
 * - Reserva transaccional con validación de fecha, día laborable, horario comercial,
 *   servicios válidos y asignación estricta al usuario autenticado verificado por el controlador.
 * - Eliminación con verificación de pertenencia (propietario o administrador).
 * - Consulta administrativa por fecha.
 *
 * Nota: Esta capa nunca accede a `$_SESSION` ni confía en `usuarioId` enviado por el cliente.
 */
class CitaService
{
    public const STATUS_OK = 'ok';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_FORBIDDEN = 'forbidden';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_ERROR = 'error';

    private CitaRepository $citaRepository;
    private ServicioRepository $servicioRepository;

    public function __construct(
        ?CitaRepository $citaRepository = null,
        ?ServicioRepository $servicioRepository = null
    ) {
        $this->citaRepository = $citaRepository ?? new CitaRepository();
        // Compartir la misma conexión del repositorio de citas cuando no se inyecta ServicioRepository aparte
        $this->servicioRepository = $servicioRepository ?? new ServicioRepository($this->citaRepository->getDb());
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
     * Valida y ejecuta la creación de una nueva reserva.
     *
     * @param mixed $usuarioIdAutenticado Identificador del usuario autenticado provisto por el controlador.
     * @param array $datos Datos de la solicitud (ej. `fecha`, `hora`, `servicios`). Ignora cualquier `usuarioId` en `$datos`.
     * @return array Resultado estructurado con `status`, `httpCode` y `resultado` o `error`.
     */
    public function reservar($usuarioIdAutenticado, array $datos): array
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

        $fecha = is_string($datos['fecha'] ?? null) ? trim($datos['fecha']) : '';
        $hora = is_string($datos['hora'] ?? null) ? trim($datos['hora']) : '';
        $serviciosRaw = is_string($datos['servicios'] ?? null) ? trim($datos['servicios']) : '';

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

        // 2. Validar que la fecha sea estrictamente futura (mínimo mañana)
        $fechaHoy = date('Y-m-d');
        if ($fecha <= $fechaHoy) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'No se pueden agendar citas para el mismo día ni para fechas pasadas. La reserva debe ser con al menos un día de anticipación.'
            ];
        }

        // 3. Validar exclusión de fines de semana (0 = Domingo, 6 = Sábado)
        $diaSemana = (int)date('w', strtotime($fecha));
        if (in_array($diaSemana, [0, 6], true)) {
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

        // 5. Horario de atención: 10:00 a 18:00 horas inclusive (límite superior 18:00)
        if ($horaInt < 10 || $horaInt > 18 || ($horaInt === 18 && $minutosInt > 0)) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'El horario de atención es de 10:00 a 18:00 horas.'
            ];
        }

        // 6. Validar servicios seleccionados: enteros positivos y desduplicación
        $partesServicios = array_filter(array_map('trim', explode(',', $serviciosRaw)), fn($s) => $s !== '');
        if (empty($partesServicios)) {
            return [
                'status' => self::STATUS_INVALID,
                'httpCode' => 422,
                'resultado' => false,
                'error' => 'Debes seleccionar al menos un servicio'
            ];
        }

        $idServicios = [];
        foreach ($partesServicios as $p) {
            $idVal = $this->validarId($p);
            if ($idVal === null) {
                return [
                    'status' => self::STATUS_INVALID,
                    'httpCode' => 422,
                    'resultado' => false,
                    'error' => 'Uno o más identificadores de servicio son inválidos. Deben ser enteros positivos.'
                ];
            }
            $idServicios[] = $idVal;
        }

        // Desduplicar servicios repetidos
        $idServicios = array_values(array_unique($idServicios));

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
        $hoy = date('Y-m-d');
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

