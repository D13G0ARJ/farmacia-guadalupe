"""
Recorrido E2E de la interfaz (Fase 1) con Playwright para Python, contra el servidor de desarrollo.

Requisitos: `pip install playwright && playwright install chromium`; base de datos de desarrollo sembrada
(`php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder`) y assets construidos (`npm run build`).

Uso (desde indicadores/):
    php artisan serve --port=8765          # en otra terminal
    python tests/Browser/walkthrough.py    # E2E_BASE para otra URL

El recorrido carga días del mes en curso (1 al 5), así que ese mes debe estar vacío y hoy debe ser >= día 5.
Para repetirlo, borra los registros del mes en curso (DailyRecord con date >= inicio de mes) y limpia la cache.
Cada paso deja una captura en tests/Browser/shots (ignorado por git) y registra PASS/FAIL sin detener el resto.
"""
import datetime as _dt
import os
import re
import sys
import time
import traceback

from playwright.sync_api import sync_playwright, expect

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8765")
SHOTS = os.path.join(os.path.dirname(__file__), "shots")
_TODAY = _dt.date.today()
_WEEKDAYS = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"]
_MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"]
PERIOD = _TODAY.strftime("%Y-%m")


def day(n):
    """Fecha ISO del día n del mes en curso."""
    return _TODAY.replace(day=n).isoformat()


def heading(n):
    d = _TODAY.replace(day=n)
    return f"{_WEEKDAYS[d.weekday()]} {d.day} de {_MONTHS[d.month - 1]} de {d.year}"
os.makedirs(SHOTS, exist_ok=True)

results = []


def step(name, fn):
    started = time.time()
    try:
        fn()
        results.append(("PASS", name))
        print(f"PASS  {name} ({time.time() - started:.0f}s)")
    except Exception as e:  # noqa: BLE001
        msg = " | ".join(str(e).split("\n"))[:400]
        results.append(("FAIL", f"{name} :: {type(e).__name__}: {msg}"))
        print(f"FAIL  {name} :: {type(e).__name__}: {msg}")
        try:
            page.screenshot(path=os.path.join(SHOTS, f"FAIL-{len(results):02d}.png"), full_page=True)
        except Exception:  # noqa: BLE001
            pass


