<?php
namespace Controllers;
use MVC\Router;
use Repositories\OperacionRepository;
use Services\InventarioService;
final class InventarioController
{
    public static function index(Router $router): void
    {
        iniciar_sesion_segura(); exigir_permiso('inventario.gestionar');
        $pagina=max(1,min(10000,(int)($_GET['pagina'] ?? 1)));
        $repo=new OperacionRepository();
        $router->render('inventario/index',['productos'=>$repo->productos($pagina),'movimientos'=>$repo->movimientosInventario(),'pagina'=>$pagina,'alertas'=>$_SESSION['inventario_alertas'] ?? []]);
        unset($_SESSION['inventario_alertas']);
    }
    public static function guardar(Router $router): void
    {
        iniciar_sesion_segura(); exigir_permiso('inventario.gestionar');
        exigir_csrf();
        try {
            $service=new InventarioService();
            if (($_POST['accion'] ?? '')==='crear') { $service->crear($_POST); }
            elseif (($_POST['accion'] ?? '')==='mover') { $service->mover((int)$_SESSION['id'],$_POST); }
            else { throw new \InvalidArgumentException('Acción inválida.'); }
            $_SESSION['inventario_alertas']=['exito'=>['Inventario actualizado y movimiento registrado.']];
        } catch (\InvalidArgumentException $e) { $_SESSION['inventario_alertas']=['error'=>[$e->getMessage()]]; }
        catch (\Throwable $e) { error_log('[Inventario] '.$e->getMessage()); $_SESSION['inventario_alertas']=['error'=>['No se pudo guardar. Compruebe que el SKU sea único e inténtelo otra vez.']]; }
        header('Location: /admin/inventario',true,303); detener_ejecucion(303);
    }
}
