<?php

declare(strict_types=1);

use App\Exports\MonthWorkbookExport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

it('descarga el mes en Excel con encabezado, filas y totales ponderados (UC-14)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    Excel::fake();

    $this->actingAs($admin)->get(route('exports.month', ['period' => '2025-09']))->assertOk();

    Excel::assertDownloaded('indicadores-2025-09.xlsx', function (MonthWorkbookExport $export): bool {
        $rows = $export->array();
        $header = $rows[0];
        $first = $rows[3];
        $totals = $rows[array_key_last($rows)];

        return $header[1] === 'SEPTIEMBRE'
            && $header[2] === 'FARMACIA GUADALUPE, C.A.'
            && $rows[2][0] === 'Día'
            && $first[0] === 'lun' && $first[1] === '01/09/2025' && $first[2] === 91154.02
            && $totals[1] === 'Total del mes (ponderado)'
            && round($totals[7], 2) === 781.93
            && $totals[5] === 3853;
    });
});

it('exige sesión para descargar', function (): void {
    $this->get(route('exports.month', ['period' => '2025-09']))->assertRedirect(route('login'));
});

it('rechaza un período inválido', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $this->actingAs($admin)->get(route('exports.month', ['period' => 'nada']))->assertNotFound();
});
