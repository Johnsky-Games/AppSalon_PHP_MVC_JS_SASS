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
    if (is_null($html)) {
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
 * Emite de forma consistente las cabeceras HTTP de seguridad en respuestas web y API.
 *
 * @return array<string, string>
 */
function emitir_cabeceras_seguridad(): array
{
    $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
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
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';

    return str_contains($accept, 'application/json') ||
           str_contains($contentType, 'application/json') ||
           str_starts_with($uri, '/api/');
}

/**
 * Detiene la ejecución si el usuario no está autenticado.
 */
function isAuth(): void
{
    iniciar_sesion_segura();
    if (($_SESSION['login'] ?? false) === true) {
        $contexto = (new \Services\AuthorizationService())->contexto((int)($_SESSION['id'] ?? 0));
        if (!$contexto || (int)$contexto['activo'] !== 1 || (int)$contexto['confirmado'] !== 1 ||
            (isset($_SESSION['version_sesion']) && (int)$contexto['version_sesion'] !== (int)$_SESSION['version_sesion'])) {
            $_SESSION = [];
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
            header('Location: /');
            detener_ejecucion(302);
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
            header('Location: /');
            detener_ejecucion(302);
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
            echo "Error 403: Solicitud no autorizada (CSRF inválido o ausente).";
        }
        detener_ejecucion(403);
    }
}

/** Revalida acceso usando roles del servidor; las sesiones heredadas no elevan privilegios. */
function puede(string $permiso): bool
{
    iniciar_sesion_segura();
    if (($_SESSION['login'] ?? false) !== true) { return false; }
    $service = new \Services\AuthorizationService();
    $c = $service->contexto((int)($_SESSION['id'] ?? 0));
    if (!$c || (int)$c['activo'] !== 1 || (int)$c['confirmado'] !== 1) { return false; }
    if (isset($_SESSION['version_sesion']) && (int)$c['version_sesion'] !== (int)$_SESSION['version_sesion']) { return false; }
    return in_array($permiso, \Services\AuthorizationService::ROLES[$c['rol']] ?? [], true);
}

function exigir_permiso(string $permiso): void
{
    isAuth();
    if (puede($permiso)) { return; }
    http_response_code(403);
    if (es_peticion_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['resultado' => false, 'error' => 'No tienes permiso para esta operación']);
    } else { echo 'Error 403: No tienes permiso para esta operación.'; }
    detener_ejecucion(403);
}

function rol_actual(): string
{
    $c = (new \Services\AuthorizationService())->contexto((int)($_SESSION['id'] ?? 0));
    return $c && (int)$c['activo'] === 1 ? (string)$c['rol'] : 'cliente';
}

function profesional_actual_id(): ?int
{
    $c = (new \Services\AuthorizationService())->contexto((int)($_SESSION['id'] ?? 0));
    return $c && (int)$c['activo'] === 1 && $c['profesional_id'] !== null ? (int)$c['profesional_id'] : null;
}
