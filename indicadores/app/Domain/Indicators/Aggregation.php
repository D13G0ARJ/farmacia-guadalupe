<?php

declare(strict_types=1);

namespace App\Domain\Indicators;

/**
 * Cómo se agrega un indicador a nivel de período (RN-04, RN-05, §2.3).
 */
enum Aggregation: string
{
    /** Σ de los valores diarios. */
    case Sum = 'sum';

    /** Σ numerador / Σ denominador. Nunca promedio de ratios. */
    case WeightedRatio = 'weighted_ratio';

    /** Promedio ponderado por la venta del día (tasa). */
    case WeightedAverage = 'weighted_average';

    /** Promedio de los días que tienen dato, informando n (inventario). */
    case AverageWithCount = 'average_with_count';

    /** Último valor del período (inventario de cierre). */
    case Last = 'last';

    /** Derivado del primero y el último (variación de tasa). */
    case FirstToLast = 'first_to_last';
}
