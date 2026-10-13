<?php

namespace Services;

use Classes\ConflictoIdempotenciaException;
use Classes\Email;
use Classes\Idempotencia;
use Classes\OperacionEnCursoException;
use Classes\RateLimiter;
use Model\Usuario;
use Repositories\CorreoSalidaRepository;
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

    /** Hash bcrypt (coste 10) de una cadena descartable; solo sirve para igualar el tiempo de login de correos inexistentes. */
    private const HASH_SENUELO = '$2y$10$luI5hG7XjLbS/l2OEYcLrONW5Bg4fFubWgfSB2maV/17K7KCR774m';

    private UsuarioRepository $repository;
    /** @var callable|null */
    private $emailDispatcher;
    private ?CorreoSalidaService $correoSalida;

    /**
     * @param UsuarioRepository|null $repository Repositorio de usuarios.
     * @param callable|null $emailDispatcher Callback opcional `fn(string $tipo, string $email, string $nombre, string $tokenPlano): bool`.
     *        Si se inyecta, `registrar` lo invoca TRAS el commit en lugar de usar la cola de correos (compatibilidad con
     *        pruebas unitarias). Recibe `confirmacion` (cuenta nueva o sin confirmar) o `aviso_cuenta_existente` (token vacío).
     * @param CorreoSalidaService|null $correoSalida Cola de correos para `registrar` (por defecto se construye con la conexión del repositorio).
     */
    public function __construct(?UsuarioRepository $repository = null, ?callable $emailDispatcher = null, ?CorreoSalidaService $correoSalida = null)
    {
        $this->repository = $repository ?? new UsuarioRepository();
        $this->emailDispatcher = $emailDispatcher;
        $this->correoSalida = $correoSalida;
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

        if ($usuario === null) {
            // Equipara el coste de bcrypt con el de una cuenta existente: sin esto, un correo inexistente responde ~10 veces
            // más rápido y el tiempo de respuesta permite enumerar cuentas (hallazgo de la ronda 2, DEF-13).
            password_verify($auth->password, self::HASH_SENUELO);
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
     * Registro público de CLIENTES con respuesta UNIFORME (anti-enumeración de cuentas, DEF-13).
     *
     * Contrato público: para cualquier correo bien formado el resultado visible es el mismo
     * (`STATUS_OK`, sin alertas, sin diferencias en `repetida`/`token` observables por el controlador): correo nuevo,
     * cliente confirmado, cliente sin confirmar, personal laboral (colaborador/propietario) y cuenta suspendida.
     * Solo los errores de VALIDACIÓN del propio formulario (`STATUS_INVALID`), el límite de intentos
     * (`STATUS_RATE_LIMITED`, mismo presupuesto para todo correo) y los conflictos de CLAVE de operación
     * (`STATUS_CONFLICT_CLAVE`/`STATUS_EN_CURSO`, que dependen solo de la clave y no de la cuenta) se distinguen.
     * `STATUS_CONFLICT` ya no lo devuelve este método: «el usuario ya está registrado» dejó de ser información pública.
     *
     * Efectos en el servidor (no visibles para quien solicita), todos en UNA transacción con la reserva de idempotencia:
     *  - correo nuevo ........................ alta de identidad + membresía cliente + evento «confirmación» en la cola;
     *  - cliente sin confirmar ............... NO crea otra cuenta, NO toca su contraseña, emite token nuevo y encola confirmación;
     *  - cliente confirmado / personal / suspendida: NO modifica nada y encola un «aviso de cuenta existente» al titular
     *    (portal 'equipo' si la cuenta es laboral sin membresía cliente), máximo uno por hora y correo (clave única de la cola).
     *
     * Entrega: tras el commit se llama a `CorreoSalidaService::despacharTrasCommit` (intento inmediato; si falla, el evento queda
     * reintentable por el worker). Si se inyectó `$emailDispatcher`, se usa ÉL tras el commit en lugar de la cola (modo
     * compatibilidad de pruebas unitarias; sin cola no hay límite horario de avisos).
     *
     * Equiparación de superficie lateral: `password_hash` se calcula en TODAS las ramas antes de decidir, la admisión del
     * límite de intentos ocurre antes de consultar la cuenta y cada rama termina en la misma secuencia
     * (reserva de idempotencia, escritura/aviso en cola, actualización de la reserva, commit, despacho). No se afirma tiempo
     * constante: ver docs/GUIA_ACTUALIZACION_IDENTIDADES.md §10 para la medición y el residuo.
     *
     * Idempotencia: con `$claveOperacion` toda solicitud (incluidas las de correos ya existentes) es repetible. Un doble clic,
     * un reintento tras perder la respuesta o una solicitud concurrente con la misma clave devuelven el mismo resultado sin repetir
     * efectos. La misma clave con datos distintos produce STATUS_CONFLICT_CLAVE. Sin clave (llamadas heredadas) el UNIQUE de correo
     * normalizado sigue impidiendo identidades duplicadas.
     *
     * @param array $datos Datos del formulario de registro.
     * @param string|null $ip IP del cliente (límite de intentos); null para llamadas de dominio.
     * @param string|null $claveOperacion Clave de operación generada al mostrar el formulario.
     * @param string|null $actor Actor de la operación (p. ej. Idempotencia::actorAnonimo()).
     * @return array Resultado estructurado con `status`, `resultado`, `id` (solo si esta llamada creó la cuenta), `usuario`, `token`, `repetida` y `alertas`.
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
                // Un reintento de una solicitud ya procesada (sea cual sea la cuenta) no consume el límite ni repite efectos.
                $previa = Idempotencia::buscar($this->repository->getDb(), 'cliente.registro', $actor, $claveOperacion, $contenido);
                if ($previa !== null) {
                    return $this->respuestaRegistroUniforme($usuario, $previa['recurso_tipo'] === 'usuario' ? $previa['recurso_id'] : null, null, true);
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

            // El coste de bcrypt se paga SIEMPRE, exista o no la cuenta (el hash se descarta si no hay alta).
            $usuario->hashPassword();

            $efecto = null;
            $op = null;
            for ($intento = 1; $op === null; $intento++) {
                $efecto = null;
                try {
                    $alta = function () use ($usuario, &$efecto): array {
                        return $this->altaOAvisoDeRegistro($usuario, $efecto);
                    };
                    $op = $usaIdempotencia
                        ? Idempotencia::ejecutar($this->repository->getDb(), 'cliente.registro', $actor, $claveOperacion, $contenido, $alta)
                        : ['repetida' => false] + $this->repository->enTransaccion($alta);
                } catch (DuplicateEntryException $e) {
                    // Otra solicitud (otra clave) creó este mismo correo entre la lectura y el INSERT: toda la transacción se
                    // revirtió; se repite y esta vez el correo ya existe, así que sigue la rama de cuenta existente.
                    if ($intento >= 3) {
                        throw $e;
                    }
                    $usuario->id = null;
                }
            }
        } catch (ConflictoIdempotenciaException $e) {
            return $this->construirRespuesta(self::STATUS_CONFLICT_CLAVE, false, $usuario,
                ['error' => ['Esta solicitud ya se procesó con otros datos. Recarga el formulario para crear una cuenta nueva.']],
                ['id' => null, 'token' => null]);
        } catch (OperacionEnCursoException $e) {
            return $this->construirRespuesta(self::STATUS_EN_CURSO, false, $usuario,
                ['error' => ['Tu solicitud anterior sigue procesándose. Espera unos segundos y revisa tu correo.']],
                ['id' => null, 'token' => null, 'segundos_restantes' => 3]);
        } catch (PersistenceException $e) {
            return $this->construirRespuesta(
                self::STATUS_ERROR,
                false,
                $usuario,
                ['error' => ['Hubo un error al procesar el registro. Intente nuevamente.']],
                ['id' => null, 'token' => null]
            );
        }

        if ($op['repetida']) {
            // Otra solicitud concurrente con la misma clave ganó: ella ya encoló y despachó.
            return $this->respuestaRegistroUniforme($usuario, $op['recurso_tipo'] === 'usuario' ? $op['recurso_id'] : null, null, true);
        }

        // Correo/aviso únicamente después de confirmar la transacción.
        $this->despacharEfectoRegistro($efecto);

        $creada = ($efecto['tipo'] ?? '') === 'alta';
        return $this->respuestaRegistroUniforme($usuario, $creada ? $efecto['usuario_id'] : null, $creada ? $efecto['token'] : null, false);
    }

    /**
     * Respuesta OK idéntica para todas las ramas. `id`/`token` solo se rellenan cuando ESTA llamada creó la cuenta
     * (los llamadores de dominio y las pruebas los necesitan; el controlador público no los usa ni los muestra).
     */
    private function respuestaRegistroUniforme(Usuario $usuario, ?int $id, ?string $token, bool $repetida): array
    {
        return $this->construirRespuesta(self::STATUS_OK, true, $usuario, [], ['id' => $id, 'token' => $token, 'repetida' => $repetida]);
    }

    /**
     * Cuerpo transaccional del registro: decide la rama según la cuenta y encola SU evento dentro de la misma transacción.
     * Llena `$efecto` (datos para el despacho post-commit; incluye el token en claro solo en memoria).
     *
     * @param array|null $efecto
     * @return array{recurso_tipo:string,recurso_id:int,datos:array}
     */
    private function altaOAvisoDeRegistro(Usuario $usuario, ?array &$efecto): array
    {
        $efecto = null;
        $existente = $this->repository->findByEmail((string)$usuario->email);

        if ($existente === null) {
            $tokenPlano = $usuario->generarTokenSeguro('confirmacion', 24);
            // Identidad y membresía cliente en una sola transacción; el registro público nunca produce acceso laboral.
            $id = $this->repository->createCliente($usuario, 'registro_publico');
            $efecto = $this->encolarEfectoRegistro('alta', $id, (string)$usuario->email, (string)$usuario->nombre, $tokenPlano, 'cliente');
            return ['recurso_tipo' => 'usuario', 'recurso_id' => $id, 'datos' => ['accion' => 'alta']];
        }

        $cuenta = $this->clasificarCuentaExistente((int)$existente->id);
        // La contraseña enviada se ignora: jamás se pisa la de una cuenta existente.
        if ((string)$existente->confirmado !== '1' && $cuenta['portal'] === 'cliente' && !$cuenta['suspendida']) {
            $tokenPlano = $this->repository->issueConfirmationToken($existente, 24);
            if ($tokenPlano !== null) {
                $efecto = $this->encolarEfectoRegistro('reconfirmacion', (int)$existente->id, (string)$existente->email, (string)$existente->nombre, $tokenPlano, 'cliente');
                return ['recurso_tipo' => 'registro_existente', 'recurso_id' => 0, 'datos' => ['accion' => 'reconfirmacion']];
            }
            // Se confirmó entre la lectura y la emisión: se trata como cuenta ya activa.
        }

        // Cliente confirmado, personal laboral o cuenta suspendida: solo se avisa al titular real del correo.
        $efecto = $this->encolarEfectoRegistro('aviso', (int)$existente->id, (string)$existente->email, (string)$existente->nombre, '', $cuenta['portal']);
        return ['recurso_tipo' => 'registro_existente', 'recurso_id' => 0, 'datos' => ['accion' => 'aviso']];
    }

    /**
     * Encola (dentro de la transacción en curso) el evento de la rama elegida; con dispatcher inyectado no usa la cola.
     * @return array{tipo:string,usuario_id:int,email:string,nombre:string,token:string,portal:string,evento:?int}
     */
    private function encolarEfectoRegistro(string $tipo, int $usuarioId, string $email, string $nombre, string $tokenPlano, string $portal): array
    {
        $evento = null;
        if ($this->emailDispatcher === null) {
            $cola = $this->colaCorreos();
            $evento = $tipo === 'aviso'
                ? $cola->encolarAvisoCuentaExistente($email, $nombre, $portal, gmdate('YmdH'))
                : $cola->encolarConfirmacionCuenta($usuarioId, $email, $nombre, $tokenPlano);
        }
        return ['tipo' => $tipo, 'usuario_id' => $usuarioId, 'email' => $email, 'nombre' => $nombre, 'token' => $tokenPlano, 'portal' => $portal, 'evento' => $evento];
    }

    /** Intento inmediato de entrega TRAS el commit; un fallo nunca revierte el alta (el evento queda reintentable). */
    private function despacharEfectoRegistro(?array $efecto): void
    {
        if ($efecto === null) {
            return;
        }
        if ($this->emailDispatcher !== null) {
            $tipo = $efecto['tipo'] === 'aviso' ? 'aviso_cuenta_existente' : 'confirmacion';
            $enviado = (bool)call_user_func($this->emailDispatcher, $tipo, $efecto['email'], $efecto['nombre'], $efecto['token']);
            if (!$enviado) {
                error_log("AuthService::registrar fallo al despachar correo '{$tipo}' para usuario ID {$efecto['usuario_id']}");
            }
            return;
        }
        if ($efecto['evento'] !== null) {
            // Devuelve false tanto si el envío falló (queda reintentable por el worker) como si otro proceso/solicitud ya lo
            // entregó (aviso ya enviado en esta hora): no es un error, por eso no se registra.
            $this->colaCorreos()->despacharTrasCommit((int)$efecto['evento']);
        }
    }

    private function colaCorreos(): CorreoSalidaService
    {
        return $this->correoSalida ??= new CorreoSalidaService(new CorreoSalidaRepository($this->repository->getDb()));
    }

    /**
     * Portal y estado de una cuenta existente. Un fallo técnico NO se interpreta como cuenta activa ni inactiva:
     * se asume la rama más conservadora (solo aviso, sin emitir token).
     * @return array{portal:string,suspendida:bool}
     */
    private function clasificarCuentaExistente(int $usuarioId): array
    {
        try {
            $c = (new AuthorizationService($this->repository->getDb()))->contexto($usuarioId);
        } catch (\Throwable) {
            return ['portal' => 'cliente', 'suspendida' => true];
        }
        if ($c === null) {
            return ['portal' => 'cliente', 'suspendida' => true];
        }
        return [
            'portal' => $c['rol'] !== 'cliente' && $c['cliente_estado'] === null ? 'equipo' : 'cliente',
            'suspendida' => $c['cliente_estado'] === 'suspendido' || (int)$c['activo'] !== 1,
        ];
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
                $enviado = $this->despacharCorreo('recuperacion', (string)$usuario->email, (string)$usuario->nombre, $tokenPlano, $this->portalDeCuenta((int)$usuario->id));
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

    /** Portal al que pertenece la cuenta: 'equipo' solo si es laboral y no tiene membresía de cliente. */
    private function portalDeCuenta(int $usuarioId): string
    {
        try {
            $c = (new AuthorizationService($this->repository->getDb()))->contexto($usuarioId);
            return $c && $c['rol'] !== 'cliente' && $c['cliente_estado'] === null ? 'equipo' : 'cliente';
        } catch (\Throwable) {
            return 'cliente';
        }
    }

    /**
     * Despacha el correo transaccional correspondiente utilizando el dispatcher inyectado o `Classes\Email`.
     */
    private function despacharCorreo(string $tipo, string $emailDestino, string $nombre, string $tokenPlano, string $portal = 'cliente'): bool
    {
        if (is_callable($this->emailDispatcher)) {
            return (bool)call_user_func($this->emailDispatcher, $tipo, $emailDestino, $nombre, $tokenPlano);
        }

        $email = new Email($nombre, $emailDestino, $tokenPlano);
        $email->portal = $portal;
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
