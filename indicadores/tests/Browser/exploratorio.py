# -*- coding: utf-8 -*-
"""
Pruebas exploratorias de robustez: se comporta como un usuario que mete datos raros, va a URLs
inválidas, cierra meses, edita a la vez desde dos navegadores, sube archivos malos y entra con un
rol limitado. No falla al primer error: registra PASS / FAIL / INFO por caso y sigue.

Requisitos: servidor `php artisan serve --port=8765` sobre la base de desarrollo con la demo
(DemoSeeder + DemoUsersSeeder). Deja capturas en tests/Browser/shots/explora-*.png.
"""
import os
import re
import sys
import time
import traceback

from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8765")
HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = os.path.join(HERE, "shots")
os.makedirs(SHOTS, exist_ok=True)
SAMPLE = os.path.join(HERE, "..", "..", "resources", "samples", "cuadro-ejemplo.xlsx")
TMP = os.path.join(HERE, "..", "..", "storage", "app", "explora-tmp")
os.makedirs(TMP, exist_ok=True)

resultados = []
console_errors = []


def registrar(estado, caso, detalle=""):
    resultados.append((estado, caso, detalle))
    print("%-5s %s%s" % (estado, caso, (" :: " + detalle) if detalle else ""), flush=True)


def caso(nombre):
    def deco(fn):
        def wrapper(*a, **k):
            try:
                fn(*a, **k)
            except Exception as e:
                registrar("FAIL", nombre, "excepción: " + repr(e)[:200])
                try:
                    a[0].screenshot(path=os.path.join(SHOTS, "explora-FAIL-%s.png" % re.sub(r"\W+", "_", nombre)[:60]))
                except Exception:
                    pass
        wrapper.__name__ = fn.__name__
        return wrapper
    return deco


def texto(page, t, timeout=6000):
    """True si el texto aparece visible en `timeout` ms."""
    limite = time.time() + timeout / 1000
    loc = page.get_by_text(t)
    while time.time() < limite:
        for i in range(min(loc.count(), 10)):
            try:
                if loc.nth(i).is_visible():
                    return True
            except Exception:
                pass
        page.wait_for_timeout(150)
    return False


def es_500(page):
    body = page.locator("body").inner_text()[:600]
    return ("Whoops" in body) or ("Server Error" in body) or ("ErrorException" in body) or ("Internal Server Error" in body)


def login(page, email, password="password"):
    for intento in range(2):
        page.goto(BASE + "/login", wait_until="networkidle")
        page.get_by_role("button", name="Entrar").wait_for(state="visible", timeout=15000)
        page.wait_for_timeout(400)
        page.locator("input[type=email]").fill(email)
        page.locator("input[type=password]").fill(password)
        page.get_by_role("button", name="Entrar").click()
        try:
            page.wait_for_url(lambda u: "/login" not in u, timeout=30000)
            page.wait_for_load_state("networkidle")
            return
        except Exception:
            print("  aviso: login de %s no entró (intento %d), url %s" % (email, intento + 1, page.url), flush=True)


def logout(page):
    try:
        page.goto(BASE + "/dashboard", wait_until="networkidle")
        page.locator("aside [data-tour='nav-salir']").click()
        page.wait_for_load_state("networkidle")
    except Exception:
        pass


def form_error_visible(page):
    """Algún mensaje de error de validación visible."""
    for sel in ["[role=alert]", "p.text-danger-600", "span.text-danger-600", "[id$='-error']"]:
        loc = page.locator(sel)
        for i in range(min(loc.count(), 20)):
            try:
                if loc.nth(i).is_visible() and loc.nth(i).inner_text().strip():
                    return loc.nth(i).inner_text().strip()[:120]
            except Exception:
                pass
    return None


def guardar(page):
    page.get_by_role("button", name=re.compile(r"^Guardar (día|cambios)$")).first.click()
    page.wait_for_timeout(1200)


def preview_usd(page):
    try:
        return page.locator("[data-tour='form-preview']").inner_text()
    except Exception:
        return ""


# ------------------------------------------------------------------ casos

