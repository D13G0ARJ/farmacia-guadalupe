<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\Currency;
use App\Mail\MonthlyReportMail;
use App\Models\Branch;
use App\Queries\DashboardQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Arma el correo del reporte mensual (§11.2) para una sede y un mes: PDF sin gráficas
 * (se dibujan en el navegador) y resumen en el cuerpo.
 */
final class BuildMonthlyReport
{
    public function __construct(
        private readonly DashboardQuery $dashboardQuery,
        private readonly Formatter $formatter,
    ) {}

    public function handle(Branch $branch, Period $period): MonthlyReportMail
    {
        $dashboard = $this->dashboardQuery->run($branch->id, $period);
        $summary = $dashboard->summary();

        $pdf = Pdf::loadView('reports.month', [
            'dashboard' => $dashboard,
            'view' => $dashboard->month,
            'branch' => $branch,
            'legalName' => $branch->legal_name,
            'images' => [],
            'formatter' => $this->formatter,
            'primary' => Indicator::primary(),
            'secondary' => Indicator::secondary(),
            'generatedBy' => 'envío programado',
            'generatedAt' => CarbonImmutable::now(),
            'canSeeGoals' => true,
        ])->setPaper('a4', 'landscape')->output();

        return new MonthlyReportMail($period, $branch, [
            'days' => $summary->days,
            'salesUsd' => $this->formatter->money($summary->sumsAll['salesUsd'], Currency::Usd, 0),
            'salesBs' => $this->formatter->money($summary->sumsAll['salesBs'], Currency::Bs, 0),
            'transactions' => $this->formatter->number($summary->sumsAll['transactions']),
            'missing' => count($dashboard->month->missingDates),
            'closed' => $dashboard->month->isClosed,
        ], $pdf);
    }
}
