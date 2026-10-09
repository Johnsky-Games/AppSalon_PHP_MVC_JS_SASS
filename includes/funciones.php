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

/**
 * Detiene la ejecución en entornos web/CLI productivos, pero permite pruebas unitarias.
 */
function detener_ejecucion(): void
{
    if (!defined('PHPUNIT_RUNNING')) {
        exit;
    }
}

/**
 * Inicia la sesión con directivas de cookies seguras si aún no está activa.
 */
function iniciar_sesion_segura(): void
{
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
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
        if (es_peticion_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['resultado' => false, 'error' => 'No autenticado']);
        } else {
            header('Location: /');
        }
        detener_ejecucion();
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
    if (!isset($_SESSION['admin']) || (string)$_SESSION['admin'] !== "1") {
        if (es_peticion_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['resultado' => false, 'error' => 'Acceso denegado']);
        } else {
            header('Location: /');
        }
        detener_ejecucion();
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
        detener_ejecucion();
    }
}