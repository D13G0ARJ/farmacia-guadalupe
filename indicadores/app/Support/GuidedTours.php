<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Recorridos guiados (§13.5, driver.js): cada pantalla explica, botón por botón, qué hace cada cosa.
 *
 * Cada paso apunta a un `data-tour="clave"` de la vista. Campos:
 *  - element: clave del data-tour (null = paso centrado, sin elemento).
 *  - title / description: en español, con el "para qué" y el "qué pasa después".
 *  - side / align: posición del globo (por defecto abajo, inicio).
 *  - can: permiso necesario para que el paso aparezca (los demás lo saltan).
 *  - click: clave de un data-tour que hay que pulsar antes de resaltar (abrir una pestaña o un diálogo).
 *  - close: true si al abandonar el paso hay que cerrar lo que se abrió (Esc).
 *  - wait: milisegundos que se espera a que aparezca el elemento tras el clic.
 *  - demo: demostración con datos de ejemplo que corre antes de mostrar el paso (resources/js/demos.js).
 *  - undo: demostración que deshace la anterior al abandonar el paso.
 * Los pasos cuyo elemento no está en pantalla (rol sin permiso, mes vacío, móvil) se saltan solos.
 * Ninguna demostración guarda nada en el servidor; `cleanup()` deja la pantalla como estaba al terminar.
 */
final class GuidedTours
{
    /**
     * Catálogo de recorridos: uno por pantalla, en el orden del menú.
     *
     * @return list<array{route: string, label: string, summary: string}>
     */
    public static function catalog(User $user): array
    {
        $all = [
            ['route' => 'dashboard', 'label' => 'Panel', 'summary' => 'El estado del mes, las tarjetas, las gráficas y los avisos.'],
            ['route' => 'records.create', 'label' => 'Cargar día', 'summary' => 'Cada campo del día, las advertencias y qué pasa al guardar.', 'can' => 'records.create'],
            ['route' => 'month', 'label' => 'Mes', 'summary' => 'El calendario, el cuadro de indicadores, cerrar y reabrir.'],
            ['route' => 'charts', 'label' => 'Gráficas', 'summary' => 'Las pestañas y qué hace cada botón de una gráfica.'],
            ['route' => 'goals', 'label' => 'Metas', 'summary' => 'Definir metas por mes o por año y leer el seguimiento.', 'can' => 'goals.view'],
            ['route' => 'annual', 'label' => 'Año', 'summary' => 'La tabla anual, la columna del año y la exportación.'],
            ['route' => 'rates', 'label' => 'Tasa BCV', 'summary' => 'Consultar, corregir, traer el histórico y recalcular.', 'can' => 'rates.manage'],
            ['route' => 'imports', 'label' => 'Importar', 'summary' => 'Subir cuadros de Excel, revisar anomalías y confirmar.', 'can' => 'imports.run'],
            ['route' => 'admin', 'label' => 'Administración', 'summary' => 'Usuarios, sedes, parámetros, correo y bitácora.', 'can' => 'admin.manage'],
            ['route' => 'profile', 'label' => 'Tu perfil', 'summary' => 'Tu nombre y tu contraseña.'],
        ];

        return array_values(array_map(
            fn (array $t) => ['route' => $t['route'], 'label' => $t['label'], 'summary' => $t['summary']],
            array_filter($all, fn (array $t) => ! isset($t['can']) || $user->can($t['can'])),
        ));
    }

    /**
     * Pasos del recorrido de una pantalla para un usuario (ya filtrados por permiso).
     *
     * @return list<array{element: string|null, title: string, description: string, side?: string, align?: string, click?: string, close?: bool, wait?: int}>
     */
    public static function for(?string $route, User $user): array
    {
        $steps = match ($route) {
            'dashboard' => [...self::intro('Panel', 'Aquí ves el estado del mes de un vistazo. Primero, cómo moverte por el sistema; después, cada parte del panel.'), ...self::shell(), ...self::dashboard()],
            'records.create' => [...self::intro('Cargar día', 'Es lo que se hace cada día: siete números y el sistema calcula el resto. Vamos campo por campo.'), ...self::dailyForm()],
            'month' => [...self::intro('Mes', 'El calendario dice qué días faltan y el cuadro reproduce el Excel con los totales ponderados.'), ...self::month()],
            'charts' => [...self::intro('Gráficas', 'Las mismas gráficas del Excel, por familia. Cada una se puede ver como tabla, descargar o ampliar.'), ...self::charts()],
            'goals' => [...self::intro('Metas', 'Una meta por indicador y mes. El sistema mide el avance y proyecta el cierre.'), ...self::goals()],
            'annual' => [...self::intro('Año', 'La tabla anual del Excel: doce meses y la columna del año.'), ...self::annual()],
            'rates' => [...self::intro('Tasa BCV', 'La tasa de cada día con su origen. Se consulta sola; aquí se revisa, se corrige y se completa.'), ...self::rates()],
            'imports' => [...self::intro('Importar', 'Trae los cuadros anteriores en Excel sin perder nada. Nada entra sin que lo revises.'), ...self::imports()],
            'admin' => [...self::intro('Administración', 'Quién entra, con qué rol, en qué sede, y los parámetros que gobiernan el sistema.'), ...self::admin()],
            'profile' => [...self::intro('Tu perfil', 'Tu nombre y tu contraseña. El correo y el rol los gestiona Administración.'), ...self::profile()],
            default => [],
        };

        if ($steps === []) {
            return [];
        }

        $steps[] = ['element' => 'help-button', 'title' => 'La ayuda siempre a mano', 'description' => 'Con este botón (o la tecla ?) abres la ayuda de la pantalla en la que estés: qué muestra, cómo se calcula cada indicador, el glosario y los atajos. Desde ahí puedes repetir este recorrido cuando quieras.', 'side' => 'bottom', 'align' => 'end'];

        return array_values(array_map(
            function (array $step): array {
                unset($step['can']);

                return $step;
            },
            array_filter($steps, fn (array $step) => ! isset($step['can']) || $user->can($step['can'])),
        ));
    }

    /**
     * Recorrido general de la estructura (menú y barra superior), disponible desde cualquier pantalla.
     *
     * @return list<array{element: string|null, title: string, description: string, side?: string, align?: string, click?: string, close?: bool, wait?: int}>
     */
    public static function shellTour(User $user): array
    {
        $steps = [...self::intro('Cómo moverte', 'El menú de la izquierda lleva a cada pantalla y la barra superior fija el mes, la sede y la moneda para todas.'), ...self::shell()];
        $steps[] = ['element' => 'help-button', 'title' => 'La ayuda siempre a mano', 'description' => 'Con este botón (o la tecla ?) abres la ayuda de la pantalla activa y la lista de recorridos.', 'side' => 'bottom', 'align' => 'end'];

        return array_values(array_map(
            function (array $step): array {
                unset($step['can']);

                return $step;
            },
            array_filter($steps, fn (array $step) => ! isset($step['can']) || $user->can($step['can'])),
        ));
    }

    /** Demostraciones disponibles (deben existir en resources/js/demos.js). */
    public const DEMOS = [
        'dash.more', 'dash.help', 'dash.help.close',
        'form.sales', 'form.transactions', 'form.units', 'form.inventory', 'form.notes', 'form.clear',
        'month.search', 'month.search.clear', 'month.exclude', 'month.exclude.off',
        'chart.data', 'chart.data.close', 'charts.indicator', 'charts.ventas',
        'goals.year', 'goals.type', 'goals.reset',
        'rates.edit', 'rates.cancel', 'rates.backfill.date', 'dialog.cancel',
        'import.sample', 'import.expand', 'import.decide', 'import.restart',
        'admin.user', 'admin.branch', 'admin.log.search', 'admin.log.clear',
        'profile.name', 'profile.restore',
    ];

