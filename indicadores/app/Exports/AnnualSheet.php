<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Indicators\Indicator;
use App\Queries\AnnualView;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Anual" (§11.1, §2.4): los 12 indicadores por mes en el orden del Excel, meses sin datos vacíos
 * y la columna del año calculada sobre todos los días (ponderada).
 */
final class AnnualSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    /** Etiquetas de la tabla anual original (§2.4). */
    private const LABELS = [
        'sales_bs' => 'Venta en Bs',
        'sales_usd' => 'Venta en $',
        'avg_rate' => 'Promedio Tasa $',
        'transactions' => 'Transacciones',
        'units' => 'Unidades',
        'avg_ticket_bs' => 'Ticket Promedio en Bs',
        'units_per_transaction' => 'Unidades Promedio x Compra',
        'avg_ticket_usd' => 'Ticket Promedio en $',
        'inventory_value_usd' => 'Valuacion de Inventario',
        'inventory_units' => 'unidades',
        'transactions_per_shift' => 'transaccionesxjornada',
        'shifts' => 'Jornada',
    ];

    private const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(private readonly AnnualView $annual) {}

    public function title(): string
    {
        return 'Anual';
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [
            [null, 'Año '.$this->annual->year],
            [],
            ['#', 'Mes', ...self::MONTHS, 'Total / Prom. año'],
        ];

        foreach (Indicator::annualOrder() as $i => $indicator) {
            $row = [$i + 1, self::LABELS[$indicator->value] ?? $indicator->label()];
            for ($m = 1; $m <= 12; $m++) {
                $row[] = IndicatorsSheet::num($this->annual->value($indicator, $m));
            }
            $row[] = IndicatorsSheet::num($this->annual->yearValue($indicator));
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];
        foreach (range('C', 'O') as $column) {
            $formats[$column] = '#,##0.00';
        }

        return $formats;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            3 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D6FE5']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            ],
            'O' => ['font' => ['bold' => true]],
        ];
    }
}
