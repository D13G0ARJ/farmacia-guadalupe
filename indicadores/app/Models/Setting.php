<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Parámetro global (branch_id null) o por sede (§4.5). El valor es JSON.
 */
#[Fillable(['key', 'branch_id', 'value'])]
class Setting extends Model
{
    /** Valores por defecto del sistema (§4.5). */
    public const DEFAULTS = [
        'sales_deviation_pct' => 35,
        'rate_deviation_pct' => 10,
        'goal_on_track_pct' => 100,
        'goal_at_risk_pct' => 90,
        'goal_currency' => 'USD',
        'goal_growth_pct' => 5,
        'operator_edit_window_days' => 7,
        'gross_margin_pct' => null,
        'app_name' => 'Indicadores · Farmacia Guadalupe',
        // Correo (§11.2, §13.8): día del reporte mensual (0 = apagado), destinatarios y recordatorio de cierre.
        'report_email_day' => 0,
        'report_recipients' => '',
        'close_reminder_enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** Lee la clave para una sede, con caída al valor global y luego al defecto. */
    public static function get(string $key, ?int $branchId = null): mixed
    {
        $rows = static::query()
            ->where('key', $key)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId !== null, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->get()
            ->keyBy(fn (self $s) => $s->branch_id ?? 0);

        if ($branchId !== null && $rows->has($branchId)) {
            return $rows[$branchId]->value;
        }

        if ($rows->has(0)) {
            return $rows[0]->value;
        }

        return self::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value, ?int $branchId = null): self
    {
        return static::query()->updateOrCreate(['key' => $key, 'branch_id' => $branchId], ['value' => $value]);
    }

    /** Borra la fila: la lectura vuelve al valor global o al defecto. */
    public static function forget(string $key, ?int $branchId = null): void
    {
        static::query()->where('key', $key)->where('branch_id', $branchId)->delete();
    }
}
