<?php

namespace Model;

// Se importa la clase ActiveRecord para poder extenderla y utilizar sus métodos y propiedades en la clase Usuario 
class Usuario extends ActiveRecord
{
    // Variables de la clase Usuario
    
    // Se definen las columnas de la tabla usuarios
    protected static $tabla = 'usuarios';
    protected static $columnasDB = ['id', 'nombre', 'apellido', 'email', 'password', 'telefono', 'admin', 'confirmado', 'token'];
    
    //Se definen las propiedades de la clase Usuario
    
    public $id;
    public $nombre;
    public $apellido;
    public $email;
    public $password;
    public $telefono;
    public $admin;
    public $confirmado;
    public $token;

    // Constructor de la clase Usuario con un array de argumentos vacío por defecto para poder crear un nuevo usuario   

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = $args['nombre'] ?? '';
        $this->apellido = $args['apellido'] ?? '';
        $this->email = $args['email'] ?? '';
        $this->password = $args['password'] ?? '';
        $this->telefono = $args['telefono'] ?? '';
        $this->admin = $args['admin'] ?? 0;
        $this->confirmado = $args['confirmado'] ?? 0;
        $this->token = $args['token'] ?? '';
    }

    // Mensajes de Validación para la creación de la cuenta

    /**
     * Valida los datos de la cuenta al crear un nuevo usuario
     * @return array Alertas de error
     */
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
    
    // Método para verificar si un usuario ya existe

    /**
     * Verifica si un usuario ya existe en la base de datos
     * @return mixed Resultado de la consulta
     */
    public function existeUsuario()
    {
        $query = "SELECT * FROM " . self::$tabla . " WHERE email = '" . $this->email . "' LIMIT 1";
        $resultado = self::$db->query($query);
        if ($resultado->num_rows) {
            self::$alertas['error'][] = 'El usuario ya está registrado';
        }

        return $resultado;
    }

    /**
     * Hashea la contraseña del usuario
     */
    public function hashPassword()
    {
        $this->password = password_hash($this->password, PASSWORD_BCRYPT);
    }

    /**
     * Genera un token único para el usuario
     */
    public function generarToken()
    {
        $this->token = uniqid();
    }

    /**
     * Realiza una consulta SQL con una cláusula WHERE
     * @param string $columna Nombre de la columna
     * @param mixed $valor Valor de la columna
     * @return Usuario|null Objeto Usuario si se encuentra, null si no se encuentra
     */
    public static function where($columna, $valor)
    {
        $query = "SELECT * FROM " . static::$tabla . " WHERE $columna = " . "'$valor'";
        $resultado = self::consultarSQL($query);
        $arrayUsuario = array_shift($resultado);

        $usuario = new Usuario();

        if ($arrayUsuario) {
            foreach ($arrayUsuario as $key => $value) {
                if (property_exists($usuario, $key)) {
                    $usuario->$key = $value;
                }
            }
            return $usuario;
        } else {
            return null;
        }
    }

    /**
     * Valida los datos de inicio de sesión
     * @return array Alertas de error
     */
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

    /**
     * Comprueba si la contraseña es correcta y la cuenta está verificada
     * @param string $password Contraseña a comprobar
     * @return bool True si la contraseña es correcta y la cuenta está verificada, false de lo contrario
     */
    public function comprobarPasswordAndVerificado($password)
    {
        $resultado = password_verify($password, $this->password);

        if (!$resultado || !$this->confirmado) {
            self::$alertas['error'][] = 'Password incorrecto o la cuenta no ha sido verificada';
        } else {
            return true;
        }
        return false;
    }

    /**
     * Valida el email del usuario
     * @return array Alertas de error
     */
    public function validarEmail()
    {
        if (!$this->email) {
            self::$alertas['error'][] = 'El email es obligatorio';
        }
        return self::$alertas;
    }

    /**
     * Valida la contraseña del usuario
     * @return array Alertas de error
     */
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