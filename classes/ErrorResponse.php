<?php

namespace Classes;

/**
 * Respuestas de error coherentes para navegador y API.
 * No consulta la base de datos: también funciona cuando falla el arranque.
 */
final class ErrorResponse
{
    public static function wantsJson(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return true;
        }

        $jsonQuality = 0.0;
        $htmlQuality = 0.0;
        foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''))) as $entry) {
            $parts = array_map('trim', explode(';', $entry));
            $type = array_shift($parts);
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)$/', $parameter, $match)) {
                    $quality = (float)$match[1];
                }
            }
            if ($type === 'application/json' || preg_match('~^application/[a-z0-9.+-]+\+json$~', $type)) {
                $jsonQuality = max($jsonQuality, $quality);
            }
            if ($type === 'text/html' || $type === 'application/xhtml+xml') {
                $htmlQuality = max($htmlQuality, $quality);
            }
        }

        // El formato del cuerpo enviado no determina el formato de la respuesta.
        return $jsonQuality > 0 && $jsonQuality >= $htmlQuality;
    }

    public static function render(int $status, array $options = []): void
    {
        if (function_exists('iniciar_sesion_segura') && session_status() === PHP_SESSION_NONE &&
            isset($_COOKIE[session_name()]) && !headers_sent()) {
            iniciar_sesion_segura();
        }
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        $details = self::details($status);
        // Algunos endpoints legados aceptan formularios HTML y solicitudes JSON.
        // Sólo el controlador puede seleccionar HTML tras comprobar ese contrato.
        $json = ($options['format'] ?? null) === 'html' ? false : self::wantsJson();
        if (function_exists('emitir_cabeceras_seguridad')) {
            emitir_cabeceras_seguridad();
        }
        http_response_code($status);
        if (!headers_sent()) {
            header_remove('Location');
            header_remove('Content-Length');
            header_remove('Content-Disposition');
            header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
            header('Cache-Control: no-store');
            header('Vary: Accept', false);
            if (isset($options['allow'])) {
                header('Allow: ' . implode(', ', $options['allow']));
            }
            if (isset($options['retry_after'])) {
                header('Retry-After: ' . max(1, (int)$options['retry_after']));
            }
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            return;
        }
        if ($json) {
            echo json_encode($options['json'] ?? [
                'resultado' => false,
                'error' => $options['mensaje'] ?? $details['title'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        $errorStatus = $status;
        $errorDetails = $details;
        $errorDescription = (string)($options['mensaje'] ?? $details['description']);
        $errorReference = (string)($options['reference'] ?? '');
        $errorCompany = self::company();
        $errorHome = $status === 401 || empty($_SESSION['login']) ? '/' : match ($_SESSION['rol'] ?? '') {
            'propietario', 'gerente', 'recepcion' => '/admin',
            'finanzas' => '/admin/finanzas',
            'profesional' => '/agenda',
            'cliente' => '/cita',
            default => '/perfil',
        };
        $errorEscape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        include __DIR__ . '/../views/errores/layout.php';
    }

    public static function fromThrowable(\Throwable $exception): void
    {
        $reference = substr(hash('sha256', uniqid('appsalon', true)), 0, 10);
        // Nunca registrar argumentos, consultas, tokens ni el mensaje de la excepción.
        error_log(sprintf(
            '[AppSalon][%s] %s code=%d at %s:%d',
            $reference,
            get_class($exception),
            (int)$exception->getCode(),
            basename($exception->getFile()),
            $exception->getLine()
        ));
        $status = $exception instanceof ServiceUnavailableException ? 503 : 500;
        self::render($status, ['reference' => $reference] + ($status === 503 ? ['retry_after' => 60] : []));
    }

    /** Información configurada y comprobada; no inventa contactos. */
    private static function company(): array
    {
        $read = static function (string $key): string {
            $value = $_ENV[$key] ?? getenv($key);
            return is_string($value) ? trim($value) : '';
        };
        $cached = is_array($_SESSION['ui_empresa'] ?? null) ? $_SESSION['ui_empresa'] : [];
        return [
            'nombre' => trim((string)($cached['nombre_comercial'] ?? '')) ?: ($read('APP_COMPANY_NAME') ?: 'App Salón'),
            'contacto' => trim((string)($cached['contacto'] ?? '')) ?: $read('APP_COMPANY_CONTACT'),
        ];
    }

    private static function details(int $status): array
    {
        return match ($status) {
            400 => ['title' => 'Revisemos esta solicitud', 'description' => 'No pudimos interpretar la información enviada. Vuelve a abrir la página e inténtalo de nuevo.', 'hint' => 'Comprueba los datos antes de continuar.', 'icon' => 'document'],
            401 => ['title' => 'Tu sesión necesita un nuevo inicio', 'description' => 'Inicia sesión para continuar y acceder a tu espacio personal.', 'hint' => 'Por tu seguridad, el acceso requiere una sesión activa.', 'icon' => 'lock'],
            403 => ['title' => 'Este acceso está protegido', 'description' => 'Tu cuenta no tiene acceso a esta sección o la solicitud necesita volver a validarse.', 'hint' => 'Si necesitas acceso, consulta con la administración del salón.', 'icon' => 'lock'],
            404 => ['title' => 'No encontramos esta página', 'description' => 'El enlace puede haber cambiado o la página ya no está disponible. Te ayudamos a volver a tu espacio.', 'hint' => 'Revisa el enlace o continúa desde el inicio.', 'icon' => 'search'],
            405 => ['title' => 'Esta acción no está disponible', 'description' => 'Esta página no admite la forma en que se envió la solicitud. Vuelve al inicio y usa las opciones de la aplicación.', 'hint' => 'Puedes seguir navegando con normalidad.', 'icon' => 'document'],
            409 => ['title' => 'Algo cambió mientras continuabas', 'description' => 'La información se actualizó antes de completar la acción. Abre la página de nuevo y revisa su estado actual.', 'hint' => 'Comprueba el resultado antes de repetir una operación.', 'icon' => 'clock'],
            410 => ['title' => 'Este enlace ya no está disponible', 'description' => 'El enlace venció o ya fue utilizado. Solicita uno nuevo para continuar de forma segura.', 'hint' => 'Usa siempre el enlace más reciente de tu correo.', 'icon' => 'clock'],
            419 => ['title' => 'Es momento de actualizar tu sesión', 'description' => 'La validación de esta página venció. Vuelve a abrirla para continuar con una sesión actualizada.', 'hint' => 'La acción no se ha confirmado.', 'icon' => 'clock'],
            422 => ['title' => 'Hay información por revisar', 'description' => 'Algunos datos no permiten completar la solicitud. Revisa la información y vuelve a enviarla.', 'hint' => 'Comprueba los campos indicados en el formulario.', 'icon' => 'document'],
            429 => ['title' => 'Tomemos una breve pausa', 'description' => 'Se recibieron varios intentos en poco tiempo. Espera unos minutos antes de volver a intentarlo.', 'hint' => 'Esta pausa ayuda a proteger tu cuenta.', 'icon' => 'clock'],
            503 => ['title' => 'Volvemos en un momento', 'description' => 'El servicio está temporalmente ocupado o en mantenimiento. Intenta acceder de nuevo en unos minutos.', 'hint' => 'Gracias por tu paciencia mientras restablecemos el servicio.', 'icon' => 'clock'],
            default => ['title' => 'No pudimos completar esta acción', 'description' => 'Ocurrió un inconveniente al procesar la solicitud. Vuelve a intentarlo en unos minutos.', 'hint' => 'Si estabas reservando o pagando, revisa el estado antes de repetir la acción.', 'icon' => 'sparkles'],
        };
    }
}
