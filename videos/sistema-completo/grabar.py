# -*- coding: utf-8 -*-
"""
Graba el recorrido completo del sistema (escritorio 1920x1080) contra el servidor de demostración.
Requiere: durations.json (gen_audio.py), servidor en BASE sobre la copia capacitacion.sqlite con el
escenario preparado (prep_escenario.php) y el cuadro de ejemplo en resources/samples.
Produce out/video_raw.webm y timeline.json (inicio de cada escena en segundos).
"""
import json
import os
import re
import shutil
import time

from playwright.sync_api import sync_playwright

BASE = os.environ.get("VIDEO_BASE", "http://127.0.0.1:8124")
HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
SAMPLE = os.path.join(ROOT, "indicadores", "resources", "samples", "cuadro-ejemplo.xlsx")
OUT = os.path.join(HERE, "out")
os.makedirs(OUT, exist_ok=True)
for v in os.listdir(OUT):
    if v.endswith(".webm"):
        os.remove(os.path.join(OUT, v))

with open(os.path.join(HERE, "durations.json"), encoding="utf-8") as f:
    DUR = json.load(f)

# Cursor visible + ondita en cada clic: el espectador ve por dónde va y dónde se pulsa.
CURSOR_JS = """
(() => {
  function ensure() {
    if (document.getElementById('cap-cursor') || !document.body) return null;
    const s = document.createElement('style');
    s.textContent = `#cap-cursor{position:fixed;left:0;top:0;width:26px;height:26px;pointer-events:none;z-index:2147483647;` +
      `transform:translate(-3px,-2px);transition:left .12s linear,top .12s linear;filter:drop-shadow(0 1px 2px rgba(0,0,0,.45))}` +
      `.cap-tap{position:fixed;width:56px;height:56px;margin:-28px 0 0 -28px;border-radius:50%;background:rgba(29,111,229,.28);` +
      `border:3px solid rgba(29,111,229,.95);pointer-events:none;z-index:2147483646;animation:capTap .65s ease-out forwards}` +
      `@keyframes capTap{from{transform:scale(.45);opacity:1}to{transform:scale(1.7);opacity:0}}`;
    document.head.appendChild(s);
    const c = document.createElement('div');
    c.id = 'cap-cursor';
    c.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26"><path d="M5 3l14 8.5-6.2 1.4-3.3 5.6z" fill="#fff" stroke="#111" stroke-width="1.6" stroke-linejoin="round"/></svg>';
    c.style.left = '960px'; c.style.top = '540px';
    document.body.appendChild(c);
    return c;
  }
  window.addEventListener('mousemove', e => { const c = ensure() || document.getElementById('cap-cursor'); if (c) { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; } }, true);
  window.addEventListener('pointerdown', e => {
    try { ensure(); const d = document.createElement('div'); d.className = 'cap-tap';
      d.style.left = e.clientX + 'px'; d.style.top = e.clientY + 'px'; document.body.appendChild(d); setTimeout(() => d.remove(), 750); } catch (_) {}
  }, true);
  document.addEventListener('DOMContentLoaded', ensure);
})();
"""
# Los recorridos guiados arrancan solos la primera vez: se marcan como vistos y se lanza uno a propósito en la escena de ayuda.
SEEN_JS = "localStorage.setItem('tours-seen', JSON.stringify({1: {dashboard:1,'records.create':1,month:1,charts:1,goals:1,annual:1,rates:1,imports:1,admin:1,profile:1,shell:1}}))"

timeline = []
T0 = None


def ahora():
    return time.perf_counter() - T0


def start_scene(page, sid, pre=0.5):
    page.wait_for_timeout(int(pre * 1000))
    timeline.append({"id": sid, "start": round(ahora(), 3)})
    print("ESCENA %s @ %.1f s" % (sid, timeline[-1]["start"]), flush=True)


def end_scene(page, tail=0.9):
    sc = timeline[-1]
    resto = sc["start"] + DUR[sc["id"]] + tail - ahora()
    if resto > 0:
        page.wait_for_timeout(int(resto * 1000))


