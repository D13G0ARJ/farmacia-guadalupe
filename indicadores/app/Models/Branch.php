<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sede. El sistema es multi-sede desde el modelo (RN-22).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $legal_name
 * @property list<int> $inventory_days
 * @property int $default_shifts
 * @property string|null $sales_deviation_pct
 * @property bool $is_active
 */
#[Fillable(['name', 'code', 'legal_name', 'inventory_days', 'default_shifts', 'sales_deviation_pct', 'is_active'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /** Días ISO con conteo de inventario por defecto: todos menos sábado (H3). */
    public const DEFAULT_INVENTORY_DAYS = [1, 2, 3, 4, 5, 7];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inventory_days' => 'array',
            'default_shifts' => 'integer',
            'sales_deviation_pct' => 'string',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<DailyRecord, $this> */
    public function dailyRecords(): HasMany
    {
        return $this->hasMany(DailyRecord::class);
    }

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** @return HasMany<PeriodEvent, $this> */
    public function periodEvents(): HasMany
    {
        return $this->hasMany(PeriodEvent::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** RN-09: ¿ese día toca conteo de inventario en esta sede? */
    public function countsInventoryOn(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->inventory_days ?? self::DEFAULT_INVENTORY_DAYS, true);
    }
}
