<?php

namespace Controllers;

use Classes\RateLimiter;
use Model\ActiveRecord;
use Model\Usuario;
use MVC\Router;
use Repositories\UsuarioRepository;
use Services\AuthService;

/**
 * Controlador HTTP para autenticación, registro, confirmación y recuperación de contraseña.
 * Gestiona exclusivamente transporte HTTP, sesiones, CSRF, vistas, mensajes y redirecciones,
 * delegando las reglas de negocio en AuthService y la persistencia en UsuarioRepository.
 */
class LoginController
{
    private static ?AuthService $authService = null;

    /**
     * Permite inyectar una instancia de AuthService (o restablecerla con null).
     */
    public static function setAuthService(?AuthService $service): void
    {
        self::$authService = $service;
    }

    /**
     * Obtiene la instancia inyectada de AuthService o construye una por defecto
     * con la conexión activa de base de datos.
     */
    private static function obtenerAuthService(): AuthService
    {
        if (self::$authService !== null) {
            return self::$authService;
        }

        return new AuthService(new UsuarioRepository(ActiveRecord::getDB()));
    }

    /**
     * Autenticación de usuarios con regeneración de sesión, reconstrucción estricta de roles
     * y Rate Limiting atómico.
     */
    public static function login(Router $router)
    {
        self::autenticar($router, 'cliente');
    }

    /** Acceso del portal de clientes (/cliente/login). `/` conserva el mismo comportamiento por compatibilidad. */
    public static function loginCliente(Router $router)
    {
        self::autenticar($router, 'cliente');
    }

    /** Acceso del portal del equipo (/equipo/login): solo cuentas con colaborador activo y rol laboral. */
    public static function loginEquipo(Router $router)
    {
        self::autenticar($router, 'equipo');
    }

    private static function autenticar(Router $router, string $portal)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        $alertas = [];
        $auth = new Usuario();
        if (!empty($_SESSION['aviso_sesion_expirada'])) {
            $alertas['error'][] = 'Tu sesión terminó o cambió tu acceso. Inicia sesión de nuevo para continuar.';
            unset($_SESSION['aviso_sesion_expirada']);
        }

