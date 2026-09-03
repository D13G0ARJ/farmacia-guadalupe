<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Indicators\DailyRecordData;
use App\Enums\DayStatus;

/**
 * Septiembre 2025 real (tests/Fixtures/septiembre-2025.json) como DTOs, con el 16/09 marcado atípico.
 */
final class SeptemberFixture
{
    /** @return array{period: string, rows: list<array<string, mixed>>, golden: array<string, mixed>} */
    public static function raw(): array
    {
        /** @var array{period: string, rows: list<array<string, mixed>>, golden: array<string, mixed>} $data */
        $data = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/septiembre-2025.json'), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @return list<DailyRecordData> */
    public static function records(bool $markAtypical = true): array
    {
        $raw = self::raw();
        $atypical = (string) $raw['golden']['atypical_date'];

        return array_map(function (array $row) use ($markAtypical, $atypical): DailyRecordData {
            if ($markAtypical && $row['date'] === $atypical) {
                $row['status'] = DayStatus::Atypical->value;
            }

            /** @var array{date: string, sales_bs: string, exchange_rate: string, transactions: int, units: int, inventory_units: int|null, inventory_value_usd: string|null, shifts: int, status?: string} $row */
            return DailyRecordData::fromArray($row);
        }, $raw['rows']);
    }

    /** @return array<string, mixed> */
    public static function golden(): array
    {
        return self::raw()['golden'];
    }
}
