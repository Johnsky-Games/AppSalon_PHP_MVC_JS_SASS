<?php

namespace Model;

use Services\ServicioService;

/**
 * Entidad de dominio para un Servicio del catálogo (`servicios`).
 * Desacoplada de ActiveRecord: la validación y reglas de negocio residen en ServicioService
 * y las consultas/persistencia SQL en ServicioRepository.
 */
class Servicio
{
    public $id;
    public $nombre;
    public $precio;

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
            ? $args['nombre']
            : '';

        $this->precio = isset($args['precio']) && (is_string($args['precio']) || is_int($args['precio']) || is_float($args['precio']))
            ? (string)$args['precio']
            : '';
    }

    /**
     * Sincroniza exclusivamente los campos editables permitidos (`nombre` y `precio`)
     * a partir de entradas escalares. Ignora `id` y cualquier otro atributo para impedir
     * asignación masiva de identificadores.
     */
    public function sincronizarEditable(array $datos): void
    {
        if (array_key_exists('nombre', $datos)) {
            $this->nombre = is_string($datos['nombre']) ? trim($datos['nombre']) : '';
        }

        if (array_key_exists('precio', $datos)) {
            $rawPrecio = $datos['precio'];
            $this->precio = (is_string($rawPrecio) || is_int($rawPrecio) || is_float($rawPrecio))
                ? trim((string)$rawPrecio)
                : '';
        }
    }

    /**
     * Alias seguro que delega en sincronizarEditable(), garantizando que `id` nunca pueda
     * ser sobrescrito desde un arreglo de entrada como `$_POST`.
     */
    public function sincronizar($args = []): void
    {
        if (is_array($args)) {
            $this->sincronizarEditable($args);
        }
    }

    /**
     * Valida el estado actual de la entidad conforme a las reglas de ServicioService.
     */
    public function validar(): array
    {
        return ServicioService::validarDatos([
            'nombre' => $this->nombre,
            'precio' => $this->precio
        ]);
    }
}