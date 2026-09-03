<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Shared\Period;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;

/** Período activo en la sesión (barra de contexto, §13.3). Por defecto, el mes en curso. */
final class PeriodContext
{
    private const KEY = 'context.period';

    public function __construct(private readonly Session $session) {}

    public function current(): Period
    {
        $stored = $this->session->get(self::KEY);

        if (is_string($stored)) {
            try {
                return Period::of($stored);
            } catch (InvalidArgumentException) {
                $this->session->forget(self::KEY);
            }
        }

        return Period::current();
    }

    public function set(Period $period): void
    {
        $this->session->put(self::KEY, $period->key());
    }
}
