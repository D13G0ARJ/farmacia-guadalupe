"""
Capturas para docs/MANUAL-USUARIO.md (Fase 7). Requiere el servidor de desarrollo con los datos de
demostración: `php artisan serve --port=8765` y luego `python tests/Browser/manual_shots.py`.
"""
import os
import re

from playwright.sync_api import expect, sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8765")
OUT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "..", "docs", "manual"))
os.makedirs(OUT, exist_ok=True)
expect.set_options(timeout=20000)


def shot(page, name, full=False):
    page.screenshot(path=os.path.join(OUT, f"{name}.png"), full_page=full)


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1280, "height": 800}, locale="es-VE", device_scale_factor=1)
    page = ctx.new_page()

    page.goto(f"{BASE}/login", wait_until="networkidle")
    shot(page, "01-acceso")
    page.fill("input[type=email]", "admin@guadalupe.local")
    page.fill("input[type=password]", "password")
    page.click("button[type=submit]")
    page.wait_for_url(f"{BASE}/dashboard", timeout=45000)
    page.select_option("#context-period", "2025-09")
    expect(page.get_by_role("heading", name="Septiembre 2025")).to_be_visible()
    expect(page.locator("section[aria-labelledby='chart-g2-title'] [x-ref='canvas'] svg")).to_be_visible()
    page.wait_for_timeout(500)
    page.get_by_role("button", name=re.compile("Cómo se calcula venta en dólares")).click()
    shot(page, "04-panel")

    page.goto(f"{BASE}/cargar/2025-09-10", wait_until="networkidle")
    shot(page, "02-cargar-dia", full=True)

    page.goto(f"{BASE}/mes/2025-09", wait_until="networkidle")
    shot(page, "03-mes", full=True)

    page.goto(f"{BASE}/graficas?tab=operacion", wait_until="networkidle")
    expect(page.locator("section[aria-labelledby='chart-g3-title'] [x-ref='canvas'] svg")).to_be_visible()
    shot(page, "05-graficas")

    page.goto(f"{BASE}/metas", wait_until="networkidle")
    shot(page, "06-metas", full=True)

    page.goto(f"{BASE}/anio", wait_until="networkidle")
    shot(page, "07-anio")

    page.goto(f"{BASE}/importar", wait_until="networkidle")
    shot(page, "08-importar")

    page.goto(f"{BASE}/administracion", wait_until="networkidle")
    shot(page, "09-administracion")

    browser.close()
    print("capturas en", OUT)
