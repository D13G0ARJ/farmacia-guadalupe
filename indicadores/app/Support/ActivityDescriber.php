<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\Goal;
use App\Models\PeriodEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Traduce una entrada de la bitácora a una frase legible (UC-17): quién, qué hizo, a qué, y los
 * cambios campo por campo con etiquetas del negocio, nunca nombres de columnas.
 */
final class ActivityDescriber
{
    private const LABELS = [
        'date' => 'Fecha', 'status' => 'Estado', 'sales_bs' => 'Venta Bs', 'exchange_rate' => 'Tasa',
        'exchange_rate_source' => 'Origen de la tasa', 'transactions' => 'Transacciones', 'units' => 'Unidades',
        'inventory_units' => 'Inventario (und.)', 'inventory_value_usd' => 'Valuación ($)', 'shifts' => 'Jornadas',
        'notes' => 'Observación', 'rate' => 'Tasa', 'source' => 'Origen', 'target' => 'Meta', 'currency' => 'Moneda',
        'indicator' => 'Indicador', 'period' => 'Mes', 'action' => 'Acción', 'reason' => 'Motivo', 'role' => 'Rol',
        'branch_ids' => 'Sedes', 'name' => 'Nombre', 'email' => 'Correo', 'is_active' => 'Activo', 'code' => 'Código',
        'default_shifts' => 'Jornadas por defecto', 'inventory_days' => 'Días de inventario',
        'sales_deviation_pct' => 'Umbral de venta (%)', 'rate_deviation_pct' => 'Umbral de tasa (%)',
        'operator_edit_window_days' => 'Ventana del operador (días)', 'goal_growth_pct' => 'Crecimiento sugerido (%)',
        'goal_on_track_pct' => 'En meta (%)', 'goal_at_risk_pct' => 'En riesgo (%)', 'goal_currency' => 'Moneda de metas',
        'gross_margin_pct' => 'Margen bruto (%)', 'app_name' => 'Nombre del sistema',
    ];

    private const VALUES = [
        'normal' => 'Normal', 'atypical' => 'Atípico', 'closed' => 'Cerrado', 'reopened' => 'Reabierto',
        'bcv' => 'BCV', 'manual' => 'Manual', 'carried' => 'Arrastrada', 'USD' => '$', 'BS' => 'Bs',
    ];

    private const WEEKDAYS = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    /** @var Collection<int, string>|null nombres de sede por id */
    private ?Collection $branchNames = null;

    public function __construct(private readonly Formatter $formatter) {}

