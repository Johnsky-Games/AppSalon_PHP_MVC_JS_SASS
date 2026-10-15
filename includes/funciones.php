<?php

function debuguear($variable): string
{
    echo "<pre>";
    var_dump($variable);
    echo "</pre>";
    detener_ejecucion();
    return '';
}

// Escapa / Sanitizar el HTML
function s($html): string
{
    if (is_null($html) || is_array($html) || is_object($html) && !method_exists($html, '__toString')) {
        return '';
    }
    return htmlspecialchars((string)$html, ENT_QUOTES, 'UTF-8');
}

class AppTerminationException extends \RuntimeException
{
    private int $statusCode;

    public function __construct(string $message = "Ejecución detenida.", int $statusCode = 200, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

/**
 * Detiene la ejecución enviando el control al manejador principal o prueba.
 * Conserva el mismo comportamiento estricto en producción y en pruebas sin atajos condicionales.
 */
function detener_ejecucion(int $codigo = 200): void
{
    throw new AppTerminationException("Ejecución detenida por control de seguridad o redirección.", $codigo);
}

/**
 * Redirige y termina la ejecución. Registra el destino para que las pruebas lo verifiquen
 * (en CLI las cabeceras HTTP no son observables).
 */
function redirigir(string $destino, int $codigo = 302): void
{
    $GLOBALS['__appsalon_redireccion'] = $destino;
    if (!headers_sent()) {
        header('Location: ' . $destino);
    }
    detener_ejecucion($codigo);
}

/**
 * Emite de forma consistente las cabeceras HTTP de seguridad en respuestas web y API.
 *
 * @return array<string, string>
 */
function emitir_cabeceras_seguridad(): array
{
    $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        // Una pantalla puede endurecerla (p. ej. el enlace de activación, que contiene un token) con $GLOBALS['__appsalon_referrer_policy'].
        'Referrer-Policy' => $GLOBALS['__appsalon_referrer_policy'] ?? 'strict-origin-when-cross-origin',
    ];

    $GLOBALS['__appsalon_security_headers'] = $headers;

    if (!headers_sent()) {
        foreach ($headers as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }
    }

    return $headers;
}

/**
 * Inicia la sesión con directivas de cookies seguras si aún no está activa.
 */
function iniciar_sesion_segura(): void
{
    emitir_cabeceras_seguridad();
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }
}

/**
 * Verifica si la petición actual espera respuesta JSON.
 */
function es_peticion_json(): bool
{
    return \Classes\ErrorResponse::wantsJson();
}

/**
 * ¿La versión de la sesión coincide con la de la cuenta? Una sesión SIN versión solo se adopta si la cuenta nunca invalidó
 * sesiones (versión inicial 1, instalaciones anteriores a esta marca). Activar una cuenta, cambiar su contraseña o su rol suben la
 * versión: una sesión sin marca no puede «adoptar» una cuenta que antes estaba sin confirmar.
 */
function version_sesion_vigente(array $contexto): bool
{
    if (!isset($_SESSION['version_sesion'])) {
        return (int)$contexto['version_sesion'] === 1;   // 1 = valor inicial (migración 010): la cuenta nunca invalidó sesiones
    }
    return (int)$contexto['version_sesion'] === (int)$_SESSION['version_sesion'];
}

/**
 * Acceso al que se envía a una persona anónima según la ruta pedida: las pantallas exclusivas del equipo llevan al acceso del
 * equipo; todo lo demás, al del cliente. Solo mira el camino de la petición (nunca un parámetro ni el Referer).
 */
function login_por_ruta(): string
{
    $ruta = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    foreach (['/agenda', '/caja', '/admin', '/profesionales', '/servicios'] as $prefijo) {
        if ($ruta === $prefijo || str_starts_with($ruta, $prefijo . '/')) { return '/equipo/login'; }
    }
    return '/';
}

/**
 * Detiene la ejecución si el usuario no está autenticado.
 */
