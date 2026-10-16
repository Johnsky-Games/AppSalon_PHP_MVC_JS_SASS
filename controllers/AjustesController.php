<?php
namespace Controllers;
use Model\ActiveRecord;
use MVC\Router;
use Repositories\CrecimientoRepository;
use Services\CrecimientoService;
class AjustesController
{
    public static function index(Router $router): void
    {
        iniciar_sesion_segura();exigir_permiso('ajustes.gestionar');$mensaje='';$error='';
        $service=new CrecimientoService(new CrecimientoRepository(ActiveRecord::getDB()));
        try {
            if($_SERVER['REQUEST_METHOD']==='POST') {
                if(!validar_csrf()){http_response_code(403);detener_ejecucion(403);}
                $service->guardarAjustes((int)$_SESSION['id'],$_POST);$mensaje='Información actualizada.';
            }
            $ajustes=$service->ajustes();
        }catch(\InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();$ajustes=$service->ajustes();}
        catch(\Repositories\PersistenceException $e){http_response_code(500);$error='No se pudieron guardar los ajustes.';$ajustes=[];}
        $router->render('ajustes/index',compact('ajustes','mensaje','error'));
    }
}
