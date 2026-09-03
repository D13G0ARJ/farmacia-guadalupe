<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Rates\ExchangeRateProvider;
use App\Domain\Rates\Providers\BcvProvider;
use App\Domain\Rates\Providers\NullProvider;
use App\Domain\Shared\Formatter;
use App\Models\Branch;
use App\Policies\PeriodPolicy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Formatter::class);

        // Frontera con el proveedor de tasa (§9.2): bcv | null según config/indicadores.php.
        $this->app->bind(ExchangeRateProvider::class, function ($app): ExchangeRateProvider {
            /** @var array{provider: string|null, primary_url: string, fallback_url: string, connect_timeout: int, timeout: int, retries: int} $config */
            $config = $app['config']->get('indicadores.rates');

            return $config['provider'] === 'bcv'
                ? new BcvProvider($app->make(HttpFactory::class), $config)
                : new NullProvider;
        });
    }

    public function boot(): void
    {
        // HTTPS obligatorio en producción (§15.2): los enlaces generados nunca vuelven a http.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Cerrar/reabrir un mes se autoriza sobre la sede (RN-13, §15.1).
        Gate::policy(Branch::class, PeriodPolicy::class);

        // Directivas de formato es-VE (§6.6): @money($v, 'USD', 0) · @num($v, 1) · @pct($v) · @fecha($d, 'weekday')
        Blade::directive('money', fn (string $expression) => "<?php echo e(app(\App\Domain\Shared\Formatter::class)->money({$expression})); ?>");
        Blade::directive('num', fn (string $expression) => "<?php echo e(app(\App\Domain\Shared\Formatter::class)->number({$expression})); ?>");
        Blade::directive('pct', fn (string $expression) => "<?php echo e(app(\App\Domain\Shared\Formatter::class)->pct({$expression})); ?>");
        Blade::directive('fecha', fn (string $expression) => "<?php echo e(app(\App\Domain\Shared\Formatter::class)->date({$expression})); ?>");
    }
}
