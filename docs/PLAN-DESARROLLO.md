# Plan de Desarrollo — Dashboard de Indicadores
## Farmacia Guadalupe, C.A.

| | |
|---|---|
| **Alcance** | Etapa 1: dashboard de indicadores y metas (sin Chatwoot, sin pedidos) |
| **Stack** | Laravel 13 · PHP 8.4 · MySQL 8 (SQLite en desarrollo y pruebas) · Livewire 4 · Tailwind 3 · Alpine · ECharts 6 |
| **Tasa** | BCV (Banco Central de Venezuela), con arrastre en días no publicados |
| **Versión del plan** | 13 (Fases 0 a 7 implementadas y probadas; entrega en `DESPLIEGUE.md` y `MANUAL-USUARIO.md`; estado por caso de uso en §21) |
| **Fecha** | 04-09-2026 |

---

## Índice

1. Alcance y objetivos
2. Especificación derivada del Excel
3. Reglas de negocio
4. Arquitectura
5. Modelo de datos
6. Capa de dominio
7. Motor de indicadores
8. Sistema de metas
9. Tasa BCV
10. Importador de histórico
11. Exportación
12. Casos de uso
13. UI y UX
14. Gráficas
15. Seguridad y roles
16. Pruebas
17. Fases de implementación
18. Matriz de cobertura
19. Preguntas pendientes al cliente y supuestos de construcción
20. Registro de iteraciones del plan
21. Estado del producto al 03-09-2026 (hecho y pendiente por caso de uso)

---

## 1. Alcance y objetivos

### 1.1 Qué se construye

Una aplicación web que **reemplaza por completo** el archivo `CUADRO DE INDICADORES MES <MES> GUADALUPE.xlsx`:

- Captura diaria de los 7 datos primarios (no 14).
- Cálculo automático y correcto de los 5 indicadores derivados y de los agregados del período.
- Tablero con KPIs, variaciones y cumplimiento de metas.
- Las 7 gráficas actuales corregidas + 4 nuevas.
- Sistema de metas con proyección de cierre (no existe hoy).
- Comparativa anual (la tabla vacía de las filas 41–53).
- Importación de los Excel históricos.
- Exportación a Excel (mismo formato) y PDF.
- Preparado para varias sedes desde el modelo; activación de multi-sede como ampliación.

### 1.2 Qué NO se construye

- Integración con Chatwoot (Etapa 2, fuera).
- Gestión de pedidos / embudo (fuera).
- Conexión con el sistema administrativo o punto de venta: **la carga diaria es manual por definición**.
- Facturación, contabilidad, inventario por producto.

### 1.3 Criterio de éxito

> Una persona carga un día en menos de un minuto, sin poder introducir los errores que hoy contiene el Excel (día de la semana corrido, tasa mal tecleada, promedio de promedios), y la gerencia ve al día 15 si el mes va a cumplir la meta.

### 1.4 Alcance por fases (resumen)

| Fase | Contenido | Estado comercial |
|---|---|---|
| MVP | Carga diaria, motor de cálculo, panel KPI, metas con proyección, 4–6 gráficas, cuadro de indicadores, export Excel | Cotizado (US$ 270) |
| Ampliación A | Importador de histórico, comparativa anual, 11 gráficas, PDF, envío programado | Ampliaciones |
| Ampliación B | Activación multi-sede: selector, consolidado, permisos por sede | US$ 150 |

El plan cubre **todo**; §17 marca qué entra en cada fase. La arquitectura es la misma en todas: no se construye nada dos veces.

> **Criterio de entrega (03-09-2026).** El cliente contrató solo el dashboard, pero se entrega como un sistema completo, funcional y amigable: todo lo de la Ampliación A (importador, año, PDF), las pantallas de administración y tasa BCV y la capa de amigabilidad de §13.8 forman parte de la entrega. Solo la Ampliación B (multi-sede) queda condicionada a que activen la segunda sede.

---

## 2. Especificación derivada del Excel

Esta sección es la **fuente de verdad funcional**. Todo lo que sigue se deriva de aquí. Proviene de cuatro pasadas de análisis sobre la estructura interna del archivo (fórmulas, gráficos, formatos, metadatos), no solo de sus valores.

### 2.1 Estructura del archivo

| Elemento | Detalle |
|---|---|
| Hojas | 8: `Indicadores ` (con espacio final) + 7 hojas de un gráfico cada una |
| Encabezado del reporte | `B2` = mes en mayúsculas (`SEPTIEMBRE`), `C2` = razón social (`FARMACIA GUADALUPE, C.A.`) |
| Fila de encabezados | Fila 3, negrita, relleno `#00B0F0`, centrada, con ajuste de texto, altura 31,5 |
| Datos | Filas 4–33 (un renglón por día), 14 columnas A–N |
| Totales | Fila 34 |
| Tabla anual | Filas 41–53: encabezado de meses en fila 41 (C–N = Enero…Diciembre), 12 indicadores en B42–B53. **Vacía** |
| Residuos | `C59 = 14.47+15.8+51.2` (borrador); anuncio de MSN Games con 2 imágenes y 4 hipervínculos en la hoja `Venta en $` |
| Vista | Zoom 80 %, orientación horizontal, sin paneles fijos, sin celdas combinadas |
| Fuente | Calibri 11 |
| Fórmulas | 163 (30 días × 5 derivadas + 12 totales + 1 residual). Sin fórmulas ocultas |
| Validación / protección / macros | Ninguna |

### 2.2 Columnas: origen, fórmula y formato de presentación

| Col | Encabezado exacto | Origen | Fórmula Excel | Formato Excel | Precisión a adoptar |
|---|---|---|---|---|---|
| A | `#` | Manual (letra del día) | — | General | **Derivado de la fecha**. Mostrar `lun`, `mar`, `mié`… (el Excel usa L/M/M/J/V/S/D, ambiguo: martes y miércoles son "M") |
| B | `Fecha` | Manual | — | `m/d/yyyy` (locale 1540A = es-VE) | `dd/mm/yyyy` |
| C | `Venta BS ` (espacio final) | **Primario** | — | `#,##0.00` | 2 decimales |
| D | `Venta en $` | Derivado | `=IF(ISERROR(C/E),"-",C/E)` | `0` | **0 decimales en tabla**, 2 en detalle |
| E | `Tasa $` | **Primario** | — | `#,##0.00` | 2 decimales (almacenar 4) |
| F | `TRN` | **Primario** | — | General | Entero |
| G | `Unidades` | **Primario** | — | General | Entero |
| H | `Ticket Promedio` | Derivado | `=IF(ISERROR(C/F),"-",C/F)` | `#,##0` | 0 decimales |
| I | `Unidades Promedio\n x Compra` (salto de línea literal) | Derivado | `=IF(ISERROR(G/F),"-",G/F)` | `0.0` | 1 decimal |
| J | `Ticket Promedio en $` | Derivado | `=IF(ISERROR(H/E),"-",H/E)` | `0.0` | 1 decimal |
| K | `Unidades Cargadas (inventario)` | **Primario** | — | General | Entero |
| L | `Valuacion de Inventario costo` | **Primario** | — | `#,##0` | 0 decimales en tabla. **Moneda: USD** (ver 2.5) |
| M | `Transacciones/ Jornadas` | Derivado | `=IF(ISERROR(F/N),"-",F/N)` | `0` | 0 decimales |
| N | `Jornada` | **Primario** | — | General | Entero |

**7 primarios**: B, C, E, F, G, K, L, N (la fecha es clave, no dato). **5 derivados**: D, H, I, J, M. **1 derivable**: A.

### 2.3 Fila de totales (fila 34): qué hace el Excel y qué debe hacer el sistema

| Col | Excel | Problema | Sistema |
|---|---|---|---|
| C | `SUM` | — | Suma |
| D | `SUM` de los $ diarios | Correcto | Suma de conversiones diarias (nunca `sum(C)/avg(E)`) |
| E | `AVERAGE` | Promedio simple | Promedio **ponderado por venta**; mostrar también simple si se pide |
| F, G | `SUM` | — | Suma |
| H | `AVERAGE(H4:H33)` | Promedio de promedios (+0,35 %) | `sum(C)/sum(F)` |
| I | `AVERAGE` | Ídem (+0,35 %) | `sum(G)/sum(F)` |
| J | `AVERAGE` | Ídem (+0,42 %) | `sum(D)/sum(F)` |
| K | `AVERAGE` | Ignora vacíos en silencio (25 de 30 días) | Promedio de días con conteo **y** valor de cierre; indicar `n` |
| L | `AVERAGE` (formato contable en euros, residuo de copia) | Ídem | Ídem |
| M | `AVERAGE` | Ídem (+0,52 %) | `sum(F)/sum(N)` |
| N | `AVERAGE` | — | Suma (jornadas del mes) y promedio |

El desvío del promedio de promedios es pequeño en meses normales (< 0,1 % sin el día atípico) pero **no tiene cota superior**. El sistema usa ratios ponderados siempre.

### 2.4 Tabla anual (filas 41–53): orden y correspondencia

| # | Etiqueta en el Excel | Indicador del sistema | Agregación mensual |
|---|---|---|---|
| 1 | Venta en Bs | `sales_bs` | Suma |
| 2 | Venta en $ | `sales_usd` | Suma |
| 3 | Promedio Tasa $ | `avg_rate` | Ponderado por venta |
| 4 | Transacciones | `transactions` | Suma |
| 5 | Unidades | `units` | Suma |
| 6 | Ticket Promedio en Bs | `avg_ticket_bs` | Ponderado |
| 7 | Unidades Promedio x Compra | `units_per_transaction` | Ponderado |
| 8 | Ticket Promedio en $ | `avg_ticket_usd` | Ponderado |
| 9 | Valuacion de Inventario | `inventory_value` | Promedio de días con conteo |
| 10 | unidades | `inventory_units` | Promedio de días con conteo |
| 11 | transaccionesxjornada | `transactions_per_shift` | Ponderado |
| 12 | Jornada | `shifts` | Suma |

Nota: el orden 9/10 está invertido respecto a las columnas diarias (L antes que K). La exportación respeta **este** orden en la hoja anual y el orden de columnas en la hoja diaria.

### 2.5 Hechos verificados que condicionan el diseño

| # | Hecho | Evidencia | Consecuencia de diseño |
|---|---|---|---|
| H1 | La columna A está corrida +4 días en 30/30 registros | Copiada de la plantilla de agosto (agosto 2025 inicia en viernes) | El día de la semana **nunca se captura**; se deriva de la fecha |
| H2 | La valuación de inventario está en **USD** | `L4 = 21.848,73` con venta diaria de Bs 91.154 (≈ $614); en Bs equivaldría a $147, absurdo | `inventory_value` se captura y almacena en USD; se muestra en Bs convertido si se pide |
| H3 | No hay conteo de inventario los **sábados**: 4 de 4 sábados sin K ni L | Los domingos **sí** tienen conteo (7/9, 14/9, 21/9, 28/9 con datos) | Config por sede `inventory_days` = todos menos sábado (por defecto); los campos se ocultan en días sin conteo |
| H4 | La tasa se repite sábado y domingo (8 de 9 repeticiones) y nunca decrece | Comportamiento del BCV, que no publica fines de semana | Regla de **arrastre**: en día sin publicación se usa la última tasa publicada, marcada como `carried` |
| H5 | Un día atípico (16/9: 28 % de un día normal, 32 TRN, jornadas = 3) sin lugar donde explicarlo | Único outlier por Tukey | Campo `notes` + marca `is_atypical` + exclusión opcional de promedios y proyección |
| H6 | Jornadas: 3 en 28 días, 4 en 2 días (1/9 y 7/9) | Columna N | Valor por defecto configurable por sede = 3 |
| H7 | Venta en $ se muestra sin decimales; ticket Bs sin decimales; ratios con 1 decimal | Formatos de número | Tabla de precisión de presentación (2.2), configurable |
| H8 | Los gráficos originales no tienen eje de fechas ni eje secundario; uno grafica 30 ceros; otro incluye encabezado y totales | XML de charts | Especificación propia de gráficas (§14); ninguna se replica "tal cual" |
| H9 | El gráfico de inventario tiene 25 puntos (excluye los 5 vacíos) | numCache | En gráficas, los días sin conteo son **huecos** (`null`), no ceros |
| H10 | Encabezados con espacios finales y saltos de línea; nombre de hoja con espacio final | `'Venta BS '`, `'Indicadores '`, `'Unidades Promedio\n x Compra'` | El importador normaliza: trim, colapso de espacios, sin saltos, sin acentos, minúsculas |
| H11 | Ruta original `…\guadalupe indicadores\09-SEPTIEMBRE\` | workbook.xml | Existe un archivo por mes: el importador procesa **lotes** de archivos |
| H12 | El autofiltro abarca la fila de totales | `_FilterDatabase = A3:N34` | Los totales **nunca** se almacenan con los datos; se calculan en presentación |
| H13 | Colores de gráficos: tema Office por defecto (azul `#5B9BD5`, naranja `#ED7D31`) con retoques manuales inconsistentes | XML | No hay esquema de color que respetar: se define uno propio con la paleta de la marca (§13) |

### 2.6 Valores de referencia del mes auditado (para pruebas)

Estos valores, recalculados de forma independiente y contrastados con los cacheados en el archivo, son los **valores dorados** de las pruebas automatizadas (§16).

| Métrica | Valor |
|---|---|
| Venta Bs (suma) | 3.012.770,86 |
| Venta USD (suma de diarios) | 18.610,68 |
| Venta USD si se hiciera `sum(Bs)/avg(tasa)` (INCORRECTO) | 18.633,78 |
| Transacciones | 3.853 |
| Unidades | 7.543 |
| Jornadas | 93 |
| Ticket promedio Bs ponderado | 781,93 (Excel: 784,63) |
| Unidades por compra ponderado | 1,9577 (Excel: 1,9645) |
| Ticket promedio USD ponderado | 4,8302 (Excel: 4,8503) |
| TRN por jornada ponderado | 41,43 (Excel: 41,64) |
| Tasa mín / máx | 148,44 / 177,61 (+19,65 %) |
| Días con inventario | 25 de 30 |
| Inventario USD inicio / fin / promedio | 21.848,73 / 23.545,31 / 22.716,44 |
| Día atípico | 2025-09-16 (USD 178 vs mediana 630) |
| Mejor / peor día de semana (USD promedio) | miércoles 745 / martes 560 |

---

## 3. Reglas de negocio

Numeradas para trazabilidad en código, pruebas y casos de uso.

| ID | Regla |
|---|---|
| RN-01 | Un registro diario es único por `(sede, fecha)`. |
| RN-02 | El día de la semana se deriva de la fecha. No es capturable ni editable. |
| RN-03 | Los indicadores derivados (venta $, ticket Bs, ticket $, unidades/compra, TRN/jornada) **no se almacenan**; se calculan al consultar. |
| RN-04 | Los agregados de período usan ratios ponderados: `Σnumerador / Σdenominador`. Nunca promedio de ratios. |
| RN-05 | La venta en divisa del período es la suma de las conversiones diarias, cada una a su propia tasa. |
| RN-06 | Cada registro diario **congela** la tasa aplicada (`exchange_rate` snapshot). Corregir la tabla de tasas después no altera registros existentes salvo acción explícita "recalcular". |
| RN-07 | La tasa de un día sin publicación BCV es la última publicada anterior (arrastre), con `source = carried`. |
| RN-08 | La tasa es global (nacional), no por sede. |
| RN-09 | Los campos de inventario son opcionales en los días configurados sin conteo (por defecto sábados). En los demás días su ausencia genera **advertencia**, no bloqueo. |
| RN-10 | La valuación de inventario se captura en USD. |
| RN-11 | Un día puede marcarse **atípico** con motivo. Los días atípicos se incluyen en totales, pero pueden excluirse de promedios y de la proyección de metas (opción en la vista, por defecto excluidos de la proyección). |
| RN-12 | Un día puede registrarse como **cerrado** (sin operación): ventas 0, TRN 0, jornadas 0, motivo obligatorio. Se distingue de "día no cargado". |
| RN-13 | Un mes puede **cerrarse**; cerrado, sus registros no se editan. Reabrir exige permiso `periods.reopen` y motivo; queda en bitácora. |
| RN-14 | Toda creación, edición o borrado de registro diario, tasa, meta o cierre deja bitácora: usuario, fecha-hora, valores anteriores y nuevos. |
| RN-15 | Validaciones duras (bloquean): fecha válida y no futura; venta ≥ 0; tasa > 0; TRN ≥ 0; unidades ≥ 0; jornadas entre 0 y 6; inventario ≥ 0. |
| RN-16 | Validaciones blandas (advierten, permiten guardar con confirmación): venta desvía > umbral (config, defecto 35 %) de la media móvil de 14 días; tasa desvía > 10 % de la anterior; unidades < TRN; TRN > 0 con venta 0; venta > 0 con TRN 0; inventario ausente en día con conteo. |
| RN-17 | La meta se define por `(sede | consolidado, indicador, mes)`. Las metas monetarias se expresan en USD por defecto (una meta en Bs "se cumple sola" con la devaluación); se permite Bs con advertencia. |
| RN-18 | La proyección de cierre usa el patrón semanal histórico cuando hay ≥ 8 semanas de datos; si no, proyección lineal. |
| RN-19 | La precisión de presentación sigue la tabla 2.2; el almacenamiento usa `DECIMAL`, nunca `FLOAT`. |
| RN-20 | Formato es-VE: miles con punto, decimales con coma; fechas `dd/mm/yyyy`; zona horaria `America/Caracas`. |
| RN-21 | La reimportación de un archivo ya importado (mismo hash o mismo mes-sede) no duplica: ofrece reemplazar. |
| RN-22 | El sistema es multi-sede desde el modelo. Con una sola sede activa, el selector de sede no se muestra. |
| RN-23 | Un usuario solo ve las sedes que tiene asignadas; el rol `direccion` ve todas y el consolidado. |
| RN-24 | Un día cerrado (sin operación) también almacena la tasa resuelta para esa fecha, para que la serie de tasas del mes sea continua. |
| RN-25 | "Hoy" y "fecha futura" se evalúan en `America/Caracas`, no en la zona del servidor ni del navegador. |
| RN-26 | La vista previa de derivados en el formulario se calcula en el navegador; el servidor recalcula al guardar y es la única fuente de verdad. Ambas implementaciones deben coincidir (prueba de paridad). |

---

## 4. Arquitectura

### 4.1 Stack y paquetes

| Capa | Elección | Motivo |
|---|---|---|
| Framework | Laravel 13 (PHP 8.4) | Auth, validación, colas, scheduler, migraciones resueltos. Versión instalada en la Fase 0 |
| UI | Livewire 4 (Volt) + Blade + Alpine.js + Tailwind CSS 3 | Reactividad sin SPA; una sola base de código |
| Gráficas | **Apache ECharts 5** (npm, importado por módulos con tree-shaking, renderer SVG) | Eje secundario, heatmap de calendario nativo, `markLine`/`markPoint`/`markArea` para metas y días atípicos, `getDataURL()` para el PDF, tema registrable con los tokens de marca, licencia Apache 2.0. Decisión razonada en §14.1 |
| Estadística (servidor) | `markrogoyski/math-php` | Mediana, percentiles, desviación estándar, detección de atípicos (Tukey) y regresión lineal de respaldo para la proyección; evita reimplementar estadística a mano |
| BD | MySQL 8 (InnoDB, `utf8mb4`) | `DECIMAL` exacto, disponibilidad en hosting local |
| Auth scaffolding | Laravel Breeze (stack Livewire) | Login, recuperación de clave, perfil |
| Roles/permisos | `spatie/laravel-permission` | Roles + permisos granulares + asignación por sede vía pivot propio |
| Bitácora | `spatie/laravel-activitylog` | Auditoría de modelos con old/new automáticos |
| Excel | `maatwebsite/excel` (PhpSpreadsheet) | Importación y exportación con estilos |
| PDF | `barryvdh/laravel-dompdf` | Sin dependencia de Chrome en el servidor; gráficas se envían como imágenes desde el navegador |
| Decimales | `brick/math` (BigDecimal) | Aritmética exacta en el motor de indicadores |
| Fechas | Carbon (incluido), `setlocale es_VE` / `Carbon::setLocale('es')` | Nombres de día/mes en español |
| Pruebas | Pest | Sintaxis clara; datasets para los valores dorados |
| Estática | Laravel Pint + Larastan (nivel 6) | Estilo y análisis estático |

### 4.2 Principios

1. **Cálculo puro y testeable.** El motor de indicadores (`App\Domain\Indicators`) no depende de Eloquent: recibe colecciones de DTOs y devuelve DTOs. Se prueba con los valores dorados sin base de datos.
2. **Acciones de un solo propósito.** Cada caso de uso de escritura es una clase `Action` invocable (`RegisterDailyRecord`, `CloseMonth`, `ImportWorkbook`…). Los componentes Livewire y los controladores solo orquestan. Toda acción que escribe más de una fila corre dentro de `DB::transaction()`; la bitácora se emite dentro de la misma transacción.
3. **Lecturas por consultas dedicadas.** Las pantallas leen a través de clases `Query` que devuelven read-models (DTOs) ya formateados para la vista; ningún componente hace `DailyRecord::where(...)` a mano.
4. **Enums para todo vocabulario cerrado.** Indicadores, fuentes de tasa, estados de día, roles, permisos.
5. **Sin lógica en Blade.** Formateo mediante un `Formatter` inyectable (es-VE) y componentes Blade.
6. **Multi-sede transversal.** Todo query lleva `branch_id` o `null` (consolidado). Un `CurrentBranch` en sesión, resuelto por middleware.
7. **Cache de agregados.** Los resúmenes de período se cachean por `(sede, período, excluir_atípicos)` e invalidan al guardar o borrar cualquier registro del período (observer), al confirmar una importación y al ejecutar `RecalculateMonthRates`. Cambiar la tabla `exchange_rates` **no** invalida (los registros conservan su snapshot, RN-06).

