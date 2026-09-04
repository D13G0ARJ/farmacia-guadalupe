<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Indicators\Indicator;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Exports\AnnualWorkbookExport;
use App\Exports\MonthWorkbookExport;
use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Queries\AnnualComparisonQuery;
use App\Queries\DashboardQuery;
use App\Queries\MonthRecordsQuery;
use App\Support\CurrentBranch;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** UC-14: descargas del mes y del año, y el reporte PDF con las gráficas que envía el navegador. */
class ExportController extends Controller
{
    private const CHART_TTL_SECONDS = 600;

    private const MAX_IMAGES = 12;

    private const MAX_IMAGE_BYTES = 1048576;

    public function month(Request $request, string $period, MonthRecordsQuery $query, AnnualComparisonQuery $annual, Formatter $formatter): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', DailyRecord::class), 403);
        $target = $this->period($period);

        $branch = app(CurrentBranch::class)->resolve($user);
        $view = $query->run($branch?->id, $target);
        $legalName = $branch === null ? 'FARMACIA GUADALUPE, C.A.' : $branch->legal_name;
        $rates = ExchangeRate::query()
            ->with('setter')
            ->whereBetween('date', [$target->start->toDateString(), $target->end()->toDateString()])
            ->orderBy('date')
            ->get();

        $filename = sprintf('indicadores-%s%s.xlsx', $target->key(), $branch === null ? '-consolidado' : '');

        return Excel::download(new MonthWorkbookExport($view, $legalName, $formatter, $annual->run($branch?->id, $target->start->year), $rates), $filename);
    }

    public function annual(Request $request, int $year, AnnualComparisonQuery $annual): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', DailyRecord::class), 403);
        abort_unless($year >= 2000 && $year <= 2100, 404);

        $branch = app(CurrentBranch::class)->resolve($user);

        return Excel::download(new AnnualWorkbookExport($annual->run($branch?->id, $year)), sprintf('indicadores-anual-%d.xlsx', $year));
    }

    /**
     * El navegador envía las gráficas del panel como PNG (§11.2) justo antes de pedir el PDF.
     * Se guardan 10 minutos por usuario y mes; si no llegan, el PDF sale igual sin ellas.
     */
    public function storeChartImages(Request $request, string $period): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', DailyRecord::class), 403);
        $target = $this->period($period);

        $images = $request->input('images');
        if (! is_array($images) || count($images) > self::MAX_IMAGES) {
            return response()->json(['message' => 'Se esperaban hasta '.self::MAX_IMAGES.' imágenes.'], 422);
        }

        $accepted = [];
        foreach ($images as $id => $dataUrl) {
            if (! is_string($id) || preg_match('/^[a-z0-9_-]{1,20}$/', $id) !== 1 || ! is_string($dataUrl)) {
                continue;
            }
            if (! str_starts_with($dataUrl, 'data:image/png;base64,') || strlen($dataUrl) > self::MAX_IMAGE_BYTES * 1.4) {
                continue;
            }
            $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
            if ($binary === false || strlen($binary) > self::MAX_IMAGE_BYTES || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
                continue;
            }
            $accepted[$id] = $dataUrl;
        }

        Cache::put($this->chartsKey($user->id, $target), $accepted, self::CHART_TTL_SECONDS);

        return response()->json(['stored' => count($accepted)]);
    }

    public function monthPdf(Request $request, string $period, DashboardQuery $dashboardQuery, Formatter $formatter): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', DailyRecord::class), 403);
        $target = $this->period($period);

        $branch = app(CurrentBranch::class)->resolve($user);
        $dashboard = $dashboardQuery->run($branch?->id, $target);
        $images = Cache::pull($this->chartsKey($user->id, $target), []);

        $pdf = Pdf::loadView('reports.month', [
            'dashboard' => $dashboard,
            'view' => $dashboard->month,
            'branch' => $branch,
            'legalName' => $branch === null ? 'FARMACIA GUADALUPE, C.A.' : $branch->legal_name,
            'images' => is_array($images) ? $images : [],
            'formatter' => $formatter,
            'primary' => Indicator::primary(),
            'secondary' => Indicator::secondary(),
            'generatedBy' => $user->name,
            'generatedAt' => CarbonImmutable::now(),
            'canSeeGoals' => $user->can('goals.view'),
        ])->setPaper('a4', 'landscape');

        $filename = sprintf('reporte-%s%s.pdf', $target->key(), $branch === null ? '-consolidado' : '');

        return $pdf->download($filename);
    }

    private function period(string $period): Period
    {
        try {
            return Period::of($period);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    private function chartsKey(int $userId, Period $period): string
    {
        return "report-charts:{$userId}:{$period->key()}";
    }
}
