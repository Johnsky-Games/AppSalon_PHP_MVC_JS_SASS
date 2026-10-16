<?php
namespace Controllers;

use Classes\ErrorResponse;
use Model\ActiveRecord;
use MVC\Router;
use Repositories\AltaClienteRepository;
use Repositories\PersistenceException;
use Repositories\UsuarioRepository;
use Services\AltaClienteService;
use Services\AuditoriaHuella;
use Services\AuthorizationService;

/**
 * Alta de un cliente por el equipo: GET|POST /admin/clientes/nuevo.
 * Permisos (comprobados en servidor): perfiles.gestionar y clientes.ver (recepción, gerencia, propietario).
 * Con posibles homónimos pide confirmación explícita («Es una persona distinta») o permite cancelar y usar la ficha existente.
 */
final class AltaClienteController
{
    private static ?AltaClienteService $service = null;

    public static function setService(?AltaClienteService $service): void { self::$service = $service; }

    private static function service(): AltaClienteService
    {
        $db = ActiveRecord::getDB();
        return self::$service ?? new AltaClienteService(new UsuarioRepository($db), new AltaClienteRepository($db));
    }

    /** Campos que se devuelven al formulario ante un error (nunca la contraseña, que no existe aquí). */
    private static function previo(): array
    {
        $previo = [];
        foreach (['nombre', 'apellido', 'email', 'telefono'] as $k) { $previo[$k] = is_string($_POST[$k] ?? null) ? mb_substr($_POST[$k], 0, 120) : ''; }
        return $previo;
    }

    public static function nuevo(Router $router): void
    {
        iniciar_sesion_segura();
        isAuth();
        exigir_permiso('perfiles.gestionar');
        exigir_permiso('clientes.ver');
        if (!headers_sent()) { header('Cache-Control: no-store'); }
        $resultado = null; $alertas = []; $previo = ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => ''];
        $clave = clave_operacion_formulario();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            exigir_csrf();
            $previo = self::previo();
            $actorId = (int)$_SESSION['id'];
            try {
                $rol = (new AuthorizationService())->contexto($actorId)['rol'] ?? null;
                $resultado = self::service()->registrar($actorId, $rol, $_POST, clave_operacion_enviada(), AuditoriaHuella::ip(AuditoriaHuella::ipDeSolicitud()));
            } catch (PersistenceException | \mysqli_sql_exception $e) {
                error_log('[AltaClienteController] datos no disponibles: ' . get_class($e));
                ErrorResponse::render(503, ['mensaje' => 'No se pudo completar el alta ahora. Inténtalo de nuevo en unos minutos.']);
                detener_ejecucion(503);
            }
            switch ($resultado['status']) {
                case AltaClienteService::OK:
                    redirigir('/admin/clientes/ficha?id=' . (int)$resultado['id'] . '&alta=ok');
                    return;
                case AltaClienteService::CANCELADO:
                    redirigir($resultado['id_existente'] ? '/admin/clientes/ficha?id=' . (int)$resultado['id_existente'] : '/admin/clientes');
                    return;
                case AltaClienteService::REQUIERE_CONFIRMACION:
                    // 200 la primera vez (paso normal del flujo); 409 si alguien intentó confirmar con un token que no corresponde.
                    http_response_code(!empty($resultado['confirmacion_invalida']) ? 409 : 200);
                    if (!empty($resultado['confirmacion_invalida'])) { $alertas['error'][] = 'La confirmación no corresponde a las coincidencias actuales. Revísalas de nuevo antes de continuar.'; }
                    break;
                case AltaClienteService::INVALID: http_response_code(422); $alertas['error'] = $resultado['errores']; break;
                case AltaClienteService::ERROR: http_response_code(500); $alertas['error'] = $resultado['errores']; break;
                default: http_response_code(409); $alertas['error'] = $resultado['errores'] ?? ['No se pudo completar el alta.']; break;
            }
        }
        $router->render('crm/alta', ['previo' => $previo, 'resultado' => $resultado, 'alertas' => $alertas, 'clave' => $clave]);
    }
}
