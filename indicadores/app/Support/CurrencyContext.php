<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/** Moneda de presentación activa: Bs, $ o ambas (§13.3, §13.8). */
final class CurrencyContext
{
    public const BS = 'BS';

    public const USD = 'USD';

    public const BOTH = 'BOTH';

    private const KEY = 'context.currency';

    public function __construct(private readonly Session $session) {}

    public function current(): string
    {
        $stored = $this->session->get(self::KEY, self::BOTH);

        return in_array($stored, [self::BS, self::USD, self::BOTH], true) ? $stored : self::BOTH;
    }

    public function set(string $currency): void
    {
        if (in_array($currency, [self::BS, self::USD, self::BOTH], true)) {
            $this->session->put(self::KEY, $currency);
        }
    }

    public function showsBs(): bool
    {
        return $this->current() !== self::USD;
    }

    public function showsUsd(): bool
    {
        return $this->current() !== self::BS;
    }
}
