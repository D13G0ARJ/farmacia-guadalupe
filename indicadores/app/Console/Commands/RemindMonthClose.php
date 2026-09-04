<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Period;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\PeriodEvent;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MonthCloseReminder;
use App\Queries\MonthRecordsQuery;
use Illuminate\Console\Command;

/**
 * Recordatorio de cierre (§13.8): a quien puede cerrar el mes, si el anterior tiene días
 * faltantes o sigue abierto. Se programa el día 1; con --force corre cualquier día.
 */
class RemindMonthClose extends Command
{
    protected $signature = 'periods:remind-close {--force : Enviar aunque el recordatorio esté apagado}';

    protected $description = 'Avisa por correo si el mes anterior tiene días sin cargar o no está cerrado';

    public function handle(MonthRecordsQuery $query): int
    {
        if (! $this->option('force') && ! (bool) Setting::get('close_reminder_enabled')) {
            $this->info('Recordatorio de cierre apagado.');

            return self::SUCCESS;
        }

        $period = Period::current()->previous();
        $sent = 0;

        foreach (Branch::query()->where('is_active', true)->orderBy('id')->get() as $branch) {
            $view = $query->run($branch->id, $period);
            $missing = count($view->missingDates);
            $closed = PeriodEvent::isClosed($branch->id, $period);
            if ($missing === 0 && $closed) {
                continue;
            }

            $users = User::query()->where('is_active', true)->permission(Permission::PeriodsClose->value)->get()
                ->filter(fn (User $u) => $u->canSeeAllBranches() || $u->branches->contains('id', $branch->id));

            foreach ($users as $user) {
                $user->notify(new MonthCloseReminder($branch, $period->key(), $missing, $closed));
                $sent++;
            }
            $this->line("{$period->label()} · {$branch->name}: faltan {$missing}, ".($closed ? 'cerrado' : 'abierto').' → '.$users->count().' aviso(s)');
        }

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }
}
