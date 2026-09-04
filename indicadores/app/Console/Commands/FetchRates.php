<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Rates\FetchBcvRate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Prueba real del proveedor BCV en el servidor (§17 Fase 7): consulta y guarda la tasa de hoy
 * y la del siguiente día hábil, como hacen las corridas programadas.
 */
class FetchRates extends Command
{
    protected $signature = 'rates:fetch {--date= : Fecha de vigencia (AAAA-MM-DD); por defecto hoy y el siguiente día hábil}';

    protected $description = 'Consulta la tasa BCV ahora y la guarda';

    public function handle(FetchBcvRate $fetch): int
    {
        $dates = $this->option('date') !== null
            ? [CarbonImmutable::parse((string) $this->option('date'))]
            : [CarbonImmutable::today(), FetchBcvRate::nextBusinessDay(CarbonImmutable::today())];

        $ok = true;
        foreach ($dates as $date) {
            $rate = $fetch->handle($date);
            if ($rate === null) {
                $this->error("Sin cotización para {$date->toDateString()}: revisa la conexión del servidor y el estado del proveedor en Tasa BCV.");
                $ok = false;

                continue;
            }
            $this->info("{$date->toDateString()}: {$rate->rate} ({$rate->source->label()})");
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
