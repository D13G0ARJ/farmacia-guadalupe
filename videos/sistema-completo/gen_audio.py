# -*- coding: utf-8 -*-
"""guion.json -> audio/<id>.mp3 + durations.json (segundos por escena)."""
import asyncio
import json
import os
import subprocess

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
        ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", ruta], text=True
    ).strip()
    duraciones[esc["id"]] = float(out)

with open(os.path.join(HERE, "durations.json"), "w", encoding="utf-8") as f:
    json.dump(duraciones, f, indent=2)
print("TOTAL narración: %.1f s" % sum(duraciones.values()))
