<?php
namespace Controllers;

use Model\ActiveRecord;
use MVC\Router;
use Repositories\ProfileRepository;
use Services\ProfileService;

final class ProfileController
{
    private static function service(): ProfileService { return new ProfileService(new ProfileRepository(ActiveRecord::getDB())); }
    private static function id(bool $public = false): int
    {
        if (!$public) { isAuth(); }
        $raw = $_GET['id'] ?? $_SESSION['id'] ?? null;
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id) { http_response_code(404); detener_ejecucion(404); }
        if (!$public && $id !== (int)($_SESSION['id'] ?? 0)) { exigir_permiso('perfiles.gestionar'); }
        return $id;
    }
    public static function index(Router $router): void
    {
        iniciar_sesion_segura();
        $id = self::id(); $service = self::service(); $alertas = [];
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                exigir_csrf();
                $service->guardar($id,$_POST,$id === (int)$_SESSION['id']);
                if ($id === (int)$_SESSION['id']) {
                    $_SESSION['nombre'] = trim($_POST['nombre']); $_SESSION['apellido'] = trim($_POST['apellido']);
                }
                $alertas['exito'][] = 'Perfil y preferencias actualizados.';
            }
            $perfil = $service->obtener($id);
            if (!$perfil) { http_response_code(404); detener_ejecucion(404); }
        } catch (\InvalidArgumentException $e) { http_response_code(422); $alertas['error'][] = $e->getMessage(); $perfil = $service->obtener($id); }
        catch (\AppTerminationException $e) { throw $e; }
        catch (\Throwable $e) { http_response_code(500); echo 'No fue posible cargar el perfil.'; return; }
        $router->render('perfil/index',['perfil'=>$perfil,'alertas'=>$alertas]);
    }
    public static function guardarFoto(Router $router): void
    {
        iniciar_sesion_segura(); $id = self::id(); exigir_csrf();
        try {
            if (($_POST['accion'] ?? '') === 'eliminar') { self::service()->eliminarFoto($id); }
            else { self::service()->subirFoto($id,$_FILES['foto'] ?? []); }
            header('Location: /perfil?id=' . $id . '&foto=actualizada'); detener_ejecucion(302);
        } catch (\AppTerminationException $e) { throw $e; }
        catch (\InvalidArgumentException $e) { http_response_code(422); echo s($e->getMessage()); }
        catch (\Throwable $e) { http_response_code(500); echo 'No fue posible guardar la foto.'; }
    }
    public static function foto(Router $router): void
    {
        iniciar_sesion_segura(); $id = self::id(true); $service = self::service();
        try {
            $perfil = $service->obtener($id);
            $viewer = ($_SESSION['login'] ?? false) === true ? (int)($_SESSION['id'] ?? 0) : null;
            if ($viewer !== null) { isAuth(); }
            $admin = $viewer !== null && puede('perfiles.gestionar');
            $path = $perfil && $service->fotoVisible($perfil,$viewer,$admin) ? $service->rutaFoto($perfil) : null;
            if (!$path) { http_response_code(404); return; }
            header('Content-Type: image/jpeg'); header('Content-Disposition: inline; filename="perfil.jpg"');
            header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($path)); readfile($path);
        } catch (\AppTerminationException $e) { throw $e; }
        catch (\Throwable $e) { http_response_code(500); }
    }
}