        if ($portal === 'equipo' && ($_GET['invitacion'] ?? '') === 'aceptada' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $alertas['exito'][] = 'Invitación aceptada. Ingresa con tu correo y tu contraseña para entrar al equipo.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $ip = RateLimiter::obtenerIP();
            $resultado = self::obtenerAuthService()->login($_POST, $ip);

            if ($resultado['usuario'] instanceof Usuario) {
                $auth = $resultado['usuario'];
            }
            $alertas = $resultado['alertas'];

            if ($resultado['status'] === AuthService::STATUS_OK && $resultado['usuario'] instanceof Usuario) {
                $usuario = $resultado['usuario'];

                // El contexto se resuelve ANTES de abrir sesión. Si la autorización no está disponible
                // (conexión, consulta, esquema sin migrar) se propaga ServiceUnavailableException → 503;
                // jamás se presenta como "cuenta inactiva". Sólo un estado explícito suspende el acceso.
                $autorizacion = new \Services\AuthorizationService();
                $acceso = $autorizacion->contexto((int)$usuario->id);
                if ($acceso === null) {
                    http_response_code(401);
                    $alertas = ['error' => ['No pudimos validar tu cuenta. Inténtalo de nuevo.']];
                    $router->render('auth/login', ['alertas' => $alertas, 'auth' => $auth]);
                    return;
                }
                $esEquipo = $acceso['rol'] !== 'cliente';
                $clienteSuspendido = $acceso['cliente_estado'] === 'suspendido';
                if ($portal === 'cliente') {
                    if ($esEquipo && $acceso['cliente_estado'] === null) {
                        // Cuenta solo laboral: no entra como cliente ni conserva privilegios; se la orienta a su portal.
                        http_response_code(422);
                        $alertas = ['error' => ['Esta cuenta pertenece al equipo del salón. Ingresa desde el acceso del equipo.']];
                        $router->render('auth/login', ['alertas' => $alertas, 'auth' => $auth, 'enlaceEquipo' => true]);
                        return;
                    }
                    if ($clienteSuspendido || (!$esEquipo && (int)$acceso['activo'] !== 1)) {
                        $_SESSION = [];
                        \Classes\ErrorResponse::render(403, ['mensaje' => 'Tu cuenta de cliente está suspendida. Escribe o llama al salón para que revisen tu caso.']);
                        detener_ejecucion(403);
                    }
                    if (!$esEquipo && $acceso['cliente_estado'] === null) {
                        // Cuenta anterior a la membresía explícita: se materializa activa, nunca reactiva una suspendida.
                        $autorizacion->asegurarMembresiaCliente((int)$usuario->id);
                    }
                } else {
                    if (!$esEquipo) {
                        http_response_code(403);
                        $pendiente = ($acceso['colaborador_estado'] ?? null) === 'invitado';
                        $alertas = ['error' => [$pendiente
                            ? 'Tu invitación al equipo sigue pendiente. Acéptala desde el enlace que recibiste por correo; hasta entonces no tienes acceso laboral.'
                            : 'Esta cuenta no tiene acceso al equipo. Si eres cliente, ingresa desde el acceso de clientes.']];
                        $router->render('auth/login-equipo', ['alertas' => $alertas, 'auth' => $auth]);
                        return;
                    }
                    if ((int)$acceso['activo'] !== 1) {
                        $_SESSION = [];
                        \Classes\ErrorResponse::render(403, ['mensaje' => 'Tu acceso al equipo está suspendido. Consulta con la administración del salón.']);
                        detener_ejecucion(403);
                    }
                }

                // Reconstruir sesión: limpiar datos y roles previos antes de poblar
                $_SESSION = [];
                session_regenerate_id(true);

                $_SESSION['id'] = $usuario->id;
                $_SESSION['nombre'] = $usuario->nombre;
                $_SESSION['apellido'] = $usuario->apellido;
                $_SESSION['email'] = $usuario->email;
                $_SESSION['login'] = true;
                $_SESSION['version_sesion'] = (int)$acceso['version_sesion'];
                $_SESSION['portal'] = $portal;
                // El rol de la sesión es el del portal: en el portal de clientes nunca se heredan permisos laborales.
                $_SESSION['rol'] = $portal === 'equipo' ? $acceso['rol'] : 'cliente';

                if ($portal === 'equipo') {
                    if (in_array($acceso['rol'], ['propietario','gerente'], true)) { $_SESSION['admin'] = '1'; }
                    else { unset($_SESSION['admin']); }
                    redirigir($acceso['rol'] === 'profesional' ? '/agenda' : ($acceso['rol'] === 'finanzas' ? '/admin/finanzas' : '/admin'));
                } else {
                    unset($_SESSION['admin']);
                    redirigir('/cita');
                }
                return;
            } elseif ($resultado['status'] === AuthService::STATUS_INVALID_TYPE) {
                http_response_code(422);
            } elseif ($resultado['status'] === AuthService::STATUS_RATE_LIMITED) {
                http_response_code(429);
                $espera = max(1, (int)($resultado['segundos_restantes'] ?? RateLimiter::DURACION_BLOQUEO));
                header("Retry-After: {$espera}");
            } elseif ($resultado['status'] === AuthService::STATUS_ERROR) {
                http_response_code(500);
            }
        }

