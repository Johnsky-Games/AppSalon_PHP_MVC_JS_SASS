<?php

namespace Repositories;

use RuntimeException;

/**
 * Excepción que representa un fallo en la capa de persistencia (conexión ausente o cerrada,
 * error de preparación o fallo de ejecución SQL en MySQL).
 * Permite distinguir fallos de base de datos de catálogos vacíos o registros inexistentes.
 */
class PersistenceException extends RuntimeException
{
}
