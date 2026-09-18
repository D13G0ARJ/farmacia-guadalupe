# Cómo pedirle a Claude recorridos guiados profesionales (driver.js) en cualquier sistema

Este documento resume el método con el que se hicieron los recorridos guiados del sistema de
indicadores de Farmacia Guadalupe (rama `recorridos-guiados`), para repetirlo con el mismo nivel en
otro sistema. Tiene dos partes: el **prompt** que se le pega a una sesión nueva de Claude Code y la
**lista de criterios** con la que se revisa el resultado.

---

## 1. Prompt para pegar en la sesión nueva

> Copia desde aquí. Cambia lo que va entre corchetes.

```
Crea una rama aparte a partir de main llamada `recorridos-guiados`. En esa rama vamos a implementar
recorridos guiados con driver.js (versión 1.x, MIT) para TODOS los módulos del sistema, explicando
absolutamente cada pantalla, cada botón, cada campo, cada pestaña, cada diálogo y cada opción, de la
forma más descriptiva y clara posible para una persona que no es técnica. No puede quedar ni una
acción ni un botón sin explicar.

Antes de escribir nada:
1. Analiza driver.js a fondo (API: driver({steps, onNextClick, onPrevClick, onDestroyStarted,
   skipMissingElement, popoverClass, progressText…}), cómo resalta elementos, cómo se navega entre
   pasos, cómo se destruye, qué pasa con elementos ocultos o que aparecen tras un clic).
2. Analiza cada módulo del sistema a detalle: abre cada vista, lista cada elemento interactivo
   (botones, enlaces, campos, selects, checkboxes, pestañas, diálogos, filas editables, atajos de
   teclado) y qué hace exactamente cada uno leyendo el código que lo ejecuta. Los textos del recorrido
   deben describir lo que el sistema hace de verdad, no lo que parece que hace.

Arquitectura que quiero (adáptala al stack de este proyecto: [Laravel + Livewire / React / Vue…]):
- Definiciones de los pasos en el backend, en una sola clase o módulo (por ejemplo
  `App\Support\GuidedTours`), un recorrido por pantalla más un recorrido general del menú/armazón.
  Cada paso: ancla (`element`), título, descripción, lado, y opcionalmente `click` (elemento a pulsar
  antes de entrar, por ejemplo para abrir una pestaña o un diálogo), `close` (cerrar el diálogo al
  salir), `wait` (ms a esperar a que aparezca), `demo` (demostración con datos de ejemplo) y `undo`.
  Los pasos se filtran por permiso/rol del usuario: nadie ve explicaciones de botones que no tiene.
- Anclas `data-tour="clave"` en las vistas, en TODOS los elementos interactivos (o en su contenedor
  inmediato). Menú lateral, barra superior, botón de ayuda, botón principal de cada pantalla,
  tablas, filtros, exportar, imprimir, diálogos (con `data-tour` en el diálogo mismo) y sus botones.
- Un `tour.js` que: resuelve el ancla visible (si hay varias, la visible), ejecuta `click`/`demo`
  y espera el elemento antes de entrar a un paso, deshace y cierra al salir, marca el recorrido
  como visto (localStorage por usuario y ruta) ANTES de destruir, arranca solo una vez por
  pantalla en escritorio (no en móvil), se puede forzar con `?recorrido=1`, y expone
  `window.guidedTour.startScreen/startShell/stop`.
- Un `demos.js` con demostraciones nombradas: el recorrido no solo señala, HACE. Escribe datos de
  ejemplo en los campos (y el usuario ve cómo reacciona la pantalla), abre diálogos y los cancela,
  cambia pestañas, filtra tablas, y en el importador sube un archivo de ejemplo real (guardado en
  el repositorio y servido por una ruta autenticada), lo analiza y muestra la revisión. Ninguna
  demostración guarda nada en el servidor: cada una tiene su deshacer y cada pantalla su limpieza
  al terminar o cancelar el recorrido (formularios vacíos, diálogos cerrados, lotes de importación
  sin confirmar eliminados). Para el paso final de un flujo que sí escribiría (confirmar
  importación, guardar), usa una captura de pantalla real dentro del globo en vez de ejecutarlo.
- Sección "Recorridos guiados" en el panel de ayuda de cada pantalla: "Ver el recorrido de esta
  pantalla", "Cómo moverte por el sistema" y la lista de todos los recorridos con la marca "visto".
  Los datos (pasos, catálogo, limpieza, url del archivo de ejemplo) van en un JSON en la página.
- Tema visual del globo acorde a la marca del sistema (clase `popoverClass`), textos de botones en
  español ("Anterior", "Siguiente", "Listo"), progreso "3 de 24".
- Los pasos de estados que no están en pantalla (mes cerrado, día bloqueado, tabla vacía,
  advertencias) se definen igual y driver.js los salta si no existen; deja documentado qué anclas
  corresponden a estados.

Calidad de los textos (revisar cada mensaje uno por uno al terminar):
- Lenguaje sencillo, sin jerga técnica, dirigido a quien usa el sistema. Cada descripción dice qué
  es el elemento, qué pasa al usarlo y cuándo conviene usarlo. Mínimo 40 caracteres, ideal 1 a 3
  frases. Títulos cortos.
- Nada de promesas falsas: si el texto dice "el sistema pide el motivo", el sistema tiene que
  pedirlo de verdad (si no, corrige el sistema o el texto). Verifica cada afirmación contra el
  código antes de darla por buena.
- Cada recorrido empieza con una introducción de la pantalla y termina en el botón de ayuda
  explicando cómo repetirlo.

Verificación automática obligatoria (pruebas en el framework del proyecto):
1. Estructura: cada recorrido tiene introducción y cierre; toda descripción supera el mínimo;
   el catálogo tiene todas las pantallas.
2. Cobertura total: para cada pantalla renderizada con datos, TODO elemento interactivo dentro del
   contenido principal está dentro de un ancla `data-tour` que tiene un paso, y toda ancla tiene un
   paso (o está declarada como ancla de estado). Si falta uno, la prueba falla.
3. Cada `demo`/`undo`/limpieza nombrado en las definiciones existe en `demos.js`.
4. El archivo de ejemplo se sirve solo con sesión y se analiza correctamente.
5. Permisos: un usuario con rol limitado no recibe pasos de lo que no puede ver.
6. Prueba de navegador (Playwright): abre cada recorrido desde el panel de ayuda y avanza hasta
   el final (con los diálogos que abre y cierra y las demostraciones), y en un navegador nuevo
   comprueba que el recorrido arranca solo la primera vez y no la segunda.

Procedimiento: implementar → revisión del código → pruebas unitarias/feature → prueba E2E de
navegador → revisar cada mensaje del recorrido a ver si es fácil de entender y correcto, y
mejorarlo → documentar en el plan del proyecto → commit y push a la rama. Cuando lo implementes,
verifícalo tú mismo pantalla por pantalla para que no falte nada. Muéstrame al final un
resumen con el número de pasos por pantalla y qué demostraciones tiene cada una.
```

