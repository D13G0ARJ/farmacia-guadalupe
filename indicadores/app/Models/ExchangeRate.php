<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\BigDecimalCast;
use App\Casts\DateOnlyCast;
use App\Enums\RateSource;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tasa BCV vigente para una fecha (RN-07, RN-08). Una fila por día.
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property BigDecimal $rate
 * @property RateSource $source
 * @property CarbonImmutable|null $fetched_at
 * @property int|null $set_by
 */
#[Fillable(['date', 'rate', 'source', 'fetched_at', 'set_by'])]
class ExchangeRate extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnlyCast::class,
            'rate' => BigDecimalCast::class.':4',
            'source' => RateSource::class,
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['date', 'rate', 'source'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
