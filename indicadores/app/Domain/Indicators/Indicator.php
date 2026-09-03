<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

use App\Domain\Shared\Formatter;
use App\Enums\Currency;
use Brick\Math\BigDecimal;

/**
 * Catálogo de indicadores (§6.1, §7.1). Cada caso conoce su etiqueta, unidad,
 * precisión de presentación, agregación de período y si admite meta.
 */
enum Indicator: string
{
    case SalesBs = 'sales_bs';
    case SalesUsd = 'sales_usd';
    case Transactions = 'transactions';
    case Units = 'units';
    case AvgTicketBs = 'avg_ticket_bs';
    case AvgTicketUsd = 'avg_ticket_usd';
    case UnitsPerTransaction = 'units_per_transaction';
    case TransactionsPerShift = 'transactions_per_shift';
    case Shifts = 'shifts';
    case AvgRate = 'avg_rate';
    case InventoryUnits = 'inventory_units';
    case InventoryValueUsd = 'inventory_value_usd';
    case SalesPerShiftUsd = 'sales_per_shift_usd';
    case RateVariationPct = 'rate_variation_pct';

    public function label(): string
    {
        return match ($this) {
            self::SalesBs => 'Venta en bolívares',
            self::SalesUsd => 'Venta en dólares',
            self::Transactions => 'Transacciones',
            self::Units => 'Unidades vendidas',
            self::AvgTicketBs => 'Ticket promedio en bolívares',
            self::AvgTicketUsd => 'Ticket promedio en dólares',
            self::UnitsPerTransaction => 'Unidades por compra',
            self::TransactionsPerShift => 'Transacciones por jornada',
            self::Shifts => 'Jornadas',
            self::AvgRate => 'Tasa promedio',
            self::InventoryUnits => 'Unidades en inventario',
            self::InventoryValueUsd => 'Valuación de inventario',
            self::SalesPerShiftUsd => 'Venta por jornada',
            self::RateVariationPct => 'Variación de la tasa',
        };
    }

    /** Etiqueta corta para encabezados de tabla. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::SalesBs => 'Venta Bs',
            self::SalesUsd => 'Venta $',
            self::Transactions => 'Transacciones',
            self::Units => 'Unidades',
            self::AvgTicketBs => 'Ticket Bs',
            self::AvgTicketUsd => 'Ticket $',
            self::UnitsPerTransaction => 'Und./compra',
            self::TransactionsPerShift => 'Trans./jornada',
            self::Shifts => 'Jornadas',
            self::AvgRate => 'Tasa',
            self::InventoryUnits => 'Inv. unidades',
            self::InventoryValueUsd => 'Inv. valuación $',
            self::SalesPerShiftUsd => 'Venta/jornada $',
            self::RateVariationPct => 'Var. tasa',
        };
    }

    /** Fórmula en palabras para la ayuda "¿Cómo se calcula?" (§13.5). */
    public function explanation(): string
    {
        return match ($this) {
            self::SalesBs => 'Suma de la venta diaria en bolívares.',
            self::SalesUsd => 'Suma de la venta de cada día convertida a dólares con la tasa BCV de ese día.',
            self::Transactions => 'Suma de las transacciones del período.',
            self::Units => 'Suma de las unidades vendidas del período.',
            self::AvgTicketBs => 'Venta del período en bolívares dividida entre las transacciones del período.',
            self::AvgTicketUsd => 'Venta del período en dólares dividida entre las transacciones del período.',
            self::UnitsPerTransaction => 'Unidades vendidas del período divididas entre las transacciones del período.',
            self::TransactionsPerShift => 'Transacciones del período divididas entre las jornadas trabajadas.',
            self::Shifts => 'Suma de las jornadas trabajadas.',
            self::AvgRate => 'Tasa promedio del período ponderada por la venta de cada día.',
            self::InventoryUnits => 'Promedio de las unidades en inventario en los días con conteo.',
            self::InventoryValueUsd => 'Promedio de la valuación del inventario en los días con conteo, en dólares.',
            self::SalesPerShiftUsd => 'Venta del período en dólares dividida entre las jornadas trabajadas.',
            self::RateVariationPct => 'Variación porcentual entre la primera y la última tasa del período.',
        };
    }

