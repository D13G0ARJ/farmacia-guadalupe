<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\BigDecimalCast;
use App\Casts\DateOnlyCast;
use App\Domain\Indicators\Indicator;
use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Meta por (sede | consolidado, indicador, mes) (RN-17).
 *
 * @property int $id
 * @property int|null $branch_id
 * @property Indicator $indicator
 * @property CarbonImmutable $period
 * @property string $period_type
 * @property BigDecimal $target
 * @property Currency $currency
 * @property int $created_by
 */
#[Fillable(['branch_id', 'indicator', 'period', 'period_type', 'target', 'currency', 'created_by'])]
class Goal extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'indicator' => Indicator::class,
            'period' => DateOnlyCast::class,
            'target' => BigDecimalCast::class.':4',
            'currency' => Currency::class,
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isConsolidated(): bool
    {
        return $this->branch_id === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['branch_id', 'indicator', 'period', 'target', 'currency'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
