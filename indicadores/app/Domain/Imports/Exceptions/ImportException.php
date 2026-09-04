<?php

declare(strict_types=1);

namespace App\Domain\Imports\Exceptions;

use DomainException;

/** Reglas del importador que la pantalla debe explicar (§10.4). */
final class ImportException extends DomainException
{
    /** @param  list<string>  $labels */
    public static function unresolved(array $labels): self
    {
        $count = count($labels);

        return new self(($count === 1 ? 'Falta 1 anomalía por resolver: ' : "Faltan {$count} anomalías por resolver: ").implode('; ', array_slice($labels, 0, 3)).($count > 3 ? '…' : '').'.');
    }

    public static function notPending(): self
    {
        return new self('Este archivo ya se procesó.');
    }

    public static function periodClosed(string $periodLabel): self
    {
        return new self("{$periodLabel} está cerrado. Para importar sobre él, dirección debe reabrirlo desde la pantalla del mes.");
    }

    public static function failed(string $reason): self
    {
        return new self('El archivo no se pudo leer: '.$reason);
    }
}
