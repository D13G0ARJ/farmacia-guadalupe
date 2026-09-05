<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Charts\ChartGoalContext;
use App\Domain\Charts\ChartSpecBuilder;
use App\Domain\Indicators\DailyMetrics;
use App\Domain\Indicators\DailyRecordData;
use App\Domain\Indicators\DeltaCalculator;
use App\Domain\Indicators\Indicator;
use App\Domain\Indicators\IndicatorCalculator;
use App\Domain\Indicators\PeriodSummary;
use App\Domain\Rates\RateResolver;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Models\DailyRecord;
use App\Models\PeriodEvent;
use Carbon\CarbonImmutable;

/**
 * Panel principal (UC-08, §13.7): KPI con variaciones (§7.2), sparklines, avisos del mes y las dos
 * gráficas del panel. Todas las cifras salen del mismo `PeriodSummary` que la tabla del mes.
 */
final class DashboardQuery
{
    private const SPARKLINE_DAYS = 14;

    public const CHARTS = ['g2', 'g8'];

    public function __construct(
        private readonly MonthRecordsQuery $months,
        private readonly IndicatorCalculator $calculator,
        private readonly DeltaCalculator $deltas,
        private readonly ChartSpecBuilder $charts,
        private readonly RateResolver $rates,
        private readonly Formatter $formatter,
        private readonly GoalProgressQuery $goals,
    ) {}

    public function run(?int $branchId, Period $period): DashboardView
    {
        $month = $this->months->run($branchId, $period);
        $indicators = [...Indicator::primary(), ...Indicator::secondary()];

        // Comparación a fecha equivalente (§7.2): si el mes en curso está incompleto, el de
        // referencia se limita a los mismos días transcurridos.
        $lastLoaded = $month->rows === [] ? null : $month->rows[array_key_last($month->rows)]->data->date;
        $partialDays = $lastLoaded !== null && $period->contains($month->today) && $lastLoaded->lt($period->end())
            ? $lastLoaded->day
            : null;

        $previous = $period->previous();
        $yearAgo = $period->sameMonthLastYear();

        $vsPrevious = $this->deltas->compare(
            $month->summary,
            $this->referenceSummary($branchId, $previous, $partialDays),
            $this->monthName($previous),
            $indicators,
            $partialDays,
        );
        $vsYear = $this->deltas->compare(
            $month->summary,
            $this->referenceSummary($branchId, $yearAgo, $partialDays),
            $this->monthName($yearAgo).' '.$yearAgo->start->year,
            $indicators,
            $partialDays,
        );

        $goals = $this->goals->run($branchId, $period, $month);

        return new DashboardView(
            period: $period,
            branchId: $branchId,
            month: $month,
            vsPrevious: $vsPrevious,
            vsYear: $vsYear,
            sparklines: $this->sparklines($month->rows, $indicators),
            notices: $branchId === null ? [] : $this->notices($branchId, $month, $previous),
            charts: $this->chartSpecs($month, ChartGoalContext::fromProgress($goals->progress)),
            partialDays: $partialDays,
            goals: $goals,
        );
    }

    /** Resumen del período de referencia, limitado a los primeros `$upToDay` días si aplica; null sin datos. */
    private function referenceSummary(?int $branchId, Period $reference, ?int $upToDay): ?PeriodSummary
    {
        $query = DailyRecord::query()->forBranch($branchId)->forPeriod($reference);
        if ($upToDay !== null) {
            $cutoff = $reference->start->addDays(min($upToDay, $reference->days()) - 1);
            $query->where('date', '<=', $cutoff->toDateString());
        }

        $records = $query->orderBy('date')->get();
        if ($records->isEmpty()) {
            return null;
        }

        return $this->calculator->summarize($records->map(fn (DailyRecord $r) => DailyRecordData::fromModel($r))->all());
    }

