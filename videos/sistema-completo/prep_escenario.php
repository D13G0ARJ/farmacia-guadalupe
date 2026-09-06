<?php

/**
 * Escenario de grabación sobre la COPIA de la base (database/capacitacion.sqlite). Aborta si no es la copia.
 * Ejecutar: DB_DATABASE=<ruta a capacitacion.sqlite> php artisan tinker videos/sistema-completo/prep_escenario.php
 */

use App\Actions\Records\RegisterClosedDay;
use App\Actions\Records\RegisterDailyRecord;
use App\Domain\Records\DailyRecordInput;
use App\Enums\DayStatus;
use App\Models\DailyRecord;
use App\Models\ImportBatch;
use App\Models\PeriodEvent;
use App\Models\Setting;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (! str_contains((string) DB::getDatabaseName(), 'capacitacion')) {
    throw new RuntimeException('Esto solo corre sobre la copia capacitacion.sqlite, no sobre la base real: '.DB::getDatabaseName());
}

$admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
$branch = $admin->accessibleBranches()->first();
$month = CarbonImmutable::today()->startOfMonth();

// Mes en curso: dos días cargados y uno cerrado, para que el calendario muestre todos los estados
DailyRecord::query()->where('date', '>=', $month->toDateString())->delete();
PeriodEvent::query()->where('period', '>=', $month->toDateString())->delete();
ImportBatch::query()->delete();
DailyRecord::query()->where('date', '>=', '2020-01-01')->where('date', '<', '2020-02-01')->delete();
User::query()->where('email', 'like', '%@farmacia.com')->delete();
Setting::query()->whereIn('key', ['report_email_day', 'report_recipients'])->delete();
Artisan::call('db:seed', ['--class' => 'DemoGoalsSeeder', '--force' => true]); // metas como en la demostración
Artisan::call('cache:clear');

$register = app(RegisterDailyRecord::class);
$samples = [
    1 => ['sales' => '812450.00', 'trn' => 128, 'units' => 296, 'inv' => 9720, 'val' => '22380.00'],
    2 => ['sales' => '868120.50', 'trn' => 137, 'units' => 311, 'inv' => 9705, 'val' => '22265.00'],
];
foreach ($samples as $day => $s) {
    $date = $month->setDay($day);
    if ($date->gt(CarbonImmutable::today())) {
        continue;
    }
    $register->handle(new DailyRecordInput(
        branchId: $branch->id,
        date: $date,
        salesBs: BigDecimal::of($s['sales']),
        rate: null,
        transactions: $s['trn'],
        units: $s['units'],
        inventoryUnits: $s['inv'],
        inventoryValueUsd: BigDecimal::of($s['val']),
        shifts: 3,
        notes: null,
        status: DayStatus::Normal,
    ), $admin);
}
if ($month->setDay(3)->lte(CarbonImmutable::today())) {
    app(RegisterClosedDay::class)->handle($branch->id, $month->setDay(3), 'Inventario general', $admin);
}

// Septiembre 2025 abierto y con el día atípico de la demostración
PeriodEvent::query()->where('period', '2025-09-01')->delete();
DailyRecord::query()->where('date', '2025-09-16')->update(['status' => DayStatus::Atypical]);

echo 'Escenario listo: '.DailyRecord::query()->where('date', '>=', $month->toDateString())->count().' días en el mes en curso'.PHP_EOL;
