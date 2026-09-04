<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Shared\Formatter;
use App\Models\ExchangeRate;
use App\Queries\AnnualView;
use App\Queries\MonthView;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Libro del mes (§11.1): hojas Indicadores (el cuadro), Anual (la tabla del año) y Tasas.
 */
final class MonthWorkbookExport implements Export, WithMultipleSheets
{
    /** @param  Collection<int, ExchangeRate>  $rates */
    public function __construct(
        private readonly MonthView $view,
        private readonly string $legalName,
        private readonly Formatter $formatter,
        private readonly AnnualView $annual,
        private readonly Collection $rates,
    ) {}

    /**
     * @return list<IndicatorsSheet|AnnualSheet|RatesSheet>
     */
    public function sheets(): array
    {
        return [
            new IndicatorsSheet($this->view, $this->legalName, $this->formatter),
            new AnnualSheet($this->annual),
            new RatesSheet($this->rates),
        ];
    }
}
