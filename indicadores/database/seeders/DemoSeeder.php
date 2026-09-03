<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DayStatus;
use App\Enums\RateSource;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga el mes de septiembre 2025 desde el fixture derivado del Excel real (§4.6, §16).
 * Idempotente: reemplaza los registros de ese mes para la sede principal.
 */
class DemoSeeder extends Seeder
{
    public const FIXTURE = 'tests/Fixtures/septiembre-2025.json';

    /** Día detectado como atípico en la auditoría del archivo (H5). */
    public const ATYPICAL_DATE = '2025-09-16';

    public function run(): void
    {
        $path = base_path(self::FIXTURE);
        if (! is_file($path)) {
            throw new RuntimeException("No existe el fixture {$path}");
        }

        /** @var array{rows: list<array<string, mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $branch = Branch::query()->where('code', BranchSeeder::MAIN_CODE)->firstOrFail();
        $admin = User::query()->orderBy('id')->firstOrFail();

        DB::transaction(function () use ($fixture, $branch, $admin): void {
            foreach ($fixture['rows'] as $row) {
                $date = CarbonImmutable::createFromFormat('Y-m-d', $row['date'])
                    ?? throw new RuntimeException("Fecha inválida en el fixture: {$row['date']}");

                ExchangeRate::query()->firstOrCreate(
                    ['date' => $date->toDateString()],
                    ['rate' => $row['exchange_rate'], 'source' => RateSource::Manual, 'set_by' => $admin->id],
                );

                DailyRecord::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'date' => $date->toDateString()],
                    [
                        'status' => $row['date'] === self::ATYPICAL_DATE ? DayStatus::Atypical : DayStatus::Normal,
                        'sales_bs' => $row['sales_bs'],
                        'exchange_rate' => $row['exchange_rate'],
                        'exchange_rate_source' => RateSource::Manual,
                        'transactions' => $row['transactions'],
                        'units' => $row['units'],
                        'inventory_units' => $row['inventory_units'],
                        'inventory_value_usd' => $row['inventory_value_usd'],
                        'shifts' => $row['shifts'],
                        'notes' => $row['date'] === self::ATYPICAL_DATE
                            ? 'Día atípico detectado en la auditoría del archivo original: 28 % de la venta habitual.'
                            : null,
                        'created_by' => $admin->id,
                    ],
                );
            }
        });
    }
}
