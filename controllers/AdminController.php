<?php

namespace Controllers;

use Model\AdminCita;
use Model\ActiveRecord;
use MVC\Router;

class AdminController
{
    public static function index(Router $router)
    {
        iniciar_sesion_segura();
        isAdmin();

        $fecha = trim($_GET['fecha'] ?? $_GET['fecha_'] ?? date('Y-m-d'));
        $fechas = explode('-', $fecha);

        if (count($fechas) !== 3 || !checkdate((int)$fechas[1], (int)$fechas[2], (int)$fechas[0])) {
            $fecha = date('Y-m-d');
        }

        // Consultar base de datos utilizando consulta preparada
        $consulta = "SELECT citas.id, citas.hora, CONCAT(usuarios.nombre, ' ', usuarios.apellido) as cliente, ";
        $consulta .= " usuarios.email, usuarios.telefono, servicios.nombre as servicio, servicios.precio ";
        $consulta .= " FROM citas ";
        $consulta .= " LEFT OUTER JOIN usuarios ON citas.usuarioId = usuarios.id ";
        $consulta .= " LEFT OUTER JOIN citasservicios ON citasservicios.citaId = citas.id ";
        $consulta .= " LEFT OUTER JOIN servicios ON servicios.id = citasservicios.servicioId ";
        $consulta .= " WHERE fecha = ? ";

        $citas = ActiveRecord::consultarSQLPreparado($consulta, 's', [$fecha]);

        $router->render('admin/index', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'citas' => $citas,
            'fecha' => $fecha
        ]);
    }
}