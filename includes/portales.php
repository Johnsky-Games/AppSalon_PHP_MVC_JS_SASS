<?php
/**
 * Guardias de portal (ronda 5): una persona puede tener membresía de cliente y laboral, pero la SESIÓN pertenece a un solo portal
 * y ese portal decide qué pantallas y acciones existen. Nunca se amplía un permiso para ocultar un error: se redirige o se niega.
 *
 *  · pantallas/API exclusivas del cliente  → una sesión del equipo va a su inicio con aviso (HTML) o recibe 403 JSON;
 *  · pantallas del equipo                  → una sesión de cliente recibe 403 con «Volver» a su portal;
 *  · cambiar de portal exige iniciar sesión en el otro (las sesiones no se mezclan).
 */

/** Aviso de una sola vez que el layout muestra en la siguiente pantalla (p. ej. tras redirigir por portal incorrecto). */
function aviso_portal(string $mensaje): void
{
    $_SESSION['aviso_portal'] = $mensaje;
}

/** Consume el aviso pendiente ('' si no hay). */
function consumir_aviso_portal(): string
{
    $aviso = $_SESSION['aviso_portal'] ?? '';
    unset($_SESSION['aviso_portal']);
    return is_string($aviso) ? $aviso : '';
}

/**
 * Mensaje de «acceso denegado» coherente con el portal de la sesión: una sesión de cliente que abre algo del equipo recibe la
 * explicación del portal, no un genérico de permisos (que sugeriría pedir un permiso en lugar de entrar por el acceso del equipo).
 */
function mensaje_acceso_denegado(string $generico): string
{
    if (($_SESSION['login'] ?? false) === true && portal_sesion() === 'cliente') {
        return 'Esta sección pertenece al portal del equipo del salón y tu sesión es la del portal de clientes. Para usarla, cierra sesión e ingresa por el acceso del equipo con una cuenta autorizada.';
    }
    return $generico;
}

/** Opciones `volver` de ErrorResponse para un acceso denegado: a una sesión de cliente se le ofrece volver a su portal. */
function opciones_volver_portal(): array
{
    if (($_SESSION['login'] ?? false) === true && portal_sesion() === 'cliente') {
        return ['volver' => '/cita', 'volver_etiqueta' => 'Volver a reservar'];
    }
    return [];
}

/**
 * Exige que la sesión pertenezca a `$portal`. Una sesión abierta debe seguir vigente (isAuth: login del portal correspondiente y
 * 401 JSON); sin sesión no hace nada y responde la autenticación propia de la acción.
 *
 *  · `cliente` pedido con sesión del equipo: HTML → redirige al inicio del equipo con aviso; JSON/API → 403 con `codigo: portal_incorrecto`.
 *  · `equipo` pedido con sesión de cliente: 403 (HTML con «Volver» al portal del cliente; JSON).
 */
function exigir_portal(string $portal): void
{
    iniciar_sesion_segura();
    // Sin sesión no hay portal que comparar: responde la autenticación propia de la acción (login del portal o 401 JSON de su contrato).
    if (($_SESSION['login'] ?? false) !== true) { return; }
    isAuth();
    if (portal_sesion() === $portal) { return; }
    $inicio = inicio_portal();
    if ($portal === 'cliente') {
        $mensaje = 'Esa pantalla es del portal de clientes. Estás trabajando en el portal del equipo; para reservar o ver tus citas personales, cierra sesión e ingresa por el acceso de clientes.';
        if (es_peticion_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['resultado' => false, 'status' => 'forbidden', 'codigo' => 'portal_incorrecto', 'error' => $mensaje, 'destino' => $inicio], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            detener_ejecucion(403);
        }
        aviso_portal($mensaje);
        redirigir($inicio, strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET' ? 302 : 303);
    }
    $mensaje = mensaje_acceso_denegado('Esta sección no está disponible en tu portal.');
    if (es_peticion_json()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['resultado' => false, 'status' => 'forbidden', 'codigo' => 'portal_incorrecto', 'error' => $mensaje, 'destino' => $inicio], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        detener_ejecucion(403);
    }
    \Classes\ErrorResponse::render(403, ['mensaje' => $mensaje, 'volver' => $inicio, 'volver_etiqueta' => 'Volver a mi portal']);
    detener_ejecucion(403);
}

/**
 * Tabla declarativa de pantallas y API cuyo portal NO lo decide un permiso: personales del cliente (reserva, sus citas, su bienestar).
 * Las pantallas del equipo no figuran aquí porque ya exigen un permiso del servidor (que una sesión de cliente nunca tiene).
 *
 * @return array<string,array{GET:string[],POST:string[]}>
 */
function guardias_portal(): array
{
    return [
        'cliente' => [
            'GET' => ['/cita', '/mis-citas', '/mi-bienestar', '/api/mis-citas', '/api/disponibilidad', '/api/profesionales'],
            'POST' => ['/mi-bienestar', '/api/citas'],
        ],
    ];
}

/**
 * Envuelve las rutas ya registradas con la guardia de su portal sin tocar los controladores: la guardia se ejecuta ANTES que la
 * acción. Las rutas no registradas se ignoran (el módulo puede no estar desplegado).
 *
 * @param \MVC\Router $router
 */
function proteger_rutas_por_portal($router, ?array $guardias = null): void
{
    foreach (($guardias ?? guardias_portal()) as $portal => $metodos) {
        foreach ($metodos as $metodo => $rutas) {
            foreach ($rutas as $ruta) {
                $original = $metodo === 'GET' ? ($router->getRoutes[$ruta] ?? null) : ($router->postRoutes[$ruta] ?? null);
                if ($original === null) { continue; }
                $envuelta = static function ($r) use ($original, $portal) {
                    exigir_portal($portal);
                    return call_user_func($original, $r);
                };
                if ($metodo === 'GET') { $router->get($ruta, $envuelta); } else { $router->post($ruta, $envuelta); }
            }
        }
    }
}
