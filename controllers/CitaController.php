<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;

class CitaController extends ActiveRecord
{

    public static function index(Router $router)
    {
        iniciar_sesion_segura();
        isAuth();

        $router->render('cita/index', [
            'nombre' => $_SESSION['nombre'],
            'apellido' => $_SESSION['apellido'],
            'id' => $_SESSION['id'],
        ]);
    }

}