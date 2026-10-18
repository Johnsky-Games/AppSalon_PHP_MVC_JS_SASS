<?php
namespace Controllers;
use MVC\Router;
use Repositories\CitaRepository;
use Services\AgendaAtencionService;
use Services\AtencionRechazada;
use Services\RegularizacionAtencionService;
use Services\CitaService;

class AgendaController
{
    /** Permisos del SERVIDOR para la sesión actual (la vista nunca decide por su cuenta). */
    private static function permisos(): array
    {
        return ['gestiona' => puede('agenda.gestionar'), 'propia' => puede('agenda.ver_propia'), 'finanzas' => puede('finanzas.gestionar'),
            'regularizar' => puede('agenda.regularizar'), 'solicitar' => puede('agenda.solicitar_regularizacion')];
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
        $entrada = \Classes\PeriodoFiltro::aplicar($_GET);
        $desde = is_string($entrada['desde'] ?? null) ? $entrada['desde'] : $service->obtenerAhora()->format('Y-m-d');
        $hasta = is_string($entrada['hasta'] ?? null) ? $entrada['hasta'] : $desde;
        $estado = AgendaAtencionService::estadoFiltro($_GET['estado'] ?? null);
        $pagina=filter_var($entrada['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $totalCitas=0; $paginas=1;
        $citas = []; $alertas = self::tomarAviso();
        if (!$service->obtenerDiaSemanaIso($desde) || !$service->obtenerDiaSemanaIso($hasta) || $hasta < $desde || strtotime($hasta) - strtotime($desde) > 31 * 86400) {
            http_response_code(422); $alertas['error'][] = 'Consulta un período válido de hasta 31 días.';
        } else {
            try {
                $totalCitas=$atencion->contar($desde,$hasta,(int)$_SESSION['id'],$permisos,$estado);
                $paginas=max(1,(int)ceil($totalCitas/25)); $pagina=min($pagina,$paginas);
                $citas = $atencion->listar($desde, $hasta, (int)$_SESSION['id'], $permisos, $estado,$pagina,25);
            }
            catch (\Throwable $e) { http_response_code(500); $alertas['error'][] = 'Agenda no disponible. Intenta nuevamente.'; }
        }
        $ahora = $atencion->ahora();
        $pendientes = 0;
        try { $pendientes = $atencion->contarPendientesRevision((int)$_SESSION['id'], $permisos); } catch (\Throwable $e) { error_log('[Agenda] Pendientes de revisión no disponibles: ' . $e->getMessage()); }
        $router->render('agenda/index', compact('citas', 'desde', 'hasta', 'gestiona', 'alertas', 'estado', 'permisos', 'ahora', 'pendientes','pagina','paginas','totalCitas') + ['script' => '<script src="/build/js/agenda-atencion.js"></script>']);
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
        $alertas = array_merge_recursive(self::tomarAviso(),$_SESSION['erp_alertas']??[]);unset($_SESSION['erp_alertas']);
        $consumo=(new \Repositories\ErpRepository())->uno('SELECT id,creado_en,motivo FROM erp_consumos WHERE cita_id=?',[$id]);
        $ahora = (new AgendaAtencionService())->ahora();
        $router->render('agenda/detalle', compact('cita', 'desde', 'hasta', 'alertas', 'permisos', 'ahora','consumo') + ['script' => '<script src="/build/js/agenda-atencion.js"></script>']);
    }
    /** Citas antiguas sin resolver («pendientes de revisión»): nunca se marcan solas; aquí se revisan. El profesional ve solo las suyas. */
    public static function pendientes(Router $router): void
    {
        iniciar_sesion_segura(); isAuth();
        $permisos = self::permisos();
        if (!$permisos['gestiona']) exigir_permiso('agenda.ver_propia');
        $citas = []; $alertas = self::tomarAviso();
        $atencion = new AgendaAtencionService();
        try { $citas = $atencion->pendientesRevision((int)$_SESSION['id'], $permisos); }
        catch (\Throwable $e) { http_response_code(500); $alertas['error'][] = 'No se pudieron cargar las citas pendientes. Intenta nuevamente.'; }
        $ahora = $atencion->ahora();
        $router->render('agenda/pendientes', compact('citas', 'alertas', 'permisos', 'ahora') + ['script' => '<script src="/build/js/agenda-atencion.js"></script>']);
    }

    public static function solicitarRegularizacion(): void { self::regularizacion('solicitar'); }
    public static function regularizar(): void { self::regularizacion('regularizar'); }
    public static function rechazarRegularizacion(): void { self::regularizacion('rechazar'); }

    /** Regularización de la atención: la autorización y las reglas viven en el servicio; aquí solo se traducen a la respuesta. */
    private static function regularizacion(string $operacion): void
    {
        iniciar_sesion_segura(); isAuth();
        // El permiso se exige ANTES que el CSRF (como el resto de rutas protegidas) y el servicio lo vuelve a comprobar con el rol vigente.
        exigir_permiso($operacion === 'solicitar' ? 'agenda.solicitar_regularizacion' : 'agenda.regularizar');
        exigir_csrf();
        if (isset($_POST['volver']) && !is_string($_POST['volver'])) { unset($_POST['volver']); }
        $servicio = new RegularizacionAtencionService();
        $actor = (int)$_SESSION['id'];
        try {
            $datos = ['cita_id' => $_POST['id'] ?? null, 'motivo' => $_POST['motivo'] ?? null, 'efectiva' => $_POST['efectiva'] ?? null,
                'idem_key' => $_POST['idem_key'] ?? null, 'regularizacion_id' => $_POST['regularizacion_id'] ?? null];
            if ($operacion === 'solicitar') {
                $servicio->solicitar($actor, $datos);
                $mensaje = 'Solicitud enviada: el propietario o el gerente la revisarán. La cita sigue pendiente de revisión.';
            } elseif ($operacion === 'regularizar') {
                $servicio->regularizar($actor, $datos);
                $mensaje = 'Atención regularizada: la cita quedó como atendida, con su fecha original y la fecha efectiva registrada.';
            } else {
                $servicio->rechazar($actor, $datos);
                $mensaje = 'Solicitud rechazada. La cita sigue pendiente de revisión.';
            }
            $_SESSION['agenda_aviso'] = ['tipo' => 'exito', 'texto' => $mensaje];
            redirigir(destino_volver('agenda')); return;
        } catch (\AppTerminationException $e) {
            throw $e; // la redirección de éxito termina la ejecución con esta excepción: no es un error
        } catch (AtencionRechazada $e) {
            $codigo = $e->http();
            responder_error_operacion($codigo, $e->getMessage(), 'agenda');
            detener_ejecucion($codigo);
        } catch (\Throwable $e) {
            error_log('[Agenda] Regularización no completada: ' . $e->getMessage());
            responder_error_operacion(500, 'No se pudo completar la operación. No se aplicaron cambios.', 'agenda');
            detener_ejecucion(500);
        }
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