@caso("login: contraseña incorrecta muestra mensaje y no 500")
def c_login_mal(page):
    page.goto(BASE + "/login", wait_until="networkidle")
    page.locator("input[type=email]").fill("admin@guadalupe.local")
    page.locator("input[type=password]").fill("incorrecta")
    page.get_by_role("button", name="Entrar").click()
    page.wait_for_timeout(1500)
    ok = form_error_visible(page) or texto(page, "no coinciden", 2000) or texto(page, "credenciales", 2000)
    registrar("PASS" if ok and not es_500(page) else "FAIL", "login: contraseña incorrecta muestra mensaje", str(ok)[:80])


@caso("login: 6 intentos seguidos activan el límite")
def c_login_throttle(page):
    page.goto(BASE + "/login", wait_until="networkidle")
    for _ in range(6):
        page.locator("input[type=email]").fill("bloqueo@guadalupe.local")
        page.locator("input[type=password]").fill("x")
        page.get_by_role("button", name="Entrar").click()
        page.wait_for_timeout(700)
    ok = texto(page, "Demasiados", 2000) or texto(page, "segundos", 2000) or texto(page, "intentos", 2000)
    registrar("PASS" if ok else "INFO", "login: límite de intentos", "mensaje de bloqueo: %s" % ok)


@caso("URL inválidas no dan 500")
def c_urls(page):
    for path in ["/cargar/2026-13-45", "/cargar/abc", "/cargar/2030-01-01", "/cargar/1999-01-01", "/cargar/2026-02-30", "/mes/abc", "/mes/1990-01", "/anio?anio=abc", "/administracion?tab[]=x", "/graficas?tab=zzz"]:
        resp = page.goto(BASE + path, wait_until="networkidle")
        status = resp.status if resp else 0
        bad = status >= 500 or es_500(page)
        registrar("FAIL" if bad else "PASS", "URL %s" % path, "HTTP %s, url final %s" % (status, page.url.replace(BASE, "")))
    for path, esperado in [("/mes/2026-13", 404), ("/exportar/mes/2025-13", 404), ("/exportar/mes/abc", 404), ("/exportar/anio/1990", 404), ("/exportar/anio/abc", 404), ("/recorrido/cuadro-ejemplo.xlsx", 200)]:
        r = page.context.request.get(BASE + path)
        registrar("PASS" if r.status == esperado else ("FAIL" if r.status >= 500 else "INFO"), "petición %s" % path, "HTTP %d (esperado %d)" % (r.status, esperado))


@caso("cargar día: entradas inválidas")
def c_form_invalidas(page):
    page.goto(BASE + "/cargar/2026-08-20", wait_until="networkidle")
    if page.locator("[data-tour='form-locked']").count():
        registrar("INFO", "cargar 2026-08-20", "bloqueado: " + page.locator("[data-tour='form-locked']").inner_text()[:80])
        return
    casos = [
        ("abc", "letras"), ("-5", "negativo"), ("1e5", "notación científica"), ("1.234.567,891234", "muchos decimales"),
        ("99999999999999999", "enorme"), ("1.000.000.000.000,00", "un billón"), (" ", "espacio"),
    ]
    for valor, nombre in casos:
        page.locator("#sales_bs").fill(valor)
        page.locator("#sales_bs").dispatch_event("input")
        page.locator("#sales_bs").blur()
        page.locator("#transactions").fill("10")
        page.locator("#units").fill("10")
        guardar(page)
        err = form_error_visible(page)
        sigue_en_form = "/cargar" in page.url
        if nombre == "muchos decimales":
            registrar("INFO", "venta '%s' (%s)" % (valor.strip(), nombre), "se redondea a 2 decimales · error: %s" % err)
        else:
            registrar("PASS" if err and sigue_en_form and not es_500(page) else "FAIL", "venta '%s' (%s)" % (valor.strip(), nombre), "error: %s · url: %s" % (err, page.url.replace(BASE, "")))
        if not sigue_en_form:
            page.goto(BASE + "/cargar/2026-08-20", wait_until="networkidle")
    # punto decimal vs coma
    page.goto(BASE + "/cargar/2026-08-20", wait_until="networkidle")
    page.locator("#sales_bs").fill("1234.56")
    page.locator("#sales_bs").dispatch_event("input")
    page.locator("#sales_bs").blur()
    page.wait_for_timeout(600)
    registrar("INFO", "venta '1234.56' con punto", "campo queda: '%s' · vista previa: %s" % (page.locator("#sales_bs").input_value(), preview_usd(page).replace("\n", " ")[:90]))
    page.locator("#sales_bs").fill("1,234.56")
    page.locator("#sales_bs").dispatch_event("input")
    page.locator("#sales_bs").blur()
    page.wait_for_timeout(600)
    registrar("INFO", "venta '1,234.56' estilo inglés", "campo queda: '%s' · vista previa: %s" % (page.locator("#sales_bs").input_value(), preview_usd(page).replace("\n", " ")[:90]))
    # transacciones con decimales, unidades negativas, jornadas 0, tasa 0
    for sel, valor, nombre in [("#transactions", "12,5", "transacciones decimal"), ("#units", "-3", "unidades negativas"), ("#shifts", "0", "jornadas 0"), ("#rate", "0", "tasa 0"), ("#rate", "-1", "tasa negativa"), ("#transactions", "1000000", "transacciones un millón")]:
        page.goto(BASE + "/cargar/2026-08-20", wait_until="networkidle")
        page.locator("#sales_bs").fill("100000")
        page.locator("#transactions").fill("100")
        page.locator("#units").fill("200")
        page.locator(sel).fill(valor)
        page.locator(sel).dispatch_event("input")
        page.locator(sel).blur()
        guardar(page)
        err = form_error_visible(page)
        strip = page.get_by_role("button", name="Guardar de todos modos")
        aviso = strip.count() > 0 and strip.first.is_visible()
        sigue = "/cargar" in page.url
        registrar("PASS" if (err or aviso or sigue) and not es_500(page) else "FAIL", nombre, "error: %s · aviso: %s · url: %s" % (err, aviso, page.url.replace(BASE, "")))
        if not sigue:
            registrar("INFO", nombre + " se guardó", "revisar si debía aceptarse")


