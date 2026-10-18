<?php
namespace Controllers;

use Model\ActiveRecord;
use MVC\Router;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\AuditoriaHuella;
use Services\AuthorizationService;
use Services\ColaboradorService;
use Services\CorreoSalidaService;
use Services\ReasignacionCitasService;

/**
 * Gestión del equipo (portal del equipo): invitaciones formales, estados laborales, roles y ficha laboral.
 * Cada mutación exige permiso en el servidor, CSRF y, en las altas, la clave de operación del formulario.
 */
class EquipoController
{
    private static function repo(): ColaboradorRepository { return new ColaboradorRepository(ActiveRecord::getDB()); }
    private static function servicio(): ColaboradorService
    {
        $db = ActiveRecord::getDB();
        return new ColaboradorService(new ColaboradorRepository($db), new CorreoSalidaService(new CorreoSalidaRepository($db)));
    }

    public static function recursosHumanos(Router $router): void
    {
        exigir_permiso('rh.gestionar');
        if (!headers_sent()) { header('Cache-Control: no-store'); }
        try { $datos = (new \Services\RecursosHumanosService(self::repo()))->consultar($_GET); }
        catch (\Repositories\PersistenceException $e) { throw new \Classes\ServiceUnavailableException('El directorio de personal no está disponible.', 0, $e); }
        $alertas = $_SESSION['equipo_alertas'] ?? []; unset($_SESSION['equipo_alertas']);
        if ($datos['errores']) { http_response_code(422); $alertas['error'] = $datos['errores']; }
        $router->render('equipo/recursos-humanos', $datos + ['alertas' => $alertas,
            'puedeAccesos' => puede('equipo.gestionar'), 'puedeProfesionales' => puede('profesionales.gestionar')]);
    }

    public static function fichaRh(Router $router): void { self::ficha($router, true); }
    public static function fichaAgendaRh(Router $router): void { self::fichaAgenda($router, true); }

