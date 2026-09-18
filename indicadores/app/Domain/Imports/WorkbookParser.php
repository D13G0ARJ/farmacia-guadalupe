<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Enums\AnomalyType;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Lee el libro mensual con la plantilla actual (§10.2). Solo el contenido es fiable: el nombre del
 * archivo no se usa. Se abre en modo solo datos: sin evaluar fórmulas ni macros.
 */
final class WorkbookParser
{
    /** Etiquetas esperadas A–N, normalizadas. */
    private const HEADERS = [
        '#', 'fecha', 'venta bs', 'venta en $', 'tasa $', 'trn', 'unidades', 'ticket promedio',
        'unidades promedio x compra', 'ticket promedio en $', 'unidades cargadas (inventario)',
        'valuacion de inventario costo', 'transacciones/ jornadas', 'jornada',
    ];

    /** Columnas primarias (índice 0 = A): B, C, E, F, G, N. */
    private const PRIMARY = [1, 2, 4, 5, 6, 13];

    private const MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    private const MAX_HEADER_SCAN = 25;

    /** Tantas filas seguidas en blanco dan por terminada la tabla de días (A6). */
    private const MAX_BLANK_ROWS = 5;

    /** Última columna de la plantilla (N). */
    private const LAST_COLUMN = 14;

    public function parse(string $path): ParsedMonth
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $sheet = $this->findSheet($spreadsheet);

        $monthName = self::text($sheet->getCell('B2')->getValue());
        $legalName = self::text($sheet->getCell('C2')->getValue());

        $headerRow = $this->findHeaderRow($sheet);
        $anomalies = $this->headerAnomalies($sheet, $headerRow);

        $rows = [];
        $withoutDate = 0;
        $blankStreak = 0;
        $r = $headerRow + 1;
        $max = $sheet->getHighestDataRow();
        while ($r <= $max) {
            $cell = $sheet->getCell([2, $r])->getValue();
            $date = self::date($cell);
            if ($date === null) {
                // La fila de totales (o el inicio de otra tabla) trae texto donde iba la fecha: ahí termina el mes.
                if (self::text($cell) !== null) {
                    break;
                }
                if (self::rowIsBlank($sheet, $r)) {
                    // Un hueco en medio no corta la importación (A6); un bloque de filas vacías sí.
                    if (++$blankStreak >= self::MAX_BLANK_ROWS) {
                        break;
                    }
                    $r++;

                    continue;
                }
                // Fila con cifras del día pero sin fecha: no se puede cargar, se avisa al final.
                if (self::looksLikeDataRow($sheet, $r)) {
                    $withoutDate++;
                }
                $blankStreak = 0;
                $r++;

                continue;
            }
            $blankStreak = 0;
            $rows[] = new ParsedRow(
                row: $r,
                date: $date->toDateString(),
                weekdayLetter: self::text($sheet->getCell([1, $r])->getValue()),
                salesBs: self::decimal($sheet->getCell([3, $r])->getValue()),
                rate: self::decimal($sheet->getCell([5, $r])->getValue()),
                transactions: self::integer($sheet->getCell([6, $r])->getValue()),
                units: self::integer($sheet->getCell([7, $r])->getValue()),
                inventoryUnits: self::integer($sheet->getCell([11, $r])->getValue()),
                inventoryValueUsd: self::decimal($sheet->getCell([12, $r])->getValue()),
                shifts: self::integer($sheet->getCell([14, $r])->getValue()),
                fileDerived: [
                    'D' => self::float($sheet->getCell([4, $r])->getValue()),
                    'H' => self::float($sheet->getCell([8, $r])->getValue()),
                    'I' => self::float($sheet->getCell([9, $r])->getValue()),
                    'J' => self::float($sheet->getCell([10, $r])->getValue()),
                    'M' => self::float($sheet->getCell([13, $r])->getValue()),
                ],
            );
            $r++;
        }

        $spreadsheet->disconnectWorksheets();

        if ($rows === []) {
            throw new RuntimeException('No se encontraron filas con fecha debajo del encabezado.');
        }

        if ($withoutDate > 0) {
            $anomalies[] = new Anomaly(
                AnomalyType::RowWithoutDate,
                $withoutDate === 1
                    ? 'Se omitió 1 fila con datos pero sin fecha en la columna B.'
                    : "Se omitieron {$withoutDate} filas con datos pero sin fecha en la columna B.",
            );
        }

