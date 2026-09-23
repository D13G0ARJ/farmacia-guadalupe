<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Rates\UpsertExchangeRate;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use Illuminate\Console\Command;

/**
 * Deja las tasas automáticas ya guardadas como manda la regla RN-27: dos decimales cortando el tercero.
 * Las manuales y las arrastradas no se tocan; los días ya cargados conservan su tasa (RN-06) hasta que
 * alguien decida recalcular el mes desde Tasa BCV.
 */
class NormalizeBcvRates extends Command
{
    protected $signature = 'rates:normalize {--dry-run : Solo muestra cuántas cambiarían}';

    protected $description = 'Corta a dos decimales, sin redondear, las tasas BCV guardadas con más decimales';

    public function handle(): int
    {
        $changed = 0;
        $rates = ExchangeRate::query()->where('source', RateSource::Bcv)->orderBy('date')->get();

        foreach ($rates as $rate) {
            $official = UpsertExchangeRate::official($rate->rate);
            if ($official->isEqualTo($rate->rate)) {
                continue;
            }
            $this->line($rate->date->format('d/m/Y').': '.$rate->rate.' → '.$official);
            if (! $this->option('dry-run')) {
                $rate->forceFill(['rate' => $official])->save();
            }
            $changed++;
        }

        $this->info(($this->option('dry-run') ? 'Cambiarían ' : 'Corregidas ').$changed.' de '.$rates->count().' tasas BCV.');

        return self::SUCCESS;
    }
}
