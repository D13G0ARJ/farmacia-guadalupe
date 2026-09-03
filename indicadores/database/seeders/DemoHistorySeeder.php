<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DayStatus;
use App\Enums\RateSource;
use App\Models\Branch;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mes de referencia sintético (agosto 2025) derivado de septiembre, para que la demo muestre
 * variaciones frente al mes anterior (§7.2). Idempotente. No se siembra junto al DemoSeeder para
 * que el mes auditado quede exactamente como en el Excel.
 */
class DemoHistorySeeder extends Seeder
{
    public const PERIOD = '2025-08';

    /** Factores de derivación: agosto vendió algo menos y con una tasa más baja. */
    private const SALES_FACTOR = '0.91';

    private const RATE_FACTOR = '0.92';

    private const VOLUME_FACTOR = '0.94';

    public function run(): void
    {
        $path = base_path(DemoSeeder::FIXTURE);
        if (! is_file($path)) {
            throw new RuntimeException("No existe el fixture {$path}");
        }

        /** @var array{rows: list<array<string, mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $fixture['rows'];

        $branch = Branch::query()->where('code', BranchSeeder::MAIN_CODE)->firstOrFail();
        $admin = User::query()->orderBy('id')->firstOrFail();
        $start = CarbonImmutable::createFromFormat('Y-m-d', self::PERIOD.'-01')
            ?? throw new RuntimeException('Período inválido');

        DB::transaction(function () use ($rows, $branch, $admin, $start): void {
            for ($day = 1; $day <= $start->daysInMonth; $day++) {
                $source = $rows[min($day, count($rows)) - 1];
                $date = $start->setDay($day);
                $rate = BigDecimal::of((string) $source['exchange_rate'])->multipliedBy(self::RATE_FACTOR)->toScale(4, RoundingMode::HalfUp);
                $sales = BigDecimal::of((string) $source['sales_bs'])->multipliedBy(self::SALES_FACTOR)->toScale(2, RoundingMode::HalfUp);
                $countsInventory = $branch->countsInventoryOn($date) && $source['inventory_units'] !== null;

                ExchangeRate::query()->firstOrCreate(
                    ['date' => $date->toDateString()],
                    ['rate' => (string) $rate, 'source' => RateSource::Manual, 'set_by' => $admin->id],
                );

                DailyRecord::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'date' => $date->toDateString()],
                    [
                        'status' => DayStatus::Normal,
                        'sales_bs' => (string) $sales,
                        'exchange_rate' => (string) $rate,
                        'exchange_rate_source' => RateSource::Manual,
                        'transactions' => (int) round($source['transactions'] * (float) self::VOLUME_FACTOR),
                        'units' => (int) round($source['units'] * (float) self::VOLUME_FACTOR),
                        'inventory_units' => $countsInventory ? (int) round($source['inventory_units'] * 0.97) : null,
                        'inventory_value_usd' => $countsInventory && $source['inventory_value_usd'] !== null
                            ? (string) BigDecimal::of((string) $source['inventory_value_usd'])->multipliedBy('0.97')->toScale(2, RoundingMode::HalfUp)
                            : null,
                        'shifts' => $source['shifts'],
                        'notes' => 'Dato de demostración derivado de septiembre.',
                        'created_by' => $admin->id,
                    ],
                );
            }
        });
    }
}
