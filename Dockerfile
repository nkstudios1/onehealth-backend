# syntax=docker/dockerfile:1
#
# OneHealth API image. Two stages:
#   1. "vendor"  installs PHP packages with Composer (nothing from here but /app/vendor is needed later).
#   2. runtime   FrankenPHP (a PHP app server built on Caddy). One process serves HTTP and runs PHP,
#                so there is no nginx + php-fpm + supervisor to wire together.
# PHP 8.4: composer.lock pins Symfony 8.x, which requires PHP >= 8.4.1.
# Build locally:  docker build -t onehealth-api .
# Run locally:    docker run --rm -p 8080:8080 --env-file .env -e APP_KEY=... onehealth-api

# ---- 1. dependencies -------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app

# Copy only the dependency files first. Docker caches this layer, so rebuilding after a code
# change does not reinstall every package. (composer.lock* = "if a lock file exists".)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
    --no-scripts --no-autoloader --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    --no-scripts --ignore-platform-reqs

# ---- 2. runtime ------------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-bookworm

# pdo_pgsql = talk to PostgreSQL (Render's database). opcache = keeps compiled PHP in memory (faster).
RUN install-php-extensions pdo_pgsql opcache

WORKDIR /app
COPY --from=vendor /app /app
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Render tells the app which port to listen on through $PORT. 8080 is the default for local runs.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