function isAuth(): void
{
    iniciar_sesion_segura();
    $loginDestino = login_por_ruta();
    if (($_SESSION['login'] ?? false) === true) {
        $contexto = (new \Services\AuthorizationService())->contexto((int)($_SESSION['id'] ?? 0));
        $loginDestino = destino_portal(($_SESSION['portal'] ?? '') === 'equipo' ? 'login_equipo' : 'login_cliente');
        // La membresía del portal debe seguir vigente: una sesión de equipo exige rol laboral activo.
        $portalSesion = $contexto ? portal_sesion($contexto) : 'cliente';
        if (!$contexto || !\Services\AuthorizationService::sesionVigente($contexto, $portalSesion) || (int)$contexto['confirmado'] !== 1 ||
            !version_sesion_vigente($contexto)) {
            $_SESSION = ['aviso_sesion_expirada' => true, 'portal_aviso' => $loginDestino];
        } else {
            // Una sola adopción para cuentas activas tras actualizar una instalación anterior.
            $_SESSION['version_sesion'] = (int)$contexto['version_sesion'];
        }
    }
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
        if (es_peticion_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['resultado' => false, 'error' => 'No autenticado']);
            detener_ejecucion(401);
        } else {
            // La pantalla pedida (solo del catálogo, solo GET) se conserva para después del inicio de sesión del portal que corresponda.
            $intencion = destino_intencion_actual();
            if ($intencion !== null) { $_SESSION['destino_tras_login'] = $intencion; }
            redirigir($_SESSION['portal_aviso'] ?? $loginDestino);
        }
    }
}

function esUltimo(string $actual, string $proximo): bool
{
    return $actual !== $proximo;
}

/**
 * Detiene la ejecución si el usuario no cuenta con rol de Administrador.
 */
function isAdmin(): void
{
    iniciar_sesion_segura();
    isAuth();
    if (!puede('equipo.gestionar') && rol_actual() !== 'gerente') {
        if (es_peticion_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['resultado' => false, 'error' => 'Acceso denegado']);
            detener_ejecucion(403);
        } else {
            \Classes\ErrorResponse::render(403, ['mensaje' => mensaje_acceso_denegado('Tu cuenta no tiene acceso a esta sección. Si lo necesitas, solicita autorización a la administración del salón.')] + opciones_volver_portal());
            detener_ejecucion(403);
        }
    }
}

/**
 * Genera o recupera el token CSRF para la sesión activa.
 */
function csrf_token(): string
{
    iniciar_sesion_segura();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida de forma estricta un token CSRF.
 * Exige que tanto el token de sesión como el recibido sean cadenas no vacías.
 * Rechaza arrays, valores nulos y tipos incorrectos.
 */
function validar_csrf(): bool
{
    iniciar_sesion_segura();

    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $receivedToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!is_string($sessionToken) || $sessionToken === '' ||
        !is_string($receivedToken) || $receivedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $receivedToken);
}

/**
 * Exige validación CSRF y detiene la ejecución si es inválido.
 */
function exigir_csrf(): void
{
    if (!validar_csrf()) {
        http_response_code(403);
        if (es_peticion_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['resultado' => false, 'error' => 'Token CSRF inválido o ausente']);
        } else {
            \Classes\ErrorResponse::render(403, ['mensaje' => 'La validación de esta página venció o no es válida. Vuelve a abrirla antes de enviar la solicitud de nuevo.']);
        }
        detener_ejecucion(403);
    }
}

/**
 * Clave de operación del formulario de alta. Se genera al MOSTRAR el formulario y se conserva si el
 * formulario se vuelve a mostrar con errores; un doble clic o reintento reutiliza la misma clave y por
 * eso el servidor reconoce la misma intención de alta. No sustituye al token CSRF.
 */
function clave_operacion_formulario(): string
{
    $enviada = $_POST['idem_key'] ?? null;
    return \Classes\Idempotencia::claveValida($enviada) ? $enviada : \Classes\Idempotencia::nuevaClave();
}

/** Clave enviada con el formulario o null si falta o es inválida. */
function clave_operacion_enviada(): ?string
{
    $enviada = $_POST['idem_key'] ?? null;
    return \Classes\Idempotencia::claveValida($enviada) ? $enviada : null;
}

/** Revalida acceso usando roles del servidor; las sesiones heredadas no elevan privilegios. */
function puede(string $permiso): bool
{
    iniciar_sesion_segura();
    if (($_SESSION['login'] ?? false) !== true) { return false; }
    $service = new \Services\AuthorizationService();
    $c = $service->contexto((int)($_SESSION['id'] ?? 0));
    if (!$c || (int)$c['confirmado'] !== 1) { return false; }
    if (!version_sesion_vigente($c)) { return false; }
    // Una sesión abierta en el portal de clientes nunca porta privilegios laborales, aunque la misma
    // persona también sea colaboradora: los contextos están separados en sesión y en autorización.
    if (portal_sesion($c) !== 'equipo' || !\Services\AuthorizationService::sesionVigente($c, 'equipo')) { return false; }
    return in_array($permiso, \Services\AuthorizationService::ROLES[$c['rol']] ?? [], true);
}

/**
 * Portal de la sesión: 'cliente' o 'equipo'. Se fija al iniciar sesión en cada portal. Las sesiones
 * anteriores a esta separación (sin marca) se derivan del rol verificado en el servidor, nunca del navegador.
 */
