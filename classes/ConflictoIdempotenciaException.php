<?php

namespace Classes;

/** La misma clave de operación se reutilizó con contenido distinto: nunca se modifica el alta original. */
final class ConflictoIdempotenciaException extends \RuntimeException
{
}
