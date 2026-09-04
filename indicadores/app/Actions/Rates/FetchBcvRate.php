<?php

declare(strict_types=1);

namespace App\Actions\Rates;

use App\Domain\Rates\ExchangeRateProvider;
use App\Domain\Shared\Decimal;
use App\Domain\Shared\Formatter;
use App\Enums\Permission;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RateDeviationDetected;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Consulta al proveedor y guarda la tasa para la fecha de vigencia indicada (§9.2).
 * Si la cotización se aparta más del umbral respecto a la anterior, se guarda igual y se
 * registra una advertencia para revisión (RN-16). Nunca bloquea: devuelve null si no hay cotización.
 */
final class FetchBcvRate
{
    public function __construct(
        private readonly ExchangeRateProvider $provider,
        private readonly UpsertExchangeRate $upsert,
    ) {}

    public function handle(CarbonImmutable $effectiveDate): ?ExchangeRate
    {
        // Estado del proveedor para la pantalla Tasa BCV (§9.4): última consulta, último éxito, último error.
        Setting::put('rates_last_attempt_at', CarbonImmutable::now()->toIso8601String());

        $quote = $this->provider->fetch();

        if ($quote === null || ! $quote->rate->isPositive()) {
            Setting::put('rates_last_error', 'El proveedor no devolvió una cotización válida ('.CarbonImmutable::now()->format('d/m H:i').').');

            return null;
        }

        Setting::put('rates_last_success_at', CarbonImmutable::now()->toIso8601String());
        Setting::forget('rates_last_error');

        $previous = ExchangeRate::query()
            ->where('date', '<', $effectiveDate->toDateString())
            ->orderByDesc('date')
            ->first();

        if ($previous !== null && $this->deviatesTooMuch($previous->rate, $quote->rate)) {
            Log::warning('Tasa BCV: desviación mayor al umbral, revisar', [
                'fecha' => $effectiveDate->toDateString(),
                'anterior' => (string) $previous->rate,
                'nueva' => (string) $quote->rate,
            ]);
            $this->notifyAdmins($effectiveDate, $previous->rate, $quote->rate);
        }

        return $this->upsert->handle($effectiveDate, $quote->rate, RateSource::Bcv, fetchedAt: $quote->fetchedAt);
    }

    /** El BCV publica en la tarde la tasa que rige el siguiente día hábil (§9.2). */
    public static function nextBusinessDay(CarbonImmutable $from): CarbonImmutable
    {
        $next = $from->addDay();
        while ($next->isWeekend()) {
            $next = $next->addDay();
        }

        return $next;
    }

    /** Correo a quien administra (RN-16): la tasa se guardó, pero conviene revisarla. */
    private function notifyAdmins(CarbonImmutable $date, BigDecimal $previous, BigDecimal $new): void
    {
        $formatter = app(Formatter::class);
        try {
            $admins = User::query()->where('is_active', true)->permission(Permission::RatesManage->value)->get();
        } catch (PermissionDoesNotExist) {
            // Base sin roles sembrados (instalación a medias): la tasa ya quedó guardada y registrada en el log.
            return;
        }

        Notification::send($admins, new RateDeviationDetected(
            $date->format('d/m/Y'),
            $formatter->number($previous, 2),
            $formatter->number($new, 2),
            $formatter->pct(Decimal::variation($previous, $new)),
        ));
    }

    private function deviatesTooMuch(BigDecimal $previous, BigDecimal $new): bool
    {
        $threshold = BigDecimal::of((string) Setting::get('rate_deviation_pct'))->dividedBy(100, 4);
        $variation = Decimal::variation($previous, $new);

        return $variation !== null && $variation->abs()->isGreaterThan($threshold);
    }
}
