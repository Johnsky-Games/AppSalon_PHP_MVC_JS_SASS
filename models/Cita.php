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
    public $usuarioId;

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

        $rawUsuarioId = $args['usuarioId'] ?? null;
        if (is_int($rawUsuarioId) && $rawUsuarioId > 0) {
            $this->usuarioId = (string)$rawUsuarioId;
        } elseif (is_string($rawUsuarioId) && ctype_digit(trim($rawUsuarioId)) && (int)trim($rawUsuarioId) > 0) {
            $this->usuarioId = trim($rawUsuarioId);
        } else {
            $this->usuarioId = '';
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