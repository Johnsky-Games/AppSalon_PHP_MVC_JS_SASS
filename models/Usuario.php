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
        $this->nombre = $args['nombre'] ?? '';
        $this->apellido = $args['apellido'] ?? '';
        $this->email = $args['email'] ?? '';
        $this->password = $args['password'] ?? '';
        $this->telefono = $args['telefono'] ?? '';
        $this->admin = $args['admin'] ?? "0";
        $this->confirmado = $args['confirmado'] ?? "0";
        $this->token = $args['token'] ?? '';
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
     * Retorna el token raw para ser enviado por correo/enlace.
     */
    public function generarTokenSeguro(string $tipo = 'confirmacion', int $horasExpiracion = 24): string
    {
        $tokenRaw = bin2hex(random_bytes(32));
        $this->token = $tokenRaw; // Compatibilidad temporal
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
     * Busca un usuario por token raw validando su hash, tipo y vigencia temporal.
     */
    public static function buscarPorTokenSeguro(string $tokenRaw, string $tipo): ?self
    {
        if (trim($tokenRaw) === '' || !self::$db) {
            return null;
        }

        $hash = hash('sha256', $tokenRaw);

        // 1. Buscar por token_hash y verificar expiración
        $query = "SELECT * FROM " . static::$tabla . " 
                  WHERE token_hash = ? AND token_tipo = ? AND token_expira >= NOW() 
                  LIMIT 1";
        $stmt = self::$db->prepare($query);
        if ($stmt) {
            $stmt->bind_param('ss', $hash, $tipo);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $registro = $res->fetch_assoc()) {
                $stmt->close();
                return static::crearObjeto($registro);
            }
            $stmt->close();
        }

        // 2. Fallback de compatibilidad únicamente para registros antiguos donde token_hash es NULL
        $queryLegacy = "SELECT * FROM " . static::$tabla . " WHERE token = ? AND token_hash IS NULL LIMIT 1";
        $stmtLegacy = self::$db->prepare($queryLegacy);
        if ($stmtLegacy) {
            $stmtLegacy->bind_param('s', $tokenRaw);
            $stmtLegacy->execute();
            $res = $stmtLegacy->get_result();
            if ($res && $registro = $res->fetch_assoc()) {
                $stmtLegacy->close();
                return static::crearObjeto($registro);
            }
            $stmtLegacy->close();
        }

        return null;
    }

    /**
     * Consume un token de forma atómica para evitar reutilización por solicitudes concurrentes.
     */
    public function consumirToken(): bool
    {
        if (!$this->id || !self::$db) {
            return false;
        }

        $query = "UPDATE " . static::$tabla . " 
                  SET token = NULL, token_hash = NULL, token_tipo = NULL, token_expira = NULL, confirmado = '1' 
                  WHERE id = ? LIMIT 1";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return false;
        }

        $id = (int)$this->id;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $actualizado = $stmt->affected_rows > 0;
        $stmt->close();

        if ($actualizado) {
            $this->token = null;
            $this->token_hash = null;
            $this->token_tipo = null;
            $this->token_expira = null;
            $this->confirmado = '1';
        }

        return $actualizado;
    }

    public function validarLogin()
    {
        if (!$this->email) {
            self::$alertas['error'][] = 'El email es obligatorio';
        }
        if (!$this->password) {
            self::$alertas['error'][] = 'El password es obligatorio';
        }
        return self::$alertas;
    }

    public function comprobarPasswordAndVerificado($password)
    {
        $resultado = password_verify($password, $this->password);

        if (!$resultado || (string)$this->confirmado !== "1") {
            self::$alertas['error'][] = 'Password incorrecto o la cuenta no ha sido verificada';
            return false;
        }
        return true;
    }

    public function validarEmail()
    {
        if (!$this->email) {
            self::$alertas['error'][] = 'El email es obligatorio';
        }
        return self::$alertas;
    }

    public function validarPassword()
    {
        if (!$this->password) {
            self::$alertas['error'][] = 'El password es obligatorio';
        }
        if (strlen($this->password) < 6) {
            self::$alertas['error'][] = 'El password debe tener al menos 6 caracteres';
        }
        return self::$alertas;
    }
}