<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Actions\Periods\CloseMonth;
use App\Actions\Rates\UpsertExchangeRate;
use App\Domain\Imports\Exceptions\ImportException;
use App\Domain\Imports\ParsedMonth;
use App\Domain\Imports\ParsedRow;
use App\Domain\Periods\Exceptions\PeriodStateException;
use App\Domain\Rates\RateResolver;
use App\Domain\Shared\Period;
use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use App\Enums\DayStatus;
use App\Enums\ImportStatus;
use App\Enums\RateSource;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ImportBatch;
use App\Models\PeriodEvent;
use App\Models\User;
use App\Support\PeriodSummaryCache;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aplica un lote revisado (§10.4 paso 5): inserta o actualiza los días en una transacción, crea las
 * tasas que faltaban como manuales, registra los días cerrados elegidos y deja bitácora.
 * Ningún dato entra sin que cada anomalía alta tenga decisión.
 */
final class ConfirmImport
{
    public function __construct(
        private readonly UpsertExchangeRate $rates,
        private readonly CloseMonth $closeMonth,
        private readonly PeriodSummaryCache $cache,
    ) {}

    /**
     * @param  array<string, string>  $decisions  id de anomalía → valor elegido
     * @return array{created: int, updated: int, skipped: int, closed: int, atypical: int, rates: int, rejected: bool}
     */
    public function handle(ImportBatch $batch, array $decisions, User $user, bool $closeAfter = false): array
    {
        if ($batch->status !== ImportStatus::Parsed || $batch->parsed_payload === null) {
            throw ImportException::notPending();
        }

        $month = ParsedMonth::fromArray($batch->parsed_payload);
        $decisions = $this->resolveDecisions($month, $decisions);
        $period = Period::of($month->period);
        $branch = Branch::query()->findOrFail($batch->branch_id);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'closed' => 0, 'atypical' => 0, 'rates' => 0, 'rejected' => false];

        if ($this->fileRejected($decisions)) {
            $batch->status = ImportStatus::Rejected;
            $batch->summary = [...($batch->summary ?? []), 'result' => ['rejected' => true]];
            $batch->save();

            return [...$result, 'rejected' => true];
        }

        // RN-13: un mes cerrado no se toca; reabrirlo es una decisión de dirección, no del importador.
        if (PeriodEvent::isClosed($branch->id, $period)) {
            throw ImportException::periodClosed($period->label());
        }

        $rows = $this->selectRows($month, $decisions, $result);

        DB::transaction(function () use ($rows, $month, $decisions, $period, $branch, $batch, $user, &$result): void {
            foreach ($rows as $row) {
                $date = CarbonImmutable::parse($row->date);
                $rate = BigDecimal::of((string) $row->rate);
                $atypical = ($decisions[AnomalyType::SalesDeviation->value.':'.$row->date] ?? null) === 'atypical';

                $conflict = $decisions[AnomalyType::RateConflict->value.':'.$row->date] ?? null;
                if ($conflict !== 'keep') {
                    $this->rates->handle($date, $rate, RateSource::Manual, $user);
                    $result['rates']++;
                }

                $record = DailyRecord::query()->forBranch($branch->id)->where('date', $row->date)->first() ?? new DailyRecord;
                $isNew = ! $record->exists;
                // Al reemplazar, el archivo trae las cifras; la marca de atípico puesta a mano se conserva.
                // Un día que estaba cerrado y ahora trae venta pasa a normal.
                $status = match (true) {
                    $atypical => DayStatus::Atypical,
                    $isNew, $record->status === DayStatus::Closed => DayStatus::Normal,
                    default => $record->status,
                };
                $record->fill([
                    'branch_id' => $branch->id,
                    'date' => $row->date,
                    'status' => $status,
                    'sales_bs' => (string) $row->salesBs,
                    'exchange_rate' => (string) $rate,
                    'exchange_rate_source' => RateSource::Manual,
                    'transactions' => (int) $row->transactions,
                    'units' => (int) $row->units,
                    'inventory_units' => $row->inventoryUnits,
                    'inventory_value_usd' => $row->inventoryValueUsd,
                    'shifts' => (int) $row->shifts,
                    'notes' => $atypical ? 'Marcado atípico al importar: venta muy distinta al resto del mes.' : ($status === DayStatus::Normal && $record->status === DayStatus::Closed ? null : $record->notes),
                    'updated_by' => $isNew ? null : $user->id,
                ]);
                if ($isNew) {
                    $record->created_by = $user->id;
                }
                $record->save();
                $result[$isNew ? 'created' : 'updated']++;
                if ($atypical) {
                    $result['atypical']++;
                }
            }

            foreach ($month->anomalies as $anomaly) {
                if ($anomaly->type !== AnomalyType::MissingDay || ($decisions[$anomaly->id()] ?? null) !== 'closed' || $anomaly->date === null) {
                    continue;
                }
                $existing = DailyRecord::query()->forBranch($branch->id)->where('date', $anomaly->date)->exists();
                if ($existing) {
                    continue;
                }
                $resolution = app(RateResolver::class)->forDate(CarbonImmutable::parse($anomaly->date));
                DailyRecord::query()->create([
                    'branch_id' => $branch->id,
                    'date' => $anomaly->date,
                    'status' => DayStatus::Closed,
                    'sales_bs' => '0',
                    'exchange_rate' => $resolution === null ? '1' : (string) $resolution->rate,
                    'exchange_rate_source' => $resolution === null ? RateSource::Manual : $resolution->source,
                    'transactions' => 0,
                    'units' => 0,
                    'inventory_units' => null,
                    'inventory_value_usd' => null,
                    'shifts' => 0,
                    'notes' => 'Registrado como cerrado al importar: el archivo no traía el día.',
                    'created_by' => $user->id,
                ]);
                $result['closed']++;
            }

            $batch->status = ImportStatus::Confirmed;
            $batch->summary = [...($batch->summary ?? []), 'result' => $result, 'decisions' => $decisions];
            $batch->save();

            activity()
                ->performedOn($batch)
                ->causedBy($user)
                ->event('imported')
                ->withProperties(['attributes' => ['period' => $month->period, ...$result]])
                ->log('imported');

            $this->cache->forget($branch->id, $period);
        });

