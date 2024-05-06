<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;

class CitaController extends ActiveRecord
{

    public static function index(Router $router)
    {
        session_start();
        // Agregado luego eliminar hasta llegar a la parte de proteger la ruta
        isAuth();

        $router->render('cita/index', [
            'nombre' => $_SESSION['nombre'],
            'apellido' => $_SESSION['apellido'],
            'id' => $_SESSION['id'],
        ]);
    }

}