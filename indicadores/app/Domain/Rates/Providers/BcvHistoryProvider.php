<?php

declare(strict_types=1);

namespace App\Domain\Rates\Providers;

use App\Domain\Rates\BcvHistoryParser;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Histórico oficial del BCV (§9.2): descarga los libros trimestrales de
 * bcv.org.ve/estadisticas/tipo-cambio-de-referencia-smc (2_1_2a25 = primer trimestre 2025, b, c, d)
 * y devuelve las tasas de vigencia dentro del rango. Un trimestre que no exista se salta.
 */
final class BcvHistoryProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly BcvHistoryParser $parser,
        private readonly string $urlTemplate,
    ) {}

    /**
     * @return array{rates: array<string, BigDecimal>, files: list<string>, failed: list<string>}
     */
    public function fetch(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rates = [];
        $files = [];
        $failed = [];

        foreach ($this->quarters($from, $to) as $file) {
            $url = str_replace('{file}', $file, $this->urlTemplate);
            $temp = tempnam(sys_get_temp_dir(), 'bcv');
            if ($temp === false) {
                $failed[] = $file;

                continue;
            }
            $path = $temp.'.xls';
            try {
                $response = $this->http->withoutVerifying()->connectTimeout(10)->timeout(90)->retry(2, 1000, throw: false)->get($url);
                if (! $response->successful() || strlen($response->body()) < 1000) {
                    $failed[] = $file;

                    continue;
                }
                file_put_contents($path, $response->body());
                foreach ($this->parser->parse($path) as $date => $rate) {
                    if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                        $rates[$date] = $rate;
                    }
                }
                $files[] = $file;
            } catch (Throwable $e) {
                Log::warning('Histórico BCV: no se pudo leer un trimestre', ['archivo' => $file, 'error' => $e->getMessage()]);
                $failed[] = $file;
            } finally {
                // `tempnam` crea su propio archivo además del .xls: se borran los dos (M7).
                @unlink($path);
                @unlink($temp);
            }
        }
        ksort($rates);

        return ['rates' => $rates, 'files' => $files, 'failed' => $failed];
    }

    /**
     * Nombres de archivo de los trimestres que cubren el rango: "2_1_2a25_smc.xls".
     *
     * @return list<string>
     */
    public function quarters(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $files = [];
        $cursor = $from->startOfQuarter();
        while ($cursor->lte($to)) {
            $letter = ['a', 'b', 'c', 'd'][intdiv($cursor->month - 1, 3)];
            $files[] = sprintf('2_1_2%s%02d_smc.xls', $letter, $cursor->year % 100);
            $cursor = $cursor->addQuarter();
        }

        return $files;
    }
}
