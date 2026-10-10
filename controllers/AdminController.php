<?php

namespace Controllers;

use Model\ActiveRecord;
use MVC\Router;
use Repositories\CitaRepository;
use Repositories\ServicioRepository;
use Services\CitaService;

class AdminController
{
    private static ?CitaService $citaService = null;

    public static function setCitaService(?CitaService $service): void
    {
        self::$citaService = $service;
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

    public static function index(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $fechaParam = $_GET['fecha'] ?? $_GET['fecha_'] ?? null;
        $resultado = self::obtenerCitaService()->consultarCitasAdmin($fechaParam);

        if ($resultado['status'] === CitaService::STATUS_ERROR) {
            http_response_code(500);
        }

        $router->render('admin/index', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'citas' => $resultado['citas'],
            'fecha' => $resultado['fecha'],
            'alertas' => $resultado['alertas'] ?? []
        ]);
    }
}