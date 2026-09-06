# Video: recorrido completo del sistema

Video narrado (voz es-VE, Paola) de unos 12 minutos que muestra todos los módulos con datos de prueba:
acceso, panel, barra de contexto, cargar día, mes (calendario, cuadro, exportar, cerrar y reabrir),
gráficas, metas, año, tasa BCV, importar, administración, perfil, ayuda y recorridos guiados.

Método: `../../COMO-CREAR-VIDEOS-DE-CAPACITACION.md` (Playwright graba, edge-tts narra, ffmpeg monta).

## Regenerar

```bash
# Desde la raíz del proyecto (guadalupe/)
cd indicadores
cp database/database.sqlite database/capacitacion.sqlite                       # copia desechable
DB_DATABASE="$PWD/database/capacitacion.sqlite" php artisan tinker ../videos/sistema-completo/prep_escenario.php
DB_DATABASE="$PWD/database/capacitacion.sqlite" php artisan serve --port=8124  # en otra terminal
cd ../videos/sistema-completo
python gen_audio.py     # solo si cambió guion.json
python grabar.py        # tiempo real: ~12 min
python montar.py        # -> out/Indicadores Guadalupe - Recorrido completo del sistema.mp4
```

Al terminar: apagar el servidor y borrar `indicadores/database/capacitacion.sqlite`.

## Cuentas y datos

- Entra como `admin@guadalupe.local` / `password` (ve todo).
- La demostración trae septiembre 2025 (datos reales del cuadro), agosto 2025 sintético, metas y usuarios por rol.
- `prep_escenario.php` deja el mes en curso con dos días cargados y uno cerrado, septiembre 2025 abierto y el
  día 16 atípico; borra importaciones y usuarios `@farmacia.com` de tomas anteriores. Solo corre sobre la copia.
- En cámara se crean: un día del mes en curso, una meta, una tasa manual, un usuario (Ana Pérez), un cierre y
  una reapertura de septiembre 2025 y la importación del cuadro de ejemplo (enero 2020).

## Gotchas de este video

- Los recorridos guiados arrancan solos la primera vez: `SEEN_JS` los marca como vistos y la escena 31 lanza uno a propósito.
- El cursor no existe en headless: `CURSOR_JS` dibuja uno que sigue al ratón, y `click()` lo desplaza despacio antes de pulsar.
- Descargar PDF y Exportar a Excel disparan descargas: se envuelven en `expect_download` para que no bloqueen.
- "Consultar ahora" llama al BCV de verdad; si no hay internet, el aviso ámbar aparece y la escena sigue.
- El formulario del día puede pedir "Guardar de todos modos" (inventario en día de conteo): `confirmar_advertencias` lo pulsa.
