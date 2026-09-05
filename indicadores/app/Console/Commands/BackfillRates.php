<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Rates\BackfillBcvRates;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Trae el histórico oficial del BCV (§9.2) para los días sin tasa. Útil al instalar y para
 * meses viejos antes de importar cuadros.
 */
class BackfillRates extends Command
{
    protected $signature = 'rates:backfill {--from=2025-01-01 : Desde (AAAA-MM-DD)} {--to= : Hasta (por defecto hoy)}';

    protected $description = 'Completa las tasas BCV que faltan desde los libros trimestrales del BCV';

    public function handle(BackfillBcvRates $action): int
    {
        try {
            $from = CarbonImmutable::parse((string) $this->option('from'));
            $to = $this->option('to') !== null ? CarbonImmutable::parse((string) $this->option('to')) : CarbonImmutable::today();
        } catch (Throwable) {
            $this->error('Fechas inválidas: usa AAAA-MM-DD.');

            return self::FAILURE;
        }
        if ($from->gt($to)) {
            $this->error('"Desde" debe ser anterior a "hasta".');

            return self::FAILURE;
        }

        $this->info("Descargando el histórico del BCV de {$from->toDateString()} a {$to->toDateString()}…");
        $result = $action->handle($from, $to);

        $this->info("Tasas nuevas: {$result['created']} · ya existían: {$result['existing']} · encontradas: {$result['found']}");
        if ($result['from'] !== null) {
            $this->line("Rango encontrado: {$result['from']} a {$result['to']}");
        }
        if ($result['failed'] !== []) {
            $this->warn('Trimestres no disponibles: '.implode(', ', $result['failed']));
        }

        return $result['found'] > 0 || $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
