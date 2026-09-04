<x-mail::message>
# Indicadores de {{ mb_strtolower($periodLabel) }}

**{{ $branchName }}** · {{ $summary['days'] }} {{ $summary['days'] === 1 ? 'día cargado' : 'días cargados' }}@if ($summary['missing'] > 0) · faltan {{ $summary['missing'] }} por cargar @endif@if ($summary['closed']) · mes cerrado @endif

| | |
|:--|--:|
| Venta en dólares | {{ $summary['salesUsd'] }} |
| Venta en bolívares | {{ $summary['salesBs'] }} |
| Transacciones | {{ $summary['transactions'] }} |

El reporte completo va adjunto en PDF: indicadores del mes, metas, cuadro de indicadores y observaciones. Las gráficas se ven en el panel.

<x-mail::button :url="$url">
Abrir el panel
</x-mail::button>

Este correo se envía automáticamente según los parámetros de Administración.
</x-mail::message>
