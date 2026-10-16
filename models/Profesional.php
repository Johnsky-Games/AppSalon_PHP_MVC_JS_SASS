<?php

namespace Model;

/**
 * Entidad de dominio para un Profesional del salón (`profesionales`).
 * Desacoplada de ActiveRecord.
 */
class Profesional
{
    public $id;
    public $nombre;
    public $activo;
    public $creado_en;

    /** @var array<int, int> Identificadores de servicios asociados */
    public array $servicioIds = [];

    /** @var array<int, string> Nombres de servicios asociados (para presentación) */
    public array $serviciosNombres = [];

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

        $this->nombre = isset($args['nombre']) && is_string($args['nombre'])
            ? trim($args['nombre'])
            : '';

        if (array_key_exists('activo', $args)) {
            $rawActivo = $args['activo'];
            if ($rawActivo === true || $rawActivo === 1 || $rawActivo === '1') {
                $this->activo = '1';
            } elseif ($rawActivo === false || $rawActivo === 0 || $rawActivo === '0') {
                $this->activo = '0';
            } else {
                $this->activo = is_scalar($rawActivo) ? trim((string)$rawActivo) : '';
            }
        } else {
            $this->activo = '1';
        }

        $this->creado_en = isset($args['creado_en']) && is_string($args['creado_en'])
            ? $args['creado_en']
            : null;

        if (isset($args['servicioIds']) && is_array($args['servicioIds'])) {
            $ids = [];
            foreach ($args['servicioIds'] as $sid) {
                if (is_int($sid) && $sid > 0) {
                    $ids[] = $sid;
                } elseif (is_string($sid) && ctype_digit(trim($sid)) && (int)trim($sid) > 0) {
                    $ids[] = (int)trim($sid);
                }
            }
            $this->servicioIds = array_values(array_unique($ids));
        }
    }

    /**
     * Sincroniza exclusivamente los campos editables (`nombre` y `activo`).
     * Ignora `id` y `creado_en` para prevenir asignación masiva.
     */
    public function sincronizarEditable(array $datos): void
    {
        if (array_key_exists('nombre', $datos)) {
            $this->nombre = is_string($datos['nombre']) ? trim($datos['nombre']) : '';
        }

        if (array_key_exists('activo', $datos)) {
            $rawActivo = $datos['activo'];
            if ($rawActivo === true || $rawActivo === 1 || $rawActivo === '1') {
                $this->activo = '1';
            } elseif ($rawActivo === false || $rawActivo === 0 || $rawActivo === '0') {
                $this->activo = '0';
            } else {
                $this->activo = is_scalar($rawActivo) ? trim((string)$rawActivo) : '';
            }
        }
    }

    public function sincronizar($args = []): void
    {
        if (is_array($args)) {
            $this->sincronizarEditable($args);
        }
    }

    public function estaActivo(): bool
    {
        return (string)$this->activo === '1';
    }
}
