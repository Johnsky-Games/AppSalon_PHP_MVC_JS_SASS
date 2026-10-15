<?php
namespace Services;

/** Operación sobre la atención de una cita que NO se realizó por una regla de negocio (mensaje seguro para mostrar; nada quedó a medias). */
final class AtencionRechazada extends \RuntimeException
{
    /** @param int $http 403 sin permiso · 404 no existe o no es suya · 409 el estado cambió/ya ocurrió · 422 datos inválidos */
    public function __construct(string $mensaje, private int $http = 422, ?\Throwable $previo = null)
    {
        parent::__construct($mensaje, $http, $previo);
    }

    public function http(): int { return $this->http; }
}
