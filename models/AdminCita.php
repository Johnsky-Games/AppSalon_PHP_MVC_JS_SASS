<?php

namespace Model;

/**
 * Proyección de lectura para el listado administrativo de citas por fecha.
 * Desacoplada de ActiveRecord; hidratada por CitaRepository::findAdminCitasByFecha().
 */
class AdminCita
{
    public $id;
    public $hora;
    public $cliente;
    public $email;
    public $telefono;
    public $servicio;
    public $precio;

    public function __construct($args = [])
    {
        if (!is_array($args)) {
            $args = [];
        }

        $this->id = isset($args['id']) && is_scalar($args['id']) ? (string)$args['id'] : null;
        $this->hora = isset($args['hora']) && is_string($args['hora']) ? $args['hora'] : '';
        $this->cliente = isset($args['cliente']) && is_string($args['cliente']) ? $args['cliente'] : '';
        $this->email = isset($args['email']) && is_string($args['email']) ? $args['email'] : '';
        $this->telefono = isset($args['telefono']) && is_string($args['telefono']) ? $args['telefono'] : '';
        $this->servicio = isset($args['servicio']) && is_string($args['servicio']) ? $args['servicio'] : '';
        $this->precio = isset($args['precio']) && (is_string($args['precio']) || is_int($args['precio']) || is_float($args['precio']))
            ? (string)$args['precio']
            : '';
    }
}