### 4.3 Estructura de carpetas

```
app/
├── Domain/
│   ├── Indicators/
│   │   ├── Indicator.php                 (enum: catálogo, etiqueta, unidad, precisión, agregación)
│   │   ├── DailyMetrics.php              (DTO: un día con derivados calculados)
│   │   ├── PeriodSummary.php             (DTO: agregados ponderados del período)
│   │   ├── IndicatorCalculator.php       (puro: DailyRecordData[] -> DailyMetrics[] + PeriodSummary)
│   │   ├── WeekdayPattern.php            (pesos por día de semana a partir del histórico)
│   │   └── DeltaCalculator.php           (variaciones vs período anterior / año anterior)
│   ├── Goals/
│   │   ├── GoalProjector.php             (avance, meta a la fecha, proyección, estado)
│   │   └── GoalStatus.php                (enum: on_track / at_risk / off_track / no_goal)
│   ├── Rates/
│   │   ├── ExchangeRateProvider.php      (interfaz)
│   │   ├── Providers/BcvProvider.php
│   │   ├── Providers/NullProvider.php
│   │   └── RateResolver.php              (tasa para una fecha: publicada > arrastre > null)
│   ├── Imports/
│   │   ├── WorkbookParser.php            (lee el .xlsx con la plantilla actual)
│   │   ├── ParsedMonth.php               (DTO)
│   │   ├── AnomalyDetector.php
│   │   └── Anomaly.php                   (DTO + enum de tipos)
│   └── Shared/
│       ├── Decimal.php                   (helpers sobre BigDecimal)
│       ├── Period.php                    (VO: mes/rango con utilidades)
│       └── Formatter.php                 (es-VE: números, moneda, fechas, día de semana)
├── Actions/
│   ├── Records/ RegisterDailyRecord, UpdateDailyRecord, MarkDayAtypical, RegisterClosedDay, DeleteDailyRecord
│   ├── Periods/ CloseMonth, ReopenMonth
│   ├── Rates/  UpsertExchangeRate, FetchBcvRate, RecalculateMonthRates
│   ├── Goals/  UpsertGoal, SuggestGoal
│   ├── Imports/ ImportWorkbook, ConfirmImport
│   └── Exports/ ExportMonthWorkbook, ExportMonthPdf
├── Queries/
│   ├── MonthRecordsQuery.php             (cuadro de indicadores)
│   ├── DashboardQuery.php                (KPIs + deltas + metas)
│   ├── ChartSeriesQuery.php              (series para cada gráfica)
│   ├── AnnualComparisonQuery.php
│   ├── MissingDaysQuery.php
│   └── GoalProgressQuery.php
├── Models/  Branch, DailyRecord, ExchangeRate, Goal, PeriodEvent, ImportBatch, User, Setting
├── Enums/   DayStatus, RateSource, Role, Permission, ImportStatus, AnomalyType, Currency
├── Livewire/
│   ├── Dashboard/ Overview, KpiCard, GoalProgress
│   ├── Records/   DailyForm, MonthTable, MonthCalendar
│   ├── Charts/    ChartPanel
│   ├── Goals/     GoalsManager
│   ├── Annual/    AnnualComparison
│   ├── Imports/   ImportWizard
│   ├── Rates/     RatesManager
│   ├── Admin/     Branches, Users, Settings
│   └── Shared/    ContextBar (sede, período, moneda)
├── Http/
│   ├── Controllers/ ExportController (descargas), ChartImageController (recibe PNG para PDF)
│   ├── Middleware/  ResolveCurrentBranch, EnsureBranchAccess
│   └── Requests/    (solo para endpoints no-Livewire)
├── Policies/ DailyRecordPolicy, GoalPolicy, PeriodPolicy, BranchPolicy, ImportPolicy
├── Observers/ DailyRecordObserver (invalida cache), ExchangeRateObserver
├── Jobs/    FetchDailyBcvRate, ProcessImportBatch, SendMonthlyReport
├── Exports/ MonthWorkbookExport (hojas: Indicadores, Anual)
└── Support/ CurrentBranch, PeriodContext
```

Front-end: `resources/js/charts/{echarts.js, theme.js, formatters.js}` (importación por módulos, tema de marca, helpers es-VE) y `resources/views/components/` para los componentes Blade de §13.5.

### 4.4 Flujo de una petición típica (carga diaria)

```
Livewire DailyForm
  └─ valida (rules duras) → detecta advertencias (RN-16) → confirma si hay advertencias
     └─ RegisterDailyRecord::handle(DailyRecordData)
          ├─ RateResolver->forDate()  (si tasa no dada)
          ├─ DailyRecord::create()    (snapshot de tasa, status, notes)
          ├─ activity() log           (automático por trait)
          └─ event(DailyRecordSaved)  → DailyRecordObserver invalida cache del período
  └─ redirige a "siguiente día faltante" o muestra resumen del día con derivados
```

### 4.5 Configuración

| Clave | Ubicación | Defecto |
|---|---|---|
| Zona horaria | `config/app.php` | `America/Caracas` |
| Locale | `config/app.php` | `es` (+ `es_VE` para intl) |
| Umbral desvío venta | tabla `settings` / por sede | 35 % |
| Umbral desvío tasa | `settings` | 10 % |
| Umbrales estado de meta | `settings` | on_track ≥ 100 %, at_risk ≥ 90 % |
| Días sin conteo de inventario | `branches.inventory_days` | `[1,2,3,4,5,7]` (ISO: 6 = sábado excluido) |
| Jornadas por defecto | `branches.default_shifts` | 3 |
| Moneda de metas | `settings` | USD |
| Proveedor de tasa | `config/rates.php` | `bcv` (con `null` para desactivar) |

### 4.6 Infraestructura mínima en hosting

- **Cron**: una sola línea `* * * * * php artisan schedule:run` (obligatoria para la tasa BCV y el envío de reportes).
- **Colas**: `QUEUE_CONNECTION=database`; el scheduler ejecuta `queue:work --stop-when-empty` cada minuto. Evita depender de un proceso supervisado (Supervisor no suele estar disponible en hosting compartido). Si el hosting lo permite, se cambia a worker persistente sin tocar código.
- **PHP**: 8.3 con `intl`, `bcmath`, `gd` (DomPDF), `zip` (PhpSpreadsheet).
- **Almacenamiento**: `storage/app/private` para importaciones y PNG temporales de gráficas; limpieza programada diaria.
- **Respaldo**: `spatie/laravel-backup` diario a disco externo o S3-compatible; conservación 30 días.
- **Datos de demostración**: `DemoSeeder` importa el fixture de septiembre para que la primera sesión del cliente muestre datos reales y no un sistema vacío.

---

## 5. Modelo de datos

### 5.1 Diagrama

```
branches 1───n daily_records n───1 exchange_rates (por fecha, referencia informativa)
branches 1───n goals
branches 1───n period_events
branches 1───n import_batches
users n───n branches (branch_user)
users 1───n activity_log (spatie)
settings (clave/valor, global o por sede)
```

### 5.2 Tablas

#### `branches`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | varchar(120) | "Farmacia Guadalupe — Sede Principal" |
| code | varchar(20) unique | "GUA-01" |
| legal_name | varchar(160) | "FARMACIA GUADALUPE, C.A." (va al encabezado del reporte) |
| inventory_days | json | días ISO con conteo, defecto `[1,2,3,4,5,7]` |
| default_shifts | tinyint | 3 |
| sales_deviation_pct | decimal(5,2) nullable | override del umbral global |
| is_active | boolean | |
| timestamps | | |

#### `exchange_rates`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| date | date **unique** | fecha de vigencia |
| rate | decimal(12,4) | Bs por USD |
| source | enum(`bcv`,`manual`,`carried`) | |
| fetched_at | timestamp nullable | cuándo se obtuvo del proveedor |
| set_by | FK users nullable | si manual |
| timestamps | | |

#### `daily_records`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| branch_id | FK | |
| date | date | |
| status | enum(`normal`,`atypical`,`closed`) | RN-11, RN-12 |
| sales_bs | decimal(14,2) | |
| exchange_rate | decimal(12,4) | **snapshot** (RN-06) |
| exchange_rate_source | enum(`bcv`,`manual`,`carried`) | copia del origen al momento de guardar |
| transactions | int unsigned | |
| units | int unsigned | |
| inventory_units | int unsigned nullable | |
| inventory_value_usd | decimal(14,2) nullable | RN-10 |
| shifts | tinyint unsigned | |
| notes | varchar(500) nullable | |
| created_by / updated_by | FK users | |
| timestamps | | |
| **unique** (branch_id, date) | | RN-01 |
| **index** (branch_id, date desc) | | |

Sin columnas derivadas (RN-03). Sin `soft deletes`: el borrado queda en bitácora con los valores.

#### `goals`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| branch_id | FK nullable | `null` = consolidado |
| indicator | varchar(40) | valor del enum `Indicator` |
| period | date | primer día del mes |
| target | decimal(14,4) | |
| currency | enum(`USD`,`BS`,`NONE`) | según indicador |
| created_by | FK users | |
| timestamps | | |
| branch_key | bigint **generado** `COALESCE(branch_id, 0)` | evita el problema de `NULL` en índices únicos de MySQL |
| **unique** (branch_key, indicator, period) | | |

#### `period_events`
| Columna | Tipo | Notas |
|---|---|---|
| id, branch_id, period (date), action enum(`closed`,`reopened`), reason varchar(300) nullable, user_id, created_at | | RN-13. El estado del mes es el `action` de la **última** fila; se conserva el historial completo. Índice (branch_id, period, created_at) |

#### `import_batches`
| Columna | Tipo | Notas |
|---|---|---|
| id, branch_id, user_id | | |
| group_id | uuid | agrupa los archivos subidos en una misma operación (lote) |
| original_filename | varchar(255) | |
| file_hash | char(64) | sha256, para RN-21 |
| period | date nullable | mes detectado |
| status | enum(`parsed`,`confirmed`,`rejected`,`failed`) | |
| summary | json | filas leídas, anomalías por tipo, decisiones |
| parsed_payload | json | filas normalizadas pendientes de confirmar |
| timestamps | | |

#### `settings`
| Columna | Tipo |
|---|---|
| key varchar(80), branch_id FK nullable, branch_key bigint generado `COALESCE(branch_id,0)`, value json, unique(key, branch_key) | |

#### `users`, `roles`, `permissions`, `model_has_roles`… (spatie) + `branch_user` (user_id, branch_id, unique)

#### `activity_log` (spatie): `subject_type/id`, `causer_id`, `event`, `properties{old,attributes}`, `created_at`. Configuración: `logOnlyDirty`, `logOnly` de los siete primarios + `status`, `notes`, `exchange_rate`; `dontSubmitEmptyLogs`. Borrado de `daily_records` registra el snapshot completo para permitir restauración por un administrador (`RestoreDeletedRecord`, Ampliación A).

### 5.3 Índices y tamaños

- `daily_records`: 365 filas/año/sede. Trivial. Índices: unique compuesto + `(branch_id, date)`.
- `exchange_rates`: 1 fila/día. Unique en `date`.
- `DECIMAL(14,2)` cubre ventas hasta 999.999.999.999,99 Bs. Con la inflación venezolana, revisar en 2–3 años; migración trivial a `(18,2)`.

### 5.4 Casts de Eloquent

| Columna | Cast | Motivo |
|---|---|---|
| `date`, `period` | `immutable_date` | Evita mutaciones accidentales al operar con Carbon |
| `sales_bs`, `exchange_rate`, `inventory_value_usd`, `rate`, `target` | `string` (sin `float`) | El dominio los convierte a `BigDecimal`; nunca pasan por `float` |
| `status`, `source`, `exchange_rate_source`, `currency`, `action` | `enum` (backed) | Vocabulario cerrado con comportamiento |
| `inventory_days`, `summary`, `parsed_payload`, `value` | `array` / `AsArrayObject` | JSON tipado |
| `indicator` | `Indicator::class` | Enum de dominio |

Prohibido en el proyecto: `decimal:N` de Laravel (devuelve string redondeado por `number_format`, con pérdida) y `float` en cualquier monto. Regla verificada por Larastan con una regla personalizada o por revisión.

---

## 6. Capa de dominio

### 6.1 Enum `Indicator`

Cada caso conoce su etiqueta, unidad, precisión, si admite meta, y cómo se agrega.

```php
enum Indicator: string {
    case SalesBs             = 'sales_bs';
    case SalesUsd            = 'sales_usd';
    case Transactions        = 'transactions';
    case Units               = 'units';
    case AvgTicketBs         = 'avg_ticket_bs';
    case AvgTicketUsd        = 'avg_ticket_usd';
    case UnitsPerTransaction = 'units_per_transaction';
    case TransactionsPerShift= 'transactions_per_shift';
    case Shifts              = 'shifts';
    case AvgRate             = 'avg_rate';
    case InventoryUnits      = 'inventory_units';
    case InventoryValueUsd   = 'inventory_value_usd';
    case SalesPerShiftUsd    = 'sales_per_shift_usd';   // nuevo
    case RateVariationPct    = 'rate_variation_pct';    // nuevo

    public function label(): string;        // "Venta en Bs"
    public function unit(): Unit;           // Bs | USD | Count | Ratio | Percent
    public function precision(): int;       // según tabla 2.2
    public function aggregation(): Aggregation; // Sum | WeightedRatio | WeightedAvg | AvgWithCount | Last
    public function supportsGoal(): bool;   // ventas, TRN, unidades, tickets, unidades/compra
    public function goalCurrency(): Currency;
}
```

### 6.2 DTOs

- `DailyRecordData` (entrada al calculador): `date, branchId, status, salesBs, rate, transactions, units, inventoryUnits?, inventoryValueUsd?, shifts, notes?`
- `DailyMetrics` (salida por día): todo lo anterior + `weekday`, `salesUsd`, `avgTicketBs`, `avgTicketUsd`, `unitsPerTransaction`, `transactionsPerShift`, `salesPerShiftUsd`.
- `PeriodSummary`: `days, daysWithInventory, sums (bs, usd, trn, units, shifts), weighted (ticketBs, ticketUsd, unitsPerTrn, trnPerShift, rate), inventory (avgUnits, avgValue, lastUnits, lastValue), rateFirst, rateLast, rateVariationPct, excludedAtypical: int`.
- `Delta`: `current, previous, absolute, pct, direction`.
- `GoalProgress`: `indicator, target, actualToDate, expectedToDate, pctOfTarget, pctOfExpected, projection, projectionPct, gap, status, daysElapsed, daysRemaining, method (weekday|linear)`.

### 6.3 `IndicatorCalculator` (puro)

```php
final class IndicatorCalculator
{
    /** @param DailyRecordData[] $records */
    public function daily(array $records): array;            // DailyMetrics[]
    public function summarize(array $records, bool $excludeAtypical = false): PeriodSummary;
}
```

Reglas de implementación:
- Divisiones protegidas: denominador 0 → `null` (la vista muestra "—", como el `"-"` del Excel).
- `salesUsd = salesBs / rate` con `RoundingMode::HALF_UP` a 4 decimales internos; presentación según precisión.
- `summarize` con `excludeAtypical` omite `status = atypical` de **ratios y promedios**, pero **no** de sumas (RN-11). Las sumas siempre incluyen todo; el DTO expone ambas versiones para las sumas si se necesita (`sumsAll`, `sumsFiltered`).
- Días `closed` cuentan como día del período con ceros; no entran en ratios (denominador 0).

### 6.4 `WeekdayPattern`

Dado un rango histórico (por defecto las últimas 8–12 **semanas completas** anteriores al período analizado, excluyendo días atípicos y cerrados), calcula el peso relativo de cada día ISO (1–7) sobre la venta USD: `weight[d] = mean(sales_usd de los días d) / Σ_d mean(...)`. Expone `weightFor(date)` y `expectedShare(from, to)`.

### 6.5 `GoalProjector`

Ver §8.

### 6.6 `Formatter` (es-VE)

- `money(BigDecimal|string, Currency, int $precision)`: `Bs 91.154,02` · `$ 614` · signo y espacio configurables.
- `number($n, $precision)`: `3.853` · `1,96`.
- `pct($n)`: `+19,7 %`.
- `date($d, 'short'|'long'|'weekday')`: `01/09/2025` · `lunes 1 de septiembre de 2025` · `lun 01/09`.
- Implementación: `NumberFormatter` (ext-intl) con `es_VE`; fallback `number_format($n, $p, ',', '.')` si intl no está disponible en el hosting.
- Expuesto a Blade como directivas `@money($v, 'USD', 0)`, `@num($v, 1)`, `@pct($v)`, `@fecha($d, 'weekday')` y como helper JS equivalente (`fmt.money`, `fmt.num`) para la vista previa del formulario. La prueba de paridad (§16) compara ambas salidas sobre el fixture.

---

## 7. Motor de indicadores

### 7.1 Catálogo

| Indicador | Clave | Diario | Período | Unidad | Precisión | Meta |
|---|---|---|---|---|---|---|
| Venta Bs | `sales_bs` | dato | Σ | Bs | 2 | Sí (con advertencia) |
| Venta $ | `sales_usd` | `bs / rate` | Σ diarios | USD | 0 tabla / 2 detalle | **Sí (principal)** |
| Transacciones | `transactions` | dato | Σ | # | 0 | Sí |
| Unidades | `units` | dato | Σ | # | 0 | Sí |
| Ticket promedio Bs | `avg_ticket_bs` | `bs / trn` | `Σbs / Σtrn` | Bs | 0 | Sí |
| Ticket promedio $ | `avg_ticket_usd` | `usd / trn` | `Σusd / Σtrn` | USD | 1 | Sí |
| Unidades por compra | `units_per_transaction` | `units / trn` | `Σunits / Σtrn` | ratio | 1 | Sí |
| TRN por jornada | `transactions_per_shift` | `trn / shifts` | `Σtrn / Σshifts` | ratio | 0 | Sí |
| Jornadas | `shifts` | dato | Σ (y promedio) | # | 0 | No |
| Tasa promedio | `avg_rate` | dato | `Σbs / Σusd` (ponderada); la simple (como el Excel) se expone como `avg_rate_simple` para conciliar con reportes antiguos | Bs/USD | 2 | No |
| Inventario unidades | `inventory_units` | dato | promedio (n días) y último | # | 0 | No |
| Valuación inventario | `inventory_value_usd` | dato | promedio (n días) y último | USD | 0 | No |
| Venta por jornada $ | `sales_per_shift_usd` | `usd / shifts` | `Σusd / Σshifts` | USD | 0 | Sí |
| Variación de tasa | `rate_variation_pct` | — | `rate_last / rate_first − 1` | % | 1 | No |

Indicadores condicionados a datos que hoy no existen (se activan si el cliente aporta margen bruto en `settings.gross_margin_pct`): `inventory_turnover`, `inventory_days`. Quedan definidos en el enum pero ocultos hasta configurar el margen.

### 7.2 Variaciones (`DeltaCalculator`)

Para cada indicador del período seleccionado:
- **vs período anterior** (mes anterior completo, o mismos días transcurridos si el mes actual está incompleto — "comparación a fecha equivalente").
- **vs mismo período del año anterior** si existe.
- Para indicadores en Bs se muestra además la variación en USD al lado, con nota "en Bs afectado por tasa (+X %)". Evita la lectura falsa de crecimiento por devaluación.

### 7.3 Cache

`PeriodSummary` se cachea con clave `summary:{branch|all}:{YYYY-MM}:{excl}` y TTL largo; se invalida por observer al guardar/borrar cualquier `daily_records` del mes o al cambiar una tasa usada. Con 30 filas por mes el cálculo es trivial; la cache existe para el consolidado multi-sede y la comparativa anual (12×N meses).

---

## 8. Sistema de metas

### 8.1 Definición

- Entidad `Goal(branch_id|null, indicator, period, target, currency)`.
- Pantalla: cuadrícula **indicador × mes** para el año seleccionado, edición en línea. Copiar mes anterior; copiar del año anterior; incrementar todas +X %.
- **Sugerencia automática** (`SuggestGoal`): promedio de los últimos 3 meses cerrados × (1 + crecimiento configurable). Se muestra como placeholder gris; el usuario acepta o escribe.
- Metas monetarias en USD por defecto (RN-17). Si el usuario elige Bs, la vista muestra advertencia permanente: "Una meta en Bs se cumple sola con la devaluación".

### 8.2 Seguimiento (`GoalProjector`)

Entradas: meta `T`, registros del mes hasta hoy, `WeekdayPattern`, fecha de corte (hoy o último día cargado), flag excluir atípicos.

```
actual        = Σ indicador hasta la fecha de corte (según agregación)
expected      = T × share(días transcurridos)      // share = Σ pesos de los días transcurridos / Σ pesos del mes
pctOfTarget   = actual / T
pctOfExpected = actual / expected                  // "vas al 104 % de lo esperado a la fecha"
projection    = actual + Σ_{d restante} estimate(d)
   estimate(d) = (actual / Σ pesos transcurridos) × weight(d)     // método weekday
   fallback    = actual / díasTranscurridos × díasRestantes         // método lineal (RN-18)
gap           = T − projection
status        = projection/T ≥ 1.00 → on_track ; ≥ 0.90 → at_risk ; else off_track
```

- Para ratios (ticket, unidades/compra): `actual` es el ratio ponderado a la fecha; `projection` = el ratio a la fecha (los ratios no "acumulan"); estado según `actual/T`.
- `daysRemaining` excluye días futuros marcados como cerrados por calendario (feriados configurados) si existen.
- Salida: `GoalProgress` con `method` para mostrar "proyección por patrón semanal" o "proyección lineal (histórico insuficiente)".

### 8.3 Presentación

