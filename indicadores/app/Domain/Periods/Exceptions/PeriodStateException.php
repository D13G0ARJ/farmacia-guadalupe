<?php

declare(strict_types=1);

namespace App\Domain\Periods\Exceptions;

use App\Domain\Shared\Period;
use Carbon\CarbonImmutable;
use DomainException;

/** Estado del mes incompatible con la acción pedida (RN-13, UC-07). */
final class PeriodStateException extends DomainException
{
    /** @var list<CarbonImmutable> */
    public array $missingDates = [];

    public static function alreadyClosed(Period $period): self
    {
        return new self($period->label().' ya está cerrado.');
    }

    public static function notClosed(Period $period): self
    {
        return new self($period->label().' no está cerrado.');
    }

    public static function future(Period $period): self
    {
        return new self('No se puede cerrar '.mb_strtolower($period->label()).': el mes no ha terminado de ocurrir.');
    }

    public static function empty(Period $period): self
    {
        return new self('No se puede cerrar '.mb_strtolower($period->label()).' sin ningún día cargado.');
    }

    /** @param  list<CarbonImmutable>  $missing */
    public static function missingDays(Period $period, array $missing): self
    {
        $days = implode(', ', array_map(fn (CarbonImmutable $d) => (string) $d->day, array_slice($missing, 0, 10)));
        $count = count($missing);
        $e = new self(($count === 1 ? 'Falta 1 día por cargar' : "Faltan {$count} días por cargar").' en '.mb_strtolower($period->label()).': '.$days.($count > 10 ? '…' : '').'. Confirma para cerrar de todos modos.');
        $e->missingDates = $missing;

        return $e;
    }

    public static function reasonRequired(): self
    {
        return new self('Escribe el motivo de la reapertura (al menos 5 caracteres).');
    }
}
