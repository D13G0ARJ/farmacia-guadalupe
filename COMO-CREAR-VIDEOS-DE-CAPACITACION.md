# Cómo crear videos de capacitación de un sistema web (guía completa)

> **Para quién es esto**: para pegárselo a un agente (Claude Code) que trabaja en
> OTRO sistema web y tiene que producir videos de capacitación narrados.
> Es el método ya probado en 8 videos: descríbeselo tal cual y podrá replicarlo.

---

## 1. Qué produce el método

Videos MP4 con **narración en español (voz IA)** sobre una **grabación real de la
aplicación** navegando sola. Nada de diapositivas: se ve el sistema funcionando.

- Duración típica: 3–5 minutos, 10–15 "escenas".
- Escritorio 1920×1080, o teléfono 390×844 escalado a 780×1688.
- Tamaño: 8–22 MB (se comparte por WhatsApp sin problema).
- **Regenerable**: si el sistema cambia, se corre el script otra vez. No se
  vuelve a grabar a mano nunca.

## 2. Requisitos (verificar antes de prometer nada)

```bash
python -c "import playwright; print('playwright ok')"
pip show edge-tts          # voz neuronal de Microsoft, gratis, sin API key
ffmpeg -version            # y ffprobe
```

Si falta algo: `pip install playwright edge-tts && playwright install chromium`.
ffmpeg se instala aparte (winget/choco/apt/brew).

## 3. Arquitectura: 4 archivos por video

Una carpeta por video, siempre con los mismos 4 archivos + tarjetas:

```
videoN-nombre/
  guion.json        <- el texto de la narración, escena por escena (la fuente de verdad)
  gen_audio.py      <- guion.json -> audio/*.mp3 + durations.json
  cards/intro.html  <- portada y cierre (HTML normal, se graban como escenas)
  cards/outro.html
  grabar.py         <- Playwright: navega la app y graba video_raw.webm + timeline.json
  montar.py         <- ffmpeg: pega cada narración en su momento -> MP4 final
  README.md         <- protocolo de datos y gotchas de ESE video
```

### La idea clave: **la narración manda**

1. Primero se genera el audio y se mide **cuánto dura cada escena**.
2. La grabación **espera** a que su narración termine antes de pasar a la
   siguiente escena, y anota en qué segundo empezó cada una.
3. El montaje coloca cada mp3 en ese segundo exacto.

Resultado: la voz y la pantalla van sincronizadas **sin editar a mano**. Y si
cambias una frase del guion, todo se re-timea solo.

---

## 4. `guion.json`

```json
{
  "voice": "es-MX-DaliaNeural",
  "rate": "-8%",
  "scenes": [
    { "id": "01_intro",  "text": "Bienvenido. En este video vas a aprender..." },
    { "id": "02_login",  "text": "Entra con tu usuario y contraseña." }
  ]
}
```

- `id` ordena las escenas y nombra su mp3. Usa prefijo numérico.
- Voces alternativas: `es-MX-JorgeNeural` (hombre), `es-US-PalomaNeural`.
- `rate: "-8%"` = un poco más lento que el default; se entiende mejor.

**Cómo escribir el guion (esto decide si el video sirve o no):**

