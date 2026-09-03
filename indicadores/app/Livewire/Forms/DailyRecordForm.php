<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Domain\Records\DailyRecordInput;
use App\Domain\Shared\Formatter;
use App\Enums\DayStatus;
use App\Models\DailyRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Closure;
use Livewire\Form;

/**
 * Campos del formulario de carga diaria (UC-02). Los números se aceptan con separadores es-VE
 * ("91.154,02") o técnicos; se normalizan antes de validar (RN-20).
 */
class DailyRecordForm extends Form
{
    public string $date = '';

    public string $sales_bs = '';

    public string $rate = '';

    public string $transactions = '';

    public string $units = '';

    public string $inventory_units = '';

    public string $inventory_value_usd = '';

    public string $shifts = '3';

    public string $notes = '';

    public bool $atypical = false;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $number = fn (bool $allowZero = true): Closure => function (string $attribute, mixed $value, Closure $fail) use ($allowZero): void {
            $parsed = app(Formatter::class)->parseNumber((string) $value);
            if ($parsed === null) {
                $fail('Escribe un número válido.');

                return;
            }
            if (str_starts_with($parsed, '-')) {
                $fail('No puede ser negativo.');

                return;
            }
            if (! $allowZero && BigDecimal::of($parsed)->isZero()) {
                $fail('Debe ser mayor que cero.');
            }
        };

        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sales_bs' => ['required', $number()],
            'rate' => ['nullable', $number(allowZero: false)],
            'transactions' => ['required', 'integer', 'min:0'],
            'units' => ['required', 'integer', 'min:0'],
            'inventory_units' => ['nullable', 'integer', 'min:0'],
            'inventory_value_usd' => ['nullable', $number()],
            'shifts' => ['required', 'integer', 'min:0', 'max:6'],
            'notes' => ['nullable', 'string', 'max:500'],
            'atypical' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'No se puede cargar un día que no ha ocurrido.',
            'date.required' => 'Elige la fecha.',
            'sales_bs.required' => 'Escribe la venta del día.',
            'transactions.required' => 'Escribe las transacciones.',
            'transactions.integer' => 'Solo números enteros.',
            'units.required' => 'Escribe las unidades vendidas.',
            'units.integer' => 'Solo números enteros.',
            'inventory_units.integer' => 'Solo números enteros.',
            'shifts.max' => 'Las jornadas deben estar entre 0 y 6.',
            'shifts.min' => 'Las jornadas deben estar entre 0 y 6.',
        ];
    }

    public function fillFromRecord(DailyRecord $record, Formatter $formatter): void
    {
        $this->date = $record->date->toDateString();
        $this->sales_bs = $formatter->number($record->sales_bs, 2);
        $this->rate = $formatter->number($record->exchange_rate, 2);
        $this->transactions = (string) $record->transactions;
        $this->units = (string) $record->units;
        $this->inventory_units = $record->inventory_units === null ? '' : (string) $record->inventory_units;
        $this->inventory_value_usd = $record->inventory_value_usd === null ? '' : $formatter->number($record->inventory_value_usd, 2);
        $this->shifts = (string) $record->shifts;
        $this->notes = (string) $record->notes;
        $this->atypical = $record->status === DayStatus::Atypical;
    }

    /** Construye la entrada del dominio. Solo válido tras `validate()`. */
    public function toInput(int $branchId, Formatter $formatter, bool $includeRate = true): DailyRecordInput
    {
        $rate = $includeRate ? $formatter->parseNumber($this->rate) : null;
        $inventoryValue = $formatter->parseNumber($this->inventory_value_usd);

        return new DailyRecordInput(
            branchId: $branchId,
            date: CarbonImmutable::parse($this->date)->startOfDay(),
            salesBs: BigDecimal::of((string) $formatter->parseNumber($this->sales_bs)),
            rate: $rate === null ? null : BigDecimal::of($rate),
            transactions: (int) $this->transactions,
            units: (int) $this->units,
            inventoryUnits: $this->inventory_units === '' ? null : (int) $this->inventory_units,
            inventoryValueUsd: $inventoryValue === null ? null : BigDecimal::of($inventoryValue),
            shifts: (int) $this->shifts,
            notes: trim($this->notes) === '' ? null : trim($this->notes),
            status: $this->atypical ? DayStatus::Atypical : DayStatus::Normal,
        );
    }

    /** Intento de construir la entrada sin validar (para advertencias mientras se escribe). */
    public function tryInput(int $branchId, Formatter $formatter): ?DailyRecordInput
    {
        if ($this->date === '' || $formatter->parseNumber($this->sales_bs) === null || ! ctype_digit($this->transactions) || ! ctype_digit($this->units)) {
            return null;
        }
        if ($this->rate !== '' && $formatter->parseNumber($this->rate) === null) {
            return null;
        }
        if ($this->inventory_value_usd !== '' && $formatter->parseNumber($this->inventory_value_usd) === null) {
            return null;
        }
        if ($this->inventory_units !== '' && ! ctype_digit($this->inventory_units)) {
            return null;
        }
        if (! ctype_digit($this->shifts)) {
            return null;
        }

        try {
            return $this->toInput($branchId, $formatter);
        } catch (\Throwable) {
            return null;
        }
    }
}
