<?php

declare(strict_types=1);

namespace App\Domain\Records\Exceptions;

use DomainException;

/** Base de las violaciones de reglas de negocio sobre registros diarios (§3). Mensajes en el idioma del usuario (§13.6). */
abstract class RecordException extends DomainException {}
