<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\BigDecimalCast;
use App\Casts\DateOnlyCast;
use App\Domain\Shared\Period;
use App\Enums\DayStatus;
use App\Enums\RateSource;
use App\Observers\DailyRecordObserver;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Factories\DailyRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Registro diario: los siete datos primarios más estado y observación.
 * Sin columnas derivadas (RN-03); la tasa es un snapshot (RN-06).
 *
 * @property int $id
 * @property int $branch_id
 * @property CarbonImmutable $date
 * @property DayStatus $status
 * @property BigDecimal $sales_bs
 * @property BigDecimal $exchange_rate
 * @property RateSource $exchange_rate_source
 * @property int $transactions
 * @property int $units
 * @property int|null $inventory_units
 * @property BigDecimal|null $inventory_value_usd
 * @property int $shifts
 * @property string|null $notes
 * @property int $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch $branch
 * @property-read User $creator
 * @property-read User|null $editor
 */
#[Fillable([
    'branch_id', 'date', 'status', 'sales_bs', 'exchange_rate', 'exchange_rate_source',
    'transactions', 'units', 'inventory_units', 'inventory_value_usd', 'shifts', 'notes',
    'created_by', 'updated_by',
])]
#[ObservedBy([DailyRecordObserver::class])]
class DailyRecord extends Model
{
    /** @use HasFactory<DailyRecordFactory> */
    use HasFactory, LogsActivity;

    /** Atributos que van a la bitácora (§5.2). */
    public const LOGGED = [
        'date', 'status', 'sales_bs', 'exchange_rate', 'exchange_rate_source',
        'transactions', 'units', 'inventory_units', 'inventory_value_usd', 'shifts', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnlyCast::class,
            'status' => DayStatus::class,
            'sales_bs' => BigDecimalCast::class.':2',
            'exchange_rate' => BigDecimalCast::class.':4',
            'exchange_rate_source' => RateSource::class,
            'transactions' => 'integer',
            'units' => 'integer',
            'inventory_units' => 'integer',
            'inventory_value_usd' => BigDecimalCast::class.':2',
            'shifts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<DailyRecord>  $query
     * @return Builder<DailyRecord>
     */
    public function scopeForPeriod(Builder $query, Period $period): Builder
    {
        return $query->whereBetween('date', [$period->start->toDateString(), $period->end()->toDateString()]);
    }

    /**
     * @param  Builder<DailyRecord>  $query
     * @return Builder<DailyRecord>
     */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $branchId === null ? $query : $query->where('branch_id', $branchId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(self::LOGGED)
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
