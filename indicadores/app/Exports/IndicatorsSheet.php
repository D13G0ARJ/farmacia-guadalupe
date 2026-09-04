<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Indicators\DailyMetrics;
use App\Domain\Shared\Formatter;
use App\Queries\MonthView;
use Brick\Math\BigDecimal;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Indicadores" (§11.1): el cuadro del mes con las mismas columnas y orden del Excel original,
 * día de la semana correcto, derivados como valores y totales ponderados separados por una fila.
 */
final class IndicatorsSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    public const HEADINGS = [
        'Día', 'Fecha', 'Venta Bs', 'Venta en $', 'Tasa $', 'TRN', 'Unidades', 'Ticket promedio',
        'Unidades promedio x compra', 'Ticket promedio en $', 'Unidades cargadas (inventario)',
        'Valuación de inventario costo', 'Transacciones / jornadas', 'Jornada',
    ];

    public function __construct(
        private readonly MonthView $view,
        private readonly string $legalName,
        private readonly Formatter $formatter,
    ) {}

    public function title(): string
    {
        return 'Indicadores';
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [
            [null, $this->view->period->monthNameUpper(), $this->legalName],
            [],
            self::HEADINGS,
        ];

        foreach ($this->view->rows as $m) {
            $rows[] = $this->row($m);
        }

        $rows[] = [];
        $rows[] = $this->totals();

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'C' => '#,##0.00', 'D' => '0', 'E' => '#,##0.00', 'H' => '#,##0', 'I' => '0.0', 'J' => '0.0',
            'K' => '#,##0', 'L' => '#,##0', 'M' => '0',
        ];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $lastRow = 3 + count($this->view->rows) + 2;

        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            3 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D6FE5']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            ],
            $lastRow => ['font' => ['bold' => true]],
        ];
    }

    /**
     * @return list<mixed>
     */
    private function row(DailyMetrics $m): array
    {
        return [
            $this->formatter->weekday($m->data->date),
            $m->data->date->format('d/m/Y'),
            self::num($m->data->salesBs),
            self::num($m->salesUsd),
            self::num($m->data->rate),
            $m->data->transactions,
            $m->data->units,
            self::num($m->avgTicketBs),
            self::num($m->unitsPerTransaction),
            self::num($m->avgTicketUsd),
            $m->data->inventoryUnits,
            self::num($m->data->inventoryValueUsd),
            self::num($m->transactionsPerShift),
            $m->data->shifts,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function totals(): array
    {
        $s = $this->view->summary;

        return [
            null, 'Total del mes (ponderado)',
            self::num($s->sumsAll['salesBs']),
            self::num($s->sumsAll['salesUsd']),
            self::num($s->avgRate),
            $s->sumsAll['transactions'],
            $s->sumsAll['units'],
            self::num($s->avgTicketBs),
            self::num($s->unitsPerTransaction),
            self::num($s->avgTicketUsd),
            self::num($s->inventoryAvgUnits),
            self::num($s->inventoryAvgValueUsd),
            self::num($s->transactionsPerShift),
            $s->sumsAll['shifts'],
        ];
    }

    public static function num(?BigDecimal $value): ?float
    {
        // Solo para la celda de Excel: el dominio nunca usa float (RN-19).
        return $value?->toFloat();
    }
}