    public static function index(Router $router): void
    {
        exigir_permiso('equipo.gestionar');
        $db = ActiveRecord::getDB();
        // Una lectura nunca envía correos (un SMTP lento no debe colgar la pantalla): los reintentos los hace
        // scripts/procesar_correos.php (incluido en el worker de notificaciones) o el botón «Reenviar invitación».
        $repo = self::repo();
        $alertas = $_SESSION['equipo_alertas'] ?? [];
        unset($_SESSION['equipo_alertas']);
        $servicios = (new ServicioRepository($db))->findAll();
        $fichas = $repo->profesionalesSinCuenta();
        $previo = $_SESSION['equipo_previo'] ?? [];
        if (!$previo && ($_GET['rol'] ?? '') === 'profesional') { $previo['rol'] = 'profesional'; }
        $fichaSeleccionada = filter_var($_GET['profesional_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$previo || !isset($previo['profesional_id'])) {
            if ($fichaSeleccionada && in_array($fichaSeleccionada, array_map('intval', array_column($fichas, 'id')), true)) {
                $previo['rol'] = 'profesional'; $previo['profesional_id'] = (string)$fichaSeleccionada;
            }
        }

        $router->render('equipo/index', [
            'colaboradores' => $repo->listar(),
            'fichasSinCuenta' => $fichas,
            'servicios' => is_array($servicios) ? $servicios : [],
            'roles' => ColaboradorRepository::ROLES_INVITABLES,
            'rolesAcceso' => array_values(array_filter(array_keys(AuthorizationService::ROLES), static fn($r) => $r !== 'cliente')),
            'alertas' => $alertas,
            'puedeRh' => puede('rh.gestionar'),
            'puedeAgenda' => puede('agenda.gestionar'),
            'puedeProfesionales' => puede('profesionales.gestionar'),
            'previo' => $previo,
            'homonimos' => $_SESSION['equipo_homonimos'] ?? null,
        ]);
        unset($_SESSION['equipo_previo'], $_SESSION['equipo_homonimos']);
    }

    public static function crear(): void { self::mutar('crear'); }
    public static function actualizar(): void { self::mutar('actualizar'); }
    public static function invitar(): void { self::mutar('reenviar'); }
    public static function revocar(): void { self::mutar('revocar'); }

    public static function vincular(): void
    {
        exigir_permiso('equipo.gestionar'); exigir_csrf();
        try {
            $c=filter_var($_POST['colaborador_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $p=filter_var($_POST['profesional_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$c || !$p) throw new \DomainException('Selecciona la persona y su ficha profesional.');
            self::repo()->vincularProfesionalExistente((int)$_SESSION['id'],$c,$p);
            $_SESSION['equipo_alertas']=['exito'=>['Ficha vinculada. Conserva servicios, horarios, citas e historial.']];
        } catch (\DomainException $e) { $_SESSION['equipo_alertas']=['error'=>[$e->getMessage()]]; }
        header('Location: /admin/recursos-humanos',true,303); detener_ejecucion(303);
    }

    private static function mutar(string $accion): void
    {
        exigir_permiso('equipo.gestionar');
        exigir_csrf();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); detener_ejecucion(405); }
        $actor = (int)$_SESSION['id'];
        $servicio = self::servicio();
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $clave = clave_operacion_enviada();
        $r = ['status' => ColaboradorService::INVALID, 'errores' => ['Datos no válidos.']];
        $mensaje = '';
        switch ($accion) {
            case 'crear':
                $r = $servicio->invitar($actor, $_POST, $clave, AuditoriaHuella::ip(AuditoriaHuella::ipDeSolicitud()));
                if ($r['status'] === ColaboradorService::OK) {
                    $mensaje = match ($r['correo'] ?? 'pendiente') {
                        'enviado' => 'Invitación enviada. La persona no tendrá acceso al equipo hasta que la acepte.',
                        'fallido' => 'La invitación quedó guardada, pero el correo no pudo enviarse. Pulsa «Reenviar invitación» o activa el worker de correos (scripts/procesar_correos.php).',
                        default => 'Invitación guardada. El correo está en cola: si no llega, pulsa «Reenviar invitación» o activa el worker de correos. Repetir esta acción no crea otra invitación.',
                    };
                    if (!empty($r['repetida'])) { $mensaje = 'Esta invitación ya estaba registrada; se muestra el resultado original.'; }
                    if (!empty($r['profesional_id'])) { $mensaje .= ' Configura sus horarios para habilitar reservas.'; }
                } elseif ($r['status'] === ColaboradorService::CANCELADO) {
                    // No se creó nada: el operador decidió usar la ficha existente.
                    $_SESSION['equipo_alertas'] = ['exito' => $r['errores']];
                    redirigir('/admin/equipo');
                    return;
                } else {
                    $_SESSION['equipo_previo'] = self::previoSeguro(array_diff_key($_POST, ['decision' => 1, 'token_confirmacion' => 1]));
                    if (!empty($r['homonimos'])) {
                        // El aviso se vuelve a mostrar con LA MISMA clave de operación: el token está ligado a ella.
                        $_SESSION['equipo_homonimos'] = ['coincidencias' => $r['homonimos']['coincidencias'], 'token' => $r['homonimos']['token'], 'clave' => $clave];
                    }
                }
                break;
            case 'reenviar':
                if (!$id) { break; }
                $r = $servicio->reenviar($actor, $id, $clave);
                if ($r['status'] === ColaboradorService::OK) {
                    $mensaje = ($r['correo'] ?? 'pendiente') === 'enviado' ? 'Invitación reenviada. El enlace anterior dejó de funcionar.' : 'Invitación renovada; el correo se enviará en breve.';
                    if (!empty($r['repetida'])) { $mensaje = 'Esta solicitud de reenvío ya se había procesado.'; }
                }
                break;
            case 'revocar':
                if (!$id) { break; }
                $r = $servicio->revocar($actor, $id);
                if ($r['status'] === ColaboradorService::OK) { $mensaje = 'Invitación revocada. El enlace ya no sirve.'; }
                break;
            case 'actualizar':
                if (!$id || !is_string($_POST['rol'] ?? null) || !is_string($_POST['estado'] ?? null)) { break; }
                $r = $servicio->cambiarAcceso($actor, $id, $_POST['rol'], $_POST['estado'], $clave);
                if ($r['status'] === ColaboradorService::OK) {
                    $mensaje = !empty($r['repetida']) ? 'Este cambio de acceso ya se había aplicado.' : 'Acceso actualizado. Las sesiones anteriores de esa persona fueron revocadas.';
                }
                break;
        }
        if ($r['status'] === ColaboradorService::OK) {
            $_SESSION['equipo_alertas'] = ['exito' => [$mensaje]];
        } else {
            $_SESSION['equipo_alertas'] = ['error' => $r['errores'] ?? ['No se pudo completar la operación.']];
        }
        redirigir('/admin/equipo');
    }