@caso("cargar día: atípico sin observación")
def c_atipico(page):
    page.goto(BASE + "/cargar/2026-08-21", wait_until="networkidle")
    if page.locator("[data-tour='form-locked']").count():
        registrar("INFO", "atípico", "formulario bloqueado")
        return
    page.locator("#sales_bs").fill("50000")
    page.locator("#transactions").fill("20")
    page.locator("#units").fill("30")
    page.locator("[data-tour='form-atypical'] input[type=checkbox]").check()
    page.locator("#notes").fill("")
    guardar(page)
    err = form_error_visible(page)
    registrar("PASS" if err and "/cargar" in page.url else "FAIL", "atípico sin observación exige motivo", str(err))


@caso("cargar día: guardar, editar, borrar y deshacer")
def c_ciclo(page):
    page.goto(BASE + "/cargar/2026-08-22", wait_until="networkidle")
    if page.locator("[data-tour='form-locked']").count():
        registrar("INFO", "ciclo guardar/editar/borrar", "formulario bloqueado")
        return
    page.locator("#sales_bs").fill("120000")
    page.locator("#transactions").fill("150")
    page.locator("#units").fill("300")
    guardar(page)
    strip = page.get_by_role("button", name="Guardar de todos modos")
    if strip.count() and strip.first.is_visible():
        strip.first.click()
        page.wait_for_timeout(1200)
    saved = texto(page, "guardado", 4000) or texto(page, "Guardado", 2000) or "/cargar/2026-08-22" not in page.url
    registrar("PASS" if saved else "FAIL", "guardar día nuevo", page.url.replace(BASE, ""))
    page.goto(BASE + "/cargar/2026-08-22", wait_until="networkidle")
    ok = page.locator("#sales_bs").input_value().replace(".", "").startswith("120000")
    registrar("PASS" if ok else "FAIL", "reabrir el día muestra lo guardado", page.locator("#sales_bs").input_value())
    page.locator("#transactions").fill("151")
    guardar(page)
    strip = page.get_by_role("button", name="Guardar de todos modos")
    if strip.count() and strip.first.is_visible():
        strip.first.click()
        page.wait_for_timeout(1200)
    page.goto(BASE + "/cargar/2026-08-22", wait_until="networkidle")
    registrar("PASS" if page.locator("#transactions").input_value() == "151" else "FAIL", "editar día", page.locator("#transactions").input_value())
    # borrar y deshacer
    page.locator("[data-tour='form-delete']").click()
    dlg = page.get_by_role("dialog")
    dlg.wait_for(state="visible", timeout=5000)
    dlg.get_by_role("button", name=re.compile("^Borrar")).click()
    page.wait_for_timeout(1500)
    undo = page.get_by_role("button", name="Deshacer")
    tiene_undo = undo.count() > 0 and undo.first.is_visible()
    registrar("PASS" if tiene_undo else "INFO", "borrar día ofrece Deshacer", "url %s" % page.url.replace(BASE, ""))
    if tiene_undo:
        undo.first.click()
        page.wait_for_timeout(1500)
        page.goto(BASE + "/cargar/2026-08-22", wait_until="networkidle")
        registrar("PASS" if page.locator("#transactions").input_value() == "151" else "FAIL", "deshacer borrado restaura el día", page.locator("#transactions").input_value())


