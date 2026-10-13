<?php

namespace MVC;

use Classes\ErrorResponse;

class Router
{
    public array $getRoutes = [];
    public array $postRoutes = [];

    public function get($url, $fn)
    {
        $this->getRoutes[$url] = $fn;
    }

    public function post($url, $fn)
    {
        $this->postRoutes[$url] = $fn;
    }

    public function comprobarRutas()
    {
        if (function_exists('emitir_cabeceras_seguridad')) {
            emitir_cabeceras_seguridad();
        }

        $currentUrl = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'GET' || $method === 'HEAD') {
            $fn = $this->getRoutes[$currentUrl] ?? null;
        } elseif ($method === 'POST') {
            $fn = $this->postRoutes[$currentUrl] ?? null;
        } else {
            $fn = null;
        }

        if (!$fn) {
            $allowed = [];
            if (isset($this->getRoutes[$currentUrl])) {
                $allowed = ['GET', 'HEAD'];
            }
            if (isset($this->postRoutes[$currentUrl])) {
                $allowed[] = 'POST';
            }
            ErrorResponse::render($allowed ? 405 : 404, $allowed ? ['allow' => $allowed] : []);
            return;
        }

        // Un fallo no debe mezclar HTML parcial con una respuesta JSON o de error.
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            call_user_func($fn, $this);
            $output = $this->collectOutput($bufferLevel);
            if ($method !== 'HEAD') {
                echo $output;
            }
        } catch (\AppTerminationException $exception) {
            $output = $this->collectOutput($bufferLevel);
            $status = http_response_code();
            $status = is_int($status) ? $status : $exception->getStatusCode();
            if ($status >= 400 && trim($output) === '') {
                ErrorResponse::render($status);
            } elseif ($method !== 'HEAD') {
                echo $output;
            }
            // Mantiene el contrato de terminación para el controlador principal y pruebas.
            throw $exception;
        } catch (\Throwable $exception) {
            $this->collectOutput($bufferLevel);
            ErrorResponse::fromThrowable($exception);
        }
    }

    private function collectOutput(int $bufferLevel): string
    {
        $output = '';
        while (ob_get_level() > $bufferLevel) {
            $output = ob_get_clean() . $output;
        }
        return $output;
    }

    public function render($view, $datos = [])
    {
        if (function_exists('emitir_cabeceras_seguridad')) {
            emitir_cabeceras_seguridad();
        }

        if (!is_string($view) || !preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~', $view) ||
            !is_file(__DIR__ . "/views/$view.php")) {
            throw new \RuntimeException('Vista no disponible.');
        }
        extract($datos, EXTR_SKIP);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            include __DIR__ . "/views/$view.php";
            $contenido = ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
        include __DIR__ . '/views/layout.php';
    }
}
