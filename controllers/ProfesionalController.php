<?php

namespace Controllers;

use Model\ActiveRecord;
use Model\HorarioProfesional;
use Model\Profesional;
use MVC\Router;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\ProfesionalService;
use Services\ServicioService;

/**
 * Controlador HTTP para la administración del catálogo de profesionales y su agenda
 * (horarios semanales, descansos y bloqueos).
 * Protegido por rol de administrador (`isAdmin()`) y validación CSRF (`exigir_csrf()`).
 */
class ProfesionalController
{
    private const ACCIONES_AGENDA_VALIDAS = [
        'guardar_horarios',
        'crear_descanso',
        'eliminar_descanso',
        'crear_bloqueo',
        'eliminar_bloqueo'
    ];

    private static ?ProfesionalService $profesionalService = null;
    private static ?ServicioService $servicioService = null;

    public static function setProfesionalService(?ProfesionalService $service): void
    {
        self::$profesionalService = $service;
    }

    public static function setServicioService(?ServicioService $service): void
    {
        self::$servicioService = $service;
    }

    private static function obtenerProfesionalService(): ProfesionalService
    {
        if (self::$profesionalService !== null) {
            return self::$profesionalService;
        }

        $db = ActiveRecord::getDB();
        return new ProfesionalService(
            new ProfesionalRepository($db),
            new ServicioRepository($db)
        );
    }

    private static function obtenerServicioService(): ServicioService
    {
        if (self::$servicioService !== null) {
            return self::$servicioService;
        }

        return new ServicioService(new ServicioRepository(ActiveRecord::getDB()));
    }

    private static function esCargaFormularioProfesionalValida(array $datos): bool
    {
        $nombreOk = !isset($datos['nombre']) || is_string($datos['nombre']);
        $activoOk = !array_key_exists('activo', $datos) || in_array($datos['activo'], ['1', 1, true, '0', 0, false], true);
        $serviciosOk = !isset($datos['servicios']) || is_array($datos['servicios']) || is_string($datos['servicios']);
        return $nombreOk && $activoOk && $serviciosOk;
    }

    /**
     * Valida la acción y la estructura de los datos de agenda ANTES de ejecutar cualquier operación.
     * Devuelve null si la estructura es válida o un mensaje de error si la solicitud es malformada.
     */
    private static function validarSolicitudAgenda(array $post): ?string
    {
        if (!array_key_exists('accion', $post) || !is_string($post['accion'])) {
            return 'La acción solicitada no es válida.';
        }

        $accion = trim($post['accion']);
        if (!in_array($accion, self::ACCIONES_AGENDA_VALIDAS, true)) {
            return 'La acción solicitada no es válida.';
        }

        switch ($accion) {
            case 'guardar_horarios':
                if (!array_key_exists('horarios', $post) || !is_array($post['horarios']) || empty($post['horarios'])) {
                    return 'Debe enviar una estructura válida de horarios semanales.';
                }
                foreach ($post['horarios'] as $item) {
                    if (!is_array($item) || empty($item)) {
                        return 'Formato de franja horaria inválido.';
                    }
                    $esFilaDirecta = array_key_exists('dia_semana', $item)
                        || array_key_exists('hora_inicio', $item)
                        || array_key_exists('hora_fin', $item)
                        || array_key_exists('activo', $item)
                        || array_key_exists('enviado', $item);

                    $filasVerificar = $esFilaDirecta ? [$item] : $item;
                    foreach ($filasVerificar as $fila) {
                        if (!is_array($fila) || empty($fila)) {
                            return 'Formato de franja horaria inválido.';
                        }
                        $tieneIndicadorEstado = array_key_exists('activo', $fila) || array_key_exists('enviado', $fila);
                        $tieneHoras = array_key_exists('hora_inicio', $fila) && array_key_exists('hora_fin', $fila);
                        if (!$tieneIndicadorEstado && !$tieneHoras) {
                            return 'Formato de franja horaria inválido.';
                        }
                        if (array_key_exists('enviado', $fila) && !in_array($fila['enviado'], ['1', 1, true], true)) {
                            return 'El indicador de envío en la franja horaria no es válido.';
                        }
                        if (array_key_exists('activo', $fila) && !in_array($fila['activo'], ['1', 1, true, '0', 0, false], true)) {
                            return 'El valor del campo activo en el horario no es válido.';
                        }
                        if (array_key_exists('dia_semana', $fila) && !is_scalar($fila['dia_semana'])) {
                            return 'El día de la semana tiene un formato inválido.';
                        }
                        if (array_key_exists('hora_inicio', $fila) && $fila['hora_inicio'] !== null && !is_string($fila['hora_inicio'])) {
                            return 'La hora de inicio tiene un formato inválido.';
                        }
                        if (array_key_exists('hora_fin', $fila) && $fila['hora_fin'] !== null && !is_string($fila['hora_fin'])) {
                            return 'La hora de fin tiene un formato inválido.';
                        }
                    }
                }
                return null;

            case 'crear_descanso':
                if (
                    !isset($post['dia_semana']) || !is_scalar($post['dia_semana']) ||
                    !isset($post['hora_inicio']) || !is_string($post['hora_inicio']) ||
                    !isset($post['hora_fin']) || !is_string($post['hora_fin']) ||
                    (array_key_exists('motivo', $post) && $post['motivo'] !== null && !is_string($post['motivo']))
                ) {
                    return 'Los datos del descanso tienen un formato inválido.';
                }
                return null;

            case 'eliminar_descanso':
                $idDesc = $post['descansoId'] ?? ($post['id'] ?? null);
                if ($idDesc === null || !is_scalar($idDesc)) {
                    return 'El identificador del descanso no es válido.';
                }
                return null;

            case 'crear_bloqueo':
                if (
                    !isset($post['fecha_inicio']) || !is_string($post['fecha_inicio']) ||
                    (array_key_exists('fecha_fin', $post) && $post['fecha_fin'] !== null && !is_string($post['fecha_fin'])) ||
                    (array_key_exists('hora_inicio', $post) && $post['hora_inicio'] !== null && !is_string($post['hora_inicio'])) ||
                    (array_key_exists('hora_fin', $post) && $post['hora_fin'] !== null && !is_string($post['hora_fin'])) ||
                    (array_key_exists('motivo', $post) && $post['motivo'] !== null && !is_string($post['motivo']))
                ) {
                    return 'Los datos del bloqueo tienen un formato inválido.';
                }
                return null;

            case 'eliminar_bloqueo':
                $idBloq = $post['bloqueoId'] ?? ($post['id'] ?? null);
                if ($idBloq === null || !is_scalar($idBloq)) {
                    return 'El identificador del bloqueo no es válido.';
                }
                return null;

            default:
                return 'La acción solicitada no es válida.';
        }
    }

