<?php
namespace Controllers;
use MVC\Router;
use Repositories\OperacionRepository;
use Services\FinanzasService;
use Services\ComisionService;
final class FinanzasController
{
    public static function index(Router $router): void
    {
        iniciar_sesion_segura(); exigir_permiso('finanzas.gestionar');
        $hoy=(new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $inicio=is_string($_GET['inicio'] ?? null) ? $_GET['inicio'] : $hoy;
        $fin=is_string($_GET['fin'] ?? null) ? $_GET['fin'] : $hoy;
        foreach ([$inicio,$fin] as $fecha) {
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
            if (!$d || $d->format('Y-m-d')!==$fecha) { http_response_code(422); $inicio=$fin=$hoy; break; }
        }
        if ($inicio>$fin) { http_response_code(422); $inicio=$fin=$hoy; }
        $pagina=max(1,min(10000,(int)($_GET['pagina'] ?? 1)));
        $datos=(new OperacionRepository())->caja($inicio,$fin,$pagina);
        $router->render('finanzas/index',array_merge($datos,compact('inicio','fin','pagina'),['alertas'=>$_SESSION['finanzas_alertas'] ?? [],'comisiones'=>(new ComisionService())->panel()]));
        unset($_SESSION['finanzas_alertas']);
    }
    public static function guardar(Router $router): void
    {
        iniciar_sesion_segura(); exigir_permiso('finanzas.gestionar');
        exigir_csrf();
        try {
            $service=new FinanzasService();
            if (($_POST['accion'] ?? '')==='configurar_comision') { (new ComisionService())->configurar((int)$_SESSION['id'],$_POST); }
            elseif (($_POST['accion'] ?? '')==='liquidar_comision') { (new ComisionService())->liquidar((int)$_SESSION['id'],$_POST); }
            elseif (($_POST['accion'] ?? '')==='reembolsar') { $service->reembolsar((int)$_SESSION['id'],$_POST); }
            else { $service->registrar((int)$_SESSION['id'],$_POST); }
            $_SESSION['finanzas_alertas']=['exito'=>['Movimiento registrado. Conserve el comprobante de recepción.']];
        } catch (\InvalidArgumentException $e) { $_SESSION['finanzas_alertas']=['error'=>[$e->getMessage()]]; }
        catch (\Throwable $e) { error_log('[Finanzas] '.$e->getMessage()); $_SESSION['finanzas_alertas']=['error'=>['No se pudo registrar. No se ha confirmado ningún movimiento.']]; }
        header('Location: /admin/finanzas',true,303); detener_ejecucion(303);
    }
}
