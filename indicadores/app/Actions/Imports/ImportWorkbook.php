<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Domain\Imports\Anomaly;
use App\Domain\Imports\AnomalyDetector;
use App\Domain\Imports\ImportContext;
use App\Domain\Imports\WorkbookParser;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\AnomalySeverity;
use App\Enums\ImportStatus;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
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
        $hash = (string) hash_file('sha256', $path);
        $batch = new ImportBatch([
            'group_id' => $groupId,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'original_filename' => mb_substr($originalName, 0, 255),
            'file_hash' => $hash,
            'status' => ImportStatus::Failed,
        ]);

        try {
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
        );

        $month = $month->withAnomalies($this->detector->detect($month, $context));

        $counts = ['high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($month->anomalies as $anomaly) {
            $counts[$anomaly->severity()->value]++;
        }

        $batch->period = $period->start;
        $batch->status = ImportStatus::Parsed;
        $batch->summary = [
            'rows' => count($month->rows),
            'period_label' => $period->label(),
            'legal_name' => $month->legalName,
            ...$counts,
            'blocking' => count(array_filter($month->anomalies, fn (Anomaly $a) => $a->severity() === AnomalySeverity::High)),
        ];
        $batch->parsed_payload = $month->toArray();
        $batch->save();

        return $batch;
    }
}
