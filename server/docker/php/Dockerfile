# Imagen ligera para desarrollo local (alternativa a la imagen oficial de Sail,
# que tardaba demasiado en construirse tras el mirror de Ubuntu).
FROM php:8.4-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl bcmath gd pcntl \
    && pecl install redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuario no root que coincide con el del host (UID/GID 1000)
ARG WWWUSER=1000
ARG WWWGROUP=1000
RUN groupadd --force -g ${WWWGROUP} app \
    && useradd -ml -u ${WWWUSER} -g app app

WORKDIR /var/www/html
USER app

EXPOSE 80
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=80"]
