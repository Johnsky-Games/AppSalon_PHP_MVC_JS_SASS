<?php

namespace Controllers;

use MVC\Router;
use Services\CitaService;

class CitaController
{
    public static function index(Router $router)
    {
        iniciar_sesion_segura();
        isAuth();

        $tz = new \DateTimeZone(CitaService::TIMEZONE);
        $fechaMinima = (new \DateTimeImmutable('tomorrow', $tz))->format('Y-m-d');

        $router->render('cita/index', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'id' => $_SESSION['id'] ?? '',
            'fechaMinima' => $fechaMinima,
        ]);
    }
}
