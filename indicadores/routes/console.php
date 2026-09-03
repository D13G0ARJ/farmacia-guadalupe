<?php

declare(strict_types=1);

use App\Actions\Rates\FetchBcvRate;
use App\Jobs\FetchDailyBcvRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schedule;

/*
 * Tasa BCV (§9.2). El BCV publica en la tarde la tasa que rige el siguiente día hábil:
 * la corrida de 17:30 la guarda con esa fecha de vigencia; la de 08:00 es respaldo para hoy.
 * Requiere un solo cron: * * * * * php artisan schedule:run (§4.6).
 */
Schedule::job(new FetchDailyBcvRate)
    ->name('bcv-rate-today')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30);

Schedule::call(function (): void {
    FetchDailyBcvRate::dispatch(FetchBcvRate::nextBusinessDay(CarbonImmutable::today())->toDateString());
})
    ->name('bcv-rate-next-business-day')
    ->dailyAt('17:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30);

/*
 * Colas sin worker supervisado (§4.6): el scheduler procesa lo pendiente cada minuto.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5);
