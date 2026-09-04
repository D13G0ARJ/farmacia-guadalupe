<?php

declare(strict_types=1);

use App\Domain\Imports\WorkbookParser;
use App\Enums\AnomalyType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const FIXTURE_XLSX = __DIR__.'/../../Fixtures/cuadro-septiembre-2025.xlsx';

/** Libro mínimo con la plantilla del cuadro para probar variantes. */
function workbook(array $overrides = [], array $rows = []): string
{
    $headers = ['#', 'Fecha', 'Venta BS ', 'Venta en $', 'Tasa $', 'TRN', 'Unidades', 'Ticket Promedio', "Unidades Promedio\n x Compra", 'Ticket Promedio en $', 'Unidades Cargadas (inventario)', 'Valuacion de Inventario costo', 'Transacciones/ Jornadas', 'Jornada'];
    $sheet = (new Spreadsheet)->getActiveSheet();
    $sheet->setTitle($overrides['title'] ?? 'Indicadores ');
    $sheet->setCellValue('B2', $overrides['month'] ?? 'OCTUBRE');
    $sheet->setCellValue('C2', 'FARMACIA GUADALUPE, C.A.');
    foreach (($overrides['headers'] ?? $headers) as $i => $h) {
        $sheet->setCellValue([$i + 1, 3], $h);
    }
    $r = 4;
    foreach ($rows as $row) {
        foreach ($row as $c => $value) {
            $sheet->setCellValue([$c + 1, $r], $value);
        }
        $r++;
    }
    $path = tempnam(sys_get_temp_dir(), 'cuadro').'.xlsx';
    (new Xlsx($sheet->getParent()))->save($path);

    return $path;
}

it('lee el archivo real: mes, razón social, 30 filas con primarios exactos y sábados sin inventario', function (): void {
    $month = (new WorkbookParser)->parse(FIXTURE_XLSX);

    expect($month->period)->toBe('2025-09')
        ->and($month->monthName)->toBe('SEPTIEMBRE')
        ->and($month->legalName)->toBe('FARMACIA GUADALUPE, C.A.')
        ->and($month->rows)->toHaveCount(30)
        ->and($month->anomalies)->toBe([]);

    $first = $month->rows[0];
    expect($first->date)->toBe('2025-09-01')
        ->and($first->row)->toBe(4)
        ->and($first->salesBs)->toBe('91154.02')
        ->and($first->rate)->toBe('148.44')
        ->and($first->transactions)->toBe(119)
        ->and($first->units)->toBe(300)
        ->and($first->inventoryUnits)->toBe(9029)
        ->and($first->inventoryValueUsd)->toBe('21848.73')
        ->and($first->shifts)->toBe(4)
        ->and($first->weekdayLetter)->toBe('v')
        // El archivo real no guarda los resultados de sus fórmulas: los derivados se recalculan siempre.
        ->and($first->fileDerived['D'])->toBeNull();

    $saturday = $month->rows[5];
    expect($saturday->date)->toBe('2025-09-06')
        ->and($saturday->inventoryUnits)->toBeNull()
        ->and($saturday->inventoryValueUsd)->toBeNull();
});

it('toma el mes de las fechas y avisa si el encabezado dice otro', function (): void {
    $path = workbook(['month' => 'OCTUBRE'], [
        ['L', '2025-11-03', 1000, null, 100, 10, 20, null, null, null, 500, 900, null, 3],
        ['M', '2025-11-04', 1200, null, 101, 12, 24, null, null, null, 510, 910, null, 3],
    ]);

    $month = (new WorkbookParser)->parse($path);

    expect($month->period)->toBe('2025-11')
        ->and($month->rows)->toHaveCount(2)
        ->and($month->anomalies)->toHaveCount(1)
        ->and($month->anomalies[0]->type)->toBe(AnomalyType::MonthMismatch)
        ->and($month->anomalies[0]->message)->toContain('OCTUBRE', 'noviembre 2025');
});

it('se detiene en la fila de totales y acepta la variante SETIEMBRE', function (): void {
    $path = workbook(['month' => 'Setiembre'], [
        ['L', '2025-09-01', 1000, null, 100, 10, 20, null, null, null, 500, 900, null, 3],
        [null, null, 1000, null, 100, 10, 20],
    ]);

    $month = (new WorkbookParser)->parse($path);

    expect($month->rows)->toHaveCount(1)->and($month->anomalies)->toBe([]);
});

it('rechaza un archivo cuya columna primaria no es la esperada y avisa de las secundarias', function (): void {
    $bad = ['#', 'Fecha', 'Ventas', 'Venta en $', 'Tasa $', 'TRN', 'Unidades', 'Ticket', "Unidades Promedio\n x Compra", 'Ticket Promedio en $', 'Unidades Cargadas (inventario)', 'Valuacion de Inventario costo', 'Transacciones/ Jornadas', 'Jornada'];
    expect(fn () => (new WorkbookParser)->parse(workbook(['headers' => $bad], [['L', '2025-09-01', 1, null, 1, 1, 1]])))
        ->toThrow(RuntimeException::class, 'columna C');

    $secondary = ['#', 'Fecha', 'Venta BS', 'Venta $', 'Tasa $', 'TRN', 'Unidades', 'Ticket', "Unidades Promedio\n x Compra", 'Ticket Promedio en $', 'Unidades Cargadas (inventario)', 'Valuacion de Inventario costo', 'Transacciones/ Jornadas', 'Jornada'];
    $month = (new WorkbookParser)->parse(workbook(['headers' => $secondary, 'month' => 'SEPTIEMBRE'], [['L', '2025-09-01', 1000, null, 100, 10, 20, null, null, null, null, null, null, 3]]));
    expect($month->anomalies)->toHaveCount(2)
        ->and($month->anomalies[0]->type)->toBe(AnomalyType::HeaderMismatch)
        ->and($month->anomalies[0]->message)->toContain('Columna D');
});

it('un archivo sin filas con fecha o sin encabezado explica el problema', function (): void {
    expect(fn () => (new WorkbookParser)->parse(workbook([], [])))->toThrow(RuntimeException::class, 'filas con fecha');

    $sheet = (new Spreadsheet)->getActiveSheet();
    $sheet->setCellValue('A1', 'Otra cosa');
    $path = tempnam(sys_get_temp_dir(), 'otro').'.xlsx';
    (new Xlsx($sheet->getParent()))->save($path);
    expect(fn () => (new WorkbookParser)->parse($path))->toThrow(RuntimeException::class, 'encabezados');
});