- Tarjeta KPI: valor, delta vs mes anterior, barra de avance con marca de "esperado a la fecha", texto "Proyección: 94 % · faltan $1.200".
- Colores por estado: verde / ámbar / rojo (paleta §13). Sin meta: gris con enlace "Definir meta".
- Pantalla Metas: tabla del mes con todas las metas y su estado; gráfica acumulado vs meta (§14 G9).

---

## 9. Tasa BCV

### 9.1 Modelo

- `exchange_rates(date unique, rate, source, fetched_at, set_by)`.
- `RateResolver::forDate(date)`: devuelve `RateResolution(rate, source, sourceDate)`: publicada del día → `bcv|manual`; si no existe, la última publicada anterior → `carried` con `sourceDate`; si no hay ninguna → `null` (el formulario exige tasa manual).

### 9.2 Obtención automática

- Interfaz `ExchangeRateProvider { fetch(Carbon $date): ?RateQuote }`.
- `BcvProvider`: consulta una fuente HTTP y parsea el USD oficial. Implementar contra una API comunitaria estable (p. ej. `ve.dolarapi.com/v1/dolares/oficial`) con el scraping directo de `bcv.org.ve` como segunda opción. Timeout 10 s, 2 reintentos, sin bloquear nunca la carga diaria.
- Job `FetchDailyBcvRate` programado a las 08:00 y 17:30 (VET). **El BCV publica en la tarde la tasa que rige el día hábil siguiente**; la corrida de 17:30 la captura y la guarda con `date = fecha de vigencia`, de modo que a la mañana siguiente la carga diaria ya encuentra la tasa del día. La corrida de 08:00 es de respaldo. Los fines de semana no hay publicación: aplica el arrastre (RN-07).
- Validación al guardar automático: si difiere > 10 % de la anterior, se guarda pero se marca para revisión y se notifica al administrador (RN-16).
- `NullProvider` para hosting sin salida a internet: todo manual.

### 9.3 Uso en la carga diaria

- El formulario precarga la tasa con `RateResolver` y muestra insignia: **BCV 25/08** · **Arrastrada del 22/08** · **Manual**.
- El usuario puede sobrescribir. Si lo hace, se guarda en `exchange_rates` como `manual` para esa fecha (si no había publicada) y en el registro como snapshot `manual`.
- El snapshot del registro nunca cambia solo (RN-06). Acción explícita "Recalcular tasas del mes desde la tabla BCV" con confirmación y bitácora.

### 9.4 Pantalla Tasa BCV

- Tabla mensual: fecha, tasa, fuente, variación diaria, quién la fijó. Edición en línea (permiso `rates.manage`).
- Gráfica de la tasa del período (§14 G10 la cruza con venta $).
- Estado del proveedor: última consulta, último éxito, errores recientes.

---

## 10. Importador de histórico

### 10.1 Objetivo

Cargar los archivos `.xlsx` de meses anteriores (uno por mes, estructura de carpetas `MM-MES`), en lote, con vista previa, detección de anomalías y decisión por caso. Ningún dato entra sin confirmación.

### 10.2 `WorkbookParser` — especificación

1. **Hoja**: la primera cuyo nombre normalizado sea `indicadores`; si no, la primera hoja.
2. **Encabezado del reporte**: `B2` → mes (nombre en español, cualquier caja, con o sin tilde, admitiendo la variante `SETIEMBRE`); `C2` → razón social (informativo). **El nombre del archivo no se usa para nada**: el archivo real de muestra se llama `…SEOTIEMBRE…` (error de tipeo), lo que confirma que solo el contenido es fiable. El año se toma de las fechas de la columna B.
3. **Fila de encabezados**: primera fila donde la celda B normalizada == `fecha`. Se validan por **posición** A–N contra las etiquetas esperadas normalizadas (trim, colapso de espacios, sin saltos de línea, sin acentos, minúsculas). Diferencias → advertencia `header_mismatch` (no bloquea si B, C, E, F, G, N coinciden).
4. **Filas de datos**: desde la siguiente fila mientras B sea fecha (`datetime` o serial Excel). Se detiene en la primera B no-fecha (fila de totales).
5. **Columnas leídas** (primarias): B, C, E, F, G, K, L, N. Vacío en K/L → `null`. Vacío en C/E/F/G/N → anomalía.
6. **Columnas ignoradas pero verificadas**: A (se compara con el día real → anomalía `weekday_mismatch`, informativa), D, H, I, J, M (se recalculan; si el valor del archivo difiere > 1 % → anomalía `derived_mismatch`, informativa; detecta fórmulas rotas).
7. **Año**: del valor de fecha. Si el mes de las fechas no coincide con `B2` → anomalía `month_mismatch`.
8. **Salida**: `ParsedMonth(period, branchHint, rows: ParsedRow[], anomalies: Anomaly[])`.

### 10.3 `AnomalyDetector` — catálogo

| Tipo | Severidad | Condición | Acción ofrecida |
|---|---|---|---|
| `missing_day` | alta | fecha del mes sin fila | Registrar como *no cargado* (omitir) / *cerrado* |
| `duplicate_date` | alta | misma fecha dos veces | Elegir cuál / omitir ambas |
| `date_out_of_period` | alta | fecha fuera del mes declarado | Omitir / mover |
| `negative_value` | alta | cualquier primario < 0 | Corregir / omitir día |
| `rate_jump` | media | tasa desvía > 10 % de la fila anterior | Aceptar / corregir |
| `units_lt_transactions` | media | G < F | Aceptar / corregir |
| `sales_deviation` | media | C desvía > 35 % de la mediana del mes | Aceptar / marcar atípico |
| `inventory_missing_on_count_day` | baja | K/L vacíos en día con conteo | Aceptar |
| `weekday_mismatch` | info | letra A ≠ día real | Solo informa |
| `derived_mismatch` | info | D/H/I/J/M ≠ recalculado > 1 % | Solo informa |
| `header_mismatch` | info/alta | etiqueta distinta | Informa; alta si columna primaria |
| `already_imported` | alta | hash o (sede, mes) ya existe | Reemplazar todo / omitir |
| `rate_conflict` | media | ya existe en `exchange_rates` una tasa distinta para esa fecha (p. ej. importada desde otra sede) | Conservar la existente / reemplazar; el snapshot del registro siempre usa la del archivo |

### 10.4 Flujo (`ImportWizard`)

1. Seleccionar sede + subir uno o varios `.xlsx` (drag & drop). Validación de carga: extensión y MIME `xlsx`, tamaño ≤ 5 MB, máximo 24 archivos por lote, el libro se abre en modo solo lectura sin evaluar fórmulas ni macros. Se calcula sha256 y se asigna `group_id`.
2. Job `ProcessImportBatch` parsea cada archivo → `import_batches(status=parsed, parsed_payload, summary)`.
3. **Vista previa** por archivo: mes detectado, 30 filas normalizadas con derivados recalculados, lista de anomalías agrupadas por severidad, contador "N altas deben resolverse".
4. El usuario resuelve anomalías altas (selects en línea) y confirma.
5. `ConfirmImport` inserta en transacción: `upsert` por `(branch, date)`; tasa → `exchange_rates` como `manual` si no existía para la fecha (con `source=manual`, `set_by` = importador); registros con `created_by` = usuario; bitácora con `import_batch_id`.
6. Resultado: resumen, enlace al mes importado. Los meses importados quedan **abiertos** (no cerrados) salvo opción "cerrar al importar".

### 10.5 Idempotencia y reemplazo

- Mismo `file_hash` → se ofrece "ya importado el dd/mm; ¿reemplazar?".
- Mismo `(sede, mes)` con datos → se muestra diff (filas nuevas / cambiadas / iguales) antes de reemplazar.

---

## 11. Exportación

### 11.1 Excel (mismo formato)

`MonthWorkbookExport` genera un libro con:

- **Hoja `Indicadores`**: `B2` mes en mayúsculas, `C2` razón social, fila 3 con los **mismos encabezados** (ya sin espacios finales ni saltos), filas de días con A = abreviatura clara (`lun`…`dom`), B fecha `dd/mm/yyyy`, C–N con valores (los derivados como valores, no fórmulas, para que nadie los rompa) y formatos de número de la tabla 2.2. Fila de totales con los agregados **ponderados**, en negrita, separada por una fila en blanco (no adyacente: evita el problema del autofiltro, H12). Sin autofiltro sobre totales.
- **Hoja `Anual`**: la tabla 12 indicadores × 12 meses en el orden de 2.4, con los meses disponibles llenos y los demás vacíos, más columna "Total/Prom. año".
- **Hoja `Tasas`**: fecha, tasa, fuente.
- Estilo: encabezados con relleno `#1D6FE5` (marca) y texto blanco; Calibri 11; anchos según 2.1.
- Sin gráficos incrustados en v1 (PhpSpreadsheet los soporta de forma limitada); las gráficas van en el PDF.

### 11.2 PDF (reporte mensual)

- Plantilla Blade → DomPDF, A4 horizontal.
- Contenido: portada (sede, mes, generado por/fecha), tarjetas KPI con deltas y metas, cuadro de indicadores, gráficas (imágenes PNG), tabla de metas con estado, observaciones de días atípicos/cerrados.
- Gráficas: el navegador genera PNG con `chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#fff' })` y las envía a `POST /reports/{period}/charts` (ruta firmada, autenticada, límite 10 solicitudes/min por usuario, ≤ 1 MB por imagen y ≤ 12 imágenes, solo `image/png` verificado por cabecera) justo antes de solicitar el PDF; el servidor las guarda temporalmente (storage privado, 10 min) y las inserta.
- Si las imágenes no llegan (navegador antiguo, bloqueo de script), el PDF se genera igualmente con tablas y una nota "gráficas no disponibles"; nunca falla la descarga.
- Envío programado (`SendMonthlyReport`, día configurable, lista de correos): Ampliación A. En esa modalidad las gráficas se generan en servidor con `spatie/browsershot` si el hosting tiene Chrome; si no, el correo lleva el PDF sin gráficas y un enlace al panel.

---

## 12. Casos de uso

Formato: actor · precondiciones · flujo principal · flujos alternos · reglas · UX · criterios de aceptación. Los códigos se usan en pruebas (§16).

### UC-01 Iniciar sesión
- **Actor**: cualquiera. **Pre**: usuario activo.
- **Flujo**: correo + clave → panel. "Recordarme" 30 días. Recuperación por correo.
- **UX**: pantalla con logo, azul de marca, campo de correo con autofoco. Error genérico (no revela si existe el correo).
- **CA**: 5 intentos fallidos → bloqueo 1 min (throttle).

### UC-02 Cargar el día (flujo principal del sistema)
- **Actor**: `operador`, `supervision`, `direccion`. **Pre**: sede asignada; mes no cerrado.
- **Flujo**:
  1. Entra a "Cargar día" (accesible desde cualquier pantalla: botón primario en la barra y en móvil FAB).
  2. La fecha viene precargada con el **primer día no cargado** del mes en curso (o hoy si todo está al día). Junto al campo: `lun 25/08/2025` en texto grande, derivado.
  3. La tasa viene precargada con insignia de origen (BCV · Arrastrada · Manual). Editable.
  4. Jornadas precargadas con el defecto de la sede (3).
  5. Si la fecha es un día sin conteo (sábado), los campos de inventario están **colapsados** con enlace "Registrar inventario de todos modos".
  6. El usuario teclea venta Bs, TRN, unidades (y opcionalmente inventario). Orden de tabulación = orden del Excel. `Enter` en el último campo = guardar.
  7. Panel lateral "Se calculará" muestra en vivo: venta $, ticket Bs, ticket $, unidades/compra, TRN/jornada. Nada de esto es editable. Se calcula en el navegador (Alpine) para respuesta inmediata; el servidor recalcula al guardar (RN-26).
  7b. Bajo cada campo, en gris, la **referencia del último día cargado para ese mismo campo** ("Ayer: 149,46" bajo la tasa; "Ayer: Bs 97.779,71" bajo la venta). Es la defensa más barata contra el error de tipeo: una tasa de 15 al lado de 149,46 salta a la vista. Los campos están agrupados en **Ventas del día**, **Operación** e **Inventario** (§13.5).
  8. Guardar → validación dura (RN-15). Las advertencias (RN-16) ya se mostraron en línea mientras se escribía; si alguna sigue sin revisar, aparece la franja "2 advertencias sin revisar" sobre el botón con **Revisar** y **Guardar de todos modos**. Sin modal.
  9. Guardado → toast "Día guardado" + resumen del día con los cinco valores calculados + botón **Cargar el siguiente (26/08)** y enlace "Ver el mes". El borrador local de esa fecha se elimina.
- **Alternos**:
  - A1: fecha ya cargada → aviso en línea "Este día ya existe" con botón "Editar el registro existente" (UC-03). No se permite duplicar (RN-01).
  - A2: fecha futura → error duro.
  - A3: mes cerrado → campo fecha deshabilitado con mensaje y enlace "Solicitar reapertura" (UC-07).
  - A4: no hay tasa disponible ni arrastre → campo tasa obligatorio, insignia roja "Sin tasa BCV: ingrese manual".
  - A5: el día no operó → botón secundario "Registrar como día cerrado" (UC-05).
  - A6: pérdida de conexión al guardar → Livewire reintenta; el formulario conserva valores; mensaje claro.
- **UX**: inputs numéricos con `inputmode="decimal"`, formateo es-VE al perder foco, teclado numérico en móvil, altura de toque ≥ 44 px, sin scroll para llegar a "Guardar" en portátil 1366×768. Colores de marca solo en acciones primarias.
- **CA**: un usuario entrenado carga un día normal en ≤ 60 s; imposible guardar fecha duplicada; el día de la semana mostrado siempre coincide con la fecha; los derivados coinciden con el motor (mismas funciones).

### UC-03 Editar un día
- **Pre**: permiso `records.update`; mes abierto.
- **Flujo**: desde la tabla del mes o el calendario → mismo formulario con valores; al guardar, bitácora con old/new. Se muestra "Última edición: usuario, fecha".
- **Alternos**: mes cerrado → solo lectura con enlace a reapertura. **Edición concurrente**: el formulario lleva el `updated_at` leído; si al guardar difiere, no sobrescribe y muestra "Ana editó este día hace 2 minutos" con "Ver sus cambios" y "Sobrescribir" (bloqueo optimista).
- **CA**: la bitácora registra cada campo cambiado; la edición concurrente nunca pierde datos en silencio.

### UC-04 Marcar día atípico
- **Flujo**: en formulario o tabla → conmutador "Día atípico" + motivo obligatorio (≥ 10 caracteres) → guarda `status=atypical`.
- **Efecto**: badge en tabla y gráficas (punto con contorno), excluido de proyección por defecto; conmutador global "Incluir días atípicos en promedios" en la barra de contexto.

### UC-05 Registrar día cerrado
- **Flujo**: "Registrar como día cerrado" → motivo obligatorio → guarda ceros, `shifts=0`, `status=closed`.
- **Efecto**: cuenta como día del mes cargado (no "faltante"); no entra en ratios ni en el patrón semanal.

### UC-06 Ver días faltantes del mes
- **Flujo**: pantalla "Mes" con vista **calendario**: cada día con color (cargado / faltante / atípico / cerrado / futuro). Clic en faltante → UC-02 con esa fecha. Contador "Faltan 3 días: 12, 18, 19".
- **CA**: los sábados sin inventario **no** aparecen como incompletos.

### UC-07 Cerrar y reabrir mes
- **Cerrar** (`periods.close`): requiere 0 días faltantes (o confirmación explícita listándolos). Inserta `period_events(action=closed)`. Efecto: registros de solo lectura; el mes aparece con candado.
- **Reabrir** (`periods.reopen`): motivo obligatorio; bitácora; notificación a `direccion`.

### UC-08 Ver panel principal
- **Contexto**: barra superior con Sede (oculta si solo hay una), Período (mes por defecto: actual; rango personalizado), Moneda (Bs / $ / ambas).
- **Contenido**, en este orden y sin nada más (§13.7): (1) **héroe del mes** con la frase de estado y la proyección sobre la gráfica acumulado vs meta; (2) cuatro tarjetas KPI primarias (venta $, transacciones, ticket $, unidades por compra) con delta, sparkline y barra de meta; (3) fila secundaria plegada (venta Bs, unidades, transacciones por jornada, inventario); (4) venta $ diaria y mapa de calor semanal; (5) avisos del mes (días faltantes, tasa arrastrada, mes anterior sin cerrar).
- **Alternos**: sin datos en el período → estado vacío con CTA "Cargar el primer día". Sin metas → tarjetas sin barra y enlace "Definir metas".
- **CA**: carga < 1 s con cache caliente; todas las cifras salen del `PeriodSummary` (una sola fuente).

### UC-09 Ver cuadro de indicadores (tabla del mes)
- Tabla con las 14 columnas del Excel + estado, en el orden original, con el día de la semana correcto, formatos de 2.2, fila de totales **fija al pie** (sticky) y calculada aparte. Ordenar por columna; buscar por fecha; conmutador Bs/$; exportar (UC-14). Filas atípicas/cerradas con badge. Clic en fila → editar.
- **CA**: los totales de la fila fija coinciden con los del panel.

### UC-10 Ver gráficas
- Pestañas por familia (Ventas · Operación · Inventario · Tasa · Año) con los filtros de la barra de contexto; abre en Ventas; máximo dos gráficas por fila; cada gráfica sin marco, con menú discreto: ampliar, descargar PNG, ver datos (§13.5, §14).

### UC-11 Definir metas
- **Pre**: permiso `goals.manage`.
- **Flujo**: pantalla Metas abre en **Este mes**: una fila por indicador con la meta editable en línea, el placeholder con la sugerencia, actual, esperado hoy, proyección y estado. La vista **Año** muestra la cuadrícula indicador × mes con navegación por teclado, pegado desde Excel y acciones "Copiar mes anterior", "Copiar año anterior", "+X % a todo el año"; guardar en lote.
- **Alternos**: elegir Bs en meta monetaria → advertencia visible.

### UC-12 Seguir metas
- En panel (tarjetas) y en pantalla Metas: tabla del mes con indicador, meta, actual, esperado a la fecha, proyección, brecha, estado; texto explicativo del método de proyección.
- **CA**: al día 20 con 68 % de avance y ritmo estable, la proyección lineal da ≈ 102 %; con patrón semanal, depende de los pesos (probado con fixture).

### UC-13 Comparativa anual
- Tabla 12 indicadores × 12 meses (+ total/promedio año), año seleccionable, Bs/$; variación mes a mes y contra año anterior al pasar el cursor; celdas de meses sin datos en gris; exportable.

### UC-14 Exportar
- Excel del mes (UC-09) y PDF del mes (panel). Descarga inmediata; para PDF, el navegador envía las imágenes de gráficas primero (transparente para el usuario, con indicador "Generando…").

### UC-15 Importar histórico
- Flujo §10.4. Solo `imports.run`. Progreso por archivo; anomalías con acciones; confirmación; resumen.

### UC-16 Gestionar tasa BCV
- Pantalla §9.4. Permiso `rates.manage`. Editar/crear tasa de una fecha; ver origen; forzar consulta al proveedor; "Recalcular snapshots del mes" con confirmación.

### UC-17 Administración
- Sedes (crear, editar, días de inventario, jornadas, umbral), usuarios (rol, sedes asignadas, activar/desactivar), parámetros globales, ver bitácora con filtros.

### UC-18 Consolidado multi-sede (Ampliación B)
- Con ≥ 2 sedes activas aparece el selector con opción "Todas (consolidado)". El consolidado suma sedes; los ratios se ponderan sobre el total; las metas de consolidado son propias (`branch_id null`). Comparación sede vs sede en gráficas de barras agrupadas.

---

## 13. UI y UX

### 13.0 Dirección de diseño

**Sujeto**: una farmacia de barrio venezolana que cada día anota siete números y cada mes quiere saber una sola cosa: *¿vamos a cumplir?* **Audiencia**: la persona que carga (rápido, a veces desde el teléfono, con el local abierto) y la dirección que consulta (quiere la respuesta antes que los datos). **Trabajo principal de la interfaz**: que cargar un día no sea una tarea y que la respuesta a "¿cómo va el mes?" esté en la primera pantalla sin buscarla.

De ahí las tres decisiones que distinguen este sistema de un panel genérico:

1. **El héroe es una frase, no una fila de tarjetas.** El panel abre con el estado del mes escrito en lenguaje llano ("Septiembre va al 68 % de la meta; al ritmo actual cierra en 94 %") sobre la gráfica de acumulado contra meta. Las tarjetas de KPI van después, en silencio.
2. **Los números son el protagonista tipográfico.** Una sola familia con cifras tabulares excelentes, tamaños generosos para valores y pequeños para etiquetas, sin mayúsculas decorativas ni etiquetas superfluas.
3. **Dos colores de marca, dos significados.** La farmacia usa azul y detalles en morado; el sistema los reparte con una regla que el usuario aprende sin que se la expliquen: **azul = datos y acciones** (ventas, botones, navegación activa, serie principal), **morado = metas y proyección** (línea de meta, cifra de proyección, estados de cumplimiento, la vista Metas). Todo lo demás es tinta, línea y blanco. Sin sombras decorativas, sin degradados, sin tarjetas idénticas.

Modo de la superficie: **Operate**. La herramienta debe desaparecer dentro de la tarea; la marca vive en detalles precisos, no en decoración. El único momento "comprometido" es el héroe del panel.

### 13.1 Paleta (derivada de la identidad de Farmacia Guadalupe)

Derivada de la portada y el logotipo: azul vivo de fondo, azul marino del texto "Guadalupe", violeta del pie de la cruz, blanco. Los códigos son una aproximación fiel; si existe manual de marca, se sustituyen sin tocar el resto del sistema (todo va en tokens CSS).

