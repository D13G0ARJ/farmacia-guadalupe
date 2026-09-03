<?php

declare(strict_types=1);

namespace App\Casts;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cast exacto para montos y tasas (§5.4, RN-19).
 *
 * Nunca pasa por float: lee la columna como string y entrega BigDecimal; al escribir
 * normaliza a la escala de la columna. Uso: 'sales_bs' => BigDecimalCast::class.':2'.
 *
 * @implements CastsAttributes<BigDecimal|null, BigDecimal|string|int|float|null>
 */
final class BigDecimalCast implements CastsAttributes
{
    /** Sin cache de objeto: el valor leído siempre pasa por `get()` y sale normalizado a la escala. */
    public bool $withoutObjectCaching = true;

    public function __construct(private readonly int $scale = 2) {}

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        if ($value === null || $value === '') {
            return null;
        }

        return BigDecimal::of((string) $value)->toScale($this->scale, RoundingMode::HalfUp);
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException("El atributo {$key} no admite float: use string o BigDecimal.");
        }

        return (string) BigDecimal::of($value instanceof BigDecimal ? $value : (string) $value)
            ->toScale($this->scale, RoundingMode::HalfUp);
    }
}
