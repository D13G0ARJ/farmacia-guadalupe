<?php

declare(strict_types=1);

use App\Domain\Imports\WorkbookParser;
use App\Exports\AnnualWorkbookExport;
use App\Exports\MonthWorkbookExport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;

uses(RefreshDatabase::class);

it('descarga el mes en Excel con encabezado, filas y totales ponderados (UC-14)', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();
    Excel::fake();

    $this->actingAs($admin)->get(route('exports.month', ['period' => '2025-09']))->assertOk();

    Excel::assertDownloaded('indicadores-2025-09.xlsx', function (MonthWorkbookExport $export): bool {
        [$indicators, $annual, $rates] = $export->sheets();
        $rows = $indicators->array();
        $header = $rows[1];
        $first = $rows[3];
        $totals = $rows[array_key_last($rows)];
        $annualRows = $annual->array();
        $rateRows = $rates->array();

        return $header[1] === 'SEPTIEMBRE'
            && $header[2] === 'FARMACIA GUADALUPE, C.A.'
            && $rows[2][0] === 'Día'
            && $first[0] === 'lun' && ExcelDate::excelToDateTimeObject($first[1])->format('d/m/Y') === '01/09/2025' && $first[2] === 91154.02
            && $totals[1] === 'Total del mes (ponderado)'
            && round($totals[7], 2) === 781.93
            && $totals[5] === 3853
            // Hoja Anual: fila 1 = Venta en Bs, columna de septiembre (índice 2 + 9 - 1 = 10)
            && $indicators->title() === 'Indicadores' && $annual->title() === 'Anual' && $rates->title() === 'Tasas'
            && $annualRows[2][1] === 'Mes' && $annualRows[2][10] === 'Septiembre' && $annualRows[2][14] === 'Total / Prom. año'
            && $annualRows[3][1] === 'Venta en Bs' && round($annualRows[3][10], 2) === 3012770.86 && $annualRows[3][2] === null
            && $annualRows[14][1] === 'Jornada' && $annualRows[14][10] === 93.0
            // Hoja Tasas: 30 filas más el encabezado
            && count($rateRows) === 31 && ExcelDate::excelToDateTimeObject($rateRows[1][0])->format('d/m/Y') === '01/09/2025' && $rateRows[1][2] === 'Manual';
    });
});

it('el Excel exportado conserva la estructura del cuadro (encabezado en la fila 3, totales en negrita, fechas reales) y se puede volver a importar', function (): void {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $admin = User::query()->where('email', 'admin@guadalupe.local')->firstOrFail();

    $response = $this->actingAs($admin)->get(route('exports.month', ['period' => '2025-09']));
    $response->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    $sheet = IOFactory::load($path)->getSheetByName('Indicadores');
    expect($sheet->getCell('B2')->getValue())->toBe('SEPTIEMBRE')
        ->and($sheet->getCell('C2')->getValue())->toBe('FARMACIA GUADALUPE, C.A.')
        ->and($sheet->getStyle('B2')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getCell('B3')->getValue())->toBe('Fecha')
        ->and($sheet->getStyle('B3')->getFill()->getStartColor()->getRGB())->toBe('1D6FE5')
        ->and($sheet->getStyle('B4')->getFill()->getFillType())->not->toBe(Fill::FILL_SOLID)
        ->and(ExcelDate::isDateTime($sheet->getCell('B4')))->toBeTrue()
        ->and($sheet->getCell('B4')->getFormattedValue())->toBe('01/09/2025')
        ->and($sheet->getCell('B35')->getValue())->toBe('Total del mes (ponderado)')
        ->and($sheet->getStyle('B35')->getFont()->getBold())->toBeTrue();

    // Ida y vuelta: lo que sale del sistema entra de nuevo sin anomalías de encabezado
    $month = app(WorkbookParser::class)->parse($path);
    expect($month->period)->toBe('2025-09')
        ->and($month->monthName)->toBe('SEPTIEMBRE')
        ->and($month->legalName)->toBe('FARMACIA GUADALUPE, C.A.')
        ->and($month->rows)->toHaveCount(30)
        ->and($month->rows[0]->date)->toBe('2025-09-01')
        ->and($month->rows[0]->salesBs)->toBe('91154.02')
        ->and($month->rows[0]->shifts)->toBe(4)
        ->and($month->anomalies)->toBe([]);
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
