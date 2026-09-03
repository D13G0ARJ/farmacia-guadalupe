<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Exports\MonthWorkbookExport;
use App\Models\DailyRecord;
use App\Queries\MonthRecordsQuery;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** UC-14: descargas del mes. */
class ExportController extends Controller
{
    public function month(Request $request, string $period, MonthRecordsQuery $query, Formatter $formatter): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', DailyRecord::class), 403);

        try {
            $target = Period::of($period);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $branch = app(CurrentBranch::class)->resolve($user);
        $view = $query->run($branch?->id, $target);
        $legalName = $branch === null ? 'FARMACIA GUADALUPE, C.A.' : $branch->legal_name;

        $filename = sprintf('indicadores-%s%s.xlsx', $target->key(), $branch === null ? '-consolidado' : '');

        return Excel::download(new MonthWorkbookExport($view, $legalName, $formatter), $filename);
    }
}
