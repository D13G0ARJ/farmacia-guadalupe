<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Domain\Shared\Period;
use App\Support\CurrencyContext;
use App\Support\CurrentBranch;
use App\Support\PeriodContext;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Barra de contexto global (§13.3): período, sede (si hay más de una) y moneda.
 * Persiste en sesión y avisa a la pantalla con `context-changed`.
 */
class ContextBar extends Component
{
    public string $period = '';

    public string $branch = '';

    public string $currency = CurrencyContext::BOTH;

    public function mount(): void
    {
        $user = auth()->user();
        $this->period = app(PeriodContext::class)->current()->key();
        $branchContext = app(CurrentBranch::class);
        $current = $branchContext->resolve($user);
        $this->branch = $branchContext->isConsolidated() ? 'all' : (string) $current?->id;
        $this->currency = app(CurrencyContext::class)->current();
    }

    public function previousPeriod(): void
    {
        $this->setPeriod(Period::of($this->period)->previous()->key());
    }

    public function nextPeriod(): void
    {
        $this->setPeriod(Period::of($this->period)->next()->key());
    }

    public function updatedPeriod(string $value): void
    {
        $this->setPeriod($value);
    }

    public function updatedBranch(string $value): void
    {
        app(CurrentBranch::class)->set(auth()->user(), $value === 'all' ? null : (int) $value);
        $this->dispatch('context-changed');
    }

    public function updatedCurrency(string $value): void
    {
        app(CurrencyContext::class)->set($value);
        $this->currency = app(CurrencyContext::class)->current();
        $this->dispatch('context-changed');
    }

    public function render(): View
    {
        $user = auth()->user();
        $branches = $user->accessibleBranches();

        return view('livewire.shared.context-bar', [
            'periodLabel' => Period::of($this->period)->label(),
            'branches' => $branches,
            'showBranches' => $branches->count() > 1,
            'canConsolidate' => $user->canSeeAllBranches(),
            'options' => $this->periodOptions(),
        ]);
    }

    private function setPeriod(string $key): void
    {
        try {
            $period = Period::of($key);
        } catch (InvalidArgumentException) {
            return;
        }

        app(PeriodContext::class)->set($period);
        $this->period = $period->key();
        $this->dispatch('context-changed');
    }

    /**
     * Últimos 24 meses para el selector.
     *
     * @return list<array{key: string, label: string}>
     */
    private function periodOptions(): array
    {
        $options = [];
        $cursor = Period::current();
        for ($i = 0; $i < 24; $i++) {
            $options[] = ['key' => $cursor->key(), 'label' => $cursor->label()];
            $cursor = $cursor->previous();
        }

        $current = Period::of($this->period)->key();
        if (! in_array($current, array_column($options, 'key'), true)) {
            array_unshift($options, ['key' => $current, 'label' => Period::of($current)->label()]);
        }

        return $options;
    }
}
