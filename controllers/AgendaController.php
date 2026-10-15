<?php
namespace Controllers;
use MVC\Router;
use Repositories\CitaRepository;
use Services\AgendaAtencionService;
use Services\CitaService;

class AgendaController
{
    /** Permisos del SERVIDOR para la sesión actual (la vista nunca decide por su cuenta). */
    private static function permisos(): array
    {
        return ['gestiona' => puede('agenda.gestionar'), 'propia' => puede('agenda.ver_propia'), 'finanzas' => puede('finanzas.gestionar')];
    }

    /** Mensaje de resultado de la última acción (se consume una sola vez; sobrevive a la redirección). */
    private static function tomarAviso(): array
    {
        $aviso = $_SESSION['agenda_aviso'] ?? null;
        unset($_SESSION['agenda_aviso']);
        return is_array($aviso) && isset($aviso['tipo'], $aviso['texto']) ? [(string)$aviso['tipo'] => [(string)$aviso['texto']]] : [];
    }

    public static function index(Router $router): void
    {
        iniciar_sesion_segura(); isAuth();
        $permisos = self::permisos();
        $gestiona = $permisos['gestiona'];
        if (!$gestiona) exigir_permiso('agenda.ver_propia');
        $service = new CitaService();
        $atencion = new AgendaAtencionService();
        $desde = is_string($_GET['desde'] ?? null) ? $_GET['desde'] : $service->obtenerAhora()->format('Y-m-d');
        $hasta = is_string($_GET['hasta'] ?? null) ? $_GET['hasta'] : $desde;
        $estado = AgendaAtencionService::estadoFiltro($_GET['estado'] ?? null);
        $citas = []; $alertas = self::tomarAviso();
        if (!$service->obtenerDiaSemanaIso($desde) || !$service->obtenerDiaSemanaIso($hasta) || $hasta < $desde || strtotime($hasta) - strtotime($desde) > 31 * 86400) {
            http_response_code(422); $alertas['error'][] = 'Consulta un período válido de hasta 31 días.';
        } else {
            try { $citas = $atencion->listar($desde, $hasta, (int)$_SESSION['id'], $permisos, $estado); }
            catch (\Throwable $e) { http_response_code(500); $alertas['error'][] = 'Agenda no disponible. Intenta nuevamente.'; }
        }
        $ahora = $atencion->ahora();
        $router->render('agenda/index', compact('citas', 'desde', 'hasta', 'gestiona', 'alertas', 'estado', 'permisos', 'ahora') + ['script' => '<script src="/build/js/agenda-atencion.js"></script>']);
    }

    /** Detalle de una cita para el equipo. Una cita ajena a la agenda del profesional es indistinguible de una inexistente (404). */
    public static function detalle(Router $router): void
    {
        iniciar_sesion_segura(); isAuth();
        $permisos = self::permisos();
        if (!$permisos['gestiona']) exigir_permiso('agenda.ver_propia');
        $id = is_string($_GET['id'] ?? null) && preg_match('/^[1-9]\d{0,9}$/D', $_GET['id']) ? (int)$_GET['id'] : 0;
        $cita = null;
        if ($id > 0) {
            try { $cita = (new AgendaAtencionService())->detalle($id, (int)$_SESSION['id'], $permisos); }
            catch (\Throwable $e) { error_log('[Agenda] Detalle no disponible: ' . $e->getMessage()); responder_error_operacion(500, 'No se pudo cargar la cita en este momento. Intenta nuevamente.', 'agenda'); detener_ejecucion(500); return; }
        }
        if (!$cita) {
            responder_error_operacion(404, 'No encontramos esa cita en tu agenda.', 'agenda');
            detener_ejecucion(404);
            return;
        }
        $desde = is_string($_GET['desde'] ?? null) ? $_GET['desde'] : null;
        $hasta = is_string($_GET['hasta'] ?? null) ? $_GET['hasta'] : null;
        $alertas = self::tomarAviso();
        $ahora = (new AgendaAtencionService())->ahora();
        $router->render('agenda/detalle', compact('cita', 'desde', 'hasta', 'alertas', 'permisos', 'ahora') + ['script' => '<script src="/build/js/agenda-atencion.js"></script>']);
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
        // Un `volver` que no sea texto (p. ej. volver[]=x) se descarta: el retorno solo se reconstruye desde el catálogo central.
        if (isset($_POST['volver']) && !is_string($_POST['volver'])) { unset($_POST['volver']); }
        $gestiona = puede('agenda.gestionar');
        $equipo = $gestiona || puede('agenda.ver_propia');
        $portal = $equipo ? 'agenda' : 'cliente_citas';
        $resultado = (new CitaService())->actualizarAgenda($_POST['id'] ?? null, $_SESSION['id'], $gestiona, puede('agenda.ver_propia'), $operacion, $_POST);
        if (!empty($resultado['resultado'])) {
            $_SESSION['agenda_aviso'] = ['tipo' => 'exito', 'texto' => (string)($resultado['mensaje'] ?? 'Cita actualizada correctamente.')];
            // «Finalizar y cobrar»: la cita se finaliza con la transición normal y luego se continúa a caja. El cobro es independiente
            // (si falla, la cita queda atendida con saldo pendiente y se reintenta desde caja o desde el detalle).
            if ($operacion === 'estado' && ($_POST['cobrar'] ?? '') === '1' && ($resultado['estado'] ?? '') === 'completada' && $gestiona && puede('finanzas.gestionar')) {
                $cobro = destino_portal('caja_cita', ['id' => (int)$_POST['id'], 'desde' => $_POST['r_desde'] ?? null, 'hasta' => $_POST['r_hasta'] ?? null]);
                if ($cobro !== '') { redirigir($cobro); return; }
            }
            redirigir(destino_volver($portal)); return;
        }
        $codigo = (int)$resultado['httpCode'];
        responder_error_operacion($codigo, 'No se pudo actualizar la cita. ' . (string)$resultado['error'], $portal);
        detener_ejecucion($codigo);
    }
}