        $router->render($portal === 'equipo' ? 'auth/login-equipo' : 'auth/login', [
            'alertas' => $alertas,
            'auth' => $auth
        ]);
    }

    /**
     * Cierre efectivo de sesión exigiendo método POST y token CSRF.
     */
    public static function logout()
    {
        iniciar_sesion_segura();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /');
            detener_ejecucion(302);
            return;
        }

        exigir_csrf();

        $destino = ($_SESSION['portal'] ?? '') === 'equipo' ? '/equipo/login' : '/';
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        redirigir($destino);
    }

    /**
     * Solicitud de restablecimiento de contraseña con tokens criptográficos y rate limiting atómico.
     */
    public static function olvide(Router $router)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $ip = RateLimiter::obtenerIP();
            $resultado = self::obtenerAuthService()->solicitarRecuperacion($_POST, $ip);
            $alertas = $resultado['alertas'];

            if ($resultado['status'] === AuthService::STATUS_INVALID_TYPE) {
                http_response_code(422);
            } elseif ($resultado['status'] === AuthService::STATUS_RATE_LIMITED) {
                http_response_code(429);
                $espera = max(1, (int)($resultado['segundos_restantes'] ?? RateLimiter::DURACION_BLOQUEO));
                header("Retry-After: {$espera}");
            } elseif ($resultado['status'] === AuthService::STATUS_ERROR) {
                http_response_code(500);
            }
        }

        $router->render('auth/olvide-password', [
            'alertas' => $alertas
        ]);
    }

    /**
     * Reenvío seguro de confirmación para cuentas no confirmadas con rate limiting y respuesta genérica.
     */
    public static function reenviarConfirmacion(Router $router)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $ip = RateLimiter::obtenerIP();
            $resultado = self::obtenerAuthService()->reenviarConfirmacion($_POST, $ip);
            $alertas = $resultado['alertas'];

            if ($resultado['status'] === AuthService::STATUS_INVALID_TYPE) {
                http_response_code(422);
            } elseif ($resultado['status'] === AuthService::STATUS_RATE_LIMITED) {
                http_response_code(429);
                $espera = max(1, (int)($resultado['segundos_restantes'] ?? RateLimiter::DURACION_BLOQUEO));
                header("Retry-After: {$espera}");
            } elseif ($resultado['status'] === AuthService::STATUS_ERROR) {
                http_response_code(500);
            }
        }

        $router->render('auth/reenviar-confirmacion', [
            'alertas' => $alertas
        ]);
    }

    /**
     * Alias de compatibilidad para reenviarConfirmacion().
     */
    public static function reenviar(Router $router)
    {
        self::reenviarConfirmacion($router);
    }

    /**
     * Recuperación de contraseña consumiendo token atómicamente por hash, tipo y vencimiento.
     */
    public static function recuperar(Router $router)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        $alertas = [];
        $error = false;

        $token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
        $validacionToken = self::obtenerAuthService()->validarTokenRecuperacion($token);
        $error = (bool)$validacionToken['error'];
        $alertas = $validacionToken['alertas'];

        if ($validacionToken['status'] === AuthService::STATUS_ERROR) {
            http_response_code(500);
        } elseif ($error) {
            http_response_code(410);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $resultadoReset = self::obtenerAuthService()->restablecerPassword($token, $_POST);
            $alertas = $resultadoReset['alertas'];

            if ($resultadoReset['status'] === AuthService::STATUS_OK) {
                redirigir(($_GET['portal'] ?? '') === 'equipo' ? '/equipo/login' : '/');
                return;
            } elseif ($resultadoReset['status'] === AuthService::STATUS_INVALID_TYPE) {
                http_response_code(422);
            } elseif ($resultadoReset['status'] === AuthService::STATUS_ERROR) {
                http_response_code(500);
                $error = true;
            } elseif (!empty($resultadoReset['error'])) {
                $error = true;
                http_response_code(410);
            } else {
                http_response_code(422);
            }
        }

        $router->render('auth/recuperar-password', [
            'alertas' => $alertas,
            'error' => $error
        ]);
    }

    /**
     * Creación de nueva cuenta con whitelist estricta (anti-mass assignment).
     */
    public static function crear(Router $router)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        $usuario = new Usuario();
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $clave = clave_operacion_enviada();
            if ($clave === null) {
                // El formulario siempre incluye su clave de operación; sin ella no se admite el alta.
                $resultado = ['status' => AuthService::STATUS_INVALID, 'usuario' => new Usuario(),
                    'alertas' => ['error' => ['El formulario venció. Recárgalo para crear tu cuenta.']]];
            } else {
                $resultado = self::obtenerAuthService()->registrar($_POST, RateLimiter::obtenerIP(), $clave, \Classes\Idempotencia::actorAnonimo());
            }
            if ($resultado['usuario'] instanceof Usuario) {
                $usuario = $resultado['usuario'];
            }
            $alertas = $resultado['alertas'];

            if ($resultado['status'] === AuthService::STATUS_OK) {
                // Respuesta pública UNIFORME (DEF-13): idéntica para correo nuevo, cliente confirmado o sin confirmar,
                // personal laboral y cuentas suspendidas. No se añade ningún dato de la cuenta ni se distingue `repetida`.
                redirigir('/mensaje');
                return;
            } elseif ($resultado['status'] === AuthService::STATUS_RATE_LIMITED) {
                http_response_code(429);
                header('Retry-After: ' . max(1,(int)($resultado['segundos_restantes'] ?? 900)));
            } elseif ($resultado['status'] === AuthService::STATUS_CONFLICT_CLAVE) {
                http_response_code(409);
            } elseif ($resultado['status'] === AuthService::STATUS_EN_CURSO) {
                http_response_code(409);
                header('Retry-After: ' . max(1,(int)($resultado['segundos_restantes'] ?? 3)));
            } elseif ($resultado['status'] === AuthService::STATUS_ERROR) {
                http_response_code(500);
            } else { http_response_code(422); }
        }

        $router->render('auth/crear-cuenta', [
            'usuario' => $usuario,
            'alertas' => $alertas
        ]);
    }

    public static function mensaje(Router $router)
    {
        $router->render('auth/mensaje', []);
    }

    /** Cabeceras del enlace de activación: la página contiene el token, no debe cachearse ni filtrarse por Referer. */
    private static function cabecerasActivacion(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store');
        }
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
            header('Content-Type: text/html; charset=utf-8');
        }
    }

    /**
     * `GET`/`HEAD /confirmar-cuenta?token=…`: SOLO VALIDAN el enlace. Nunca activan ni consumen el token (los escáneres de
     * enlaces, vistas previas y reintentos del navegador hacen GET). GET muestra el formulario de activación; HEAD
     * devuelve únicamente el estado (200 válido, 410 inválido/vencido/usado, 500 fallo técnico) sin cuerpo.
     */
    public static function confirmar(Router $router)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        self::cabecerasActivacion();

        $token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
        $validacion = self::obtenerAuthService()->validarTokenConfirmacion($token);
        $estado = $validacion['status'];
        http_response_code($estado === AuthService::STATUS_ERROR ? 500 : ($estado === AuthService::STATUS_OK ? 200 : 410));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        // Se precargan nombre, apellido y teléfono con lo que ya hay en la cuenta: los escribió quien registró el correo ANTES de
        // verificarlo y el titular debe poder revisarlos y corregirlos. Riesgo residual documentado: un escáner de enlaces del
        // proveedor de correo que haga GET verá esos datos; son los del propio registro y van al mismo buzón (HEAD no los muestra).
        $datos = [];
        if ($estado === AuthService::STATUS_OK && $validacion['usuario'] instanceof Usuario) {
            $u = $validacion['usuario'];
            $datos = ['nombre' => self::limpiarParaMostrar((string)$u->nombre), 'apellido' => self::limpiarParaMostrar((string)$u->apellido), 'telefono' => self::limpiarParaMostrar((string)$u->telefono)];
        }
        self::renderActivacion($router, $estado === AuthService::STATUS_OK ? 'formulario' : ($estado === AuthService::STATUS_ERROR ? 'error' : 'invalido'),
            $validacion['alertas'], $token, $datos);
    }

    /**
     * `POST /confirmar-cuenta`: ACTIVA la cuenta. CSRF obligatorio, token en el cuerpo (nunca en la query), contraseña
     * definitiva elegida por quien abrió el enlace (password + password2), clave de operación del formulario (doble clic),
     * límite de intentos por IP y por huella del token. No inicia sesión; regenera el identificador de sesión del navegador.
     */
    public static function activar(Router $router)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        self::cabecerasActivacion();
        exigir_csrf();

        $clave = clave_operacion_enviada();
        $token = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        if ($clave === null) {
            // Sin clave de operación no se admite la activación (el formulario siempre la incluye); no se toca nada.
            $resultado = ['status' => AuthService::STATUS_INVALID, 'enlace' => true,
                'alertas' => ['error' => ['El formulario venció. Abre de nuevo el enlace de tu correo.']]];
        } else {
            $resultado = self::obtenerAuthService()->activarCuenta(
                ['token' => $_POST['token'] ?? null, 'password' => $_POST['password'] ?? null, 'password2' => $_POST['password2'] ?? null,
                    'nombre' => $_POST['nombre'] ?? null, 'apellido' => $_POST['apellido'] ?? null, 'telefono' => $_POST['telefono'] ?? null],
                RateLimiter::obtenerIP(),
                $clave,
                // El actor se liga al enlace (no a la sesión): la sesión se regenera al activar y un reintento no debe perder la idempotencia.
                'activacion:' . substr(hash('sha256', $token), 0, 32)
            );
        }

        switch ($resultado['status']) {
            case AuthService::STATUS_OK:
                // Sin auto-login: nuevo identificador de sesión para este navegador y a iniciar sesión con la contraseña elegida.
                session_regenerate_id(true);
                http_response_code(200);
                self::renderActivacion($router, 'exito', $resultado['alertas'], '');
                return;
            case AuthService::STATUS_RATE_LIMITED:
                http_response_code(429);
                header('Retry-After: ' . max(1, (int)($resultado['segundos_restantes'] ?? RateLimiter::DURACION_BLOQUEO)));
                break;
            case AuthService::STATUS_CONFLICT_CLAVE:
                http_response_code(409);
                break;
            case AuthService::STATUS_EN_CURSO:
                http_response_code(409);
                header('Retry-After: ' . max(1, (int)($resultado['segundos_restantes'] ?? 3)));
                break;
            case AuthService::STATUS_ERROR:
                http_response_code(500);
                self::renderActivacion($router, 'error', $resultado['alertas'], '');
                return;
            default:
                // Contraseña inválida o formulario vencido: el enlace sigue vigente, se vuelve a mostrar el formulario (422).
                // Enlace inválido/vencido/usado: pantalla genérica (410) sin distinguir la causa.
                if (($resultado['enlace'] ?? true) === false || $token === '') {
                    http_response_code(410);
                    self::renderActivacion($router, 'invalido', $resultado['alertas'], '');
                    return;
                }
                http_response_code(422);
        }
        // Se conserva lo escrito en los datos de contacto (nunca la contraseña) y solo si son texto.
        $escrito = [];
        foreach (['nombre', 'apellido', 'telefono'] as $campo) {
            $escrito[$campo] = is_string($_POST[$campo] ?? null) ? self::limpiarParaMostrar($_POST[$campo]) : '';
        }
        self::renderActivacion($router, 'formulario', $resultado['alertas'], $token, $escrito);
    }

    /** Quita bytes de control y UTF-8 inválido antes de volver a escribir un dato en el formulario (higiene de salida, QA R4-1/R4-12b). */
    private static function limpiarParaMostrar(string $valor): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub($valor, 'UTF-8')) ?? '';
    }

    private static function renderActivacion(Router $router, string $modo, array $alertas, string $token, array $datos = []): void
    {
        // El Router vuelve a emitir las cabeceras de seguridad al renderizar: la política se fija solo durante este render.
        $GLOBALS['__appsalon_referrer_policy'] = 'no-referrer';
        try {
            $router->render('auth/confirmar-cuenta', ['modo' => $modo, 'alertas' => $alertas, 'token' => $token, 'datos' => $datos]);
        } finally {
            unset($GLOBALS['__appsalon_referrer_policy']);
        }
    }
}
