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
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();
        $alertas = [];
        $auth = new Usuario();

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

                // Reconstruir sesión: limpiar datos y roles previos antes de poblar
                $_SESSION = [];
                session_regenerate_id(true);

                $_SESSION['id'] = $usuario->id;
                $_SESSION['nombre'] = $usuario->nombre;
                $_SESSION['apellido'] = $usuario->apellido;
                $_SESSION['email'] = $usuario->email;
                $_SESSION['login'] = true;

                if ((string)$usuario->admin === '1') {
                    $_SESSION['admin'] = '1';
                    header('Location: /admin');
                } else {
                    unset($_SESSION['admin']);
                    header('Location: /cita');
                }
                detener_ejecucion(302);
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

        $router->render('auth/login', [
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
        header('Location: /');
        detener_ejecucion(302);
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
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $resultadoReset = self::obtenerAuthService()->restablecerPassword($token, $_POST);
            $alertas = $resultadoReset['alertas'];

            if ($resultadoReset['status'] === AuthService::STATUS_OK) {
                header('Location: /');
                detener_ejecucion(302);
                return;
            } elseif ($resultadoReset['status'] === AuthService::STATUS_INVALID_TYPE) {
                http_response_code(422);
            } elseif ($resultadoReset['status'] === AuthService::STATUS_ERROR) {
                http_response_code(500);
                $error = true;
            } elseif (!empty($resultadoReset['error'])) {
                $error = true;
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

            $resultado = self::obtenerAuthService()->registrar($_POST);
            if ($resultado['usuario'] instanceof Usuario) {
                $usuario = $resultado['usuario'];
            }
            $alertas = $resultado['alertas'];

            if ($resultado['status'] === AuthService::STATUS_OK) {
                header('Location: /mensaje');
                detener_ejecucion(302);
                return;
            } elseif ($resultado['status'] === AuthService::STATUS_ERROR) {
                http_response_code(500);
            }
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

    /**
     * Confirmación de cuenta atómica condicionada por hash, tipo y vencimiento.
     */
    public static function confirmar(Router $router)
    {
        iniciar_sesion_segura();
        Usuario::limpiarAlertas();

        $token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
        $resultado = self::obtenerAuthService()->confirmarCuenta($token);
        $alertas = $resultado['alertas'];

        if ($resultado['status'] === AuthService::STATUS_ERROR) {
            http_response_code(500);
        }

        $router->render('auth/confirmar-cuenta', [
            'alertas' => $alertas
        ]);
    }
}