        if ($closeAfter) {
            try {
                $this->closeMonth->handle($branch, $period, $user, confirmMissing: true);
            } catch (PeriodStateException) {
                // Mes futuro, vacío o ya cerrado: la importación vale igual.
            }
        }

        return $result;
    }

    /**
     * Completa las decisiones con las propuestas por defecto y exige una para cada anomalía alta.
     *
     * @param  array<string, string>  $decisions
     * @return array<string, string>
     */
    private function resolveDecisions(ParsedMonth $month, array $decisions): array
    {
        $resolved = [];
        $unresolved = [];
        foreach ($month->anomalies as $anomaly) {
            $options = array_column($anomaly->type->options(), 'value');
            if ($options === []) {
                continue;
            }
            $chosen = $decisions[$anomaly->id()] ?? null;
            if ($chosen !== null && in_array($chosen, $options, true)) {
                $resolved[$anomaly->id()] = $chosen;

                continue;
            }
            if ($anomaly->severity() === AnomalySeverity::High) {
                $unresolved[] = $anomaly->type->label().($anomaly->date !== null ? ' ('.CarbonImmutable::parse($anomaly->date)->format('d/m').')' : '');

                continue;
            }
            $resolved[$anomaly->id()] = (string) $anomaly->defaultDecision();
        }

        if ($unresolved !== []) {
            throw ImportException::unresolved($unresolved);
        }

        return $resolved;
    }

    /** @param  array<string, string>  $decisions */
    private function fileRejected(array $decisions): bool
    {
        return ($decisions[AnomalyType::AlreadyImported->value] ?? null) === 'skip'
            || ($decisions[AnomalyType::MonthMismatch->value] ?? null) === 'skip';
    }

    /**
     * Filas que entran: sin las omitidas, sin fechas fuera del mes, sin incompletas, y una sola por fecha.
     *
     * @param  array<string, string>  $decisions
     * @param  array{created: int, updated: int, skipped: int, closed: int, atypical: int, rates: int, rejected: bool}  $result
     * @return list<ParsedRow>
     */
    private function selectRows(ParsedMonth $month, array $decisions, array &$result): array
    {
        $period = Period::of($month->period);
        $omitDates = [];
        $duplicateChoice = [];
        foreach ($month->anomalies as $anomaly) {
            $decision = $decisions[$anomaly->id()] ?? null;
            if ($anomaly->date === null) {
                continue;
            }
            if ($decision === 'omit') {
                $omitDates[$anomaly->date] = true;
            }
            if ($anomaly->type === AnomalyType::DuplicateDate && in_array($decision, ['first', 'last'], true)) {
                $duplicateChoice[$anomaly->date] = $decision;
            }
        }

        $byDate = [];
        foreach ($month->rows as $row) {
            if (isset($omitDates[$row->date]) || ! $row->isComplete() || ! $period->contains(CarbonImmutable::parse($row->date))) {
                $result['skipped']++;

                continue;
            }
            if (isset($byDate[$row->date])) {
                if (($duplicateChoice[$row->date] ?? 'first') === 'last') {
                    $byDate[$row->date] = $row;
                }
                $result['skipped']++;

                continue;
            }
            $byDate[$row->date] = $row;
        }
        ksort($byDate);

        return array_values($byDate);
    }
}
