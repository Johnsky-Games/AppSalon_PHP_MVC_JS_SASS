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
        [$errorHome, $errorHomeEtiqueta] = self::inicio($status);
        [$errorVolver, $errorVolverEtiqueta] = self::volver($options);
        $errorEscape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Un error de cliente (4xx) de una persona con sesión se pinta dentro del layout de su portal (menú incluido); los 5xx y
        // cualquier fallo del layout usan la página independiente, que no depende de la base de datos.
        if (self::enLayout($status, $options) && self::incluirEnLayout(get_defined_vars())) {
            return;
        }
        include __DIR__ . '/../views/errores/layout.php';
    }

    /**
     * Enlace principal de «inicio» y su etiqueta, calculados por el PORTAL de la sesión (inicio_portal()), no por el rol que el
     * navegador guarda: sin sesión o con 401 es el acceso del portal; con sesión, el inicio de su rol dentro de su portal.
     *
     * @return array{0:string,1:string}
     */
    private static function inicio(int $status): array
    {
        $logueado = ($_SESSION['login'] ?? false) === true;
        if ($status === 401 || !$logueado) {
            $esEquipo = ($_SESSION['portal'] ?? '') === 'equipo' || ($_SESSION['portal_aviso'] ?? '') === '/equipo/login';
            $acceso = $esEquipo ? '/equipo/login' : '/';
            return [$acceso, $status === 401 ? 'Iniciar sesión' : 'Volver al inicio'];
        }
        $porRol = static fn (): string => match ($_SESSION['rol'] ?? '') {
            'propietario', 'gerente', 'recepcion' => '/admin',
            'finanzas' => '/admin/finanzas',
            'profesional' => '/agenda',
            'cliente' => '/cita',
            default => '/perfil',
        };
        // Sesiones heredadas o de pruebas, sin marca de portal ni identificador: solo queda el rol guardado (el destino vuelve a autorizar).
        if (!isset($_SESSION['portal']) && (int)($_SESSION['id'] ?? 0) <= 0 || !function_exists('inicio_portal')) {
            return [$porRol(), 'Volver al inicio'];
        }
        try {
            return [inicio_portal(), 'Volver al inicio'];
        } catch (\Throwable $e) {
            // La autorización puede no estar disponible (503): el enlace se deduce de la marca de portal de la sesión.
            return [($_SESSION['portal'] ?? '') === 'equipo' ? $porRol() : '/cita', 'Volver al inicio'];
        }
    }

    /**
     * Botón «Volver» opcional (opciones `volver` y `volver_etiqueta`). La ruta se valida otra vez contra el catálogo de destinos,
     * el portal y los permisos de la sesión: una URL externa, una ruta de otro portal o inventada se descarta y queda solo «inicio».
     *
     * @return array{0:string,1:string}
     */
    private static function volver(array $options): array
    {
        $ruta = $options['volver'] ?? null;
        if (!is_string($ruta) || $ruta === '' || !function_exists('destino_validar_ruta')) { return ['', 'Volver']; }
        try {
            $valida = destino_validar_ruta($ruta);
        } catch (\Throwable $e) {
            $valida = '';
        }
        if ($valida === '') { return ['', 'Volver']; }
        $etiqueta = $options['volver_etiqueta'] ?? null;
        $etiqueta = is_string($etiqueta) ? trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', mb_scrub($etiqueta, 'UTF-8')) ?? '') : '';
        return [$valida, $etiqueta !== '' ? mb_substr($etiqueta, 0, 80) : 'Volver'];
    }

    private static function enLayout(int $status, array $options): bool
    {
        return ($_SESSION['login'] ?? false) === true && $status >= 400 && $status < 500 && $status !== 401
            && ($options['standalone'] ?? false) !== true && is_file(__DIR__ . '/../views/layout.php');
    }

    /** Pinta la tarjeta de error dentro del layout del portal; devuelve false (sin salida parcial) si el layout no puede renderizarse. */
    private static function incluirEnLayout(array $variables): bool
    {
        $nivel = ob_get_level();
        try {
            $variables['uiNoIndex'] = true; // una página de error nunca se indexa (igual que views/errores/layout.php)
            extract($variables, EXTR_SKIP);
            ob_start();
            echo '<div class="nav-equipo-error">';
            include __DIR__ . '/../views/errores/tarjeta.php';
            echo '</div>';
            $contenido = ob_get_clean();
            ob_start();
            include __DIR__ . '/../views/layout.php';
            ob_end_flush();
            return true;
        } catch (\Throwable $e) {
            while (ob_get_level() > $nivel) { ob_end_clean(); }
            return false;
        }
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
