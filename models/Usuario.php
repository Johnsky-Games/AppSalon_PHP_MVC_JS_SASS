<?php

namespace Model;

/**
 * Entidad de dominio para un Usuario (`usuarios`).
 * Desacoplada de ActiveRecord: las consultas y escrituras SQL residen en UsuarioRepository
 * y las reglas de negocio de registro, autenticación, confirmación y recuperación en AuthService.
 */
class Usuario
{
    protected static array $alertas = [];

    public $id;
    public $nombre;
    public $apellido;
    public $email;
    public $password;
    public $telefono;
    public $admin;
    public $confirmado;
    public $token;
    public $token_hash;
    public $token_tipo;
    public $token_expira;

    /**
     * Observador para instrumentación y auditoría de llamadas reales al verificador de contraseña.
     * Permite verificar invocaciones reales sin alterar, sustituir ni omitir la lógica de password_verify.
     * @var callable|null
     */
    public static $observadorVerificacionPassword = null;

    public function __construct($args = [])
    {
        if (!is_array($args)) {
            $args = [];
        }

        $this->id = $args['id'] ?? null;
        $this->nombre = is_string($args['nombre'] ?? null) ? trim($args['nombre']) : '';
        $this->apellido = is_string($args['apellido'] ?? null) ? trim($args['apellido']) : '';
        $this->email = is_string($args['email'] ?? null) ? trim($args['email']) : '';
        $this->password = is_string($args['password'] ?? null) ? $args['password'] : '';
        $this->telefono = is_string($args['telefono'] ?? null) ? trim($args['telefono']) : '';
        $this->admin = isset($args['admin']) && (is_string($args['admin']) || is_int($args['admin'])) ? (string)$args['admin'] : "0";
        $this->confirmado = isset($args['confirmado']) && (is_string($args['confirmado']) || is_int($args['confirmado'])) ? (string)$args['confirmado'] : "0";
        $this->token = is_string($args['token'] ?? null) ? $args['token'] : '';
        $this->token_hash = $args['token_hash'] ?? null;
        $this->token_tipo = $args['token_tipo'] ?? null;
        $this->token_expira = $args['token_expira'] ?? null;
    }

    public static function setAlerta($tipo, $mensaje): void
    {
        static::$alertas[$tipo][] = $mensaje;
    }

    public static function getAlertas(): array
    {
        return static::$alertas;
    }

    public static function limpiarAlertas(): void
    {
        static::$alertas = [];
    }

    /**
     * Sincroniza datos de registro público asegurando que únicamente los campos permitidos
     * sean asignados y los privilegios sean forzados desde el servidor.
     */
    public function sincronizarRegistro(array $args = []): void
    {
        $permitidos = ['nombre', 'apellido', 'email', 'password', 'telefono'];
        foreach ($permitidos as $campo) {
            if (isset($args[$campo]) && is_string($args[$campo])) {
                $this->$campo = trim($args[$campo]);
            }
        }
        // Inmutables desde el cliente:
        $this->admin = "0";
        $this->confirmado = "0";
        $this->id = null;
        $this->token = '';
        $this->token_hash = null;
        $this->token_tipo = null;
        $this->token_expira = null;
    }

    /**
     * Alias seguro que delega en sincronizarRegistro() para garantizar que
     * `id`, `admin`, `confirmado` y campos de token jamás puedan sobrescribirse desde formularios.
     */
    public function sincronizar($args = []): void
    {
        if (is_array($args)) {
            $this->sincronizarRegistro($args);
        }
    }

    public function validarNuevaCuenta(): array
    {
        self::$alertas = [];
        if (!$this->nombre) {
            self::$alertas['error'][] = 'Debes añadir un nombre';
        }
        if (!$this->apellido) {
            self::$alertas['error'][] = 'Debes añadir un apellido';
        }
        if (!$this->telefono) {
            self::$alertas['error'][] = 'El teléfono es obligatorio';
        }
        if (!$this->email) {
            self::$alertas['error'][] = 'El email es obligatorio';
        }
        if (!$this->password) {
            self::$alertas['error'][] = 'El password es obligatorio';
        }
        if (strlen((string)$this->password) < 6) {
            self::$alertas['error'][] = 'El password debe tener al menos 6 caracteres';
        }
        return self::$alertas;
    }

    public function validarLogin(): array
    {
        self::$alertas = [];
        if (!$this->email) {
            self::$alertas['error'][] = 'El email es obligatorio';
        }
        if (!$this->password) {
            self::$alertas['error'][] = 'El password es obligatorio';
        }
        return self::$alertas;
    }

    public function validarEmail(): array
    {
        self::$alertas = [];
        if (!$this->email || !is_string($this->email)) {
            self::$alertas['error'][] = 'El email es obligatorio';
        }
        return self::$alertas;
    }

    public function validarPassword(): array
    {
        self::$alertas = [];
        if (!$this->password || !is_string($this->password)) {
            self::$alertas['error'][] = 'El password es obligatorio';
        } elseif (strlen($this->password) < 6) {
            self::$alertas['error'][] = 'El password debe tener al menos 6 caracteres';
        }
        return self::$alertas;
    }

    public function hashPassword(): void
    {
        $this->password = password_hash((string)$this->password, PASSWORD_BCRYPT);
    }

    /**
     * Genera un token criptográfico seguro con hash SHA-256, tipo y fecha de expiración.
     * Retorna el token raw exclusivamente para el envío seguro de correo/enlace.
     * El token original en texto plano NUNCA se persiste en la base de datos.
     */
    public function generarTokenSeguro(string $tipo = 'confirmacion', int $horasExpiracion = 24): string
    {
        $tokenRaw = bin2hex(random_bytes(32));
        $this->token = null; // No almacenar token en texto plano
        $this->token_hash = hash('sha256', $tokenRaw);
        $this->token_tipo = $tipo;
        $this->token_expira = date('Y-m-d H:i:s', time() + ($horasExpiracion * 3600));

        return $tokenRaw;
    }

    /**
     * Compatibilidad hacia atrás con el método generarToken()
     */
    public function generarToken()
    {
        return $this->generarTokenSeguro('confirmacion', 24);
    }

    public function comprobarPasswordAndVerificado($password): bool
    {
        if (is_callable(self::$observadorVerificacionPassword)) {
            call_user_func(self::$observadorVerificacionPassword, $this->email, $this->confirmado);
        }

        if (!is_string($password) || $password === '' || !is_string($this->password) || $this->password === '') {
            self::$alertas['error'][] = 'Password incorrecto o la cuenta no ha sido verificada';
            return false;
        }

        $resultado = password_verify($password, $this->password);

        if (!$resultado || (string)$this->confirmado !== "1") {
            self::$alertas['error'][] = 'Password incorrecto o la cuenta no ha sido verificada';
            return false;
        }
        return true;
    }
}