    /**
     * @return array{when: string, who: string, verb: string, subject: string, changes: list<array{label: string, old: string, new: string}>}
     */
    public function describe(Activity $activity): array
    {
        $props = $activity->properties;
        $attributes = (array) ($props['attributes'] ?? []);
        $old = (array) ($props['old'] ?? []);
        $event = (string) ($activity->event ?? $activity->description ?? '');

        [$verb, $subject] = $this->verbAndSubject($activity, $event, $attributes, $old);

        $changes = [];
        foreach ($attributes as $key => $new) {
            if (in_array($key, ['undone_at'], true)) {
                continue;
            }
            $before = $old[$key] ?? null;
            if ($event === 'updated' && $before == $new) {
                continue;
            }
            $changes[] = ['label' => self::LABELS[$key] ?? (string) $key, 'old' => $this->value($key, $before), 'new' => $this->value($key, $new)];
        }
        if ($event === 'deleted' && $attributes === [] && $old !== []) {
            foreach (['date', 'sales_bs', 'transactions', 'units'] as $key) {
                if (array_key_exists($key, $old)) {
                    $changes[] = ['label' => self::LABELS[$key], 'old' => $this->value($key, $old[$key]), 'new' => '—'];
                }
            }
        }

        $created = $activity->created_at ?? CarbonImmutable::now();

        return [
            'when' => $this->formatter->date($created, 'weekday_full').' '.$created->format('H:i'),
            'who' => $activity->causer?->getAttribute('name') ?? 'Sistema',
            'verb' => $verb,
            'subject' => $subject,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     * @return array{0: string, 1: string}
     */
    private function verbAndSubject(Activity $activity, string $event, array $attributes, array $old): array
    {
        $subject = $activity->subject;
        $type = (string) $activity->subject_type;
        $verb = match ($event) {
            'created' => 'creó',
            'updated' => 'editó',
            'deleted' => 'borró',
            'activated' => 'activó el acceso de',
            'deactivated' => 'desactivó el acceso de',
            'password_reset' => 'cambió la contraseña de',
            'settings_updated' => 'cambió',
            default => $event,
        };

        $what = match ($type) {
            DailyRecord::class => 'el día '.$this->date($subject?->getAttribute('date') ?? $attributes['date'] ?? $old['date'] ?? null),
            ExchangeRate::class => 'la tasa del '.$this->date($subject?->getAttribute('date') ?? $attributes['date'] ?? $old['date'] ?? null),
            Goal::class => $this->goal($subject, $attributes, $old),
            PeriodEvent::class => 'el mes '.$this->period($subject?->getAttribute('period') ?? $attributes['period'] ?? null),
            User::class => (string) ($subject?->getAttribute('name') ?? $attributes['name'] ?? 'un usuario'),
            Branch::class => 'la sede '.($subject?->getAttribute('name') ?? $attributes['name'] ?? ''),
            default => $event === 'settings_updated' ? 'los parámetros' : 'un registro',
        };

        if ($type === PeriodEvent::class) {
            $action = $attributes['action'] ?? $subject?->getAttribute('action')->value ?? null;
            $verb = $action === 'reopened' ? 'reabrió' : 'cerró';
        }
        if ($type === User::class && in_array($event, ['created', 'updated', 'deleted'], true)) {
            $what = 'al usuario '.$what;
        }

        return [$verb, $what];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     */
    private function goal(?object $subject, array $attributes, array $old): string
    {
        $indicator = $subject?->getAttribute('indicator') ?? $attributes['indicator'] ?? $old['indicator'] ?? null;
        $label = $indicator instanceof Indicator ? $indicator->label() : (Indicator::tryFrom((string) $indicator)?->label() ?? 'indicador');
        $period = $subject?->getAttribute('period') ?? $attributes['period'] ?? $old['period'] ?? null;

        return 'la meta de '.mb_strtolower($label).' de '.$this->period($period);
    }

    private function date(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        try {
            $date = $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return (string) $value;
        }

        return $this->formatter->date($date, 'short');
    }

    private function period(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        try {
            return mb_strtolower(Period::of($value instanceof CarbonImmutable ? $value : CarbonImmutable::parse((string) $value))->label());
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function value(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if ($key === 'branch_ids' && is_array($value)) {
            $names = $this->branchNames();

            return $value === [] ? 'todas' : implode(', ', array_map(fn ($id) => $names[(int) $id] ?? "#{$id}", $value));
        }
        if ($key === 'inventory_days' && is_array($value)) {
            return implode(', ', array_map(fn ($d) => self::WEEKDAYS[(int) $d] ?? (string) $d, $value));
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $value));
        }
        if ($key === 'role') {
            return Role::tryFrom((string) $value)?->label() ?? (string) $value;
        }
        if ($key === 'indicator') {
            return Indicator::tryFrom((string) $value)?->label() ?? (string) $value;
        }
        if (in_array($key, ['date'], true)) {
            return $this->date($value);
        }
        if ($key === 'period') {
            return $this->period($value);
        }
        $string = is_object($value) && property_exists($value, 'value') ? (string) $value->value : (string) $value;

        return self::VALUES[$string] ?? $string;
    }

    /** @return Collection<int, string> */
    private function branchNames(): Collection
    {
        return $this->branchNames ??= Branch::query()->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name]);
    }
}
