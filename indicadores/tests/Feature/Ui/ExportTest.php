<?php

declare(strict_types=1);

use App\Exports\AnnualWorkbookExport;
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
        [$indicators, $annual, $rates] = $export->sheets();
        $rows = $indicators->array();
        $header = $rows[0];
        $first = $rows[3];
        $totals = $rows[array_key_last($rows)];
        $annualRows = $annual->array();
        $rateRows = $rates->array();

        return $header[1] === 'SEPTIEMBRE'
            && $header[2] === 'FARMACIA GUADALUPE, C.A.'
            && $rows[2][0] === 'Día'
            && $first[0] === 'lun' && $first[1] === '01/09/2025' && $first[2] === 91154.02
            && $totals[1] === 'Total del mes (ponderado)'
            && round($totals[7], 2) === 781.93
            && $totals[5] === 3853
            // Hoja Anual: fila 1 = Venta en Bs, columna de septiembre (índice 2 + 9 - 1 = 10)
            && $indicators->title() === 'Indicadores' && $annual->title() === 'Anual' && $rates->title() === 'Tasas'
            && $annualRows[2][1] === 'Mes' && $annualRows[2][10] === 'Septiembre' && $annualRows[2][14] === 'Total / Prom. año'
            && $annualRows[3][1] === 'Venta en Bs' && round($annualRows[3][10], 2) === 3012770.86 && $annualRows[3][2] === null
            && $annualRows[14][1] === 'Jornada' && $annualRows[14][10] === 93.0
            // Hoja Tasas: 30 filas más el encabezado
            && count($rateRows) === 31 && $rateRows[1][0] === '01/09/2025' && $rateRows[1][2] === 'Manual';
    });
});

it('descarga el año en Excel con la hoja Anual (UC-13)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    Excel::fake();

    $this->actingAs($admin)->get(route('exports.annual', ['year' => 2025]))->assertOk();

    Excel::assertDownloaded('indicadores-anual-2025.xlsx', function (AnnualWorkbookExport $export): bool {
        $rows = $export->sheets()[0]->array();

        return $rows[0][1] === 'Año 2025' && $rows[3][1] === 'Venta en Bs' && round($rows[3][10], 2) === 3012770.86;
    });

    $this->actingAs($admin)->get(route('exports.annual', ['year' => 1999]))->assertNotFound();
});

it('exige sesión para descargar', function (): void {
    $this->get(route('exports.month', ['period' => '2025-09']))->assertRedirect(route('login'));
});

it('rechaza un período inválido', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $this->actingAs($admin)->get(route('exports.month', ['period' => 'nada']))->assertNotFound();
});
