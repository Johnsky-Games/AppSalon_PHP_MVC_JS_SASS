<?php

namespace Controllers;

use Model\ActiveRecord;
use Repositories\CitaRepository;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\CitaService;
use Services\DisponibilidadService;
use Services\ServicioService;

class APIController
{
    private static ?ServicioService $servicioService = null;
    private static ?CitaService $citaService = null;
    private static ?DisponibilidadService $disponibilidadService = null;

    public static function setServicioService(?ServicioService $service): void
    {
        self::$servicioService = $service;
    }

    public static function setCitaService(?CitaService $service): void
    {
        self::$citaService = $service;
    }

    public static function setDisponibilidadService(?DisponibilidadService $service): void
    {
        self::$disponibilidadService = $service;
    }

    private static function obtenerServicioService(): ServicioService
    {
        if (self::$servicioService !== null) {
            return self::$servicioService;
        }

        return new ServicioService(new ServicioRepository(ActiveRecord::getDB()));
    }

    private static function obtenerCitaService(): CitaService
    {
        if (self::$citaService !== null) {
            return self::$citaService;
        }

        $db = ActiveRecord::getDB();
        return new CitaService(
            new CitaRepository($db),
            new ServicioRepository($db)
        );
    }

    private static function obtenerDisponibilidadService(): DisponibilidadService
    {
        if (self::$disponibilidadService !== null) {
            return self::$disponibilidadService;
        }

        $db = ActiveRecord::getDB();
        return new DisponibilidadService(
            new ProfesionalRepository($db),
            new ServicioRepository($db),
            null,
            new CitaRepository($db)
        );
    }

    public static function index()
    {
        header('Content-Type: application/json; charset=utf-8');
        $resultado = self::obtenerServicioService()->listar();

        if ($resultado['status'] === ServicioService::STATUS_ERROR) {
            http_response_code(500);
            echo json_encode([
                'resultado' => false,
                'error' => 'No fue posible consultar el catálogo de servicios'
            ]);
            detener_ejecucion(500);
            return;
        }

        http_response_code(200);
        echo json_encode($resultado['servicios']);
    }

    /**
     * Endpoint de lectura autenticado `GET /api/disponibilidad` (Fases 4A y 4B).
     *
     * Calcula los intervalos disponibles `[inicio, fin)` según la configuración de agenda del profesional,
     * descontando descansos, bloqueos y citas existentes en `America/Guayaquil`, y calculando la duración
     * total desde el catálogo para los servicios seleccionados.
     *
     * Distingue:
     * - No autenticado (`HTTP 401`, `status: 'unauthorized'`, `codigo: 'no_autenticado'`).
     * - Solicitud inválida (`HTTP 422`, `status: 'invalid'`, `codigo: 'solicitud_invalida'`).
     * - Profesional inexistente (`HTTP 404`, `status: 'professional_not_found'`, `codigo: 'profesional_no_encontrado'`).
     * - Profesional inactivo (`HTTP 409`, `status: 'professional_inactive'`, `codigo: 'profesional_inactivo'`).
     * - Servicios incompatibles (`HTTP 422`, `status: 'incompatible_services'`, `codigo: 'servicios_incompatibles'`).
     * - Disponibilidad vacía (`HTTP 200`, `status: 'empty'`, `codigo: 'disponibilidad_vacia'`, `disponible: false`, `intervalos: []`).
     * - Disponibilidad con intervalos (`HTTP 200`, `status: 'ok'`, `codigo: 'disponibilidad_encontrada'`, `disponible: true`, `intervalos: [...]`).
     * - Fallo SQL (`HTTP 500`, `status: 'error'`, `codigo: 'error_persistencia'`).
     */
    public static function disponibilidad()
    {
        iniciar_sesion_segura();
        header('Content-Type: application/json; charset=utf-8');

        $idSesion = $_SESSION['id'] ?? null;
        $esIdValido = is_int($idSesion)
            ? $idSesion >= 1
            : (is_string($idSesion) && ctype_digit(trim($idSesion)) && (int)trim($idSesion) >= 1);

        if (!isset($_SESSION['login']) || $_SESSION['login'] !== true || !$esIdValido) {
            http_response_code(401);
            echo json_encode([
                'resultado' => false,
                'disponible' => false,
                'status' => 'unauthorized',
                'codigo' => 'no_autenticado',
                'intervalos' => [],
                'error' => 'No autenticado'
            ]);
            detener_ejecucion(401);
            return;
        }

        $resultado = self::obtenerDisponibilidadService()->consultarDesdeParametros($_GET);
        $httpCode = (int)($resultado['httpCode'] ?? 200);
        unset($resultado['httpCode']);

        http_response_code($httpCode);
        echo json_encode($resultado);
        if ($httpCode !== 200) {
            detener_ejecucion($httpCode);
        }
    }