function portal_sesion(?array $contexto = null): string
{
    $marca = $_SESSION['portal'] ?? null;
    if ($marca === 'cliente' || $marca === 'equipo') { return $marca; }
    $c = $contexto ?? (new \Services\AuthorizationService())->contexto((int)($_SESSION['id'] ?? 0));
    return $c && $c['rol'] !== 'cliente' ? 'equipo' : 'cliente';
}

/**
 * Página de inicio según el portal de la sesión (logo, «Volver al inicio» y redirecciones coherentes). Cada rol tiene el suyo: un
 * profesional no puede abrir /admin y finanzas no abre la agenda. Una sesión de equipo cuya membresía laboral ya no está vigente
 * conduce al acceso del equipo (nunca a una pantalla del cliente).
 */
function inicio_portal(): string
{
    if (($_SESSION['login'] ?? false) !== true) { return '/'; }
    if (portal_sesion() === 'equipo') {
        $rol = rol_actual();
        if ($rol === 'cliente') { return '/equipo/login'; }
        return match ($rol) { 'profesional' => '/agenda', 'finanzas' => '/admin/finanzas', default => '/admin' };
    }
    return '/cita';
}

/**
 * HEREDADA (R2 · DEF-R2-03). Destino de retorno a partir del `Referer` del MISMO origen, reducido a ruta+consulta (CWE-601).
 * Ya no la usa ningún controlador: desde la ronda 5 todo retorno sale del catálogo (`destino_volver()` / `campos_retorno()`), que
 * además valida portal y permiso. Se conserva únicamente por compatibilidad de la API pública y de su prueba de regresión
 * (tests/Unit/DestinoRetornoSeguroTest.php); no debe usarse en código nuevo porque el `Referer` lo controla el navegador, puede
 * faltar (políticas de privacidad) y no distingue portales.
 *
 * @deprecated usar destino_volver()
 */
function destino_retorno_seguro(?string $referer = null): string
{
    $referer ??= (string)($_SERVER['HTTP_REFERER'] ?? '');
    $porDefecto = inicio_portal();
    if ($referer === '' || strlen($referer) > 2048 || preg_match('/[\x00-\x1f\x7f\\\\]/', $referer)) { return $porDefecto; }
    $p = parse_url($referer);
    if ($p === false || !isset($p['host'])) { return $porDefecto; }
    $permitidos = [];
    $solicitud = parse_url('//' . (string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!empty($solicitud['host'])) { $permitidos[] = strtolower($solicitud['host']) . ':' . ($solicitud['port'] ?? ''); }
    $app = parse_url((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
    if (!empty($app['host'])) { $permitidos[] = strtolower($app['host']) . ':' . ($app['port'] ?? ''); }
    $origen = strtolower($p['host']) . ':' . ($p['port'] ?? '');
    if (!in_array($origen, $permitidos, true)) { return $porDefecto; }
    $ruta = $p['path'] ?? '/';
    if ($ruta === '' || $ruta[0] !== '/' || str_starts_with($ruta, '//')) { return $porDefecto; }
    return $ruta . (isset($p['query']) ? '?' . $p['query'] : '');
}

function exigir_permiso(string $permiso): void
{
    isAuth();
    if (puede($permiso)) { return; }
    http_response_code(403);
    if (es_peticion_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['resultado' => false, 'error' => 'No tienes permiso para esta operación']);
    } else {
        \Classes\ErrorResponse::render(403, ['mensaje' => mensaje_acceso_denegado('Tu cuenta no tiene permiso para realizar esta operación. Consulta con la administración del salón si necesitas acceso.')] + opciones_volver_portal());
    }
    detener_ejecucion(403);
}

function rol_actual(): string
{
    $c = (new \Services\AuthorizationService())->contexto((int)($_SESSION['id'] ?? 0));
    if (!$c || portal_sesion($c) !== 'equipo' || !\Services\AuthorizationService::sesionVigente($c, 'equipo')) { return 'cliente'; }
    return (string)$c['rol'];
}

function profesional_actual_id(): ?int
{
    $c = (new \Services\AuthorizationService())->contexto((int)($_SESSION['id'] ?? 0));
    return $c && portal_sesion($c) === 'equipo' && \Services\AuthorizationService::sesionVigente($c, 'equipo') && $c['profesional_id'] !== null ? (int)$c['profesional_id'] : null;
}

// Destinos centrales por portal y permiso (ronda 5) y guardias de portal.
require_once __DIR__ . '/destinos.php';
require_once __DIR__ . '/portales.php';