def espera(page, frac):
    """Sincroniza una acción con un punto de la narración (0.0 a 1.0)."""
    sc = timeline[-1]
    resto = sc["start"] + DUR[sc["id"]] * frac - ahora()
    if resto > 0:
        page.wait_for_timeout(int(resto * 1000))


def texto_visible(page, texto, timeout=20000, exact=False):
    limite = time.time() + timeout / 1000
    loc = page.get_by_text(texto, exact=exact)
    while time.time() < limite:
        for i in range(min(loc.count(), 12)):
            try:
                if loc.nth(i).is_visible():
                    return loc.nth(i)
            except Exception:
                pass
        page.wait_for_timeout(200)
    raise TimeoutError("no apareció visible: " + texto)


def scroll_to(page, loc, margen=160):
    try:
        loc.evaluate("(e,m)=>{const r=e.getBoundingClientRect();window.scrollBy({top:r.top-m,behavior:'smooth'})}", margen)
        page.wait_for_timeout(900)
    except Exception as e:
        print("  aviso scroll:", repr(e)[:110])


def scroll_top(page):
    page.evaluate("window.scrollTo({top:0,behavior:'smooth'})")
    page.wait_for_timeout(700)


def glide(page, loc):
    """Lleva el cursor hasta el elemento, despacio, y lo deja encima. Si no está, no pasa nada."""
    try:
        if loc.count() == 0:
            print("  aviso glide: no existe", loc)
            return None
        box = loc.first.bounding_box(timeout=3000)
    except Exception as e:
        print("  aviso glide:", repr(e)[:110])
        return None
    if not box:
        return None
    page.mouse.move(box["x"] + box["width"] / 2, box["y"] + box["height"] / 2, steps=22)
    page.wait_for_timeout(180)
    return box


def click(page, loc, timeout=15000):
    """Clic humano: el cursor se desplaza hasta el botón y pulsa."""
    loc.wait_for(state="visible", timeout=timeout)
    try:
        loc.evaluate("e=>{const r=e.getBoundingClientRect(); if(r.top<90||r.bottom>window.innerHeight-40) window.scrollBy({top:r.top-260,behavior:'smooth'})}")
        page.wait_for_timeout(500)
    except Exception:
        pass
    glide(page, loc)
    loc.click(timeout=timeout)


def teclear(page, loc, texto, delay=55):
    click(page, loc)
    loc.fill("")
    loc.type(texto, delay=delay)


def click_hasta(page, loc, esperado, intentos=3, timeout=4000):
    """Pulsa y comprueba que apareció lo esperado; si el clic se perdió, vuelve a pulsar."""
    for intento in range(intentos):
        click(page, loc)
        try:
            esperado.wait_for(state="visible", timeout=timeout)
            return True
        except Exception:
            print("  aviso clic perdido (intento %d)" % (intento + 1))
    return False


def nav(page, key, path):
    """Clic en el menú lateral; si el clic se pierde (una petición en curso), reintenta y al final entra por URL."""
    for intento in range(2):
        try:
            click(page, page.locator("aside [data-tour='%s']" % key))
            page.wait_for_url(re.compile("/" + path + r"(/|$|\?)"), timeout=8000)
            break
        except Exception as e:
            print("  aviso nav %s (intento %d): %s" % (key, intento + 1, repr(e)[:90]))
    else:
        page.goto(BASE + "/" + path)
    page.wait_for_load_state("networkidle")


def confirmar_advertencias(page):
    strip = page.get_by_role("button", name="Guardar de todos modos")
    try:
        strip.wait_for(state="visible", timeout=3500)
        page.wait_for_timeout(800)
        click(page, strip)
    except Exception:
        pass


