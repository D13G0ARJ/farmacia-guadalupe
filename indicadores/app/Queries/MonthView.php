<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\PeriodSummary;
use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use Carbon\CarbonImmutable;

/**
 * Modelo de lectura del mes para el calendario, la tabla y los totales (UC-06, UC-09).
 * En el consolidado (`branchId` null) las filas traen todas las sedes; `byDate`, `missingDates`,
 * `statusOf()` e `isClosed` solo tienen sentido con una sede.
 */
final readonly class MonthView
{
    /**
     * @param  list<DailyMetrics>  $rows  ordenadas por fecha
     * @param  array<string, DailyMetrics>  $byDate  'Y-m-d' → métricas (solo con sede)
     * @param  list<CarbonImmutable>  $missingDates  días del mes hasta hoy sin registro (solo con sede)
     */
    public function __construct(
        public Period $period,
        public ?int $branchId,
        public array $rows,
        public array $byDate,
        public PeriodSummary $summary,
        public array $missingDates,
        public bool $isClosed,
        public CarbonImmutable $today,
    ) {}

    public function has(CarbonImmutable $date): bool
    {
        return isset($this->byDate[$date->toDateString()]);
    }

    public function statusOf(CarbonImmutable $date): string
    {
        if ($date->gt($this->today)) {
            return 'future';
        }
        $metrics = $this->byDate[$date->toDateString()] ?? null;
        if ($metrics === null) {
            return 'missing';
        }

        return match ($metrics->data->status) {
            DayStatus::Normal => 'loaded',
            DayStatus::Atypical => 'atypical',
            DayStatus::Closed => 'closed',
        };
    }

    public function firstMissingDate(): ?CarbonImmutable
    {
        return $this->missingDates[0] ?? null;
    }

    public function loadedDays(): int
    {
        return count($this->rows);
    }
}
