<?php

declare(strict_types=1);

namespace App\Domain\Records;

/** Advertencia blanda (RN-16): no bloquea, exige un clic consciente (§13.5). */
final readonly class Warning
{
    public function __construct(
        public string $code,
        public string $field,
        public string $message,
    ) {}
}
