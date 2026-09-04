<?php

declare(strict_types=1);

use App\Domain\Imports\Anomaly;
use App\Domain\Imports\AnomalyDetector;
use App\Domain\Imports\ImportContext;
use App\Domain\Imports\ParsedMonth;
use App\Domain\Imports\ParsedRow;
use App\Domain\Shared\Formatter;
use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\SeptemberFixture;

const LETTERS = [1 => 'L', 2 => 'M', 3 => 'M', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

/** Septiembre real como filas leídas, con la letra del día correcta. */
function septemberParsed(): ParsedMonth
{
    $rows = [];
    foreach (SeptemberFixture::raw()['rows'] as $i => $row) {
        $date = CarbonImmutable::parse($row['date']);
        $rows[] = new ParsedRow(
            row: $i + 4,
            date: $row['date'],
            weekdayLetter: LETTERS[$date->dayOfWeekIso],
            salesBs: (string) $row['sales_bs'],
            rate: (string) $row['exchange_rate'],
            transactions: (int) $row['transactions'],
            units: (int) $row['units'],
            inventoryUnits: $row['inventory_units'],
            inventoryValueUsd: $row['inventory_value_usd'],
            shifts: (int) $row['shifts'],
            fileDerived: ['D' => (float) $row['sales_bs'] / (float) $row['exchange_rate']],
        );
    }

    return new ParsedMonth('2025-09', 'SEPTIEMBRE', 'FARMACIA GUADALUPE, C.A.', $rows);
}

function importContext(array $overrides = []): ImportContext
{
    return new ImportContext(
        inventoryDays: $overrides['inventoryDays'] ?? Branch::DEFAULT_INVENTORY_DAYS,
        existingRecords: $overrides['existingRecords'] ?? 0,
        existingRates: $overrides['existingRates'] ?? [],
        alreadyImportedAt: $overrides['alreadyImportedAt'] ?? null,
    );
}

function types(array $anomalies): array
{
    return array_map(fn (Anomaly $a) => $a->type->value, $anomalies);
}

beforeEach(fn () => CarbonImmutable::setTestNow('2025-10-03 09:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('en el mes real solo encuentra el atípico del 16/09 y su inventario vacío; nada bloquea', function (): void {
    $anomalies = (new AnomalyDetector(new Formatter))->detect(septemberParsed(), importContext());

    $byType = array_count_values(types($anomalies));
    $deviation = array_values(array_filter($anomalies, fn (Anomaly $a) => $a->type === AnomalyType::SalesDeviation));

    expect($byType[AnomalyType::InventoryMissingOnCountDay->value] ?? 0)->toBe(1)
        ->and(array_column(array_map(fn (Anomaly $a) => $a->toArray(), $deviation), 'date'))->toContain('2025-09-16')
        ->and($deviation[0]->message)->toContain('Bs', '%', 'mediana')
        ->and($byType[AnomalyType::DerivedMismatch->value] ?? 0)->toBe(0)
        ->and($byType[AnomalyType::WeekdayMismatch->value] ?? 0)->toBe(0)
        ->and(array_filter($anomalies, fn (Anomaly $a) => $a->severity() === AnomalySeverity::High))->toBe([]);
});

it('detecta duplicado, día faltante, fecha fuera del mes, negativo y dato vacío como altas', function (): void {
    $base = septemberParsed();
    $rows = $base->rows;
    $rows[] = new ParsedRow(40, '2025-09-10', 'M', '1', '150', 1, 1, null, null, 3);            // duplicado
    unset($rows[4]);                                                                                 // falta el 05/09
    $rows[] = new ParsedRow(41, '2025-10-01', 'M', '1', '150', 1, 1, null, null, 3);            // fuera del mes
    $rows[] = new ParsedRow(42, '2025-09-05', 'V', '-5', '150', 1, 1, null, null, 3);           // negativo (repone el 05)
    $rows[] = new ParsedRow(43, '2025-09-31', 'X', '1', '150', 1, 1, null, null, 3);            // no existe: no se lee como fecha
    $rows[7] = new ParsedRow(11, '2025-09-08', 'L', null, '154.01', 117, 234, 9383, '22196', 3); // sin venta

    $anomalies = (new AnomalyDetector(new Formatter))->detect(new ParsedMonth('2025-09', 'SEPTIEMBRE', null, array_values($rows)), importContext());
    $ids = array_map(fn (Anomaly $a) => $a->id(), $anomalies);

    expect($ids)->toContain('duplicate_date:2025-09-10', 'date_out_of_period:2025-10-01', 'negative_value:2025-09-05', 'missing_value:2025-09-08')
        ->and($ids)->not->toContain('missing_day:2025-09-05')
        ->and(array_filter($anomalies, fn (Anomaly $a) => $a->type === AnomalyType::MissingDay))->toBe([]);

    $withoutFive = array_values(array_filter($base->rows, fn (ParsedRow $r) => $r->date !== '2025-09-05'));
    $missing = (new AnomalyDetector(new Formatter))->detect(new ParsedMonth('2025-09', 'SEPTIEMBRE', null, $withoutFive), importContext());
    expect(array_map(fn (Anomaly $a) => $a->id(), $missing))->toContain('missing_day:2025-09-05');
});

it('detecta salto de tasa, unidades < transacciones y letra de día equivocada con el dato concreto', function (): void {
    $base = septemberParsed();
    $rows = $base->rows;
    $rows[2] = new ParsedRow(6, '2025-09-03', 'V', '102970.43', '200', 132, 100, 9914, '22631.16', 3);

    $anomalies = (new AnomalyDetector(new Formatter))->detect(new ParsedMonth('2025-09', 'SEPTIEMBRE', null, $rows), importContext());
    $find = fn (AnomalyType $t) => array_values(array_filter($anomalies, fn (Anomaly $a) => $a->type === $t && $a->date === '2025-09-03'))[0] ?? null;

    expect($find(AnomalyType::RateJump)?->message)->toContain('149,46', '200,00')
        ->and($find(AnomalyType::UnitsLtTransactions)?->message)->toContain('100 unidades', '132 transacciones')
        ->and($find(AnomalyType::WeekdayMismatch)?->message)->toContain('"V"', 'mié')
        ->and($find(AnomalyType::RateJump)?->severity())->toBe(AnomalySeverity::Medium);
});

it('avisa de mes ya importado, mismo archivo ya importado y tasa en conflicto', function (): void {
    $detector = new AnomalyDetector(new Formatter);
    $month = septemberParsed();

    $existing = $detector->detect($month, importContext(['existingRecords' => 30]));
    $hash = $detector->detect($month, importContext(['alreadyImportedAt' => '02/09/2026']));
    $conflict = $detector->detect($month, importContext(['existingRates' => ['2025-09-01' => '148.4400', '2025-09-02' => '150.0000']]));

    expect(types($existing))->toContain(AnomalyType::AlreadyImported->value)
        ->and($existing[0]->message)->toContain('30 días cargados')
        ->and($hash[0]->message)->toContain('02/09/2026')
        ->and(array_map(fn (Anomaly $a) => $a->id(), array_filter($conflict, fn (Anomaly $a) => $a->type === AnomalyType::RateConflict)))->toBe(['rate_conflict:2025-09-02']);
});

it('marca la fórmula rota del archivo como informativa', function (): void {
    $base = septemberParsed();
    $rows = $base->rows;
    $rows[0] = new ParsedRow(4, '2025-09-01', 'L', '91154.02', '148.44', 119, 300, 9029, '21848.73', 4, ['D' => 999.0]);

    $anomalies = (new AnomalyDetector(new Formatter))->detect(new ParsedMonth('2025-09', 'SEPTIEMBRE', null, $rows), importContext());
    $derived = array_values(array_filter($anomalies, fn (Anomaly $a) => $a->type === AnomalyType::DerivedMismatch));

    expect($derived)->toHaveCount(1)
        ->and($derived[0]->message)->toContain('venta en $', '999,00', '614,08')
        ->and($derived[0]->severity())->toBe(AnomalySeverity::Info)
        ->and($derived[0]->type->options())->toBe([]);
});
