<?php

namespace Repositories;

/**
 * La cita tiene evidencia (regularizaciones, cobros, devoluciones, comprobantes) que no puede quedar sin su cita:
 * la eliminación física se rechaza. `$motivo` es `regularizacion` o `cobros`.
 */
class CitaProtegidaException extends \RuntimeException
{
    public function __construct(public readonly string $motivo, ?\Throwable $previo = null)
    {
        parent::__construct('Cita protegida contra eliminación física: ' . $motivo, 0, $previo);
    }
}
