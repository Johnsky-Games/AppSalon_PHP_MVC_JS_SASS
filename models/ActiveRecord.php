<?php
namespace Model;

class ActiveRecord
{
    // Base DE DATOS
    protected static $db;
    protected static $tabla = '';
    protected static $columnasDB = [];

    // Alertas y Mensajes
    protected static $alertas = [];
    public $id;

    // Definir la conexión a la BD - includes/database.php
    public static function setDB($database)
    {
        self::$db = $database;
    }

    public static function getDB()
    {
        return self::$db;
    }

    public static function setAlerta($tipo, $mensaje)
    {
        static::$alertas[$tipo][] = $mensaje;
    }

    // Validación
    public static function getAlertas()
    {
        return static::$alertas;
    }

    public function validar()
    {
        static::$alertas = [];
        return static::$alertas;
    }

    // Consulta SQL para crear un objeto en Memoria
    public static function consultarSQL($query)
    {
        if (!self::$db) {
            return [];
        }

        // Consultar la base de datos
        $resultado = self::$db->query($query);
        if (!$resultado) {
            return [];
        }

        // Iterar los resultados
        $array = [];
        while ($registro = $resultado->fetch_assoc()) {
            $array[] = static::crearObjeto($registro);
        }

        // liberar la memoria
        $resultado->free();

        // retornar los resultados
        return $array;
    }

    // Consulta SQL con Sentencias Preparadas (Previene inyección SQL)
    public static function consultarSQLPreparado(string $query, string $tipos = '', array $params = [])
    {
        if (!self::$db) {
            return [];
        }

        if (empty($params)) {
            return self::consultarSQL($query);
        }

        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $resultado = $stmt->get_result();

        $array = [];
        if ($resultado) {
            while ($registro = $resultado->fetch_assoc()) {
                $array[] = static::crearObjeto($registro);
            }
            $resultado->free();
        }
        $stmt->close();

        return $array;
    }

    // Crea el objeto en memoria que es igual al de la BD
    protected static function crearObjeto($registro)
    {
        $objeto = new static;

        foreach ($registro as $key => $value) {
            if (property_exists($objeto, $key)) {
                $objeto->$key = $value;
            }
        }

        return $objeto;
    }

    // Identificar y unir los atributos de la BD
    public function atributos()
    {
        $atributos = [];
        foreach (static::$columnasDB as $columna) {
            if ($columna === 'id') {
                continue;
            }
            $atributos[$columna] = $this->$columna;
        }
        return $atributos;
    }

    // Sanitizar los datos antes de guardarlos en la BD (Compatibilidad)
    public function sanitizarAtributos()
    {
        $atributos = $this->atributos();
        $sanitizado = [];
        foreach ($atributos as $key => $value) {
            if (!is_null($value)) {
                $sanitizado[$key] = self::$db ? self::$db->escape_string((string)$value) : (string)$value;
            } else {
                $sanitizado[$key] = null;
            }
        }
        return $sanitizado;
    }

    // Sincroniza BD con Objetos en memoria
    public function sincronizar($args = [])
    {
        foreach ($args as $key => $value) {
            if (property_exists($this, $key) && !is_null($value)) {
                $this->$key = $value;
            }
        }
    }

    // Registros - CRUD
    public function guardar()
    {
        $resultado = '';
        if (!is_null($this->id)) {
            // actualizar
            $resultado = $this->actualizar();
        } else {
            // Creando un nuevo registro
            $resultado = $this->crear();
        }
        return $resultado;
    }

    // Todos los registros
    public static function all()
    {
        $query = "SELECT * FROM " . static::$tabla;
        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    // Busca un registro por su id usando consulta preparada
    public static function find($id)
    {
        if (is_null($id) || !is_numeric($id)) {
            return null;
        }

        $query = "SELECT * FROM " . static::$tabla . " WHERE id = ? LIMIT 1";
        $resultado = self::consultarSQLPreparado($query, 'i', [(int)$id]);
        return array_shift($resultado);
    }

    // Búsqueda genérica por columna con lista blanca de columnas y consulta preparada
    public static function where($columna, $valor)
    {
        if (!in_array($columna, static::$columnasDB, true)) {
            return null;
        }

        $query = "SELECT * FROM " . static::$tabla . " WHERE {$columna} = ? LIMIT 1";
        $resultado = self::consultarSQLPreparado($query, 's', [(string)$valor]);
        return array_shift($resultado);
    }

    // Consulta plana de SQL 
    public static function SQL($query)
    {
        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    // Obtener Registros con cierta cantidad
    public static function get($limite)
    {
        if (!is_numeric($limite)) {
            return [];
        }
        $query = "SELECT * FROM " . static::$tabla . " LIMIT ?";
        $resultado = self::consultarSQLPreparado($query, 'i', [(int)$limite]);
        return array_shift($resultado);
    }

    // Crea un nuevo registro usando consultas preparadas
    public function crear()
    {
        if (!self::$db) {
            return [
                'resultado' => false,
                'id' => null
            ];
        }

        $atributos = $this->atributos();
        $columnas = array_keys($atributos);
        $placeholders = array_fill(0, count($columnas), '?');

        $query = "INSERT INTO " . static::$tabla . " (" . join(', ', $columnas) . ") VALUES (" . join(', ', $placeholders) . ")";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return [
                'resultado' => false,
                'id' => null
            ];
        }

        $tipos = str_repeat('s', count($atributos));
        $valores = array_values($atributos);
        $stmt->bind_param($tipos, ...$valores);
        $ejecutado = $stmt->execute();
        $afectadas = $stmt->affected_rows;
        $insertId = self::$db->insert_id;
        $stmt->close();

        if (!$ejecutado || $afectadas <= 0) {
            return [
                'resultado' => false,
                'id' => null
            ];
        }

        $this->id = $insertId;

        return [
            'resultado' => true,
            'id' => $insertId
        ];
    }

    // Actualizar el registro usando consultas preparadas
    public function actualizar()
    {
        if (!self::$db || is_null($this->id)) {
            return false;
        }

        $atributos = $this->atributos();
        $valores = [];
        foreach (array_keys($atributos) as $key) {
            $valores[] = "{$key} = ?";
        }

        $query = "UPDATE " . static::$tabla . " SET " . join(', ', $valores) . " WHERE id = ? LIMIT 1";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return false;
        }

        $tipos = str_repeat('s', count($atributos)) . 'i';
        $params = array_values($atributos);
        $params[] = (int)$this->id;

        $stmt->bind_param($tipos, ...$params);
        $ejecutado = $stmt->execute();
        $stmt->close();

        return $ejecutado;
    }

    // Eliminar un Registro por su ID usando consulta preparada
    public function eliminar()
    {
        if (!self::$db || is_null($this->id) || !is_numeric($this->id)) {
            return false;
        }

        $query = "DELETE FROM " . static::$tabla . " WHERE id = ? LIMIT 1";
        $stmt = self::$db->prepare($query);
        if (!$stmt) {
            return false;
        }

        $id = (int)$this->id;
        $stmt->bind_param('i', $id);
        $ejecutado = $stmt->execute();
        $afectadas = $stmt->affected_rows;
        $stmt->close();

        return $ejecutado && $afectadas > 0;
    }
}