@caso("concurrencia: dos usuarios editan el mismo día")
def c_concurrencia(page, ctx_factory):
    page.goto(BASE + "/cargar/2026-08-22", wait_until="networkidle")
    if page.locator("[data-tour='form-locked']").count() or page.locator("#transactions").input_value() == "":
        registrar("INFO", "concurrencia", "no hay día para editar")
        return
    otro = ctx_factory()
    p2 = otro.new_page()
    login(p2, "supervision@guadalupe.local")
    p2.goto(BASE + "/cargar/2026-08-22", wait_until="networkidle")
    p2.locator("#transactions").fill("160")
    guardar(p2)
    s = p2.get_by_role("button", name="Guardar de todos modos")
    if s.count() and s.first.is_visible():
        s.first.click()
        p2.wait_for_timeout(1200)
    otro.close()
    page.locator("#transactions").fill("170")
    guardar(page)
    s = page.get_by_role("button", name="Guardar de todos modos")
    if s.count() and s.first.is_visible():
        s.first.click()
        page.wait_for_timeout(1200)
    body = page.locator("body").inner_text()
    conflicto = ("editó este día" in body) or ("Revisa sus cambios" in body) or ("otra persona" in body)
    registrar("PASS" if conflicto else "FAIL", "edición concurrente avisa del conflicto", "url %s · 500: %s" % (page.url.replace(BASE, ""), es_500(page)))


@caso("mes: cerrar con faltantes, reabrir sin motivo, formulario bloqueado")
def c_mes(page):
    page.goto(BASE + "/mes/2026-08", wait_until="networkidle")
    btn = page.locator("[data-tour='month-close']")
    if not btn.count():
        registrar("INFO", "mes 2026-08", "no hay botón cerrar (¿ya cerrado?)")
    else:
        btn.click()
        dlg = page.get_by_role("dialog")
        dlg.wait_for(state="visible", timeout=5000)
        confirm = dlg.get_by_role("button", name=re.compile("^Cerrar "))
        disabled_antes = confirm.is_disabled()
        if dlg.get_by_role("checkbox").count():
            registrar("PASS" if disabled_antes else "FAIL", "cerrar con faltantes exige confirmar", "botón deshabilitado antes del check: %s" % disabled_antes)
            dlg.get_by_role("checkbox").check()
        confirm.click()
        page.wait_for_timeout(1500)
        registrar("PASS" if texto(page, "Cerrado el", 4000) else "FAIL", "mes cerrado")
        page.goto(BASE + "/cargar/2026-08-22", wait_until="networkidle")
        registrar("PASS" if page.locator("[data-tour='form-locked']").count() else "FAIL", "día de mes cerrado bloqueado en Cargar día")
        page.goto(BASE + "/mes/2026-08", wait_until="networkidle")
        page.locator("[data-tour='month-reopen']").click()
        dlg = page.get_by_role("dialog")
        dlg.wait_for(state="visible", timeout=5000)
        page.locator("#reopen-reason").fill("")
        dlg.get_by_role("button", name=re.compile("^Reabrir ")).click()
        page.wait_for_timeout(1200)
        err = form_error_visible(page)
        registrar("PASS" if err or dlg.is_visible() else "FAIL", "reabrir sin motivo lo exige", str(err))
        page.locator("#reopen-reason").fill("prueba exploratoria")
        dlg.get_by_role("button", name=re.compile("^Reabrir ")).click()
        page.wait_for_timeout(1500)
        registrar("PASS" if page.locator("[data-tour='month-close']").count() else "FAIL", "mes reabierto")
    # búsqueda sin resultados y navegación al futuro
    page.goto(BASE + "/mes/2025-09", wait_until="networkidle")
    page.locator("[data-tour='month-search'] input").fill("99")
    page.wait_for_timeout(1200)
    registrar("PASS" if not es_500(page) else "FAIL", "búsqueda sin resultados", page.locator("[data-tour='month-table']").inner_text()[:80].replace("\n", " "))
    for _ in range(18):
        page.get_by_role("button", name="Mes siguiente").click()
        page.wait_for_timeout(350)
    registrar("PASS" if not es_500(page) else "FAIL", "18 clics en Mes siguiente", "período final: " + page.locator("#context-period").input_value())


