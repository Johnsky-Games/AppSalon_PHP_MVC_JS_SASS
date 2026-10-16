<?php
namespace Services;

/** Una reasignación del lote no es válida: revierte TODO el lote (la excepción sale de la transacción idempotente). */
final class ReasignacionRechazada extends \RuntimeException
{
    public function __construct(public readonly int $citaId, public readonly string $codigoNegocio, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