    /**
     * Últimos 14 días cargados (sin cerrados) por indicador, como flotantes para la sparkline (§13.5).
     *
     * @param  list<DailyMetrics>  $rows
     * @param  list<Indicator>  $indicators
     * @return array<string, list<float>>
     */
    private function sparklines(array $rows, array $indicators): array
    {
        $recent = array_values(array_filter($rows, fn (DailyMetrics $m) => ! $m->data->isClosed()));
        $recent = array_slice($recent, -self::SPARKLINE_DAYS);

        $lines = [];
        foreach ($indicators as $indicator) {
            $points = [];
            foreach ($recent as $metrics) {
                $value = $metrics->value($indicator);
                if ($value !== null) {
                    $points[] = $value->toFloat();
                }
            }
            $lines[$indicator->value] = $points;
        }

        return $lines;
    }

    /**
     * Avisos del mes (§13.7 punto 5): días faltantes, tasa de hoy arrastrada, mes anterior sin cerrar.
     *
     * @return list<array{tone: string, icon: string, text: string, href: string|null, action: string|null}>
     */
    private function notices(int $branchId, MonthView $month, Period $previous): array
    {
        $notices = [];

        if ($month->isClosed) {
            $notices[] = ['tone' => 'success', 'icon' => 'lock', 'text' => $month->period->label().' está cerrado.', 'href' => null, 'action' => null];
        }

        $missing = count($month->missingDates);
        if ($missing > 0) {
            $days = implode(', ', array_map(fn (CarbonImmutable $d) => (string) $d->day, array_slice($month->missingDates, 0, 6)));
            $notices[] = [
                'tone' => 'warning',
                'icon' => 'warning',
                'text' => ($missing === 1 ? 'Falta 1 día por cargar: ' : "Faltan {$missing} días por cargar: ").$days.($missing > 6 ? '…' : '').'.',
                'href' => route('records.create', ['date' => $month->firstMissingDate()?->toDateString()]),
                'action' => $missing === 1 ? 'Cargarlo' : 'Cargar el primero',
            ];
        }

        if ($month->period->contains($month->today) && $month->today->isWeekday()) {
            $resolution = $this->rates->forDate($month->today);
            if ($resolution === null) {
                $notices[] = ['tone' => 'danger', 'icon' => 'rate', 'text' => 'No hay tasa BCV para hoy: el formulario pedirá la tasa manual.', 'href' => null, 'action' => null];
            } elseif ($resolution->isStale()) {
                $notices[] = ['tone' => 'warning', 'icon' => 'rate', 'text' => 'La tasa de hoy viene arrastrada del '.$resolution->sourceDate->format('d/m/Y').' ('.$resolution->ageLabel().', '.$this->formatter->number($resolution->rate, 2).'): no hubo consultas al BCV desde entonces.', 'href' => route('rates'), 'action' => 'Consultar ahora'];
            } elseif ($resolution->isCarried()) {
                $notices[] = ['tone' => 'neutral', 'icon' => 'rate', 'text' => 'La tasa de hoy aún no está publicada: se usa la arrastrada del '.$this->formatter->date($resolution->sourceDate, 'weekday').' ('.$this->formatter->number($resolution->rate, 2).').', 'href' => null, 'action' => null];
            }
        }

        $previousHasRecords = DailyRecord::query()->forBranch($branchId)->forPeriod($previous)->exists();
        if ($previousHasRecords && ! PeriodEvent::isClosed($branchId, $previous)) {
            $notices[] = [
                'tone' => 'neutral',
                'icon' => 'calendar',
                'text' => $previous->label().' no está cerrado.',
                'href' => route('month', ['period' => $previous->key()]),
                'action' => 'Ver el mes',
            ];
        }

        return $notices;
    }

    /**
     * G9 (acumulado vs meta) solo cuando hay meta de venta en dólares; luego G2 y G8.
     *
     * @return array<string, array<string, mixed>>
     */
    private function chartSpecs(MonthView $month, ChartGoalContext $goal): array
    {
        $ids = $goal->cumulative === null ? self::CHARTS : ['g9', ...self::CHARTS];

        $specs = [];
        foreach ($ids as $id) {
            $specs[$id] = $this->charts->build($id, $month->rows, $month->period, $goal)->toArray();
        }

        return $specs;
    }

    private function monthName(Period $period): string
    {
        return mb_strtolower($period->monthNameUpper());
    }
}
