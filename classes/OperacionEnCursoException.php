<?php

namespace Classes;

/** Otra solicitud con la misma clave sigue procesándose; el cliente debe reintentar en unos segundos. */
final class OperacionEnCursoException extends \RuntimeException
{
}
