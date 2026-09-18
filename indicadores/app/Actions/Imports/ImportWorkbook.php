<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Domain\Imports\Anomaly;
use App\Domain\Imports\AnomalyDetector;
use App\Domain\Imports\ImportContext;
use App\Domain\Imports\ParsedMonth;
use App\Domain\Imports\WorkbookParser;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use App\Enums\ImportStatus;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Lee un archivo, detecta anomalías y deja el lote en estado `parsed` para la revisión (§10.4).
 * El archivo no se conserva: basta el hash y lo leído. Nunca lanza: un archivo ilegible queda `failed`.
 */
final class ImportWorkbook
{
    public function __construct(
        private readonly WorkbookParser $parser,
        private readonly AnomalyDetector $detector,
        private readonly Formatter $formatter,
    ) {}

    public function handle(string $path, string $originalName, Branch $branch, User $user, string $groupId): ImportBatch
    {
        $batch = new ImportBatch([
            'group_id' => $groupId,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'original_filename' => mb_substr($originalName, 0, 255),
            'file_hash' => '',
            'status' => ImportStatus::Failed,
        ]);

        try {
            // El hash también puede fallar (disco, permisos): el lote queda fallido, no revienta la pantalla (B21).
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('no se pudo leer el archivo del disco.');
            }
            $batch->file_hash = $hash;
            $month = $this->parser->parse($path);
        } catch (Throwable $e) {
            $batch->summary = ['error' => $e->getMessage()];
            $batch->save();

            return $batch;
        }

        $period = Period::of($month->period);
        $previous = ImportBatch::query()
            ->where('file_hash', $hash)
            ->where('status', ImportStatus::Confirmed)
            ->latest('id')
            ->first();

        // Dos archivos del mismo mes en el mismo lote: el segundo pisaría al primero sin avisar (M1).
        $sameMonthInBatch = ImportBatch::query()
            ->where('group_id', $groupId)
            ->where('status', ImportStatus::Parsed)
            ->where('period', $period->start->toDateString())
            ->get();

        $context = new ImportContext(
            inventoryDays: array_map('intval', $branch->inventory_days ?? Branch::DEFAULT_INVENTORY_DAYS),
            existingRecords: DailyRecord::query()->forBranch($branch->id)->forPeriod($period)->count(),
            existingRates: ExchangeRate::query()
                ->whereBetween('date', [$period->start->toDateString(), $period->end()->toDateString()])
                ->get()
                ->mapWithKeys(fn (ExchangeRate $r) => [$r->date->toDateString() => (string) $r->rate])
                ->all(),
            alreadyImportedAt: $previous === null ? null : $this->formatter->date($previous->created_at, 'short'),
            salesDeviationPct: (int) ($branch->sales_deviation_pct ?? Setting::get('sales_deviation_pct', $branch->id)),
            rateDeviationPct: (int) Setting::get('rate_deviation_pct'),
            duplicatePeriodInBatch: $sameMonthInBatch->isNotEmpty(),
        );

        $month = $month->withAnomalies($this->detector->detect($month, $context));

        $batch->period = $period->start;
        $batch->status = ImportStatus::Parsed;
        $batch->summary = [
            'rows' => count($month->rows),
            'period_label' => $period->label(),
            'legal_name' => $month->legalName,
            ...self::countBySeverity($month->anomalies),
            'blocking' => count(array_filter($month->anomalies, fn (Anomaly $a) => $a->severity() === AnomalySeverity::High)),
        ];
        $batch->parsed_payload = $month->toArray();
        $batch->save();

        // El primer archivo del mes también tiene que pedir la decisión: si no, el aviso llega tarde (M1).
        foreach ($sameMonthInBatch as $earlier) {
            $this->markDuplicatePeriod($earlier, $period);
        }

        return $batch;
    }

    /** Agrega la anomalía de mes repetido a un lote ya analizado, si todavía no la tiene. */
    private function markDuplicatePeriod(ImportBatch $batch, Period $period): void
    {
        if ($batch->parsed_payload === null) {
            return;
        }

        $month = ParsedMonth::fromArray($batch->parsed_payload);
        foreach ($month->anomalies as $anomaly) {
            if ($anomaly->type === AnomalyType::DuplicatePeriodInBatch) {
                return;
            }
        }

        $month = $month->withAnomalies([new Anomaly(
            AnomalyType::DuplicatePeriodInBatch,
            'Dos archivos del mismo mes en este lote ('.mb_strtolower($period->label()).'): elige cuál importar; el otro se guardaría encima sin avisar.',
        )]);

        $batch->parsed_payload = $month->toArray();
        $batch->summary = [
            ...($batch->summary ?? []),
            ...self::countBySeverity($month->anomalies),
            'blocking' => count(array_filter($month->anomalies, fn (Anomaly $a) => $a->severity() === AnomalySeverity::High)),
        ];
        $batch->save();
    }

    /**
     * @param  list<Anomaly>  $anomalies
     * @return array{high: int, medium: int, low: int, info: int}
     */
    private static function countBySeverity(array $anomalies): array
    {
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($anomalies as $anomaly) {
            $counts[$anomaly->severity()->value]++;
        }

        return $counts;
    }
}