@caso("exportaciones y PDF de meses vacíos")
def c_export(page):
    for path in ["/exportar/mes/2024-01", "/exportar/anio/2024", "/exportar/mes/2024-01/pdf", "/exportar/mes/2025-09", "/exportar/anio/2025", "/exportar/mes/2025-09/pdf"]:
        r = page.context.request.get(BASE + path, timeout=60000)
        ct = r.headers.get("content-type", "")
        tam = len(r.body())
        registrar("PASS" if r.status == 200 and tam > 1000 else ("FAIL" if r.status >= 500 else "INFO"), "descarga " + path, "HTTP %d · %s · %d bytes" % (r.status, ct[:40], tam))


@caso("importar: archivos inválidos y flujo")
def c_import(page):
    page.goto(BASE + "/importar", wait_until="networkidle")
    falso = os.path.join(TMP, "falso.xlsx")
    with open(falso, "w") as f:
        f.write("esto no es un excel")
    page.set_input_files("#import-files", falso)
    page.wait_for_timeout(1500)
    if page.locator("[data-tour='import-analyze']").count():
        page.locator("[data-tour='import-analyze']").click()
        page.wait_for_timeout(4000)
    body = page.locator("body").inner_text()
    registrar("FAIL" if es_500(page) else "PASS", "importar .xlsx falso (texto)", "mensaje: " + ("no se pudo" if "no se pudo" in body.lower() else body[body.find("falso.xlsx"):][:120].replace("\n", " ")))
    page.goto(BASE + "/importar", wait_until="networkidle")
    csv = os.path.join(TMP, "datos.csv")
    with open(csv, "w") as f:
        f.write("a,b,c\n1,2,3\n")
    try:
        page.set_input_files("#import-files", csv)
        page.wait_for_timeout(1500)
        body = page.locator("body").inner_text()
        registrar("FAIL" if es_500(page) else "PASS", "importar .csv", body[body.find("datos.csv") - 60:][:160].replace("\n", " ") if "datos.csv" in body else "rechazado sin listar")
    except Exception as e:
        registrar("INFO", "importar .csv", repr(e)[:80])
    # muestra real dos veces (mismo mes)
    page.goto(BASE + "/importar", wait_until="networkidle")
    page.set_input_files("#import-files", [SAMPLE])
    page.wait_for_timeout(1000)
    page.locator("[data-tour='import-analyze']").click()
    page.wait_for_selector("[data-tour='import-review']", timeout=60000)
    confirm = page.locator("[data-tour='import-confirm']")
    registrar("PASS" if confirm.is_disabled() else "FAIL", "importar: confirmar deshabilitado con anomalías sin decidir")
    page.locator("[data-tour='import-restart']").click()
    page.wait_for_timeout(1500)
    registrar("PASS" if page.locator("#import-files").count() else "FAIL", "importar: volver a empezar")


@caso("tasas: valores inválidos y diálogo de histórico")
def c_tasas(page):
    page.goto(BASE + "/tasas", wait_until="networkidle")
    page.select_option("#context-period", "2025-09")
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(1500)
    for valor in ["abc", "0", "-1", "999999999999", "99999999", "1485"]:
        inp = page.locator("[data-tour='rates-table'] input")
        for _ in range(3):
            page.locator("[data-tour='rates-edit']").first.click()
            try:
                inp.wait_for(state="visible", timeout=4000)
                break
            except Exception:
                page.wait_for_timeout(800)
        inp.fill(valor)
        inp.press("Enter")
        page.wait_for_timeout(1500)
        err = form_error_visible(page)
        sigue = inp.count() > 0
        registrar("PASS" if err and sigue else "FAIL", "tasa '%s' rechazada o pide confirmar" % valor, str(err))
        if sigue:
            inp.press("Escape")
            page.wait_for_timeout(600)
    page.locator("[data-tour='rates-backfill']").click()
    dlg = page.get_by_role("dialog")
    dlg.wait_for(state="visible", timeout=5000)
    page.locator("#backfill-from").fill("2030-01-01")
    boton = dlg.get_by_role("button", name=re.compile("Traer|Descargar|Consultar"))
    if boton.count():
        boton.first.click()
        page.wait_for_timeout(2500)
    registrar("FAIL" if es_500(page) else "PASS", "histórico desde fecha futura", (form_error_visible(page) or page.locator("body").inner_text()[:0]) or "sin error visible")
    page.keyboard.press("Escape")


