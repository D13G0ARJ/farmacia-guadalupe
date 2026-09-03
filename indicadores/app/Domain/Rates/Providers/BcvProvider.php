<?php

declare(strict_types=1);

namespace App\Domain\Rates\Providers;

use App\Domain\Rates\ExchangeRateProvider;
use App\Domain\Rates\RateQuote;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * USD oficial del BCV (§9.2): primero una API comunitaria, luego la página del BCV.
 * Nunca lanza: si ambas fuentes fallan devuelve null y la carga diaria sigue manual.
 */
final class BcvProvider implements ExchangeRateProvider
{
    /**
     * @param  array{primary_url: string, fallback_url: string, connect_timeout: int, timeout: int, retries: int}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly array $config,
    ) {}

    public function fetch(): ?RateQuote
    {
        return $this->fromApi() ?? $this->fromBcvPage();
    }

    private function fromApi(): ?RateQuote
    {
        try {
            $json = $this->client()->get($this->config['primary_url'])->throw()->json();

            $value = $json['promedio'] ?? $json['venta'] ?? null;
            if (! is_numeric($value)) {
                return null;
            }

            return new RateQuote(BigDecimal::of((string) $value), CarbonImmutable::now(), 'api');
        } catch (ConnectionException|RequestException|MathException $e) {
            Log::warning('Tasa BCV: la API primaria no respondió', ['error' => $e->getMessage()]);

            return null;
        } catch (Throwable $e) {
            Log::warning('Tasa BCV: respuesta inesperada de la API primaria', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function fromBcvPage(): ?RateQuote
    {
        try {
            $html = $this->client()->withoutVerifying()->get($this->config['fallback_url'])->throw()->body();

            // Bloque <div id="dolar"> … <strong> 148,44000000 </strong>
            if (preg_match('/id="dolar".*?<strong>\s*([\d.]+,\d+|\d+(?:\.\d+)?)\s*<\/strong>/is', $html, $m) !== 1) {
                return null;
            }

            $normalized = str_replace(['.', ','], ['', '.'], $m[1]);

            return new RateQuote(BigDecimal::of($normalized), CarbonImmutable::now(), 'bcv.org.ve');
        } catch (Throwable $e) {
            Log::warning('Tasa BCV: la página del BCV no respondió', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($this->config['timeout'])
            ->retry($this->config['retries'], 500, throw: false)
            ->acceptJson();
    }
}
