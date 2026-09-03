<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Stringable;

/**
 * Objeto de valor: un mes calendario (§6, RN-20). Inmutable.
 */
final readonly class Period implements Stringable
{
    private function __construct(public CarbonImmutable $start) {}

    /** Acepta "2025-09", "2025-09-01" o cualquier fecha del mes. */
    public static function of(string|CarbonImmutable $value): self
    {
        if ($value instanceof CarbonImmutable) {
            return new self($value->startOfMonth()->startOfDay());
        }

        $normalized = preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? $value.'-01' : $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Período inválido: {$value}");
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $normalized)
            ?? throw new InvalidArgumentException("Período inválido: {$value}");

        return new self($date->startOfMonth()->startOfDay());
    }

    public static function current(): self
    {
        return new self(CarbonImmutable::today(config('app.timezone'))->startOfMonth());
    }

    public function end(): CarbonImmutable
    {
        return $this->start->endOfMonth()->startOfDay();
    }

    public function days(): int
    {
        return $this->start->daysInMonth;
    }

    /** Clave estable para cache y URLs: "2025-09". */
    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    /** "Septiembre 2025". */
    public function label(): string
    {
        return ucfirst($this->start->locale('es')->translatedFormat('F Y'));
    }

    /** "SEPTIEMBRE" (encabezado del Excel exportado). */
    public function monthNameUpper(): string
    {
        return mb_strtoupper($this->start->locale('es')->translatedFormat('F'));
    }

    public function previous(): self
    {
        return new self($this->start->subMonthNoOverflow());
    }

    public function next(): self
    {
        return new self($this->start->addMonthNoOverflow());
    }

    public function sameMonthLastYear(): self
    {
        return new self($this->start->subYearNoOverflow());
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->format('Y-m') === $this->key();
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /** @return list<CarbonImmutable> */
    public function dates(): array
    {
        $dates = [];
        for ($d = $this->start; $d->lte($this->end()); $d = $d->addDay()) {
            $dates[] = $d;
        }

        return $dates;
    }

    public function __toString(): string
    {
        return $this->key();
    }
}
