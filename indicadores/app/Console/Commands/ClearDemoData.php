<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyRecord;
use App\Models\ExchangeRate;
use App\Models\Goal;
use App\Models\ImportBatch;
use App\Models\PeriodEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Limpia los datos de demostración antes de entregar (§17 Fase 7): días, tasas, metas, cierres,
 * importaciones, bitácora, usuarios de demostración y el estado del proveedor. Conserva sedes,
 * parámetros y el administrador.
 */
class ClearDemoData extends Command
{
    use ConfirmableTrait;

    public const DEMO_EMAILS = ['operador@guadalupe.local', 'supervision@guadalupe.local', 'direccion@guadalupe.local'];

    protected $signature = 'demo:clear {--force : Sin confirmación (obligatorio en producción)}';

    protected $description = 'Borra los datos de demostración y deja el sistema listo para la carga real';

    public function handle(): int
    {
        if (! $this->confirmToProceed('Se borrarán todos los días, tasas, metas y la bitácora.')) {
            return self::FAILURE;
        }

        DB::transaction(function (): void {
            DailyRecord::query()->delete();
            ExchangeRate::query()->delete();
            Goal::query()->delete();
            PeriodEvent::query()->delete();
            ImportBatch::query()->delete();
            Activity::query()->delete();
            User::query()->whereIn('email', self::DEMO_EMAILS)->delete();
            Setting::query()->whereIn('key', ['rates_last_attempt_at', 'rates_last_success_at', 'rates_last_error'])->delete();
        });

        Cache::flush();

        $this->info('Datos de demostración borrados. Quedan las sedes, los parámetros y '.User::query()->count().' usuario(s).');
        $this->line('Siguiente paso: php artisan rates:fetch para traer la tasa de hoy.');

        return self::SUCCESS;
    }
}
