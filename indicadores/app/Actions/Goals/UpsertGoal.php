<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Domain\Goals\Exceptions\InvalidGoalException;
use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Models\Goal;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Define, cambia o borra la meta de (sede | consolidado, indicador, mes) (UC-11, RN-17).
 * Una meta vacía elimina la existente. Idempotente: la clave única evita duplicados.
 */
final class UpsertGoal
{
    public function __construct(private readonly Formatter $formatter) {}

    /** @return Goal|null  null cuando la meta quedó eliminada o no existía */
    public function handle(?int $branchId, Indicator $indicator, Period $period, ?string $target, User $user): ?Goal
    {
        if (! $indicator->supportsGoal()) {
            throw InvalidGoalException::unsupported($indicator->label());
        }

        $existing = Goal::query()
            ->where('indicator', $indicator->value)
            ->where('period', $period->start->toDateString())
            ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId))
            ->first();

        $parsed = $this->formatter->parseNumber($target);
        if ($parsed === null) {
            if (trim((string) $target) !== '') {
                throw InvalidGoalException::notPositive();
            }
            $existing?->delete();

            return null;
        }

        try {
            $value = BigDecimal::of($parsed);
        } catch (MathException) {
            throw InvalidGoalException::notPositive();
        }
        if (! $value->isPositive()) {
            throw InvalidGoalException::notPositive();
        }

        if ($existing !== null) {
            $existing->fill(['target' => $value, 'currency' => $indicator->goalCurrency()])->save();

            return $existing;
        }

        return Goal::query()->create([
            'branch_id' => $branchId,
            'indicator' => $indicator,
            'period' => $period->start->toDateString(),
            'target' => $value,
            'currency' => $indicator->goalCurrency(),
            'created_by' => $user->id,
        ]);
    }
}
