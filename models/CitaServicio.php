<?php

namespace Model;

/**
 * Entidad de dominio para el vínculo entre una cita y un servicio (`citasservicios`).
 * Desacoplada de ActiveRecord; persistida transaccionalmente mediante CitaRepository.
 */
class CitaServicio
{
    public $id;
    public $citaId;
    public $servicioId;
    public $nombre_servicio;
    public $precio_servicio;
    public $duracion_minutos;

    public function __construct($args = [])
    {
        if (!is_array($args)) {
            $args = [];
        }

        $rawId = $args['id'] ?? null;
        if (is_int($rawId) && $rawId > 0) {
            $this->id = (string)$rawId;
        } elseif (is_string($rawId) && ctype_digit(trim($rawId)) && (int)trim($rawId) > 0) {
            $this->id = trim($rawId);
        } else {
            $this->id = null;
        }

        $rawCitaId = $args['citaId'] ?? null;
        if (is_int($rawCitaId) && $rawCitaId > 0) {
            $this->citaId = (string)$rawCitaId;
        } elseif (is_string($rawCitaId) && ctype_digit(trim($rawCitaId)) && (int)trim($rawCitaId) > 0) {
            $this->citaId = trim($rawCitaId);
        } else {
            $this->citaId = '';
        }

        $rawServicioId = $args['servicioId'] ?? null;
        if (is_int($rawServicioId) && $rawServicioId > 0) {
            $this->servicioId = (string)$rawServicioId;
        } elseif (is_string($rawServicioId) && ctype_digit(trim($rawServicioId)) && (int)trim($rawServicioId) > 0) {
            $this->servicioId = trim($rawServicioId);
        } else {
            $this->servicioId = '';
        }

        $this->nombre_servicio = isset($args['nombre_servicio']) && is_string($args['nombre_servicio']) && trim($args['nombre_servicio']) !== ''
            ? trim($args['nombre_servicio'])
            : null;

        if (isset($args['precio_servicio']) && (is_string($args['precio_servicio']) || is_int($args['precio_servicio']) || is_float($args['precio_servicio']))) {
            $precioStr = trim((string)$args['precio_servicio']);
            $this->precio_servicio = $precioStr !== '' ? $precioStr : null;
        } else {
            $this->precio_servicio = null;
        }

        $rawDuracion = $args['duracion_minutos'] ?? null;
        if (is_int($rawDuracion) && $rawDuracion > 0) {
            $this->duracion_minutos = (string)$rawDuracion;
        } elseif (is_string($rawDuracion) && ctype_digit(trim($rawDuracion)) && (int)trim($rawDuracion) > 0) {
            $this->duracion_minutos = trim($rawDuracion);
        } else {
            $this->duracion_minutos = null;
        }
    }
}