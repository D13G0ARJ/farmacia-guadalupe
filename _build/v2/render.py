from playwright.sync_api import sync_playwright
import pathlib
src = pathlib.Path("_build/v2/doc.html").resolve().as_uri()
out = str(pathlib.Path("Propuesta Dashboard Farmacia Guadalupe.pdf").resolve())
HDR = """<div style="width:100%;font-family:Inter,Segoe UI,sans-serif;font-size:6.6pt;color:#8A9AA1;
 padding:0 17mm;display:flex;justify-content:space-between;border-bottom:.5pt solid #E4E0D9;padding-bottom:2mm;">
 <span style="letter-spacing:.7pt;text-transform:uppercase;">Farmacia Guadalupe, C.A.</span>
 <span style="letter-spacing:.7pt;text-transform:uppercase;">Propuesta de alcance &middot; v2.0</span></div>"""
FTR = """<div style="width:100%;font-family:Inter,Segoe UI,sans-serif;font-size:6.8pt;color:#8A9AA1;
 padding:0 17mm;display:flex;justify-content:space-between;align-items:center;">
 <span>Documento confidencial &mdash; preparado para revisi&oacute;n del cliente</span>
 <span style="font-weight:600;color:#0B6B5B;"><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>"""
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page()
    pg.goto(src, wait_until="networkidle"); pg.evaluate("document.fonts.ready"); pg.wait_for_timeout(1200)
    pg.pdf(path=out, format="A4", print_background=True, display_header_footer=True,
           header_template=HDR, footer_template=FTR,
           margin={"top":"19mm","bottom":"15mm","left":"0","right":"0"})
    b.close()
print("PDF ->", out)
