<?php

namespace Controllers;

use Model\ActiveRecord;
use Model\Servicio;
use MVC\Router;
use Repositories\ServicioRepository;
use Services\ServicioService;

/**
 * Controlador HTTP para la administración del catálogo de servicios.
 * Gestiona exclusivamente transporte HTTP, sesión, autorización de administrador,
 * protección CSRF, renderizado de vistas y redirecciones, delegando validación
 * y persistencia en ServicioService y ServicioRepository.
 */
class ServicioController
{
    private static ?ServicioService $servicioService = null;

    /**
     * Permite inyectar una instancia de ServicioService (o restablecerla con null).
     */
    public static function setServicioService(?ServicioService $service): void
    {
        self::$servicioService = $service;
    }

    /**
     * Obtiene la instancia inyectada de ServicioService o construye una por defecto
     * con la conexión activa de base de datos.
     */
    private static function obtenerServicioService(): ServicioService
    {
        if (self::$servicioService !== null) {
            return self::$servicioService;
        }

        return new ServicioService(new ServicioRepository(ActiveRecord::getDB()));
    }

    /**
     * Comprueba si los campos editables enviados en el formulario son escalares válidos.
     */
    private static function esCargaEscalarFormulario(array $datos): bool
    {
        $nombreOk = !isset($datos['nombre']) || is_string($datos['nombre']);
        $precioOk = !isset($datos['precio']) || is_string($datos['precio']) || is_int($datos['precio']) || is_float($datos['precio']);
        return $nombreOk && $precioOk;
    }

    public static function index(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $resultado = self::obtenerServicioService()->listar();
        if ($resultado['status'] === ServicioService::STATUS_ERROR) {
            http_response_code(500);
        }

        $router->render('servicios/index', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'servicios' => $resultado['servicios'],
            'alertas' => $resultado['alertas']
        ]);
    }

    public static function crear(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $servicio = new Servicio();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $resultado = self::obtenerServicioService()->crear($_POST);

            if ($resultado['status'] === ServicioService::STATUS_OK) {
                header('Location: /servicios');
                detener_ejecucion(302);
                return;
            }

            $servicio = $resultado['servicio'];
            $alertas = $resultado['alertas'];
            if ($resultado['status'] === ServicioService::STATUS_ERROR) {
                http_response_code(500);
            } elseif (es_peticion_json() || !self::esCargaEscalarFormulario($_POST)) {
                http_response_code(422);
            }
        }

        $router->render('servicios/crear', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'servicio' => $servicio,
            'alertas' => $alertas
        ]);
    }

    public static function actualizar(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $service = self::obtenerServicioService();
        $id = $service->validarId($_GET['id'] ?? null);
        if ($id === null) {
            header('Location: /servicios');
            detener_ejecucion(302);
            return;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $resultado = $service->actualizar($id, $_POST);

            if ($resultado['status'] === ServicioService::STATUS_OK ||
                $resultado['status'] === ServicioService::STATUS_NOT_FOUND) {
                header('Location: /servicios');
                detener_ejecucion(302);
                return;
            }

            $servicio = $resultado['servicio'] ?? new Servicio(['id' => (string)$id]);
            $alertas = $resultado['alertas'];
            if ($resultado['status'] === ServicioService::STATUS_ERROR) {
                http_response_code(500);
            } elseif (es_peticion_json() || !self::esCargaEscalarFormulario($_POST)) {
                http_response_code(422);
            }
        } else {
            $consulta = $service->obtenerPorId($id);

            if ($consulta['status'] === ServicioService::STATUS_NOT_FOUND ||
                $consulta['status'] === ServicioService::STATUS_INVALID) {
                header('Location: /servicios');
                detener_ejecucion(302);
                return;
            }

            if ($consulta['status'] === ServicioService::STATUS_ERROR) {
                http_response_code(500);
                $servicio = new Servicio(['id' => (string)$id]);
                $alertas = $consulta['alertas'];
            } else {
                $servicio = $consulta['servicio'];
            }
        }

        $router->render('servicios/actualizar', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'servicio' => $servicio,
            'alertas' => $alertas
        ]);
    }

    public static function eliminar(?Router $router = null)
    {
        iniciar_sesion_segura();
        isAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $service = self::obtenerServicioService();
            $resultado = $service->eliminar($_POST['id'] ?? null);

            if ($resultado['status'] === ServicioService::STATUS_ERROR) {
                http_response_code(500);
                if ($router !== null) {
                    $listado = $service->listar();
                    $router->render('servicios/index', [
                        'nombre' => $_SESSION['nombre'] ?? '',
                        'apellido' => $_SESSION['apellido'] ?? '',
                        'servicios' => $listado['servicios'] ?? [],
                        'alertas' => $resultado['alertas']
                    ]);
                } else {
                    echo $resultado['alertas']['error'][0] ?? 'Error al eliminar el servicio.';
                }
                detener_ejecucion(500);
                return;
            }

            header('Location: /servicios');
            detener_ejecucion(302);
            return;
        }
    }
}