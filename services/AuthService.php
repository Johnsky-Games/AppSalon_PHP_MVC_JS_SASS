<?php

namespace Services;

use Classes\Email;
use Classes\RateLimiter;
use Model\Usuario;
use Repositories\PersistenceException;
use Repositories\UsuarioRepository;

/**
 * Servicio de dominio para autenticación, registro, confirmación de cuenta
 * y recuperación de contraseña.
 *
 * Recibe datos y contexto explícitos; no accede directamente a $_POST, $_GET ni $_SESSION.
 */
class AuthService
{
    public const STATUS_OK = 'ok';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_INVALID_TYPE = 'invalid_type';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_ERROR = 'error';

    private UsuarioRepository $repository;
    /** @var callable|null */
    private $emailDispatcher;

    /**
     * @param UsuarioRepository|null $repository Repositorio de usuarios.
     * @param callable|null $emailDispatcher Callback opcional `fn(string $tipo, string $email, string $nombre, string $tokenPlano): bool`.
     */
    public function __construct(?UsuarioRepository $repository = null, ?callable $emailDispatcher = null)
    {
        $this->repository = $repository ?? new UsuarioRepository();
        $this->emailDispatcher = $emailDispatcher;
    }

    /**
     * Autentica a un usuario validando tipos escalares, campos obligatorios, presupuesto de rate limiting,
     * hash de contraseña y estado de confirmación de la cuenta.
     *
     * Garantías preservadas:
     * - Un login exitoso limpia únicamente el contador por cuenta (`TIPO_EMAIL_LOGIN`) y NUNCA reinicia
     *   el presupuesto compartido por IP (`TIPO_IP_LOGIN`).
     * - Diferencia entradas no escalares (`STATUS_INVALID_TYPE`), errores de validación (`STATUS_INVALID`),
     *   bloqueo por rate limit (`STATUS_RATE_LIMITED`), credenciales inválidas / cuenta no confirmada
     *   (`STATUS_UNAUTHORIZED`) y fallos SQL (`STATUS_ERROR`), sin revelar existencia de cuentas ni detalles SQL.
     *
     * @param array $datos Datos de entrada (`email`, `password`).
     * @param string $ip Dirección IP del cliente para rate limiting.
     * @return array Resultado estructurado con `status`, `resultado`, `usuario` y `alertas`.
     */
    public function login(array $datos, string $ip = '127.0.0.1'): array
    {
        Usuario::limpiarAlertas();

        $emailRaw = $datos['email'] ?? null;
        $passwordRaw = $datos['password'] ?? null;

        if (!is_string($emailRaw) || !is_string($passwordRaw)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID_TYPE,
                false,
                new Usuario(),
                ['error' => ['Los campos email y password deben ser cadenas de texto válidas']]
            );
        }

