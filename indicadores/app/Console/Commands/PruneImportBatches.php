<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Limpieza de lotes huérfanos (§10): lo que se analizó y nunca se confirmó solo ocupa espacio
 * (el payload leído de cada archivo pesa). Los confirmados y los rechazados son historia: no se tocan.
 */
class PruneImportBatches extends Command
{
    protected $signature = 'imports:prune {--days=7 : Antigüedad mínima, en días, de lo analizado sin confirmar}';

    protected $description = 'Borra los lotes de importación analizados o fallidos que nadie confirmó';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('Los días deben ser 1 o más.');

            return self::FAILURE;
        }

        $before = CarbonImmutable::now()->subDays($days);
        $deleted = ImportBatch::query()
            ->whereIn('status', [ImportStatus::Parsed, ImportStatus::Failed])
            ->where('created_at', '<', $before)
            ->delete();

        $this->info("Lotes de importación borrados: {$deleted} (anteriores al ".$before->format('d/m/Y H:i').').');

        return self::SUCCESS;
    }
}