    /**
     * Guarda una cita y sus servicios dentro de una transacción atómica.
     * Valida autenticación y CSRF en el controlador, y delega reglas de negocio
     * y persistencia a CitaService entregando la identidad verificada de la sesión.
     */
    public static function guardar()
    {
        iniciar_sesion_segura();
        header('Content-Type: application/json; charset=utf-8');

        // 1. Verificación de Autenticación
        $idSesion = $_SESSION['id'] ?? null;
        $esIdValido = is_int($idSesion)
            ? $idSesion >= 1
            : (is_string($idSesion) && ctype_digit(trim($idSesion)) && (int)trim($idSesion) >= 1);

        if (!isset($_SESSION['login']) || $_SESSION['login'] !== true || !$esIdValido) {
            http_response_code(401);
            echo json_encode(['resultado' => false, 'error' => 'No autenticado']);
            detener_ejecucion(401);
            return;
        }

        // 2. Verificación de CSRF
        if (!validar_csrf()) {
            http_response_code(403);
            echo json_encode(['resultado' => false, 'error' => 'Token CSRF inválido o ausente']);
            detener_ejecucion(403);
            return;
        }

        // 3. Delegar validación de negocio y persistencia atómica a CitaService
        // entregando la identidad verificada desde la sesión (nunca del POST)
        $usuarioIdAutenticado = $_SESSION['id'];
        $resultado = self::obtenerCitaService()->reservar($usuarioIdAutenticado, $_POST);

        if ($resultado['status'] === CitaService::STATUS_UNAUTHORIZED) {
            http_response_code(401);
            echo json_encode(['resultado' => false, 'error' => $resultado['error']]);
            detener_ejecucion(401);
            return;
        }

        if ($resultado['status'] === CitaService::STATUS_CONFLICT) {
            http_response_code(409);
            echo json_encode([
                'resultado' => false,
                'status' => $resultado['status'],
                'codigo' => $resultado['codigo'] ?? 'conflicto_ocupacion',
                'error' => $resultado['error']
            ]);
            detener_ejecucion(409);
            return;
        }

        if (
            $resultado['status'] === CitaService::STATUS_NOT_FOUND
            && (int)($resultado['httpCode'] ?? 422) === 404
        ) {
            http_response_code(404);
            echo json_encode([
                'resultado' => false,
                'status' => $resultado['status'],
                'codigo' => $resultado['codigo'] ?? 'profesional_no_encontrado',
                'error' => $resultado['error']
            ]);
            detener_ejecucion(404);
            return;
        }

        if (
            $resultado['status'] === CitaService::STATUS_INVALID
            || $resultado['status'] === CitaService::STATUS_NOT_FOUND
        ) {
            http_response_code(422);
            echo json_encode([
                'resultado' => false,
                'status' => $resultado['status'],
                'codigo' => $resultado['codigo'] ?? 'solicitud_invalida',
                'error' => $resultado['error']
            ]);
            detener_ejecucion(422);
            return;
        }

        if ($resultado['status'] === CitaService::STATUS_ERROR) {
            http_response_code(500);
            echo json_encode(['resultado' => false, 'error' => $resultado['error']]);
            return;
        }

        http_response_code(200);
        // Conservar el contrato JSON esperado por src/js/app.js (resultado.resultado truthy)
        $respuesta = ['resultado' => $resultado['resultado']];
        if (isset($resultado['profesionalId'])) {
            $respuesta['id'] = $resultado['id'];
            $respuesta['profesionalId'] = $resultado['profesionalId'];
            $respuesta['fecha'] = $resultado['fecha'];
            $respuesta['hora_inicio'] = $resultado['hora_inicio'];
            $respuesta['hora_fin'] = $resultado['hora_fin'];
            $respuesta['duracion_total_minutos'] = $resultado['duracion_total_minutos'];
        }
        echo json_encode($respuesta);
    }

    /**
     * Eliminación de cita con verificación de autenticación y CSRF en el controlador,
     * delegando la verificación de pertenencia (propietario o administrador) y el
     * borrado transaccional a CitaService.
     */
    public static function eliminar()
    {
        iniciar_sesion_segura();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();
            isAuth();

            $usuarioIdAutenticado = $_SESSION['id'] ?? null;
            $esAdmin = isset($_SESSION['admin']) && (string)$_SESSION['admin'] === '1';

            $resultado = self::obtenerCitaService()->eliminar(
                $_POST['id'] ?? null,
                $usuarioIdAutenticado,
                $esAdmin
            );

            if ($resultado['status'] === CitaService::STATUS_INVALID) {
                header('Location: /admin');
                detener_ejecucion(302);
                return;
            }

            if ($resultado['status'] === CitaService::STATUS_NOT_FOUND) {
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin'));
                detener_ejecucion(302);
                return;
            }

            if (
                $resultado['status'] === CitaService::STATUS_FORBIDDEN
                || $resultado['status'] === CitaService::STATUS_UNAUTHORIZED
            ) {
                http_response_code(403);
                if (es_peticion_json()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'resultado' => false,
                        'error' => $resultado['error'] ?? 'No tienes autorización para eliminar esta cita'
                    ]);
                } else {
                    echo "Error 403: No tienes autorización para eliminar esta cita.";
                }
                detener_ejecucion(403);
                return;
            }

            if ($resultado['status'] === CitaService::STATUS_ERROR) {
                http_response_code(500);
                $mensajeError = $resultado['error'] ?? 'No fue posible eliminar la cita';
                if (es_peticion_json()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'resultado' => false,
                        'error' => $mensajeError
                    ]);
                } else {
                    echo "Error 500: " . s((string)$mensajeError);
                }
                detener_ejecucion(500);
                return;
            }

            $destino = $_SERVER['HTTP_REFERER'] ?? ($esAdmin ? '/admin' : '/cita');
            header('Location: ' . $destino);
            detener_ejecucion(302);
            return;
        }
    }
}