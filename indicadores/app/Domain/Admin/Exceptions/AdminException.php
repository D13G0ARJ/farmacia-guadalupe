<?php

declare(strict_types=1);

namespace App\Domain\Admin\Exceptions;

use DomainException;

/** Reglas de administración que la interfaz debe explicar, no ocultar (UC-17). */
final class AdminException extends DomainException
{
    public static function cannotDeactivateSelf(): self
    {
        return new self('No puedes desactivar tu propio acceso. Pídeselo a otro administrador.');
    }

    public static function lastAdmin(): self
    {
        return new self('Es el único administrador activo: primero nombra a otro.');
    }

    public static function cannotDemoteLastAdmin(): self
    {
        return new self('Es el único administrador activo: no puedes quitarle el rol sin nombrar a otro.');
    }

    public static function branchInUse(): self
    {
        return new self('La sede tiene días cargados: puedes desactivarla, no borrarla.');
    }

    public static function lastActiveBranch(): self
    {
        return new self('Debe quedar al menos una sede activa.');
    }
}
