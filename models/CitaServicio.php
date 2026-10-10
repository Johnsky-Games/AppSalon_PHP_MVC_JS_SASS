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
    }
}