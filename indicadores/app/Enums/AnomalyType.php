<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Catálogo de anomalías del importador (§10.3): tipo, severidad y decisiones que se ofrecen.
 */
enum AnomalyType: string
{
    case MissingDay = 'missing_day';
    case DuplicateDate = 'duplicate_date';
    case DateOutOfPeriod = 'date_out_of_period';
    case NegativeValue = 'negative_value';
    case MissingValue = 'missing_value';
    case RateJump = 'rate_jump';
    case UnitsLtTransactions = 'units_lt_transactions';
    case SalesDeviation = 'sales_deviation';
    case InventoryMissingOnCountDay = 'inventory_missing_on_count_day';
    case WeekdayMismatch = 'weekday_mismatch';
    case DerivedMismatch = 'derived_mismatch';
    case HeaderMismatch = 'header_mismatch';
    case MonthMismatch = 'month_mismatch';
    case AlreadyImported = 'already_imported';
    case RateConflict = 'rate_conflict';

    public function severity(): AnomalySeverity
    {
        return match ($this) {
            self::MissingDay, self::DuplicateDate, self::DateOutOfPeriod, self::NegativeValue,
            self::MissingValue, self::AlreadyImported, self::MonthMismatch => AnomalySeverity::High,
            self::RateJump, self::UnitsLtTransactions, self::SalesDeviation, self::RateConflict => AnomalySeverity::Medium,
            self::InventoryMissingOnCountDay => AnomalySeverity::Low,
            self::WeekdayMismatch, self::DerivedMismatch, self::HeaderMismatch => AnomalySeverity::Info,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MissingDay => 'Día sin fila',
            self::DuplicateDate => 'Fecha repetida',
            self::DateOutOfPeriod => 'Fecha fuera del mes',
            self::NegativeValue => 'Valor negativo',
            self::MissingValue => 'Dato obligatorio vacío',
            self::RateJump => 'Salto de tasa',
            self::UnitsLtTransactions => 'Menos unidades que transacciones',
            self::SalesDeviation => 'Venta muy distinta al resto del mes',
            self::InventoryMissingOnCountDay => 'Inventario vacío en día con conteo',
            self::WeekdayMismatch => 'Letra del día distinta al día real',
            self::DerivedMismatch => 'Fórmula del archivo distinta al cálculo',
            self::HeaderMismatch => 'Encabezado distinto al esperado',
            self::MonthMismatch => 'El mes del encabezado no coincide con las fechas',
            self::AlreadyImported => 'Este mes ya tiene datos',
            self::RateConflict => 'Tasa distinta a la ya registrada',
        };
    }

    /**
     * Decisiones que se ofrecen; la primera es la propuesta. Vacío = solo informa.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        return match ($this) {
            self::MissingDay => [
                ['value' => 'omit', 'label' => 'Dejarlo sin cargar'],
                ['value' => 'closed', 'label' => 'Registrarlo como día cerrado'],
            ],
            self::DuplicateDate => [
                ['value' => 'first', 'label' => 'Usar la primera fila'],
                ['value' => 'last', 'label' => 'Usar la última fila'],
                ['value' => 'omit', 'label' => 'Omitir ese día'],
            ],
            self::DateOutOfPeriod, self::NegativeValue, self::MissingValue => [
                ['value' => 'omit', 'label' => 'Omitir ese día'],
            ],
            self::MonthMismatch => [
                ['value' => 'dates', 'label' => 'Confiar en las fechas'],
                ['value' => 'skip', 'label' => 'No importar este archivo'],
            ],
            self::SalesDeviation => [
                ['value' => 'accept', 'label' => 'Aceptar tal cual'],
                ['value' => 'atypical', 'label' => 'Marcar el día como atípico'],
            ],
            self::RateJump, self::UnitsLtTransactions => [
                ['value' => 'accept', 'label' => 'Aceptar tal cual'],
                ['value' => 'omit', 'label' => 'Omitir ese día'],
            ],
            self::AlreadyImported => [
                ['value' => 'replace', 'label' => 'Reemplazar con el archivo'],
                ['value' => 'skip', 'label' => 'No importar este archivo'],
            ],
            self::RateConflict => [
                ['value' => 'keep', 'label' => 'Conservar la tasa registrada'],
                ['value' => 'replace', 'label' => 'Usar la del archivo'],
            ],
            default => [],
        };
    }
}
