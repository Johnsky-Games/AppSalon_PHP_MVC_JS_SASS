<?php

namespace Model;

/**
 * Entidad de dominio para una franja de horario semanal de un profesional (`horarios_profesionales`).
 * Desacoplada de ActiveRecord.
 * Convención de `dia_semana`: ISO-8601 (1 = Lunes ... 7 = Domingo).
 */
class HorarioProfesional
{
    public $id;
    public $profesionalId;
    public int $dia_semana;
    public string $hora_inicio;
    public string $hora_fin;

    public const DIAS_SEMANA = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo'
    ];

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

        $rawDia = $args['dia_semana'] ?? 1;
        $this->dia_semana = (is_int($rawDia) || (is_string($rawDia) && preg_match('/^-?\d+$/', trim($rawDia)) === 1))
            ? (int)$rawDia
            : 0;

        $this->hora_inicio = self::normalizarHora(is_string($args['hora_inicio'] ?? null) ? $args['hora_inicio'] : '');
        $this->hora_fin = self::normalizarHora(is_string($args['hora_fin'] ?? null) ? $args['hora_fin'] : '');
    }

    public static function normalizarHora(string $hora): string
    {
        $trimmed = trim($hora);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d):00$/', $trimmed, $m) === 1) {
            return $m[1] . ':' . $m[2];
        }
        return $trimmed;
    }

    public function nombreDia(): string
    {
        return self::DIAS_SEMANA[$this->dia_semana] ?? 'Desconocido';
    }
}
