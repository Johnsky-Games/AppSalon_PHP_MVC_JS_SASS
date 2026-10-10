<?php

require_once __DIR__ . '/../includes/app.php';

use Controllers\AdminController;
use Controllers\CitaController;
use Controllers\LoginController;
use Controllers\APIController;
use Controllers\ProfesionalController;
use Controllers\ServicioController;
use MVC\Router;

$router = new Router();

//Iniciar sesion
$router->get('/', [LoginController::class, 'login']);
$router->post('/', [LoginController::class, 'login']);
$router->get('/logout', [LoginController::class, 'logout']);
$router->post('/logout', [LoginController::class, 'logout']);

//Recuperar contraseña
$router->get('/olvide', [LoginController::class, 'olvide']);
$router->post('/olvide', [LoginController::class, 'olvide']);
$router->get('/recuperar', [LoginController::class, 'recuperar']);
$router->post('/recuperar', [LoginController::class, 'recuperar']);
//Actualizar contraseña
$router->get('/crear-cuenta', [LoginController::class, 'crear']);
$router->post('/crear-cuenta', [LoginController::class, 'crear']);

//Confirmar cuenta
$router->get('/confirmar-cuenta', [LoginController::class, 'confirmar']);
$router->get('/mensaje', [LoginController::class, 'mensaje']);
$router->get('/reenviar-confirmacion', [LoginController::class, 'reenviarConfirmacion']);
$router->post('/reenviar-confirmacion', [LoginController::class, 'reenviarConfirmacion']);

//REA PRIVADA

$router->get('/cita', [CitaController::class, 'index']);
$router->get('/admin', [AdminController::class, 'index']);
$router->post('/api/eliminar', [ApiController::class, 'eliminar']);

//API de citas
$router->get('/api/servicios', [APIController::class, 'index']);
$router->get('/api/profesionales', [APIController::class, 'profesionales']);
$router->get('/api/disponibilidad', [APIController::class, 'disponibilidad']);
$router->get('/api/mis-citas', [APIController::class, 'misCitas']);
$router->post('/api/citas', [APIController::class, 'guardar']);

//CRUD de Servicios
$router->get('/servicios', [ServicioController::class, 'index']);
$router->get('/servicios/crear', [ServicioController::class, 'crear']);
$router->post('/servicios/crear', [ServicioController::class, 'crear']);
$router->get('/servicios/actualizar', [ServicioController::class, 'actualizar']);
$router->post('/servicios/actualizar', [ServicioController::class, 'actualizar']);
$router->post('/servicios/eliminar', [ServicioController::class, 'eliminar']);

//Administración de Profesionales y Configuración de Horarios
$router->get('/profesionales', [ProfesionalController::class, 'index']);
$router->get('/profesionales/crear', [ProfesionalController::class, 'crear']);
$router->post('/profesionales/crear', [ProfesionalController::class, 'crear']);
$router->get('/profesionales/actualizar', [ProfesionalController::class, 'actualizar']);
$router->post('/profesionales/actualizar', [ProfesionalController::class, 'actualizar']);
$router->post('/profesionales/estado', [ProfesionalController::class, 'cambiarEstado']);
$router->get('/profesionales/horarios', [ProfesionalController::class, 'horarios']);
$router->post('/profesionales/horarios', [ProfesionalController::class, 'horarios']);
$router->post('/profesionales/descansos/crear', [ProfesionalController::class, 'crearDescanso']);
$router->post('/profesionales/descansos/eliminar', [ProfesionalController::class, 'eliminarDescanso']);
$router->post('/profesionales/bloqueos/crear', [ProfesionalController::class, 'crearBloqueo']);
$router->post('/profesionales/bloqueos/eliminar', [ProfesionalController::class, 'eliminarBloqueo']);

// Comprueba y valida las rutas, que existan y les asigna las funciones del Controlador
try {
    $router->comprobarRutas();
} catch (\AppTerminationException $e) {
    // Terminación controlada tras emisión de encabezados o respuesta
    exit;
}