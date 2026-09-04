<?php

declare(strict_types=1);

namespace App\Livewire\Rates;

use App\Actions\Rates\FetchBcvRate;
use App\Actions\Rates\RecalculateMonthRates;
use App\Actions\Rates\UpsertExchangeRate;
use App\Domain\Charts\ChartSpecBuilder;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\Permission;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Queries\RatesMonthQuery;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
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
    public string $period = '';

    public ?string $editingDate = null;

    public string $editValue = '';

    public bool $recalcDialog = false;

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
        $this->editingDate = $date;
        $published = ExchangeRate::query()->where('date', $date)->first();
        $this->editValue = $published === null ? '' : app(Formatter::class)->number($published->rate, 2);
    }

    public function cancelEdit(): void
    {
        $this->editingDate = null;
        $this->editValue = '';
        $this->resetErrorBag();
    }

    public function saveRate(UpsertExchangeRate $action, Formatter $formatter): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);
        if ($this->editingDate === null) {
            return;
        }

        $parsed = $formatter->parseNumber($this->editValue);
        if ($parsed === null || BigDecimal::of($parsed)->isLessThanOrEqualTo(0)) {
            $this->addError('editValue', 'Escribe la tasa en bolívares por dólar, por ejemplo 177,61.');

            return;
        }

        $date = CarbonImmutable::parse($this->editingDate);
        $action->handle($date, BigDecimal::of($parsed), RateSource::Manual, auth()->user());

        $this->dispatch('toast', type: 'success', message: 'Tasa del '.$formatter->date($date, 'short').' guardada. Los días ya cargados conservan la suya hasta que recalcules.');
        $this->cancelEdit();
    }

    public function fetchNow(FetchBcvRate $action, Formatter $formatter): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);

        $today = CarbonImmutable::today();
        $targets = [FetchBcvRate::nextBusinessDay($today)];
        if ($today->isWeekday()) {
            array_unshift($targets, $today);
        }

        $saved = null;
        foreach ($targets as $target) {
            $result = $action->handle($target);
            if ($result !== null) {
                $saved = $result;
            }
        }

        if ($saved === null) {
            $this->dispatch('toast', type: 'warning', message: 'El BCV no respondió. Inténtalo más tarde o escribe la tasa a mano.');

            return;
        }

        $this->dispatch('toast', type: 'success', message: 'Tasa del '.$formatter->date($saved->date, 'short').': '.$formatter->number($saved->rate, 2).' ('.$saved->source->label().').');
    }

    public function recalculate(RecalculateMonthRates $action, Formatter $formatter): void
    {
        abort_unless(auth()->user()->can(Permission::RatesManage->value), 403);
        $branch = app(CurrentBranch::class)->resolve(auth()->user()) ?? abort(403, 'Elige una sede.');

        $changed = $action->handle($branch->id, Period::of($this->period), auth()->user());
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

        return view('livewire.rates.rates-page', [
            'periodLabel' => $period->label(),
            'rows' => $data['rows'],
            'first' => $data['first'],
            'last' => $data['last'],
            'published' => $data['published'],
            'manual' => $data['manual'],
            'status' => $this->describeStatus($data['status'], $formatter),
            'branch' => $branch,
            'pending' => $branch === null ? 0 : $recalculate->preview($branch->id, $period),
            'formatter' => $formatter,
        ]);
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