@caso("metas: valores inválidos")
def c_metas(page):
    page.goto(BASE + "/metas", wait_until="networkidle")
    page.select_option("#context-period", "2025-09")
    page.wait_for_load_state("networkidle")
    for valor in ["-100", "abc", "0"]:
        campo = page.locator("#target-transactions")
        campo.fill(valor)
        campo.blur()
        page.wait_for_timeout(1200)
        err = form_error_visible(page)
        registrar("PASS" if err or not es_500(page) else "FAIL", "meta '%s'" % valor, "error: %s · queda: %s" % (err, campo.input_value()))
    page.get_by_role("button", name="Año", exact=True).click()
    page.wait_for_selector("[data-tour='goals-grid']", timeout=10000)
    page.locator("#growth").fill("abc")
    page.get_by_role("button", name="Aplicar a todo el año").click()
    page.wait_for_timeout(1200)
    registrar("FAIL" if es_500(page) else "PASS", "crecimiento 'abc'", str(form_error_visible(page)))


@caso("administración: validaciones")
def c_admin(page):
    page.goto(BASE + "/administracion", wait_until="networkidle")
    page.locator("[data-tour='admin-new-user']").click()
    dlg = page.get_by_role("dialog")
    dlg.wait_for(state="visible", timeout=5000)
    page.locator("#user-name").fill("Duplicado")
    page.locator("#user-email").fill("OPERADOR@guadalupe.local")
    dlg.get_by_role("button", name="Crear usuario").click()
    page.wait_for_timeout(1500)
    err = form_error_visible(page)
    registrar("PASS" if err and dlg.is_visible() else "FAIL", "usuario con correo duplicado (mayúsculas)", str(err))
    if dlg.get_by_role("button", name="Crear usuario").is_disabled():
        registrar("FAIL", "botón Crear usuario quedó deshabilitado tras el error")
        page.reload(wait_until="networkidle")
        page.locator("[data-tour='admin-new-user']").click()
        dlg = page.get_by_role("dialog")
        dlg.wait_for(state="visible", timeout=5000)
        page.locator("#user-name").fill("Prueba")
    page.locator("#user-email").fill("noesuncorreo")
    dlg.get_by_role("button", name="Crear usuario").click()
    page.wait_for_timeout(1200)
    registrar("PASS" if form_error_visible(page) else "FAIL", "correo inválido", str(form_error_visible(page)))
    page.locator("#user-name").fill("<script>alert(1)</script>")
    page.locator("#user-email").fill("xss-prueba@farmacia.com")
    dlg.get_by_role("button", name="Crear usuario").click()
    page.wait_for_timeout(1500)
    body = page.content()
    registrar("PASS" if "<script>alert(1)</script>" not in body else "FAIL", "nombre con HTML se escapa")
    # desactivarse a sí mismo
    fila = page.get_by_role("row", name=re.compile("Administrador"))
    toggle = fila.locator("[data-tour='admin-user-toggle']")
    registrar("PASS" if toggle.count() == 0 else "INFO", "no se ofrece desactivar al propio usuario", "botones: %d" % toggle.count())
    # sede con código repetido
    page.locator("[data-tour='admin-tab-sedes']").click()
    page.wait_for_selector("#panel-sedes", timeout=10000)
    page.locator("[data-tour='admin-new-branch']").click()
    dlg = page.get_by_role("dialog")
    dlg.wait_for(state="visible", timeout=5000)
    page.locator("#branch-name").fill("Sede repetida")
    page.locator("#branch-code").fill("GUA-01")
    page.locator("#branch-legal").fill("X")
    dlg.get_by_role("button", name=re.compile("Crear|Guardar")).first.click()
    page.wait_for_timeout(1500)
    registrar("PASS" if form_error_visible(page) and dlg.is_visible() else "FAIL", "sede con código repetido", str(form_error_visible(page)))
    dlg.get_by_role("button", name="Cancelar").click()
    # parámetros
    page.locator("[data-tour='admin-tab-parametros']").click()
    page.wait_for_selector("#panel-parametros", timeout=10000)
    page.locator("#s-sales").fill("")
    page.locator("[data-tour='admin-settings-save']").click()
    page.wait_for_timeout(1500)
    registrar("FAIL" if es_500(page) or texto(page, "Server Error", 500) else "PASS", "parámetro numérico vacío no rompe", str(form_error_visible(page)))
    page.locator("#s-sales").fill("abc")
    page.locator("[data-tour='admin-settings-save']").click()
    page.wait_for_timeout(1500)
    registrar("FAIL" if es_500(page) or texto(page, "Server Error", 500) else "PASS", "parámetro numérico con letras no rompe", str(form_error_visible(page)))
    page.reload(wait_until="networkidle")
    page.locator("[data-tour='admin-tab-parametros']").click()
    page.wait_for_selector("#panel-parametros", timeout=10000)
    page.locator("#s-report-day").fill("35")
    page.locator("#s-report-to").fill("no-es-correo")
    page.locator("#s-ontrack").fill("150")
    page.locator("[data-tour='admin-settings-save']").click()
    page.wait_for_timeout(1500)
    registrar("PASS" if form_error_visible(page) else "FAIL", "parámetros inválidos (día 35, correo malo, 150 %)", str(form_error_visible(page)))
    page.reload(wait_until="networkidle")


