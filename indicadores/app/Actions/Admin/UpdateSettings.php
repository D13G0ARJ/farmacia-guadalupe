<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guarda los parámetros globales (§4.5, UC-17) y deja en la bitácora solo lo que cambió.
 */
final class UpdateSettings
{
    /** Claves editables desde Administración, en el orden de la pantalla. */
    public const EDITABLE = [
        'sales_deviation_pct', 'rate_deviation_pct', 'operator_edit_window_days',
        'goal_growth_pct', 'goal_on_track_pct', 'goal_at_risk_pct', 'goal_currency',
        'gross_margin_pct', 'app_name',
    ];

    /**
     * @param  array<string, mixed>  $values
     * @return list<string> claves que cambiaron
     */
    public function handle(array $values, User $actor): array
    {
        return DB::transaction(function () use ($values, $actor): array {
            $changed = [];
            $old = [];
            $new = [];

            foreach (self::EDITABLE as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }
                $current = Setting::get($key);
                $value = $values[$key];
                if ($current == $value) {
                    continue;
                }
                Setting::put($key, $value);
                $changed[] = $key;
                $old[$key] = $current;
                $new[$key] = $value;
            }

            if ($changed !== []) {
                activity()
                    ->causedBy($actor)
                    ->event('settings_updated')
                    ->withProperties(['old' => $old, 'attributes' => $new])
                    ->log('settings_updated');
            }

            return $changed;
        });
    }
}
