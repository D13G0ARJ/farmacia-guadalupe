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

    /** El día guardado es un "día cerrado" (RN-12): editar otro campo no debe convertirlo en normal (M11). */
    public bool $closed = false;

    /** Tasa guardada del registro en curso, en forma canónica ("148.4421"): referencia para saber si el usuario la cambió (A1). */
    public string $originalRate = '';

    /** Topes de las columnas de `daily_records` (A3): pasarse devolvía un 500 de MySQL. */
    public const MAX_SALES_BS = '999999999999.99';

    public const MAX_RATE = '99999999.9999';

    public const MAX_INVENTORY_VALUE_USD = '999999999999.99';

    public const MAX_UNSIGNED_INT = 4294967295;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $number = fn (string $max, bool $allowZero = true): Closure => function (string $attribute, mixed $value, Closure $fail) use ($max, $allowZero): void {
            $parsed = app(Formatter::class)->parseNumber((string) $value);
            if ($parsed === null) {
                $fail('Escribe un número válido. Usa la coma para los decimales: 1.234,56.');

                return;
            }
            if (str_starts_with($parsed, '-')) {
                $fail('No puede ser negativo.');

                return;
            }
            if (! $allowZero && BigDecimal::of($parsed)->isZero()) {
                $fail('Debe ser mayor que cero.');

                return;
            }
            if (BigDecimal::of($parsed)->isGreaterThan(BigDecimal::of($max))) {
                $fail('Es demasiado grande. Revisa el valor.');
            }
        };

        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sales_bs' => ['required', $number(self::MAX_SALES_BS)],
            'rate' => ['nullable', $number(self::MAX_RATE, allowZero: false)],
            'transactions' => ['required', 'integer', 'min:0', 'max:'.self::MAX_UNSIGNED_INT],
            'units' => ['required', 'integer', 'min:0', 'max:'.self::MAX_UNSIGNED_INT],
            'inventory_units' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_UNSIGNED_INT],
            'inventory_value_usd' => ['nullable', $number(self::MAX_INVENTORY_VALUE_USD)],
            'shifts' => ['required', 'integer', 'min:0', 'max:6'],
            // El motivo del día atípico necesita cuerpo: el dominio ya lo exigía, aquí se avisa en el campo (M19).
            'notes' => ['nullable', 'string', 'max:500', 'required_if:atypical,true', ...($this->atypical ? ['min:10'] : [])],
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
            'notes.required_if' => 'Escribe el motivo del día atípico (por ejemplo: corte de luz, media jornada).',
            'date.required' => 'Elige la fecha.',
            'sales_bs.required' => 'Escribe la venta del día.',
            'transactions.required' => 'Escribe las transacciones.',
            'transactions.integer' => 'Solo números enteros.',
            'units.required' => 'Escribe las unidades vendidas.',
            'units.integer' => 'Solo números enteros.',
            'inventory_units.integer' => 'Solo números enteros.',
            'shifts.max' => 'Las jornadas deben estar entre 0 y 6.',
            'shifts.min' => 'Las jornadas deben estar entre 0 y 6.',
            'notes.min' => 'Escribe al menos 10 caracteres para el motivo del día atípico.',
            'transactions.max' => 'Es demasiado grande. Revisa el valor.',
            'units.max' => 'Es demasiado grande. Revisa el valor.',
            'inventory_units.max' => 'Es demasiado grande. Revisa el valor.',
        ];
    }

    public function fillFromRecord(DailyRecord $record, Formatter $formatter): void
    {
        $this->date = $record->date->toDateString();
        $this->sales_bs = $formatter->number($record->sales_bs, 2);
        // Con dos decimales fijos, 148,4421 se mostraba como 148,44 y volvía reescrita como manual (A1).
        $this->rate = $formatter->numberFlexible($record->exchange_rate);
        $this->originalRate = (string) $record->exchange_rate;
        $this->closed = $record->status === DayStatus::Closed;
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
            // Un día cerrado sigue cerrado mientras nadie lo marque atípico (M11).
            status: match (true) {
                $this->atypical => DayStatus::Atypical,
                $this->closed => DayStatus::Closed,
                default => DayStatus::Normal,
            },
        );
    }

    /**
     * ¿El usuario cambió la tasa respecto de la guardada? Solo entonces se manda al dominio
     * y se reescribe la tasa global del día (A1).
     */
    public function rateChanged(Formatter $formatter): bool
    {
        $typed = trim($this->rate);
        if ($typed === '') {
            return false;
        }
        if ($this->originalRate === '') {
            return true;
        }

        $parsed = $formatter->parseNumber($typed);

        // Inválida: se marca como editada para que la validación la muestre en el campo.
        return $parsed === null || ! BigDecimal::of($parsed)->isEqualTo(BigDecimal::of($this->originalRate));
    }

    /** Al cambiar de fecha, lo escrito para el día anterior no puede quedarse pegado (M13). */
    public function resetTypedFields(): void
    {
        $this->sales_bs = '';
        $this->transactions = '';
        $this->units = '';
        $this->inventory_units = '';
        $this->inventory_value_usd = '';
        $this->notes = '';
        $this->atypical = false;
        $this->closed = false;
        $this->originalRate = '';
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
