<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Indicators\Indicator;

/**
 * Ayuda contextual (§13.5): qué muestra cada pantalla, cómo se calcula cada cifra y el glosario
 * de los términos fijos de la interfaz (§13.6). Nunca saca al usuario de donde está.
 */
final class HelpContent
{
    /**
     * @return array{title: string, intro: string, items: list<string>}
     */
    public static function for(?string $route): array
    {
        return match ($route) {
            'dashboard' => [
                'title' => 'Panel',
                'intro' => 'El estado del mes de un vistazo: cuánto se vendió, cómo va frente a la meta y qué falta por cargar.',
                'items' => [
                    'El héroe compara la venta en dólares con la meta del mes y proyecta el cierre con el patrón de cada día de la semana.',
                    'Cada tarjeta muestra el valor del mes, la variación frente al mes anterior (mismos días) y una línea con los últimos 14 días.',
                    'Los promedios son ponderados: se calculan con las sumas del mes, no promediando los días.',
                    '"Descargar PDF" arma el reporte del mes con las gráficas que ves en pantalla.',
                    'Los avisos del mes señalan días sin cargar, tasas arrastradas y meses anteriores sin cerrar.',
                ],
            ],
            'records.create' => [
                'title' => 'Cargar día',
                'intro' => 'Se escriben solo los datos primarios; el sistema calcula el resto y lo muestra a la derecha antes de guardar.',
                'items' => [
                    'La tasa BCV se propone sola. Si un día no hay publicación (fines de semana), se arrastra la última.',
                    'Las advertencias en ámbar no bloquean: revisa el dato o guarda de todos modos.',
                    'Lo que escribes queda guardado en este navegador hasta que el día se guarde en el sistema.',
                    'Un día atípico (corte de luz, media jornada) cuenta en los totales, pero no en los promedios ni en la proyección.',
                    'Si la farmacia no abrió, regístralo como día cerrado para que no cuente como faltante.',
                ],
            ],
            'month' => [
                'title' => 'Mes',
                'intro' => 'El calendario dice qué días faltan y el cuadro reproduce el Excel con totales ponderados.',
                'items' => [
                    'Haz clic en cualquier día del calendario para cargarlo o editarlo. Con el teclado, muévete con las flechas.',
                    'Ordena el cuadro haciendo clic en un encabezado; busca un día escribiendo su número o fecha.',
                    'La fila de totales suma ventas, transacciones, unidades y jornadas, y pondera los promedios.',
                    'Cerrar el mes lo deja en solo lectura; dirección puede reabrirlo con un motivo.',
                    '"Imprimir" prepara la página sin menús para papel o PDF del navegador.',
                ],
            ],
            'charts' => [
                'title' => 'Gráficas',
                'intro' => 'Las mismas gráficas del Excel, por familia, siempre del mes y la sede elegidos arriba.',
                'items' => [
                    '"Datos" muestra la tabla detrás de la gráfica; "PNG" la descarga; "Ampliar" la abre a pantalla completa.',
                    'En Ventas, la moneda elegida decide qué gráfica va primero.',
                    'La pestaña Tasa cruza la venta en dólares con la tasa BCV del mes.',
                    'La pestaña Año compara cada mes con el mismo mes del año anterior.',
                ],
            ],
            'goals' => [
                'title' => 'Metas',
                'intro' => 'Una meta por indicador y mes. El sistema mide el avance y proyecta el cierre.',
                'items' => [
                    'La sugerencia parte del mes anterior más el crecimiento configurado en Administración.',
                    '"Esperado hoy" es lo que debería llevar el mes a la fecha según el patrón semanal.',
                    'En la vista Año se editan doce meses a la vez; se puede pegar desde Excel y aplicar +X % a una fila.',
                    'Verde, ámbar y rojo dependen de los umbrales de Administración.',
                ],
            ],
            'annual' => [
                'title' => 'Año',
                'intro' => 'La tabla anual del Excel: doce meses y la columna del año, ponderada sobre todos los días.',
                'items' => [
                    'Pasa el cursor por una celda para ver la variación frente al mes anterior y frente al año pasado.',
                    'Los meses sin datos quedan en gris; importa los cuadros anteriores para completarlos.',
                    '"Exportar a Excel" descarga la tabla del año.',
                ],
            ],
            'rates' => [
                'title' => 'Tasa BCV',
                'intro' => 'La tasa de cada día con su origen. Se consulta sola dos veces al día; aquí se corrige a mano.',
                'items' => [
                    'Corregir una tasa no cambia los días ya cargados: usa "Recalcular el mes" y revisa la vista previa.',
                    '"Consultar ahora" pide la tasa de hoy y la del siguiente día hábil.',
                    'Si el BCV se desvía más del umbral, se avisa al administrador por correo.',
                ],
            ],
            'imports' => [
                'title' => 'Importar',
                'intro' => 'Trae los cuadros anteriores en Excel sin perder nada. Nada entra sin revisarlo.',
                'items' => [
                    'El mes se lee del contenido del archivo, no del nombre.',
                    'Las anomalías "debe resolverse" exigen una decisión; el resto trae una propuesta.',
                    'Un mes ya cargado se puede reemplazar; las marcas de atípico puestas a mano se conservan.',
                ],
            ],
            'admin' => [
                'title' => 'Administración',
                'intro' => 'Quién entra, con qué rol, en qué sede, y los parámetros que gobiernan advertencias, metas y correos.',
                'items' => [
                    'Cada rol se explica al elegirlo. Nadie se desactiva a sí mismo y siempre queda un administrador.',
                    'Los parámetros de correo definen el día del reporte mensual y a quién llega.',
                    'La bitácora cuenta en frases quién hizo qué y cuándo.',
                ],
            ],
            'profile' => [
                'title' => 'Tu perfil',
                'intro' => 'Tu nombre y tu contraseña. El correo y el rol los gestiona Administración.',
                'items' => ['Si el administrador te dio una contraseña temporal, cámbiala aquí.'],
            ],
            default => [
                'title' => 'Ayuda',
                'intro' => 'Indicadores de Farmacia Guadalupe: carga diaria, cuadro del mes, gráficas y metas.',
                'items' => ['Usa el menú de la izquierda para moverte entre pantallas.'],
            ],
        };
    }

