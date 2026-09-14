# syntax=docker/dockerfile:1
FROM php:8.4-apache-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl git gosu libfreetype6-dev libicu-dev libjpeg62-turbo-dev libonig-dev \
    libpng-dev libwebp-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) bcmath exif gd intl mbstring opcache pcntl pdo_mysql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

FROM php-base AS dependencies
COPY backend/composer.json backend/composer.lock ./
RUN --mount=type=secret,id=COMPOSER_AUTH,required=true \
    COMPOSER_AUTH="$(cat /run/secrets/COMPOSER_AUTH)" composer install \
    --no-dev --prefer-dist --no-interaction --no-scripts --no-progress

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY backend/package.json backend/package-lock.json ./
RUN npm ci
COPY backend/resources ./resources
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY backend/vite.config.js ./
RUN npm run build

FROM php-base AS production
COPY backend/ .
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY .docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY .docker/php.ini /usr/local/etc/php/conf.d/tendies.ini
COPY --chmod=755 .docker/entrypoint.sh /usr/local/bin/tendies-entrypoint
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi \
    && php artisan nova:publish --no-interaction

EXPOSE 80
ENTRYPOINT ["tendies-entrypoint"]
CMD ["web"]
