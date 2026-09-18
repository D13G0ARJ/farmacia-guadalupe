<?php

declare(strict_types=1);

namespace App\Livewire\Rates;

use App\Actions\Rates\BackfillBcvRates;
use App\Actions\Rates\FetchBcvRate;
use App\Actions\Rates\RecalculateMonthRates;
use App\Actions\Rates\UpsertExchangeRate;
use App\Domain\Charts\ChartSpecBuilder;
use App\Domain\Periods\Exceptions\PeriodStateException;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\Permission;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\PeriodEvent;
use App\Models\Setting;
use App\Queries\RatesMonthQuery;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * UC-16 (§9.4): tabla mensual de tasas con edición en línea, gráfica, estado del proveedor,
 * consulta inmediata al BCV y recálculo de los días del mes con confirmación (RN-06).
 */
#[Layout('layouts.app')]
#[Title('Tasa BCV')]
class RatesPage extends Component
{
    /** Hora (America/Caracas) a partir de la cual la cotización publicada rige el siguiente día hábil (§9.2). */
    private const NEXT_DAY_HOUR = 17;

    /** Desde la pantalla el histórico llega hasta aquí; más atrás es trabajo del comando (M5). */
    private const BACKFILL_MAX_MONTHS = 36;

    public string $period = '';

    /** Solo lo fija `startEdit` (B17): un payload manipulado no puede escribir una fecha cualquiera. */
    #[Locked]
    public ?string $editingDate = null;

    public string $editValue = '';

    /** El usuario ya vio el aviso de desvío y volvió a guardar. */
    public bool $deviationConfirmed = false;

    /** Tope de la columna decimal(12,4). */
    private const MAX_RATE = '99999999.9999';

    public bool $recalcDialog = false;

    public bool $backfillDialog = false;

    /** Desde cuándo traer el histórico del BCV (AAAA-MM-DD). */
    public string $backfillFrom = '2025-01-01';

