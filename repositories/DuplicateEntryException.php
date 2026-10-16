<?php

namespace Repositories;

/**
 * Violación de una restricción UNIQUE de negocio (identidad ya existente).
 * Permite responder "ya existe" de forma controlada, sin exponer errores SQL, incluso cuando
 * dos solicitudes concurrentes superan una comprobación previa de existencia.
 */
class DuplicateEntryException extends PersistenceException
{
}
