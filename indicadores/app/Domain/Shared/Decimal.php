<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Aritmética exacta del dominio (RN-19). Escala interna 4; la presentación redondea después.
 */
final class Decimal
{
    public const SCALE = 4;

    /** División protegida: denominador cero o nulo → null (el "-" del Excel). */
    public static function divide(BigDecimal|int|null $numerator, BigDecimal|int|null $denominator, int $scale = self::SCALE): ?BigDecimal
    {
        if ($numerator === null || $denominator === null) {
            return null;
        }

        $den = BigDecimal::of($denominator);

        if ($den->isZero()) {
            return null;
        }

        return BigDecimal::of($numerator)->dividedBy($den, $scale, RoundingMode::HalfUp);
    }

    /** @param  iterable<BigDecimal|int|null>  $values */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            if ($value !== null) {
                $total = $total->plus($value);
            }
        }

        return $total;
    }

    /**
     * Promedio simple de los valores no nulos; null si no hay ninguno.
     *
     * @param  iterable<BigDecimal|int|null>  $values
     */
    public static function average(iterable $values, int $scale = self::SCALE): ?BigDecimal
    {
        $total = BigDecimal::zero();
        $count = 0;
        foreach ($values as $value) {
            if ($value !== null) {
                $total = $total->plus($value);
                $count++;
            }
        }

        return $count === 0 ? null : $total->dividedBy($count, $scale, RoundingMode::HalfUp);
    }

    /** Variación relativa `to / from − 1`; null si `from` es cero o nulo. */
    public static function variation(?BigDecimal $from, ?BigDecimal $to, int $scale = self::SCALE): ?BigDecimal
    {
        if ($from === null || $to === null || $from->isZero()) {
            return null;
        }

        return $to->dividedBy($from, $scale, RoundingMode::HalfUp)->minus(1);
    }

    public static function round(?BigDecimal $value, int $scale): ?BigDecimal
    {
        return $value?->toScale($scale, RoundingMode::HalfUp);
    }
}
