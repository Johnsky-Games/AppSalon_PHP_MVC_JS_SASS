<?php
namespace Services;

/** Operación financiera que NO se realizó por una regla de negocio (el mensaje es seguro para mostrar). Nada quedó a medias. */
final class CobroRechazado extends \RuntimeException
{
    /** @param int $http 403 sin permiso para esa acción · 404 no existe · 409 el estado cambió/ya ocurrió · 422 datos inválidos */
    public function __construct(string $mensaje, private int $http = 422, ?\Throwable $previo = null)
    {
        parent::__construct($mensaje, $http, $previo);
    }

    public function http(): int { return $this->http; }
}
