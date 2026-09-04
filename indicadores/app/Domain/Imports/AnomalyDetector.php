<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Shared\Decimal;
use App\Domain\Shared\Formatter;
use App\Domain\Shared\Period;
use App\Enums\AnomalyType;
use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Catálogo de anomalías (§10.3) sobre un mes ya leído. Puro: los hechos de la base llegan en
 * `ImportContext`. Cada mensaje dice el dato concreto para que la decisión sea fácil.
 */
final class AnomalyDetector
{
    private const WEEKDAY_LETTERS = [1 => 'L', 2 => 'M', 3 => 'M', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

    public function __construct(private readonly Formatter $formatter) {}

    /** @return list<Anomaly> */
    public function detect(ParsedMonth $month, ImportContext $context): array
    {
        $anomalies = [];
        $period = Period::of($month->period);

        if ($context->alreadyImportedAt !== null) {
            $anomalies[] = new Anomaly(AnomalyType::AlreadyImported, 'Este mismo archivo se importó el '.$context->alreadyImportedAt.'.');
        } elseif ($context->existingRecords > 0) {
            $anomalies[] = new Anomaly(AnomalyType::AlreadyImported, mb_strtolower($period->label()).' ya tiene '.$context->existingRecords.' días cargados en esta sede.');
        }

        $byDate = [];
        foreach ($month->rows as $row) {
            $byDate[$row->date][] = $row;
        }

        foreach ($byDate as $date => $rows) {
            if (count($rows) > 1) {
                $anomalies[] = new Anomaly(AnomalyType::DuplicateDate, 'El '.$this->day($date).' aparece '.count($rows).' veces (filas '.implode(', ', array_map(fn (ParsedRow $r) => $r->row, $rows)).').', $date);
            }
        }

        foreach ($period->dates() as $date) {
            if ($date->lte(CarbonImmutable::today()) && ! isset($byDate[$date->toDateString()])) {
                $anomalies[] = new Anomaly(AnomalyType::MissingDay, 'No hay fila para el '.$this->formatter->date($date, 'weekday').'.', $date->toDateString());
            }
        }

        $sales = [];
        $previousRate = null;
        foreach ($month->rows as $row) {
            $date = $row->date;
            $label = $this->day($date);
            $carbon = CarbonImmutable::parse($date);

            if (! $period->contains($carbon)) {
                $anomalies[] = new Anomaly(AnomalyType::DateOutOfPeriod, "La fila {$row->row} es del {$label}, fuera de ".mb_strtolower($period->label()).'.', $date, $row->row);

                continue;
            }

            if (! $row->isComplete()) {
                $missing = [];
                foreach (['salesBs' => 'venta', 'rate' => 'tasa', 'transactions' => 'transacciones', 'units' => 'unidades', 'shifts' => 'jornadas'] as $field => $name) {
                    if ($row->{$field} === null) {
                        $missing[] = $name;
                    }
                }
                $anomalies[] = new Anomaly(AnomalyType::MissingValue, "El {$label} no tiene ".implode(', ', $missing).'.', $date, $row->row);

                continue;
            }

            $negatives = [];
            foreach (['salesBs' => 'venta', 'rate' => 'tasa', 'transactions' => 'transacciones', 'units' => 'unidades', 'inventoryUnits' => 'inventario', 'inventoryValueUsd' => 'valuación', 'shifts' => 'jornadas'] as $field => $name) {
                $value = $row->{$field};
                if ($value !== null && (is_int($value) ? $value < 0 : BigDecimal::of($value)->isNegative())) {
                    $negatives[] = $name;
                }
            }
            if ($negatives !== []) {
                $anomalies[] = new Anomaly(AnomalyType::NegativeValue, "El {$label} tiene valores negativos en ".implode(', ', $negatives).'.', $date, $row->row);

                continue;
            }

            $rate = BigDecimal::of((string) $row->rate);
            if ($rate->isZero()) {
                $anomalies[] = new Anomaly(AnomalyType::MissingValue, "El {$label} tiene tasa 0.", $date, $row->row);

                continue;
            }

            if ($previousRate !== null) {
                $variation = Decimal::variation($previousRate, $rate);
                if ($variation !== null && $variation->abs()->isGreaterThan(BigDecimal::of($context->rateDeviationPct)->dividedBy(100, 4))) {
                    $anomalies[] = new Anomaly(AnomalyType::RateJump, "El {$label} la tasa pasa de ".$this->formatter->number($previousRate, 2).' a '.$this->formatter->number($rate, 2).' ('.$this->formatter->pct($variation).').', $date, $row->row);
                }
            }
            $previousRate = $rate;

            if ($row->units < $row->transactions) {
                $anomalies[] = new Anomaly(AnomalyType::UnitsLtTransactions, "El {$label} tiene {$row->units} unidades y {$row->transactions} transacciones.", $date, $row->row);
            }

            if (in_array($carbon->dayOfWeekIso, $context->inventoryDays, true) && ($row->inventoryUnits === null || $row->inventoryValueUsd === null)) {
                $anomalies[] = new Anomaly(AnomalyType::InventoryMissingOnCountDay, "El {$label} toca conteo y el inventario está vacío.", $date, $row->row);
            }

            $expectedLetter = self::WEEKDAY_LETTERS[$carbon->dayOfWeekIso];
            $letter = mb_strtoupper(trim((string) $row->weekdayLetter));
            if ($letter !== '' && $letter !== $expectedLetter) {
                $anomalies[] = new Anomaly(AnomalyType::WeekdayMismatch, "El {$label} dice \"{$letter}\" y es ".$this->formatter->weekday($carbon).'. Se usa el día real.', $date, $row->row);
            }

            $existing = $context->existingRates[$date] ?? null;
            if ($existing !== null && ! BigDecimal::of($existing)->isEqualTo($rate)) {
                $anomalies[] = new Anomaly(AnomalyType::RateConflict, "El {$label} ya tiene tasa ".$this->formatter->number($existing, 2).' registrada y el archivo trae '.$this->formatter->number($rate, 2).'.', $date, $row->row);
            }

            $this->checkDerived($row, $rate, $label, $anomalies);
            $sales[$date] = BigDecimal::of((string) $row->salesBs);
        }

        $this->checkSalesDeviation($sales, $context->salesDeviationPct, $anomalies);

        return $anomalies;
    }

    /** @param  list<Anomaly>  $anomalies */
    private function checkDerived(ParsedRow $row, BigDecimal $rate, string $label, array &$anomalies): void
    {
        $sales = BigDecimal::of((string) $row->salesBs);
        $expected = [
            'D' => Decimal::divide($sales, $rate),
            'H' => Decimal::divide($sales, $row->transactions),
            'I' => Decimal::divide($row->units, $row->transactions),
            'J' => Decimal::divide(Decimal::divide($sales, $row->transactions), $rate),
            'M' => Decimal::divide($row->transactions, $row->shifts),
        ];
        $names = ['D' => 'venta en $', 'H' => 'ticket promedio', 'I' => 'unidades por compra', 'J' => 'ticket en $', 'M' => 'transacciones por jornada'];

        foreach ($expected as $column => $value) {
            $inFile = $row->fileDerived[$column] ?? null;
            if ($inFile === null || $value === null || $value->isZero()) {
                continue;
            }
            $diff = Decimal::variation($value, BigDecimal::of((string) $inFile));
            if ($diff !== null && $diff->abs()->isGreaterThan(BigDecimal::of('0.01'))) {
                $anomalies[] = new Anomaly(AnomalyType::DerivedMismatch, "El {$label}: {$names[$column]} del archivo (".$this->formatter->number($inFile, 2).') no coincide con el cálculo ('.$this->formatter->number($value->toScale(2, RoundingMode::HalfUp), 2).'). Se usa el cálculo.', $row->date, $row->row);
            }
        }
    }

    /**
     * @param  array<string, BigDecimal>  $sales
     * @param  list<Anomaly>  $anomalies
     */
    private function checkSalesDeviation(array $sales, int $thresholdPct, array &$anomalies): void
    {
        if (count($sales) < 5) {
            return;
        }
        $sorted = array_values($sales);
        usort($sorted, fn (BigDecimal $a, BigDecimal $b) => $a->compareTo($b));
        $n = count($sorted);
        $median = $n % 2 === 1 ? $sorted[intdiv($n, 2)] : $sorted[$n / 2 - 1]->plus($sorted[$n / 2])->dividedBy(2, 4, RoundingMode::HalfUp);
        if ($median->isZero()) {
            return;
        }
        $threshold = BigDecimal::of($thresholdPct)->dividedBy(100, 4);

        foreach ($sales as $date => $value) {
            $variation = Decimal::variation($median, $value);
            if ($variation !== null && $variation->abs()->isGreaterThan($threshold)) {
                $anomalies[] = new Anomaly(AnomalyType::SalesDeviation, 'El '.$this->day($date).' vendió '.$this->formatter->money($value, Currency::Bs, 0).' ('.$this->formatter->pct($variation).' respecto a la mediana del mes, '.$this->formatter->money($median, Currency::Bs, 0).').', $date);
            }
        }
    }

    private function day(string $date): string
    {
        return $this->formatter->date(CarbonImmutable::parse($date), 'weekday');
    }
}
