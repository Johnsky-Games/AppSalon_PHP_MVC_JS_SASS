<?php

namespace Controllers;

use Model\Servicio;
use MVC\Router;

class ServicioController
{
    public static function index(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $servicios = Servicio::all();

        $router->render('servicios/index', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'servicios' => $servicios
        ]);
    }

    public static function crear(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $servicio = new Servicio;
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $servicio->sincronizar($_POST);
            $alertas = $servicio->validar();

            if (empty($alertas)) {
                $servicio->guardar();
                header('Location: /servicios');
                exit;
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

        $id = $_GET['id'] ?? null;
        if (!is_numeric($id)) {
            header('Location: /servicios');
            exit;
        }

        $servicio = Servicio::find($id);
        if (!$servicio) {
            header('Location: /servicios');
            exit;
        }

        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $servicio->sincronizar($_POST);
            $alertas = $servicio->validar();

            if (empty($alertas)) {
                $servicio->guardar();
                header('Location: /servicios');
                exit;
            }
        }

        $router->render('servicios/actualizar', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'servicio' => $servicio,
            'alertas' => $alertas
        ]);
    }

    public static function eliminar()
    {
        iniciar_sesion_segura();
        isAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $id = $_POST['id'] ?? null;
            if (is_numeric($id)) {
                $servicio = Servicio::find($id);
                if ($servicio) {
                    $servicio->eliminar();
                }
            }
            header('Location: /servicios');
            exit;
        }
    }
}