def shot(page, name):
    page.screenshot(path=os.path.join(SHOTS, f"{name}.png"), full_page=True)


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1366, "height": 768}, locale="es-VE", accept_downloads=True)
    page = ctx.new_page()
    console_errors = []
    page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: console_errors.append(f"pageerror @ {page.url}: {e}"))
    page.on("response", lambda r: console_errors.append(f"HTTP {r.status} {r.url}") if r.status >= 400 else None)

    # ---------- 1. Login ----------
    def login():
        page.goto(f"{BASE}/login", wait_until="networkidle")
        expect(page.get_by_text("Farmacia Guadalupe")).to_be_visible()
        expect(page.get_by_role("button", name="Entrar")).to_be_visible()
        assert page.locator("text=Log in").count() == 0
        shot(page, "01-login")
        page.fill("input[type=email]", "admin@guadalupe.local")
        page.fill("input[type=password]", "password")
        page.click("button[type=submit]")
        page.wait_for_url(f"{BASE}/dashboard", timeout=45000)  # la primera petición compila vistas
        page.wait_for_load_state("networkidle")
    step("login redirige al panel", login)

    # ---------- 2. Panel (mes actual vacío) ----------
    def dashboard_empty():
        expect(page.get_by_text("Aún no hay días cargados")).to_be_visible()
        expect(page.get_by_role("link", name="Cargar el primer día")).to_be_visible()
        shot(page, "02-panel-vacio")
    step("panel del mes actual muestra el estado vacío con su acción", dashboard_empty)

    # ---------- 3. Barra de contexto: ir a septiembre 2025 ----------
    def context_period():
        page.select_option("#context-period", "2025-09")
        page.wait_for_load_state("networkidle")
        time.sleep(0.6)
        expect(page.get_by_role("heading", name="Septiembre 2025")).to_be_visible()
        expect(page.get_by_text("$ 18.611").first).to_be_visible()
        expect(page.get_by_text("Venta en dólares", exact=True).first).to_be_visible()
        shot(page, "03-panel-septiembre")
    step("barra de contexto cambia el período y el panel muestra los KPI de septiembre", context_period)

    def dashboard_secondary():
        page.get_by_text("Ver 4 indicadores más").click()
        expect(page.get_by_text("Valuación de inventario")).to_be_visible()
        expect(page.get_by_text("promedio de 25 conteos")).to_be_visible()
        shot(page, "04-panel-secundarios")
    step("fila secundaria plegada se despliega", dashboard_secondary)

    # ---------- 3b. Fase 2: deltas, sparklines, gráficas y avisos en el panel ----------
    def dashboard_phase2():
        expect(page.get_by_text("vs agosto").first).to_be_visible()
        expect(page.get_by_text("En dólares:").first).to_be_visible()
        assert page.locator("svg polyline").count() >= 4, "faltan sparklines"
        # ECharts dibuja SVG dentro del lienzo de cada gráfica (G2 barras, G8 mapa de calor)
        expect(page.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas'] svg")).to_be_visible(timeout=15000)
        expect(page.locator("section[aria-labelledby='chart-g8-title'] [x-ref='canvas'] svg")).to_be_visible(timeout=15000)
        bars = page.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas'] svg path").count()
        assert bars >= 30, f"barras dibujadas: {bars}"
        expect(page.get_by_text("Avisos del mes")).to_be_visible()
        expect(page.get_by_text("Agosto 2025 no está cerrado.")).to_be_visible()
        # Tooltip formateado en es-VE al pasar sobre la primera barra
        canvas = page.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas']")
        canvas.scroll_into_view_if_needed()
        box = canvas.bounding_box()
        page.mouse.move(box["x"] + box["width"] / 2, box["y"] + box["height"] / 2)
        page.mouse.move(box["x"] + box["width"] / 2 + 4, box["y"] + box["height"] / 2 + 2)
        time.sleep(0.6)
        expect(page.get_by_text(re.compile(r"\d{2}/09 · \$ \d"))).to_be_visible()
        shot(page, "03b-panel-fase2")
    step("panel: variaciones vs agosto, sparklines, G2 y G8 dibujadas, tooltip es-VE y avisos", dashboard_phase2)

    def dashboard_data_table():
        panel = page.locator("section[aria-labelledby='chart-g2-title']")
        panel.get_by_role("button", name="Datos").click()
        expect(panel.locator("table tbody tr")).to_have_count(30)
        expect(panel.locator("table tbody tr").first).to_contain_text("$ 614")
        panel.get_by_role("button", name="Datos").click()
        expect(panel.locator("table")).to_be_hidden()
    step("panel: 'Datos' despliega la tabla de la gráfica con los mismos valores", dashboard_data_table)

    def dashboard_context_updates_charts():
        try:
            page.get_by_role("button", name="Mes anterior").click()
            expect(page.get_by_role("heading", name="Agosto 2025")).to_be_visible(timeout=20000)
            expect(page.locator("section[aria-labelledby='chart-g2-title']")).to_contain_text("Agosto 2025", timeout=20000)
            assert page.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas'] svg path").count() >= 31
        finally:
            # El período vive en sesión: se restaura pase lo que pase
            page.select_option("#context-period", "2025-09")
            expect(page.get_by_role("heading", name="Septiembre 2025")).to_be_visible(timeout=20000)
        expect(page.locator("section[aria-labelledby='chart-g2-title']")).to_contain_text("Septiembre 2025", timeout=20000)
    step("panel: cambiar de mes redibuja las gráficas sin recargar", dashboard_context_updates_charts)

    # ---------- 3d. Fase 3: héroe con meta, barras de meta y G9 ----------
    def dashboard_goals():
        expect(page.get_by_text(re.compile(r"Septiembre 2025 cerró en"))).to_be_visible()
        expect(page.get_by_text("frente a una meta de $ 20.000")).to_be_visible()
        expect(page.locator("section[aria-labelledby='chart-g9-title'] [x-ref='canvas'] svg")).to_be_visible(timeout=15000)
        assert page.locator("[role='progressbar']").count() >= 4, "faltan barras de meta en las tarjetas"
        expect(page.get_by_text("Meta $ 5,0 · vas al").first).to_be_visible()
        # La línea de meta diaria entra en la leyenda de G2
        expect(page.locator("section[aria-labelledby='chart-g2-title']")).to_contain_text("Meta diaria")
        shot(page, "03c-panel-metas")
    step("panel: héroe con el cierre frente a la meta, G9 dibujada y barras de meta en las tarjetas", dashboard_goals)

    # ---------- 3e. Página de metas ----------
    def goals_month_view():
        page.click("aside a[href$='/metas']")
        page.wait_for_load_state("networkidle")
        expect(page.get_by_role("heading", name="Metas")).to_be_visible()
        expect(page.locator("#target-sales_usd")).to_have_value("20.000")
        expect(page.locator("#target-sales_bs")).to_have_attribute("placeholder", re.compile(r"Sugerida: "))
        expect(page.get_by_text("En riesgo").first).to_be_visible()
        expect(page.get_by_text("proyección por patrón semanal")).to_be_visible()
        shot(page, "05d-metas-mes")
        # Edición en línea: guarda al perder el foco y el panel lo refleja (se restaura al final pase lo que pase)
        try:
            page.fill("#target-sales_usd", "21000")
            page.locator("#target-sales_usd").blur()
            expect(page.get_by_text("Meta guardada.")).to_be_visible(timeout=10000)
            expect(page.locator("#target-sales_usd")).to_have_value("21.000", timeout=10000)
            page.goto(f"{BASE}/dashboard", wait_until="networkidle")
            expect(page.get_by_text("frente a una meta de $ 21.000")).to_be_visible()
        finally:
            page.goto(f"{BASE}/metas", wait_until="networkidle")
            page.fill("#target-sales_usd", "20000")
            page.locator("#target-sales_usd").blur()
            expect(page.locator("#target-sales_usd")).to_have_value("20.000", timeout=10000)
        # Valor inválido: error en línea, sin modal
        page.fill("#target-units", "muchas")
        page.locator("#target-units").blur()
        expect(page.get_by_text("La meta debe ser un número mayor que cero.")).to_be_visible(timeout=10000)
    step("metas: vista del mes con edición en línea, sugerencia, estados y error en línea", goals_month_view)

    def goals_year_view():
        page.get_by_role("button", name="Año", exact=True).click()
        expect(page.get_by_role("heading", name="2025")).to_be_visible(timeout=10000)
        assert "view=year" in page.url, page.url
        expect(page.locator("#grid-sales_usd-2025-09")).to_have_value("20.000")
        expect(page.locator("#grid-sales_usd-2025-08")).to_have_value("18.500")
        # Navegación con teclado: flecha abajo pasa a la fila siguiente, misma columna
        page.locator("#grid-sales_usd-2025-09").focus()
        page.keyboard.press("ArrowDown")
        assert page.evaluate("document.activeElement.id") == "grid-transactions-2025-09"
        # +10 % a todo el año solo prepara la cuadrícula; recargar la descarta sin guardar
        page.fill("#growth", "10")
        page.get_by_role("button", name="Aplicar a todo el año").click()
        expect(page.locator("#grid-sales_usd-2025-09")).to_have_value("22.000", timeout=10000)
        shot(page, "05e-metas-anio")
        page.reload(wait_until="networkidle")
        expect(page.locator("#grid-sales_usd-2025-09")).to_have_value("20.000")
        # Guardado en lote: solo la celda cambiada; una celda vacía borra la meta
        page.locator("#grid-sales_usd-2025-10").fill("21000")
        page.get_by_role("button", name="Guardar metas").click()
        expect(page.get_by_text("1 meta guardada.").last).to_be_visible(timeout=10000)
        expect(page.locator("#grid-sales_usd-2025-10")).to_have_value("21.000", timeout=10000)
        page.locator("#grid-sales_usd-2025-10").fill("")
        page.get_by_role("button", name="Guardar metas").click()
        expect(page.locator("#grid-sales_usd-2025-10")).to_have_value("", timeout=10000)
        expect(page.get_by_text("1 meta guardada.").last).to_be_visible()
        assert page.evaluate("document.documentElement.scrollWidth") <= 1366, "scroll horizontal en la cuadrícula"
    step("metas: cuadrícula anual con teclado, +X %, guardado en lote y borrado por celda vacía", goals_year_view)

    # ---------- 3c. Página de gráficas ----------
    def charts_page():
        page.click("aside a[href$='/graficas']")
        page.wait_for_load_state("networkidle")
        expect(page.get_by_role("heading", name="Gráficas")).to_be_visible()
        expect(page.get_by_role("tab", name="Ventas")).to_have_attribute("aria-selected", "true")
        for cid in ("g2", "g1", "g8"):
            expect(page.locator(f"section[aria-labelledby='chart-{cid}-title'] [x-ref='canvas'] svg")).to_be_visible(timeout=15000)
        shot(page, "05b-graficas-ventas")
        page.get_by_role("tab", name="Operación").click()
        expect(page.get_by_role("tab", name="Operación")).to_have_attribute("aria-selected", "true", timeout=10000)
        for cid in ("g3", "g6", "g4", "g5"):
            expect(page.locator(f"section[aria-labelledby='chart-{cid}-title'] [x-ref='canvas'] svg")).to_be_visible(timeout=15000)
        assert "tab=operacion" in page.url, page.url
        shot(page, "05c-graficas-operacion")
        page.get_by_role("tab", name="Inventario").click()
        expect(page.locator("section[aria-labelledby='chart-g7-title'] [x-ref='canvas'] svg")).to_be_visible(timeout=15000)
        with page.expect_download(timeout=15000) as dl:
            page.locator("section[aria-labelledby='chart-g7-title']").get_by_role("button", name="PNG").click()
        assert dl.value.suggested_filename == "g7-2025-09.png", dl.value.suggested_filename
        page.get_by_role("tab", name="Ventas").click()
        expect(page.get_by_role("tab", name="Ventas")).to_have_attribute("aria-selected", "true", timeout=10000)
    step("gráficas: pestañas Ventas/Operación/Inventario, URL con la pestaña y descarga PNG", charts_page)

    def charts_empty_month():
        page.goto(f"{BASE}/graficas", wait_until="networkidle")
        try:
            page.select_option("#context-period", PERIOD)
            expect(page.get_by_text(re.compile(r"Aún no hay días cargados en")).first).to_be_visible(timeout=20000)
            assert page.locator("[x-ref='canvas'] svg").count() == 0
        finally:
            # El período vive en sesión: se restaura pase lo que pase para no arrastrar el fallo a los pasos siguientes
            page.select_option("#context-period", "2025-09")
            expect(page.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas'] svg")).to_be_visible(timeout=20000)
    step("gráficas: un mes vacío muestra el estado vacío y vuelve a dibujar al cambiar de mes", charts_empty_month)

    # ---------- 4. Mes: calendario y cuadro ----------
    def month_page():
        page.click("aside a[href$='/mes']")
        page.wait_for_load_state("networkidle")
        expect(page.get_by_role("heading", name="Septiembre 2025")).to_be_visible()
        expect(page.get_by_text("Todos los días cargados")).to_be_visible()
        expect(page.get_by_text("Cuadro de indicadores")).to_be_visible()
        expect(page.get_by_text("Bs 3.012.770,86")).to_be_visible()
        cells = page.locator("a[aria-label*='de septiembre de 2025']")
        assert cells.count() == 30, f"celdas del calendario: {cells.count()}"
        assert "atípico" in page.locator("a[aria-label*='16 de septiembre de 2025']").get_attribute("aria-label")
        shot(page, "05-mes")
    step("pantalla del mes: calendario de 30 días, atípico marcado y totales", month_page)

    def currency_toggle():
        try:
            page.get_by_role("button", name="$", exact=True).click()
            expect(page.locator("table thead")).not_to_contain_text("Venta Bs", timeout=20000)
            expect(page.locator("table thead")).to_contain_text("Venta $")
        finally:
            # La moneda vive en sesión: se restaura pase lo que pase
            page.get_by_role("button", name="Bs y $").click()
            expect(page.locator("table thead")).to_contain_text("Venta Bs", timeout=20000)
        expect(page.locator("table thead")).to_contain_text("Venta $")
        shot(page, "06-mes-moneda")
    step("conmutador de moneda cambia las columnas de la tabla", currency_toggle)

    def exclude_atypical():
        page.get_by_label("Excluir días atípicos de los promedios").check()
        expect(page.get_by_text("sin 1 atípico")).to_be_visible(timeout=10000)
        expect(page.locator("tfoot")).to_contain_text("Bs 781", timeout=10000)
        page.get_by_label("Excluir días atípicos de los promedios").uncheck()
        expect(page.get_by_text("sin 1 atípico")).to_have_count(0, timeout=10000)
    step("excluir atípicos recalcula los promedios ponderados", exclude_atypical)

    def export_excel():
        with page.expect_download(timeout=20000) as dl:
            page.get_by_role("link", name="Exportar a Excel").click()
        d = dl.value
        path = os.path.join(SHOTS, d.suggested_filename)
        d.save_as(path)
        assert d.suggested_filename == "indicadores-2025-09.xlsx", d.suggested_filename
        assert os.path.getsize(path) > 5000
    step("exportar a Excel descarga indicadores-2025-09.xlsx", export_excel)

    # ---------- 5. Formulario diario ----------
    def form_open():
        page.goto(f"{BASE}/cargar/{day(1)}", wait_until="networkidle")
        expect(page.get_by_role("heading", name=heading(1))).to_be_visible()
        assert "Arrastrada" in page.locator("#rate").evaluate("e => e.parentElement.parentElement.innerText")
        assert page.input_value("#rate") == "177,61", page.input_value("#rate")
        assert page.input_value("#shifts") == "3"
        expect(page.get_by_text("Ayer:").first).to_be_visible()
        shot(page, "07-formulario-vacio")
    step("formulario abre con día de semana derivado, tasa arrastrada y referencia de ayer", form_open)

    def form_preview():
        page.fill("#sales_bs", "91154,02")
        page.locator("#sales_bs").blur()
        time.sleep(0.3)
        assert page.input_value("#sales_bs") == "91.154,02", page.input_value("#sales_bs")
        page.fill("#transactions", "119")
        page.fill("#units", "300")
        page.locator("#units").blur()
        time.sleep(0.9)
        aside = page.locator("aside", has_text="Se calculará").inner_text()
        assert "$ 513,23" in aside, aside          # 91154,02 / 177,61
        assert "Bs 766" in aside, aside            # ticket
        assert "2,5" in aside, aside               # und/compra
        assert "40" in aside, aside                # trn/jornada 119/3 = 39,67 → 40
        shot(page, "08-formulario-preview")
    step("vista previa en el navegador calcula los derivados y formatea al perder el foco", form_preview)

    def form_warning():
        page.fill("#rate", "15")
        page.locator("#rate").blur()
        time.sleep(1.2)
        try:
            expect(page.get_by_text("menor que ayer")).to_be_visible()
        except AssertionError:
            raise AssertionError("sin advertencia de tasa; pantalla: " + page.locator("main").inner_text()[:500])
        badge = page.locator("#rate").evaluate("e => e.parentElement.parentElement.innerText")
        assert "Manual" in badge, badge
        page.get_by_role("button", name="Guardar día").click()
        time.sleep(1.2)
        expect(page.get_by_text(re.compile(r"advertencias? sin revisar"))).to_be_visible()
        assert page.url.endswith(f"/cargar/{day(1)}"), page.url
        shot(page, "09-formulario-advertencia")
    step("advertencia en línea por tasa y franja sin modal al guardar", form_warning)

    def form_fix_and_save():
        page.fill("#rate", "")
        page.locator("#rate").blur()
        expect(page.locator("#rate")).to_have_value("177,61", timeout=8000)
        assert "Arrastrada" in page.locator("#rate").evaluate("e => e.parentElement.parentElement.innerText")
        page.fill("#inventory_units", "9029")
        page.fill("#inventory_value_usd", "21848,73")
        page.locator("#inventory_value_usd").blur()
        time.sleep(0.8)
        page.get_by_role("button", name="Guardar día").click()
        page.wait_for_url(f"{BASE}/cargar/{day(2)}", timeout=15000)
        page.wait_for_load_state("networkidle")
        expect(page.get_by_text("Día guardado.")).to_be_visible()
        expect(page.get_by_role("heading", name=heading(2))).to_be_visible()
        expect(page.get_by_text("Ayer: Bs 91.154,02")).to_be_visible()
        shot(page, "10-guardado-siguiente-dia")
    step("guardar redirige al siguiente día con el aviso y la referencia del día guardado", form_fix_and_save)

    def form_duplicate():
        page.goto(f"{BASE}/cargar/{day(1)}", wait_until="networkidle")
        expect(page.get_by_text("Editar día")).to_be_visible()
        expect(page.get_by_role("button", name="Guardar cambios")).to_be_visible()
        assert page.input_value("#sales_bs") == "91.154,02"
        shot(page, "11-editar-dia")
    step("volver a un día cargado abre el modo de edición", form_duplicate)

    def closed_day():
        page.goto(f"{BASE}/cargar/{day(3)}", wait_until="networkidle")
        page.get_by_role("button", name="Registrar como día cerrado").click()
        page.fill("#closed_reason", "Feriado local")
        page.get_by_role("button", name="Registrar cerrado").click()
        page.wait_for_load_state("networkidle")
        time.sleep(1.0)
        expect(page.get_by_text("Día registrado como cerrado.")).to_be_visible()
    step("registrar día cerrado desde el formulario", closed_day)

    def month_current():
        page.goto(f"{BASE}/mes/{PERIOD}", wait_until="networkidle")
        expect(page.get_by_text("Falta", exact=True).first).to_be_visible()  # leyenda del calendario
        assert "cerrado" in page.locator(f"a[aria-label*='{heading(3)[1:]}']").get_attribute("aria-label")
        closed_row = page.locator("tbody tr", has_text="Cerrado")
        expect(closed_row).to_have_count(1)
        assert "Bs 0,00" not in closed_row.inner_text(), closed_row.inner_text()
        shot(page, "12-mes-actual")
    step("mes actual muestra días cargados, cerrado y faltantes", month_current)

    # ---------- 5f. Fase 4: cerrar y reabrir el mes, candado en el formulario, historial ----------
    month_name = _MONTHS[_TODAY.month - 1]
    month_label = f"{month_name.capitalize()} {_TODAY.year}"

    def close_month():
        page.goto(f"{BASE}/mes/{PERIOD}", wait_until="networkidle")
        page.get_by_role("button", name=f"Cerrar {month_name}").click()
        dialog = page.get_by_role("dialog")
        expect(dialog).to_be_visible()
        expect(dialog.get_by_text("nadie podrá editarlo sin reabrirlo")).to_be_visible()
        # Con días faltantes el botón espera la confirmación explícita (UC-07)
        if dialog.get_by_role("checkbox").count():
            expect(dialog.get_by_role("button", name=f"Cerrar {month_name}")).to_be_disabled()
            dialog.get_by_role("checkbox").check()
            expect(dialog.get_by_role("button", name=f"Cerrar {month_name}")).to_be_enabled(timeout=10000)
        shot(page, "12b-cerrar-mes-dialogo")
        dialog.get_by_role("button", name=f"Cerrar {month_name}").click()
        expect(page.get_by_role("button", name="Reabrir")).to_be_visible(timeout=15000)
        expect(dialog).to_be_hidden()
        expect(page.get_by_text(re.compile(r"Cerrado el \d{2}/\d{2}")).first).to_be_visible()
        expect(page.get_by_text("Historial del mes")).to_be_visible()
        expect(page.get_by_text(re.compile(rf"{month_label} cerrado\.")).first).to_be_visible()
        assert page.get_by_role("button", name=f"Cerrar {month_name}").count() == 0
        shot(page, "12c-mes-cerrado")
    step("mes: cerrar con confirmación, insignia 'Cerrado el', historial y botón Reabrir", close_month)

    def locked_form():
        page.goto(f"{BASE}/cargar/{day(1)}", wait_until="networkidle")
        expect(page.get_by_text(re.compile(rf"{month_label} está cerrado desde el \d{{2}}/\d{{2}}"))).to_be_visible()
        expect(page.get_by_role("link", name="Reabrir desde el mes")).to_be_visible()
        assert page.locator("form[inert]").count() == 1, "el formulario debería estar inerte"
        assert page.get_by_role("button", name="Borrar día").count() == 0
        shot(page, "12d-formulario-cerrado")
    step("formulario: con el mes cerrado queda inerte y avisa desde cuándo", locked_form)

    def reopen_month():
        page.goto(f"{BASE}/mes/{PERIOD}", wait_until="networkidle")
        page.get_by_role("button", name="Reabrir").click()
        dialog = page.get_by_role("dialog")
        dialog.get_by_role("button", name=f"Reabrir {month_name}").click()
        expect(dialog.get_by_text("Escribe el motivo de la reapertura")).to_be_visible(timeout=10000)
        page.fill("#reopen-reason", "Se cargó mal el día 2")
        dialog.get_by_role("button", name=f"Reabrir {month_name}").click()
        expect(page.get_by_text(f"{month_label} reabierto.")).to_be_visible(timeout=10000)
        expect(page.get_by_role("button", name=f"Cerrar {month_name}")).to_be_visible()
        expect(page.get_by_text("Se cargó mal el día 2")).to_be_visible()
        assert page.locator("section[aria-labelledby='history-title'] li").count() == 2
        shot(page, "12e-mes-reabierto")
    step("mes: reabrir exige motivo, avisa y deja dos entradas en el historial", reopen_month)

    def delete_day_and_undo():
        page.goto(f"{BASE}/cargar/{day(1)}", wait_until="networkidle")
        expect(page.get_by_text("Última edición:")).to_be_visible()
        page.get_by_role("button", name="Borrar día").click()
        dialog = page.get_by_role("dialog")
        dialog.get_by_role("button", name="Borrar día").click()
        page.wait_for_url(f"{BASE}/mes/{PERIOD}", timeout=15000)
        expect(page.get_by_text(re.compile(r"^Día \d{2}/\d{2}/\d{4} borrado\.$"))).to_be_visible()
        page.get_by_role("button", name="Deshacer").click()
        expect(page.get_by_text(re.compile(r"^Día \d{2}/\d{2}/\d{4} restaurado\.$"))).to_be_visible(timeout=10000)
        assert "cargado" in page.locator(f"a[aria-label*='{heading(1)[1:]}']").get_attribute("aria-label")
        shot(page, "12f-dia-restaurado")
    step("formulario: borrar día con confirmación y 'Deshacer' desde el aviso lo restaura", delete_day_and_undo)

    # ---------- 6. Móvil ----------
    def mobile():
        m = browser.new_context(viewport={"width": 360, "height": 740}, locale="es-VE", device_scale_factor=2)
        mp = m.new_page()
        mp.goto(f"{BASE}/login", wait_until="networkidle")
        mp.fill("input[type=email]", "admin@guadalupe.local")
        mp.fill("input[type=password]", "password")
        mp.click("button[type=submit]")
        mp.wait_for_url(f"{BASE}/dashboard", timeout=45000)
        mp.goto(f"{BASE}/cargar/{day(4)}", wait_until="networkidle")
        assert mp.evaluate("document.documentElement.scrollWidth") <= 360, "scroll horizontal en móvil (formulario)"
        expect(mp.get_by_role("link", name="Cargar", exact=True)).to_be_visible()
        # Barra fija: derivados clave + Guardar, encima de la navegación inferior y sin solaparse
        bar = mp.locator("form div.fixed")
        expect(bar).to_be_visible()
        expect(bar.get_by_role("button", name="Guardar día")).to_be_visible()
        mp.fill("#sales_bs", "91154,02"); mp.fill("#transactions", "119"); mp.fill("#units", "300"); mp.locator("#units").blur()
        expect(bar).to_contain_text("$ 513,23")
        expect(bar).to_contain_text("2,5")
        bar_bottom = bar.evaluate("e => e.getBoundingClientRect().bottom")
        nav_top = mp.locator("nav[aria-label='Navegación']").evaluate("e => e.getBoundingClientRect().top")
        assert bar_bottom <= nav_top + 0.5, f"la barra ({bar_bottom}) se solapa con la navegación ({nav_top})"
        mp.screenshot(path=os.path.join(SHOTS, "13-movil-formulario.png"), full_page=False)
        mp.goto(f"{BASE}/mes/2025-09", wait_until="networkidle")
        assert mp.evaluate("document.documentElement.scrollWidth") <= 360, "scroll horizontal en móvil (mes)"
        mp.goto(f"{BASE}/dashboard", wait_until="networkidle")
        assert mp.evaluate("document.documentElement.scrollWidth") <= 360, "scroll horizontal en móvil (panel)"
        canvas = mp.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas']")
        expect(canvas.locator("svg")).to_be_visible(timeout=15000)
        assert abs(canvas.bounding_box()["height"] - 220) < 2, canvas.bounding_box()
        mp.screenshot(path=os.path.join(SHOTS, "16-movil-panel.png"), full_page=True)
        mp.goto(f"{BASE}/metas", wait_until="networkidle")
        assert mp.evaluate("document.documentElement.scrollWidth") <= 360, "scroll horizontal en móvil (metas)"
        expect(mp.locator("#target-sales_usd")).to_be_visible()
        mp.screenshot(path=os.path.join(SHOTS, "17-movil-metas.png"), full_page=False)
        mp.screenshot(path=os.path.join(SHOTS, "14-movil-mes.png"), full_page=False)
        mp.get_by_role("button", name="Más").click()
        time.sleep(0.4)
        expect(mp.get_by_role("dialog").get_by_text("Configuración")).to_be_visible()
        mp.screenshot(path=os.path.join(SHOTS, "15-movil-menu.png"), full_page=False)
        m.close()
    step("móvil 360 px: sin scroll horizontal, navegación inferior y menú", mobile)

    # ---------- 7. Logout ----------
    def logout():
        page.goto(f"{BASE}/dashboard", wait_until="networkidle")
        page.click("aside form button[type=submit]")
        page.wait_for_url(f"{BASE}/login", timeout=15000)
    step("salir cierra la sesión", logout)

    browser.close()

print("\n=== RESUMEN ===")
for status, name in results:
    print(status, name)
fails = [r for r in results if r[0] == "FAIL"]
print(f"\n{len(results) - len(fails)}/{len(results)} pasos OK")
if console_errors:
    print("\nErrores de consola del navegador:")
    for e in console_errors[:10]:
        print(" -", e[:200])
sys.exit(1 if fails else 0)
