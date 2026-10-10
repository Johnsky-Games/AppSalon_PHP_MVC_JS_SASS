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
    public $hora_inicio;
    public $hora_fin;
    public $duracion_total_minutos;
    public $profesionalId;
    public $profesional_nombre;
    public $cliente;
    public $email;
    public $telefono;
    public $servicio;
    public $precio;
    public $duracion_minutos;

    public function __construct($args = [])
    {
        if (!is_array($args)) {
            $args = [];
        }

        $this->id = isset($args['id']) && is_scalar($args['id']) ? (string)$args['id'] : null;
        $this->hora = isset($args['hora']) && is_string($args['hora']) ? $args['hora'] : '';
        $this->hora_inicio = isset($args['hora_inicio']) && is_string($args['hora_inicio']) ? $args['hora_inicio'] : null;
        $this->hora_fin = isset($args['hora_fin']) && is_string($args['hora_fin']) ? $args['hora_fin'] : null;
        $this->duracion_total_minutos = isset($args['duracion_total_minutos']) && is_scalar($args['duracion_total_minutos'])
            ? (string)$args['duracion_total_minutos']
            : null;
        $this->profesionalId = isset($args['profesionalId']) && is_scalar($args['profesionalId'])
            ? (string)$args['profesionalId']
            : null;
        $this->profesional_nombre = isset($args['profesional_nombre']) && is_string($args['profesional_nombre']) && trim($args['profesional_nombre']) !== ''
            ? trim($args['profesional_nombre'])
            : null;
        $this->cliente = isset($args['cliente']) && is_string($args['cliente']) ? $args['cliente'] : '';
        $this->email = isset($args['email']) && is_string($args['email']) ? $args['email'] : '';
        $this->telefono = isset($args['telefono']) && is_string($args['telefono']) ? $args['telefono'] : '';
        $this->servicio = isset($args['servicio']) && is_string($args['servicio']) ? $args['servicio'] : '';
        $this->precio = isset($args['precio']) && (is_string($args['precio']) || is_int($args['precio']) || is_float($args['precio']))
            ? (string)$args['precio']
            : '';
        $this->duracion_minutos = isset($args['duracion_minutos']) && is_scalar($args['duracion_minutos'])
            ? (string)$args['duracion_minutos']
            : null;
    }
}