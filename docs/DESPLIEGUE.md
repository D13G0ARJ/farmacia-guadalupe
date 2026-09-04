# Despliegue y operación — Indicadores Farmacia Guadalupe

Guía para poner el sistema en el servidor del cliente y mantenerlo. Complementa el plan (`PLAN-DESARROLLO.md`, §4.6, §15.2 y §17 Fase 7).

## 1. Requisitos del hosting

| Recurso | Mínimo |
|---|---|
| PHP | 8.4 con `intl`, `bcmath`, `gd`, `zip`, `pdo_mysql`, `mbstring`, `fileinfo` |
| Base de datos | MySQL 8 o MariaDB 10.6 (`utf8mb4`) |
| Servidor web | Apache o Nginx apuntando a `indicadores/public` con HTTPS |
| Cron | Una línea: `* * * * * cd /ruta/indicadores && php artisan schedule:run >> /dev/null 2>&1` |
| Correo | Cuenta SMTP del cliente (para restablecer contraseñas, avisos y el reporte mensual) |
| Salida a internet | Para consultar la tasa BCV (`ve.dolarapi.com` y `bcv.org.ve`) |
| Herramientas de compilación | Node 20 y npm solo para `npm run build` (puede hacerse fuera del servidor y subir `public/build`) |

No hace falta Supervisor ni un worker permanente: la cola se procesa cada minuto desde el cron (`queue:work --stop-when-empty`).

## 2. Primera instalación

```bash
git clone https://github.com/D13G0ARJ/farmacia-guadalupe.git
cd farmacia-guadalupe/indicadores
composer install --no-dev --optimize-autoloader
cp .env.production.example .env        # completar APP_URL, DB_*, MAIL_*, ADMIN_*
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force            # roles, sede principal, parámetros, administrador
npm ci && npm run build                # o subir la carpeta public/build ya compilada
php artisan storage:link
php artisan optimize
```

Permisos: `storage/` y `bootstrap/cache/` escribibles por el usuario del servidor web.

El administrador inicial sale de `ADMIN_NAME`, `ADMIN_EMAIL` y `ADMIN_PASSWORD` del `.env`. Cambia la contraseña en la primera sesión (Perfil) y crea los demás usuarios desde Administración › Usuarios.

## 3. Datos de demostración

En desarrollo el sistema trae septiembre 2025 real y agosto sintético para que la primera sesión no esté vacía. Antes de la carga real:

```bash
php artisan demo:clear --force     # borra días, tasas, metas, cierres, importaciones, bitácora y usuarios de demostración
php artisan rates:fetch            # trae la tasa BCV de hoy y del siguiente día hábil
```

Si el cliente quiere ver la demostración en el servidor, sembrarla es opcional: `php artisan db:seed --class=DemoSeeder --force`.

## 4. Comprobaciones tras desplegar

1. **Acceso**: entrar con el administrador, comprobar que el menú muestra Panel, Cargar día, Mes, Gráficas, Metas, Año, Tasa BCV, Importar y Administración.
2. **Tasa BCV real**: `php artisan rates:fetch` debe imprimir dos fechas con su tasa. En Tasa BCV se ve "última consulta" y "último éxito". Si falla, revisar salida a internet y `RATES_PRIMARY_URL`.
3. **Cron**: esperar un minuto y verificar en Tasa BCV que la consulta programada corrió (08:00 y 17:30 hora de Caracas), o ejecutar `php artisan schedule:list`.
4. **Correo**: `php artisan tinker --execute 'Mail::raw("Prueba", fn ($m) => $m->to("tu@correo.com")->subject("Indicadores"));'` debe llegar. Luego configurar en Administración › Correo el día del reporte y los destinatarios.
5. **PDF**: en el Panel, "Descargar PDF" con un mes cargado.
6. **HTTPS**: el navegador debe mostrar el candado; el sistema fuerza `https` en producción y envía `Strict-Transport-Security`.

## 5. Tareas programadas

| Tarea | Cuándo | Comando |
|---|---|---|
| Tasa BCV de hoy (respaldo) | 08:00 | job `FetchDailyBcvRate` |
| Tasa BCV del siguiente día hábil | 17:30 | job `FetchDailyBcvRate` |
| Reporte mensual por correo | 07:00, solo el día configurado | `reports:send-monthly` |
| Recordatorio de cierre | día 1, 08:30 | `periods:remind-close` |
| Respaldo de la base de datos | 02:00 | `db:backup` |
| Cola | cada minuto | `queue:work --stop-when-empty` |

Todas se pueden correr a mano con `php artisan <comando>`; `reports:send-monthly --force --period=2025-09` envía un mes concreto.

## 6. Respaldos

`db:backup` deja `storage/app/backups/indicadores-AAAA-MM-DD-HHMMSS.sql.gz` (mysqldump comprimido) y conserva 30 días. El hosting debe copiar esa carpeta fuera del servidor (respaldo del panel de hosting, rsync o un bucket S3). Restaurar:

```bash
gunzip < indicadores-2026-09-04-020000.sql.gz | mysql -u indicadores -p indicadores
```

Probar una restauración en una base de prueba una vez al mes.

## 7. Seguridad

- HTTPS obligatorio; cookies `Secure` y `SameSite=Lax`; cabeceras `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` y HSTS.
- Contraseñas con bcrypt; límite de intentos en el acceso; usuarios desactivables sin borrar su historial.
- Autorización en el servidor por rol (operador, supervisión, dirección, administrador) y por sede.
- La bitácora no se edita desde la aplicación.
- `APP_DEBUG=false` siempre en producción.
- Repositorio: contiene el cuadro real de septiembre 2025 como fixture; conviene mantenerlo privado.

## 8. Actualizaciones

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize
```

Antes de actualizar, `php artisan db:backup`.

## 9. Pendientes que dependen del cliente

- Cuenta SMTP y dominio definitivo.
- Respuestas a las preguntas de `PLAN-DESARROLLO.md` §19 (reunión de arranque).
- Segunda sede (Fase 8, ampliación): el modelo ya la soporta; falta activar el selector y el consolidado en la interfaz.
- Autenticación en dos pasos para dirección: opcional, requiere instalar Fortify; no está incluida.
