<?php

namespace Controllers;

use MVC\Router;
use Model\Usuario;
use Classes\Email;
use Classes\RateLimiter;
use Model\ActiveRecord;

class LoginController
{
    /**
     * Autenticación de usuarios con regeneración de sesión, reconstrucción estricta de roles
     * y Rate Limiting atómico.
     */
    public static function login(Router $router)
    {
        iniciar_sesion_segura();
        $alertas = [];
        $auth = new Usuario;
        $db = ActiveRecord::getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $emailRaw = $_POST['email'] ?? null;
            $passwordRaw = $_POST['password'] ?? null;

            if (!is_string($emailRaw) || !is_string($passwordRaw)) {
                http_response_code(422);
                Usuario::setAlerta('error', 'Los campos email y password deben ser cadenas de texto válidas');
                $alertas = Usuario::getAlertas();
                $router->render('auth/login', [
                    'alertas' => $alertas,
                    'auth' => $auth,
                ]);
                return;
            }

            $auth = new Usuario($_POST);
            $alertas = $auth->validarLogin();

            $ip = RateLimiter::obtenerIP();
            $email = trim($emailRaw);

            if (!empty($alertas)) {
                $router->render('auth/login', [
                    'alertas' => $alertas,
                    'auth' => $auth,
                ]);
                return;
            }

            // Admisión y reserva atómica de intento previa a la verificación costosa (bcrypt)
            $admision = $db ? RateLimiter::admitirIntentoLogin($db, $ip, $email) : ['admitido' => true, 'segundos_restantes' => 0];

            if (!$admision['admitido']) {
                http_response_code(429);
                $espera = max(1, (int)$admision['segundos_restantes']);
                header("Retry-After: {$espera}");
                $minutos = ceil($espera / 60);
                Usuario::setAlerta('error', "Demasiados intentos fallidos. Por seguridad, intente de nuevo en {$minutos} minutos.");
            } else {
                $usuario = Usuario::where('email', $email);

                if ($usuario && $usuario->comprobarPasswordAndVerificado($auth->password)) {
                    if ($db) {
                        // Política de rate limiting: Un login exitoso limpia ÚNICAMENTE el contador
                        // de la cuenta (email) autenticada. El presupuesto de la IP es compartido y
                        // NO se reinicia aquí; solo expira naturalmente por ventana temporal para
                        // evitar que un atacante eluda el límite de IP intercalando accesos válidos
                        // a una cuenta de control con ataques de fuerza bruta hacia otras cuentas.
                        RateLimiter::limpiarIntentos($db, RateLimiter::TIPO_EMAIL_LOGIN, $email);
                    }

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
                } else {
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

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
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
        $alertas = [];
        $db = ActiveRecord::getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $emailRaw = $_POST['email'] ?? null;
            if (!is_string($emailRaw)) {
                http_response_code(422);
                Usuario::setAlerta('error', 'El email debe ser una cadena de texto válida');
                $alertas = Usuario::getAlertas();
                $router->render('auth/olvide-password', [
                    'alertas' => $alertas
                ]);
                return;
            }

            $email = trim($emailRaw);
            $auth = new Usuario(['email' => $email]);
            $alertas = $auth->validarEmail();

            $ip = RateLimiter::obtenerIP();

            if (!empty($alertas)) {
                $router->render('auth/olvide-password', [
                    'alertas' => $alertas
                ]);
                return;
            }

            $admision = $db ? RateLimiter::admitirIntentoRecovery($db, $ip, $email) : ['admitido' => true, 'segundos_restantes' => 0];

            if (!$admision['admitido']) {
                http_response_code(429);
                $espera = max(1, (int)$admision['segundos_restantes']);
                header("Retry-After: {$espera}");
                $minutos = ceil($espera / 60);
                Usuario::setAlerta('error', "Demasiadas solicitudes de recuperación. Intente en {$minutos} minutos.");
            } else {
                $usuario = Usuario::where('email', $email);
                if ($usuario && (string)$usuario->confirmado === '1') {
                    // Actualización preparada atómica exclusiva de los campos del token condicionada a confirmado = '1'.
                    // No sobreescribe password, rol admin ni datos del perfil ante peticiones concurrentes.
                    $tokenRaw = $usuario->generarYPersistirTokenRecuperacion(2);

                    if ($tokenRaw !== null) {
                        $emailObj = new Email($usuario->nombre, $usuario->email, $tokenRaw);
                        $enviado = $emailObj->enviarInstrucciones();
                        if (!$enviado) {
                            error_log("LoginController::olvide fallo al despachar correo de recuperacion para usuario ID {$usuario->id}");
                        }
                    } else {
                        error_log("LoginController::olvide fallo al persistir token de recuperacion para usuario ID {$usuario->id}");
                    }
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
     * Reenvío seguro de confirmación para cuentas no confirmadas con rate limiting y respuesta genérica.
     */
    public static function reenviarConfirmacion(Router $router)
    {
        iniciar_sesion_segura();
        $alertas = [];
        $db = ActiveRecord::getDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $emailRaw = $_POST['email'] ?? null;
            if (!is_string($emailRaw)) {
                http_response_code(422);
                Usuario::setAlerta('error', 'El email debe ser una cadena de texto válida');
                $alertas = Usuario::getAlertas();
                $router->render('auth/reenviar-confirmacion', [
                    'alertas' => $alertas
                ]);
                return;
            }

            $email = trim($emailRaw);
            $auth = new Usuario(['email' => $email]);
            $alertas = $auth->validarEmail();

            $ip = RateLimiter::obtenerIP();

            if (!empty($alertas)) {
                $router->render('auth/reenviar-confirmacion', [
                    'alertas' => $alertas
                ]);
                return;
            }

            $admision = $db ? RateLimiter::admitirIntentoReconfirm($db, $ip, $email) : ['admitido' => true, 'segundos_restantes' => 0];

            if (!$admision['admitido']) {
                http_response_code(429);
                $espera = max(1, (int)$admision['segundos_restantes']);
                header("Retry-After: {$espera}");
                $minutos = ceil($espera / 60);
                Usuario::setAlerta('error', "Demasiadas solicitudes de confirmación. Intente de nuevo en {$minutos} minutos.");
            } else {
                $usuario = Usuario::where('email', $email);
                if ($usuario && (string)$usuario->confirmado !== '1') {
                    // Actualización preparada atómica exclusiva de los campos del token condicionada a confirmado = '0'.
                    // No sobreescribe password, rol admin ni estado de confirmación ante peticiones concurrentes.
                    $tokenRaw = $usuario->generarYPersistirTokenConfirmacion(24);

                    if ($tokenRaw !== null) {
                        $emailObj = new Email($usuario->nombre, $usuario->email, $tokenRaw);
                        $enviado = $emailObj->enviarConfirmacion();
                        if (!$enviado) {
                            error_log("LoginController::reenviarConfirmacion fallo al despachar correo para usuario ID {$usuario->id}");
                        }
                    } else {
                        error_log("LoginController::reenviarConfirmacion fallo al persistir token para usuario ID {$usuario->id}");
                    }
                }

                // Respuesta genérica para prevenir enumeración de cuentas
                Usuario::setAlerta('exito', 'Si la cuenta existe y está pendiente de confirmación, hemos enviado un nuevo enlace a tu correo electrónico.');
            }
        }

        $alertas = Usuario::getAlertas();
        $router->render('auth/reenviar-confirmacion', [
            'alertas' => $alertas
        ]);
    }

    /**
     * Recuperación de contraseña consumiendo token atómicamente por hash, tipo y vencimiento.
     */
    public static function recuperar(Router $router)
    {
        iniciar_sesion_segura();
        $alertas = [];
        $error = false;
        $token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';

        $usuario = Usuario::buscarPorTokenSeguro($token, 'recuperacion');
        if (!$usuario) {
            Usuario::setAlerta('error', 'Token no válido o expirado');
            $error = true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();

            $passwordInput = $_POST['password'] ?? null;
            if (!is_string($passwordInput)) {
                http_response_code(422);
                Usuario::setAlerta('error', 'El password debe ser una cadena de texto válida');
                $alertas = Usuario::getAlertas();
                $router->render('auth/recuperar-password', [
                    'alertas' => $alertas,
                    'error' => $error
                ]);
                return;
            }

            $password = new Usuario(['password' => $passwordInput]);
            $alertas = $password->validarPassword();

            if (empty($alertas)) {
                $hashNuevo = password_hash($password->password, PASSWORD_BCRYPT);
                $actualizado = Usuario::restablecerPasswordPorToken($token, $hashNuevo);

                if ($actualizado) {
                    header('Location: /');
                    detener_ejecucion(302);
                    return;
                } else {
                    Usuario::setAlerta('error', 'El token no es válido, ya fue utilizado o ha expirado.');
                    $error = true;
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
                        $enviado = $email->enviarConfirmacion();
                        if (!$enviado) {
                            error_log("LoginController::crear fallo al despachar correo de confirmacion para usuario ID {$usuario->id}");
                        }

                        header('Location: /mensaje');
                        detener_ejecucion(302);
                        return;
                    } else {
                        error_log("LoginController::crear fallo al persistir usuario y token en base de datos");
                        Usuario::setAlerta('error', 'Hubo un error al procesar el registro. Intente nuevamente.');
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
     * Confirmación de cuenta atómica condicionada por hash, tipo y vencimiento.
     */
    public static function confirmar(Router $router)
    {
        iniciar_sesion_segura();
        $alertas = [];
        $token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';

        $exito = Usuario::confirmarCuentaPorToken($token);

        if ($exito) {
            Usuario::setAlerta('exito', 'Cuenta confirmada correctamente');
        } else {
            Usuario::setAlerta('error', 'Token no válido, ya utilizado o expirado');
        }

        $alertas = Usuario::getAlertas();
        $router->render('auth/confirmar-cuenta', [
            'alertas' => $alertas
        ]);
    }
}