---

## 2. Con qué se revisa el resultado (checklist)

Marca cada punto antes de dar el trabajo por terminado.

**Cobertura**
- [ ] Hay un recorrido por pantalla y uno del menú/armazón.
- [ ] Cada botón, enlace, campo, pestaña, diálogo y fila editable tiene su paso.
- [ ] Una prueba automática falla si aparece un elemento interactivo sin ancla o un ancla sin paso.
- [ ] Los estados ocultos (cerrado, vacío, bloqueado, advertencias) tienen paso y están declarados.

**Textos**
- [ ] Cada mensaje se leyó uno por uno; ninguno tiene jerga ni describe algo que el sistema no hace.
- [ ] Cada descripción responde: qué es, qué pasa al usarlo, cuándo usarlo.
- [ ] Introducción al inicio y cierre en el botón de ayuda.

**Demostraciones**
- [ ] Los formularios se llenan con datos de ejemplo delante del usuario y se vacían al salir.
- [ ] Los diálogos se abren y se cancelan solos; las pestañas cambian solas.
- [ ] El importador (o flujo equivalente) sube un archivo de ejemplo real y muestra la revisión;
      el paso final que escribiría se muestra con una captura, no se ejecuta.
- [ ] Nada queda guardado en el servidor después de un recorrido, ni aunque se cancele a mitad.

