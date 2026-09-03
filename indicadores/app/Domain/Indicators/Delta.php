<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Variación de un indicador frente a un período de referencia (§7.2).
 * `comparedDays` indica una comparación a fecha equivalente (mes en curso incompleto).
 * `usdVariation` acompaña a los indicadores en Bs para separar crecimiento real de devaluación.
 */
final readonly class Delta
{
    public function __construct(
        public Indicator $indicator,
        public ?BigDecimal $current,
        public ?BigDecimal $previous,
        public ?BigDecimal $variation,
        public string $against,
        public ?int $comparedDays = null,
        public ?BigDecimal $usdVariation = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->variation !== null;
    }

    /** 1 sube, -1 baja, 0 sin cambio apreciable (menos de una décima de punto porcentual). */
    public function direction(): int
    {
        if ($this->variation === null) {
            return 0;
        }

        return $this->variation->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->getSign();
    }

    /** Tono visual del delta: success/danger según si "más" es mejor; neutral si no se colorea (§13.1). */
    public function tone(): string
    {
        $better = $this->indicator->moreIsBetter();
        $direction = $this->direction();

        if ($better === null || $direction === 0) {
            return 'neutral';
        }

        return ($direction > 0) === $better ? 'success' : 'danger';
    }
}