    /**
     * Demostraciones que dejan la pantalla como estaba cuando el recorrido termina o se cierra.
     *
     * @return list<string>
     */
    public static function cleanup(?string $route): array
    {
        return match ($route) {
            'dashboard' => ['dash.help.close'],
            'records.create' => ['form.clear'],
            'month' => ['month.search.clear', 'month.exclude.off'],
            'charts' => ['chart.data.close', 'charts.ventas'],
            'goals' => ['goals.reset'],
            'rates' => ['rates.cancel', 'dialog.cancel'],
            'imports' => ['import.restart'],
            'admin' => ['dialog.cancel', 'admin.log.clear'],
            'profile' => ['profile.restore'],
            default => [],
        };
    }

    /**
     * Anclas que solo existen en un estado que la página de prueba no muestra (mes vacío o cerrado,
     * secuencia de faltantes, borrador, advertencias, otras pestañas del importador…). Se saltan
     * solas en el recorrido cuando no están en pantalla.
     *
     * @return list<string>
     */
    public static function stateAnchors(string $route): array
    {
        return match ($route) {
            'dashboard' => ['dash-empty', 'dash-goal-link', 'dash-notices', 'context-branch'],
            'records.create' => ['form-sequence', 'form-locked', 'form-warnings', 'form-last-edit', 'form-delete', 'dialog-delete-day', 'form-closed-day'],
            'month' => ['month-closed-badge', 'month-reopen', 'dialog-reopen-month', 'month-empty', 'month-history'],
            'charts' => ['charts-annual-indicator'],
            'annual' => ['annual-empty'],
            'rates' => ['rates-pending'],
            'imports' => ['import-branch', 'import-review', 'import-skip', 'import-toggle', 'import-decision', 'import-preview', 'import-close-after', 'import-restart', 'import-confirm', 'import-result', 'import-more'],
            'admin' => ['admin-new-branch', 'admin-branch-card', 'dialog-branch', 'admin-issued', 'admin-settings-warnings', 'admin-settings-edit', 'admin-settings-goals', 'admin-settings-other', 'admin-settings-mail', 'admin-settings-save', 'admin-log-type', 'admin-log-search', 'admin-log-list', 'admin-log-more'],
            'goals' => ['goals-year-nav', 'goals-copy-year', 'goals-growth', 'goals-save-year', 'goals-grid', 'goals-grid-mobile'],
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private static function intro(string $title, string $description): array
    {
        return [['element' => null, 'title' => 'Recorrido: '.$title, 'description' => $description.' Avanza con "Siguiente" o con la flecha derecha; sal cuando quieras con Esc.']];
    }

    /** @return list<array<string, mixed>> */
    private static function shell(): array
    {
        return [
            ['element' => 'nav-panel', 'title' => 'Panel', 'description' => 'La pantalla de resumen: cuánto se vendió en el mes, cómo va frente a la meta, las gráficas principales y los avisos. Supervisión y dirección entran aquí al iniciar sesión.', 'side' => 'right'],
            ['element' => 'nav-cargar', 'title' => 'Cargar día', 'description' => 'La tarea diaria: se escriben la venta, la tasa, las transacciones, las unidades, las jornadas y el inventario del día. Abre directamente el primer día del mes que todavía falta.', 'side' => 'right', 'can' => 'records.create'],
            ['element' => 'nav-mes', 'title' => 'Mes', 'description' => 'El calendario con el estado de cada día (cargado, falta, atípico, cerrado) y el cuadro de indicadores completo, igual al del Excel, con la fila de totales. Desde aquí se cierra y se reabre el mes. El operador entra aquí al iniciar sesión.', 'side' => 'right'],
            ['element' => 'nav-graficas', 'title' => 'Gráficas', 'description' => 'Todas las gráficas del Excel por familia: ventas, operación, inventario, tasa y comparación anual.', 'side' => 'right'],
            ['element' => 'nav-metas', 'title' => 'Metas', 'description' => 'Definir la meta de cada indicador por mes o para todo el año y seguir el avance con la proyección de cierre.', 'side' => 'right', 'can' => 'goals.view'],
            ['element' => 'nav-anio', 'title' => 'Año', 'description' => 'La tabla anual del Excel: cada indicador por mes y el total o promedio del año, con la variación frente al mes anterior y frente al año pasado.', 'side' => 'right'],
            ['element' => 'nav-tasas', 'title' => 'Tasa BCV', 'description' => 'La tasa oficial de cada día, con su origen. Se consulta sola dos veces al día; aquí se corrige a mano, se trae el histórico y se recalcula un mes.', 'side' => 'right', 'can' => 'rates.manage'],
            ['element' => 'nav-importar', 'title' => 'Importar', 'description' => 'Para traer los cuadros de meses anteriores desde Excel: el sistema los lee, señala lo raro y tú decides antes de guardar nada.', 'side' => 'right', 'can' => 'imports.run'],
            ['element' => 'nav-admin', 'title' => 'Administración', 'description' => 'Usuarios y sus roles, sedes, parámetros de advertencias y metas, correo y la bitácora de todo lo que pasa en el sistema.', 'side' => 'right', 'can' => 'admin.manage'],
            ['element' => 'nav-perfil', 'title' => 'Tu perfil', 'description' => 'Tu nombre tal como aparece en la bitácora y el cambio de tu contraseña. Aquí también se ve con qué usuario entraste.', 'side' => 'right'],
            ['element' => 'nav-salir', 'title' => 'Salir', 'description' => 'Cierra la sesión. Lo que estabas escribiendo en el formulario del día queda guardado en este navegador y se recupera al volver.', 'side' => 'right'],
            ['element' => 'context-period', 'title' => 'El mes con el que trabajas', 'description' => 'Todas las pantallas responden a este mes: el panel, el cuadro, las gráficas y las metas. Las flechas pasan al mes anterior o siguiente; el desplegable salta a cualquiera de los últimos 24 meses. Al cambiarlo, la pantalla se actualiza sola, sin recargar.', 'side' => 'bottom'],
            ['element' => 'context-branch', 'title' => 'La sede', 'description' => 'Cuando hay más de una sede, aquí eliges cuál ver. Dirección y administración también pueden ver "Todas las sedes" (el consolidado).', 'side' => 'bottom'],
            ['element' => 'context-currency', 'title' => 'La moneda', 'description' => '"Bs" muestra todo en bolívares, "$" en dólares y "Bs y $" ambas columnas. En las tarjetas del panel la moneda elegida va en grande y la otra debajo. Se recuerda mientras dure tu sesión.', 'side' => 'bottom'],
            ['element' => 'new-day-button', 'title' => 'Cargar día, desde cualquier pantalla', 'description' => 'Este botón siempre está visible y abre el formulario en el primer día que falta. Atajo de teclado: Alt + N.', 'side' => 'bottom', 'align' => 'end', 'can' => 'records.create'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function dashboard(): array
    {
        return [
            ['element' => 'dash-title', 'title' => 'Mes y sede', 'description' => 'El encabezado confirma qué mes y qué sede estás viendo. Si cambias el mes arriba, todo lo que sigue se recalcula.', 'side' => 'bottom'],
            ['element' => 'dash-pdf', 'title' => 'Descargar PDF', 'description' => 'Arma el reporte del mes en PDF: indicadores con sus variaciones y metas, las gráficas tal como las ves en pantalla, el cuadro completo, la tabla de metas y las observaciones de los días atípicos o cerrados. Tarda unos segundos y se descarga solo.', 'side' => 'bottom', 'align' => 'end', 'can' => 'reports.export'],
            ['element' => 'dash-print', 'title' => 'Imprimir', 'description' => 'Abre el diálogo de impresión del navegador con el panel preparado para papel: sin menús ni barras. También sirve para "Guardar como PDF" desde el navegador.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'dash-empty', 'title' => 'Mes sin datos', 'description' => 'Cuando el mes elegido no tiene días cargados, el panel lo dice y ofrece cargar el primero. Elige otro mes arriba para ver datos.', 'side' => 'top'],
            ['element' => 'dash-hero', 'title' => 'El mes en una frase', 'description' => 'La línea grande resume el mes: si hay meta de venta en dólares, dice a qué porcentaje va y en cuánto cerraría al ritmo actual, calculado con lo que suele venderse cada día de la semana. Si no hay meta, muestra lo vendido hasta hoy y te invita a definir una. Debajo, la gráfica del acumulado frente a la meta: la línea azul es lo vendido día a día, la punteada morada es lo que debería llevar a cada fecha, y la gris, hacia dónde va.', 'side' => 'bottom'],
            ['element' => 'dash-goal-link', 'title' => 'Definir meta', 'description' => 'Lleva a Metas para fijar la meta de venta del mes. Con meta, el héroe y las tarjetas muestran avance y proyección.', 'side' => 'bottom', 'can' => 'goals.manage'],
            ['element' => 'dash-kpis', 'title' => 'Las cuatro tarjetas principales', 'description' => 'Venta en dólares, transacciones, ticket promedio en dólares y unidades por compra. Cada tarjeta trae el valor del mes, la variación frente al mes anterior (si el mes va a medias, compara la misma cantidad de días), una pequeña línea con los últimos 14 días y, si hay meta, la barra de avance con la marca de lo esperado a la fecha.', 'side' => 'top'],
            ['element' => 'kpi-help', 'title' => '¿Cómo se calcula?', 'description' => 'El icono de información de cada tarjeta abre la fórmula en palabras y el valor del último día cargado, como ves ahora. No sale de la pantalla: se cierra con Esc o haciendo clic fuera.', 'side' => 'bottom', 'align' => 'end', 'demo' => 'dash.help', 'undo' => 'dash.help.close'],
            ['element' => 'dash-more', 'title' => 'Los otros cuatro indicadores', 'description' => 'Al pulsar "Ver 4 indicadores más" se despliegan venta en bolívares, unidades vendidas, transacciones por jornada y valuación del inventario, con las mismas variaciones y metas. Ya quedaron abiertos para que los veas.', 'side' => 'top', 'demo' => 'dash.more'],
            ['element' => 'dash-charts', 'title' => 'Venta diaria y mapa de calor', 'description' => 'La venta en dólares de cada día (con la meta diaria en morado, si la hay; el círculo rojo marca un día atípico) y el mapa de calor por día de la semana: cuanto más oscuro, más venta. En cada gráfica: "Datos" muestra la tabla, "PNG" la descarga y "Ampliar" la abre a pantalla completa.', 'side' => 'top'],
            ['element' => 'chart-title', 'title' => 'Cada gráfica', 'description' => 'Título, subtítulo con el período y una leyenda clicable para ocultar series. Al pasar el cursor, el tooltip muestra el valor exacto en formato venezolano.', 'side' => 'bottom'],
            ['element' => 'chart-data', 'title' => 'Datos', 'description' => 'Despliega debajo de la gráfica la tabla con los mismos números, útil para leer un valor exacto o copiarlo. Acaba de abrirse: mírala debajo de la gráfica.', 'side' => 'left', 'demo' => 'chart.data', 'undo' => 'chart.data.close'],
            ['element' => 'chart-png', 'title' => 'PNG', 'description' => 'Descarga la gráfica como imagen con fondo blanco, lista para pegar en un correo o una presentación.', 'side' => 'left'],
            ['element' => 'chart-expand', 'title' => 'Ampliar', 'description' => 'Abre la gráfica a pantalla completa. Se cierra con Esc, con la X o haciendo clic fuera.', 'side' => 'left'],
            ['element' => 'dash-notices', 'title' => 'Avisos del mes', 'description' => 'Lo que necesita atención: días sin cargar (con acceso directo para cargarlos), tasa de hoy arrastrada o vieja, y meses anteriores que siguen abiertos. Cada aviso trae su acción a la derecha.', 'side' => 'top'],
            ['element' => 'dash-rate-line', 'title' => 'La tasa del mes', 'description' => 'De qué tasa a qué tasa fue el mes y cuánto varió. El enlace abre el cuadro completo.', 'side' => 'top'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function dailyForm(): array
    {
        return [
            ['element' => 'form-heading', 'title' => 'El día que estás cargando', 'description' => 'La fecha con su día de la semana, calculado por el sistema (nunca se escribe a mano). Si dice "Editar día", ese día ya estaba cargado y estás corrigiéndolo.', 'side' => 'bottom'],
            ['element' => 'form-see-month', 'title' => 'Ver el mes', 'description' => 'Vuelve al calendario y al cuadro del mes al que pertenece esta fecha, sin perder lo que hayas escrito (queda en el borrador).', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'form-sequence', 'title' => 'Carga en secuencia', 'description' => 'Cuando entras desde "Cargar los N faltantes", esta franja dice en qué faltante vas. "Anterior" y "Siguiente" saltan entre los días que faltan; "Salir" deja la secuencia. Al guardar, el sistema sigue solo con el próximo.', 'side' => 'bottom'],
            ['element' => 'form-draft', 'title' => 'Borrador recuperado', 'description' => 'Lo que escribes se guarda en este navegador mientras escribes. Si cerraste la pestaña o venció la sesión, al volver a este día se recupera y se avisa aquí; "Descartar" lo borra.', 'side' => 'bottom'],
            ['element' => 'form-locked', 'title' => 'Mes cerrado o solo lectura', 'description' => 'Si el mes está cerrado, el formulario se ve atenuado y no se puede guardar; dirección puede reabrirlo desde Mes. El operador tampoco puede editar días más viejos que su ventana de edición.', 'side' => 'bottom'],
            ['element' => 'form-date', 'title' => 'Fecha', 'description' => 'Puedes cambiarla para cargar otro día. No admite fechas futuras. Al cambiarla se vuelve a proponer la tasa y las referencias del último día cargado.', 'side' => 'right'],
            ['element' => 'form-sales', 'demo' => 'form.sales', 'title' => 'Venta del día (Bs)', 'description' => 'El total vendido en bolívares. Acabamos de escribir un ejemplo, 95.480,50, para que veas cómo reacciona el formulario. Acepta coma o punto; al salir del campo se formatea. Debajo se ve la venta del último día cargado como referencia. Si se aleja mucho del promedio de los últimos 14 días, aparece una advertencia en ámbar.', 'side' => 'bottom'],
            ['element' => 'form-rate', 'title' => 'Tasa BCV (Bs por $)', 'description' => 'Viene propuesta sola: la que publicó el BCV para ese día o, si no hubo publicación (fin de semana o feriado), la última conocida, "arrastrada". La insignia de abajo dice de dónde salió. Si escribes otra, queda como "Manual" y se respeta. Una tasa arrastrada de hace más de una semana sale en rojo y te pide confirmarla antes de guardar.', 'side' => 'bottom'],
            ['element' => 'form-transactions', 'demo' => 'form.transactions', 'title' => 'Transacciones', 'description' => 'Cuántas ventas se cobraron (tickets o facturas). Escribimos 131 de ejemplo: mira cómo a la derecha ya aparece el ticket promedio, que es la venta dividida entre las transacciones.', 'side' => 'bottom'],
            ['element' => 'form-units', 'demo' => 'form.units', 'title' => 'Unidades vendidas', 'description' => 'Cuántos productos se vendieron. Escribimos 287 de ejemplo: con las transacciones, el panel de la derecha calcula las unidades por compra. Si hubiera menos unidades que transacciones, el sistema avisaría.', 'side' => 'bottom'],
            ['element' => 'form-shifts', 'title' => 'Jornadas (turnos)', 'description' => 'Turnos trabajados en el día. Viene precargado con el valor por defecto de la sede. Sirve para las transacciones por jornada.', 'side' => 'bottom'],
            ['element' => 'form-inventory', 'demo' => 'form.inventory', 'title' => 'Inventario', 'description' => 'Unidades en inventario y su valuación en dólares (de ejemplo: 9.850 unidades y $ 22.410). Solo se piden los días de conteo configurados para la sede; los demás días la sección aparece plegada y puedes abrirla si igual contaste.', 'side' => 'top'],
            ['element' => 'form-notes', 'demo' => 'form.notes', 'title' => 'Observación del día', 'description' => 'Un texto libre para dejar constancia de algo (corte de luz, media jornada, inventario general). Es obligatoria si marcas el día como atípico y aparece en el reporte PDF.', 'side' => 'top'],
            ['element' => 'form-atypical', 'title' => 'Día atípico', 'description' => 'Márcalo cuando el día no representa la operación normal. Cuenta en los totales del mes, pero no en los promedios ni en la proyección de la meta. Tras guardar tienes unos segundos para deshacerlo desde el aviso.', 'side' => 'top'],
            ['element' => 'form-warnings', 'title' => 'Advertencias sin revisar', 'description' => 'Cuando algún dato se ve raro, esta franja lo resume. "Revisar" te lleva al primer campo con advertencia para que lo confirmes o lo corrijas; "Guardar de todos modos" guarda tal cual. Una advertencia nunca bloquea: solo pide una segunda mirada.', 'side' => 'top'],
            ['element' => 'form-preview', 'title' => 'Se calculará', 'description' => 'Mientras escribes, aquí ves los derivados con los datos de ejemplo que acabamos de escribir: venta en dólares, ticket promedio en bolívares y en dólares, unidades por compra y transacciones por jornada. Son los mismos cálculos que hará el servidor al guardar.', 'side' => 'left'],
            ['element' => 'form-save', 'title' => 'Guardar día', 'description' => 'Con los datos completos, este botón guarda y pasa al siguiente día que falte; si no falta ninguno, vuelve al mes. En este recorrido no lo pulsamos: los datos de ejemplo se borran al terminar. Enter también guarda. En un día ya cargado el botón dice "Guardar cambios" y queda registrado quién lo editó y cuándo.', 'side' => 'top'],
            ['element' => 'form-last-edit', 'title' => 'Última edición', 'description' => 'Quién tocó este día por última vez y cuándo, tomado de la bitácora.', 'side' => 'top'],
            ['element' => 'form-delete', 'title' => 'Borrar día', 'description' => 'Elimina el día con confirmación. Tras borrarlo tienes diez segundos para deshacerlo desde el aviso; queda en la bitácora.', 'side' => 'top', 'can' => 'records.delete'],
            ['element' => 'dialog-delete-day', 'title' => 'Confirmar el borrado', 'description' => 'La confirmación recuerda que tendrás unos segundos para deshacerlo desde el aviso. "Cancelar" no borra nada.', 'side' => 'top', 'click' => 'form-delete', 'close' => true, 'wait' => 800, 'can' => 'records.delete'],
            ['element' => 'form-mobile-bar', 'title' => 'En el teléfono', 'description' => 'En pantallas pequeñas los derivados clave y el botón Guardar quedan fijos al pie, sobre la navegación.', 'side' => 'top'],
            ['element' => 'form-closed-day', 'title' => 'Registrar como día cerrado', 'description' => 'Si la farmacia no abrió (feriado, inventario general), regístralo con un motivo: el día deja de contar como faltante y no afecta los promedios.', 'side' => 'top'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function month(): array
    {
        return [
            ['element' => 'month-title', 'title' => 'Mes y sede', 'description' => 'El mes que estás viendo y su sede. Se cambia desde la barra superior.', 'side' => 'bottom'],
            ['element' => 'month-closed-badge', 'title' => 'Mes cerrado', 'description' => 'Esta insignia dice desde cuándo está cerrado y, al pasar el cursor, quién lo cerró. Mientras esté cerrado nadie puede editar sus días.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'month-close', 'title' => 'Cerrar el mes', 'description' => 'Cuando el mes está completo, cerrarlo lo deja en solo lectura para que los indicadores no cambien. Pide confirmación y, si faltan días, te lo dice y exige marcar la casilla para cerrar igual.', 'side' => 'bottom', 'align' => 'end', 'can' => 'periods.close'],
            ['element' => 'dialog-close-month', 'title' => 'Confirmar el cierre', 'description' => 'Antes de cerrar, el sistema dice si faltan días (y cuáles). Con faltantes hay que marcar la casilla "Cerrar de todos modos". Al confirmar, el mes queda en solo lectura y se registra quién lo cerró y cuándo. Aquí lo cerramos sin hacer nada: "Cancelar".', 'side' => 'left', 'click' => 'month-close', 'close' => true, 'wait' => 800, 'can' => 'periods.close'],
            ['element' => 'month-reopen', 'title' => 'Reabrir', 'description' => 'Solo dirección. Exige un motivo, avisa por correo y deja las dos entradas (cierre y reapertura) en el historial de abajo.', 'side' => 'bottom', 'align' => 'end', 'can' => 'periods.reopen'],
            ['element' => 'dialog-reopen-month', 'title' => 'Confirmar la reapertura', 'description' => 'El motivo es obligatorio y queda en el historial; dirección recibe un correo. Mientras el mes esté abierto, sus días se pueden volver a editar. Salimos con "Cancelar".', 'side' => 'left', 'click' => 'month-reopen', 'close' => true, 'wait' => 800, 'can' => 'periods.reopen'],
            ['element' => 'month-export', 'title' => 'Exportar a Excel', 'description' => 'Descarga el libro del mes con tres hojas: Indicadores (el cuadro con el mismo orden de columnas del Excel original), Anual (la tabla del año) y Tasas (la tasa de cada día con su origen).', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'month-print', 'title' => 'Imprimir', 'description' => 'Prepara la página sin menús para papel o para "Guardar como PDF" desde el navegador.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'month-empty', 'title' => 'Mes sin días', 'description' => 'Si el mes no tiene nada cargado, se ofrece cargar el primer día directamente.', 'side' => 'top'],
            ['element' => 'month-missing', 'title' => 'Días que faltan', 'description' => 'Cuenta los días del mes sin cargar hasta hoy. "Cargar los N faltantes" abre el formulario en secuencia: al guardar cada uno, pasa solo al siguiente.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'month-calendar', 'title' => 'Calendario', 'description' => 'Un cuadro por día con su estado: azul cargado, ámbar punteado falta, morado atípico, gris cerrado, y en pantallas grandes la venta en dólares. Haz clic en un día para cargarlo o corregirlo; con el teclado, muévete con las flechas.', 'side' => 'top'],
            ['element' => 'month-legend', 'title' => 'Leyenda', 'description' => 'Los cuatro colores del calendario, para no tener que recordarlos.', 'side' => 'top'],
            ['element' => 'month-search', 'demo' => 'month.search', 'undo' => 'month.search.clear', 'title' => 'Buscar fecha', 'description' => 'Escribe un número de día ("16"), una fecha ("16/09") o un día de la semana ("mar") y el cuadro muestra solo esas filas: acabamos de escribir "16" y abajo quedó solo ese día. Los totales no cambian: siempre son del mes completo.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'month-exclude', 'demo' => 'month.exclude', 'undo' => 'month.exclude.off', 'title' => 'Excluir días atípicos', 'description' => 'La casilla quedó marcada de ejemplo: fíjate en la fila de totales. Con la casilla marcada, los promedios (tickets, unidades por compra, transacciones por jornada y tasa) se calculan sin los días marcados como atípicos, para que un corte de luz no distorsione el mes. Las sumas no cambian. La fila de totales dice cuántos días se dejaron fuera.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'month-table', 'title' => 'Cuadro de indicadores', 'description' => 'Las 14 columnas del Excel agrupadas en Ventas, Operación, Promedios e Inventario. Las derivadas van en gris. Pasa el cursor por un encabezado para leer cómo se calcula; haz clic para ordenar por esa columna (otro clic invierte el orden). La primera columna se queda fija al desplazarte.', 'side' => 'top'],
            ['element' => 'month-row-date', 'title' => 'Cada fila es un día', 'description' => 'La fecha es un enlace al formulario de ese día. Junto a ella aparece la insignia "Atípico" o "Cerrado" cuando aplica. Un día cerrado muestra guiones: no vendió.', 'side' => 'right'],
            ['element' => 'month-totals', 'title' => 'Total del mes (ponderado)', 'description' => 'La fila que queda fija al pie. Venta, transacciones, unidades y jornadas se suman. Los tickets, las unidades por compra, las transacciones por jornada y la tasa se calculan con esas sumas del mes (por eso "ponderado"): no es el promedio de los valores de cada día, que daría un número engañoso. El inventario es el promedio de los días en que se contó.', 'side' => 'top'],
            ['element' => 'month-summary-line', 'title' => 'Resumen del mes', 'description' => 'Cuántos días hay cargados, en cuántos se contó inventario y cómo fue la tasa de principio a fin del mes.', 'side' => 'top'],
            ['element' => 'month-history', 'title' => 'Historial del mes', 'description' => 'Cada cierre y reapertura, con quién lo hizo, cuándo y el motivo. Es la bitácora visible del mes.', 'side' => 'top'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function charts(): array
    {
        return [
            ['element' => 'charts-subtitle', 'title' => 'Período y sede', 'description' => 'Las gráficas son del mes y la sede elegidos arriba. En la pestaña Año, del año de ese mes.', 'side' => 'bottom'],
            ['element' => 'charts-tab-ventas', 'title' => 'Pestaña Ventas', 'description' => 'La venta diaria en dólares (con la meta diaria), la venta diaria en bolívares y el mapa de calor por día de la semana. La moneda elegida arriba decide cuál va primero.', 'side' => 'bottom'],
            ['element' => 'charts-tab-operacion', 'title' => 'Pestaña Operación', 'description' => 'Cuatro gráficas: transacciones y unidades de cada día; transacciones por jornada; ticket promedio en bolívares junto a las unidades por compra; y unidades por compra junto al ticket en dólares.', 'side' => 'bottom'],
            ['element' => 'charts-tab-inventario', 'title' => 'Pestaña Inventario', 'description' => 'Unidades en inventario y su valuación en los días de conteo.', 'side' => 'bottom'],
            ['element' => 'charts-tab-tasa', 'title' => 'Pestaña Tasa', 'description' => 'La venta en dólares en barras contra la tasa BCV en línea, sobre un segundo eje: para ver si la venta en dólares se sostiene cuando la tasa sube.', 'side' => 'bottom'],
            ['element' => 'charts-tab-anio', 'click' => 'charts-tab-anio', 'wait' => 2500, 'title' => 'Pestaña Año', 'description' => 'Cada mes del año en barras junto al mismo mes del año anterior en gris, para el indicador que elijas en el selector que aparece al abrirla. El indicador queda en la dirección de la página, así que puedes compartir el enlace.', 'side' => 'bottom'],
            ['element' => 'charts-annual-indicator', 'demo' => 'charts.indicator', 'title' => 'Indicador de la comparación', 'description' => 'Elige qué indicador comparar mes a mes con el año anterior: venta, transacciones, unidades, tickets, inventario o jornadas. De ejemplo acabamos de elegir Transacciones: la gráfica cambió sola.', 'side' => 'bottom'],
            ['element' => 'chart-title', 'title' => 'Cada gráfica', 'description' => 'Título, subtítulo con el período y una leyenda clicable para ocultar series. Al pasar el cursor, el tooltip muestra el valor exacto en formato venezolano.', 'side' => 'bottom'],
            ['element' => 'chart-data', 'title' => 'Datos', 'description' => 'Muestra debajo de la gráfica la tabla con los mismos números, para leer un valor exacto o copiarlo.', 'side' => 'left'],
            ['element' => 'chart-png', 'title' => 'PNG', 'description' => 'Descarga la gráfica como imagen con el nombre de la gráfica y el mes.', 'side' => 'left'],
            ['element' => 'chart-expand', 'title' => 'Ampliar', 'description' => 'La abre a pantalla completa para presentarla; Esc la cierra.', 'side' => 'left'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function goals(): array
    {
        return [
            ['element' => 'goals-view', 'title' => 'Este mes o Año', 'description' => '"Este mes" muestra las metas del mes elegido arriba con su seguimiento. "Año" abre la cuadrícula de doce meses para definirlas todas de una vez.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'goals-copy-month', 'title' => 'Copiar del mes anterior', 'description' => 'Rellena las metas de este mes con las del anterior para ajustarlas desde ahí.', 'side' => 'bottom', 'align' => 'end', 'can' => 'goals.manage'],
            ['element' => 'goals-table', 'title' => 'Seguimiento del mes', 'description' => 'Una fila por indicador con cinco columnas: la meta (se edita aquí mismo si tienes permiso), lo que va del mes, lo que debería llevar a la fecha según lo que suele venderse cada día de la semana, en cuánto cerraría al ritmo actual, y el estado en verde, ámbar o rojo según los umbrales definidos en Administración.', 'side' => 'top'],
            ['element' => 'goals-target', 'title' => 'La meta, editable en línea', 'description' => 'Escribe el valor y sal del campo: se guarda solo. Si está vacío, el sistema muestra una sugerencia (el mes anterior más el crecimiento configurado). Dejarlo vacío borra la meta.', 'side' => 'right', 'can' => 'goals.manage'],
            ['element' => 'goals-status', 'title' => 'Estado', 'description' => 'En meta, en riesgo o por debajo, siempre con icono y texto. Ojo con el ticket y las unidades por compra: no se acumulan a lo largo del mes, así que su estado sale del valor que llevan a la fecha, no de una proyección.', 'side' => 'left'],
            ['element' => 'goals-footnote', 'title' => 'Corte y método', 'description' => 'Hasta qué día se contó y si la proyección usa el patrón semanal (cuando hay histórico suficiente) o una proyección lineal.', 'side' => 'top'],
            ['element' => 'goals-year-nav', 'demo' => 'goals.year', 'wait' => 3000, 'title' => 'Año de la cuadrícula', 'description' => 'Las flechas cambian de año hacia atrás o hacia adelante; la cuadrícula se recarga con las metas de ese año y lo que no se haya guardado se pierde.', 'side' => 'bottom'],
            ['element' => 'goals-copy-year', 'title' => 'Copiar el año anterior', 'description' => 'Trae las doce metas del año pasado a la cuadrícula para ajustarlas.', 'side' => 'bottom', 'can' => 'goals.manage'],
            ['element' => 'goals-growth', 'title' => 'Aplicar un porcentaje', 'description' => 'Escribe un porcentaje y "Aplicar a todo el año" sube (o baja, con negativo) todas las metas de la cuadrícula en ese porcentaje.', 'side' => 'bottom', 'can' => 'goals.manage'],
            ['element' => 'goals-save-year', 'title' => 'Guardar metas', 'description' => 'Guarda toda la cuadrícula de una vez. Una celda vacía borra esa meta.', 'side' => 'bottom', 'align' => 'end', 'can' => 'goals.manage'],
            ['element' => 'goals-grid-mobile', 'title' => 'En el teléfono', 'description' => 'La cuadrícula se apila: una tarjeta por indicador con sus doce meses.', 'side' => 'top'],
            ['element' => 'goals-grid', 'demo' => 'goals.type', 'title' => 'Cuadrícula indicador por mes', 'description' => 'Doce meses por indicador. De ejemplo escribimos 20.000 en la primera celda; no se guarda hasta pulsar "Guardar metas", y al terminar el recorrido se borra. Flechas y Enter mueven el foco entre celdas y puedes pegar un bloque copiado desde Excel. En móvil se apila: una tarjeta por indicador.', 'side' => 'top'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function annual(): array
    {
        return [
            ['element' => 'annual-title', 'title' => 'Año y sede', 'description' => 'El año que estás viendo y cuántos meses tienen datos.', 'side' => 'bottom'],
            ['element' => 'annual-year-nav', 'title' => 'Cambiar de año', 'description' => 'Las flechas y el desplegable cambian de año; el año queda en la dirección de la página.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'annual-export', 'title' => 'Exportar a Excel', 'description' => 'Descarga la tabla del año como libro de Excel.', 'side' => 'bottom', 'align' => 'end', 'can' => 'reports.export'],
            ['element' => 'annual-empty', 'title' => 'Año sin datos', 'description' => 'Si no hay meses cargados, carga días o importa cuadros anteriores para completar el año.', 'side' => 'top'],
            ['element' => 'annual-table', 'title' => 'La tabla anual del Excel', 'description' => 'Los 12 indicadores en su orden, un mes por columna. Los meses sin datos van en gris. Pasa el cursor por una celda para ver la variación frente al mes anterior y frente al mismo mes del año pasado.', 'side' => 'top'],
            ['element' => 'annual-year-column', 'title' => 'Columna Año', 'description' => 'El total del año para lo que se suma (venta, transacciones, unidades, jornadas) y, para los promedios (tickets, unidades por compra, tasa), el valor calculado con todos los días del año juntos, no promediando los doce meses. Así un mes corto no pesa igual que uno completo.', 'side' => 'left'],
            ['element' => 'annual-footnote', 'title' => 'Cómo se calcula cada celda', 'description' => 'Cada mes se calcula igual que en el cuadro del mes: se suman venta, transacciones, unidades y jornadas; los tickets, las unidades por compra, las transacciones por jornada y la tasa salen de esas sumas; el inventario es el promedio de los días con conteo.', 'side' => 'top'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function rates(): array
    {
        return [
            ['element' => 'rates-fetch', 'title' => 'Consultar ahora', 'description' => 'Pide al BCV la tasa de hoy y la del siguiente día hábil sin esperar a las consultas automáticas de las 08:00 y las 17:30. En fin de semana solo trae la del lunes, porque el BCV no publica sábados ni domingos.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'rates-backfill', 'title' => 'Traer histórico del BCV', 'description' => 'Descarga los libros trimestrales oficiales del BCV desde la fecha que elijas y agrega las tasas de los días que no tienen ninguna. Las manuales y las ya publicadas no se tocan. Sirve para completar meses viejos antes de importar cuadros.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'dialog-backfill', 'demo' => 'rates.backfill.date', 'title' => 'Desde qué fecha', 'description' => 'Eliges la fecha inicial (hasta hoy). Cada trimestre tarda unos segundos en descargarse del BCV. Al terminar, un aviso dice cuántas tasas nuevas entraron y de qué fecha a qué fecha. Salimos con "Cancelar".', 'side' => 'left', 'click' => 'rates-backfill', 'close' => true, 'wait' => 800],
            ['element' => 'rates-recalc', 'title' => 'Recalcular el mes', 'description' => 'Cada día cargado guarda la tasa con la que se cargó. Si corriges tasas aquí, este botón vuelve a calcular la venta en dólares de los días del mes con las tasas de esta tabla; antes te dice cuántos días cambiarían.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'dialog-recalc', 'title' => 'Confirmar el recálculo', 'description' => 'Dice cuántos días cambiarían. Si ninguno, el botón queda deshabilitado. Queda en la bitácora. Salimos con "Cancelar".', 'side' => 'left', 'click' => 'rates-recalc', 'close' => true, 'wait' => 800],
            ['element' => 'rates-status', 'title' => 'Estado de la consulta automática', 'description' => 'Cuándo fue la última consulta, la última exitosa y el último error, más cuántas tasas del mes son publicadas, cuántas manuales y de qué tasa a qué tasa fue el mes.', 'side' => 'bottom'],
            ['element' => 'rates-pending', 'title' => 'Días con tasa distinta', 'description' => 'Aparece cuando hay días cargados con una tasa diferente a la de esta tabla. Conservan la suya hasta que decidas recalcular.', 'side' => 'bottom'],
            ['element' => 'rates-chart', 'title' => 'La tasa del mes en gráfica', 'description' => 'Un punto por día. El tooltip dice el origen de cada uno.', 'side' => 'top'],
            ['element' => 'chart-title', 'title' => 'La gráfica', 'description' => 'Título y período; la leyenda no aplica aquí porque es una sola serie. El tooltip dice la tasa y el origen de cada día.', 'side' => 'bottom'],
            ['element' => 'chart-data', 'title' => 'Datos', 'description' => 'Muestra la tabla con la tasa de cada día, la misma que la gráfica.', 'side' => 'left'],
            ['element' => 'chart-png', 'title' => 'PNG', 'description' => 'Descarga la gráfica de la tasa como imagen con fondo blanco.', 'side' => 'left'],
            ['element' => 'chart-expand', 'title' => 'Ampliar', 'description' => 'Abre la gráfica a pantalla completa; Esc la cierra.', 'side' => 'left'],
            ['element' => 'rates-table', 'title' => 'Tasa por día', 'description' => 'Cada día del mes hasta hoy con su tasa, el origen (BCV, Manual, Arrastrada de tal día, o Sin tasa), la variación frente al día anterior y quién la fijó. Una arrastrada de más de una semana se marca en rojo.', 'side' => 'top'],
            ['element' => 'rates-edit', 'title' => 'Fijar o editar', 'description' => 'Cada fila tiene este botón: "Fijar" cuando el día no tiene tasa propia (arrastrada o sin tasa) y "Editar" cuando ya la tiene. Abre la edición en línea de ese día.', 'side' => 'left'],
            ['element' => 'rates-table', 'demo' => 'rates.edit', 'undo' => 'rates.cancel', 'title' => 'La edición en línea', 'description' => 'Así se ve al pulsarlo, con 812,50 escrito de ejemplo: Enter guarda y Esc cancela. La tasa queda como manual y en la bitácora, y una tasa manual nunca la pisa la automática. Aquí la cancelamos sin guardar.', 'side' => 'top'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function imports(): array
    {
        return [
            ['element' => 'import-steps', 'title' => 'Tres pasos', 'description' => 'Archivos, Revisión y Confirmación. En ningún momento entra un dato sin que lo hayas revisado.', 'side' => 'bottom'],
            ['element' => 'import-branch', 'title' => 'Sede', 'description' => 'A qué sede pertenecen los archivos. Solo aparece cuando hay más de una.', 'side' => 'bottom'],
            ['element' => 'import-dropzone', 'title' => 'Archivos', 'description' => 'Arrastra aquí los cuadros de indicadores en Excel (.xlsx, sin macros, hasta 5 MB y 24 archivos por lote) o haz clic para elegirlos. El nombre del archivo no importa: el mes se lee del contenido.', 'side' => 'top'],
            ['element' => 'import-analyze', 'title' => 'Analizar', 'description' => 'Al pulsar Siguiente, el recorrido subirá un cuadro de ejemplo (enero 2020, ficticio, con dos errores a propósito) y lo analizará para que veas la revisión. Este botón lee los archivos y pasa a la revisión. Detecta el mes, la razón social, las filas y las anomalías: días faltantes, fechas duplicadas o fuera del mes, valores negativos o vacíos, saltos de tasa, ventas muy distintas al resto del mes, meses ya cargados.', 'side' => 'top', 'align' => 'end'],
            ['element' => 'import-review', 'demo' => 'import.sample', 'wait' => 45000, 'title' => 'Revisión de cada archivo', 'description' => 'Por archivo: el mes detectado, las filas, la razón social y las anomalías agrupadas por gravedad. "Debe resolverse" exige una decisión tuya; "revisar" y "aviso" traen una propuesta; "informativa" solo informa.', 'side' => 'bottom'],
            ['element' => 'import-skip', 'title' => 'No importar', 'description' => 'Deja fuera ese archivo del lote sin borrar la revisión.', 'side' => 'left'],
            ['element' => 'import-toggle', 'demo' => 'import.expand', 'title' => 'Revisar / Ocultar', 'description' => 'Despliega el detalle del archivo: anomalías con sus decisiones y la vista previa. Ya quedó desplegado.', 'side' => 'left'],
            ['element' => 'import-decision', 'demo' => 'import.decide', 'title' => 'La decisión', 'description' => 'El cuadro de ejemplo trae una fecha repetida y un día que falta: son las anomalías que "deben resolverse". Acabamos de elegir la primera opción en una de ellas para que veas cómo cambia el botón de importar. Cada anomalía se resuelve eligiendo una opción en palabras del negocio: omitir ese día, registrarlo como cerrado, usar la primera o la última fila cuando una fecha está repetida, marcar el día como atípico, conservar la tasa que ya estaba registrada, reemplazar un mes ya cargado o no importar el archivo. Nada se aplica hasta que pulses Importar.', 'side' => 'left'],
            ['element' => 'import-preview', 'title' => 'Vista previa', 'description' => 'Los días tal como quedarían, con los derivados ya recalculados por el sistema (no los del archivo).', 'side' => 'top'],
            ['element' => 'import-close-after', 'title' => 'Cerrar los meses al importar', 'description' => 'Deja los meses importados en solo lectura de una vez. Dirección puede reabrirlos.', 'side' => 'top'],
            ['element' => 'import-restart', 'title' => 'Volver a empezar', 'description' => 'Descarta la revisión y vuelve a la subida de archivos. Nada se guardó.', 'side' => 'top', 'align' => 'end'],
            ['element' => 'import-confirm', 'title' => 'Importar', 'description' => 'En este recorrido no lo pulsamos: al terminar, el archivo de ejemplo se descarta solo. Guarda los meses revisados. Está deshabilitado mientras quede una anomalía "debe resolverse" sin decisión: el propio botón dice cuántas faltan. Todo queda en la bitácora.', 'side' => 'top', 'align' => 'end'],
            ['element' => null, 'title' => 'Paso 3: Confirmación', 'description' => 'Así se ve el último paso después de importar de verdad: por archivo, los días nuevos, actualizados, cerrados, atípicos y las filas omitidas, con acceso directo al mes importado.<img src="/images/recorrido/importar-confirmacion.png" alt="Pantalla de confirmación de la importación" style="display:block;width:100%;margin-top:10px;border:1px solid #DDE4EC;border-radius:8px">'],
            ['element' => 'import-result', 'title' => 'Resultado', 'description' => 'Por archivo: días nuevos, actualizados, cerrados, atípicos y filas omitidas, con acceso directo al mes importado.', 'side' => 'bottom'],
            ['element' => 'import-more', 'title' => 'Importar más archivos', 'description' => 'Vuelve al primer paso para subir otro lote de cuadros; lo ya importado queda guardado.', 'side' => 'top', 'align' => 'end'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function admin(): array
    {
        return [
            ['element' => 'admin-tabs', 'title' => 'Cuatro secciones', 'description' => 'Usuarios, Sedes, Parámetros y Bitácora. La pestaña activa queda en la dirección de la página.', 'side' => 'bottom'],
            ['element' => 'admin-new-user', 'title' => 'Nuevo usuario', 'description' => 'Abre el formulario: nombre, correo, rol (con la explicación de lo que puede hacer cada uno), sedes y una contraseña inicial sugerida. Al crearlo, las credenciales se muestran una sola vez para que se las entregues en persona.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'dialog-user', 'demo' => 'admin.user', 'title' => 'El formulario de usuario', 'description' => 'Lo llenamos con datos de ejemplo (Ana Pérez, rol Supervisión) sin crear nada. Nombre, correo, rol (debajo del selector se explica lo que puede hacer cada rol), sedes (los roles de dirección y administración ven todas) y la contraseña inicial: viene sugerida, legible y sin caracteres confusos; "Generar" propone otra. Salimos con "Cancelar".', 'side' => 'left', 'click' => 'admin-new-user', 'close' => true, 'wait' => 800],
            ['element' => 'admin-issued', 'title' => 'Credenciales para entregar', 'description' => 'Al crear un usuario o cambiar una contraseña, aparece esta franja con el correo y la contraseña una sola vez. "Copiar" las lleva al portapapeles; "Listo" la cierra.', 'side' => 'bottom'],
            ['element' => 'admin-users-table', 'title' => 'Usuarios', 'description' => 'Quién tiene acceso, con qué rol, en qué sedes y si está activo. Un usuario desactivado no entra aunque su contraseña sea correcta y, si tenía sesión abierta, la pierde.', 'side' => 'top'],
            ['element' => 'admin-user-edit', 'title' => 'Editar', 'description' => 'Cambia nombre, correo, rol o sedes. Nadie se desactiva a sí mismo y siempre queda al menos un administrador activo.', 'side' => 'left'],
            ['element' => 'admin-user-password', 'title' => 'Contraseña', 'description' => 'Reemplaza la contraseña de ese usuario por una nueva (sugerida o escrita) y te la muestra una vez para entregarla.', 'side' => 'left'],
            ['element' => 'dialog-password', 'title' => 'Cambiar la contraseña', 'description' => 'Se reemplaza la actual por la nueva (sugerida o escrita) y se muestra una vez para entregarla en persona. Salimos con "Cancelar".', 'side' => 'left', 'click' => 'admin-user-password', 'close' => true, 'wait' => 800],
            ['element' => 'admin-user-toggle', 'title' => 'Desactivar / Activar', 'description' => 'Quita o devuelve el acceso sin borrar nada: su historial en la bitácora se conserva.', 'side' => 'left'],
            ['element' => 'admin-tab-sedes', 'title' => 'Sedes', 'description' => 'Cada sede con su código, razón social (va en los reportes), jornadas por defecto, días de conteo de inventario, umbral propio de venta y si está activa.', 'side' => 'bottom', 'click' => 'admin-tab-sedes', 'wait' => 1500],
            ['element' => 'admin-new-branch', 'title' => 'Nueva sede', 'description' => 'Crea otra sede. Los usuarios se le asignan desde Usuarios y aparece en el selector de la barra superior.', 'side' => 'bottom', 'align' => 'end'],
            ['element' => 'dialog-branch', 'demo' => 'admin.branch', 'title' => 'El formulario de sede', 'description' => 'Nombre, código corto, jornadas por defecto (se precargan al cargar el día), razón social (va en los reportes), los días de la semana en que se cuenta inventario, un umbral propio de advertencia de venta y si la sede está activa. Salimos con "Cancelar".', 'side' => 'left', 'click' => 'admin-new-branch', 'close' => true, 'wait' => 800],
            ['element' => 'admin-branch-card', 'title' => 'Tarjeta de sede', 'description' => 'Un resumen de la configuración y cuántos días tiene cargados. "Editar" abre el formulario; una sede inactiva no recibe cargas pero conserva sus datos.', 'side' => 'top'],
            ['element' => 'admin-tab-parametros', 'title' => 'Parámetros', 'description' => 'Los valores que gobiernan el sistema, cada uno con su explicación.', 'side' => 'bottom', 'click' => 'admin-tab-parametros', 'wait' => 1500],
            ['element' => 'admin-settings-warnings', 'title' => 'Advertencias al cargar el día', 'description' => 'Desvío de la venta respecto al promedio de los últimos 14 días y desvío de la tasa respecto al día anterior. Nunca bloquean: avisan y piden confirmar.', 'side' => 'top'],
            ['element' => 'admin-settings-edit', 'title' => 'Ventana del operador', 'description' => 'Hasta cuántos días atrás puede editar el operador. Supervisión y dirección no tienen límite.', 'side' => 'top'],
            ['element' => 'admin-settings-goals', 'title' => 'Metas', 'description' => 'El crecimiento con el que se sugieren las metas, en qué moneda se definen y los umbrales que pintan la proyección en verde, ámbar o rojo.', 'side' => 'top'],
            ['element' => 'admin-settings-other', 'title' => 'Otros', 'description' => 'El nombre del sistema, que aparece en la pestaña del navegador y en los reportes, y el margen bruto: es opcional y por ahora solo se guarda, previsto para los indicadores de rotación de inventario de una etapa posterior.', 'side' => 'top'],
            ['element' => 'admin-settings-mail', 'title' => 'Correo', 'description' => 'El día del mes en que se envía el reporte PDF del mes anterior (0 lo apaga), a quién, y si el día 1 se recuerda cerrar el mes anterior cuando tiene pendientes. Necesita el correo saliente configurado en el servidor.', 'side' => 'top'],
            ['element' => 'admin-settings-save', 'title' => 'Guardar parámetros', 'description' => 'Guarda solo lo que cambió y lo deja en la bitácora con el valor anterior y el nuevo.', 'side' => 'top', 'align' => 'end'],
            ['element' => 'admin-tab-bitacora', 'title' => 'Bitácora', 'description' => 'Todo lo que pasa en el sistema, en frases: quién cargó, editó o borró un día, quién cerró o reabrió un mes, quién cambió una tasa, un usuario, una sede o un parámetro, y qué importó.', 'side' => 'bottom', 'click' => 'admin-tab-bitacora', 'wait' => 1500],
            ['element' => 'admin-log-type', 'title' => 'Filtrar por familia', 'description' => 'Días, meses, tasas, metas, usuarios, sedes, parámetros o importaciones.', 'side' => 'bottom'],
            ['element' => 'admin-log-search', 'demo' => 'admin.log.search', 'undo' => 'admin.log.clear', 'title' => 'Filtrar por persona', 'description' => 'Escribe parte del nombre de quien hizo la acción y la lista se reduce mientras escribes; de ejemplo escribimos "Admin".', 'side' => 'bottom'],
            ['element' => 'admin-log-list', 'title' => 'Las entradas', 'description' => 'Fecha y hora, quién, qué y a qué. "Ver cambios" despliega el valor anterior y el nuevo de cada campo.', 'side' => 'top'],
            ['element' => 'admin-log-more', 'title' => 'Ver más', 'description' => 'Carga las entradas más antiguas de la bitácora, de a bloques, sin perder el filtro que tengas puesto.', 'side' => 'top'],
            ['element' => 'admin-tab-usuarios', 'title' => 'De vuelta a Usuarios', 'description' => 'Las pestañas conservan sus filtros mientras estés en la pantalla.', 'side' => 'bottom', 'click' => 'admin-tab-usuarios', 'wait' => 1500],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function profile(): array
    {
        return [
            ['element' => 'profile-name', 'demo' => 'profile.name', 'undo' => 'profile.restore', 'title' => 'Nombre', 'description' => 'Así te ven los demás en la bitácora y en los cierres de mes. Escribimos "Ana Pérez" de ejemplo; se restaura solo al pasar de paso.', 'side' => 'bottom'],
            ['element' => 'profile-email', 'title' => 'Correo', 'description' => 'Con él entras y recuperas la contraseña. Solo Administración lo cambia, para que nadie quede fuera por una verificación pendiente.', 'side' => 'bottom'],
            ['element' => 'profile-role', 'title' => 'Rol', 'description' => 'Lo que puedes hacer en el sistema. Lo asigna Administración.', 'side' => 'bottom'],
            ['element' => 'profile-save-name', 'title' => 'Guardar nombre', 'description' => 'Guarda el nombre. Debe tener al menos tres letras.', 'side' => 'top', 'align' => 'end'],
            ['element' => 'profile-password', 'title' => 'Cambiar tu contraseña', 'description' => 'Escribe la actual, la nueva (al menos 8 caracteres) y repítela. Si el administrador te dio una temporal, cámbiala aquí la primera vez.', 'side' => 'left'],
            ['element' => 'profile-change-password', 'title' => 'Cambiar contraseña', 'description' => 'Aplica el cambio; la próxima vez entras con la nueva.', 'side' => 'top', 'align' => 'end'],
        ];
    }
}