@caso("perfil: contraseña corta y que no coincide")
def c_perfil(page):
    page.goto(BASE + "/profile", wait_until="networkidle")
    page.locator("#update_password_current_password").fill("password")
    page.locator("#update_password_password").fill("abc")
    page.locator("#update_password_password_confirmation").fill("abd")
    page.get_by_role("button", name="Cambiar contraseña").click()
    page.wait_for_timeout(1500)
    registrar("PASS" if form_error_visible(page) else "FAIL", "contraseña corta / distinta", str(form_error_visible(page)))
    page.locator("#name").fill("")
    page.get_by_role("button", name="Guardar nombre").click()
    page.wait_for_timeout(1200)
    registrar("PASS" if form_error_visible(page) else "FAIL", "nombre vacío", str(form_error_visible(page)))
    page.reload()


@caso("operador: pantallas restringidas")
def c_operador(page):
    logout(page)
    login(page, "operador@guadalupe.local")
    for path, esperado in [("/administracion", 403), ("/tasas", 403), ("/importar", 403), ("/metas", 403), ("/dashboard", 200), ("/mes/2025-09", 200), ("/anio", 200), ("/graficas", 200), ("/exportar/mes/2025-09", 403)]:
        resp = page.goto(BASE + path, wait_until="networkidle")
        st = resp.status if resp else 0
        ok = (st == esperado) or (esperado == 403 and st in (302, 403) and "/dashboard" in page.url)
        registrar("PASS" if ok else "FAIL", "operador %s" % path, "HTTP %s → %s" % (st, page.url.replace(BASE, "")))
    page.goto(BASE + "/mes/2025-09", wait_until="networkidle")
    registrar("PASS" if page.locator("[data-tour='month-close']").count() == 0 else "FAIL", "operador no ve Cerrar mes")
    page.goto(BASE + "/cargar/2025-09-10", wait_until="networkidle")
    registrar("PASS" if page.locator("[data-tour='form-delete']").count() == 0 else "FAIL", "operador no ve Borrar día")
    logout(page)
    login(page, "admin@guadalupe.local")


@caso("usuario desactivado pierde la sesión")
def c_desactivado(page, ctx_factory):
    otro = ctx_factory()
    p2 = otro.new_page()
    login(p2, "operador@guadalupe.local")
    p2.goto(BASE + "/mes/2025-09", wait_until="networkidle")
    activo_antes = "/login" not in p2.url
    page.goto(BASE + "/administracion", wait_until="networkidle")
    fila = page.get_by_role("row", name=re.compile("Ana Operadora"))
    fila.locator("[data-tour='admin-user-toggle']").click()
    page.wait_for_timeout(1500)
    p2.goto(BASE + "/mes/2025-09", wait_until="networkidle")
    expulsado = "/login" in p2.url or texto(p2, "desactiv", 2000)
    registrar("PASS" if activo_antes and expulsado else "FAIL", "usuario desactivado con sesión abierta es expulsado", "antes: %s · después url: %s" % (activo_antes, p2.url.replace(BASE, "")))
    otro.close()
    fila = page.get_by_role("row", name=re.compile("Ana Operadora"))
    fila.locator("[data-tour='admin-user-toggle']").click()
    page.wait_for_timeout(1000)