| Token | Hex | Uso |
|---|---|---|
| `brand-800` | `#0F3F8F` | Texto de marca, títulos de pantalla, tinta principal sobre fondos claros |
| `panel` | `#EEF3F9` | Segunda capa neutra, ligeramente fría: barra lateral, barra superior, cabeceras de tabla. Distingue el marco del contenido sin cargar color |
| `brand-700` | `#1558C2` | Hover de acciones primarias |
| `brand-600` | `#1D6FE5` | **Primario**: botones, enlaces, navegación activa, serie 1 de gráficas |
| `brand-500` | `#3D8AF0` | Foco visible, estados activos suaves |
| `brand-100` | `#E4EEFC` | Fondo del héroe del panel, fila seleccionada |
| `brand-50` | `#F2F7FE` | Fondo de página |
| `accent-600` | `#6C4FD8` | **Morado de la marca = metas**: línea de meta, cifra de proyección en el héroe, marca de "esperado hoy" en las barras de meta, encabezado de la vista Metas, serie "meta" en gráficas. También el badge "atípico". Nunca para botones |
| `accent-100` | `#EDE8FB` | Fondo de los badges de meta y "atípico"; fila de meta en tablas |
| `ink-900` | `#14232F` | Texto principal |
| `ink-600` | `#4B5B67` | Texto secundario, etiquetas |
| `ink-400` | `#8A98A3` | Texto terciario, ejes de gráficas |
| `line` | `#DDE4EC` | Bordes, separadores, hairlines de tabla |
| `surface` | `#FFFFFF` | Superficie de contenido |
| `success-600` | `#1E8E5A` | Meta cumplida / en camino, deltas positivos |
| `warning-600` | `#D98A0B` | En riesgo, advertencias blandas |
| `danger-600` | `#D23F3F` | Fuera de meta, errores duros, deltas negativos |
| `success-100` / `warning-100` / `danger-100` | `#E4F5EC` / `#FCF1DE` / `#FBE6E6` | Fondos de estado |

**Series de gráficas (orden fijo)**: `brand-600` → `accent-600` → `#0FA3A3` (teal) → `warning-600` → `ink-400`. Meta: `brand-800` discontinua. Tasa: `ink-600`. Días atípicos: marcador con borde `danger-600`. Los deltas usan verde/rojo solo cuando "más es mejor"; la tasa de cambio no se colorea.

Contraste verificado: `brand-600` sobre blanco 4,7:1; `ink-600` sobre blanco 7,4:1; texto blanco sobre `brand-600` 4,7:1. Todo texto cumple AA; los estados nunca dependen solo del color (llevan icono o texto).

### 13.2 Tipografía y formato

- **Familia única: IBM Plex Sans** (self-hosted, pesos 400 / 500 / 600). Elegida por sus cifras tabulares limpias, su buena cobertura de diacríticos en español y un carácter técnico sin frialdad, distinto del sans por defecto de cualquier plantilla. `font-feature-settings: "tnum"` en todo número.
- Escala (px): 13 etiquetas · 15 cuerpo · 17 subtítulos · 22 títulos de pantalla · 32 valores KPI · 44 valor del héroe. Pesos: 400 texto, 500 etiquetas y encabezados de tabla, 600 títulos y valores.
- **Sentence case en todo**: etiquetas, botones, títulos, encabezados de tabla. Sin versalitas ni mayúsculas decorativas.
- Prosa a ≤ 75 caracteres por línea (ayudas, estados vacíos, modales).
- Números es-VE (RN-20): `Bs 91.154,02` · `$ 614` · `+19,7 %`. Fechas: en tablas `lun 01/09`; en títulos `Septiembre 2025`; en formularios `01/09/2025`.

### 13.3 Layout y navegación

```
┌────────────────────────────────────────────────────────────────────┐
│ ✚ Guadalupe │ Septiembre 2025 ▾ │ Sede Principal ▾ │ Bs $ │  Cargar día  │ ● │
├────────┬───────────────────────────────────────────────────────────┤
│ Panel  │  Septiembre 2025                            Sede Principal │
│ Cargar │                                                           │
│ Mes    │  ┌───────────────────────────────────────────────────────┐│
│ Gráficas│ │ Septiembre va al 68 % de la meta.                     ││
│ Metas  │  │ Al ritmo actual cierra en 94 %: faltan $ 1.200.       ││
│ Anual  │  │ [gráfica acumulado vs meta, ancho completo]           ││
│ Tasa   │  └───────────────────────────────────────────────────────┘│
│ Importar│                                                          │
│ Admin  │  Venta $      Transacciones   Ticket $     Unid./compra   │
│        │  $ 12.640     2.610           $ 4,84       1,96           │
│        │  +6,1 %       +3,2 %          −0,4 %       +1,1 %         │
└────────┴───────────────────────────────────────────────────────────┘
```

- **Barra superior**: logo (la cruz) + período + sede (oculta si hay una) + conmutador de moneda + **Cargar día** como único botón primario. Persiste en sesión.
- **Título de pantalla**: el nombre del período como título (`Septiembre 2025`) y la sede como subtítulo alineado a la derecha. Sin migas encadenadas con puntos.
- **Barra lateral** sobre fondo `panel`, texto `brand-800`, entrada activa con barra izquierda `brand-600` y texto `brand-600`. Ocho entradas agrupadas en tres bloques con un título de grupo en 13 px `ink-400` (sentence case), para no superar cinco opciones visibles por nivel: **Día a día** (Panel, Cargar día, Mes) · **Análisis** (Gráficas, Metas, Año) · **Configuración** (Tasa BCV, Importar, Administración). Colapsable a iconos con etiqueta al pasar el cursor. Alineación a la izquierda en todo el contenido; nada centrado salvo estados vacíos.
- **Móvil (< 768 px)**: barra inferior con Panel · Cargar · Mes · Más; período y sede en una hoja desplegable desde el título; "Cargar día" es un botón fijo inferior, no un FAB flotante que tape datos.
- **Anchos**: contenido máximo 1280 px; tablas pueden extenderse al ancho completo con scroll interno.

### 13.4 Sistema visual

| Elemento | Regla |
|---|---|
| Superficies | Página `brand-50`; contenido en `surface` blanco delimitado por **hairlines** `line`, no por sombras. Sombra únicamente en modales, menús y toasts (una sola: `0 8px 24px rgba(20,35,47,.12)`) |
| Radios | 6 px en controles, 8 px en tarjetas KPI y tablas, 12 px en el héroe y modales. Tres radios, no uno para todo |
| Espaciado | Cuadrícula de 4 px. Ritmo vertical entre bloques: 24 px; dentro de tarjetas: 16 px; entre campos: 20 px |
| Iconos | Lucide, trazo 1,75 px, 18 px en texto y 20 px en navegación. Nunca icono solo sin etiqueta o `aria-label` |
| Jerarquía de tarjetas | El **héroe** (fondo `brand-100`, radio 12, texto grande) es el único bloque destacado. Las tarjetas KPI son blancas y silenciosas. Las gráficas **no llevan marco**: título, subtítulo y gráfica directamente sobre la superficie, separadas por una hairline |
| Estados de datos | Badge = icono + texto (`⚠ Atípico`, `⏻ Cerrado`, `✓ Cerrado el 05/10`), nunca solo color |
| Estados de componentes | Todo control interactivo define **default, hover, focus, active, disabled, loading y error** antes de considerarse terminado. Mismo botón, mismo campo y mismo icono en todas las pantallas: si "Guardar" se ve distinto en dos sitios, uno está mal |
| Capas superpuestas | Menús, selectores de fecha y tooltips se montan fuera de contenedores con `overflow` (portal / `position: fixed`) para que la tabla con scroll no los recorte |
| Motion | Solo movimiento que comunica estado, 150–250 ms: al guardar un día, los cinco valores calculados se fijan y la celda del calendario se rellena; despliegue de grupos y paneles; carga con skeleton. Sin secuencias de entrada al cargar la página, sin hover animado en tarjetas. `prefers-reduced-motion` respetado |
| Modo oscuro | No en v1. Los tokens lo permiten después |
| Marca | Cruz del logotipo como icono de la barra y favicon; wordmark solo en login y PDF. La cruz de marca (`<x-brand-mark>`) lleva los brazos en blanco o `brand-600` y el **pie en violeta**, como el logotipo; el wordmark (`<x-brand-wordmark>`) pone "Farmacia" en 12 px sobre "Guadalupe" en 27–32 px, sin versalitas |

### 13.5 Componentes

| Componente | Especificación |
|---|---|
| **Héroe del mes** (panel) | Fondo `brand-100`, radio 12. Línea 1 (44 px, 600): "Septiembre va al 68 % de la meta". Línea 2 (17 px, `ink-600`): "Al ritmo actual cierra en 94 %: faltan $ 1.200. Quedan 2 miércoles, tus días más fuertes." Debajo, G9 a ancho completo. Sin meta: "Septiembre lleva $ 12.640 vendidos. Define una meta para ver la proyección." con botón secundario |
| **Tarjeta KPI** | Blanca, hairline, radio 8, 20 px de padding. Etiqueta 13 px `ink-600` en sentence case ("Venta en dólares"); valor 32 px tabular; delta 13 px con flecha y color; **sparkline** de 14 días en `ink-400` (60 × 20 px, **SVG en línea generado en Blade, sin librería**) a la derecha del valor; barra de meta de 4 px con marca de "esperado hoy". 4 tarjetas primarias por fila; fila secundaria plegada ("Ver 4 indicadores más") |
| **Tabla del mes** | Dos filas de encabezado: grupos (Fecha · Ventas · Operación · Promedios · Inventario) y columnas. Columna de fecha fija a la izquierda; fila de totales fija al pie con etiqueta "Total del mes (ponderado)". Filas 40 px, cebra `brand-50` cada 2, hairlines, números a la derecha, unidades en el encabezado no en las celdas. En < 1280 px se ocultan las 5 columnas calculadas tras "Mostrar cálculos". Clic en fila = editar; badge de estado junto a la fecha |
| **Formulario del día** | Tres grupos con título: **Ventas del día** (venta en Bs, tasa BCV), **Operación** (transacciones, unidades vendidas, jornadas), **Inventario** (unidades en inventario, valuación en $; plegado en días sin conteo). Etiquetas con la unidad dentro: "Venta del día (Bs)", "Tasa BCV (Bs por $)", "Jornadas (turnos)". Bajo cada campo, en 13 px `ink-400`, la referencia del último día cargado: "Ayer: 149,46". Panel derecho "Se calculará" fijo, con los cinco derivados en 22 px tabulares. Borrador guardado en el navegador por fecha (si se cierra la pestaña, se recupera). Dos columnas en ≥ 1024 px; una en móvil con el panel calculado al final |
| **Input numérico** | `inputmode=decimal`; acepta `,` y `.`; formatea es-VE al perder foco; selecciona todo al enfocar; sin spinners; unidad como sufijo visual dentro del campo cuando ayuda ("Bs", "$") |
| **Insignia de tasa** | Píldora junto al campo: `brand-100` "BCV 25/08", `ink-100` "Arrastrada del viernes 22/08", `warning-100` "Manual". Tooltip: "El BCV no publica fines de semana; se usa la última tasa" |
| **Advertencias en línea** | Aparecen bajo el campo mientras se escribe, en ámbar, con la comparación concreta: "Es 90 % menor que ayer (149,46)". **Sin modal**: al pulsar Guardar con advertencias, sobre el botón aparece una franja ámbar "2 advertencias sin revisar" con dos acciones, **Revisar** (lleva al primer campo) y **Guardar de todos modos**; el foco pasa a la franja. Los guardados con advertencia quedan marcados para revisión del supervisor |
| **Calendario del mes** | 7 columnas lun→dom, semanas etiquetadas "1–7", "8–14"…; celda: número del día + estado; en ≥ 1024 px además la venta en $ corta. Colores: cargado `brand-100`, faltante `warning-100` con borde punteado, atípico `accent-100`, cerrado `ink-100`, futuro atenuado. Tap en faltante abre el formulario con esa fecha |
| **Gráfica** | Sin marco. Título 17 px, subtítulo con período en `ink-600`, menú discreto (ampliar, PNG, ver datos). Leyenda clicable; tooltip con "mié 24/09 · $ 854 · 161 transacciones". Altura 280 px en escritorio, 220 en móvil |
| **Metas** | Vista por defecto **Este mes**: lista de filas (indicador, meta editable en línea, actual, esperado hoy, proyección, estado con icono). Vista **Año**: cuadrícula indicador × mes con navegación por teclado y pegado desde Excel |
| **Asistente de importación** | Pasos: Archivos → Revisión → Confirmación. En Revisión, anomalías agrupadas por severidad con contador y filas de la vista previa con la celda afectada resaltada; "Confirmar" deshabilitado hasta resolver las altas, con texto "Faltan 2 anomalías por resolver" |
| **Modal** | Solo para cerrar/reabrir mes, borrar día y confirmar importación. Radio 12, sombra única, título en sentence case, botón primario a la derecha con el mismo verbo de la acción, `Esc` cierra. Todo lo demás se resuelve en línea |
| **Ayuda contextual** | Cada tarjeta KPI y cada columna calculada tiene un enlace "¿Cómo se calcula?" que abre un popover con la fórmula en palabras ("Venta del mes en Bs dividida entre las transacciones del mes") y el valor de ayer. Cada pantalla tiene un botón "?" en la barra que abre un panel lateral con la ayuda de esa pantalla y el glosario de siete términos. La ayuda nunca saca al usuario de donde está |
| **Toast** | Inferior derecha, 4 s, mismo verbo de la acción en pasado ("Día guardado", "Mes cerrado"); "Deshacer" 10 s donde aplica |
| **Estado vacío** | Cruz de la marca en `brand-100` a 48 px, una frase que dice qué falta y un botón que lo resuelve: "Aún no hay días cargados en septiembre." → **Cargar el primer día** |

### 13.6 Microcopy

Reglas: sentence case, voz activa, tuteo, sin disculpas ni relleno. El verbo del botón se repite en la confirmación.

| Momento | Texto |
|---|---|
| Botón primario del formulario | **Guardar día** |
| Confirmación | Día guardado. `Cargar el siguiente (26/08)` · `Ver el mes` |
| Duplicado | Este día ya está cargado. `Editar el registro` |
| Fecha futura | No se puede cargar un día que no ha ocurrido. |
| Sin tasa | No hay tasa BCV para esta fecha. Escríbela para continuar. |
| Advertencia tasa | Es 90 % menor que ayer (149,46). Revísala. |
| Advertencia venta | Es 52 % menor que el promedio de los últimos 14 días (Bs 98.400). |
| Unidades < transacciones | Hay menos unidades (120) que transacciones (138). |
| Inventario ausente en día con conteo | Hoy toca conteo de inventario y está vacío. Puedes guardar igual. |
| Mes cerrado | Septiembre está cerrado desde el 05/10. `Pedir reapertura` |
| Cerrar mes | **Cerrar septiembre** → "Septiembre cerrado. Nadie podrá editarlo sin reabrirlo." |
| Reabrir | Motivo de la reapertura (obligatorio) → "Septiembre reabierto." |
| Día atípico | Motivo (por ejemplo: corte de luz, media jornada) → "Día marcado como atípico. No se usará en la proyección." |
| Meta sin definir | Define una meta para ver la proyección. `Definir meta` |
| Panel sin datos | Aún no hay días cargados en septiembre. `Cargar el primer día` |
| Importación | "Faltan 2 anomalías por resolver" → **Importar 30 días** → "Septiembre 2025 importado." |
| Error de red | No se pudo guardar. Tus datos siguen aquí; intenta de nuevo. |

Términos fijos en toda la interfaz: **día** (registro diario), **mes**, **meta**, **tasa BCV**, **transacciones** (no "TRN" salvo en la tabla compacta, donde el encabezado lleva tooltip), **unidades**, **jornadas**. Nunca "registro", "período" ni "ítem" de cara al usuario.

### 13.7 Jerarquía de las pantallas clave

**Panel**: (1) héroe del mes → (2) 4 KPI primarios [venta $, transacciones, ticket $, unidades por compra] → (3) fila secundaria plegada [venta Bs, unidades, transacciones por jornada, inventario] → (4) dos gráficas: venta $ diaria (G2) y mapa de calor (G8) → (5) avisos del mes (faltan 3 días; tasa de hoy arrastrada; mes anterior sin cerrar). Nada más.

**Cargar día**: (1) fecha con el día de la semana en 22 px → (2) los tres grupos de campos → (3) panel "Se calculará" → (4) **Guardar día** siempre visible (fijo al pie en móvil).

**Mes**: (1) calendario → (2) contador de faltantes con acción → (3) tabla completa debajo (o pestaña en móvil).

**Gráficas**: pestañas por familia: **Ventas** (G2, G1, G8, G9) · **Operación** (G3, G6, G4, G5) · **Inventario** (G7) · **Tasa** (G10) · **Año** (G11). Abre en Ventas. Máximo dos gráficas por fila.

**Metas**: (1) vista "Este mes" → (2) vista "Año".

### 13.8 Casos de UX transversales

- **Errores duros** en línea bajo el campo, sin modal; el foco salta al primero.
- **Advertencias blandas** nunca bloquean; aparecen mientras se escribe y exigen un clic consciente al guardar.
- **Borrador automático** del formulario en el navegador, por fecha: una pestaña cerrada o una sesión vencida no pierde lo escrito.
- **Deshacer** en borrado de día (10 s) y en marcado atípico.
- **Carga de días atrasados**: "Cargar los 3 faltantes" abre el formulario en secuencia con siguiente/anterior.
- **Persistencia de contexto**: período, sede y moneda por usuario; el último usado se guarda en el perfil.
- **Inicio por rol**: `operador` aterriza en Mes; `supervision` y `direccion` en Panel.
- **Recordatorio de cierre** (Ampliación A): el día 1, banner a `supervision` si el mes anterior tiene faltantes o no está cerrado.
- **Moneda**: en tablas, "Bs y $" muestra ambas columnas; en KPI, la elegida en grande y la otra debajo en 13 px.
- **Teclado**: `Alt+N` cargar día, `Enter` guarda, `Esc` cierra, flechas en calendario y cuadrícula de metas.
- **Accesibilidad**: labels asociados, `aria-live` en el panel calculado y toasts, foco visible `brand-500` de 2 px, AA, toque ≥ 44 px, estados con icono + texto.
- **Rendimiento percibido**: skeletons en KPI y gráficas; `wire:loading` en botones; sin recargas completas.
- **Móvil**: la carga diaria se prueba a 360 px con teclado numérico; el botón Guardar es fijo al pie.
- **Impresión**: hoja de estilos para Mes y Panel.

---

### 13.9 Pantalla de acceso (UC-01)

El acceso es la **única pantalla comprometida con la marca**: fuera de la aplicación, sin datos que estorbar y con el wordmark ya autorizado aquí (§13.4). Todo lo demás del sistema sigue en modo *Operate*.

- **Composición**: dos columnas. Izquierda (≥ 1024 px, 46 % hasta 640 px) el **panel de marca**; derecha el formulario sobre blanco, ancho de lectura 400 px. En < 1024 px el panel se convierte en una **banda superior de ~190 px** para que el campo de correo y el botón queden sobre la línea de flotación en un teléfono de 390 × 844.
- **Panel de marca**: cruz + wordmark arriba; regla de 48 × 3 px en el violeta del pie de la cruz; una frase de 38 px ("Los números del mes, claros desde el primer día."), su bajada y tres líneas de lo que hace el sistema; al pie, la razón social. La textura son **cruces de la marca** en retícula girada 14°, al 6 % y desvanecidas con una máscara, más **una cruz sobredimensionada trazada a hairline** que sale por el borde inferior derecho. Sin fotos, sin iconos de terceros, sin brillos.
- **Único degradado del sistema**: el fondo del panel se mueve entre dos tonos del mismo azul (`#14509F` → `brand-800` → `brand-900`), de modo que lee como profundidad, no como color. `brand-900` (`#0A2C66`) existe solo para esto.
- **Formulario**: título de 22 px, campos de 44 px con foco de 2 px, **mostrar/ocultar contraseña** con etiqueta accesible que cambia de estado, "Recordarme" y recuperación en la misma línea, botón primario a ancho completo con estado de carga ("Entrando…"). Errores en línea bajo el campo (§13.8), nunca en modal, y mensaje genérico que no revela si el correo existe.
- **Movimiento**: una sola entrada de 240 ms (opacidad + 8 px) en la columna del formulario, anulada por `prefers-reduced-motion`. Nada más se anima.
- El resto de pantallas de invitado (recuperar, nueva contraseña, confirmar, verificar) heredan el mismo marco y el mismo título de 22 px.


## 14. Gráficas

Todas con eje X de fechas reales (día de semana + día), tooltip completo, huecos (`null`) en días sin dato, marcadores especiales en atípicos, y respeto a la moneda de la barra de contexto cuando aplica. Se presentan **sin marco**, agrupadas en pestañas por familia (§13.7), con la serie de meta siempre en morado `accent-600` y la serie principal en azul `brand-600`.

| # | Gráfica | Tipo | Series | Eje | Origen |
|---|---|---|---|---|---|
| G1 | Venta en Bs por día | Barras | venta Bs | 1 | Excel (corregida: sin serie de ceros) |
| G2 | Venta en $ por día | Barras + línea | venta $; línea de meta diaria esperada | 1 | Excel (corregida: título, eje de fechas) + meta |
| G3 | Transacciones y unidades | Barras + línea | TRN (barras); unidades (línea) | **2** | Excel (corregida: eje secundario) |
| G4 | Ticket promedio Bs y unidades por compra | Barras + línea | ticket Bs (barras); unidades/compra (línea) | **2** | Excel (corregida: hoy la línea es invisible) |
| G5 | Unidades por compra y ticket $ | Líneas | ambas | 2 | Excel |
| G6 | Transacciones por jornada | Línea con marcadores | TRN/jornada | 1 | Excel (corregida: sin encabezado ni totales) |
| G7 | Inventario: unidades y valuación $ | Barras + línea | unidades (barras); valuación (línea) | 2 | Excel (corregida: huecos en sábados, no ceros) |
| G8 | **Mapa de calor semanal** | Heatmap | filas = semana del mes, columnas = lun…dom, valor = venta $ | — | Nueva |
| G9 | **Acumulado vs meta** | Área + líneas | acumulado real; línea de meta; línea de "esperado a la fecha"; proyección punteada | 1 | Nueva |
| G10 | **Tasa BCV vs venta $** | Línea + barras | tasa (línea, eje 2); venta $ (barras) | 2 | Nueva |
| G11 | **Comparativa interanual** | Barras agrupadas | indicador por mes, año actual vs anterior | 1 | Nueva |

