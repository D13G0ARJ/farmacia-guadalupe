<?php

declare(strict_types=1);

use App\Domain\Shared\Formatter;
use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

dataset('formatters', [
    'intl' => [fn () => new Formatter(useIntl: true)],
    'fallback' => [fn () => new Formatter(useIntl: false)],
]);

it('formatea números con separadores es-VE', function (Closure $make): void {
    $f = $make();

    expect($f->number('3853'))->toBe('3.853')
        ->and($f->number('1.9577', 2))->toBe('1,96')
        ->and($f->number(BigDecimal::of('3012770.86'), 2))->toBe('3.012.770,86')
        ->and($f->number('0.5', 0))->toBe('1')
        ->and($f->number(null))->toBe('—');
})->with('formatters');

it('formatea moneda con símbolo y precisión de la tabla 2.2', function (Closure $make): void {
    $f = $make();

    expect($f->money('91154.02', Currency::Bs))->toBe('Bs 91.154,02')
        ->and($f->money('614.0799', Currency::Usd, 0))->toBe('$ 614')
        ->and($f->money('18610.68', Currency::Usd, 2))->toBe('$ 18.610,68')
        ->and($f->money('1.96', Currency::None, 2))->toBe('1,96')
        ->and($f->money(null, Currency::Usd))->toBe('—');
})->with('formatters');

it('formatea porcentajes con signo a partir de una fracción', function (Closure $make): void {
    $f = $make();

    expect($f->pct('0.1965'))->toBe('+19,7 %')
        ->and($f->pct('-0.0042', 2))->toBe('-0,42 %')
        ->and($f->pct('0'))->toBe('0,0 %')
        ->and($f->pct('0.94', 0, signed: false))->toBe('94 %');
})->with('formatters');

it('deriva el día de la semana de la fecha (RN-02)', function (): void {
    $f = new Formatter;
    $monday = CarbonImmutable::parse('2025-09-01');

    expect($f->weekday($monday))->toBe('lun')
        ->and($f->date($monday, 'weekday'))->toBe('lun 01/09')
        ->and($f->date($monday))->toBe('01/09/2025')
        ->and($f->date($monday, 'long'))->toBe('lunes 1 de septiembre de 2025')
        ->and($f->weekday(CarbonImmutable::parse('2025-09-06')))->toBe('sáb')
        ->and($f->weekday(CarbonImmutable::parse('2025-09-24')))->toBe('mié');
});

it('lee números escritos a la venezolana o en notación técnica (espejo de fmt.parse)', function (): void {
    $f = new Formatter;

    expect($f->parseNumber('91.154,02'))->toBe('91154.02')
        ->and($f->parseNumber('91.154'))->toBe('91154')          // puntos cada tres dígitos = miles
        ->and($f->parseNumber('1.234.567'))->toBe('1234567')
        ->and($f->parseNumber('148.44'))->toBe('148.44')          // un punto con dos decimales = técnico
        ->and($f->parseNumber('148,44'))->toBe('148.44')
        ->and($f->parseNumber('3853'))->toBe('3853')
        ->and($f->parseNumber(' 21 848,73 '))->toBe('21848.73')
        ->and($f->parseNumber('-5'))->toBe('-5')
        ->and($f->parseNumber('abc'))->toBeNull()
        ->and($f->parseNumber('1.2.3'))->toBeNull()
        ->and($f->parseNumber(''))->toBeNull()
        ->and($f->parseNumber(null))->toBeNull();
});

it('el archivo original tenía la columna de días corrida: el sistema no puede reproducir ese error', function (): void {
    // H1: el Excel decía "v" (viernes) para el 01/09/2025, que es lunes.
    expect((new Formatter)->weekday(CarbonImmutable::parse('2025-09-01')))->not->toBe('vie');
});