@caso("móvil: sin desborde horizontal")
def c_movil(page, ctx_factory):
    ctx = ctx_factory(viewport={"width": 390, "height": 844})
    p = ctx.new_page()
    login(p, "admin@guadalupe.local")
    for path in ["/dashboard", "/cargar/2025-09-10", "/mes/2025-09", "/graficas", "/metas", "/anio", "/tasas", "/importar", "/administracion", "/profile"]:
        p.goto(BASE + path, wait_until="networkidle")
        p.wait_for_timeout(800)
        over = p.evaluate("document.documentElement.scrollWidth - window.innerWidth")
        registrar("PASS" if over <= 2 else "FAIL", "móvil %s" % path, "desborde %d px" % over)
    ctx.close()


@caso("navegación rápida entre pantallas (gráficas se renderizan tras navegar)")
def c_navegacion(page):
    page.goto(BASE + "/dashboard", wait_until="networkidle")
    for key, path in [("nav-graficas", "graficas"), ("nav-panel", "dashboard"), ("nav-graficas", "graficas"), ("nav-anio", "anio"), ("nav-panel", "dashboard")]:
        page.locator("aside [data-tour='%s']" % key).click()
        page.wait_for_url(re.compile("/" + path), timeout=10000)
        page.wait_for_timeout(1200)
    svgs = page.locator("[x-ref='canvas'] svg").count()
    registrar("PASS" if svgs >= 2 else "FAIL", "gráficas presentes tras navegar ida y vuelta", "svg: %d" % svgs)


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)

    def ctx_factory(**kw):
        c = browser.new_context(viewport=kw.pop("viewport", {"width": 1440, "height": 900}), locale="es-VE", accept_downloads=True, **kw)
        c.add_init_script("localStorage.setItem('tours-seen', JSON.stringify({1:{dashboard:1,'records.create':1,month:1,charts:1,goals:1,annual:1,rates:1,imports:1,admin:1,profile:1,shell:1},2:{dashboard:1,'records.create':1,month:1,charts:1,goals:1,annual:1,rates:1,imports:1,admin:1,profile:1,shell:1},3:{dashboard:1,month:1,'records.create':1,charts:1,annual:1,shell:1},4:{dashboard:1,shell:1}}))")
        return c

    ctx = ctx_factory()
    page = ctx.new_page()
    page.on("console", lambda m: console_errors.append((page.url.replace(BASE, ""), m.text[:160])) if m.type == "error" else None)
    page.on("pageerror", lambda e: console_errors.append((page.url.replace(BASE, ""), "pageerror: " + str(e)[:160])))

    c_login_mal(page)
    c_login_throttle(page)
    login(page, "admin@guadalupe.local")
    c_urls(page)
    c_form_invalidas(page)
    c_atipico(page)
    c_ciclo(page)
    c_concurrencia(page, ctx_factory)
    c_mes(page)
    c_export(page)
    c_import(page)
    c_tasas(page)
    c_metas(page)
    c_admin(page)
    c_perfil(page)
    c_navegacion(page)
    c_desactivado(page, ctx_factory)
    c_movil(page, ctx_factory)
    c_operador(page)

    browser.close()

print("\n===== RESUMEN =====")
for estado in ("FAIL", "INFO", "PASS"):
    n = sum(1 for r in resultados if r[0] == estado)
    print("%s: %d" % (estado, n))
print("\n--- FAIL ---")
for r in resultados:
    if r[0] == "FAIL":
        print("  %s :: %s" % (r[1], r[2]))
print("\n--- INFO ---")
for r in resultados:
    if r[0] == "INFO":
        print("  %s :: %s" % (r[1], r[2]))
print("\n--- errores de consola (%d) ---" % len(console_errors))
vistos = set()
for u, t in console_errors:
    if (u, t[:60]) in vistos:
        continue
    vistos.add((u, t[:60]))
    print("  %s :: %s" % (u, t))
