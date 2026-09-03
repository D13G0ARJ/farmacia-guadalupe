<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Columna DATE sin hora, portable entre MySQL y SQLite.
 *
 * El cast nativo `immutable_date` guarda "Y-m-d H:i:s" (formato del driver), lo que rompe
 * las búsquedas por igualdad en SQLite (usado en pruebas). Este cast guarda siempre "Y-m-d"
 * y devuelve CarbonImmutable a medianoche en la zona horaria de la aplicación.
 *
 * @implements CastsAttributes<CarbonImmutable|null, CarbonInterface|DateTimeInterface|string|null>
 */
final class DateOnlyCast implements CastsAttributes
{
    /** Sin cache de objeto: la lectura siempre devuelve CarbonImmutable a medianoche, venga de donde venga. */
    public bool $withoutObjectCaching = true;

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Sin zona explícita: Laravel fija la zona por defecto de PHP a config('app.timezone') al arrancar,
        // y así el cast también funciona en pruebas unitarias sin contenedor.
        $date = CarbonImmutable::createFromFormat('Y-m-d', substr((string) $value, 0, 10));

        return $date?->startOfDay();
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value) !== 1) {
            throw new InvalidArgumentException("El atributo {$key} espera una fecha Y-m-d; se recibió \"{$value}\".");
        }

        return substr((string) $value, 0, 10);
    }
}
