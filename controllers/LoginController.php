<?php

namespace Controllers;

use MVC\Router;
use Classes\Email;
use Classes\RateLimiter;
use Model\Usuario;
use Model\ActiveRecord;

class LoginController
{
    /**
     * Maneja el inicio de sesión con validación CSRF, Rate Limiting y regeneración de sesión.
     */
    public static function login(Router $router)
    {
        iniciar_sesion_segura();
        $auth = new Usuario;
        $alertas = [];
        $db = ActiveRecord::getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $auth = new Usuario($_POST);
            $alertas = $auth->validarLogin();

            $ip = RateLimiter::obtenerIP();

            // Verificación de Rate Limiting por IP y por Email
            if ($db && (RateLimiter::estaBloqueado($db, RateLimiter::TIPO_IP_LOGIN, $ip) ||
                RateLimiter::estaBloqueado($db, RateLimiter::TIPO_EMAIL_LOGIN, (string)$auth->email))) {
                Usuario::setAlerta('error', 'Demasiados intentos fallidos. Por seguridad, intente de nuevo en 15 minutos.');
                $alertas = Usuario::getAlertas();
            } elseif (empty($alertas)) {
                // Comprobar si el usuario existe mediante consulta preparada
                $usuario = Usuario::where('email', $auth->email);

                if ($usuario && $usuario->comprobarPasswordAndVerificado($auth->password)) {
                    // Limpiar intentos fallidos al autenticar exitosamente
                    if ($db) {
                        RateLimiter::limpiarIntentos($db, RateLimiter::TIPO_IP_LOGIN, $ip);
                        RateLimiter::limpiarIntentos($db, RateLimiter::TIPO_EMAIL_LOGIN, (string)$auth->email);
                    }

                    // Regenerar identificador de sesión para mitigar fijación de sesión
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
                        header('Location: /cita');
                    }
                    exit;
                } else {
                    // Registrar intento fallido en ambos vectores (IP y Email)
                    if ($db) {
                        RateLimiter::registrarIntentoFallido($db, RateLimiter::TIPO_IP_LOGIN, $ip, RateLimiter::MAX_INTENTOS_IP);
                        RateLimiter::registrarIntentoFallido($db, RateLimiter::TIPO_EMAIL_LOGIN, (string)$auth->email, RateLimiter::MAX_INTENTOS_EMAIL);
                    }
                    Usuario::setAlerta('error', 'Credenciales incorrectas o la cuenta no ha sido verificada');
                }
            }
        }

        $alertas = Usuario::getAlertas();

        $router->render('auth/login', [
            'alertas' => $alertas,
            'auth' => $auth,
        ]);
    }

    /**
     * Cierre efectivo de sesión destruyendo la cookie y limpiando el estado.
     */
    public static function logout()
    {
        iniciar_sesion_segura();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        session_destroy();
        header('Location: /');
        exit;
    }

    /**
     * Solicitud de restablecimiento de contraseña con tokens criptográficos con vencimiento.
     */
    public static function olvide(Router $router)
    {
        iniciar_sesion_segura();
        $alertas = [];
        $db = ActiveRecord::getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $auth = new Usuario($_POST);
            $alertas = $auth->validarEmail();

            $ip = RateLimiter::obtenerIP();

            if ($db && (RateLimiter::estaBloqueado($db, RateLimiter::TIPO_IP_RECOVERY, $ip) ||
                RateLimiter::estaBloqueado($db, RateLimiter::TIPO_EMAIL_RECOVERY, (string)$auth->email))) {
                Usuario::setAlerta('error', 'Demasiadas solicitudes de recuperación. Intente en 15 minutos.');
                $alertas = Usuario::getAlertas();
            } elseif (empty($alertas)) {
                if ($db) {
                    RateLimiter::registrarIntentoFallido($db, RateLimiter::TIPO_IP_RECOVERY, $ip, RateLimiter::MAX_INTENTOS_IP);
                    RateLimiter::registrarIntentoFallido($db, RateLimiter::TIPO_EMAIL_RECOVERY, (string)$auth->email, RateLimiter::MAX_INTENTOS_EMAIL);
                }

                $usuario = Usuario::where('email', $auth->email);
                if ($usuario && (string)$usuario->confirmado === '1') {
                    // Token seguro con vencimiento de 2 horas para recuperación
                    $tokenRaw = $usuario->generarTokenSeguro('recuperacion', 2);
                    $usuario->guardar();

                    // Enviar email con el token raw
                    $email = new Email($usuario->nombre, $usuario->email, $tokenRaw);
                    $email->enviarInstrucciones();
                }

                // Mensaje genérico para prevenir enumeración de usuarios
                Usuario::setAlerta('exito', 'Si el correo electrónico está registrado, recibirás las instrucciones para restablecer tu contraseña en breve.');
            }
        }

        $alertas = Usuario::getAlertas();

        $router->render('auth/olvide-password', [
            'alertas' => $alertas
        ]);
    }

    /**
     * Recuperación de contraseña consumiendo token de uso único no expirado.
     */
    public static function recuperar(Router $router)
    {
        iniciar_sesion_segura();
        $alertas = [];
        $error = false;
        $token = trim((string)($_GET['token'] ?? ''));

        $usuario = Usuario::buscarPorTokenSeguro($token, 'recuperacion');
        if (!$usuario) {
            Usuario::setAlerta('error', 'Token no válido o expirado');
            $error = true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            if ($usuario) {
                $password = new Usuario($_POST);
                $alertas = $password->validarPassword();

                if (empty($alertas)) {
                    $usuario->password = $password->password;
                    $usuario->hashPassword();
                    $usuario->consumirToken(); // Invalida el token inmediatamente
                    $usuario->guardar();

                    header('Location: /');
                    exit;
                }
            }
        }

        $alertas = Usuario::getAlertas();
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
        $usuario = new Usuario;
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            // Asignación segura con lista blanca explícita
            $usuario->sincronizarRegistro($_POST);
            $alertas = $usuario->validarNuevaCuenta();

            if (empty($alertas)) {
                if ($usuario->existeUsuario()) {
                    $alertas = Usuario::getAlertas();
                } else {
                    $usuario->hashPassword();
                    $tokenRaw = $usuario->generarTokenSeguro('confirmacion', 24);

                    $resultado = $usuario->guardar();

                    if ($resultado && !empty($resultado['resultado'])) {
                        $email = new Email($usuario->nombre, $usuario->email, $tokenRaw);
                        $email->enviarConfirmacion();

                        header('Location: /mensaje');
                        exit;
                    }
                }
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
     * Confirmación de cuenta atómica verificando token y expiración.
     */
    public static function confirmar(Router $router)
    {
        iniciar_sesion_segura();
        $alertas = [];
        $token = trim((string)($_GET['token'] ?? ''));

        $usuario = Usuario::buscarPorTokenSeguro($token, 'confirmacion');

        if (!$usuario) {
            Usuario::setAlerta('error', 'Token no válido o expirado');
        } else {
            $usuario->consumirToken();
            $usuario->guardar();
            Usuario::setAlerta('exito', 'Cuenta confirmada correctamente');
        }

        $alertas = Usuario::getAlertas();
        $router->render('auth/confirmar-cuenta', [
            'alertas' => $alertas
        ]);
    }
}