    public static function index(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $resultado = self::obtenerProfesionalService()->listar(false);
        if ($resultado['status'] === ProfesionalService::STATUS_ERROR) {
            http_response_code(500);
        }

        $router->render('profesionales/index', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'profesionales' => $resultado['profesionales'],
            'alertas' => $resultado['alertas']
        ]);
    }

    public static function crear(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $profesional = new Profesional();
        $alertas = [];

        $catalogoServicios = self::obtenerServicioService()->listar();
        if ($catalogoServicios['status'] === ServicioService::STATUS_ERROR) {
            http_response_code(500);
            $alertas = $catalogoServicios['alertas'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $resultado = self::obtenerProfesionalService()->crear($_POST);
            if ($resultado['status'] === ProfesionalService::STATUS_OK) {
                header('Location: /profesionales');
                detener_ejecucion(302);
                return;
            }

            $profesional = $resultado['profesional'] ?? new Profesional();
            $alertas = $resultado['alertas'];

            if ($resultado['status'] === ProfesionalService::STATUS_ERROR) {
                http_response_code(500);
            } elseif (es_peticion_json() || !self::esCargaFormularioProfesionalValida($_POST)) {
                http_response_code(422);
            }
        }

        $router->render('profesionales/crear', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'profesional' => $profesional,
            'serviciosDisponibles' => $catalogoServicios['servicios'] ?? [],
            'alertas' => $alertas
        ]);
    }

    public static function actualizar(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $service = self::obtenerProfesionalService();
        $id = $service->validarId($_GET['id'] ?? null);
        if ($id === null) {
            header('Location: /profesionales');
            detener_ejecucion(302);
            return;
        }

        $alertas = [];
        $catalogoServicios = self::obtenerServicioService()->listar();
        if ($catalogoServicios['status'] === ServicioService::STATUS_ERROR) {
            http_response_code(500);
            $alertas = $catalogoServicios['alertas'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $resultado = $service->actualizar($id, $_POST);
            if (
                $resultado['status'] === ProfesionalService::STATUS_OK ||
                $resultado['status'] === ProfesionalService::STATUS_NOT_FOUND
            ) {
                header('Location: /profesionales');
                detener_ejecucion(302);
                return;
            }

            $profesional = $resultado['profesional'] ?? new Profesional(['id' => (string)$id]);
            $alertas = $resultado['alertas'];

            if ($resultado['status'] === ProfesionalService::STATUS_ERROR) {
                http_response_code(500);
            } elseif (es_peticion_json() || !self::esCargaFormularioProfesionalValida($_POST)) {
                http_response_code(422);
            }
        } else {
            $consulta = $service->obtenerPorId($id);
            if (
                $consulta['status'] === ProfesionalService::STATUS_NOT_FOUND ||
                $consulta['status'] === ProfesionalService::STATUS_INVALID
            ) {
                header('Location: /profesionales');
                detener_ejecucion(302);
                return;
            }

            if ($consulta['status'] === ProfesionalService::STATUS_ERROR) {
                http_response_code(500);
                $profesional = new Profesional(['id' => (string)$id]);
                $alertas = $consulta['alertas'];
            } else {
                $profesional = $consulta['profesional'];
            }
        }

        $router->render('profesionales/actualizar', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'profesional' => $profesional,
            'serviciosDisponibles' => $catalogoServicios['servicios'] ?? [],
            'alertas' => $alertas
        ]);
    }

    /**
     * Activa o desactiva un profesional conservando referencias históricas.
     */
    public static function cambiarEstado(?Router $router = null)
    {
        iniciar_sesion_segura();
        isAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $service = self::obtenerProfesionalService();
            $resultado = $service->cambiarEstado($_POST['id'] ?? null, $_POST['activo'] ?? null);

            if ($resultado['status'] === ProfesionalService::STATUS_ERROR) {
                http_response_code(500);
                if ($router !== null) {
                    $listado = $service->listar(false);
                    $router->render('profesionales/index', [
                        'nombre' => $_SESSION['nombre'] ?? '',
                        'apellido' => $_SESSION['apellido'] ?? '',
                        'profesionales' => $listado['profesionales'] ?? [],
                        'alertas' => $resultado['alertas']
                    ]);
                } else {
                    echo s($resultado['alertas']['error'][0] ?? 'Error al cambiar el estado del profesional.');
                }
                detener_ejecucion(500);
                return;
            }

            if ($resultado['status'] === ProfesionalService::STATUS_INVALID) {
                http_response_code(422);
                if (es_peticion_json()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'resultado' => false,
                        'error' => $resultado['alertas']['error'][0] ?? 'Datos inválidos'
                    ]);
                } elseif ($router !== null) {
                    $listado = $service->listar(false);
                    $router->render('profesionales/index', [
                        'nombre' => $_SESSION['nombre'] ?? '',
                        'apellido' => $_SESSION['apellido'] ?? '',
                        'profesionales' => $listado['profesionales'] ?? [],
                        'alertas' => $resultado['alertas']
                    ]);
                } else {
                    echo s($resultado['alertas']['error'][0] ?? 'Datos inválidos.');
                }
                detener_ejecucion(422);
                return;
            }

            header('Location: /profesionales');
            detener_ejecucion(302);
            return;
        }
    }

    /**
     * Vista y gestión de horarios semanales, descansos y bloqueos de un profesional.
     */
    public static function horarios(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $service = self::obtenerProfesionalService();
        $idRaw = $_GET['id'] ?? ($_POST['profesionalId'] ?? null);
        $id = $service->validarId($idRaw);
        if ($id === null) {
            header('Location: /profesionales');
            detener_ejecucion(302);
            return;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            // Validar la acción y la estructura de los datos ANTES de ejecutar cualquier operación
            $errorEstructura = self::validarSolicitudAgenda($_POST);
            if ($errorEstructura !== null) {
                http_response_code(422);
                $alertas = ['error' => [$errorEstructura]];
                self::renderizarVistaHorarios($router, $service, $id, $alertas);
                return;
            }

            $accion = trim($_POST['accion']);
            $resultadoAccion = self::ejecutarAccionAgenda($service, $id, $accion, $_POST);

            if ($resultadoAccion['status'] === ProfesionalService::STATUS_OK) {
                header("Location: /profesionales/horarios?id={$id}");
                detener_ejecucion(302);
                return;
            }

            if ($resultadoAccion['status'] === ProfesionalService::STATUS_NOT_FOUND) {
                if ($accion === 'guardar_horarios' || $accion === 'crear_descanso' || $accion === 'crear_bloqueo') {
                    header('Location: /profesionales');
                } else {
                    header("Location: /profesionales/horarios?id={$id}");
                }
                detener_ejecucion(302);
                return;
            }

            $alertas = $resultadoAccion['alertas'];
            if ($resultadoAccion['status'] === ProfesionalService::STATUS_ERROR) {
                http_response_code(500);
            } elseif (es_peticion_json()) {
                http_response_code(422);
            }
        }

        self::renderizarVistaHorarios($router, $service, $id, $alertas);
    }

    public static function crearDescanso(Router $router)
    {
        if (!array_key_exists('accion', $_POST)) {
            $_POST['accion'] = 'crear_descanso';
        } elseif ($_POST['accion'] !== 'crear_descanso') {
            $_POST['accion'] = '__accion_invalida__';
        }
        self::horarios($router);
    }

    public static function eliminarDescanso(Router $router)
    {
        if (!array_key_exists('accion', $_POST)) {
            $_POST['accion'] = 'eliminar_descanso';
        } elseif ($_POST['accion'] !== 'eliminar_descanso') {
            $_POST['accion'] = '__accion_invalida__';
        }
        self::horarios($router);
    }

    public static function crearBloqueo(Router $router)
    {
        if (!array_key_exists('accion', $_POST)) {
            $_POST['accion'] = 'crear_bloqueo';
        } elseif ($_POST['accion'] !== 'crear_bloqueo') {
            $_POST['accion'] = '__accion_invalida__';
        }
        self::horarios($router);
    }

    public static function eliminarBloqueo(Router $router)
    {
        if (!array_key_exists('accion', $_POST)) {
            $_POST['accion'] = 'eliminar_bloqueo';
        } elseif ($_POST['accion'] !== 'eliminar_bloqueo') {
            $_POST['accion'] = '__accion_invalida__';
        }
        self::horarios($router);
    }

    private static function ejecutarAccionAgenda(
        ProfesionalService $service,
        int $profesionalId,
        string $accion,
        array $post
    ): array {
        switch ($accion) {
            case 'crear_descanso':
                return $service->crearDescanso($profesionalId, $post);

            case 'eliminar_descanso':
                return $service->eliminarDescanso($profesionalId, $post['descansoId'] ?? ($post['id'] ?? null));

            case 'crear_bloqueo':
                return $service->crearBloqueo($profesionalId, $post);

            case 'eliminar_bloqueo':
                return $service->eliminarBloqueo($profesionalId, $post['bloqueoId'] ?? ($post['id'] ?? null));

            case 'guardar_horarios':
                return $service->guardarHorariosSemanales($profesionalId, $post['horarios']);

            default:
                return [
                    'status' => ProfesionalService::STATUS_INVALID,
                    'resultado' => false,
                    'alertas' => [
                        'error' => ['La acción solicitada no es válida.']
                    ]
                ];
        }
    }

    private static function renderizarVistaHorarios(
        Router $router,
        ProfesionalService $service,
        int $id,
        array $alertasPrevias
    ): void {
        $agenda = $service->obtenerAgendaCompleta($id);

        if ($agenda['status'] === ProfesionalService::STATUS_NOT_FOUND) {
            header('Location: /profesionales');
            detener_ejecucion(302);
            return;
        }

        if ($agenda['status'] === ProfesionalService::STATUS_ERROR) {
            http_response_code(500);
            if (empty($alertasPrevias)) {
                $alertasPrevias = $agenda['alertas'];
            }
        }

        $profesional = $agenda['profesional'] ?? new Profesional(['id' => (string)$id]);
        $horarios = $agenda['horarios'] ?? [];
        $descansos = $agenda['descansos'] ?? [];
        $bloqueos = $agenda['bloqueos'] ?? [];

        // Agrupar todas las franjas horarias por día de la semana (conservando múltiples franjas por día)
        $horariosPorDia = [];
        foreach (array_keys(HorarioProfesional::DIAS_SEMANA) as $numDia) {
            $horariosPorDia[$numDia] = [];
        }
        foreach ($horarios as $h) {
            $horariosPorDia[$h->dia_semana][] = $h;
        }

        $router->render('profesionales/horarios', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'profesional' => $profesional,
            'diasSemana' => HorarioProfesional::DIAS_SEMANA,
            'horarios' => $horarios,
            'horariosPorDia' => $horariosPorDia,
            'descansos' => $descansos,
            'bloqueos' => $bloqueos,
            'zonaHoraria' => ProfesionalService::TIMEZONE,
            'alertas' => $alertasPrevias
        ]);
    }
}
