<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Reports\BuildMonthlyReport;
use App\Domain\Shared\Period;
use App\Models\Branch;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Envío programado del reporte mensual (§11.2): corre a diario y solo actúa el día configurado
 * en Administración (0 = apagado), para el mes anterior y cada sede activa.
 */
class SendMonthlyReport extends Command
{
    protected $signature = 'reports:send-monthly {--period= : Mes a enviar (AAAA-MM); por defecto el anterior} {--force : Enviar aunque hoy no sea el día configurado}';

    protected $description = 'Envía por correo el reporte PDF del mes a los destinatarios configurados';

    public function handle(BuildMonthlyReport $builder): int
    {
        $day = (int) Setting::get('report_email_day');
        $recipients = self::recipients((string) Setting::get('report_recipients'));

        if (! $this->option('force') && ($day < 1 || CarbonImmutable::today()->day !== $day)) {
            $this->info($day < 1 ? 'Envío programado apagado (día 0).' : "Hoy no es el día {$day}: no se envía.");

            return self::SUCCESS;
        }
        if ($recipients === []) {
            $this->warn('No hay destinatarios configurados en Administración › Correo.');

            return self::SUCCESS;
        }

        try {
            $period = $this->option('period') !== null ? Period::of((string) $this->option('period')) : Period::current()->previous();
        } catch (InvalidArgumentException) {
            $this->error('Período inválido: usa AAAA-MM.');

            return self::FAILURE;
        }

        $sent = 0;
        foreach (Branch::query()->where('is_active', true)->orderBy('id')->get() as $branch) {
            Mail::to($recipients)->send($builder->handle($branch, $period));
            $sent++;
            $this->line("Enviado: {$period->label()} · {$branch->name} → ".implode(', ', $recipients));
        }

        $this->info("Reportes enviados: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Lista de correos separados por coma o salto de línea, sin duplicados ni inválidos.
     *
     * @return list<string>
     */
    public static function recipients(string $raw): array
    {
        $emails = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $email) {
            $email = mb_strtolower(trim($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }
}