**Comportamiento**
- [ ] Arranca solo una vez por pantalla y usuario en escritorio; nunca en móvil.
- [ ] Se puede relanzar desde la ayuda y con `?recorrido=1`.
- [ ] Marca "visto" antes de la animación de cierre (si no, vuelve a arrancar).
- [ ] Respeta permisos: cada rol ve solo los pasos de lo que puede usar.
- [ ] El globo usa los colores y la tipografía de la marca.

**Pruebas**
- [ ] Pruebas de estructura, cobertura, demos, archivo de ejemplo y permisos en verde.
- [ ] Prueba de navegador que recorre los N recorridos hasta el final y comprueba el arranque automático.
- [ ] Documentado en el plan del proyecto y subido a la rama.

---

## 3. Trampas que ya nos costaron tiempo (díselas a la sesión nueva si el stack es parecido)

- **Blade/Livewire:** en atributos de componentes `<x-...>` no van directivas ni comillas dobles
  dentro de `{{ }}`; `@json([...])` dentro de `<script>` con paréntesis anidados rompe la
  compilación (usar `json_encode` en `@php` y `{!! !!}`).
- **Elementos duplicados ocultos** (paneles, overlays) rompen los localizadores estrictos de
  Playwright: envolverlos en `<template x-if>` o equivalente.
- **Tecla "?"** para la ayuda no funciona cuando el foco está en un campo; en las pruebas usar el
  botón de ayuda.
- **Pasos con demostración tardan** (escriben datos y esperan el elemento): la prueba de navegador
  debe dar margen a que el progreso avance, no exigirlo al instante.
- **driver.js salta los pasos cuyo elemento no existe**: el progreso puede avanzar de a más de uno;
  la prueba debe exigir llegar al final, no avanzar exactamente de uno en uno.
- **Servidores huérfanos** en Windows: al matar la prueba de navegador, `php -S` sobrevive; revisar
  el puerto con `netstat -ano` y cerrar el proceso con PowerShell `Stop-Process`, no con `taskkill`
  desde Git Bash.
- **Un recorrido de prueba no se corre al mismo tiempo que la suite de pruebas** (comparten la base).

---

## 4. Referencia del proyecto donde ya está hecho

Rama `recorridos-guiados` de https://github.com/D13G0ARJ/farmacia-guadalupe, carpeta `indicadores/`:

| Pieza | Archivo |
|---|---|
| Definiciones de pasos, demos, limpieza y anclas de estado | `app/Support/GuidedTours.php` |
| Motor del recorrido | `resources/js/tour.js` |
| Demostraciones con datos de ejemplo | `resources/js/demos.js` |
| Sección en el panel de ayuda + JSON | `resources/views/layouts/partials/help-panel.blade.php` |
| Tema del globo | `resources/css/app.css` (`.driver-popover.tour-guadalupe`) |
| Archivo de ejemplo y ruta que lo sirve | `resources/samples/cuadro-ejemplo.xlsx`, `routes/web.php` (`tours.sample`) |
| Pruebas de cobertura y calidad | `tests/Feature/Ui/GuidedToursTest.php` |
| Prueba de navegador (los diez recorridos y el arranque automático) | `tests/Browser/walkthrough.py` |
| Documentación | `docs/PLAN-DESARROLLO.md` §20 iteración 18 |

Si el otro sistema es Laravel + Livewire, la sesión nueva puede copiar esa arquitectura tal cual y
adaptar anclas y textos; si es otro stack, el prompt de arriba describe lo que hay que reproducir.