    public static function ficha(Router $router, bool $desdeRh = false): void
    {
        exigir_permiso('rh.gestionar');
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $colaborador = $id ? self::repo()->obtener($id) : null;
        if (!$colaborador) { \Classes\ErrorResponse::render(404); detener_ejecucion(404); }
        $alertas = $_SESSION['equipo_alertas'] ?? [];
        unset($_SESSION['equipo_alertas']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            exigir_csrf();
            $r = self::servicio()->actualizarFicha((int)$_SESSION['id'], (int)$id, $_POST);
            if ($r['status'] === ColaboradorService::OK) {
                $_SESSION['equipo_alertas'] = ['exito' => ['Ficha laboral actualizada.']];
                redirigir($desdeRh ? '/admin/recursos-humanos' : '/admin/equipo');
                return;
            }
            $alertas = ['error' => $r['errores'] ?? ['No se pudo guardar la ficha.']];
            http_response_code(422);
        }
        $router->render('equipo/ficha', ['colaborador' => $colaborador, 'alertas' => $alertas, 'desdeRh' => $desdeRh] + self::datosAgenda($colaborador));
    }

    /**
     * Datos del asistente de agenda (servicios, horario y descansos) de la ficha: solo si la persona es profesional,
     * tiene ficha operativa y no está dada de baja. $enviado reemplaza lo guardado cuando el formulario falló.
     */
    private static function datosAgenda(array $colaborador, ?array $enviado = null): array
    {
        $vacio = ['agenda' => null];
        if (($colaborador['rol'] ?? '') !== 'profesional' || empty($colaborador['profesional_id']) || ($colaborador['estado'] ?? '') === 'baja') { return $vacio; }
        $db = ActiveRecord::getDB();
        $repo = new ProfesionalRepository($db);
        $prof = $repo->findById((int)$colaborador['profesional_id']);
        if (!$prof) { return $vacio; }
        $franjas = [];
        foreach ($repo->findHorariosByProfesional((int)$prof->id) as $h) { $franjas[(int)$h->dia_semana][] = ['inicio' => substr((string)$h->hora_inicio, 0, 5), 'fin' => substr((string)$h->hora_fin, 0, 5)]; }
        $descansos = [];
        foreach ($repo->findDescansosByProfesional((int)$prof->id) as $d) { $descansos[(int)$d->dia_semana][] = ['inicio' => substr((string)$d->hora_inicio, 0, 5), 'fin' => substr((string)$d->hora_fin, 0, 5), 'motivo' => (string)($d->motivo ?? '')]; }
        $servicios = (new ServicioRepository($db))->findAll();
        $seleccion = array_map('intval', $prof->servicioIds);
        if ($enviado !== null) {
            $seleccion = array_map('intval', array_filter((array)($enviado['servicios'] ?? []), 'is_scalar'));
            $franjas = is_array($enviado['franjas'] ?? null) ? $enviado['franjas'] : [];
            $descansos = is_array($enviado['descansos'] ?? null) ? $enviado['descansos'] : [];
        }
        return ['agenda' => [
            'profesional' => $prof->nombre, 'activo' => (int)$prof->activo === 1,
            'servicios' => is_array($servicios) ? $servicios : [], 'seleccion' => $seleccion, 'franjas' => $franjas, 'descansos' => $descansos,
            'puedeEditar' => puede('profesionales.gestionar'),
        ]];
    }