- Habla de **tú**, como un compañero explicando, no como un manual.
- Una idea por escena. Si una escena pasa de ~25 s, pártela en dos.
- Nombra los botones **exactamente como dicen en pantalla** ("toca Guardar
  borrador", no "guarda el borrador").
- Explica el **por qué**, no solo el clic: "esta foto es obligatoria: es la
  evidencia de tu trabajo".
- Cierra con 3–4 puntos clave accionables.
- **No prometas nada que no se vea en pantalla.** Si el sistema no tiene esa
  función, quítala del guion (ver Gotcha #1).

## 5. `gen_audio.py` (genérico, cópialo tal cual)

```python
# -*- coding: utf-8 -*-
"""guion.json -> audio/<id>.mp3 + durations.json (segundos por escena)."""
import asyncio, json, os, subprocess
import edge_tts

HERE = os.path.dirname(os.path.abspath(__file__))
AUDIO = os.path.join(HERE, "audio")
os.makedirs(AUDIO, exist_ok=True)

with open(os.path.join(HERE, "guion.json"), encoding="utf-8") as f:
    GUION = json.load(f)

async def main():
    for esc in GUION["scenes"]:
        destino = os.path.join(AUDIO, esc["id"] + ".mp3")
        await edge_tts.Communicate(esc["text"], GUION["voice"], rate=GUION["rate"]).save(destino)
        print("OK audio:", esc["id"])

asyncio.run(main())

duraciones = {}
for esc in GUION["scenes"]:
    ruta = os.path.join(AUDIO, esc["id"] + ".mp3")
    out = subprocess.check_output(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",
         "-of", "csv=p=0", ruta], text=True).strip()
    duraciones[esc["id"]] = float(out)

with open(os.path.join(HERE, "durations.json"), "w", encoding="utf-8") as f:
    json.dump(duraciones, f, indent=2)
print("TOTAL narración: %.1f s" % sum(duraciones.values()))
```

## 6. `grabar.py`: esqueleto y helpers imprescindibles

```python
# -*- coding: utf-8 -*-
import json, os, shutil, time
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8000"          # la app local
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out"); os.makedirs(OUT, exist_ok=True)
for v in os.listdir(OUT):               # limpia webm de tomas anteriores
    if v.endswith(".webm"): os.remove(os.path.join(OUT, v))

with open(os.path.join(HERE, "durations.json"), encoding="utf-8") as f:
    DUR = json.load(f)

# Ondita dorada en cada clic: el espectador ve DÓNDE se pulsa.
# (Aquí también se neutralizan pop-ups que estorben; ver Gotcha #5.)
TAP_JS = """
(() => {
  function ensureStyle() {
    if (document.getElementById('cap-tap-style') || !document.head) return;
    const s = document.createElement('style');
    s.id = 'cap-tap-style';
    s.textContent = `.cap-tap{position:fixed;width:52px;height:52px;margin:-26px 0 0 -26px;`+
      `border-radius:50%;background:rgba(252,219,70,.40);border:3px solid rgba(252,219,70,.95);`+
      `pointer-events:none;z-index:2147483647;animation:capTap .65s ease-out forwards}`+
      `@keyframes capTap{from{transform:scale(.45);opacity:1}to{transform:scale(1.7);opacity:0}}`;
    document.head.appendChild(s);
  }
  window.addEventListener('pointerdown', e => {
    try { ensureStyle();
      const d = document.createElement('div');
      d.className = 'cap-tap'; d.style.left = e.clientX+'px'; d.style.top = e.clientY+'px';
      document.body.appendChild(d); setTimeout(() => d.remove(), 750);
    } catch (_) {}
  }, true);
})();
"""

timeline = []; T0 = None
def ahora(): return time.perf_counter() - T0

def start_scene(page, sid, pre=0.5):
    """Marca el inicio de una escena (y de su narración)."""
    page.wait_for_timeout(int(pre * 1000))
    timeline.append({"id": sid, "start": round(ahora(), 3)})
    print("ESCENA %s @ %.1f s" % (sid, timeline[-1]["start"]))

def end_scene(page, tail=0.9):
    """Espera hasta cubrir la narración de la escena + una colita."""
    sc = timeline[-1]
    resto = sc["start"] + DUR[sc["id"]] + tail - ahora()
    if resto > 0: page.wait_for_timeout(int(resto * 1000))

def espera_narracion(page, frac):
    """Sincroniza un clic con un punto de la frase (0.0-1.0)."""
    sc = timeline[-1]
    resto = sc["start"] + DUR[sc["id"]] * frac - ahora()
    if resto > 0: page.wait_for_timeout(int(resto * 1000))

def texto_visible(page, texto, timeout=20000):
    """Primer elemento VISIBLE con ese texto (ver Gotcha #3)."""
    limite = time.time() + timeout/1000
    loc = page.get_by_text(texto)
    while time.time() < limite:
        for i in range(min(loc.count(), 10)):
            try:
                if loc.nth(i).is_visible(): return loc.nth(i)
            except Exception: pass
        page.wait_for_timeout(250)
    raise TimeoutError("no apareció visible: " + texto)

def scroll_to(page, texto, margen=140):
    """Scroll suave por JS (ver Gotcha #2)."""
    try:
        el = texto_visible(page, texto, timeout=6000)
        el.evaluate("(e,m)=>{const r=e.getBoundingClientRect();"
                    "window.scrollBy({top:r.top-m,behavior:'smooth'})}", margen)
        page.wait_for_timeout(1000)
    except Exception as e:
        print("  aviso scroll a", texto, ":", repr(e)[:110])

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    ctx = browser.new_context(
        viewport={"width": 1920, "height": 1080}, device_scale_factor=1,
        locale="es-ES", color_scheme="dark",
        record_video_dir=OUT, record_video_size={"width": 1920, "height": 1080},
    )
    # Para TELÉFONO: viewport 390x844, device_scale_factor=2, is_mobile=True,
    # has_touch=True, record_video_size 390x844 (¡ver Gotcha #4!).
    ctx.add_init_script(TAP_JS)
    page = ctx.new_page()
    T0 = time.perf_counter()
    page.on("dialog", lambda d: d.accept())          # confirm() nativos
    page.on("console", lambda m: print("  console.error:", m.text[:140])
            if m.type == "error" else None)

    # ---- 01 · Portada
    page.goto("file:///" + os.path.join(HERE, "cards", "intro.html").replace("\\", "/"))
    start_scene(page, "01_intro", pre=0.8); end_scene(page, tail=0.6)

    # ---- 02 · Ejemplo de escena real
    page.goto(BASE + "/login", wait_until="load")
    start_scene(page, "02_login", pre=0.2)
    page.locator('input[name="email"]').type("demo@ejemplo.com", delay=35)
    page.locator('input[name="password"]').type("secreto", delay=40)
    espera_narracion(page, 0.90)        # justo cuando la voz dice "entra"
    page.click('button[type="submit"]')
    page.wait_for_load_state("load"); page.wait_for_timeout(1500)
    end_scene(page)

    # ... más escenas ...

    video = page.video; ctx.close()
    shutil.move(video.path(), os.path.join(OUT, "video_raw.webm"))
    browser.close()

with open(os.path.join(HERE, "timeline.json"), "w", encoding="utf-8") as f:
    json.dump(timeline, f, indent=2)
print("GRABACION COMPLETA")
```

**Reglas de oro al escribir escenas**

- Teclea con `delay=35..90` (se ve humano). No uses `fill()` salvo en pasos que
  no son la lección (p. ej. un login de transición).
- Sincroniza cada clic con `espera_narracion(page, fracción)`: el clic ocurre
  cuando la voz lo nombra.
- **Nunca uses esperas fijas para cambios del servidor.** Espera el elemento:
  `wait_for_selector`, `texto_visible`, `wait_for_url`, `wait_for_function`.
- Verifica lo que creaste antes de seguir. Si un paso puede perderse en una
  carrera del framework, compruébalo y reintenta.
- Envuelve en `try/except` con `print("  aviso: ...")` solo lo accesorio; lo
  esencial debe **romper la toma** (mejor fallar que publicar un video mudo).

## 7. `montar.py` (genérico, cópialo tal cual)

```python
# -*- coding: utf-8 -*-
"""video_raw.webm + audio/*.mp3 -> MP4 final, cada voz en su segundo."""
import json, os, subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out")
VIDEO = os.path.join(OUT, "video_raw.webm")
FINAL = os.path.join(OUT, "Capacitacion - Nombre del video.mp4")

with open(os.path.join(HERE, "timeline.json"), encoding="utf-8") as f:
    TIMELINE = json.load(f)

dur = float(subprocess.check_output(
    ["ffprobe","-v","error","-show_entries","format=duration","-of","csv=p=0", VIDEO],
    text=True).strip())

cmd = ["ffmpeg", "-y", "-i", VIDEO]
filtros, labels = [], []
for i, sc in enumerate(TIMELINE):
    cmd += ["-i", os.path.join(HERE, "audio", sc["id"] + ".mp3")]
    ms = int(sc["start"] * 1000)
    filtros.append("[%d:a]adelay=%d|%d[a%d]" % (i+1, ms, ms, i))
    labels.append("[a%d]" % i)

# Ancla silenciosa del largo del video: evita que amix recorte el final.
filtros.append("anullsrc=channel_layout=stereo:sample_rate=44100:d=%.3f[anc]" % dur)
filtros.append("[anc]%samix=inputs=%d:duration=first:normalize=0[aout]"
               % ("".join(labels), len(labels)+1))

# Solo para TELÉFONO: recorta el lienzo real y reescala a 2x (ver Gotcha #4).
# filtros.append("[0:v]crop=390:844:0:0,scale=780:1688:flags=lanczos[vout]")

cmd += ["-filter_complex", ";".join(filtros),
        "-map", "0:v", "-map", "[aout]",          # o "[vout]" si recortaste
        "-c:v","libx264","-crf","20","-preset","medium","-r","30","-pix_fmt","yuv420p",
        "-c:a","aac","-b:a","160k","-movflags","+faststart", FINAL]
subprocess.check_call(cmd)
print("MONTAJE COMPLETO ->", FINAL)
```

## 8. Tarjetas de portada y cierre (`cards/*.html`)

HTML normal a pantalla completa, con el color de marca. La portada lleva
logo + título; el cierre, 3–4 puntos clave.

```html
<!DOCTYPE html><html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">  <!-- ¡Gotcha #6! -->
<style>
  *{margin:0;padding:0;box-sizing:border-box} html,body{width:100%;height:100%}
  body{background:#18181b;color:#fafafa;font-family:'Segoe UI',system-ui,sans-serif;
       display:flex;flex-direction:column;align-items:center;justify-content:center;
       text-align:center;padding:60px;animation:fadein 1.2s ease-out}
  @keyframes fadein{from{opacity:0}to{opacity:1}}
  h1{font-size:58px;line-height:1.15;font-weight:800} h1 span{color:#fcdb46}
  .kicker{font-size:18px;letter-spacing:.28em;text-transform:uppercase;color:#a1a1aa;margin-bottom:18px}
  .bar{width:90px;height:6px;background:#fcdb46;border-radius:3px;margin:34px auto 0}
</style></head><body>
  <img src="../../../public/icon-512.png" style="width:130px;border-radius:30px;margin-bottom:40px">
  <div class="kicker">Capacitación · Nombre del sistema</div>
  <h1>Cómo hacer<br><span>tal cosa</span></h1>
  <div class="bar"></div>
</body></html>
```

## 9. Protocolo de datos (NO grabar contra datos reales)

Los listados muestran nombres, correos y teléfonos de personas reales. **Siempre**
se graba contra una copia desechable:

```bash
mysqldump -uroot sistema > /tmp/cap.sql
mysql -uroot -e "CREATE DATABASE sistema_capacitacion"
mysql -uroot sistema_capacitacion < /tmp/cap.sql
# script de anonimización: nombres/correos de ejemplo + contraseña conocida
DB_DATABASE=sistema_capacitacion php artisan tinker anonimizar.php
DB_DATABASE=sistema_capacitacion php artisan serve --port=8124
```

- El script de anonimización debe **abortar si la base no es la copia**
  (`if (DB::getDatabaseName() !== 'sistema_capacitacion') throw ...`).
- `prep_escenario.php`: deja el escenario listo (usuarios, permisos, datos de
  partida). Idealmente **lo mínimo**: lo demás se crea en cámara.
- `reset_take.php`: borra lo que la toma creó, para repetir sin ensuciar.
- Al terminar: `DROP DATABASE`, apagar el servidor. La base real nunca se toca.

## 10. Los 12 gotchas que te van a morder (aprendidos a golpes)

1. **No narres lo que no existe.** Antes de escribir el guion, abre el código y
   confirma que el botón/campo está activo. Nos pasó con un "link opcional" que
   estaba comentado en el blade desde hacía meses: hubo que regrabar.
2. **`scroll_into_view_if_needed` se cuelga 30 s** si la página tiene
   `scroll-behavior: smooth`: su chequeo de estabilidad nunca pasa. Haz scroll
   por JS (`window.scrollBy({behavior:'smooth'})`).
3. **`.first` casa nodos ocultos** (`<option>` de filtros, textos de changelog).
   Recorre hasta el primer **visible** (`texto_visible`), o el scroll y las
   esperas van a un elemento invisible con rect 0,0.
4. **Playwright NO escala el video hacia arriba.** Si pones
   `record_video_size` mayor que el viewport, el contenido queda en una esquina
   con relleno gris. Graba al tamaño del viewport y reescala en ffmpeg.
5. **Los pop-ups invisibles bloquean TODOS los clics.** Un modal con overlay
   `bg-black/60` que aparece a los 800 ms hizo que cada clic esperara 30 s y
   fallara. Solución: sembrar su marca en `localStorage` desde el init script,
   o cerrarlo antes de cada escena.
6. **Las tarjetas HTML necesitan `<meta name="viewport">`** o la emulación móvil
   las renderiza a 980 px encogidas (texto diminuto).
7. **Frameworks reactivos (Livewire/Alpine): los selects se resetean** tras
   guardar. Espera el reset (`wait_for_function` sobre `.value === ''`) y
   **verifica que lo que agregaste apareció**, o se pierde en silencio.
8. **Componentes UI modernos no son HTML nativo.** Encontramos `<ui-radio>` y
   `<ui-checkbox>` sin `name` ni `input`: `input[type=checkbox]` no existía.
   Inspecciona el DOM real antes de escribir selectores.
9. **Los redirects "obvios" mienten.** Un guardado puede volver a la pantalla de
   origen y no al listado. Espera **por URL aceptando ambos destinos**, y dale
   margen: el primer request en frío puede pasar de 20 s.
10. **Un formulario público puede dejar sesión abierta** (auto-login): si luego
    vas a `/login` te redirige y no hay nada que teclear. `ctx.clear_cookies()`.
11. **El servidor de desarrollo suele ser de un solo hilo** (`artisan serve`,
    `runserver`): a veces falla un recurso. Dale reintento con `reload()` a los
    pasos de navegación.
12. **Si necesitas mezclar escritorio y teléfono en un mismo video**, no puedes
    cambiar el viewport a media grabación: el lienzo se fija al crear el
    contexto. Graba **partes separadas** (una por vista), guarda el inicio de
    cada escena **relativo a su parte**, y en el montaje concatena escalando la
    parte móvil centrada:
    `[1:v]scale=-2:1000,pad=1920:1080:(ow-iw)/2:(oh-ih)/2:color=0x18181b`.
    El offset de audio es `duración de las partes previas + inicio relativo`.

## 11. Flujo de trabajo completo

```bash
# 1. Copia anonimizada + servidor + escenario
mysql ... && php artisan tinker anonimizar.php && php artisan tinker prep_escenario.php
DB_DATABASE=sistema_capacitacion php artisan serve --port=8124

# 2. Voz (repetir cada vez que cambie el guion)
python gen_audio.py

# 3. Grabar (tiempo real: un video de 4 min tarda 4 min)
python grabar.py

# 4. Montar
python montar.py

# 5. Entre tomas fallidas
php artisan tinker reset_take.php
```

## 12. QA obligatorio antes de entregar

```bash
# Fotogramas de los momentos clave (¡míralos de verdad!)
ffmpeg -ss 45 -i final.mp4 -frames:v 1 qa_45.png

# ¿Hay pista de audio y suena?
ffprobe -v error -show_entries stream=codec_type -of csv final.mp4
ffmpeg -ss 30 -t 10 -i final.mp4 -af volumedetect -f null -
```

Revisa: (a) cada escena nueva muestra lo que la voz dice; (b) ninguna escena
quedó en silencio largo (síntoma de una espera muerta: mira el log, si una
escena duró mucho más que su narración algo se colgó); (c) no aparecen datos
reales; (d) las tarjetas se ven completas.

**Y limpia siempre**: `DROP DATABASE`, matar el servidor, borrar usuarios de
prueba, restaurar contraseñas que hayas cambiado.

## 13. Escribe un README por video

Con: qué cubre, cómo regenerarlo, qué cuentas usa, y **los gotchas específicos
de ese módulo**. Es lo que hace que dentro de 3 meses se pueda regenerar en 10
minutos en vez de re-descubrir todo.

---

## Plantilla de prompt para arrancar en el otro sistema

> Quiero producir videos de capacitación de este sistema con el método de la
> guía que te paso (Playwright graba la app + edge-tts narra + ffmpeg monta).
> Antes de escribir nada:
> 1. Verifica que estén playwright, edge-tts y ffmpeg.
> 2. Dime qué procesos del sistema ameritan un video y en qué orden, mirando el
>    menú y los módulos reales del código.
> 3. Propón el primer video: guion escena por escena, y confirma **en el código**
>    que cada botón/campo que vas a narrar existe y está activo.
> 4. Prepara una copia anonimizada de la base para grabar (nunca la real).
> 5. Graba, monta, y revisa fotogramas antes de dármelo.
> Guion en español, tratando de tú, nombrando los botones tal como aparecen.