Detalles:
- G2/G9 solo muestran meta si existe; si no, leyenda "Sin meta definida".
- G3/G4/G7: eje secundario etiquetado con la unidad; colores serie 1/serie 2 de la paleta.
- G8: escala secuencial de `brand-50` a `brand-800`; celdas de días faltantes en blanco con borde punteado; tooltip con fecha y valor.
- G11: selector de indicador; cuando no hay año anterior, muestra solo el actual con nota.
- Todas exportan PNG (`getDataURL`, 2×) y se reutilizan en el PDF.

### 14.1 Implementación con ECharts

**Por qué ECharts y no ApexCharts o Chart.js.** Las once gráficas piden tres cosas que ECharts resuelve de forma nativa y las otras librerías con plugins o simulaciones: el mapa de calor en forma de **calendario** (coordenada `calendar`), las **anotaciones de meta y de días atípicos** (`markLine`, `markPoint`, `markArea`) y un **objeto de configuración completamente declarativo** que PHP construye y Livewire entrega como JSON. Licencia Apache 2.0, renderer SVG para PNG nítidos en el PDF, módulo de accesibilidad con patrones `decal`. Highcharts se descartó por licencia comercial; D3 por horas; Chart.js por necesitar tres plugins para lo mismo.

**Módulos importados** (tree-shaking; ~110 KB gz en total):

```js
// resources/js/charts/echarts.js
import * as echarts from 'echarts/core';
import { BarChart, LineChart, HeatmapChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, DataZoomComponent,
         MarkLineComponent, MarkPointComponent, MarkAreaComponent,
         CalendarComponent, VisualMapComponent, AriaComponent } from 'echarts/components';
import { SVGRenderer } from 'echarts/renderers';
echarts.use([BarChart, LineChart, HeatmapChart, GridComponent, TooltipComponent, LegendComponent,
             DataZoomComponent, MarkLineComponent, MarkPointComponent, MarkAreaComponent,
             CalendarComponent, VisualMapComponent, AriaComponent, SVGRenderer]);
export default echarts;
```

**Tema `guadalupe`** (`resources/js/charts/theme.js`, registrado una vez con `echarts.registerTheme`): serie principal `#1D6FE5`, meta `#6C4FD8`, paleta de series según §13.1, texto de ejes `#8A98A3`, líneas de cuadrícula `#DDE4EC` discontinuas suaves, fuente IBM Plex Sans, `animationDuration: 200`, tooltip blanco con hairline y la única sombra del sistema, sin `title` interno (el título vive en HTML).

**Integración con Livewire** (componente `ChartPanel`):

```
ChartSeriesQuery (PHP) → array $option (ya con formato es-VE resuelto donde es texto)
  → propiedad pública del componente Livewire
  → Alpine x-init: chart = echarts.init($el, 'guadalupe', { renderer: 'svg' })
  → $watch('option', o => chart.setOption(o, { notMerge: true }))
  → ResizeObserver → chart.resize()
  → formatters numéricos: mismo helper JS `fmt.money` / `fmt.num` de §6.6 (paridad con PHP)
```

Cambiar período, sede o moneda no toca JavaScript: cambia el JSON.

**Correspondencia por gráfica**:

| Gráfica | Construcción en ECharts |
|---|---|
| G1, G2 | `bar` sobre eje `category` de fechas; G2 añade `markLine` con la meta diaria esperada (`accent-600`, discontinua) |
| G3, G4, G5, G7, G10 | Dos `yAxis`; serie secundaria con `yAxisIndex: 1`; unidad en `axisLabel.formatter` |
| G6 | `line` con `symbol: 'circle'`, `connectNulls: false` |
| G7 | `null` en sábados → hueco real; `markArea` gris en días cerrados |
| G8 | `heatmap` sobre `calendar` del mes (`range: '2025-09'`, `dayLabel` lun→dom, `cellSize` auto); `visualMap` continuo de `brand-50` a `brand-800`; días faltantes con `itemStyle` punteado |
| G9 | `line` acumulado con `areaStyle` suave; `markLine` meta (morado); `markLine` "esperado hoy"; serie proyección con `lineStyle.type: 'dashed'` |
| G11 | `bar` agrupadas (`barGap`), año actual `brand-600`, anterior `ink-400` |
| Días atípicos (todas) | `markPoint` con `symbol: 'circle'`, borde `danger-600`, etiqueta con el motivo en tooltip |

**Reglas comunes**: `tooltip.trigger: 'axis'` con `axisPointer` de línea; `tooltip.formatter` construye "mié 24/09 · $ 854 · 161 transacciones"; `aria.enabled: true` con `decal` para daltonismo; `grid` con `containLabel: true`; altura 280 px escritorio / 220 px móvil; `dataZoom` solo en rangos personalizados > 45 días.

**Sparklines**: fuera de ECharts. SVG en línea de 60 × 20 px generado en Blade (`<polyline>` normalizado), sin JavaScript.

---

## 15. Seguridad y roles

### 15.1 Roles y permisos (spatie)

| Permiso | operador | supervision | direccion | admin |
|---|---|---|---|---|
| `records.view` | ✓ | ✓ | ✓ | ✓ |
| `records.create` | ✓ | ✓ | ✓ | ✓ |
| `records.update` | ✓ (mismo día y 7 días atrás) | ✓ | ✓ | ✓ |
| `records.delete` | — | ✓ | ✓ | ✓ |
| `records.mark_atypical` | ✓ | ✓ | ✓ | ✓ |
| `periods.close` | — | ✓ | ✓ | ✓ |
| `periods.reopen` | — | — | ✓ | ✓ |
| `goals.view` | — | ✓ | ✓ | ✓ |
| `goals.manage` | — | — | ✓ | ✓ |
| `rates.manage` | — | ✓ | ✓ | ✓ |
| `imports.run` | — | ✓ | ✓ | ✓ |
| `reports.export` | — | ✓ | ✓ | ✓ |
| `branches.all` (consolidado) | — | — | ✓ | ✓ |
| `admin.*` | — | — | — | ✓ |

- Asignación por sede vía `branch_user`; `direccion` y `admin` acceden a todas (RN-23).
- La restricción temporal del operador (editar solo 7 días atrás) es configurable en `settings.operator_edit_window_days`.

### 15.2 Medidas

- Autenticación Breeze; contraseñas `bcrypt`; throttle en login; sesión con `SameSite=Lax`; 2FA opcional (Fortify) para `direccion`/`admin`.
- Autorización siempre en Policies (servidor); la UI solo oculta.
- Middleware `EnsureBranchAccess` en toda ruta con sede.
- CSRF (Livewire lo maneja); validación en servidor de **todo** input.
- Bitácora `activity_log` no editable desde la app; retención indefinida.
- Respaldo diario de MySQL (`spatie/laravel-backup` o cron `mysqldump`) a almacenamiento externo; prueba de restauración mensual.
- HTTPS obligatorio; cabeceras de seguridad (`secure-headers`).
- Sin datos personales de clientes: el sistema no los maneja.

---

## 16. Pruebas

### 16.1 Unitarias (sin BD) — `tests/Unit`

| Prueba | Valores dorados (§2.6) |
|---|---|
| `IndicatorCalculatorTest::summarize_september_2025` | Σbs 3.012.770,86 · Σusd 18.610,68 · trn 3.853 · units 7.543 · shifts 93 · ticket Bs 781,93 · und/compra 1,9577 · ticket $ 4,8302 · trn/jornada 41,43 · días con inventario 25 |
| `…::usd_is_sum_of_daily_not_total_over_avg_rate` | 18.610,68 ≠ 18.633,78 |
| `…::ratios_are_weighted_not_averaged` | 781,93 ≠ 784,63 |
| `…::division_by_zero_yields_null` | día con trn 0 → ticket `null` |
| `…::closed_days_do_not_enter_ratios` | |
| `…::atypical_exclusion_affects_ratios_not_sums` | |
| `WeekdayDerivationTest` | 2025-09-01 → lunes; los 30 días del fixture |
| `WeekdayPatternTest` | con el fixture: miércoles peso máximo, martes mínimo |
| `GoalProjectorTest` | lineal y por patrón; estados en umbrales 100/90; ratios no acumulan |
| `RateResolverTest` | sábado 06/09 → arrastre del viernes 05/09 (152,82), `source=carried` |
| `FormatterTest` | `91154.02` → `Bs 91.154,02`; `0.1965` → `+19,7 %` |
| `WorkbookParserTest` | parsea el archivo real: 30 filas, mes `SEPTIEMBRE`, 5 nulos en K/L, 30 `weekday_mismatch`, 0 `derived_mismatch` |
| `AnomalyDetectorTest` | fixture modificado: duplicado, faltante, salto de tasa, negativo, conflicto de tasa entre sedes |
| `PreviewParityTest` | los derivados calculados por el helper JS (ejecutado con Node en CI sobre el fixture exportado a JSON) coinciden con `IndicatorCalculator` en los 30 días |
| `DemoSeederTest` | tras `db:seed --class=DemoSeeder` existen 30 registros de septiembre y el panel muestra Σusd 18.610,68 |
| `ConcurrentEditTest` | dos ediciones del mismo día con `updated_at` distinto: la segunda no sobrescribe y devuelve conflicto |
| `UploadValidationTest` | `.xlsm`, > 5 MB y archivo con macros son rechazados con mensaje claro |

El archivo real de septiembre (anonimizable) se incluye como fixture en `tests/Fixtures/`.

### 16.2 Funcionales (Pest + RefreshDatabase) — `tests/Feature`

- UC-02: crear día; duplicado rechazado; advertencia de desvío requiere confirmación; sábado no exige inventario; día futuro rechazado; mes cerrado bloquea.
- UC-03/04/05: edición con bitácora; atípico con motivo; cerrado con ceros.
- UC-07: cerrar con faltantes requiere confirmación; reabrir exige permiso y motivo.
- UC-11/12: metas únicas por (sede, indicador, mes); progreso coherente con calculadora.
- UC-15: importación completa del fixture → 30 registros, tasas creadas como `manual`, bitácora con batch; reimportar mismo hash → oferta de reemplazo.
- UC-14: export Excel abre y contiene encabezados y totales ponderados; PDF genera con imágenes.
- Autorización: operador no puede cerrar mes ni ver metas; usuario sin sede no ve datos.

### 16.3 Navegador (Laravel Dusk, opcional) 

- Flujo UC-02 completo en 360 px y 1366 px; teclado `Enter` guarda; panel calculado se actualiza.

### 16.4 Calidad

- Pint en pre-commit; Larastan nivel 6 en CI; cobertura objetivo 80 % en `Domain/` y `Actions/`.

---

## 17. Fases de implementación

Orden de construcción pensado para tener algo usable lo antes posible y para que cada fase se pruebe sobre la anterior.

### Fase 0 — Cimientos (día 1–2) — **IMPLEMENTADA** (ver §20, iteración 9)
- Proyecto Laravel 11, Breeze (Livewire), Tailwind con tokens de §13.1, IBM Plex Sans self-hosted.
- Paquetes: permission, activitylog, excel, dompdf, brick/math, Pest, Pint, Larastan.
- Migraciones completas de §5 (todas, aunque no se usen aún), seeders: roles/permisos, sede principal, admin.
- Formatter es-VE + pruebas. Configuración de zona horaria y locale.
- CI mínimo (GitHub Actions: pint, larastan, pest).

### Fase 1 — Núcleo de datos (día 3–6) — **MVP** — **IMPLEMENTADA** (ver §20, iteraciones 10 y 11)
- Enum `Indicator`, DTOs, `IndicatorCalculator`, `WeekdayPattern` con pruebas de valores dorados.
- `ExchangeRate` + `RateResolver` + `BcvProvider` + job programado.
- `DailyRecord` + Actions (registrar, editar, atípico, cerrado, borrar) + Policies + Observer de cache.
- Livewire `DailyForm` completo (UC-02 a UC-05) y `MonthCalendar` (UC-06).
- `MonthTable` (UC-09) con totales ponderados y export Excel básico.

### Fase 2 — Panel y gráficas (día 7–9) — **MVP** — **IMPLEMENTADA** (ver §20, iteración 12)
- `DashboardQuery`, `DeltaCalculator`, tarjetas KPI, barra de contexto.
- `ChartSeriesQuery` + ECharts (módulos, tema `guadalupe`, `ChartPanel`): G1, G2, G3, G6 (MVP), luego G4, G5, G7.
- Estados vacíos, skeletons, responsive.

### Fase 3 — Metas (día 10–12) — **MVP** — **IMPLEMENTADA** (ver §20, iteración 13)
- `Goal`, `GoalsManager` (cuadrícula), `SuggestGoal`, `GoalProjector`, `GoalProgressQuery`.
- Barras de meta en KPI, G9 acumulado vs meta, tabla de seguimiento.

### Fase 4 — Cierre de mes, roles y pulido (día 13–14) — **MVP** — **IMPLEMENTADA** (ver §20, iteración 14; el despliegue queda preparado, no ejecutado)
- `CloseMonth`/`ReopenMonth`, candados en UI, permisos por rol, bitácora visible.
- Pruebas funcionales de UC-02…UC-09, UC-11, UC-12. Despliegue a producción.

### Fase 5 — Administración y tasa BCV (día 15–17) — **IMPLEMENTADA** (ver §20, iteración 15)
- **Administración (UC-17)**: usuarios (crear, desactivar, rol, sede, restablecer contraseña), sedes (nombre, jornadas por defecto, días de inventario) y parámetros (`settings`: umbrales de advertencia, ventana de edición del operador, crecimiento sugerido de metas). Sin esto el cliente no puede dar acceso a su gente.
- **Tasa BCV (UC-16, §9.4)**: historial de tasas con origen, corrección manual, consulta forzada al BCV y recálculo del mes con vista previa del efecto.
- Perfil de usuario con la identidad y en español (hoy sigue con el diseño de Breeze).
- Menú: "Tasa BCV" y "Administración" dejan de estar en "Próximamente".

### Fase 6 — Histórico, año y PDF (día 18–23) — **IMPLEMENTADA** (ver §20, iteración 16; era "Ampliación A")
- **Importador (UC-15, §10)** con asistente de tres pasos y pruebas contra el archivo real; alimenta la comparación interanual, el patrón semanal y las sugerencias de meta.
- **Año (UC-13)**: tabla anual del Excel (§2.4), G11 comparativa interanual, G10 tasa vs venta, pestañas "Tasa" y "Año" en Gráficas y hojas "Anual" y "Tasas" en la exportación (§11.1).
- **Reporte PDF mensual (§11.2)** con cuadro, KPI, metas y gráficas. El envío programado por correo pasa a la Fase 7 (necesita SMTP).
- G8 ya está construido (Fase 2).

### Fase 7 — Amigabilidad y entrega (día 24–28) — **IMPLEMENTADA** (ver §20, iteración 17; queda lo que depende del cliente)
- Pendientes de §13.5 y §13.8: ayuda contextual ("¿Cómo se calcula?" y panel "?" con glosario), borrador automático del formulario por fecha, sesión vencida y red caída con mensaje amable, carga en secuencia de los días atrasados, hoja de impresión para Mes y Panel, atajos de teclado, tabla de metas apilada en móvil, "Ampliar" en las gráficas, envío programado del reporte PDF por correo, deshacer del marcado atípico, ordenar y buscar en el cuadro del mes.
- Entrega: guía `DESPLIEGUE.md` (requisitos, instalación, comprobaciones, tareas programadas, respaldo, seguridad, actualización), respaldo diario (`db:backup`), cabeceras de seguridad, prueba del BCV (`rates:fetch`, verificada desde desarrollo contra las fuentes reales), limpieza de la demostración (`demo:clear`), `MANUAL-USUARIO.md` con capturas. 2FA para dirección no se incluye (requiere Fortify; queda como opción).
- **Depende del cliente**: hosting y dominio, cuenta SMTP, ejecutar el despliegue, capacitación y la reunión de arranque con las preguntas de §19.

### Fase 8 — Multi-sede (día 29–31) — **CONDICIONADA** (Ampliación B, US$ 150)
- Activar selector de sede, consolidado por gráfica, metas de consolidado, comparación entre sedes (UC-18), permisos por sede en UI.

> Los días son de dedicación completa y sirven para ordenar el trabajo, no como compromiso contractual (el plazo pactado es 2–3 semanas para el MVP teniendo todos los insumos). Estado al 03-09-2026: Fases 0 a 4 terminadas en 14 días de trabajo; faltan unos 14 días para las Fases 5 a 7.

---

## 18. Matriz de cobertura

Cada requerimiento del cliente y cada hallazgo de la auditoría, con el componente que lo resuelve.

| Requerimiento / hallazgo | Resuelto por | UC | Prueba |
|---|---|---|---|
| "Digitalizarlo" (reemplazar el Excel) | DailyForm, MonthTable, export Excel | 02, 09, 14 | Feature UC-02, UC-14 |
| "Sacar estadística" | IndicatorCalculator, DeltaCalculator, DashboardQuery | 08 | Unit calculator |
| "Gráficas" | §14 G1–G11 | 10 | Feature charts render |
| "KPIs (metas)" | Goal, GoalProjector, GoalsManager | 11, 12 | Unit projector, Feature goals |
| Multi-sede (1 hoy, otra por abrir) | `branch_id` en todo el modelo; UC-18 | 18 | Feature consolidado |
| Tasa BCV | ExchangeRate, RateResolver, BcvProvider | 16 | Unit resolver |
| H1 día de semana corrido | RN-02, derivación de fecha | 02 | WeekdayDerivationTest |
| H2 valuación en USD | `inventory_value_usd` | 02 | — |
| H3 sábados sin inventario | `branches.inventory_days`, RN-09 | 02, 06 | Feature sábado |
| H4 arrastre de tasa | RN-07, `source=carried` | 02, 16 | RateResolverTest |
| H5 día atípico sin explicación | `status`, `notes`, RN-11 | 04 | Feature atípico |
| H6 jornadas por defecto | `branches.default_shifts` | 02 | — |
| H7 precisión de presentación | `Indicator::precision()`, Formatter | 08, 09 | FormatterTest |
| H8/H9 gráficos rotos | §14 (nueva especificación) | 10 | — |
| H10 encabezados sucios | WorkbookParser normalización | 15 | WorkbookParserTest |
| H11 archivo por mes | ImportWizard en lote | 15 | Feature import |
| H12 totales con datos | RN-03, totales en presentación y export separados | 09, 14 | — |
| Promedio de promedios | RN-04, `summarize` ponderado | 08, 09 | `ratios_are_weighted` |
| Venta $ = suma diaria | RN-05 | 08 | `usd_is_sum_of_daily` |
| Sin validación en el Excel | RN-15, RN-16, modal de advertencias | 02 | Feature advertencia |
| Tabla anual vacía | AnnualComparisonQuery, export hoja Anual | 13, 14 | Feature anual |
| Reporte que hoy envían | Export Excel mismo formato + PDF | 14 | Feature export |
| Bitácora | activitylog en modelos | 03, 07 | Feature bitácora |
| Carga en < 1 min | UX UC-02 (defaults, tab order, Enter, panel calculado) | 02 | Dusk (opcional) |

---

## 19. Preguntas pendientes al cliente y supuestos de construcción

El cliente aprobó verbalmente y está reuniendo el presupuesto. Para no detener el desarrollo, **se construye con los supuestos de esta tabla**, y cada uno queda aislado en configuración o en un punto único del código, de modo que la respuesta del cliente se aplica sin rehacer nada. Estas preguntas se formulan **en la reunión de arranque, cuando paguen**, y sus respuestas se registran en esta misma tabla.

### 19.1 Preguntas que afectan datos y cálculo

| # | Pregunta | Supuesto con el que se construye | Si la respuesta difiere | Dónde se ajusta |
|---|---|---|---|---|
| P1 | **La tasa es la BCV** (confirmado). ¿La quieren automática desde la web del BCV, o prefieren cargarla a mano cada día? | Automática con arrastre en fines de semana; siempre editable | Se desactiva el proveedor (`rates.provider = null`); todo lo demás igual | `config/rates.php` |
| P2 | La **valuación de inventario**: ¿está a **costo** o a **precio de venta**? ¿Siempre en **dólares**? | A costo, en USD (el encabezado dice "costo"; el orden de magnitud solo cuadra en USD) | Cambia la etiqueta y, si fuera en Bs, se agrega conversión con la tasa del día | Etiqueta en `Indicator`; una columna más si fuera Bs |
| P3 | ¿Por qué **no se cuenta inventario los sábados**? ¿Es regla fija o depende del personal? ¿Los domingos sí? | Sábados sin conteo; domingos con conteo (así está en el archivo) | Se cambia el conjunto de días | `branches.inventory_days` (pantalla Admin) |
| P4 | ¿Qué es exactamente una **jornada**? (¿turno de caja, turno de personal?) ¿Por qué hay días con 4? | Turno de trabajo; valor por defecto 3 | Solo cambia el texto de ayuda y el valor por defecto | `branches.default_shifts` |
| P5 | Una **transacción (TRN)**, ¿es una factura? ¿Incluye devoluciones o notas de crédito? ¿Las **unidades** cuentan fracciones (blíster, tableta suelta)? | Se captura tal como lo hacen hoy, como entero | Si hay devoluciones separadas, se agrega un campo opcional "devoluciones" y un indicador neto | Migración + `Indicator` |
| P6 | ¿Disponen del **margen bruto** o del costo de ventas? | No configurado; rotación y días de inventario quedan ocultos | Se configura el margen y aparecen los dos indicadores | `settings.gross_margin_pct` |
| P7 | ¿Qué pasó el **16 de septiembre** (día con el 28 % de la venta normal)? | Existe el campo de incidencia y la marca "atípico"; ese día se marcará al importar | Confirma la necesidad; no cambia diseño | — |
| P8 | ¿Quieren ver la **venta en $ con decimales** o sin ellos como en el Excel? ¿Y el ticket? | Como el Excel: $ sin decimales en tabla, ticket Bs sin decimales, ratios con 1 | Tabla de precisión | `Indicator::precision()` |
| P9 | ¿Un desvío del **35 %** respecto a la media móvil es un buen umbral de advertencia? | 35 % ventas, 10 % tasa | Se ajusta el número | `settings` (pantalla Admin) |

