<?php
namespace Controllers;
use Classes\Email;
use MVC\Router;
use Repositories\EquipoRepository;
use Services\AuthorizationService;

class EquipoController
{
    public static function index(Router $router): void
    {
        exigir_permiso('equipo.gestionar');
        $repo=new EquipoRepository();
        $alertas=$_SESSION['equipo_alertas']??[];unset($_SESSION['equipo_alertas']);
        $router->render('equipo/index',['equipo'=>$repo->listar(),'profesionales'=>$repo->profesionales(),'roles'=>array_keys(AuthorizationService::ROLES),'alertas'=>$alertas]);
    }
    public static function crear(): void { self::guardar('crear'); }
    public static function actualizar(): void { self::guardar('actualizar'); }
    public static function invitar(): void { self::guardar('invitar'); }
    private static function guardar(string $accion): void
    {
        exigir_permiso('equipo.gestionar');exigir_csrf();
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { http_response_code(405);detener_ejecucion(405); }
        try {
            $repo=new EquipoRepository();$actor=(int)$_SESSION['id'];$id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($accion==='crear') { $invite=$repo->crear($actor,$_POST); }
            elseif ($accion==='invitar') { if (!$id) { throw new \InvalidArgumentException('ID no válido'); } $invite=$repo->reenviarInvitacion($actor,$id); }
            else {
                if (!$id || !is_string($_POST['rol']??null) || !in_array($_POST['activo']??null,['0','1'],true)) { throw new \InvalidArgumentException('Datos de acceso no válidos'); }
                $profRaw=$_POST['profesional_id']??'';
                $prof=$profRaw===''?null:filter_var($profRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($prof===false) { throw new \InvalidArgumentException('Profesional no válido'); }
                $repo->actualizar($actor,$id,$_POST['rol'],$_POST['activo']==='1',$prof);
                if ($id===$actor) { $c=(new AuthorizationService())->contexto($id);$_SESSION['version_sesion']=(int)$c['version_sesion'];$_SESSION['rol']=$c['rol']; }
            }
            $message='Acceso actualizado. Las sesiones anteriores fueron revocadas.';
            if (isset($invite)) {
                $sent=(new Email($invite['nombre'],$invite['email'],$invite['token']))->enviarInvitacion();
                $message=$sent?'Invitación enviada: el colaborador establecerá su contraseña desde su correo.':'La cuenta se guardó pero el correo no pudo enviarse. Corrige SMTP y pulsa Reenviar invitación.';
            }
            $_SESSION['equipo_alertas']=['exito'=>[$message]];
        } catch (\InvalidArgumentException|\DomainException $e) { $_SESSION['equipo_alertas']=['error'=>[$e->getMessage()]]; }
        catch (\Throwable $e) { error_log('Equipo: '.$e->getMessage());$_SESSION['equipo_alertas']=['error'=>['No se pudo guardar el acceso. Revisa si el email ya existe.']]; }
        header('Location: /admin/equipo');detener_ejecucion(302);
    }
}
