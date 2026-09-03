<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Support\PeriodSummaryCache;
use Carbon\CarbonImmutable;

/** Invalida la cache de agregados al guardar o borrar un registro (§4.2 principio 7). */
final class DailyRecordObserver
{
    public function __construct(private readonly PeriodSummaryCache $cache) {}

    public function saved(DailyRecord $record): void
    {
        $this->forgetFor($record);

        $originalDate = $record->getOriginal('date');
        if ($originalDate instanceof CarbonImmutable && ! $originalDate->isSameMonth($record->date)) {
            $this->cache->forget($record->branch_id, Period::of($originalDate));
        }
    }

    public function deleted(DailyRecord $record): void
    {
        $this->forgetFor($record);
    }

    private function forgetFor(DailyRecord $record): void
    {
        $this->cache->forget($record->branch_id, Period::of($record->date));
    }
}