    /** POST del asistente de la ficha: guarda servicios y horario del profesional de forma atómica e idempotente. */
    public static function fichaAgenda(Router $router, bool $desdeRh = false): void
    {
        exigir_permiso('rh.gestionar');
        exigir_permiso('profesionales.gestionar');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); detener_ejecucion(405); }
        exigir_csrf();
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $colaborador = $id ? self::repo()->obtener($id) : null;
        if (!$colaborador) { \Classes\ErrorResponse::render(404); detener_ejecucion(404); }
        $r = self::servicio()->configurarAgenda((int)$_SESSION['id'], (int)$id, $_POST, clave_operacion_enviada());
        if ($r['status'] === ColaboradorService::OK) {
            $_SESSION['equipo_alertas'] = ['exito' => [!empty($r['repetida']) ? 'Esta configuración ya se había guardado.' : (
                ($r['estado'] ?? '') === 'invitado'
                    ? 'Servicios y horario guardados. La ficha seguirá sin aparecer en reservas hasta que la persona acepte la invitación.'
                    : 'Servicios y horario del profesional actualizados.')]];
            redirigir(($desdeRh ? '/admin/recursos-humanos/ficha' : '/admin/equipo/ficha') . '?id=' . (int)$id);
            return;
        }
        http_response_code($r['status'] === ColaboradorService::CONFLICT_KEY || $r['status'] === ColaboradorService::IN_PROGRESS ? 409 : ($r['status'] === ColaboradorService::FORBIDDEN ? 403 : 422));
        $router->render('equipo/ficha', ['colaborador' => $colaborador, 'desdeRh' => $desdeRh, 'alertas' => ['error' => $r['errores'] ?? ['No se pudo guardar la agenda.']]] + self::datosAgenda($colaborador, self::previoSeguro($_POST)));
    }

    /** Copia saneada (solo texto, hasta 4 niveles) de lo enviado, para repoblar el formulario tras un error. */
    private static function previoSeguro(array $post): array
    {
        $limpiar = static function (mixed $v, int $nivel) use (&$limpiar): mixed {
            if (is_string($v)) { return mb_substr($v, 0, 200); }
            if (is_array($v) && $nivel < 4) {
                $salida = [];
                foreach (array_slice($v, 0, 60, true) as $k => $x) {
                    $x = $limpiar($x, $nivel + 1);
                    if ($x !== null) { $salida[is_int($k) ? $k : mb_substr((string)$k, 0, 20)] = $x; }
                }
                return $salida;
            }
            return null;
        };
        $salida = [];
        foreach (array_diff_key($post, ['csrf_token' => 1, 'idem_key' => 1]) as $k => $v) {
            $v = $limpiar($v, 0);
            if ($v !== null) { $salida[(string)$k] = $v; }
        }
        return $salida;
    }

    /** Pantalla del asistente de revisión y reasignación de citas futuras de un profesional (previa a su baja o suspensión). */
    public static function reasignar(Router $router): void
    {
        exigir_permiso('agenda.gestionar');
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) { \Classes\ErrorResponse::render(404); detener_ejecucion(404); }
        $r = (new ReasignacionCitasService())->revisar((int)$_SESSION['id'], (int)$id);
        if ($r['status'] === ReasignacionCitasService::NOT_FOUND) { \Classes\ErrorResponse::render(404); detener_ejecucion(404); }
        if ($r['status'] === ReasignacionCitasService::FORBIDDEN) { \Classes\ErrorResponse::render(403); detener_ejecucion(403); }
        $alertas = $_SESSION['equipo_alertas'] ?? [];
        unset($_SESSION['equipo_alertas']);
        if ($r['status'] !== ReasignacionCitasService::OK) {
            http_response_code(500);
            $alertas = ['error' => $r['errores'] ?? ['No se pudo cargar la revisión.']];
            $r = ['profesional' => ['id' => 0, 'nombre' => '', 'colaborador_id' => (int)$id, 'colaborador' => '', 'rol' => '', 'estado' => ''], 'citas' => [], 'resumen' => ['total' => 0, 'con_sustituto' => 0, 'bloqueadas' => 0], 'ver_cliente' => false];
        }
        $router->render('equipo/reasignar', [
            'revision' => $r, 'alertas' => $alertas, 'puedeBaja' => puede('equipo.gestionar'),
            'idemReasignar' => clave_operacion_formulario(), 'idemAcceso' => \Classes\Idempotencia::nuevaClave(),
        ]);
    }

    /** POST: aplica las decisiones (reasignar / cancelar / dejar para revisión manual) sobre las citas pendientes. */
    public static function reasignarAplicar(): void
    {
        exigir_permiso('agenda.gestionar');
        exigir_csrf();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); detener_ejecucion(405); }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $decisiones = $_POST['decision'] ?? [];
        $motivo = $_POST['motivo'] ?? '';
        if (!$id || !is_array($decisiones) || !is_string($motivo)) {
            $_SESSION['equipo_alertas'] = ['error' => ['Datos no válidos.']];
            // Quien gestiona agenda puede no tener equipo.gestionar: se vuelve a su inicio, nunca a una ruta que le deniegue (QA R2B-11).
            redirigir(inicio_portal());
            return;
        }
        $r = (new ReasignacionCitasService())->aplicar((int)$_SESSION['id'], (int)$id, $decisiones, $motivo, clave_operacion_enviada());
        if ($r['status'] === ReasignacionCitasService::OK) {
            $partes = [];
            if (!empty($r['reasignadas'])) { $partes[] = $r['reasignadas'] . ' reasignada(s)'; }
            if (!empty($r['canceladas'])) { $partes[] = $r['canceladas'] . ' cancelada(s)'; }
            if (!empty($r['manuales'])) { $partes[] = $r['manuales'] . ' pendiente(s) de revisión manual'; }
            $texto = (!empty($r['repetida']) && empty($r['canceladas']) ? 'Estas decisiones ya se habían aplicado. ' : '') . 'Resultado: ' . implode(', ', $partes ?: ['sin cambios']) . '. Citas futuras sin resolver: ' . (int)($r['pendientes'] ?? 0) . '.';
            $_SESSION['equipo_alertas'] = ['exito' => [$texto]] + (!empty($r['avisos']) ? ['error' => $r['avisos']] : []);
        } else {
            $_SESSION['equipo_alertas'] = ['error' => $r['errores'] ?? ['No se pudo completar la operación.']];
        }
        redirigir('/admin/equipo/reasignar?id=' . (int)$id);
    }
}