    /**
     * Fórmulas en palabras de cada indicador (§13.5 "¿Cómo se calcula?").
     *
     * @return list<array{label: string, explanation: string}>
     */
    public static function formulas(): array
    {
        return array_map(fn (Indicator $i) => ['label' => $i->label(), 'explanation' => $i->explanation()], Indicator::annualOrder());
    }

    /**
     * Glosario de los siete términos fijos (§13.6).
     *
     * @return list<array{term: string, definition: string}>
     */
    public static function glossary(): array
    {
        return [
            ['term' => 'Día', 'definition' => 'El registro de un día de operación: venta, tasa, transacciones, unidades, jornadas e inventario.'],
            ['term' => 'Mes', 'definition' => 'Los días de un mes calendario para una sede. Se cierra cuando está completo y ya no se edita.'],
            ['term' => 'Meta', 'definition' => 'El valor que se quiere alcanzar en un indicador durante un mes.'],
            ['term' => 'Tasa BCV', 'definition' => 'Bolívares por dólar que publica el Banco Central de Venezuela; convierte la venta a dólares.'],
            ['term' => 'Transacciones', 'definition' => 'Cantidad de ventas cobradas en el día (tickets o facturas).'],
            ['term' => 'Unidades', 'definition' => 'Cantidad de productos vendidos en el día.'],
            ['term' => 'Jornadas', 'definition' => 'Turnos trabajados en el día; sirven para medir transacciones por jornada.'],
        ];
    }

    /**
     * Atajos de teclado (§13.8).
     *
     * @return list<array{keys: string, action: string}>
     */
    public static function shortcuts(): array
    {
        return [
            ['keys' => 'Alt + N', 'action' => 'Cargar día'],
            ['keys' => '?', 'action' => 'Abrir o cerrar esta ayuda'],
            ['keys' => 'Enter', 'action' => 'Guardar el formulario'],
            ['keys' => 'Esc', 'action' => 'Cerrar ventanas y paneles'],
            ['keys' => 'Flechas', 'action' => 'Moverse por el calendario del mes y la cuadrícula de metas'],
        ];
    }
}