### 19.2 Preguntas que afectan metas y lectura del negocio

| # | Pregunta | Supuesto | Si difiere | Dónde |
|---|---|---|---|---|
| P10 | ¿**Quién define las metas** y con qué frecuencia? ¿Mensuales, o también semanales/diarias? | Mensuales por indicador, las define `direccion`, sugeridas por el sistema | Metas semanales requieren `period_type` en `goals` (columna prevista, no expuesta) | `goals.period_type` + pantalla |
| P11 | ¿Las metas en **dólares** o en **bolívares**? | USD (una meta en Bs se cumple sola por devaluación) | Se permite Bs con advertencia; ya soportado | `goals.currency` |
| P12 | ¿Qué indicadores les importan **más** para el panel principal? (orden de las tarjetas) | Venta $, venta Bs, TRN, unidades, ticket $, unidades/compra | Se reordena | `settings.dashboard_cards` |
| P13 | ¿Comparan contra el **mes anterior** o contra el **mismo mes del año pasado**? ¿Ambos? | Ambos (el segundo cuando haya histórico) | Se oculta uno | Config de tarjeta |

### 19.3 Preguntas que afectan usuarios, sedes y operación

| # | Pregunta | Supuesto | Si difiere | Dónde |
|---|---|---|---|---|
| P14 | ¿**Quiénes cargan** el cuadro hoy y cuántas personas son? ¿Quién lo **consulta**? | 1–2 operadores, 1 supervisor, 1–2 dirección | Solo cambia el seeder de usuarios | Seeder / pantalla Usuarios |
| P15 | ¿El **operador** puede ver la venta total del mes y las metas, o solo cargar? | Ve la tabla del mes, no ve metas ni panel de dirección | Se ajustan permisos | Roles (spatie) |
| P16 | ¿Cuándo abre la **segunda sede** y cómo se llama? ¿Tendrá su propia razón social? ¿Comparte metas o son independientes? | Una sede activa ("Sede Principal"); modelo listo para N | Se crea la sede en Admin y se activa la Ampliación B | Pantalla Sedes |
| P17 | ¿Conservan los **Excel de meses anteriores**? ¿Desde qué año? ¿**Siempre con la misma plantilla**? | Importador para la plantilla actual; se valida con los archivos que entreguen | Si hubo otra plantilla, un segundo `WorkbookParser` versionado | `Domain/Imports/Parsers/` |
| P18 | ¿Existe algún **dashboard previo** o intento anterior? | No | Se revisa antes de la Fase 5 por si hay datos migrables | — |
| P19 | ¿**A quién** se envía hoy el reporte mensual y en qué formato lo prefieren (Excel, PDF, ambos)? ¿Quieren envío automático por correo? | Ambos formatos disponibles; envío automático en Ampliación A | Se configura lista de correos y día | `settings.report_recipients` |
| P20 | ¿**Dónde se aloja**? ¿Tienen hosting o servidor propio, o lo proveemos? ¿Tienen dominio? | Hosting compartido con PHP 8.3 + MySQL 8 (ver §4.6); dominio del cliente | Si es servidor propio: worker persistente y Chrome para PDF con gráficas en correo | `.env` |
| P21 | ¿Tienen **manual de marca** o el logo en vector? | Paleta derivada de la portada de Facebook (§13.1); logo en PNG | Se sustituyen los tokens y el archivo del logo | `tailwind.config.js` tokens + `public/brand/` |
| P22 | ¿Cómo quieren que se **llame** el sistema en pantalla y en el PDF? | "Indicadores · Farmacia Guadalupe" | Texto | `settings.app_name` |

### 19.4 Cómo se aplican las respuestas

1. Cada respuesta se anota en la columna "Supuesto" tachando el anterior, con fecha.
2. Si la respuesta coincide con el supuesto: nada que hacer.
3. Si difiere y el ajuste es de **configuración**: se aplica en la pantalla Admin o en `.env` el mismo día.
4. Si difiere y el ajuste es de **código** (P2 en Bs, P5 devoluciones, P10 metas semanales, P17 segunda plantilla): se estima y se acuerda como parte del arranque, sin bloquear el resto.

> Regla práctica: **ninguna de las 22 preguntas bloquea el desarrollo**. Todas tienen un supuesto razonable respaldado por el archivo auditado, y ninguna respuesta obliga a rehacer una tabla, una pantalla o el motor de cálculo.

---

## 20. Registro de iteraciones del plan

Se documentan las pasadas de revisión, qué se encontró y qué cambió. El objetivo es que el plan final no dependa de "lo que se asumió" sino de lo verificado.

### Iteración 1 — Borrador completo
Redacción de §1–§18 a partir de las tres auditorías previas del Excel (estructura, fórmulas, gráficos, metadatos), la conversación con el cliente y las decisiones de stack.

### Iteración 2 — Revisión contra el Excel (cobertura funcional)

Se recorrió cada columna, fórmula, formato, gráfico y celda residual del archivo contra el plan. Hallazgos y correcciones:

| # | Hallazgo | Corrección aplicada |
|---|---|---|
| 2.1 | El nombre del archivo real tiene un error de tipeo (`SEOTIEMBRE`); un importador que dedujera el mes del nombre fallaría | §10.2: el mes se lee de `B2` (tolerando `SETIEMBRE`) y el año de las fechas; el nombre del archivo no se usa |
| 2.2 | Dos sedes importando el mismo mes escribirían la misma fecha en `exchange_rates` con valores potencialmente distintos | Nueva anomalía `rate_conflict` (§10.3) |
| 2.3 | La tabla anual del Excel usa promedio simple de la tasa (`AVERAGE(E)`); el sistema usa ponderado. Conciliar con reportes antiguos sería imposible | `avg_rate_simple` expuesto junto al ponderado (§7.1) |
| 2.4 | Un día cerrado sin tasa rompería la continuidad de la serie de tasas del mes en G10 | RN-24 |
| 2.5 | Se verificó que la valuación (columna L) solo tiene sentido en USD (21.848 frente a una venta diaria de $614); se convirtió en hecho H2 y en pregunta P2 para confirmación | §2.5, §19.1 |
| 2.6 | Se verificó que los domingos sí tienen inventario (7, 14, 21 y 28/9 con datos) y solo el sábado no; el defecto `inventory_days` excluye únicamente el 6 (ISO) | §2.5 H3, §4.5 |
| 2.7 | El gráfico de inventario original tiene 25 puntos (excluye vacíos): confirma que en gráficas los días sin conteo deben ser huecos, no ceros | H9, §14 G7 |
| 2.8 | La venta en $ se muestra sin decimales en el Excel (formato `0`) y el ticket Bs también (`#,##0`); adoptado como precisión por defecto y elevado a pregunta P8 | §2.2, §19.1 |

### Iteración 3 — Revisión de casos de uso y UX

Se recorrió cada caso de uso como si se operara el sistema en la farmacia, en escritorio y en teléfono. Hallazgos y correcciones:

| # | Hallazgo | Corrección aplicada |
|---|---|---|
| 3.1 | El error más probable en la carga (tasa 15 en vez de 150, venta con un cero de más) no tenía defensa visual inmediata; la validación blanda avisa solo al guardar | UC-02 paso 7b: referencia "Ayer: …" bajo cada campo |
| 3.2 | Calcular la vista previa en el servidor en cada tecla añade latencia perceptible en hosting compartido | RN-26: vista previa en Alpine, servidor como fuente de verdad, prueba de paridad |
| 3.3 | El operador aterrizaba en un panel de dirección que no le sirve y que quizá no debe ver | Inicio por rol (§13.5) |
| 3.4 | "Fecha futura" evaluada en la zona del servidor daría falsos rechazos a las 8 p. m. de Caracas si el servidor está en UTC | RN-25 |
| 3.5 | La cuadrícula de metas (12 meses × N indicadores) sería tediosa con el ratón | Navegación por teclado y pegado desde Excel (§13.5) |
| 3.6 | Nadie recuerda cerrar el mes ni cargar los días que faltaron | Recordatorio de cierre el día 1 (Ampliación A, §13.5) |
| 3.7 | El significado de "Ambas" monedas era ambiguo entre tabla y tarjetas | Definido en §13.5 |
| 3.8 | La descarga del PDF podía fallar si el navegador no enviaba las imágenes | Fallback sin gráficas (§11.2) |

### Iteración 4 — Revisión de arquitectura y calidad de código

Se revisó el modelo de datos, la separación de capas y la operación en hosting compartido. Hallazgos y correcciones:

| # | Hallazgo | Corrección aplicada |
|---|---|---|
| 4.1 | `unique(branch_id, indicator, period)` con `branch_id NULL` (consolidado) **no es único en MySQL**: permite metas duplicadas para el consolidado. Mismo problema en `settings` | Columna generada `branch_key = COALESCE(branch_id, 0)` y unique sobre ella (§5.2) |
| 4.2 | `period_closures` con `reopened_at` nullable mezclaba estado e historial y complicaba "reabrir dos veces" | Reemplazada por `period_events` (historial de acciones; el estado es la última fila) |
| 4.3 | Faltaba la acción explícita para "recalcular tasas del mes" mencionada en §9.3 | `RecalculateMonthRates` en §4.3 |
| 4.4 | El `Formatter` estaba definido solo en PHP; la vista previa en navegador necesitaba el mismo formato | Directivas Blade + helper JS equivalente (§6.6) |
| 4.5 | Jobs y scheduler asumían un worker de colas supervisado, que no existe en hosting compartido | §4.6: colas en base de datos ejecutadas por el scheduler; un solo cron |
| 4.6 | El BCV publica en la tarde la tasa del día siguiente; el job de la mañana llegaría tarde para la carga temprana | §9.2: corrida de 17:30 guarda con `date = vigencia`; la de 08:00 es respaldo |
| 4.7 | Un sistema vacío en la primera demostración transmite menos que uno con datos reales | `DemoSeeder` con el fixture de septiembre (§4.6, §16) |
| 4.8 | Se añadieron a §16 las pruebas que respaldan los puntos anteriores | `PreviewParityTest`, `DemoSeederTest`, conflicto de tasa en `AnomalyDetectorTest` |

### Iteración 5 — Incorporación del contexto comercial

El cliente aprobó verbalmente y está reuniendo el presupuesto; el desarrollo avanza antes del pago. Se añadió §19 con las 22 preguntas pendientes, cada una con el supuesto de construcción y el punto único donde se ajusta la respuesta. Ninguna bloquea el desarrollo.

### Iteración 6 — Code review del plan

Revisión del modelo, las capas y la operación como si fuera una pull request. Hallazgos y correcciones:

| # | Hallazgo | Corrección |
|---|---|---|
| 6.1 | Acciones que escriben varias filas (registro + tasa manual + bitácora) sin transacción explícita | §4.2: `DB::transaction()` obligatorio en acciones de escritura |
| 6.2 | Sin política de casts: `decimal:N` de Laravel redondea con `number_format` y un `float` accidental rompería la exactitud | §5.4: tabla de casts; `float` y `decimal:N` prohibidos |
| 6.3 | Dos usuarios editando el mismo día se sobrescribían en silencio | UC-03: bloqueo optimista por `updated_at` con resolución explícita; `ConcurrentEditTest` |
| 6.4 | Carga de archivos sin límites ni validación de tipo (un `.xlsm` con macros pasaba) | §10.4: MIME, tamaño, cantidad, apertura sin macros; `UploadValidationTest` |
| 6.5 | El endpoint que recibe PNG de gráficas era un vector de abuso (sin límites ni firma) | §11.2: ruta firmada, límites de tamaño, cantidad y frecuencia |
| 6.6 | Invalidación de cache incompleta: importaciones y recálculo de tasas no invalidaban | §4.2 principio 7 |
| 6.7 | Bitácora sin configuración: se habrían registrado `updated_at` y campos irrelevantes; sin vía de restauración | §5.2 `activity_log`: `logOnlyDirty`, atributos explícitos, `RestoreDeletedRecord` |
| 6.8 | Archivos subidos juntos no tenían identidad de lote | `import_batches.group_id` |
| 6.9 | "Semanas cerradas" en `WeekdayPattern` era ambiguo (¿meses cerrados?) | "Semanas completas anteriores al período" |

### Iteración 7 — Revisión de UX y UI (rúbrica Impeccable, modo Operate)

Se evaluó la especificación con las 10 heurísticas de Nielsen, el checklist de carga cognitiva y las personas de referencia. Puntuación de la versión 4: 31/40; de la versión 5: 36/40. Hallazgos y correcciones:

| # | Hallazgo | Corrección |
|---|---|---|
| 7.1 | El panel abría con seis tarjetas idénticas: nada era "lo primero"; la pregunta del usuario ("¿vamos a cumplir?") no tenía respuesta visible | Héroe del mes: frase de estado + proyección sobre la gráfica acumulado vs meta; cuatro KPI primarios; fila secundaria plegada (§13.5, §13.7, UC-08) |
| 7.2 | Etiquetas en mayúsculas, títulos con puntos medios y tarjetas con sombra uniforme: marcas de interfaz genérica | Sentence case en todo; título + subtítulo; hairlines en vez de sombras; tres radios con jerarquía (§13.2, §13.4) |
| 7.3 | Tipografía por defecto (Inter) sin decisión | IBM Plex Sans, una familia, cifras tabulares, escala fija (§13.2) |
| 7.4 | El morado de la marca no tenía rol; se usaba como "segunda serie" sin significado | Regla azul = datos y acciones, morado = metas y proyección (§13.0, §13.1) |
| 7.5 | Navegación de ocho entradas planas supera el límite de opciones visibles por nivel | Tres grupos con título (§13.3) |
| 7.6 | Barra lateral en azul marino saturado: color pesado en estado inactivo | Capa neutra `panel` con activo en azul (§13.1, §13.3) |
| 7.7 | Modal de advertencias al guardar: interrumpe y se aprende a ignorar | Advertencias en línea al escribir + franja sobre el botón; modal solo para cerrar/reabrir/borrar/importar (§13.5) |
| 7.8 | Sin ayuda contextual: el operador nuevo no sabía qué es "TRN" ni cómo se calcula el ticket (heurística 10 en 2/4) | "¿Cómo se calcula?" en cada KPI y columna; panel de ayuda por pantalla con glosario (§13.5) |
| 7.9 | Formulario de siete campos planos: sin agrupación ni unidades en las etiquetas | Tres grupos con título; unidad dentro de la etiqueta; referencia "Ayer" por campo (§13.5, UC-02) |
| 7.10 | Sin borrador: una pestaña cerrada o sesión vencida perdía lo escrito (persona "Casey", uso en teléfono con interrupciones) | Borrador local por fecha (§13.5, §13.8) |
| 7.11 | Estados de componentes no definidos; overlays podían quedar recortados por tablas con scroll | Regla de siete estados por control; overlays en portal (§13.4) |
| 7.12 | Once gráficas en una sola cuadrícula | Pestañas por familia, dos por fila (§13.7, UC-10) |
| 7.13 | Metas solo como cuadrícula anual: densa para el uso mensual habitual | Vista "Este mes" por defecto; "Año" para planificar (§13.5, UC-11) |
| 7.14 | Botón flotante en móvil tapaba datos del calendario | Botón fijo al pie (§13.3) |

Puntuación por heurística (v5): estado del sistema 4 · lenguaje del usuario 4 · control y libertad 3 · consistencia 4 · prevención de errores 4 · reconocimiento 4 · flexibilidad 3 · minimalismo 4 · recuperación 4 · ayuda 2→3 (sube a 4 cuando exista la ayuda por pantalla construida). Carga cognitiva del formulario: 0 fallos del checklist; del panel: 0 fallos tras la jerarquía del héroe.

### Iteración 8 — Selección de la librería de gráficas

Se compararon ECharts, ApexCharts, Chart.js, Highcharts y D3 contra los requisitos concretos de §14 (eje secundario, calendario de calor, anotaciones de meta y atípicos, export 2×, formato es-VE, integración Livewire, licencia). Decisión: **ECharts 5** (§14.1); sparklines como SVG en línea; estadística en servidor con `math-php`. Se reemplazaron las referencias a ApexCharts en §4.1, §4.3, §11.2, §14 y §17.

### Iteración 9 — Implementación de la Fase 0 y code review del código

Proyecto creado en `indicadores/`. Lo entregado: Laravel 13 con Breeze (stack Livewire/Volt), Tailwind 3 con los tokens de §13.1 y IBM Plex Sans self-hosted (subsets latin), las 8 migraciones de §5 más `users.is_active`, permisos y bitácora de Spatie, modelos con casts exactos, enums, `Indicator`, `Formatter` es-VE, `Period`, seeders (roles, sede, admin, demo con septiembre 2025 desde el fixture), módulos JS de ECharts con carga diferida, CI, Larastan nivel 6, Pest. **63 pruebas en verde, 0 errores de análisis estático, estilo limpio.**

Diferencias respecto a lo planificado, con su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 9.1 | Laravel 11 / PHP 8.3 / Livewire 3 / ECharts 5 | Laravel 13 / PHP 8.4 / Livewire 4 / ECharts 6 | Versiones vigentes al instalar; todo lo especificado es compatible |
| 9.2 | MySQL en desarrollo | SQLite en desarrollo y pruebas; MySQL en producción | No hay MySQL en la máquina de desarrollo; las migraciones se escribieron portables |
| 9.3 | Cast `immutable_date` | Cast propio `DateOnlyCast` (guarda `Y-m-d`) | El cast nativo guarda `Y-m-d H:i:s` y rompía la igualdad de fechas en SQLite; detectado por la prueba de idempotencia del seeder |
| 9.4 | `LogsActivity` en `Traits`, `dontSubmitEmptyLogs()` | `Models\Concerns\LogsActivity`, `dontLogEmptyChanges()` | Cambios de API en activitylog v5 |
| 9.5 | `RoundingMode::HALF_UP` | `RoundingMode::HalfUp` | brick/math convirtió `RoundingMode` en enum |
| 9.6 | ECharts ~110 KB gz | 219 KB gz, en un chunk separado que se carga solo en pantallas con gráficas; el bundle inicial pesa 1 KB gz | ECharts 6 es mayor de lo estimado; la carga diferida elimina el costo en la carga diaria |

Hallazgos del code review sobre el código generado y su corrección:

| # | Hallazgo | Corrección |
|---|---|---|
| 9.7 | Las plantillas de Breeze cargaban la fuente Figtree desde una CDN, contra §13.2 | Eliminado; prueba `AccessTest` verifica que ninguna vista llama a CDN de fuentes |
| 9.8 | Registro público de usuarios habilitado por Breeze | Ruta eliminada (404); los usuarios los crea Administración. Prueba incluida |
| 9.9 | La raíz mostraba la página de bienvenida de Laravel | Redirige al panel |
| 9.10 | `env()` en un seeder (nulo con config cacheada) | `config/indicadores.php` |
| 9.11 | `User` sin `MustVerifyEmail` con el middleware `verified` activo | Implementado; el admin sembrado nace verificado |
| 9.12 | Relaciones y colecciones sin genéricos; `HasFactory` en modelos sin factory | Genéricos completos (Larastan nivel 6 en cero), trait retirado |
| 9.13 | Un cast que declaraba no admitir `float` pero lo comprobaba | Tipo corregido; la comprobación ahora tiene sentido |
| 9.14 | `@tailwindcss/vite` v4 residual junto a Tailwind 3 | Retirado |

Instaladas además las herramientas de agente pedidas por el usuario y por el `CLAUDE.md` del proyecto: Impeccable (diseño) y Laravel Boost (guías y MCP).

### Iteración 10 — Implementación de la Fase 1 y code review en bucle

Entregado en `indicadores/`: motor de indicadores (`IndicatorCalculator`, `WeekdayPattern`, DTOs) verificado contra los valores dorados de §2.6; tasa BCV (`BcvProvider` con API primaria y respaldo en la página del BCV, `RateResolver` con arrastre, `UpsertExchangeRate`, `FetchBcvRate`, `RecalculateMonthRates`, job programado a las 08:00 y 17:30); registros diarios (`RegisterDailyRecord`, `UpdateDailyRecord` con bloqueo optimista, `MarkDayAtypical`, `RegisterClosedDay`, `DeleteDailyRecord`), advertencias blandas (`WarningDetector`), políticas, cache de agregados con invalidación por observer, consultas de lectura; interfaz: armazón de la aplicación (§13.3), barra de contexto, formulario de carga diaria con vista previa en el navegador y advertencias en línea, pantalla del mes con calendario y cuadro de indicadores, panel inicial, exportación básica a Excel. **140 pruebas en verde, Larastan nivel 6 en cero, Pint limpio, assets construidos.**

