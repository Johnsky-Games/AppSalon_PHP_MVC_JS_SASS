<?php

namespace Model;

/**
 * Entidad de dominio para una Cita (`citas`).
 * Desacoplada de ActiveRecord; la validación y reglas de negocio residen en CitaService
 * y las consultas/persistencia transaccional en CitaRepository.
 */
class Cita
{
    public $id;
    public $fecha;
    public $hora;
    public $hora_inicio;
    public $hora_fin;
    public $duracion_total_minutos;
    public $usuarioId;
    public $profesionalId;

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

        $this->fecha = isset($args['fecha']) && is_string($args['fecha'])
            ? trim($args['fecha'])
            : '';

        $this->hora = isset($args['hora']) && is_string($args['hora'])
            ? trim($args['hora'])
            : '';

        $this->hora_inicio = isset($args['hora_inicio']) && is_string($args['hora_inicio']) && trim($args['hora_inicio']) !== ''
            ? trim($args['hora_inicio'])
            : null;

        $this->hora_fin = isset($args['hora_fin']) && is_string($args['hora_fin']) && trim($args['hora_fin']) !== ''
            ? trim($args['hora_fin'])
            : null;

        $rawDuracion = $args['duracion_total_minutos'] ?? null;
        if (is_int($rawDuracion) && $rawDuracion > 0) {
            $this->duracion_total_minutos = (string)$rawDuracion;
        } elseif (is_string($rawDuracion) && ctype_digit(trim($rawDuracion)) && (int)trim($rawDuracion) > 0) {
            $this->duracion_total_minutos = trim($rawDuracion);
        } else {
            $this->duracion_total_minutos = null;
        }

        $rawUsuarioId = $args['usuarioId'] ?? null;
        if (is_int($rawUsuarioId) && $rawUsuarioId > 0) {
            $this->usuarioId = (string)$rawUsuarioId;
        } elseif (is_string($rawUsuarioId) && ctype_digit(trim($rawUsuarioId)) && (int)trim($rawUsuarioId) > 0) {
            $this->usuarioId = trim($rawUsuarioId);
        } else {
            $this->usuarioId = '';
        }

        $rawProfId = $args['profesionalId'] ?? null;
        if (is_int($rawProfId) && $rawProfId > 0) {
            $this->profesionalId = (string)$rawProfId;
        } elseif (is_string($rawProfId) && ctype_digit(trim($rawProfId)) && (int)trim($rawProfId) > 0) {
            $this->profesionalId = trim($rawProfId);
        } else {
            $this->profesionalId = null;
        }
    }

    /**
     * Sincroniza únicamente los campos editables de una cita (`fecha` y `hora`).
     * Nunca permite modificar `id` ni `usuarioId`.
     */
    public function sincronizarEditable(array $args = []): void
    {
        if (array_key_exists('fecha', $args)) {
            $this->fecha = is_string($args['fecha']) ? trim($args['fecha']) : '';
        }

        if (array_key_exists('hora', $args)) {
            $this->hora = is_string($args['hora']) ? trim($args['hora']) : '';
        }
    }

    public function sincronizar($args = []): void
    {
        if (!is_array($args)) {
            return;
        }
        $this->sincronizarEditable($args);
    }
}