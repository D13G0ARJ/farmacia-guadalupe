<?php

declare(strict_types=1);

namespace App\Domain\Rates;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee los libros trimestrales que publica el BCV en "Tipo de cambio de referencia" (§9.2):
 * una hoja por día de operación, con "Fecha Valor" (vigencia) en la fila 5 y la fila USD con
 * la venta (ASK) en bolívares en la columna G. Devuelve vigencia → tasa.
 */
final class BcvHistoryParser
{
    /**
     * @return array<string, BigDecimal> fecha de vigencia (Y-m-d) → Bs por dólar
     */
    public function parse(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $rates = [];
        foreach ($book->getAllSheets() as $sheet) {
            $date = $this->effectiveDate($sheet);
            $rate = $this->usdSell($sheet);
            if ($date !== null && $rate !== null) {
                $rates[$date->toDateString()] = $rate;
            }
        }
        $book->disconnectWorksheets();
        ksort($rates);

        return $rates;
    }

    /** "Fecha Valor: 01/04/2025" en alguna celda de las primeras filas; si falta, la fecha del nombre de la hoja. */
    private function effectiveDate(Worksheet $sheet): ?CarbonImmutable
    {
        for ($row = 1; $row <= 8; $row++) {
            foreach (range('A', 'H') as $column) {
                $value = $sheet->getCell($column.$row)->getValue();
                if (is_string($value) && preg_match('/Fecha\s+Valor:?\s*(\d{2})\/(\d{2})\/(\d{4})/i', $value, $m) === 1) {
                    return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1])?->startOfDay();
                }
            }
        }

        return preg_match('/^(\d{2})(\d{2})(\d{4})$/', $sheet->getTitle(), $m) === 1
            ? CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1])?->startOfDay()
            : null;
    }

    /** Fila cuya columna B dice USD: la venta en Bs está en G (o, si falta, la compra en F). */
    private function usdSell(Worksheet $sheet): ?BigDecimal
    {
        $last = min($sheet->getHighestRow(), 60);
        for ($row = 9; $row <= $last; $row++) {
            if (mb_strtoupper(trim((string) $sheet->getCell('B'.$row)->getValue())) !== 'USD') {
                continue;
            }
            foreach (['G', 'F'] as $column) {
                $value = $sheet->getCell($column.$row)->getValue();
                if (is_numeric($value) && (float) $value > 0) {
                    try {
                        return BigDecimal::of((string) $value);
                    } catch (MathException) {
                        return null;
                    }
                }
            }
        }

        return null;
    }
}