Desvíos respecto al plan y su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 10.1 | `MissingDaysQuery` y `PeriodSummaryQuery` como clases aparte | Fusionadas en `MonthRecordsQuery` / `MonthView` | Evitar abstracciones sin segundo uso (guía de arquitectura del proyecto) |
| 10.2 | Middleware `ResolveCurrentBranch` y `EnsureBranchAccess` | Contexto resuelto por `CurrentBranch`/`PeriodContext` en sesión y autorizado en cada componente con políticas | No hay rutas con sede en la URL; la autorización vive donde se usa |
| 10.3 | Componente `x-icon` | `x-lucide` | `blade-icons` (dependencia transitiva) ya registra `x-icon` |
| 10.4 | Redirecciones con `wire:navigate` tras guardar | Recarga completa | Garantiza que el aviso en sesión ("Día guardado") se muestre siempre |
| 10.5 | Ruta de cierre de sesión implícita en Breeze | Ruta `POST /logout` propia | El stack Livewire de Breeze cerraba sesión desde un componente Volt que se sustituyó por el armazón nuevo |

Hallazgos del code review en bucle (tres rondas hasta quedar sin correcciones):

| Ronda | Hallazgo | Corrección |
|---|---|---|
| 1 | `"91.154"` sin coma se leía como 91,154 y `"1.234.567"` como inválido | `parseNumber` y `fmt.parse` reconocen puntos de miles cada tres dígitos; pruebas de paridad |
| 1 | Cargar una fecha ya existente producía un error 500 (violación de unicidad sin mensaje) | `DuplicateDayException` en las acciones; el formulario muestra "El 01/09/2025 ya está cargado" |
| 1 | Re-escribir la tasa arrastrada la convertía en manual y creaba una fila de tasa redundante | La acción compara con la tasa resuelta y conserva el origen si coincide |
| 1 | Eloquent devolvía el `BigDecimal` original sin normalizar tras `create()` | `withoutObjectCaching` en los casts |
| 1 | Política de cierre de mes no registrada; filtros de bitácora por `subject_id` colisionaban entre tablas | `Gate::policy(Branch, PeriodPolicy)`; filtros por `subject_type` |
| 2 | El operador aterrizaba en el panel de dirección | Redirección a Mes según `Role::homeRoute()` (§13.8) |
| 2 | `accessibleBranches()` consultaba dos veces por petición | Memo con `once()` |
| 3 | Cambiar de sede con el formulario abierto guardaba en la sede anterior | El formulario escucha `context-changed` y se reabre para la sede activa |
| 3 | Aviso tras guardar dependía de la navegación parcial | Recarga completa tras guardar |

Pendiente para fases siguientes (no forma parte de la Fase 1): restilizar las pantallas de acceso de Breeze con la identidad (§13), deltas y sparklines en las tarjetas KPI, gráficas, metas, cierre de mes desde la interfaz, borrador local del formulario y ayuda contextual.

### Iteración 11 — Pruebas de interfaz en navegador (Playwright)

Recorrido E2E con Chromium contra el servidor de desarrollo y la base sembrada con septiembre 2025: `indicadores/tests/Browser/walkthrough.py` (17 pasos: acceso, panel vacío y con datos, barra de contexto, mes con calendario y cuadro, moneda, exclusión de atípicos, exportación, formulario con vista previa, formateo al perder el foco, advertencias, guardado y redirección, edición, día cerrado, móvil a 360 px y salida). Las pruebas Pest no ejercitan Alpine, la cache real ni el CSS, y ahí estaban los defectos:

| # | Hallazgo (solo visible en navegador) | Corrección |
|---|---|---|
| 11.1 | La pantalla del mes devolvía 500 con la cache `database`/`file`: Laravel 13 no deserializa objetos de la cache (`cache.serializable_classes = false`) y `PeriodSummary` llegaba como `__PHP_Incomplete_Class`. Las pruebas usaban el store `array`, que no serializa | `PeriodSummary::toArray()/fromArray()`; la cache guarda escalares. El store `array` serializa en pruebas (`CACHE_ARRAY_SERIALIZE`) para reproducir el comportamiento real; prueba `PeriodSummaryCacheTest` |
| 11.2 | El botón «Revisar» de la franja de advertencias rompía Alpine ("Invalid or unexpected token"): `@js()` dentro de un atributo de componente Blade no se compila | Interpolación `{{ }}`; la prueba del formulario comprueba el `onclick` compilado |
| 11.3 | La vista previa se reiniciaba a "—" en cada respuesta de Livewire (el `x-data` con valores del servidor se re-evaluaba al morph) | Estado enlazado con `$wire.entangle` y `x-data` estático; así también sigue las correcciones del servidor (tasa arrastrada, cambio de fecha) |
| 11.4 | En móvil, la página del mes desbordaba a 1.280 px: el hijo flex del armazón no podía encoger por debajo del ancho mínimo de la tabla | `min-w-0` en el contenedor; la tabla se desplaza dentro de su propio contenedor y el calendario cabe en 360 px |
| 11.5 | En móvil, el panel «Se calculará» y el botón Guardar quedaban al final del formulario (§13.3 pedía Guardar fijo al pie) | Barra fija sobre la navegación inferior con Venta $, Ticket Bs, Und./compra y Guardar; alturas en px porque el `rem` base es 15 px |
| 11.6 | Un día cerrado se listaba con ceros en el cuadro | Guiones en la fila cerrada; prueba en `MonthOverviewTest` |
| 11.7 | La advertencia "hoy toca inventario y está vacío" aparecía mientras el operador aún escribía los primeros campos | Solo se evalúa al intentar guardar |

Estado tras la iteración: 17/17 pasos del recorrido, 146 pruebas Pest, Larastan nivel 6 en cero, Pint limpio, sin errores de consola en el navegador.

### Iteración 12 — Implementación de la Fase 2 (panel y gráficas) con code review y pruebas en navegador

Entregado en `indicadores/`:

- **Dominio**: `Delta` y `DeltaCalculator` (§7.2: variación frente al mes anterior y al mismo mes del año anterior; comparación a fecha equivalente cuando el mes en curso está incompleto; para los indicadores en Bs, la variación en $ al lado para separar crecimiento de devaluación; tono por `moreIsBetter`, tasa sin color). `ChartSpecBuilder` (puro) construye la opción de ECharts de G1–G8 con tooltips y tabla de datos ya formateados en es-VE, marcador rojo en días atípicos, franja gris en días cerrados y huecos (no ceros) en días sin dato.
- **Consultas**: `DashboardQuery`/`DashboardView` (KPI, deltas, sparklines de 14 días, avisos del mes: faltantes, tasa de hoy arrastrada o ausente, mes anterior sin cerrar, mes cerrado; G2 y G8) y `ChartSeriesQuery`.
- **Interfaz**: tarjetas KPI con delta, flecha y sparkline SVG generada en Blade (§13.5); con la moneda en Bs la venta y el ticket van en Bs en grande y en $ debajo (§13.8). Panel con G2 y G8 sin marco y sección "Avisos del mes". Pantalla **Gráficas** (`/graficas`) con pestañas Ventas (G2, G1, G8) · Operación (G3, G6, G4, G5) · Inventario (G7); Tasa y Año visibles como próximas. Cada gráfica con acciones "Datos" (tabla con los mismos valores) y "PNG" (2×). ECharts se carga bajo demanda (227 KB gz solo en pantallas con gráficas; el formulario sigue en 2 KB).
- **Demo**: `DemoHistorySeeder` siembra un agosto 2025 sintético derivado de septiembre para que la demo muestre variaciones; no toca el mes auditado.
- **Pruebas**: 173 Pest (unitarias de `DeltaCalculator` y `ChartSpecBuilder` con el fixture real; funcionales de `DashboardQuery`, panel, página de gráficas y seeder), Larastan nivel 6 en cero, Pint limpio. Recorrido Playwright ampliado a 22 pasos: deltas y sparklines visibles, SVG de ECharts dibujado en G1–G8, tooltip es-VE, tabla "Datos", cambio de mes redibuja sin recargar, pestañas con URL, descarga PNG, mes vacío, móvil (gráfica a 220 px sin desborde).

Desvíos respecto al plan y su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 12.1 | `ChartPanel` como componente Livewire por gráfica | Componente Blade `x-chart-panel` + Alpine `chartPanel` que lee `$wire.specs[id]` del componente de la pantalla | Una sola petición por cambio de contexto para todas las gráficas de la pestaña; Livewire no toca el SVG (`wire:ignore`) |
| 12.2 | Segunda serie en morado (paleta del tema) | Segunda serie en teal; el morado queda solo para metas | §13.1/§14: "meta siempre en morado" no debe confundirse con una serie de datos |
| 12.3 | Gráficas G4, G5, G7 "luego" | Incluidas ya | Mismo constructor; costaba más excluirlas |
| 12.4 | Sparkline de 14 días "a la derecha del valor" | Igual, pero la venta en Bs va sin céntimos y baja a 22 px cuando la cifra es larga | En 1366 px "Bs 3.012.770,86" a 32 px se partía en dos líneas y pisaba la sparkline |
| 12.5 | Consolidado multi-sede en gráficas | Toma la primera fila por fecha | El consolidado por gráfica pertenece a la Ampliación B |

Hallazgos del code review y del recorrido en navegador (corregidos):

| # | Hallazgo | Corrección |
|---|---|---|
| 12.6 | Cada actualización de Livewire redibujaba todas las gráficas aunque no cambiaran | El panel compara la especificación serializada y solo llama a `setOption` si cambió |
| 12.7 | Rampa del mapa de calor con cuatro paradas: el grueso de los días caía en azul oscuro | Rampa de siete paradas de `brand-50` a `brand-800`; etiqueta blanca solo a partir del 55 % del rango |
| 12.8 | La prueba de agosto asumía que vendía menos; con 31 días vende más en total | La prueba calcula la variación esperada con `IndicatorCalculator` en vez de fijar el signo |
| 12.9 | Aviso de inventario vacío (11.7) y días futuros sembrados: un mes con huecos intermedios no es "parcial" | La comparación a fecha equivalente usa el último día cargado; documentado en la prueba |

Pendiente para la Fase 3: héroe con meta y proyección (G9), barra de meta en las tarjetas, línea de meta diaria en G2; después, G10 (tasa) y G11 (año), "Ampliar" en las gráficas y el consolidado multi-sede.

### Iteración 13 — Implementación de la Fase 3 (metas) con code review y pruebas en navegador

Entregado en `indicadores/`:

- **Dominio** (`app/Domain/Goals`): `GoalStatus` (en meta / en riesgo / fuera de meta / sin datos aún / sin meta), `GoalProgress` y `GoalProjector` (§8.2): para indicadores que suman, `actual` a la fecha de corte, `esperado` = meta × cuota de peso transcurrida, `proyección` = actual + ritmo × peso restante; con patrón semanal fiable (≥ 8 semanas de histórico) los pesos son los del patrón y, si no, lineal (RN-18). Los atípicos cuentan en el total pero no marcan el ritmo. Los ratios no acumulan: su estado sale del valor ponderado a la fecha. Umbrales configurables (`goal_on_track_pct`, `goal_at_risk_pct`). Produce además las curvas de G9 y la meta diaria de G2.
- **Acciones**: `UpsertGoal` (crea, cambia o borra la meta de sede/consolidado × indicador × mes; moneda según el indicador; bitácora) y `SuggestGoal` (promedio de los últimos tres meses con datos × (1 + `goal_growth_pct`, 5 % por defecto)).
- **Consulta** `GoalProgressQuery` → `GoalTracking`: corte en el último día cargado del mes en curso (fin de mes en meses pasados), patrón semanal con seis meses de histórico, seguimiento de los nueve indicadores con meta posible.
- **Interfaz**: pantalla **Metas** (`/metas`, permiso `goals.view`; edición con `goals.manage`) con vista **Este mes** (meta editable en línea que se guarda al perder el foco, sugerencia como placeholder, actual, esperado hoy, proyección con %, estado con icono y texto, nota del método y "Copiar de {mes anterior}") y vista **Año** (cuadrícula indicador × mes con flechas/Enter, pegado desde Excel, "Copiar {año anterior}", "+X % a todo el año", guardado en lote; una celda vacía borra). Advertencia permanente en metas en Bs. **Panel**: héroe "Septiembre va al 68 % de la meta · Al ritmo actual cierra en 94 %: faltan $ 1.200. Quedan 2 miércoles, tus días más fuertes." (o "cerró en 93 %" en meses completos) con G9 debajo; barra de meta de 4 px con marca de "esperado hoy" y texto de proyección en cada tarjeta; "Definir meta" para dirección cuando no hay meta; línea de meta diaria en G2. Los operadores no ven nada de metas.
- **Demo**: `DemoGoalsSeeder` (metas de septiembre y dos de agosto).
- **Pruebas**: 197 Pest (unitarias de `GoalProjector` con el criterio de aceptación de UC-12: al día 20 con 68 % la proyección lineal da 102 %; umbrales; atípicos; ratios; series; funcionales de acciones, consulta, política, `GoalsManager` y panel), Larastan nivel 6 en cero, Pint limpio. Recorrido Playwright ampliado a 25 pasos (héroe y G9, barras de meta, edición en línea con toast y reflejo en el panel, error en línea, cuadrícula anual con teclado, +X %, guardado en lote y borrado, móvil).

Desvíos respecto al plan y su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 13.1 | Sugerencia sobre "meses cerrados" | Sobre los últimos tres meses con datos | El cierre de mes llega en la Fase 4; sin él no habría sugerencias |
| 13.2 | `daysRemaining` excluye feriados configurados | Cuenta todos los días futuros | No existe calendario de feriados (P17 pendiente) |
| 13.3 | Fecha de corte "hoy o último día cargado" | Último día cargado del mes en curso | Comparar "esperado a hoy" con un actual al que le faltan días castigaba sin motivo; el aviso de faltantes ya lo dice |

Hallazgos del code review y del recorrido en navegador (corregidos):

| # | Hallazgo | Corrección |
|---|---|---|
| 13.4 | Cada aviso (toast) salía duplicado: Livewire 3 ya emite `dispatch()` como evento de navegador y además había un puente en JS que lo reemitía | Puente eliminado; el layout escucha el evento nativo |
| 13.5 | La cuadrícula anual mostraba metas viejas tras editar en la vista del mes | Ambas vistas se recargan al cambiar de vista y tras cada guardado; prueba de regresión |
| 13.6 | La página de metas desbordaba en horizontal: las etiquetas `sr-only` (posición absoluta) de las celdas se salían del contenedor con scroll | Contenedor `relative` |
| 13.7 | Todas las metas aparecían con borde rojo: `$errors->first()` devuelve cadena vacía, no null | Se comprueba `$errors->has()` |
| 13.8 | En un mes completo la marca "Hoy" de G9 se solapaba con la etiqueta de la meta | La marca solo se dibuja con el mes en curso |

Pendiente para la Fase 4: cierre y reapertura de mes desde la interfaz (con el recordatorio de cierre), pulido de roles, restilizar el acceso, deploy. Después: G10, G11, importador, PDF y el consolidado multi-sede.

### Iteración 14 — Implementación de la Fase 4 (cierre de mes, roles y pulido) con code review y pruebas en navegador

Entregado en `indicadores/`:

- **Cierre y reapertura (UC-07, RN-13, RN-14)**: `CloseMonth` (exige mes iniciado con al menos un día; con días faltantes pide confirmación explícita y lo anota en el motivo; no cierra dos veces) y `ReopenMonth` (motivo obligatorio de 5+ caracteres, solo sobre un mes cerrado, aviso por correo `MonthReopened` a dirección y administración). `PeriodEvent` queda también en la bitácora general. En la pantalla del mes: botón "Cerrar {mes}" (`periods.close`) y "Reabrir" (`periods.reopen`) con los dos modales permitidos por §13.5, insignia "Cerrado el 05/10" e **Historial del mes** (quién, cuándo y motivo). El formulario del día cerrado queda inerte y dice desde cuándo, con enlace a reabrir o a pedir la reapertura.
- **Roles y candados**: el operador fuera de su ventana de edición (`operator_edit_window_days`) ve el día en solo lectura con la explicación, en vez de un 403. Supervisión y dirección borran un día con confirmación; el aviso ofrece **Deshacer** durante 10 s (`UndoDeleteDailyRecord` recrea el registro desde el snapshot de la bitácora, ventana de 15 minutos, sin duplicar). "Última edición: quién y cuándo" en el formulario de edición.
- **Acceso con la identidad**: layout de invitado y páginas de entrar, recuperar, restablecer, verificar y confirmar contraseña con la marca (cruz azul, "Farmacia Guadalupe · Indicadores del mes"), en español y con los componentes del sistema. Mensajes de autenticación, contraseñas y validación en `lang/es`.
- **Preparación del despliegue (§4.6)**: `.env.production.example` con los pasos, HTTPS forzado en producción, cierre de sesión por controlador (para `route:cache`), WAL para SQLite en desarrollo. Falta ejecutar el despliegue cuando el cliente entregue el hosting.
- **Pruebas**: 209 Pest (acciones de cierre/reapertura con sus reglas y notificación; política por rol; pantalla del mes con cierre, confirmación de faltantes, reapertura con motivo, historial y deshacer; formulario cerrado, solo lectura del operador y borrado con deshacer; acceso en español), Larastan nivel 6 en cero, Pint limpio. Recorrido Playwright ampliado a 29 pasos (acceso con marca, cerrar con confirmación, formulario inerte, reabrir con motivo, historial con dos entradas, borrar y deshacer).

Desvíos respecto al plan y su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 14.1 | Recordatorio de cierre el día 1 (banner a supervisión) | El aviso "{mes anterior} no está cerrado" ya vive en el panel todos los días | Más útil que un banner de un día; la Ampliación A podrá añadir el correo |
| 14.2 | 2FA opcional para dirección/admin | No incluido | Requiere Fortify (dependencia nueva); se propone al cliente con el hosting |
| 14.3 | Despliegue a producción | Preparado, no ejecutado | Sin hosting ni dominio del cliente todavía |

Hallazgos del code review y del recorrido en navegador (corregidos):

| # | Hallazgo | Corrección |
|---|---|---|
| 14.4 | Al borrar un día quedaban dos eventos "deleted" en la bitácora (el manual con snapshot y el automático del trait, vacío) y "Deshacer" leía el vacío | El borrado desactiva el registro automático y conserva solo el snapshot |
| 14.5 | La fecha del snapshot se serializaba como instante UTC: al restaurar en America/Caracas caía en el día anterior | El snapshot guarda la fecha como `Y-m-d` |
| 14.6 | `@if` dentro de un atributo de componente Blade no compila (misma familia que el `@js` de 11.2) | Expresión PHP en el atributo |
| 14.7 | Los errores de validación de una petición anterior persistían en el modal de reapertura | Se limpia el error de la acción antes de reintentar |
| 14.8 | El recorrido asumía un mes sin días faltantes para cerrar | El paso marca la confirmación cuando hace falta, como haría la persona |

Pendiente (fuera del MVP): 2FA, tabla de metas apilada en móvil, perfil de usuario con la identidad, Ampliaciones A y B (§17).

### Iteración 15 — Implementación de la Fase 5 (administración, tasa BCV y perfil) con code review y pruebas en navegador

Entregado en `indicadores/`:

- **Administración (UC-17, §15.1)** en `/administracion`, solo `admin.manage`, con cuatro pestañas. **Usuarios**: crear y editar (nombre, correo, rol con explicación en palabras del negocio, sedes), contraseña inicial sugerida y legible (sin 0/O ni 1/l) o escrita a mano, credenciales mostradas una sola vez con botón de copiar, cambiar contraseña, desactivar y reactivar. Reglas: nadie se desactiva a sí mismo, nunca queda el sistema sin administrador activo, los usuarios nacen verificados (no dependen del correo). Un usuario desactivado no entra aunque su contraseña sea correcta y, si tenía sesión abierta, la pierde en la siguiente petición con la explicación en pantalla. **Sedes**: nombre, código, razón social, jornadas por defecto, días con conteo de inventario, umbral propio de venta, activa o inactiva (siempre queda una activa). **Parámetros**: umbrales de advertencia, ventana del operador, crecimiento sugerido, umbrales de metas, moneda de metas, margen bruto y nombre del sistema, cada uno con su ayuda; solo se guarda lo que cambia. **Bitácora**: quién hizo qué, a qué y con qué cambios, en frases ("Ana editó el día 03/09/2025", "Luis cerró el mes septiembre 2025"), con filtros por familia y por persona y carga progresiva.
- **Tasa BCV (UC-16, §9.4)** en `/tasas` para `rates.manage`: tabla del mes con origen (BCV, Manual, Arrastrada del vie 05/09), variación diaria y quién la fijó; edición en línea con Enter y Esc; gráfica del mes; estado del proveedor (última consulta, último éxito, último error) que ahora persiste; "Consultar ahora" (hoy y el siguiente día hábil) y "Recalcular el mes" con confirmación que anticipa cuántos días cambiarían (RN-06). Aviso permanente cuando hay días cargados con una tasa distinta a la de la tabla.
- **Perfil** con la identidad y en español: el usuario cambia su nombre y su contraseña; el correo y el rol los gestiona Administración (evita quedarse fuera por una verificación pendiente). Se retiró el borrado de la propia cuenta.
- **Menú**: "Tasa BCV" y "Administración" dejan de estar en "Próximamente"; solo aparecen a quien tiene el permiso.
- **Demo**: `DemoUsersSeeder` con un usuario por rol (operador, supervisión, dirección; contraseña `password`).
- **Pruebas**: 228 Pest (acciones de administración y sus reglas; pantalla de administración con creación, validación, credenciales, sedes, parámetros y bitácora; pantalla de tasas con edición en línea, proveedor simulado y recálculo; usuario desactivado; perfil), Larastan nivel 6 en cero, Pint limpio. Recorrido Playwright ampliado a 35 pasos (seis nuevos: usuarios, contraseñas y activación, sedes y parámetros, bitácora, tasas, perfil, y un segundo navegador que entra con el usuario nuevo y pierde la sesión al desactivarlo).

