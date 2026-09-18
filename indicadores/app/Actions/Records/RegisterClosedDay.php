<?php

declare(strict_types=1);

namespace App\Actions\Records;

use App\Domain\Rates\RateResolver;
use App\Domain\Records\Exceptions\DuplicateDayException;
use App\Domain\Records\Exceptions\InvalidRecordException;
use App\Domain\Records\Exceptions\PeriodClosedException;
use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use App\Enums\RateSource;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * UC-05 / RN-12: un día sin operación. Ceros, jornadas 0, motivo obligatorio y la tasa resuelta
 * para que la serie del mes sea continua (RN-24). Si esa fecha no tiene tasa publicada ni arrastre,
 * se acepta la que escriba el usuario (M14): la columna no admite nulos.
 */
final class RegisterClosedDay
{
    public function __construct(private readonly RateResolver $resolver) {}

    public function handle(int $branchId, CarbonImmutable $date, string $reason, User $user, ?BigDecimal $rate = null): DailyRecord
    {
        if ($date->gt(CarbonImmutable::today())) {
            throw InvalidRecordException::because(['No se puede cargar un día que no ha ocurrido.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw InvalidRecordException::because(['Indica por qué no operó ese día.']);
        }

        $period = Period::of($date);
        if (PeriodEvent::isClosed($branchId, $period)) {
            throw PeriodClosedException::for($period);
        }

        if (DailyRecord::query()->forBranch($branchId)->where('date', $date->toDateString())->exists()) {
            throw DuplicateDayException::for($date);
        }

        if ($rate !== null && ! $rate->isPositive()) {
            throw InvalidRecordException::because(['La tasa debe ser mayor que cero.']);
        }

        $resolution = $this->resolver->forDate($date);
        [$value, $source] = match (true) {
            $rate !== null && ($resolution === null || ! $resolution->rate->isEqualTo($rate)) => [$rate, RateSource::Manual],
            $resolution !== null => [$resolution->rate, $resolution->source],
            default => throw InvalidRecordException::because(['Escribe la tasa para registrar el día cerrado.']),
        };

        try {
            return DailyRecord::query()->create([
                'branch_id' => $branchId,
                'date' => $date,
                'status' => DayStatus::Closed,
                'sales_bs' => '0',
                'exchange_rate' => $value,
                'exchange_rate_source' => $source,
                'transactions' => 0,
                'units' => 0,
                'inventory_units' => null,
                'inventory_value_usd' => null,
                'shifts' => 0,
                'notes' => trim($reason),
                'created_by' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro usuario cargó ese día entre la comprobación y el guardado (A4).
            throw DuplicateDayException::for($date);
        }
    }
}
