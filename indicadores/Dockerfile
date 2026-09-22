# Imagen de producción para "Indicadores Farmacia Guadalupe" (Laravel 13 + Livewire).
# Construye dependencias PHP y assets de Vite, y sirve con Nginx + PHP-FPM.
# El planificador (tasa BCV, reporte mensual, respaldo y cola) corre dentro del
# mismo contenedor vía Supervisor (php artisan schedule:work).

# ---------- Etapa 1: dependencias PHP (Composer) ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY . .
RUN composer install \
      --no-dev \
      --optimize-autoloader \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --prefer-dist \
      --ignore-platform-reqs

# ---------- Etapa 2: assets de frontend (Vite) ----------
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---------- Etapa 3: runtime ----------
FROM php:8.4-fpm-bookworm AS runtime

# Paquetes del sistema: Nginx, Supervisor, cliente MySQL (para db:backup) y
# librerías necesarias para compilar las extensiones de PHP.
RUN apt-get update && apt-get install -y --no-install-recommends \
      nginx \
      supervisor \
      git \
      unzip \
      gzip \
      default-mysql-client \
      libicu-dev \
      libzip-dev \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
      libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
      intl \
      bcmath \
      gd \
      zip \
      pdo_mysql \
      mbstring \
      exif \
      pcntl \
      opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configuración de PHP, PHP-FPM, Nginx y Supervisor.
COPY docker/php.ini /usr/local/etc/php/conf.d/zzz-app.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf

WORKDIR /var/www/html

# Código de la aplicación.
COPY . .
# Dependencias ya instaladas (vendor) y assets ya compilados (public/build).
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Permisos para el usuario del servidor web.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint"]
