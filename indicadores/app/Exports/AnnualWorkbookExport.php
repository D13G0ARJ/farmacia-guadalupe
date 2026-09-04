<?php

declare(strict_types=1);

namespace App\Exports;

use App\Queries\AnnualView;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Libro del año (UC-13): solo la hoja Anual. */
final class AnnualWorkbookExport implements Export, WithMultipleSheets
{
    public function __construct(private readonly AnnualView $annual) {}

    /**
     * @return list<AnnualSheet>
     */
    public function sheets(): array
    {
        return [new AnnualSheet($this->annual)];
    }
}
