<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnlyCast;
use App\Domain\Shared\Period;
use App\Enums\PeriodAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Historial de cierre/reapertura de un mes (RN-13). El estado es la última fila.
 *
 * @property int $id
 * @property int $branch_id
 * @property CarbonImmutable $period
 * @property PeriodAction $action
 * @property string|null $reason
 * @property int $user_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['branch_id', 'period', 'action', 'reason', 'user_id'])]
class PeriodEvent extends Model
{
    use LogsActivity;

    public const UPDATED_AT = null;

    /** RN-14: el cierre y la reapertura quedan también en la bitácora general. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['branch_id', 'period', 'action', 'reason'])
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => DateOnlyCast::class,
            'action' => PeriodAction::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ¿Está cerrado el mes para la sede? */
    public static function isClosed(int $branchId, Period $period): bool
    {
        $last = static::query()
            ->where('branch_id', $branchId)
            ->where('period', $period->start->toDateString())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $last !== null && $last->action === PeriodAction::Closed;
    }
}