        $period = $this->dominantPeriod($rows);
        $declared = $monthName === null ? null : (self::MONTHS[self::normalize($monthName)] ?? null);
        if ($declared !== null && $declared !== (int) substr($period, 5, 2)) {
            $anomalies[] = new Anomaly(AnomalyType::MonthMismatch, "El encabezado dice {$monthName} pero las fechas son de ".self::monthLabel($period).'.');
        }

        return new ParsedMonth($period, $monthName, $legalName, $rows, $anomalies);
    }

    /**
     * La hoja "Indicadores"; si no está, la primera cuyo encabezado de la columna B diga "Fecha"
     * (los libros viejos la llaman de otras formas); en último caso, la primera hoja (B23).
     */
    private function findSheet(Spreadsheet $spreadsheet): Worksheet
    {
        $withHeader = null;
        foreach ($spreadsheet->getWorksheetIterator() as $candidate) {
            if (self::normalize($candidate->getTitle()) === 'indicadores') {
                return $candidate;
            }
            if ($withHeader === null && $this->headerRowIn($candidate) !== null) {
                $withHeader = $candidate;
            }
        }

        return $withHeader ?? $spreadsheet->getSheet(0);
    }

    private function findHeaderRow(Worksheet $sheet): int
    {
        return $this->headerRowIn($sheet) ?? throw new RuntimeException(
            'No se encontró la fila de encabezados (la celda "Fecha" en la columna B) en la hoja "'.$sheet->getTitle().'". '
            .'Revisa que la hoja con el cuadro del mes se llame "Indicadores" y que su columna B diga "Fecha".'
        );
    }

    private function headerRowIn(Worksheet $sheet): ?int
    {
        for ($r = 1; $r <= self::MAX_HEADER_SCAN; $r++) {
            if (self::normalize(self::text($sheet->getCell([2, $r])->getValue()) ?? '') === 'fecha') {
                return $r;
            }
        }

        return null;
    }

    /** Ninguna celda A–N con contenido. */
    private static function rowIsBlank(Worksheet $sheet, int $row): bool
    {
        for ($c = 1; $c <= self::LAST_COLUMN; $c++) {
            if (self::text($sheet->getCell([$c, $row])->getValue()) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Distingue un día al que le falta la fecha de la fila de totales: la plantilla siempre
     * escribe la letra del día en la columna A, y la de totales la deja vacía.
     */
    private static function looksLikeDataRow(Worksheet $sheet, int $row): bool
    {
        return self::text($sheet->getCell([1, $row])->getValue()) !== null;
    }

    /** @return list<Anomaly> */
    private function headerAnomalies(Worksheet $sheet, int $headerRow): array
    {
        $anomalies = [];
        foreach (self::HEADERS as $i => $expected) {
            $actual = self::normalize(self::text($sheet->getCell([$i + 1, $headerRow])->getValue()) ?? '');
            if ($actual === $expected) {
                continue;
            }
            $column = chr(ord('A') + $i);
            if (in_array($i, self::PRIMARY, true)) {
                throw new RuntimeException("La columna {$column} debería ser \"{$expected}\" y dice \"{$actual}\": el archivo no tiene la plantilla esperada.");
            }
            $anomalies[] = new Anomaly(AnomalyType::HeaderMismatch, "Columna {$column}: se esperaba \"{$expected}\" y dice \"{$actual}\". Se recalcula igual.");
        }

        return $anomalies;
    }

    /** @param  list<ParsedRow>  $rows */
    private function dominantPeriod(array $rows): string
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = substr($row->date, 0, 7);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', $value));
        $value = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return trim($value);
    }

    private static function monthLabel(string $period): string
    {
        return mb_strtolower(CarbonImmutable::parse($period.'-01')->locale('es')->translatedFormat('F Y'));
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }
        if (is_int($value) || is_float($value)) {
            if ($value < 20000 || $value > 80000) {
                return null;
            }

            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value))->startOfDay();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return CarbonImmutable::parse(trim($value))->startOfDay();
        }

        return null;
    }

    private static function float(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private static function decimal(mixed $value): ?string
    {
        $float = self::float($value);
        if ($float === null) {
            return null;
        }

        return rtrim(rtrim(number_format($float, 4, '.', ''), '0'), '.');
    }

    private static function integer(mixed $value): ?int
    {
        $float = self::float($value);

        return $float === null ? null : (int) round($float);
    }
}
