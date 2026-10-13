<?php
namespace Controllers;

use Model\ActiveRecord;
use MVC\Router;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Repositories\ServicioRepository;
use Services\AuthorizationService;
use Services\ColaboradorService;
use Services\CorreoSalidaService;

/**
 * Gestión del equipo (portal del equipo): invitaciones formales, estados laborales, roles y ficha laboral.
 * Cada mutación exige permiso en el servidor, CSRF y, en las altas, la clave de operación del formulario.
 */
class EquipoController
{
    private static function repo(): ColaboradorRepository { return new ColaboradorRepository(ActiveRecord::getDB()); }
    private static function servicio(): ColaboradorService
    {
        $db = ActiveRecord::getDB();
        return new ColaboradorService(new ColaboradorRepository($db), new CorreoSalidaService(new CorreoSalidaRepository($db)));
    }

    public static function index(Router $router): void
    {
        exigir_permiso('equipo.gestionar');
        $db = ActiveRecord::getDB();
        // Reintento oportunista de correos pendientes: sin worker permanente el equipo sigue viendo su avance.
        try { (new CorreoSalidaService(new CorreoSalidaRepository($db)))->procesarPendientes(3); } catch (\Throwable) { /* se verá como pendiente */ }
        $repo = self::repo();
        $alertas = $_SESSION['equipo_alertas'] ?? [];
        unset($_SESSION['equipo_alertas']);
        $servicios = (new ServicioRepository($db))->findAll();
        $router->render('equipo/index', [
            'colaboradores' => $repo->listar(),
            'fichasSinCuenta' => $repo->profesionalesSinCuenta(),
            'servicios' => is_array($servicios) ? $servicios : [],
            'roles' => ColaboradorRepository::ROLES_INVITABLES,
            'rolesAcceso' => array_values(array_filter(array_keys(AuthorizationService::ROLES), static fn($r) => $r !== 'cliente')),
            'alertas' => $alertas,
            'puedeRh' => puede('rh.gestionar'),
            'previo' => $_SESSION['equipo_previo'] ?? [],
        ]);
        unset($_SESSION['equipo_previo']);
    }

    public static function crear(): void { self::mutar('crear'); }
    public static function actualizar(): void { self::mutar('actualizar'); }
    public static function invitar(): void { self::mutar('reenviar'); }
    public static function revocar(): void { self::mutar('revocar'); }

    private static function mutar(string $accion): void
    {
        exigir_permiso('equipo.gestionar');
        exigir_csrf();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); detener_ejecucion(405); }
        $actor = (int)$_SESSION['id'];
        $servicio = self::servicio();
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $clave = clave_operacion_enviada();
        $r = ['status' => ColaboradorService::INVALID, 'errores' => ['Datos no válidos.']];
        $mensaje = '';
        switch ($accion) {
            case 'crear':
                $r = $servicio->invitar($actor, $_POST, $clave);
                if ($r['status'] === ColaboradorService::OK) {
                    $mensaje = match ($r['correo'] ?? 'pendiente') {
                        'enviado' => 'Invitación enviada. La persona no tendrá acceso al equipo hasta que la acepte.',
                        'fallido' => 'La invitación quedó guardada, pero el correo no pudo enviarse. Se reintentará; también puedes pulsar «Reenviar invitación».',
                        default => 'Invitación guardada. El correo está en cola y se enviará en breve; no se creará ninguna otra invitación si repites la acción.',
                    };
                    if (!empty($r['repetida'])) { $mensaje = 'Esta invitación ya estaba registrada; se muestra el resultado original.'; }
                    if (!empty($r['profesional_id'])) { $mensaje .= ' Configura sus horarios para habilitar reservas.'; }
                } else { $_SESSION['equipo_previo'] = array_diff_key($_POST, ['csrf_token' => 1]); }
                break;
            case 'reenviar':
                if (!$id) { break; }
                $r = $servicio->reenviar($actor, $id, $clave);
                if ($r['status'] === ColaboradorService::OK) {
                    $mensaje = ($r['correo'] ?? 'pendiente') === 'enviado' ? 'Invitación reenviada. El enlace anterior dejó de funcionar.' : 'Invitación renovada; el correo se enviará en breve.';
                    if (!empty($r['repetida'])) { $mensaje = 'Esta solicitud de reenvío ya se había procesado.'; }
                }
                break;
            case 'revocar':
                if (!$id) { break; }
                $r = $servicio->revocar($actor, $id);
                if ($r['status'] === ColaboradorService::OK) { $mensaje = 'Invitación revocada. El enlace ya no sirve.'; }
                break;
            case 'actualizar':
                if (!$id || !is_string($_POST['rol'] ?? null) || !is_string($_POST['estado'] ?? null)) { break; }
                $r = $servicio->cambiarAcceso($actor, $id, $_POST['rol'], $_POST['estado']);
                if ($r['status'] === ColaboradorService::OK) { $mensaje = 'Acceso actualizado. Las sesiones anteriores de esa persona fueron revocadas.'; }
                break;
        }
        if ($r['status'] === ColaboradorService::OK) {
            $_SESSION['equipo_alertas'] = ['exito' => [$mensaje]];
        } else {
            $_SESSION['equipo_alertas'] = ['error' => $r['errores'] ?? ['No se pudo completar la operación.']];
        }
        redirigir('/admin/equipo');
    }

    public static function ficha(Router $router): void
    {
        exigir_permiso('rh.gestionar');
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $colaborador = $id ? self::repo()->obtener($id) : null;
        if (!$colaborador) { \Classes\ErrorResponse::render(404); detener_ejecucion(404); }
        $alertas = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            exigir_csrf();
            $r = self::servicio()->actualizarFicha((int)$_SESSION['id'], (int)$id, $_POST);
            if ($r['status'] === ColaboradorService::OK) {
                $_SESSION['equipo_alertas'] = ['exito' => ['Ficha laboral actualizada.']];
                redirigir('/admin/equipo');
                return;
            }
            $alertas = ['error' => $r['errores'] ?? ['No se pudo guardar la ficha.']];
            http_response_code(422);
        }
        $router->render('equipo/ficha', ['colaborador' => $colaborador, 'alertas' => $alertas]);
    }
}
