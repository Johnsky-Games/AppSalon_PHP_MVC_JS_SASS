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
        $activoOk = !isset($datos['activo']) || is_scalar($datos['activo']);
        $serviciosOk = !isset($datos['servicios']) || is_array($datos['servicios']);
        return $nombreOk && $activoOk && $serviciosOk;
    }

    private static function esCargaFormularioAgendaValida(array $datos): bool
    {
        $accionOk = !isset($datos['accion']) || is_string($datos['accion']);
        $horariosOk = !isset($datos['horarios']) || is_array($datos['horarios']);
        $diaOk = !isset($datos['dia_semana']) || is_scalar($datos['dia_semana']);
        $horaInicioOk = !isset($datos['hora_inicio']) || is_string($datos['hora_inicio']);
        $horaFinOk = !isset($datos['hora_fin']) || is_string($datos['hora_fin']);
        $fechaInicioOk = !isset($datos['fecha_inicio']) || is_string($datos['fecha_inicio']);
        $fechaFinOk = !isset($datos['fecha_fin']) || is_string($datos['fecha_fin']);
        return $accionOk && $horariosOk && $diaOk && $horaInicioOk && $horaFinOk && $fechaInicioOk && $fechaFinOk;
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

            if ($resultado['status'] === ProfesionalService::STATUS_INVALID && es_peticion_json()) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'resultado' => false,
                    'error' => $resultado['alertas']['error'][0] ?? 'Datos inválidos'
                ]);
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

            $accion = is_string($_POST['accion'] ?? null) ? trim($_POST['accion']) : 'guardar_horarios';
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
            } elseif (es_peticion_json() || !self::esCargaFormularioAgendaValida($_POST)) {
                http_response_code(422);
            }
        }

        self::renderizarVistaHorarios($router, $service, $id, $alertas);
    }

    public static function crearDescanso(Router $router)
    {
        $_POST['accion'] = 'crear_descanso';
        self::horarios($router);
    }

    public static function eliminarDescanso(Router $router)
    {
        $_POST['accion'] = 'eliminar_descanso';
        self::horarios($router);
    }

    public static function crearBloqueo(Router $router)
    {
        $_POST['accion'] = 'crear_bloqueo';
        self::horarios($router);
    }

    public static function eliminarBloqueo(Router $router)
    {
        $_POST['accion'] = 'eliminar_bloqueo';
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
            default:
                $horariosRaw = is_array($post['horarios'] ?? null) ? $post['horarios'] : [];
                return $service->guardarHorariosSemanales($profesionalId, $horariosRaw);
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

        // Indexar horarios por día de la semana para el formulario semanal
        $horariosPorDia = [];
        foreach ($horarios as $h) {
            if (!isset($horariosPorDia[$h->dia_semana])) {
                $horariosPorDia[$h->dia_semana] = $h;
            }
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
