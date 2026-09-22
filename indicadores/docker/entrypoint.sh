#!/usr/bin/env bash
# Arranque del contenedor: prepara la aplicación y lanza los procesos.
set -euo pipefail

cd /var/www/html

echo "==> Esperando a la base de datos ${DB_HOST}:${DB_PORT} ..."
tries=0
until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" --silent 2>/dev/null; do
  tries=$((tries + 1))
  if [ "$tries" -ge 60 ]; then
    echo "!! La base de datos no respondió tras 60 intentos. Abortando."
    exit 1
  fi
  sleep 2
done
echo "==> Base de datos disponible."

# Descubrir paquetes (se omitió durante el build con --no-scripts).
php artisan package:discover --ansi || true

# Enlace de almacenamiento público (idempotente).
php artisan storage:link 2>/dev/null || true

# Migraciones.
# RUN_SEED=true solo en el PRIMER despliegue: reconstruye el esquema desde cero
# (por si un intento anterior dejó tablas a medias) y siembra roles, sede,
# parámetros y usuario administrador. QUITAR esta variable tras el primer
# despliegue exitoso para que los siguientes no borren datos.
if [ "${RUN_SEED:-false}" = "true" ]; then
  echo "==> Primer despliegue: migrate:fresh --seed (RUN_SEED=true) ..."
  php artisan migrate:fresh --force --seed
else
  echo "==> Ejecutando migraciones ..."
  php artisan migrate --force
fi

# Cachés de configuración, rutas y vistas.
echo "==> Optimizando (config/route/view cache) ..."
php artisan optimize

# Permisos (por si el volumen de storage se montó de nuevo).
chown -R www-data:www-data storage bootstrap/cache || true

echo "==> Iniciando Nginx, PHP-FPM y el planificador ..."
exec supervisord -c /etc/supervisor/conf.d/app.conf
