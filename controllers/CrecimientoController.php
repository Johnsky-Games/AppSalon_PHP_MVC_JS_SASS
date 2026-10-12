<?php
namespace Controllers;
use Model\ActiveRecord;
use MVC\Router;
use Repositories\CrecimientoRepository;
use Services\CrecimientoService;

class CrecimientoController
{
    private static function service(): CrecimientoService { return new CrecimientoService(new CrecimientoRepository(ActiveRecord::getDB())); }
    public static function index(Router $router): void
    {
        iniciar_sesion_segura(); exigir_permiso('marketing.gestionar'); $mensaje=''; $error=''; $respuestas=[];
        try {
            if($_SERVER['REQUEST_METHOD']==='POST') {
                if(!validar_csrf()){http_response_code(403); detener_ejecucion(403);}
                if(($_POST['accion']??'')==='segmento') { self::csv(self::service()->segmento((string)($_POST['segmento']??''))); return; }
                $mensaje=self::service()->accionAdmin((int)$_SESSION['id'],$_POST);
            }
            if(isset($_GET['formulario_id'])) {
                exigir_permiso('perfiles.gestionar');
                $fid=filter_var($_GET['formulario_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if(!$fid){throw new \InvalidArgumentException('Formulario inválido.');}
                $respuestas=self::service()->respuestas($fid);
            }
            $datos=self::service()->dashboard();
        } catch(\InvalidArgumentException $e) {http_response_code(422);$error=$e->getMessage();$datos=self::service()->dashboard();}
        catch(\Repositories\PersistenceException $e){http_response_code(500);$error='No se pudo completar la operación.';$datos=[];}
        $router->render('crecimiento/index',compact('datos','mensaje','error','respuestas'));
    }
    public static function cliente(Router $router): void
    {
        iniciar_sesion_segura();isAuth(); $mensaje='';$error='';$service=self::service();
        try {
            if($_SERVER['REQUEST_METHOD']==='POST') {
                if(!validar_csrf()){http_response_code(403);detener_ejecucion(403);}
                $mensaje=$service->accionCliente((int)$_SESSION['id'],$_POST);
            }
            $datos=$service->cliente((int)$_SESSION['id']);$ajustes=$service->ajustes();
        } catch(\InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();$datos=$service->cliente((int)$_SESSION['id']);$ajustes=$service->ajustes();}
        catch(\Repositories\PersistenceException $e){http_response_code(500);$error='No se pudo completar la operación.';$datos=[];$ajustes=[];}
        $router->render('bienestar/index',compact('datos','ajustes','mensaje','error'));
    }
    private static function csv(array $filas): void
    {
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="segmento-consentido.csv"');header('Cache-Control: no-store');
        $out=fopen('php://output','w');fputcsv($out,['ID','Nombre','Apellido','Correo','Consentimiento']);
        foreach($filas as $fila){fputcsv($out,array_map(static function($v){$s=(string)$v;return preg_match('/^[\s]*[=+@\-]/u',$s)?"'".$s:$s;},array_values($fila)));}fclose($out);
    }
}
