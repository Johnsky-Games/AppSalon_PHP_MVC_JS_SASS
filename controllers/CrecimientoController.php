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
        iniciar_sesion_segura();
        // Descarga directa sin permiso: 403 y queda anotado el intento (la autorización se comprueba aquí, no en el botón).
        if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='segmento') { isAuth(); }
        if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='segmento' && !puede('marketing.gestionar')) { self::auditarIntento('denegada','sin_permiso',(string)(is_string($_POST['formato']??null)?$_POST['formato']:'csv')); }
        exigir_permiso('marketing.gestionar'); $mensaje=''; $error=''; $respuestas=[];
        try {
            if($_SERVER['REQUEST_METHOD']==='POST') {
                if(!validar_csrf()){http_response_code(403); detener_ejecucion(403);}
                if(($_POST['accion']??'')==='segmento') { self::exportarContactos(); return; }
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
    private static function auditarIntento(string $resultado, string $motivo, string $formato, ?array $filtros = null): void
    {
        try {
            (new \Services\CrmAuditoriaService(new \Repositories\CrmAuditoriaRepository(ActiveRecord::getDB())))->registrar('campana', $resultado, $motivo, \Services\ExportacionAuditoriaService::actorDeSesion(), $filtros, null, $formato);
        } catch(\Throwable $e) { error_log('[CrecimientoController] no se pudo auditar un intento de exportación ('.$resultado.'): '.get_class($e)); }
    }

    /**
     * Exportación de contactos con consentimiento vigente (`POST /admin/crecimiento`, accion=segmento, formato=csv|xlsx).
     * Mismo flujo que el CRM: autorizar → validar (422 + auditoría) → generar → AUDITAR (append-only) → entregar; si no se puede registrar, no se entrega.
     */
    private static function exportarContactos(): void
    {
        $segmento=(string)($_POST['segmento']??'');
        $formato=\Classes\Exportacion\Exportador::formatoDe($_POST['formato']??null);
        if($formato===null) { self::auditarIntento('fallida','formato_invalido','invalido',['segmento'=>$segmento]); throw new \InvalidArgumentException('El formato de exportación no es válido: use csv o xlsx.'); }
        try { $filas=self::service()->segmento($segmento); }
        catch(\InvalidArgumentException $e) { self::auditarIntento('fallida','segmento_invalido',$formato); throw $e; }
        catch(\Throwable $e) { self::auditarIntento('fallida','error_datos',$formato,['segmento'=>$segmento]); throw $e; }
        $columnas=[['clave'=>'id','titulo'=>'ID','tipo'=>'entero'],['clave'=>'nombre','titulo'=>'Nombre'],['clave'=>'apellido','titulo'=>'Apellido'],['clave'=>'email','titulo'=>'Correo'],['clave'=>'consentimiento_en','titulo'=>'Consentimiento','tipo'=>'fechahora']];
        $limite=1000;   // tope del repositorio (LIMIT 1000): si lo alcanza, puede haber más contactos y se avisa
        $resultado=\Classes\Exportacion\Exportador::generar($formato,'segmento-consentido',$columnas,$filas,['nombre'=>count($filas).' contactos'],
            ['hoja'=>'Contactos','truncado_conocido'=>count($filas)>=$limite,'aviso_truncado'=>'AVISO: la exportación admite hasta '.$limite.' contactos y este filtro puede tener más. Afine el segmento o exporte por partes.']);
        try {
            (new \Services\CrmAuditoriaService(new \Repositories\CrmAuditoriaRepository(ActiveRecord::getDB())))->registrar('campana','exitosa',null,\Services\ExportacionAuditoriaService::actorDeSesion(),['segmento'=>$segmento],
                ['columnas'=>$resultado['columnas'],'filas'=>$resultado['filas'],'truncado'=>$resultado['truncado'],'sha256'=>$resultado['sha256'],'bytes'=>$resultado['bytes'],'formato'=>$formato],$formato);
        } catch(\Throwable $e) {
            if(is_resource($resultado['flujo'])) { fclose($resultado['flujo']); }
            error_log('[CrecimientoController] exportación NO entregada: falló la auditoría ('.get_class($e).')');
            throw new \Classes\ServiceUnavailableException('No se pudo registrar la auditoría de la exportación; por seguridad el archivo no se entregó.',0,$e);
        }
        \Classes\Exportacion\Exportador::entregar($resultado);
    }
}
