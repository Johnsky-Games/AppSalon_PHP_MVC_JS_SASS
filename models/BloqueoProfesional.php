<?php

namespace Model;

/**
 * Entidad de dominio para un bloqueo de agenda por fecha completa o intervalo (`bloqueos_profesionales`).
 * Desacoplada de ActiveRecord.
 */
class BloqueoProfesional
{
    public $id;
    public $profesionalId;
    public string $fecha_inicio;
    public string $fecha_fin;
    public ?string $hora_inicio;
    public ?string $hora_fin;
    public ?string $motivo;

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

        $rawProfId = $args['profesionalId'] ?? null;
        if (is_int($rawProfId) && $rawProfId > 0) {
            $this->profesionalId = (string)$rawProfId;
        } elseif (is_string($rawProfId) && ctype_digit(trim($rawProfId)) && (int)trim($rawProfId) > 0) {
            $this->profesionalId = trim($rawProfId);
        } else {
            $this->profesionalId = null;
        }

        $fechaUnica = isset($args['fecha']) && is_string($args['fecha']) ? trim($args['fecha']) : '';
        $this->fecha_inicio = isset($args['fecha_inicio']) && is_string($args['fecha_inicio']) && trim($args['fecha_inicio']) !== ''
            ? trim($args['fecha_inicio'])
            : $fechaUnica;
        $this->fecha_fin = isset($args['fecha_fin']) && is_string($args['fecha_fin']) && trim($args['fecha_fin']) !== ''
            ? trim($args['fecha_fin'])
            : ($this->fecha_inicio !== '' ? $this->fecha_inicio : $fechaUnica);

        $rawHoraInicio = isset($args['hora_inicio']) && is_string($args['hora_inicio']) ? trim($args['hora_inicio']) : '';
        $rawHoraFin = isset($args['hora_fin']) && is_string($args['hora_fin']) ? trim($args['hora_fin']) : '';

        $this->hora_inicio = $rawHoraInicio !== '' ? HorarioProfesional::normalizarHora($rawHoraInicio) : null;
        $this->hora_fin = $rawHoraFin !== '' ? HorarioProfesional::normalizarHora($rawHoraFin) : null;

        if (isset($args['motivo']) && is_string($args['motivo']) && trim($args['motivo']) !== '') {
            $this->motivo = trim($args['motivo']);
        } else {
            $this->motivo = null;
        }
    }

    public function esDiaCompleto(): bool
    {
        return $this->hora_inicio === null && $this->hora_fin === null;
    }
}
