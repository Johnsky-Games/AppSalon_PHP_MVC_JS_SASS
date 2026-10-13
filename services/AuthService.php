<?php

namespace Services;

use Classes\ConflictoIdempotenciaException;
use Classes\Email;
use Classes\Idempotencia;
use Classes\OperacionEnCursoException;
use Classes\RateLimiter;
use Model\Usuario;
use Repositories\DuplicateEntryException;
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
    /** Misma clave de operación reutilizada con datos distintos. */
    public const STATUS_CONFLICT_CLAVE = 'conflict_key';
    /** Otra solicitud con la misma clave sigue procesándose. */
    public const STATUS_EN_CURSO = 'in_progress';

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
     * Registra una nueva cuenta de CLIENTE aplicando lista blanca de campos permitidos,
     * validaciones de dominio, hash bcrypt, generación de token seguro con hash SHA-256
     * y envío de correo únicamente tras persistir con éxito la cuenta y su membresía.
     *
     * Idempotencia: con `$claveOperacion` el alta es repetible. Un doble clic, un reintento tras perder la
     * respuesta o una solicitud concurrente con la misma clave devuelven el resultado original sin crear
     * otra cuenta ni enviar otro correo. La misma clave con datos distintos produce STATUS_CONFLICT_CLAVE.
     * Sin clave (llamadas heredadas) el UNIQUE de correo normalizado sigue impidiendo identidades duplicadas.
     *
     * @param array $datos Datos del formulario de registro.
     * @param string|null $ip IP del cliente (límite de intentos); null para llamadas de dominio.
     * @param string|null $claveOperacion Clave de operación generada al mostrar el formulario.
     * @param string|null $actor Actor de la operación (p. ej. Idempotencia::actorAnonimo()).
     * @return array Resultado estructurado con `status`, `resultado`, `id`, `usuario`, `token`, `repetida` y `alertas`.
     */
    public function registrar(array $datos, ?string $ip = null, ?string $claveOperacion = null, ?string $actor = null): array
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

        $usaIdempotencia = $claveOperacion !== null && $actor !== null;
        $contenido = [
            'nombre' => (string)$usuario->nombre, 'apellido' => (string)$usuario->apellido,
            'telefono' => (string)$usuario->telefono, 'email' => mb_strtolower(trim((string)$usuario->email)),
            'password' => (string)$usuario->password,
        ];

        try {
            if ($usaIdempotencia) {
                if (!Idempotencia::claveValida($claveOperacion)) {
                    return $this->construirRespuesta(self::STATUS_INVALID, false, $usuario,
                        ['error' => ['El formulario venció. Recárgalo para crear tu cuenta.']], ['id' => null, 'token' => null]);
                }
                // Un reintento de una alta ya confirmada no consume el límite de intentos ni repite efectos.
                $previa = Idempotencia::buscar($this->repository->getDb(), 'cliente.registro', $actor, $claveOperacion, $contenido);
                if ($previa !== null) {
                    return $this->construirRespuesta(self::STATUS_OK, true, $usuario, [],
                        ['id' => $previa['recurso_id'], 'token' => null, 'repetida' => true]);
                }
            }
            // El transporte público siempre suministra IP; llamadas de dominio existentes conservan compatibilidad.
            if ($ip !== null) {
                $admision = RateLimiter::admitirDosVectores($this->repository->getDb(), 'ip_register', $ip, 'email_register', strtolower((string)$usuario->email), 10, 3);
                if (!$admision['admitido']) {
                    return $this->construirRespuesta(self::STATUS_RATE_LIMITED, false, $usuario,
                        ['error' => ['Demasiadas solicitudes de registro. Intenta más tarde.']],
                        ['segundos_restantes' => max(1,(int)$admision['segundos_restantes']), 'id' => null, 'token' => null]);
                }
            }
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
            // Identidad y membresía cliente se crean en una sola transacción; el registro público
            // nunca produce acceso laboral ni requiere aprobación del equipo.
            if ($usaIdempotencia) {
                $op = Idempotencia::ejecutar($this->repository->getDb(), 'cliente.registro', $actor, $claveOperacion, $contenido,
                    function () use ($usuario): array {
                        $id = $this->repository->createCliente($usuario, 'registro_publico');
                        return ['recurso_tipo' => 'usuario', 'recurso_id' => $id, 'datos' => []];
                    });
                if ($op['repetida']) {
                    // Otra solicitud concurrente con la misma clave ganó: su correo ya se envió.
                    return $this->construirRespuesta(self::STATUS_OK, true, $usuario, [],
                        ['id' => $op['recurso_id'], 'token' => null, 'repetida' => true]);
                }
                $insertId = $op['recurso_id'];
            } else {
                $insertId = $this->repository->createCliente($usuario, 'registro_publico');
            }
        } catch (ConflictoIdempotenciaException $e) {
            return $this->construirRespuesta(self::STATUS_CONFLICT_CLAVE, false, $usuario,
                ['error' => ['Esta solicitud ya se procesó con otros datos. Recarga el formulario para crear una cuenta nueva.']],
                ['id' => null, 'token' => null]);
        } catch (OperacionEnCursoException $e) {
            return $this->construirRespuesta(self::STATUS_EN_CURSO, false, $usuario,
                ['error' => ['Tu solicitud anterior sigue procesándose. Espera unos segundos y revisa tu correo.']],
                ['id' => null, 'token' => null, 'segundos_restantes' => 3]);
        } catch (DuplicateEntryException $e) {
            // Otra solicitud concurrente ganó la carrera por el mismo correo: misma respuesta que el SELECT previo.
            return $this->construirRespuesta(
                self::STATUS_CONFLICT,
                false,
                $usuario,
                ['error' => ['El usuario ya está registrado']],
                ['id' => null, 'token' => null]
            );
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
            ['id' => $insertId, 'token' => $tokenPlano, 'repetida' => false]
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
     * Verifica un enlace de confirmación sin consumirlo, para solicitudes HEAD.
     *
     * @param mixed $tokenRaw Token recibido por query string.
     * @return array Resultado estructurado con `status`, `resultado`, `usuario` y `alertas`.
     */
    public function validarTokenConfirmacion($tokenRaw): array
    {
        $token = is_string($tokenRaw) ? trim($tokenRaw) : '';
        try {
            $usuario = $token !== '' ? $this->repository->findByValidToken($token, 'confirmacion') : null;
        } catch (PersistenceException $exception) {
            return $this->construirRespuesta(self::STATUS_ERROR, false, null,
                ['error' => ['No fue posible verificar el enlace en este momento.']]);
        }
        return $usuario !== null
            ? $this->construirRespuesta(self::STATUS_OK, true, $usuario, [])
            : $this->construirRespuesta(self::STATUS_INVALID, false, null,
                ['error' => ['El enlace no es válido, ya fue utilizado o venció.']]);
    }

    /** Verifica la vigencia del enlace antes de mostrar el formulario de recuperación. */
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
