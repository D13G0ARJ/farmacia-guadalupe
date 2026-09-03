<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use NumberFormatter;

/**
 * Formato es-VE (RN-20, §6.6): miles con punto, decimales con coma,
 * "Bs 91.154,02", "$ 614", "+19,7 %", "lun 01/09".
 *
 * Usa ext-intl si está disponible; si no, number_format con los mismos separadores.
 */
final class Formatter
{
    private const LOCALE = 'es_VE';

    private const WEEKDAYS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

    private const WEEKDAYS_LONG = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private ?NumberFormatter $intl;

    public function __construct(?bool $useIntl = null)
    {
        $useIntl ??= class_exists(NumberFormatter::class);
        $this->intl = $useIntl ? new NumberFormatter(self::LOCALE, NumberFormatter::DECIMAL) : null;
    }

    /** "3.853" · "1,96" · "—" para null. */
    public function number(BigDecimal|string|int|float|null $value, int $precision = 0): string
    {
        if ($value === null) {
            return '—';
        }

        $decimal = self::toDecimal($value)->toScale($precision, RoundingMode::HalfUp);

        if ($this->intl !== null) {
            $this->intl->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $precision);
            $this->intl->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $precision);
            $this->intl->setAttribute(NumberFormatter::GROUPING_USED, 1);
            $formatted = $this->intl->format($decimal->toFloat());

            if ($formatted !== false) {
                return str_replace("\u{A0}", '.', $formatted);
            }
        }

        return number_format($decimal->toFloat(), $precision, ',', '.');
    }

    /** "Bs 91.154,02" · "$ 614". */
    public function money(BigDecimal|string|int|float|null $value, Currency $currency, int $precision = 2): string
    {
        if ($value === null) {
            return '—';
        }

        $symbol = $currency->symbol();
        $number = $this->number($value, $precision);

        return $symbol === '' ? $number : "{$symbol} {$number}";
    }

    /** Recibe una fracción (0,1965) y devuelve "+19,7 %". */
    public function pct(BigDecimal|string|int|float|null $fraction, int $precision = 1, bool $signed = true): string
    {
        if ($fraction === null) {
            return '—';
        }

        $value = self::toDecimal($fraction)->multipliedBy(100)->toScale($precision, RoundingMode::HalfUp);
        $sign = $signed && $value->isPositive() ? '+' : '';

        return $sign.$this->number($value, $precision).' %';
    }

    /** short: "01/09/2025" · weekday: "lun 01/09" · long: "lunes 1 de septiembre de 2025". */
    public function date(CarbonInterface $date, string $style = 'short'): string
    {
        return match ($style) {
            'weekday' => self::WEEKDAYS[$date->dayOfWeekIso - 1].' '.$date->format('d/m'),
            'weekday_full' => self::WEEKDAYS[$date->dayOfWeekIso - 1].' '.$date->format('d/m/Y'),
            'long' => self::WEEKDAYS_LONG[$date->dayOfWeekIso - 1].' '.$date->day.' de '
                .mb_strtolower($date->locale('es')->translatedFormat('F')).' de '.$date->year,
            default => $date->format('d/m/Y'),
        };
    }

    /** "lun" … "dom" (RN-02: derivado de la fecha, nunca capturado). */
    public function weekday(CarbonInterface $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeekIso - 1];
    }

    /**
     * Lee un número escrito con separadores es-VE ("91.154,02") o técnicos ("91154.02")
     * y devuelve una cadena decimal canónica, o null si no es un número. Espejo de `fmt.parse` en JS.
     */
    public function parseNumber(?string $input): ?string
    {
        $s = trim((string) $input);
        if ($s === '') {
            return null;
        }

        $s = str_replace(' ', '', $s);

        if (str_contains($s, ',')) {
            // Coma = decimal; los puntos son miles: "91.154,02" → 91154.02
            $normalized = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s) === 1) {
            // Sin coma y puntos cada tres dígitos = miles: "91.154" → 91154, "1.234.567" → 1234567
            $normalized = str_replace('.', '', $s);
        } else {
            // Un solo punto con otra cantidad de decimales = notación técnica: "148.44" → 148.44
            $normalized = $s;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private static function toDecimal(BigDecimal|string|int|float $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        // float solo para presentación; el dominio nunca lo usa (RN-19).
        return is_float($value) ? BigDecimal::of(number_format($value, 10, '.', '')) : BigDecimal::of($value);
    }
}
