<?php

// El renderer de emergencia se carga antes que las dependencias y la base de datos.
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../classes/ErrorResponse.php';
require_once __DIR__ . '/../classes/ServiceUnavailableException.php';
ini_set('display_errors', '0');
$requestBufferLevel = ob_get_level();
ob_start();

use Controllers\AdminController;
use Controllers\CitaController;
use Controllers\LoginController;
use Controllers\APIController;
use Controllers\PagoController;
use Controllers\ProfesionalController;
use Controllers\ReporteController;
use Controllers\ServicioController;
use MVC\Router;

try {
require_once __DIR__ . '/../includes/app.php';

$router = new Router();

//Iniciar sesion
// Portal de clientes (compatibilidad: `/` y `/crear-cuenta` siguen funcionando).
$router->get('/', [LoginController::class, 'login']);
$router->post('/', [LoginController::class, 'login']);
$router->get('/cliente/login', [LoginController::class, 'loginCliente']);
$router->post('/cliente/login', [LoginController::class, 'loginCliente']);
$router->get('/cliente/registro', [LoginController::class, 'crear']);
$router->post('/cliente/registro', [LoginController::class, 'crear']);
// Portal del equipo: sin registro público; las cuentas nacen de una invitación aceptada.
$router->get('/equipo/login', [LoginController::class, 'loginEquipo']);
$router->post('/equipo/login', [LoginController::class, 'loginEquipo']);
$router->get('/equipo/aceptar', [\Controllers\InvitacionController::class, 'aceptar']);
$router->post('/equipo/aceptar', [\Controllers\InvitacionController::class, 'aceptar']);
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

//Base de Pagos (Fase 6C)
$router->post('/api/pagos/iniciar', [PagoController::class, 'iniciar']);
$router->post('/api/pagos/webhook', [PagoController::class, 'webhook']);
$router->get('/pago/resultado', [PagoController::class, 'resultado']);
$router->post('/api/pagos/reembolsar', [PagoController::class, 'reembolsar']);

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

//Reportes Administrativos (Fase 6B)
$router->get('/admin/reportes', [ReporteController::class, 'index']);
$router->get('/admin/reportes/exportar', [ReporteController::class, 'exportarCsv']);

// Plataforma integral: identidad, operación y gestión comercial.
$router->get('/admin/equipo', [\Controllers\EquipoController::class, 'index']);
$router->post('/admin/equipo/crear', [\Controllers\EquipoController::class, 'crear']);
$router->post('/admin/equipo/actualizar', [\Controllers\EquipoController::class, 'actualizar']);
$router->post('/admin/equipo/invitar', [\Controllers\EquipoController::class, 'invitar']);
$router->post('/admin/equipo/revocar', [\Controllers\EquipoController::class, 'revocar']);
$router->get('/admin/equipo/ficha', [\Controllers\EquipoController::class, 'ficha']);
$router->post('/admin/equipo/ficha', [\Controllers\EquipoController::class, 'ficha']);
$router->get('/perfil', [\Controllers\ProfileController::class, 'index']);
$router->post('/perfil', [\Controllers\ProfileController::class, 'index']);
$router->get('/perfil/foto', [\Controllers\ProfileController::class, 'foto']);
$router->post('/perfil/foto', [\Controllers\ProfileController::class, 'guardarFoto']);
$router->get('/agenda', [\Controllers\AgendaController::class, 'index']);
$router->get('/mis-citas', [\Controllers\AgendaController::class, 'historial']);
$router->post('/agenda/estado', [\Controllers\AgendaController::class, 'estado']);
$router->post('/agenda/reprogramar', [\Controllers\AgendaController::class, 'reprogramar']);
$router->get('/admin/finanzas', [\Controllers\FinanzasController::class, 'index']);
$router->post('/admin/finanzas', [\Controllers\FinanzasController::class, 'guardar']);
$router->get('/admin/inventario', [\Controllers\InventarioController::class, 'index']);
$router->post('/admin/inventario', [\Controllers\InventarioController::class, 'guardar']);
$router->get('/admin/crecimiento', [\Controllers\CrecimientoController::class, 'index']);
$router->post('/admin/crecimiento', [\Controllers\CrecimientoController::class, 'index']);
$router->get('/mi-bienestar', [\Controllers\CrecimientoController::class, 'cliente']);
$router->post('/mi-bienestar', [\Controllers\CrecimientoController::class, 'cliente']);
$router->get('/admin/ajustes', [\Controllers\AjustesController::class, 'index']);
$router->post('/admin/ajustes', [\Controllers\AjustesController::class, 'index']);

// CRM de clientes (módulo CrmController: listado, ficha 360° y exportación).
$router->get('/admin/clientes', [\Controllers\CrmController::class, 'index']);
$router->get('/admin/clientes/ficha', [\Controllers\CrmController::class, 'ficha']);
$router->get('/admin/clientes/exportar', [\Controllers\CrmController::class, 'exportar']);
$router->get('/admin/clientes/auditoria', [\Controllers\CrmController::class, 'auditoria']);
$router->get('/admin/clientes/nuevo', [\Controllers\AltaClienteController::class, 'nuevo']);
$router->post('/admin/clientes/nuevo', [\Controllers\AltaClienteController::class, 'nuevo']);

// Comprueba y valida las rutas, que existan y les asigna las funciones del Controlador
    emitir_cabeceras_seguridad();
    $router->comprobarRutas();
} catch (\AppTerminationException $e) {
    // Redirecciones y respuestas controladas ya fueron emitidas por el controlador.
} catch (\Throwable $e) {
    while (ob_get_level() > $requestBufferLevel) {
        ob_end_clean();
    }
    \Classes\ErrorResponse::fromThrowable($e);
}
while (ob_get_level() > $requestBufferLevel) {
    ob_end_flush();
}
