# syntax=docker/dockerfile:1.7

FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN mkdir -p public && npm run build

FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        curl \
        nginx \
        oniguruma-dev \
        sqlite-dev \
        supervisor \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        mbstring \
        opcache \
        pdo_sqlite \
        pdo_mysql \
    && rm -rf /tmp/* /var/cache/apk/*

WORKDIR /var/www/html

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-izinhukum.ini
COPY docker/entrypoint.sh /usr/local/bin/izinhukum-entrypoint
COPY --from=vendor --chown=www-data:www-data /app .
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN chmod +x /usr/local/bin/izinhukum-entrypoint \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["izinhukum-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
