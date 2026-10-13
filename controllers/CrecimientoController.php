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
                if(($_POST['accion']??'')==='segmento') { self::csv(self::service()->segmento((string)($_POST['segmento']??'')),(string)($_POST['segmento']??'')); return; }
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
    /** Exportación de campaña. Igual que las del CRM: se audita (append-only) ANTES de entregar; si no se puede registrar, no se entrega. */
    private static function csv(array $filas, string $segmento = ''): void
    {
        $cabecera=['ID','Nombre','Apellido','Correo','Consentimiento'];
        $out=fopen('php://temp','r+');fputcsv($out,$cabecera);
        foreach($filas as $fila){fputcsv($out,array_map(static function($v){$s=(string)$v;return preg_match('/^[\s]*[=+@\-]/u',$s)?"'".$s:$s;},array_values($fila)));}
        rewind($out);$contenido=(string)stream_get_contents($out);fclose($out);
        try {
            $c=(new \Services\AuthorizationService())->contexto((int)($_SESSION['id']??0));
            (new \Services\CrmAuditoriaService(new \Repositories\CrmAuditoriaRepository(ActiveRecord::getDB())))->registrar('campana','exitosa',null,['id'=>(int)($_SESSION['id']??0)?:null,'rol'=>$c['rol']??null],['segmento'=>$segmento],
                ['columnas'=>$cabecera,'filas'=>count($filas),'truncado'=>count($filas)>=1000,'contenido'=>$contenido]);
        } catch(\Throwable $e) {
            error_log('[CrecimientoController] exportación NO entregada: falló la auditoría ('.get_class($e).')');
            throw new \Classes\ServiceUnavailableException('No se pudo registrar la auditoría de la exportación; por seguridad el archivo no se entregó.',0,$e);
        }
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="segmento-consentido.csv"');header('Cache-Control: no-store');
        echo $contenido;
    }
}
