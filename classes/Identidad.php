<?php

namespace Classes;

/** Reglas únicas de normalización de identidad, compartidas por registro, invitación y búsqueda. */
final class Identidad
{
    /**
     * Normaliza un correo para comparar identidades: recorta espacios y pasa a minúsculas.
     * NO elimina puntos ni sufijos "+": son direcciones distintas según el proveedor y suprimirlas
     * fusionaría personas diferentes.
     */
    public static function normalizarEmail(string $correo): string
    {
        return mb_strtolower(trim($correo), 'UTF-8');
    }
}