        $auth = new Usuario($datos);
        $alertas = $auth->validarLogin();
        if (!empty($alertas)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                $auth,
                $alertas
            );
        }

        $email = trim($emailRaw);

        try {
            $db = $this->repository->getDb();
            $admision = RateLimiter::admitirIntentoLogin($db, $ip, $email);
            if (!$admision['admitido']) {
                $espera = max(1, (int)$admision['segundos_restantes']);
                $minutos = (int)ceil($espera / 60);
                return $this->construirRespuesta(
                    self::STATUS_RATE_LIMITED,
                    false,
                    $auth,
                    ['error' => ["Demasiados intentos fallidos. Por seguridad, intente de nuevo en {$minutos} minutos."]],
                    ['segundos_restantes' => $espera]
                );
            }

            $usuario = $this->repository->findByEmail($email);
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                $auth,
                ['error' => ['No fue posible procesar el inicio de sesión en este momento. Intenta nuevamente.']]
            );
        }

        if ($usuario !== null && $usuario->comprobarPasswordAndVerificado($auth->password)) {
            // Política de rate limiting: Un login exitoso limpia ÚNICAMENTE el contador
            // de la cuenta (email) autenticada. El presupuesto de la IP es compartido y
            // NO se reinicia aquí; solo expira naturalmente por ventana temporal para
            // evitar que un atacante eluda el límite de IP intercalando accesos válidos
            // a una cuenta de control con ataques de fuerza bruta hacia otras cuentas.
            try {
                RateLimiter::limpiarIntentos($this->repository->getDb(), RateLimiter::TIPO_EMAIL_LOGIN, $email);
            } catch (\Throwable $e) {
                // No interrumpir el login ya verificado si falla la limpieza auxiliar
            }

            return $this->construirRespuesta(
                self::STATUS_OK,
                true,
                $usuario,
                []
            );
        }

        // Mensaje unificado para credenciales inválidas o cuenta no verificada (sin enumeración de cuentas)
        return $this->construirRespuesta(
            self::STATUS_UNAUTHORIZED,
            false,
            $auth,
            ['error' => ['Credenciales incorrectas o la cuenta no ha sido verificada']]
        );
    }

    /**
     * Registra una nueva cuenta de usuario aplicando lista blanca de campos permitidos,
     * validaciones de dominio, hash bcrypt, generación de token seguro con hash SHA-256
     * y envío de correo únicamente tras persistir con éxito el usuario.
     *
     * @param array $datos Datos del formulario de registro.
     * @return array Resultado estructurado con `status`, `resultado`, `id`, `usuario`, `token` y `alertas`.
     */
    public function registrar(array $datos): array
    {
        Usuario::limpiarAlertas();

        $usuario = new Usuario();
        $usuario->sincronizarRegistro($datos);

        $alertas = $usuario->validarNuevaCuenta();
        if (!empty($alertas)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                $usuario,
                $alertas,
                ['id' => null, 'token' => null]
            );
        }

        try {
            if ($this->repository->existsByEmail((string)$usuario->email)) {
                return $this->construirRespuesta(
                    self::STATUS_CONFLICT,
                    false,
                    $usuario,
                    ['error' => ['El usuario ya está registrado']],
                    ['id' => null, 'token' => null]
                );
            }

            $usuario->hashPassword();
            $tokenPlano = $usuario->generarTokenSeguro('confirmacion', 24);
            $insertId = $this->repository->create($usuario);
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                $usuario,
                ['error' => ['Hubo un error al procesar el registro. Intente nuevamente.']],
                ['id' => null, 'token' => null]
            );
        }

        // Enviar correo únicamente después de persistir exitosamente el usuario y su token
        $enviado = $this->despacharCorreo('confirmacion', (string)$usuario->email, (string)$usuario->nombre, $tokenPlano);
        if (!$enviado) {
            error_log("AuthService::registrar fallo al despachar correo de confirmacion para usuario ID {$usuario->id}");
        }

        return $this->construirRespuesta(
            self::STATUS_OK,
            true,
            $usuario,
            [],
            ['id' => $insertId, 'token' => $tokenPlano]
        );
    }

    /**
     * Confirma una cuenta consumiendo atómicamente un token de confirmación de un solo uso.
     *
     * @param mixed $tokenRaw Token recibido por query string.
     * @return array Resultado estructurado con `status`, `resultado` y `alertas`.
     */
    public function confirmarCuenta($tokenRaw): array
    {
        Usuario::limpiarAlertas();

        $token = is_string($tokenRaw) ? trim($tokenRaw) : '';
        if ($token === '') {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                null,
                ['error' => ['Token no válido, ya utilizado o expirado']]
            );
        }

        try {
            $confirmado = $this->repository->confirmAccountByToken($token);
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                null,
                ['error' => ['No fue posible procesar la confirmación en este momento. Intenta nuevamente.']]
            );
        }

        if (!$confirmado) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                null,
                ['error' => ['Token no válido, ya utilizado o expirado']]
            );
        }

        return $this->construirRespuesta(
            self::STATUS_OK,
            true,
            null,
            ['exito' => ['Cuenta confirmada correctamente']]
        );
    }

    /**
     * Procesa la solicitud de recuperación de contraseña (`/olvide`).
     * Preserva anti-enumeración de cuentas, rate limiting por IP/email, actualización atómica
     * exclusiva de columnas de token y envío de correo solo tras persistir el token.
     *
     * @param array $datos Datos de entrada (`email`).
     * @param string $ip Dirección IP del cliente.
     * @return array Resultado estructurado con `status`, `resultado`, `usuario` y `alertas`.
     */
    public function solicitarRecuperacion(array $datos, string $ip = '127.0.0.1'): array
    {
        Usuario::limpiarAlertas();

        $emailRaw = $datos['email'] ?? null;
        if (!is_string($emailRaw)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID_TYPE,
                false,
                new Usuario(),
                ['error' => ['El email debe ser una cadena de texto válida']]
            );
        }

        $email = trim($emailRaw);
        $auth = new Usuario(['email' => $email]);

        $alertas = $auth->validarEmail();
        if (!empty($alertas)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                $auth,
                $alertas
            );
        }

        try {
            $db = $this->repository->getDb();
            $admision = RateLimiter::admitirIntentoRecovery($db, $ip, $email);
            if (!$admision['admitido']) {
                $espera = max(1, (int)$admision['segundos_restantes']);
                $minutos = (int)ceil($espera / 60);
                return $this->construirRespuesta(
                    self::STATUS_RATE_LIMITED,
                    false,
                    $auth,
                    ['error' => ["Demasiadas solicitudes de recuperación. Intente en {$minutos} minutos."]],
                    ['segundos_restantes' => $espera]
                );
            }

            $usuario = $this->repository->findByEmail($email);
            if ($usuario !== null && (string)$usuario->confirmado === '1') {
                $tokenPlano = $this->repository->issueRecoveryToken($usuario, 2);
                if ($tokenPlano === null) {
                    error_log("AuthService::solicitarRecuperacion fallo al persistir token de recuperacion para usuario ID {$usuario->id}");
                    return $this->construirRespuesta(
                        self::STATUS_ERROR,
                        false,
                        $auth,
                        ['error' => ['No fue posible procesar la solicitud en este momento. Intenta nuevamente.']]
                    );
                }

                // Enviar instrucciones únicamente después de persistir exitosamente el token
                $enviado = $this->despacharCorreo('recuperacion', (string)$usuario->email, (string)$usuario->nombre, $tokenPlano);
                if (!$enviado) {
                    error_log("AuthService::solicitarRecuperacion fallo al despachar correo de recuperacion para usuario ID {$usuario->id}");
                }
            }
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                $auth,
                ['error' => ['No fue posible procesar la solicitud en este momento. Intenta nuevamente.']]
            );
        }

        // Mensaje genérico uniforme para evitar enumeración de usuarios/estados
        return $this->construirRespuesta(
            self::STATUS_OK,
            true,
            $auth,
            ['exito' => ['Si el correo electrónico está registrado, recibirás las instrucciones para restablecer tu contraseña en breve.']]
        );
    }

    /**
     * Procesa la solicitud de reenvío de confirmación de cuenta (`/reenviar-confirmacion`).
     * Preserva anti-enumeración de cuentas, rate limiting por IP/email, actualización atómica
     * con condición `confirmado = '0'` y envío de correo solo tras persistir el token.
     *
     * @param array $datos Datos de entrada (`email`).
     * @param string $ip Dirección IP del cliente.
     * @return array Resultado estructurado con `status`, `resultado`, `usuario` y `alertas`.
     */
    public function reenviarConfirmacion(array $datos, string $ip = '127.0.0.1'): array
    {
        Usuario::limpiarAlertas();

        $emailRaw = $datos['email'] ?? null;
        if (!is_string($emailRaw)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID_TYPE,
                false,
                new Usuario(),
                ['error' => ['El email debe ser una cadena de texto válida']]
            );
        }

        $email = trim($emailRaw);
        $auth = new Usuario(['email' => $email]);

        $alertas = $auth->validarEmail();
        if (!empty($alertas)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                $auth,
                $alertas
            );
        }

        try {
            $db = $this->repository->getDb();
            $admision = RateLimiter::admitirIntentoReconfirm($db, $ip, $email);
            if (!$admision['admitido']) {
                $espera = max(1, (int)$admision['segundos_restantes']);
                $minutos = (int)ceil($espera / 60);
                return $this->construirRespuesta(
                    self::STATUS_RATE_LIMITED,
                    false,
                    $auth,
                    ['error' => ["Demasiadas solicitudes de confirmación. Intente de nuevo en {$minutos} minutos."]],
                    ['segundos_restantes' => $espera]
                );
            }

            $usuario = $this->repository->findByEmail($email);
            if ($usuario !== null && (string)$usuario->confirmado !== '1') {
                $tokenPlano = $this->repository->issueConfirmationToken($usuario, 24);
                if ($tokenPlano === null) {
                    error_log("AuthService::reenviarConfirmacion fallo al persistir token para usuario ID {$usuario->id}");
                    return $this->construirRespuesta(
                        self::STATUS_ERROR,
                        false,
                        $auth,
                        ['error' => ['No fue posible procesar la solicitud en este momento. Intenta nuevamente.']]
                    );
                }

                // Enviar correo de confirmación únicamente después de persistir exitosamente el token
                $enviado = $this->despacharCorreo('confirmacion', (string)$usuario->email, (string)$usuario->nombre, $tokenPlano);
                if (!$enviado) {
                    error_log("AuthService::reenviarConfirmacion fallo al despachar correo para usuario ID {$usuario->id}");
                }
            }
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                $auth,
                ['error' => ['No fue posible procesar la solicitud en este momento. Intenta nuevamente.']]
            );
        }

        // Respuesta genérica uniforme para prevenir enumeración de cuentas
        return $this->construirRespuesta(
            self::STATUS_OK,
            true,
            $auth,
            ['exito' => ['Si la cuenta existe y está pendiente de confirmación, hemos enviado un nuevo enlace a tu correo electrónico.']]
        );
    }

    /**
     * Verifica si un token de recuperación es válido y vigente para mostrar el formulario `/recuperar`.
     *
     * @param mixed $tokenRaw Token recibido por query string.
     * @return array Resultado estructurado con `status`, `resultado`, `error`, `usuario` y `alertas`.
     */
    public function validarTokenRecuperacion($tokenRaw): array
    {
        Usuario::limpiarAlertas();

        $token = is_string($tokenRaw) ? trim($tokenRaw) : '';
        if ($token === '') {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                null,
                ['error' => ['Token no válido o expirado']],
                ['error' => true]
            );
        }

        try {
            $usuario = $this->repository->findByValidToken($token, 'recuperacion');
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                null,
                ['error' => ['No fue posible verificar el token en este momento. Intenta nuevamente.']],
                ['error' => true]
            );
        }

        if ($usuario === null) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                null,
                ['error' => ['Token no válido o expirado']],
                ['error' => true]
            );
        }

        return $this->construirRespuesta(
            self::STATUS_OK,
            true,
            $usuario,
            [],
            ['error' => false]
        );
    }

    /**
     * Valida la nueva contraseña y consume atómicamente el token de recuperación de un solo uso.
     *
     * @param mixed $tokenRaw Token de recuperación en texto plano.
     * @param array $datos Datos enviados en el formulario (`password`).
     * @return array Resultado estructurado con `status`, `resultado`, `error` y `alertas`.
     */
    public function restablecerPassword($tokenRaw, array $datos): array
    {
        Usuario::limpiarAlertas();

        $token = is_string($tokenRaw) ? trim($tokenRaw) : '';
        $passwordInput = $datos['password'] ?? null;

        if (!is_string($passwordInput)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID_TYPE,
                false,
                null,
                ['error' => ['El password debe ser una cadena de texto válida']],
                ['error' => false]
            );
        }

        if ($token === '') {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                null,
                ['error' => ['El token no es válido, ya fue utilizado o ha expirado.']],
                ['error' => true]
            );
        }

        $passwordNuevo = new Usuario(['password' => $passwordInput]);
        $alertas = $passwordNuevo->validarPassword();
        if (!empty($alertas)) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                null,
                $alertas,
                ['error' => false]
            );
        }

        $hashNuevo = password_hash((string)$passwordNuevo->password, PASSWORD_BCRYPT);

        try {
            $actualizado = $this->repository->resetPasswordByToken($token, $hashNuevo);
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                null,
                ['error' => ['No fue posible restablecer la contraseña en este momento. Intenta nuevamente.']],
                ['error' => true]
            );
        }

        if (!$actualizado) {
            return $this->construirRespuesta(
                self::STATUS_INVALID,
                false,
                null,
                ['error' => ['El token no es válido, ya fue utilizado o ha expirado.']],
                ['error' => true]
            );
        }

        return $this->construirRespuesta(
            self::STATUS_OK,
            true,
            null,
            [],
            ['error' => false]
        );
    }

    /**
     * Despacha el correo transaccional correspondiente utilizando el dispatcher inyectado o `Classes\Email`.
     */
    private function despacharCorreo(string $tipo, string $emailDestino, string $nombre, string $tokenPlano): bool
    {
        if (is_callable($this->emailDispatcher)) {
            return (bool)call_user_func($this->emailDispatcher, $tipo, $emailDestino, $nombre, $tokenPlano);
        }

        $email = new Email($nombre, $emailDestino, $tokenPlano);
        if ($tipo === 'recuperacion') {
            return $email->enviarInstrucciones();
        }

        return $email->enviarConfirmacion();
    }

    /**
     * Construye el arreglo de respuesta estándar y sincroniza las alertas estáticas en `Usuario`
     * para mantener compatibilidad con vistas y pruebas existentes.
     */
    private function construirRespuesta(
        string $status,
        bool $resultado,
        ?Usuario $usuario,
        array $alertas,
        array $extra = []
    ): array {
        Usuario::limpiarAlertas();
        foreach ($alertas as $tipo => $mensajes) {
            if (is_array($mensajes)) {
                foreach ($mensajes as $mensaje) {
                    Usuario::setAlerta((string)$tipo, (string)$mensaje);
                }
            }
        }

        return array_merge([
            'status' => $status,
            'resultado' => $resultado,
            'usuario' => $usuario,
            'alertas' => $alertas
        ], $extra);
    }
}
