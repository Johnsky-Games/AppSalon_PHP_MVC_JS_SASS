<?php
namespace Controllers;
use MVC\Router;
use Repositories\CitaRepository;
use Services\CitaService;

class AgendaController
{
    public static function index(Router $router): void
    {
        iniciar_sesion_segura(); isAuth();
        $gestiona = puede('agenda.gestionar');
        if (!$gestiona) exigir_permiso('agenda.ver_propia');
        $service = new CitaService();
        $desde = is_string($_GET['desde'] ?? null) ? $_GET['desde'] : $service->obtenerAhora()->format('Y-m-d');
        $hasta = is_string($_GET['hasta'] ?? null) ? $_GET['hasta'] : $desde;
        $citas = []; $alertas = [];
        if (!$service->obtenerDiaSemanaIso($desde) || !$service->obtenerDiaSemanaIso($hasta) || $hasta < $desde || strtotime($hasta) - strtotime($desde) > 31 * 86400) {
            http_response_code(422); $alertas['error'][] = 'Consulta un período válido de hasta 31 días.';
        } else {
            try { $citas = (new CitaRepository())->listarAgenda($desde, $hasta, $gestiona ? null : (int)$_SESSION['id']); }
            catch (\Throwable $e) { http_response_code(500); $alertas['error'][] = 'Agenda no disponible. Intenta nuevamente.'; }
        }
        $router->render('agenda/index', compact('citas', 'desde', 'hasta', 'gestiona', 'alertas'));
    }
    public static function historial(Router $router): void
    {
        iniciar_sesion_segura(); isAuth();
        $respuesta = (new CitaService())->consultarCitasCliente($_SESSION['id'], false);
        http_response_code($respuesta['httpCode']);
        $router->render('agenda/historial', ['citas' => $respuesta['citas'] ?? [], 'alertas' => isset($respuesta['error']) ? ['error' => [$respuesta['error']]] : []]);
    }
    public static function estado(): void { self::modificar('estado'); }
    public static function reprogramar(): void { self::modificar('reprogramar'); }
    private static function modificar(string $operacion): void
    {
        iniciar_sesion_segura(); isAuth(); exigir_csrf();
        $resultado = (new CitaService())->actualizarAgenda($_POST['id'] ?? null, $_SESSION['id'], puede('agenda.gestionar'), puede('agenda.ver_propia'), $operacion, $_POST);
        if (!empty($resultado['resultado'])) {
            header('Location: ' . (puede('agenda.gestionar') || puede('agenda.ver_propia') ? '/agenda' : '/mis-citas')); detener_ejecucion(302); return;
        }
        http_response_code($resultado['httpCode']);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>No se pudo actualizar la cita</h1><p>' . s($resultado['error']) . '</p><a href="/mis-citas">Volver a las citas</a>';
        detener_ejecucion($resultado['httpCode']);
    }
}
