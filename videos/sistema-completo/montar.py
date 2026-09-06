# -*- coding: utf-8 -*-
"""
video_raw.webm + audio/*.mp3 -> MP4 final, cada voz en su segundo.
Además recorta los huecos de espera entre escenas (páginas cargando, descargas): la narración de una escena
termina y la siguiente tarda en arrancar; ese tramo, en el que la pantalla no cambia, se elimina.
"""
import json
import os
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out")
VIDEO = os.path.join(OUT, "video_raw.webm")
FINAL = os.path.join(OUT, "Indicadores Guadalupe - Recorrido completo del sistema.mp4")
HUECO_MIN = 3.5   # segundos de silencio a partir de los cuales se recorta
COLA = 1.0        # segundos que se dejan tras el fin de la narración
MARGEN = 0.2      # segundos antes del arranque de la escena siguiente que se conservan

with open(os.path.join(HERE, "timeline.json"), encoding="utf-8") as f:
    TIMELINE = json.load(f)
with open(os.path.join(HERE, "durations.json"), encoding="utf-8") as f:
    DUR = json.load(f)

dur_total = float(subprocess.check_output(
    ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", VIDEO], text=True
).strip())

# 1) Huecos a recortar: [desde, hasta] en el tiempo original
cortes = []
for i, sc in enumerate(TIMELINE[:-1]):
    fin_narracion = sc["start"] + DUR[sc["id"]]
    siguiente = TIMELINE[i + 1]["start"]
    hueco = siguiente - fin_narracion
    if hueco > HUECO_MIN:
        cortes.append((fin_narracion + COLA, siguiente - MARGEN))
        print("recorte tras %s: %.1f s" % (sc["id"], cortes[-1][1] - cortes[-1][0]))

# 2) Tramos que se conservan y nuevo inicio de cada escena
tramos, cursor = [], 0.0
for a, b in cortes:
    tramos.append((cursor, a))
    cursor = b
tramos.append((cursor, dur_total))


def tiempo_nuevo(t):
    quitado = sum(min(b, t) - a for a, b in cortes if a < t)
    return t - quitado


starts = [(sc["id"], tiempo_nuevo(sc["start"])) for sc in TIMELINE]
dur_nueva = tiempo_nuevo(dur_total)

# 3) ffmpeg: recorte + concatenación de video, voces en su segundo, ancla silenciosa del largo total
cmd = ["ffmpeg", "-y", "-i", VIDEO]
filtros = []
for i, (a, b) in enumerate(tramos):
    filtros.append("[0:v]trim=start=%.3f:end=%.3f,setpts=PTS-STARTPTS[v%d]" % (a, b, i))
filtros.append("".join("[v%d]" % i for i in range(len(tramos))) + "concat=n=%d:v=1:a=0[vcut]" % len(tramos))

labels = []
for i, (sid, start) in enumerate(starts):
    cmd += ["-i", os.path.join(HERE, "audio", sid + ".mp3")]
    ms = int(start * 1000)
    filtros.append("[%d:a]adelay=%d|%d[a%d]" % (i + 1, ms, ms, i))
    labels.append("[a%d]" % i)
filtros.append("anullsrc=channel_layout=stereo:sample_rate=44100:d=%.3f[anc]" % dur_nueva)
filtros.append("[anc]%samix=inputs=%d:duration=first:normalize=0[aout]" % ("".join(labels), len(labels) + 1))

cmd += ["-filter_complex", ";".join(filtros),
        "-map", "[vcut]", "-map", "[aout]",
        "-c:v", "libx264", "-crf", "20", "-preset", "medium", "-r", "30", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-movflags", "+faststart", "-t", "%.3f" % dur_nueva, FINAL]
subprocess.check_call(cmd)

with open(os.path.join(HERE, "timeline_final.json"), "w", encoding="utf-8") as f:
    json.dump([{"id": sid, "start": round(st, 3)} for sid, st in starts], f, indent=2)
print("MONTAJE COMPLETO (%.0f s, %d recortes) -> %s" % (dur_nueva, len(cortes), FINAL))
