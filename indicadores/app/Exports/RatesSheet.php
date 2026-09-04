<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\ExchangeRate;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Hoja "Tasas" (§11.1): fecha, tasa, fuente y quién la fijó. */
final class RatesSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    /** @param  Collection<int, ExchangeRate>  $rates */
    public function __construct(private readonly Collection $rates) {}

    public function title(): string
    {
        return 'Tasas';
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [['Fecha', 'Tasa (Bs por $)', 'Fuente', 'Fijada por']];
        foreach ($this->rates as $rate) {
            $rows[] = [
                $rate->date->format('d/m/Y'),
                IndicatorsSheet::num($rate->rate),
                $rate->source->label(),
                $rate->setter !== null ? $rate->setter->name : ($rate->source->value === 'bcv' ? 'BCV (automática)' : null),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['B' => '#,##0.0000'];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D6FE5']],
            ],
        ];
    }
}
