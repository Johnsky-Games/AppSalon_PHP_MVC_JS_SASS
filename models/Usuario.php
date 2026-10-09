<?php

namespace Model;

class Usuario extends ActiveRecord
{
    protected static $tabla = 'usuarios';
    protected static $columnasDB = [
        'id', 'nombre', 'apellido', 'email', 'password', 'telefono', 
        'admin', 'confirmado', 'token', 'token_hash', 'token_tipo', 'token_expira'
    ];

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

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = is_string($args['nombre'] ?? null) ? trim($args['nombre']) : '';
        $this->apellido = is_string($args['apellido'] ?? null) ? trim($args['apellido']) : '';
        $this->email = is_string($args['email'] ?? null) ? trim($args['email']) : '';
        $this->password = is_string($args['password'] ?? null) ? $args['password'] : '';
        $this->telefono = is_string($args['telefono'] ?? null) ? trim($args['telefono']) : '';
        $this->admin = isset($args['admin']) && is_string($args['admin']) ? $args['admin'] : "0";
        $this->confirmado = isset($args['confirmado']) && is_string($args['confirmado']) ? $args['confirmado'] : "0";
        $this->token = is_string($args['token'] ?? null) ? $args['token'] : '';
        $this->token_hash = $args['token_hash'] ?? null;
        $this->token_tipo = $args['token_tipo'] ?? null;
        $this->token_expira = $args['token_expira'] ?? null;
    }

    /**
     * Sincroniza datos de registro público asegurando que únicamente los campos permitidos
     * sean asignados y los privilegios sean forzados desde el servidor.
     * @param array $args
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

    public function validarNuevaCuenta()
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
        if (strlen($this->password) < 6) {
            self::$alertas['error'][] = 'El password debe tener al menos 6 caracteres';
        }
        return self::$alertas;
    }

    /**
     * Verifica si un usuario ya existe en la base de datos usando consulta preparada.
     */
    public function existeUsuario()
    {
        if (!self::$db) {
            return false;
        }

        $query = "SELECT id FROM " . self::$tabla . " WHERE email = ? LIMIT 1";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return false;
        }

        $email = $this->email;
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $existe = $resultado && $resultado->num_rows > 0;
        $stmt->close();

        if ($existe) {
            self::$alertas['error'][] = 'El usuario ya está registrado';
        }

        return $existe;
    }

    public function hashPassword()
    {
        $this->password = password_hash($this->password, PASSWORD_BCRYPT);
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

    /**
     * Busca un usuario por token raw validando exclusivamente su hash SHA-256, tipo y vigencia.
     * Se elimina la aceptación indefinida de tokens antiguos en texto plano sin expiración ni propósito.
     */
    public static function buscarPorTokenSeguro(string $tokenRaw, string $tipo): ?self
    {
        if (trim($tokenRaw) === '' || !self::$db) {
            return null;
        }

        $hash = hash('sha256', $tokenRaw);

        $query = "SELECT * FROM " . static::$tabla . " 
                  WHERE token_hash = ? AND token_tipo = ? AND token_expira >= NOW() 
                  LIMIT 1";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ss', $hash, $tipo);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $res = $stmt->get_result();
        if ($res && $registro = $res->fetch_assoc()) {
            $stmt->close();
            return static::crearObjeto($registro);
        }
        $stmt->close();

        return null;
    }

    /**
     * Confirmación atómica de cuenta condicionada por hash, tipo y vigencia temporal.
     * Garantiza uso único y previene condiciones de carrera en solicitudes concurrentes.
     * Retorna true únicamente si exactamente una fila fue actualizada.
     */
    public static function confirmarCuentaPorToken(string $tokenRaw): bool
    {
        if (trim($tokenRaw) === '' || !self::$db) {
            return false;
        }

        $hash = hash('sha256', $tokenRaw);

        $query = "UPDATE " . static::$tabla . " 
                  SET confirmado = '1', token = NULL, token_hash = NULL, token_tipo = NULL, token_expira = NULL 
                  WHERE token_hash = ? AND token_tipo = 'confirmacion' AND token_expira >= NOW() 
                  LIMIT 1";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $hash);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $filas = $stmt->affected_rows;
        $stmt->close();

        return $filas === 1;
    }

    /**
     * Restablecimiento atómico de contraseña condicionado por hash, tipo y vigencia temporal.
     * Invalida el token en la misma sentencia UPDATE del nuevo password.
     * Retorna true únicamente si exactamente una fila fue actualizada.
     */
    public static function restablecerPasswordPorToken(string $tokenRaw, string $nuevoPasswordHash): bool
    {
        if (trim($tokenRaw) === '' || !self::$db) {
            return false;
        }

        $hash = hash('sha256', $tokenRaw);

        $query = "UPDATE " . static::$tabla . " 
                  SET password = ?, token = NULL, token_hash = NULL, token_tipo = NULL, token_expira = NULL 
                  WHERE token_hash = ? AND token_tipo = 'recuperacion' AND token_expira >= NOW() 
                  LIMIT 1";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $nuevoPasswordHash, $hash);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $filas = $stmt->affected_rows;
        $stmt->close();

        return $filas === 1;
    }

    /**
     * Emite y persiste de forma atómica un nuevo token de recuperación.
     * Actualiza EXCLUSIVAMENTE las columnas del token condicionado a que la cuenta esté confirmada (confirmado = '1').
     * No modifica password, rol admin ni estado de confirmación, evitando sobreescrituras en concurrencia.
     * Retorna el token raw solo si la actualización afectó exactamente una fila.
     */
    public function generarYPersistirTokenRecuperacion(int $horasExpiracion = 2): ?string
    {
        if (!$this->id || !self::$db) {
            return null;
        }

        $tokenRaw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $tokenRaw);
        $expira = date('Y-m-d H:i:s', time() + ($horasExpiracion * 3600));
        $tipo = 'recuperacion';

        $query = "UPDATE " . static::$tabla . " 
                  SET token = NULL, token_hash = ?, token_tipo = ?, token_expira = ? 
                  WHERE id = ? AND confirmado = '1' 
                  LIMIT 1";

        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return null;
        }

        $id = (int)$this->id;
        $stmt->bind_param('sssi', $hash, $tipo, $expira, $id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $filas = $stmt->affected_rows;
        $stmt->close();

        if ($filas === 1) {
            $this->token = null;
            $this->token_hash = $hash;
            $this->token_tipo = $tipo;
            $this->token_expira = $expira;
            return $tokenRaw;
        }

        return null;
    }

    /**
     * Emite y persiste de forma atómica un nuevo token de confirmación.
     * Actualiza EXCLUSIVAMENTE las columnas del token condicionado a que la cuenta NO esté confirmada (confirmado = '0').
     * No modifica password, rol admin ni estado de confirmación, evitando sobreescrituras en concurrencia.
     * Retorna el token raw solo si la actualización afectó exactamente una fila.
     */
    public function generarYPersistirTokenConfirmacion(int $horasExpiracion = 24): ?string
    {
        if (!$this->id || !self::$db) {
            return null;
        }

        $tokenRaw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $tokenRaw);
        $expira = date('Y-m-d H:i:s', time() + ($horasExpiracion * 3600));
        $tipo = 'confirmacion';

        $query = "UPDATE " . static::$tabla . " 
                  SET token = NULL, token_hash = ?, token_tipo = ?, token_expira = ? 
                  WHERE id = ? AND confirmado = '0' 
                  LIMIT 1";

        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return null;
        }

        $id = (int)$this->id;
        $stmt->bind_param('sssi', $hash, $tipo, $expira, $id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $filas = $stmt->affected_rows;
        $stmt->close();

        if ($filas === 1) {
            $this->token = null;
            $this->token_hash = $hash;
            $this->token_tipo = $tipo;
            $this->token_expira = $expira;
            return $tokenRaw;
        }

        return null;
    }

    public function validarLogin()
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

    /**
     * Observador para instrumentación y auditoría de llamadas reales al verificador de contraseña.
     * Permite verificar invocaciones reales sin alterar, sustituir ni omitir la lógica de password_verify.
     * @var callable|null
     */
    public static $observadorVerificacionPassword = null;

    public function comprobarPasswordAndVerificado($password)
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

    public function validarEmail()
    {
        self::$alertas = [];
        if (!$this->email || !is_string($this->email)) {
            self::$alertas['error'][] = 'El email es obligatorio';
        }
        return self::$alertas;
    }

    public function validarPassword()
    {
        self::$alertas = [];
        if (!$this->password || !is_string($this->password)) {
            self::$alertas['error'][] = 'El password es obligatorio';
        } elseif (strlen($this->password) < 6) {
            self::$alertas['error'][] = 'El password debe tener al menos 6 caracteres';
        }
        return self::$alertas;
    }
}