    public function unit(): Unit
    {
        return match ($this) {
            self::SalesBs, self::AvgTicketBs => Unit::Bs,
            self::SalesUsd, self::AvgTicketUsd, self::InventoryValueUsd, self::SalesPerShiftUsd => Unit::Usd,
            self::Transactions, self::Units, self::Shifts, self::InventoryUnits => Unit::Count,
            self::UnitsPerTransaction, self::TransactionsPerShift => Unit::Ratio,
            self::AvgRate => Unit::RatePerUsd,
            self::RateVariationPct => Unit::Percent,
        };
    }

    /** Decimales de presentación en tabla (§2.2, H7). */
    public function precision(): int
    {
        return match ($this) {
            self::SalesBs, self::AvgRate => 2,
            self::AvgTicketUsd, self::UnitsPerTransaction, self::RateVariationPct => 1,
            default => 0,
        };
    }

    /** Valor formateado según la unidad del indicador (§2.2): "Bs 91.154,02", "$ 614", "1,9". */
    public function format(Formatter $formatter, BigDecimal|string|int|float|null $value, ?int $precision = null): string
    {
        $precision ??= $this->precision();

        return match ($this->unit()) {
            Unit::Bs => $formatter->money($value, Currency::Bs, $precision),
            Unit::Usd => $formatter->money($value, Currency::Usd, $precision),
            Unit::Percent => $formatter->pct($value, $precision),
            default => $formatter->number($value, $precision),
        };
    }

    /** Decimales de presentación en detalle (tarjetas ampliadas, PDF). */
    public function detailPrecision(): int
    {
        return match ($this) {
            self::SalesUsd, self::SalesPerShiftUsd, self::InventoryValueUsd, self::AvgTicketBs => 2,
            self::UnitsPerTransaction => 2,
            default => $this->precision(),
        };
    }

    public function aggregation(): Aggregation
    {
        return match ($this) {
            self::SalesBs, self::SalesUsd, self::Transactions, self::Units, self::Shifts => Aggregation::Sum,
            self::AvgTicketBs, self::AvgTicketUsd, self::UnitsPerTransaction,
            self::TransactionsPerShift, self::SalesPerShiftUsd => Aggregation::WeightedRatio,
            self::AvgRate => Aggregation::WeightedAverage,
            self::InventoryUnits, self::InventoryValueUsd => Aggregation::AverageWithCount,
            self::RateVariationPct => Aggregation::FirstToLast,
        };
    }

    public function supportsGoal(): bool
    {
        return match ($this) {
            self::SalesBs, self::SalesUsd, self::Transactions, self::Units, self::AvgTicketBs,
            self::AvgTicketUsd, self::UnitsPerTransaction, self::TransactionsPerShift, self::SalesPerShiftUsd => true,
            default => false,
        };
    }

    public function goalCurrency(): Currency
    {
        return match ($this->unit()) {
            Unit::Bs => Currency::Bs,
            Unit::Usd => Currency::Usd,
            default => Currency::None,
        };
    }

    /** Si "más" es mejor: decide el color de los deltas. La tasa no se colorea (§13.1). */
    public function moreIsBetter(): ?bool
    {
        return match ($this) {
            self::AvgRate, self::RateVariationPct => null,
            default => true,
        };
    }

    /**
     * Indicadores primarios del panel, en orden (§13.7).
     *
     * @return list<self>
     */
    public static function primary(): array
    {
        return [self::SalesUsd, self::Transactions, self::AvgTicketUsd, self::UnitsPerTransaction];
    }

    /**
     * Fila secundaria plegada del panel (§13.7).
     *
     * @return list<self>
     */
    public static function secondary(): array
    {
        return [self::SalesBs, self::Units, self::TransactionsPerShift, self::InventoryValueUsd];
    }

    /**
     * Orden de la tabla anual del Excel (§2.4).
     *
     * @return list<self>
     */
    public static function annualOrder(): array
    {
        return [
            self::SalesBs, self::SalesUsd, self::AvgRate, self::Transactions, self::Units,
            self::AvgTicketBs, self::UnitsPerTransaction, self::AvgTicketUsd,
            self::InventoryValueUsd, self::InventoryUnits, self::TransactionsPerShift, self::Shifts,
        ];
    }
}