Desvíos respecto al plan y su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 15.1 | El usuario cambia su correo desde el perfil | Solo Administración cambia correos | Cambiar el correo obliga a verificarlo de nuevo; sin SMTP configurado dejaría al usuario fuera |
| 15.2 | Notificación al administrador cuando el BCV se desvía más del umbral | Se registra en el estado del proveedor y en la bitácora de la tasa; el correo llega con la Fase 7 (SMTP) | No hay correo configurado todavía |

Hallazgos del code review y del recorrido en navegador (corregidos):

| # | Hallazgo | Corrección |
|---|---|---|
| 15.3 | El middleware de acceso activo cerraba la sesión de usuarios creados por factory (atributo ausente en memoria) | Comprueba `false` explícito y la factory declara `is_active` |
| 15.4 | Comillas dobles dentro de una expresión Blade en un atributo de componente rompían la compilación de la pantalla de tasas | La edición en línea recibe solo la fecha y el componente busca la tasa vigente |
| 15.5 | Limpiar el último error del proveedor intentaba guardar `null` en una columna obligatoria | `Setting::forget()` borra la fila y la lectura vuelve al defecto |

### Iteración 16 — Implementación de la Fase 6 (importador, año y reporte PDF) con code review y pruebas en navegador

Entregado en `indicadores/`:

- **Importador de histórico (UC-15, §10)** en `/importar` para `imports.run`, en tres pasos. **Archivos**: sede, zona de arrastre, hasta 24 `.xlsx` de 5 MB, sin macros; el mes se lee del contenido, no del nombre. **Revisión**: por cada archivo, mes detectado, filas, razón social y las anomalías agrupadas por gravedad (debe resolverse, revisar, aviso, información), cada una con su decisión en palabras del negocio (omitir el día, registrarlo como cerrado, usar la primera o la última fila, marcar atípico, conservar la tasa registrada, reemplazar el mes ya cargado, no importar el archivo), vista previa con los derivados recalculados y casilla "No importar" por archivo. Las anomalías altas no traen decisión por defecto: el botón dice "Faltan N anomalías por resolver" hasta que la persona elige. **Confirmación**: resumen por archivo (días nuevos, actualizados, cerrados, atípicos, filas omitidas), enlace al mes y opción de cerrar los meses al importar. El `WorkbookParser` lee el archivo real del cliente (hoja "indicadores", mes en B2, razón social en C2, fila de encabezados por su texto, filas hasta la primera sin fecha) y el `AnomalyDetector` aplica el catálogo de §10.3 con el contexto de la base (meses ya cargados, tasas registradas, umbrales). Reimportar el mismo archivo exige decidir entre reemplazar u omitir; un mes cerrado no se toca (RN-13): pide reabrirlo. Todo queda en bitácora ("Ana importó el mes Septiembre 2025 desde el archivo CUADRO.xlsx (30 días)").
- **Año (UC-13)** en `/anio`: la tabla anual del Excel (§2.4) con los 12 indicadores en su orden, doce meses, columna "Año" ponderada sobre todos los días (nunca promedio de promedios), meses sin datos en gris, variación frente al mes anterior y frente al año pasado al pasar el cursor, selector de año con flechas, conmutador de moneda respetado y exportación a Excel. **Gráficas**: pestañas "Tasa" (G10, venta en dólares en barras y tasa en línea sobre un segundo eje) y "Año" (G11, barras del año y del anterior por mes, con selector de indicador en la URL); desaparece "Próximamente".
- **Exportación (UC-14, §11.1)**: el libro del mes trae ahora tres hojas: Indicadores (el cuadro), Anual (la tabla del año, 12×12 más "Total / Prom. año") y Tasas (fecha, tasa, origen, quién la fijó). Libro del año aparte desde la pantalla Año.
- **Reporte PDF mensual (§11.2)**: A4 apaisado con encabezado (mes, sede, días cargados, tasa de inicio a fin), tarjetas de KPI con variación y meta, gráficas del panel, cuadro de 14 columnas con totales ponderados, tabla de metas y observaciones de días atípicos o cerrados. El navegador manda las gráficas en pantalla como PNG justo antes de pedir el PDF (validadas: solo PNG, hasta 12 y 1 MB cada una, guardadas 10 minutos por usuario y mes, consumidas al generar); si no llegan, el PDF sale igual con una nota.
- **Pruebas**: 258 Pest (lector del archivo real, detector con cada anomalía, acciones de importar y confirmar con sus decisiones, asistente con subida real, consulta y pantalla anual, G10 y G11, exportación multi-hoja y anual, PDF con imágenes válidas e inválidas), Larastan nivel 6 en cero, Pint limpio. Recorrido Playwright ampliado a 39 pasos (cinco nuevos: pestañas Tasa y Año, pantalla Año con exportación y navegación, descarga del PDF con las gráficas enviadas, e importación del cuadro real con decisión de reemplazo y verificación en bitácora).

Desvíos respecto al plan y su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 16.1 | El archivo se guarda en `storage/app/imports/{batch}` y se procesa en cola | Se lee al subirlo, sin cola ni copia guardada; solo queda el hash y el contenido interpretado (`parsed_payload`) | Los cuadros pesan menos de 100 KB y se leen en menos de un segundo; el hosting compartido no tiene worker de cola |
| 16.2 | Las gráficas del PDF se renderizan en el servidor | El navegador envía las gráficas que ya tiene dibujadas | Evita instalar un navegador sin cabeza en el hosting; el PDF sale igual sin ellas |
| 16.3 | Envío programado del PDF por correo | Pasa a la Fase 7 | Necesita el SMTP del cliente |

Hallazgos del code review y del recorrido en navegador (corregidos):

| # | Hallazgo | Corrección |
|---|---|---|
| 16.4 | El nombre temporal de Livewire lleva metadatos codificados y el lector de Excel no lo reconocía | El asistente lee desde una copia limpia con extensión `.xlsx` |
| 16.5 | El archivo real no guarda los valores calculados de las fórmulas, así que los derivados del archivo llegan vacíos | El detector compara derivados solo cuando el archivo los trae; los derivados siempre se recalculan (RN-04) |
| 16.6 | Confirmar una importación sobre un mes cerrado lo modificaba sin aviso | `ConfirmImport` rechaza el mes cerrado y explica que dirección debe reabrirlo |
| 16.7 | Un año fuera de rango en la URL o por las flechas dejaba la pantalla Año en blanco | Vuelve al año del período activo |
| 16.8 | La suite completa agotaba la memoria por defecto de PHP al generar tres PDF en un solo proceso | `memory_limit` de 512 MB para las pruebas; un PDF solo cabe en 64 MB, así que producción sigue con 128 MB |
| 16.9 | Las gráficas se dibujan en SVG, así que "PNG" descargaba un SVG con extensión `.png` y el PDF rechazaba las imágenes (llegaban cero) | La gráfica se rasteriza en el navegador a un PNG real a 2× con fondo blanco, tanto para la descarga como para el PDF |
| 16.10 | Reemplazar un mes ya cargado borraba la marca de atípico y la nota puestas a mano (el recorrido lo detectó al perder el día atípico de la demostración) | Al reemplazar se conservan estado y nota; solo un día cerrado que ahora trae venta pasa a normal |
| 16.11 | En el PDF, el título "Gráficas" quedaba solo al pie de la primera página y el acumulado se veía diminuto a media página | Las gráficas van en su propia página y el acumulado ocupa el ancho completo |

### Iteración 17 — Implementación de la Fase 7 (amigabilidad y entrega) con code review y pruebas en navegador

Entregado en `indicadores/`:

- **Ayuda contextual (§13.5)**: botón "?" en la barra (o la tecla `?`) abre un panel lateral con la ayuda de la pantalla activa, las fórmulas en palabras de los 12 indicadores, el glosario de siete términos y los atajos; nunca navega. En cada tarjeta KPI, "¿Cómo se calcula?" abre un popover con la fórmula y el valor del último día cargado.
- **Formulario (§13.8)**: borrador automático por sede y fecha en el navegador (se recupera con aviso y "Descartar"; se limpia al guardar), sesión vencida y red caída con mensaje amable en lugar de la pantalla de error, indicador "Sin conexión", carga en secuencia de los días atrasados ("Cargar los 3 faltantes" → "Faltante 1 de 3", anterior/siguiente/salir; al guardar sigue con el próximo), y "Deshacer" de 10 s al marcar un día como atípico (conserva la observación).
- **Mes (UC-09)**: ordenar por cualquier columna (clic en el encabezado, `aria-sort`), buscar por fecha ("16", "16/09", "mar") sin tocar los totales, flechas para moverse por el calendario, botón "Imprimir" y hoja de impresión (sin menús ni barras) también en el Panel.
- **Gráficas**: "Ampliar" abre la gráfica a pantalla completa (segundo lienzo, Esc cierra). **Metas**: la cuadrícula anual se apila en móvil (una tarjeta por indicador con sus doce meses). Atajos `Alt+N` y `?`.
- **Correo (§11.2, §13.8, RN-16)**: Administración › Correo define el día del reporte mensual (0 apaga) y sus destinatarios, y el recordatorio de cierre. `reports:send-monthly` (a diario, actúa solo ese día) envía el PDF del mes anterior por sede con el resumen en el cuerpo; `periods:remind-close` (día 1) avisa a quien puede cerrar si el mes anterior tiene faltantes o sigue abierto; la tasa BCV desviada más del umbral avisa por correo a quien gestiona tasas.
- **Tasa BCV (§9.2, §9.3)**: histórico oficial desde los libros trimestrales del BCV (`rates:backfill`, botón "Traer histórico del BCV"), y arrastres de más de una semana marcados como viejos (insignia roja, advertencia al guardar, aviso en el panel).
- **Entrega (§15.2, §4.6)**: cabeceras de seguridad en toda respuesta (`nosniff`, `SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, HSTS con HTTPS); `db:backup` diario (mysqldump comprimido o `VACUUM INTO` en SQLite, 30 días de retención); `demo:clear` para dejar el sistema listo para la carga real; `rates:fetch` para probar el BCV en el servidor (probado desde desarrollo: la API responde 807,39 al 04-09-2026); `docs/DESPLIEGUE.md` y `docs/MANUAL-USUARIO.md` con capturas.
- **Pruebas**: 274 Pest (ayuda por pantalla, orden y búsqueda del cuadro, deshacer atípico, secuencia de faltantes, borrador, correo programado y sus reglas, recordatorio de cierre, aviso de tasa, `rates:fetch`, cabeceras, respaldo, limpieza), Larastan nivel 6 en cero, Pint limpio. Recorrido Playwright ampliado a 46 pasos (siete nuevos: ayuda y atajos, gráfica ampliada, orden y búsqueda con flechas en el calendario, borrador recuperado, atípico con deshacer, secuencia de faltantes, parámetros de correo).

Desvíos respecto al plan y su motivo:

| # | Planificado | Real | Motivo |
|---|---|---|---|
| 17.1 | 2FA opcional para dirección con Fortify | No incluida | Es una dependencia nueva del stack de acceso; se decide con el cliente si la quiere |
| 17.2 | Respaldo con `spatie/laravel-backup` | Comando propio `db:backup` | Evita una dependencia más; el hosting copia la carpeta de respaldos afuera |
| 17.3 | Persistencia del contexto (mes, sede, moneda) en el perfil | Se mantiene en la sesión | Con sesión de base de datos dura semanas; guardarlo en el perfil no cambia la experiencia |
| 17.4 | Gráficas del reporte por correo con Browsershot | El PDF del correo va sin gráficas y con enlace al panel | Chrome no suele estar en hosting compartido; el PDF descargado desde el panel sí las lleva |

Hallazgos del code review y del recorrido en navegador (corregidos):

| # | Hallazgo | Corrección |
|---|---|---|
| 17.5 | El texto "Faltante 2 de 3" quedaba partido en dos nodos y no se podía leer como frase | Toda la frase en un solo elemento |
| 17.6 | Un viernes con conteo de inventario detiene el guardado con el aviso de inventario vacío; la secuencia debía continuar tras "Guardar de todos modos" | La secuencia se mantiene en ambos caminos de guardado |
| 17.7 | `VACUUM INTO` no corre dentro de una transacción (la de las pruebas) y la ruta de Windows necesitaba barras normales | Opción `--connection` para respaldar cualquier conexión y ruta normalizada |
| 17.8 | El panel de ayuda oculto seguía en el DOM y duplicaba textos de la pantalla ("Avisos del mes", nombres de indicadores) para lectores de pantalla, "buscar en la página" y el recorrido automatizado | El panel solo existe en el DOM mientras está abierto |
| 17.9 | El borrador restaurado se muestra ya formateado ("12.345,00") aunque se escribió "12345" | Comportamiento esperado: el campo formatea al perder el foco; el recorrido acepta ambos |
| 17.10 | Sin cron (desarrollo, o un servidor recién instalado), la tasa se arrastraba durante meses en silencio: el formulario proponía 177,61 de septiembre 2025 cuando el BCV iba por 807 | Un arrastre de más de una semana se marca como viejo: insignia roja con fecha completa y antigüedad, advertencia que exige confirmar al guardar, aviso ámbar en el panel con acceso a "Consultar ahora" y fila en rojo en Tasa BCV |
| 17.11 | No había forma de traer las tasas de meses pasados (las API comunitarias no dan histórico) | Se leen los libros trimestrales oficiales del BCV ("Tipo de cambio de referencia", una hoja por día con la fecha de vigencia): `rates:backfill --from=2025-01-01` y el botón "Traer histórico del BCV" en Tasa BCV; solo crea los días sin tasa |
| 17.12 | Los avisos tras una redirección (día guardado, borrado, atípico) se perdían a veces: una petición intermedia del navegador consumía el `flash` de sesión | El aviso se guarda con `put` y el layout lo consume con `pull`: sobrevive a peticiones intermedias y se muestra una sola vez |

### Iteración 18 — Recorridos guiados con driver.js (rama `recorridos-guiados`)

Objetivo: que el sistema se explique solo, botón por botón, sin que haga falta una capacitación presencial.

Entregado en `indicadores/`:

- **driver.js 1.8** (MIT, 25 KB, sin dependencias) empaquetado con Vite. `resources/js/tour.js` monta los recorridos, resuelve las anclas `data-tour`, abre pestañas y diálogos cuando un paso lo pide (`click`, `wait`, `close`), y recuerda por usuario qué recorridos vio (`localStorage`, clave `tours-seen`). Globo con los tokens de la marca (`.tour-guadalupe`), textos "Anterior / Siguiente / Listo", progreso "3 de 24", teclado (flechas, Esc) y respeto a "reducir movimiento".
- **Definiciones en PHP** (`App\Support\GuidedTours`): un recorrido por pantalla (Panel, Cargar día, Mes, Gráficas, Metas, Año, Tasa BCV, Importar, Administración, Perfil) más el recorrido general del menú y la barra superior. Cada paso lleva título, explicación completa (qué es, para qué sirve, qué pasa después) y el permiso necesario: el operador no ve pasos de cierre, reapertura, metas ni administración. Los pasos cuyo elemento no está en pantalla (mes vacío, borrador, advertencias, pestañas del importador) se saltan solos.
- **Anclas `data-tour`** (149) en todas las vistas: menú, barra de contexto, botón de ayuda, y en cada botón, enlace, campo, pestaña, diálogo y tabla de las diez pantallas.
- **Panel de ayuda**: sección "Recorridos guiados" con "Ver el recorrido de esta pantalla", "Cómo moverte por el sistema" y la lista de los diez recorridos con la marca "visto"; cualquier pantalla arranca su recorrido con `?recorrido=1`. La primera vez que un usuario entra a una pantalla (en escritorio) el recorrido arranca solo, una vez.
- **Demostraciones con datos de ejemplo** (`resources/js/demos.js`, 34 acciones): el recorrido no solo señala, hace. En Cargar día escribe un día de ejemplo campo por campo y el panel "Se calculará" responde en vivo; en Mes busca "16" y marca "excluir atípicos"; en Gráficas abre "Datos" y cambia el indicador de la comparación anual; en Metas abre la cuadrícula y escribe una meta; en Tasa BCV abre la edición en línea y el diálogo del histórico con una fecha; en Importar sube un cuadro de ejemplo (enero 2020, ficticio, con una fecha repetida y un día faltante a propósito), lo analiza, despliega la revisión, elige una decisión y muestra la pantalla de confirmación en imagen; en Administración llena el formulario de usuario y de sede y filtra la bitácora; en Perfil escribe un nombre. Nada se guarda: cada paso deshace lo suyo al abandonarlo y cada pantalla tiene una limpieza final (`GuidedTours::cleanup`), y "Volver a empezar" del importador ahora descarta lo analizado y no confirmado. Al terminar, la observación pasó a ser obligatoria cuando el día es atípico (la pantalla ya lo prometía) y se corrigieron tres afirmaciones de los textos que no coincidían con el sistema.
- **Verificación automática** (`GuidedToursTest`): (1) cada recorrido tiene introducción, cierre en la ayuda y descripciones de al menos 40 caracteres; (2) para cada pantalla renderizada con datos: todo botón, enlace, campo, selector, área de texto y desplegable dentro de `<main>` está dentro de un ancla, cada ancla tiene su paso y cada paso apunta a un ancla real de la vista; (3) los permisos filtran pasos y catálogo; (4) el panel expone los pasos. Con eso, agregar un botón sin recorrido rompe la suite.
- **Recorrido Playwright**: dos pasos nuevos: los diez recorridos se ejecutan hasta el final desde el panel de ayuda (con los diálogos que abren y cierran) y, en un navegador nuevo, el recorrido arranca solo en la primera visita, se marca como visto y no vuelve a aparecer.

- **Video de demostración completo** (`videos/sistema-completo/`, método de `COMO-CREAR-VIDEOS-DE-CAPACITACION.md`): 32 escenas narradas (voz es-VE), unos 13 minutos, grabadas con Playwright a 1920×1080 con cursor visible sobre una copia desechable de la base (`prep_escenario.php` deja el mes en curso con días cargados y uno cerrado). Muestra cada módulo con datos de prueba: entra, recorre el panel y la barra de contexto, carga un día completo, cierra y reabre un mes, exporta, recorre las gráficas, fija metas, edita una tasa, importa el cuadro de ejemplo hasta confirmar, crea un usuario, revisa la bitácora y termina en la ayuda con un recorrido guiado en marcha. El MP4 se entrega aparte (no va al repositorio); `videos/sistema-completo/README.md` explica cómo regenerarlo.
Desvíos y decisiones:

| # | Decisión | Motivo |
|---|---|---|
| 18.1 | driver.js en lugar de Shepherd o Intro.js | Los otros dos exigen licencia comercial para uso interno en una empresa; driver.js es MIT y pesa cinco veces menos |
| 18.2 | "Visto" en el navegador, no en la base | No exige migración ni tocar el perfil; si el usuario cambia de equipo, el recorrido se ofrece una vez más, que es inofensivo |
| 18.3 | Los diálogos se recorren abriéndolos y cerrándolos con "Cancelar" | driver.js solo resalta lo que existe en pantalla; así el recorrido explica también lo que hay dentro de cada diálogo sin ejecutar nada |
| 18.4 | Sin arranque automático en móvil | El menú lateral está oculto y el globo taparía el formulario; el recorrido sigue disponible desde la ayuda |

### Estado final

El plan cubre las 14 columnas, las 163 fórmulas, los 7 gráficos, la tabla anual, los 13 hechos verificados y los 3 pedidos del cliente (digitalizar, estadística y gráficas, KPIs con metas), más la preparación multi-sede. El estado de construcción por caso de uso está en §21.

---

## 21. Estado del producto al 04-09-2026

Qué hay construido y probado (274 pruebas Pest, Larastan nivel 6, recorrido Playwright de 46 pasos) y qué falta, por caso de uso. "Hecho" significa implementado, con pruebas y verificado en navegador.

| UC | Caso de uso | Estado | Falta |
|---|---|---|---|
| 01 | Iniciar sesión | Hecho | 2FA opcional (requiere Fortify; a decidir con el cliente) |
| 02 | Cargar el día | Hecho (borrador, red caída, secuencia de faltantes) | — |
| 03 | Editar un día | Hecho | — |
| 04 | Marcar día atípico | Hecho (con Deshacer) | — |
| 05 | Registrar día cerrado | Hecho | — |
| 06 | Ver días faltantes | Hecho | — |
| 07 | Cerrar y reabrir mes | Hecho (recordatorio por correo el día 1) | — |
| 08 | Panel principal | Hecho (con "¿Cómo se calcula?" e impresión) | — |
| 09 | Cuadro de indicadores | Hecho (ordenar, buscar, imprimir) | — |
| 10 | Gráficas | Hecho G1–G11 (con "Ampliar") | — |
| 11 | Definir metas | Hecho (cuadrícula apilada en móvil) | — |
| 12 | Seguir metas | Hecho | — |
| 13 | Comparativa anual | Hecho | — |
| 14 | Exportar | Hecho (Excel de tres hojas, Excel del año, PDF mensual, envío programado por correo) | SMTP del cliente para activarlo |
| 15 | Importar histórico | Hecho | — |
| 16 | Gestionar tasa BCV | Hecho (histórico oficial del BCV, arrastre viejo señalado, aviso por correo si se desvía) | SMTP del cliente para el aviso |
| 17 | Administración | Hecho | — |
| 18 | Consolidado multi-sede | Preparado en el modelo | Selector y consolidado en UI (Fase 8, condicionada) |

Transversal hecho: identidad visual completa (perfil incluido), acceso en español, roles y políticas, bitácora visible, cache, tasa BCV automática programada, exportación Excel y PDF, importación del histórico, seeders de demostración (septiembre real, agosto sintético, metas, usuarios por rol). Transversal hecho en la Fase 7: ayuda contextual y atajos, cabeceras de seguridad, respaldo diario, limpieza de la demostración, guía de despliegue y manual con capturas. Transversal pendiente (depende del cliente): hosting, SMTP, ejecutar el despliegue y capacitación. Cada requerimiento tiene componente, caso de uso y prueba asignados (§18). Las decisiones que dependen del cliente están aisladas en §19.
