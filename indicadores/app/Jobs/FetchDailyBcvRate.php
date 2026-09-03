<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Rates\FetchBcvRate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Obtiene la tasa BCV para una fecha de vigencia (§9.2). Programado en routes/console.php:
 * 08:00 para hoy (respaldo) y 17:30 para el siguiente día hábil (publicación vespertina del BCV).
 */
final class FetchDailyBcvRate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly ?string $effectiveDate = null) {}

    public function uniqueId(): string
    {
        return $this->effectiveDate ?? 'today';
    }

    public function handle(FetchBcvRate $fetch): void
    {
        $date = $this->effectiveDate !== null
            ? CarbonImmutable::parse($this->effectiveDate)
            : CarbonImmutable::today();

        $fetch->handle($date->startOfDay());
    }
}