def cerrar_toast_espera(page):
    page.wait_for_timeout(300)


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    ctx = browser.new_context(
        viewport={"width": 1920, "height": 1080}, device_scale_factor=1, locale="es-VE",
        record_video_dir=OUT, record_video_size={"width": 1920, "height": 1080}, accept_downloads=True,
    )
    ctx.add_init_script(CURSOR_JS)
    ctx.add_init_script(SEEN_JS)
    page = ctx.new_page()
    T0 = time.perf_counter()
    page.on("dialog", lambda d: d.accept())
    page.on("console", lambda m: print("  console.error:", m.text[:140]) if m.type == "error" else None)

    def escena_01():
        # Portada
        page.goto("file:///" + os.path.join(HERE, "cards", "intro.html").replace("\\", "/"))
        start_scene(page, "01_intro", pre=0.8)
        end_scene(page, tail=0.6)

    def escena_02():
        # Acceso
        page.goto(BASE + "/login", wait_until="networkidle")
        start_scene(page, "02_login", pre=0.3)
        espera(page, 0.12)
        teclear(page, page.locator("input[type=email]"), "admin@guadalupe.local", delay=45)
        teclear(page, page.locator("input[type=password]"), "password", delay=60)
        espera(page, 0.86)
        click(page, page.get_by_role("button", name="Entrar"))
        page.wait_for_url(BASE + "/dashboard", timeout=60000)
        page.wait_for_load_state("networkidle")
        end_scene(page)

    def escena_03():
        # Panel: héroe
        page.select_option("#context-period", "2025-09")
        texto_visible(page, "Septiembre 2025 cerró", timeout=30000)
        page.wait_for_selector("section[aria-labelledby='chart-g9-title'] [x-ref='canvas'] svg", timeout=30000)
        start_scene(page, "03_panel_hero", pre=0.4)
        glide(page, page.locator("[data-tour='dash-hero']"))
        espera(page, 0.55)
        glide(page, page.locator("section[aria-labelledby='chart-g9-title'] [x-ref='canvas']"))
        end_scene(page)

    def escena_04():
        # Panel: tarjetas
        start_scene(page, "04_panel_kpi", pre=0.2)
        scroll_to(page, page.locator("[data-tour='dash-kpis']"), margen=110)
        glide(page, page.locator("[data-tour='dash-kpis'] > div").first)
        espera(page, 0.42)
        click(page, page.locator("[data-tour='kpi-help']").first)
        espera(page, 0.72)
        page.keyboard.press("Escape")
        click(page, page.locator("[data-tour='dash-more'] summary"))
        page.wait_for_timeout(400)
        scroll_to(page, page.locator("[data-tour='dash-more']"), margen=120)
        end_scene(page)

    def escena_05():
        # Panel: gráficas
        start_scene(page, "05_panel_graficas", pre=0.2)
        scroll_to(page, page.locator("[data-tour='dash-charts']"), margen=90)
        glide(page, page.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas']"))
        espera(page, 0.55)
        click(page, page.locator("section[aria-labelledby='chart-g2-title']").get_by_role("button", name="Datos"))
        espera(page, 0.85)
        click(page, page.locator("section[aria-labelledby='chart-g2-title']").get_by_role("button", name="Datos"))
        end_scene(page)

    def escena_06():
        # Panel: avisos y PDF
        start_scene(page, "06_panel_avisos", pre=0.2)
        scroll_to(page, page.locator("[data-tour='dash-notices']"), margen=140)
        glide(page, page.locator("[data-tour='dash-notices']"))
        espera(page, 0.5)
        scroll_top(page)
        espera(page, 0.68)
        try:
            with page.expect_download(timeout=12000):
                click(page, page.locator("[data-tour='dash-pdf']"))
        except Exception as e:
            print("  aviso PDF:", repr(e)[:100])
        end_scene(page)

    def escena_07():
        # Barra de contexto
        start_scene(page, "07_contexto", pre=0.2)
        click(page, page.get_by_role("button", name="Mes anterior"))
        texto_visible(page, "Agosto 2025", timeout=30000)
        espera(page, 0.3)
        click(page, page.get_by_role("button", name="Mes siguiente"))
        texto_visible(page, "Septiembre 2025", timeout=30000)
        espera(page, 0.5)
        click(page, page.get_by_role("button", name="$", exact=True))
        page.wait_for_timeout(1500)
        espera(page, 0.72)
        click(page, page.get_by_role("button", name="Bs y $"))
        page.wait_for_timeout(1200)
        espera(page, 0.88)
        glide(page, page.locator("[data-tour='new-day-button']"))
        end_scene(page)

    def escena_08():
        # Cargar día: abrir
        click(page, page.locator("[data-tour='new-day-button']"))
        page.wait_for_url(re.compile(r"/cargar"), timeout=30000)
        page.wait_for_load_state("networkidle")
        start_scene(page, "08_cargar_abrir", pre=0.3)
        glide(page, page.locator("[data-tour='form-heading']"))
        espera(page, 0.55)
        glide(page, page.locator("[data-tour='form-preview']"))
        espera(page, 0.8)
        glide(page, page.locator("[data-tour='form-sales']"))
        end_scene(page)

    def escena_09():
        # Cargar día: campos
        start_scene(page, "09_cargar_campos", pre=0.1)
        teclear(page, page.locator("#sales_bs"), "923450,75", delay=70)
        page.locator("#sales_bs").blur()
        espera(page, 0.32)
        glide(page, page.locator("[data-tour='form-rate']"))
        espera(page, 0.5)
        teclear(page, page.locator("#transactions"), "134", delay=90)
        teclear(page, page.locator("#units"), "301", delay=90)
        page.locator("#units").blur()
        espera(page, 0.78)
        glide(page, page.locator("[data-tour='form-preview']"))
        end_scene(page)

    def escena_10():
        # Cargar día: inventario y observación
        start_scene(page, "10_cargar_inventario", pre=0.1)
        inv = page.locator("#inventory_units")
        if inv.count() and inv.is_visible():
            teclear(page, inv, "9840", delay=70)
            teclear(page, page.locator("#inventory_value_usd"), "22450", delay=70)
            page.locator("#inventory_value_usd").blur()
        else:
            glide(page, page.locator("[data-tour='form-inventory']"))
        espera(page, 0.4)
        teclear(page, page.locator("#notes"), "Día normal. Llegó el pedido de la tarde.", delay=40)
        espera(page, 0.6)
        glide(page, page.locator("[data-tour='form-atypical']"))
        end_scene(page)

    def escena_11():
        # Cargar día: guardar
        start_scene(page, "11_cargar_guardar", pre=0.1)
        espera(page, 0.2)
        url_antes = page.url
        click(page, page.locator("[data-tour='form-save']"))
        confirmar_advertencias(page)
        page.wait_for_url(lambda u: u != url_antes, timeout=30000)
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(800)  # que Livewire termine de asentar la redirección antes del siguiente clic
        end_scene(page)

    def escena_12():
        # Mes: calendario
        nav(page, 'nav-mes', 'mes')
        page.wait_for_load_state("networkidle")
        start_scene(page, "12_mes_calendario", pre=0.3)
        glide(page, page.locator("[data-tour='month-calendar']"))
        espera(page, 0.35)
        glide(page, page.locator("[data-tour='month-legend']"))
        espera(page, 0.55)
        glide(page, page.locator("[data-tour='month-missing']"))
        espera(page, 0.78)
        page.select_option("#context-period", "2025-09")
        texto_visible(page, "Todos los días cargados", timeout=30000)
        end_scene(page)

    def escena_13():
        # Mes: cuadro
        start_scene(page, "13_mes_cuadro", pre=0.2)
        scroll_to(page, page.locator("[data-tour='month-table']"), margen=150)
        glide(page, page.locator("[data-tour='month-table'] thead"))
        espera(page, 0.38)
        click(page, page.get_by_role("button", name="Venta Bs"))
        page.wait_for_timeout(1200)
        espera(page, 0.55)
        teclear(page, page.locator("[data-tour='month-search'] input"), "16", delay=120)
        page.wait_for_timeout(1500)
        espera(page, 0.72)
        page.locator("[data-tour='month-search'] input").fill("")
        click(page, page.get_by_role("button", name="Fecha"))
        page.wait_for_timeout(1000)
        glide(page, page.locator("[data-tour='month-totals']"))
        end_scene(page)

    def escena_14():
        # Mes: excluir atípicos, exportar, imprimir
        start_scene(page, "14_mes_excluir_exportar", pre=0.1)
        scroll_to(page, page.locator("[data-tour='month-table']"), margen=150)
        click(page, page.locator("[data-tour='month-exclude'] input"))
        page.wait_for_timeout(1500)
        glide(page, page.locator("[data-tour='month-totals']"))
        espera(page, 0.45)
        click(page, page.locator("[data-tour='month-exclude'] input"))
        scroll_top(page)
        espera(page, 0.55)
        try:
            with page.expect_download(timeout=30000):
                click(page, page.locator("[data-tour='month-export']"))
        except Exception as e:
            print("  aviso Excel:", repr(e)[:100])
        espera(page, 0.85)
        glide(page, page.locator("[data-tour='month-print']"))
        end_scene(page)

    def escena_15():
        # Mes: cerrar y reabrir
        start_scene(page, "15_mes_cerrar", pre=0.1)
        dialog = page.get_by_role("dialog")
        click_hasta(page, page.locator("[data-tour='month-close']"), dialog)
        dialog.wait_for(state="visible", timeout=5000)
        espera(page, 0.25)
        if dialog.get_by_role("checkbox").count():
            click(page, dialog.get_by_role("checkbox"))
        click(page, dialog.get_by_role("button", name=re.compile(r"^Cerrar ")))
        texto_visible(page, "Cerrado el", timeout=20000)
        espera(page, 0.5)
        dialog = page.get_by_role("dialog")
        click_hasta(page, page.locator("[data-tour='month-reopen']"), dialog)
        dialog.wait_for(state="visible", timeout=5000)
        teclear(page, page.locator("#reopen-reason"), "Corrección del día 16", delay=45)
        espera(page, 0.78)
        click(page, dialog.get_by_role("button", name=re.compile(r"^Reabrir ")))
        texto_visible(page, "Reabierto", timeout=20000)
        scroll_to(page, page.locator("[data-tour='month-history']"), margen=200)
        end_scene(page)

    def escena_16():
        # Gráficas: ventas
        nav(page, 'nav-graficas', 'graficas')
        page.wait_for_selector("section[aria-labelledby='chart-g2-title'] [x-ref='canvas'] svg", timeout=30000)
        start_scene(page, "16_graficas_ventas", pre=0.3)
        glide(page, page.locator("[data-tour='charts-tab-ventas']"))
        espera(page, 0.45)
        scroll_to(page, page.locator("section[aria-labelledby='chart-g8-title']"), margen=120)
        glide(page, page.locator("section[aria-labelledby='chart-g8-title'] [x-ref='canvas']"))
        end_scene(page)

    def escena_17():
        # Gráficas: operación e inventario
        start_scene(page, "17_graficas_operacion", pre=0.1)
        scroll_top(page)
        click(page, page.locator("[data-tour='charts-tab-operacion']"))
        page.wait_for_selector("section[aria-labelledby='chart-g3-title'] [x-ref='canvas'] svg", timeout=30000)
        espera(page, 0.35)
        scroll_to(page, page.locator("section[aria-labelledby='chart-g4-title']"), margen=120)
        espera(page, 0.62)
        scroll_top(page)
        click(page, page.locator("[data-tour='charts-tab-inventario']"))
        page.wait_for_selector("section[aria-labelledby='chart-g7-title'] [x-ref='canvas'] svg", timeout=30000)
        end_scene(page)

    def escena_18():
        # Gráficas: tasa y año, ampliar
        start_scene(page, "18_graficas_tasa_anio", pre=0.1)
        click(page, page.locator("[data-tour='charts-tab-tasa']"))
        page.wait_for_selector("section[aria-labelledby='chart-g10-title'] [x-ref='canvas'] svg", timeout=30000)
        espera(page, 0.33)
        click(page, page.locator("[data-tour='charts-tab-anio']"))
        page.wait_for_selector("section[aria-labelledby='chart-g11-title'] [x-ref='canvas'] svg", timeout=30000)
        espera(page, 0.55)
        page.select_option("#annual-indicator", "transactions")
        page.wait_for_timeout(1500)
        espera(page, 0.75)
        click(page, page.locator("section[aria-labelledby='chart-g11-title']").get_by_role("button", name="Ampliar"))
        page.wait_for_timeout(2500)
        page.keyboard.press("Escape")
        end_scene(page)

    def escena_19():
        # Metas: este mes
        nav(page, 'nav-metas', 'metas')
        page.wait_for_load_state("networkidle")
        start_scene(page, "19_metas_mes", pre=0.3)
        glide(page, page.locator("[data-tour='goals-table'] thead"))
        espera(page, 0.45)
        glide(page, page.locator("[data-tour='goals-status']").first)
        espera(page, 0.62)
        campo = page.locator("#target-units")
        teclear(page, campo, "8200", delay=80)
        campo.blur()
        page.wait_for_timeout(1500)
        end_scene(page)

    def escena_20():
        # Metas: año
        start_scene(page, "20_metas_anio", pre=0.1)
        click(page, page.get_by_role("button", name="Año", exact=True))
        page.wait_for_selector("[data-tour='goals-grid']", timeout=20000)
        espera(page, 0.35)
        glide(page, page.locator("[data-tour='goals-grid']"))
        espera(page, 0.5)
        teclear(page, page.locator("#growth"), "5", delay=80)
        click(page, page.get_by_role("button", name="Aplicar a todo el año"))
        page.wait_for_timeout(1200)
        espera(page, 0.85)
        click(page, page.get_by_role("button", name="Guardar metas"))
        page.wait_for_timeout(1500)
        page.wait_for_load_state("networkidle")
        end_scene(page)

    def escena_21():
        # Año
        nav(page, 'nav-anio', 'anio')
        page.wait_for_load_state("networkidle")
        start_scene(page, "21_anio", pre=0.3)
        glide(page, page.locator("[data-tour='annual-table'] thead"))
        espera(page, 0.35)
        glide(page, page.get_by_text("$ 18.611").first)
        page.wait_for_timeout(1500)
        espera(page, 0.62)
        glide(page, page.locator("[data-tour='annual-year-column']"))
        espera(page, 0.85)
        glide(page, page.locator("[data-tour='annual-export']"))
        end_scene(page)

    def escena_22():
        # Tasa BCV
        nav(page, 'nav-tasas', 'tasas')
        page.wait_for_load_state("networkidle")
        start_scene(page, "22_tasas", pre=0.3)
        glide(page, page.locator("[data-tour='rates-status']"))
        espera(page, 0.3)
        scroll_to(page, page.locator("[data-tour='rates-table']"), margen=150)
        glide(page, page.locator("[data-tour='rates-table'] tbody tr").first)
        espera(page, 0.62)
        inline = page.locator("[data-tour='rates-table'] input")
        click_hasta(page, page.locator("[data-tour='rates-edit']").first, inline)
        inline.wait_for(state="visible", timeout=5000)
        inline.type("148,50", delay=90)
        espera(page, 0.9)
        inline.press("Enter")
        page.wait_for_timeout(1200)
        end_scene(page)

    def escena_23():
        # Tasa BCV: consultar, histórico, recalcular
        scroll_top(page)
        start_scene(page, "23_tasas_historico", pre=0.1)
        click(page, page.locator("[data-tour='rates-fetch']"))
        page.wait_for_timeout(2500)
        espera(page, 0.35)
        dialog = page.get_by_role("dialog")
        click_hasta(page, page.locator("[data-tour='rates-backfill']"), dialog)
        dialog.wait_for(state="visible", timeout=5000)
        espera(page, 0.62)
        click(page, dialog.get_by_role("button", name="Cancelar"))
        espera(page, 0.72)
        dialog = page.get_by_role("dialog")
        click_hasta(page, page.locator("[data-tour='rates-recalc']"), dialog)
        dialog.wait_for(state="visible", timeout=5000)
        espera(page, 0.95)
        click(page, dialog.get_by_role("button", name="Cancelar"))
        end_scene(page)

    def escena_24():
        # Importar: subir
        nav(page, 'nav-importar', 'importar')
        page.wait_for_load_state("networkidle")
        start_scene(page, "24_importar_subir", pre=0.3)
        glide(page, page.locator("[data-tour='import-dropzone']"))
        espera(page, 0.5)
        page.set_input_files("#import-files", SAMPLE)
        texto_visible(page, "cuadro-ejemplo.xlsx", timeout=20000)
        espera(page, 0.85)
        click(page, page.locator("[data-tour='import-analyze']"))
        page.wait_for_selector("[data-tour='import-review']", timeout=60000)
        end_scene(page)

    def escena_25():
        # Importar: revisión
        start_scene(page, "25_importar_revision", pre=0.2)
        glide(page, page.locator("[data-tour='import-review']").first)
        if not page.locator("[data-tour='import-decision']").count():
            click(page, page.locator("[data-tour='import-toggle']").first)
            page.wait_for_timeout(1200)
        espera(page, 0.35)
        selects = page.locator("[data-tour='import-decision']")
        for i in range(min(selects.count(), 4)):
            sel = selects.nth(i)
            try:
                if sel.input_value() == "":
                    scroll_to(page, sel, margen=300)
                    glide(page, sel)
                    opciones = sel.locator("option").evaluate_all("os => os.map(o => o.value).filter(v => v)")
                    if opciones:
                        sel.select_option(opciones[0])
                        page.wait_for_timeout(900)
            except Exception as e:
                print("  aviso decisión:", repr(e)[:100])
        espera(page, 0.8)
        scroll_to(page, page.locator("[data-tour='import-preview']"), margen=140)
        end_scene(page)

    def escena_26():
        # Importar: confirmar
        start_scene(page, "26_importar_confirmar", pre=0.1)
        scroll_to(page, page.locator("[data-tour='import-confirm']"), margen=520)
        glide(page, page.locator("[data-tour='import-confirm']"))
        espera(page, 0.35)
        click(page, page.locator("[data-tour='import-confirm']"))
        texto_visible(page, "importado.", timeout=60000)
        glide(page, page.locator("[data-tour='import-result']").first)
        end_scene(page)

    def escena_27():
        # Administración: usuarios
        nav(page, 'nav-admin', 'administracion')
        page.wait_for_load_state("networkidle")
        start_scene(page, "27_admin_usuarios", pre=0.3)
        espera(page, 0.12)
        dialog = page.get_by_role("dialog")
        click_hasta(page, page.locator("[data-tour='admin-new-user']"), dialog)
        dialog.wait_for(state="visible", timeout=5000)
        teclear(page, page.locator("#user-name"), "Ana Pérez", delay=60)
        teclear(page, page.locator("#user-email"), "ana.perez@farmacia.com", delay=40)
        page.select_option("#user-role", "supervision")
        page.wait_for_timeout(800)
        espera(page, 0.6)
        click(page, dialog.get_by_role("button", name="Crear usuario"))
        texto_visible(page, "Entrégale estos datos", timeout=20000)
        espera(page, 0.8)
        click(page, page.get_by_role("button", name="Copiar"))
        page.wait_for_timeout(800)
        glide(page, page.get_by_role("row", name=re.compile("Ana Pérez")).locator("[data-tour='admin-user-toggle']"))
        end_scene(page)

    def escena_28():
        # Administración: sedes y parámetros
        start_scene(page, "28_admin_sedes_parametros", pre=0.1)
        click(page, page.locator("[data-tour='admin-tab-sedes']"))
        page.wait_for_selector("#panel-sedes", timeout=20000)
        espera(page, 0.15)
        click(page, page.locator("#panel-sedes").get_by_role("button", name="Editar").first)
        dialog = page.get_by_role("dialog")
        dialog.wait_for(state="visible", timeout=10000)
        espera(page, 0.38)
        click(page, dialog.get_by_role("button", name="Cancelar"))
        click(page, page.locator("[data-tour='admin-tab-parametros']"))
        page.wait_for_selector("#panel-parametros", timeout=20000)
        espera(page, 0.55)
        scroll_to(page, page.locator("[data-tour='admin-settings-goals']"), margen=120)
        espera(page, 0.78)
        scroll_to(page, page.locator("[data-tour='admin-settings-mail']"), margen=120)
        glide(page, page.locator("[data-tour='admin-settings-mail']"))
        end_scene(page)

    def escena_29():
        # Administración: bitácora
        start_scene(page, "29_admin_bitacora", pre=0.1)
        scroll_top(page)
        click(page, page.locator("[data-tour='admin-tab-bitacora']"))
        page.wait_for_selector("#panel-bitacora", timeout=20000)
        espera(page, 0.45)
        glide(page, page.locator("[data-tour='admin-log-list']"))
        espera(page, 0.6)
        teclear(page, page.locator("[data-tour='admin-log-search'] input"), "Admin", delay=90)
        page.wait_for_timeout(1200)
        espera(page, 0.82)
        ver = page.get_by_role("button", name=re.compile(r"^Ver cambios"))
        if ver.count():
            click(page, ver.first)
        end_scene(page)

    def escena_30():
        # Perfil
        nav(page, 'nav-perfil', 'profile')
        page.wait_for_load_state("networkidle")
        start_scene(page, "30_perfil", pre=0.3)
        glide(page, page.locator("[data-tour='profile-name']"))
        espera(page, 0.5)
        glide(page, page.locator("[data-tour='profile-password']"))
        end_scene(page)

    def escena_31():
        # Ayuda y recorridos guiados
        nav(page, 'nav-panel', 'dashboard')
        page.wait_for_load_state("networkidle")
        start_scene(page, "31_ayuda", pre=0.3)
        panel = page.get_by_role("dialog", name=re.compile("Ayuda"))
        click_hasta(page, page.locator("[data-tour='help-button']"), panel)
        panel.wait_for(state="visible", timeout=5000)
        espera(page, 0.28)
        click(page, panel.get_by_text("¿Cómo se calcula cada indicador?"))
        page.wait_for_timeout(1200)
        espera(page, 0.5)
        click(page, panel.get_by_role("button", name="Ver el recorrido de esta pantalla"))
        page.wait_for_selector(".driver-popover", timeout=15000)
        for _ in range(4):
            espera(page, min(0.62 + _ * 0.09, 0.95))
            click(page, page.locator(".driver-popover-next-btn"))
            page.wait_for_timeout(500)
        end_scene(page, tail=1.2)
        page.keyboard.press("Escape")
        page.wait_for_timeout(600)

    def escena_32():
        # Cierre
        page.goto("file:///" + os.path.join(HERE, "cards", "outro.html").replace("\\", "/"))
        start_scene(page, "32_outro", pre=0.6)
        end_scene(page, tail=1.5)


    ESCENAS = [
        ("01_intro", escena_01),
        ("02_login", escena_02),
        ("03_panel_hero", escena_03),
        ("04_panel_kpi", escena_04),
        ("05_panel_graficas", escena_05),
        ("06_panel_avisos", escena_06),
        ("07_contexto", escena_07),
        ("08_cargar_abrir", escena_08),
        ("09_cargar_campos", escena_09),
        ("10_cargar_inventario", escena_10),
        ("11_cargar_guardar", escena_11),
        ("12_mes_calendario", escena_12),
        ("13_mes_cuadro", escena_13),
        ("14_mes_excluir_exportar", escena_14),
        ("15_mes_cerrar", escena_15),
        ("16_graficas_ventas", escena_16),
        ("17_graficas_operacion", escena_17),
        ("18_graficas_tasa_anio", escena_18),
        ("19_metas_mes", escena_19),
        ("20_metas_anio", escena_20),
        ("21_anio", escena_21),
        ("22_tasas", escena_22),
        ("23_tasas_historico", escena_23),
        ("24_importar_subir", escena_24),
        ("25_importar_revision", escena_25),
        ("26_importar_confirmar", escena_26),
        ("27_admin_usuarios", escena_27),
        ("28_admin_sedes_parametros", escena_28),
        ("29_admin_bitacora", escena_29),
        ("30_perfil", escena_30),
        ("31_ayuda", escena_31),
        ("32_outro", escena_32),
    ]


    def correr_escenas():
        for sid, fn in ESCENAS:
            antes = len(timeline)
            try:
                fn()
            except Exception as e:
                print("  FALLO escena %s: %s" % (sid, repr(e)[:160]), flush=True)
                try:
                    page.keyboard.press("Escape")
                except Exception:
                    pass
                if len(timeline) == antes:
                    # No llegó a arrancar la narración: se deja correr igual para no desalinear el audio.
                    start_scene(page, sid, pre=0.2)
                try:
                    end_scene(page)
                except Exception:
                    pass


    correr_escenas()


    video = page.video
    ctx.close()
    shutil.move(video.path(), os.path.join(OUT, "video_raw.webm"))
    browser.close()

with open(os.path.join(HERE, "timeline.json"), "w", encoding="utf-8") as f:
    json.dump(timeline, f, indent=2)
print("GRABACION COMPLETA · %.0f s" % ahora())
