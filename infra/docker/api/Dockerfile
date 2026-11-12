# syntax=docker/dockerfile:1
# Laravel API image. Only the `dev` target exists for now; the production target (code baked in,
# `composer install --no-dev`, opcache tuned) is added with the deployment work in Phase 6.

FROM php:8.3-fpm-alpine AS base

# Build context is the repository root; the source is bind-mounted at runtime in development.
ARG HOST_UID=1000
ARG HOST_GID=1000

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# phpredis (Redis client), pdo_mysql (MySQL), plus extensions Laravel and later phases need.
RUN install-php-extensions pdo_mysql redis pcntl opcache intl zip bcmath \
    && apk add --no-cache shadow \
    && usermod -u "${HOST_UID}" www-data \
    && groupmod -g "${HOST_GID}" www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS dev

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# php-fpm keeps a root master process and drops workers to www-data (mapped to your host UID/GID
# so files written to the bind mount stay yours). Port 9000 is reachable only on private networks.
EXPOSE 9000
CMD ["php-fpm"]