    /** @var array<string, array<string, mixed>> */
    public array $specs = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);
        $this->period = app(PeriodContext::class)->current()->key();
    }

    #[On('context-changed')]
    public function refreshContext(): void
    {
        $this->period = app(PeriodContext::class)->current()->key();
        $this->cancelEdit();
    }

    /** Abre la edición en línea con la tasa publicada de ese día (vacía si es arrastrada o no existe). */
    public function startEdit(string $date): void
    {
        $this->resetErrorBag();
        if (! $this->belongsToPeriod($date)) {
            return;
        }
        $this->editingDate = $date;
        $published = ExchangeRate::query()->where('date', $date)->first();
        $this->editValue = $published === null ? '' : app(Formatter::class)->number($published->rate, 2);
    }

    public function cancelEdit(): void
    {
        $this->editingDate = null;
        $this->editValue = '';
        $this->deviationConfirmed = false;
        $this->resetErrorBag();
    }

    public function updatedEditValue(): void
    {
        // Si cambia el valor tras el aviso, hay que volver a confirmar.
        $this->deviationConfirmed = false;
    }

    public function saveRate(UpsertExchangeRate $action, Formatter $formatter): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);
        if ($this->editingDate === null) {
            return;
        }
        // La fecha editada tiene que ser una de las que muestra la tabla (B17).
        if (! $this->belongsToPeriod($this->editingDate)) {
            $this->cancelEdit();

            return;
        }

        $parsed = $formatter->parseNumber($this->editValue);
        if ($parsed === null || BigDecimal::of($parsed)->isLessThanOrEqualTo(0)) {
            $this->addError('editValue', 'Escribe la tasa en bolívares por dólar, por ejemplo 177,61.');

            return;
        }
        $value = BigDecimal::of($parsed);
        if ($value->isGreaterThan(self::MAX_RATE)) {
            $this->addError('editValue', 'Es demasiado grande. Revisa el valor.');

            return;
        }

        $date = CarbonImmutable::parse($this->editingDate);

        // Un dedo de más (1.485 en vez de 148,5) no se guarda a la primera: se pide confirmar (RN-16).
        $reference = $this->referenceRate($date);
        if ($reference !== null && ! $this->deviationConfirmed && $this->deviatesTooMuch($reference, $value)) {
            $this->deviationConfirmed = true;
            $variation = Decimal::variation($reference, $value);
            $this->addError('editValue', 'Difiere '.$formatter->pct($variation).' de la tasa anterior ('.$formatter->number($reference, 2).'). Si es correcta, guarda otra vez para confirmar.');

            return;
        }

        $action->handle($date, $value, RateSource::Manual, auth()->user());

        $this->dispatch('toast', type: 'success', message: 'Tasa del '.$formatter->date($date, 'short').' guardada. Los días ya cargados conservan la suya hasta que recalcules.');
        $this->cancelEdit();
    }

    /** Última tasa publicada antes de la fecha editada (o la siguiente, si es la primera del histórico). */
    private function referenceRate(CarbonImmutable $date): ?BigDecimal
    {
        $previous = ExchangeRate::query()->where('date', '<', $date->toDateString())->orderByDesc('date')->first();
        $next = $previous ?? ExchangeRate::query()->where('date', '>', $date->toDateString())->orderBy('date')->first();

        return $next?->rate;
    }

    private function deviatesTooMuch(BigDecimal $reference, BigDecimal $new): bool
    {
        $threshold = BigDecimal::of((string) Setting::get('rate_deviation_pct'))->dividedBy(100, 4);
        $variation = Decimal::variation($reference, $new);

        return $variation !== null && $variation->abs()->isGreaterThan($threshold);
    }

    public function fetchNow(FetchBcvRate $action, Formatter $formatter): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);

        // El proveedor devuelve la cotización vigente, sin fecha: se guarda para un solo día (M6).
        // Igual que el programador (§9.2): hasta las 17:00 rige hoy; después, el siguiente día hábil.
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();
        $target = ($now->hour >= self::NEXT_DAY_HOUR || ! $today->isWeekday())
            ? FetchBcvRate::nextBusinessDay($today)
            : $today;

        $saved = $action->handle($target);

        if ($saved === null) {
            $this->dispatch('toast', type: 'warning', message: 'El BCV no respondió. Inténtalo más tarde o escribe la tasa a mano.');

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Tasa del '.$formatter->date($saved->date, 'short').': '.$formatter->number($saved->rate, 2).' ('.$saved->source->label().').');
    }

    /** Trae del BCV las tasas históricas que faltan desde una fecha (§9.2); nunca pisa una tasa existente. */
    public function backfill(BackfillBcvRates $action, Formatter $formatter): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);
        $this->resetErrorBag('backfillFrom');

        try {
            $from = CarbonImmutable::parse($this->backfillFrom)->startOfDay();
        } catch (InvalidArgumentException) {
            $this->addError('backfillFrom', 'Escribe una fecha válida.');

            return;
        }
        $earliest = CarbonImmutable::today()->subMonthsNoOverflow(self::BACKFILL_MAX_MONTHS)->startOfDay();
        if ($from->gt(CarbonImmutable::today())) {
            $this->addError('backfillFrom', 'La fecha debe estar entre '.$earliest->format('d/m/Y').' y hoy.');

            return;
        }
        if ($from->lt($earliest)) {
            $this->addError('backfillFrom', 'Desde la pantalla se pueden traer hasta 3 años; para más, usa el comando rates:backfill.');

            return;
        }

        $result = $action->handle($from, CarbonImmutable::today());
        $this->backfillDialog = false;

        if ($result['found'] === 0) {
            $this->dispatch('toast', type: 'warning', message: 'El BCV no devolvió tasas para ese período. Inténtalo más tarde o escríbelas a mano.');

            return;
        }

        $message = $result['created'] === 0
            ? 'Todas las tasas de ese período ya estaban: nada que agregar.'
            : ($result['created'] === 1 ? '1 tasa nueva del BCV' : "{$result['created']} tasas nuevas del BCV").' ('.$formatter->date(CarbonImmutable::parse((string) $result['from']), 'short').' a '.$formatter->date(CarbonImmutable::parse((string) $result['to']), 'short').').';
        if ($result['failed'] !== []) {
            $message .= ' Algún trimestre no estaba disponible; vuelve a intentarlo más tarde.';
        }
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function recalculate(RecalculateMonthRates $action, Formatter $formatter): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);
        $branch = app(CurrentBranch::class)->resolve(auth()->user()) ?? abort(403, 'Elige una sede.');

        try {
            $changed = $action->handle($branch->id, Period::of($this->period), auth()->user());
        } catch (PeriodStateException $e) {
            // RN-13: un mes cerrado no se toca; reabrirlo es decisión de dirección.
            $this->recalcDialog = false;
            $this->dispatch('toast', type: 'warning', message: $e->getMessage().' Para recalcular sus tasas, dirección debe reabrirlo desde la pantalla del mes.');

            return;
        }
        $this->recalcDialog = false;

        $this->dispatch('toast', type: 'success', message: match ($changed) {
            0 => 'Ningún día necesitaba cambios.',
            1 => '1 día actualizado con su tasa.',
            default => "{$changed} días actualizados con su tasa.",
        });
    }

    public function render(RatesMonthQuery $query, ChartSpecBuilder $charts, RecalculateMonthRates $recalculate, Formatter $formatter): View
    {
        $period = Period::of($this->period);
        $data = $query->run($period);
        $branch = app(CurrentBranch::class)->resolve(auth()->user());

        $points = array_map(fn (array $row) => [
            'date' => $row['date'],
            'rate' => $row['rate']?->toFloat(),
            'source' => $row['source']?->value,
        ], $data['rows']);
        $this->specs = ['rate' => $charts->rateHistory($points, $period)->toArray()];

        $closed = $branch !== null && PeriodEvent::isClosed($branch->id, $period);

        return view('livewire.rates.rates-page', [
            'periodLabel' => $period->label(),
            'closed' => $closed,
            'rows' => $data['rows'],
            'first' => $data['first'],
            'last' => $data['last'],
            'published' => $data['published'],
            'manual' => $data['manual'],
            'status' => $this->describeStatus($data['status'], $formatter),
            'branch' => $branch,
            'pending' => $branch === null || $closed ? 0 : $recalculate->preview($branch->id, $period),
            'formatter' => $formatter,
        ]);
    }

    /** La fecha que se edita tiene que pertenecer al mes que muestra la tabla (B17). */
    private function belongsToPeriod(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        try {
            return Period::of($this->period)->contains(CarbonImmutable::parse($date));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param  array{provider: string, lastAttempt: string|null, lastSuccess: string|null, lastError: string|null}  $status
     * @return array{automatic: bool, headline: string, detail: string, error: string|null}
     */
    private function describeStatus(array $status, Formatter $formatter): array
    {
        $automatic = $status['provider'] !== 'null';
        $when = fn (?string $iso): string => $iso === null ? 'nunca' : $formatter->date(CarbonImmutable::parse($iso), 'weekday').' '.CarbonImmutable::parse($iso)->format('H:i');

        return [
            'automatic' => $automatic,
            'headline' => $automatic ? 'Consulta automática al BCV a las 08:00 y 17:30' : 'Consulta automática desactivada: las tasas se escriben a mano',
            'detail' => $automatic ? 'Última consulta: '.$when($status['lastAttempt']).' · último éxito: '.$when($status['lastSuccess']) : '',
            'error' => $status['lastError'],
        ];
    }
}
