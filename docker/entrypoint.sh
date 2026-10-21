#!/bin/sh
# Runs every time the container starts, then hands over to the web server.
set -e
cd /app

# Fail loudly with a clear message instead of a vague 500 later.
if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with:  php artisan key:generate --show" >&2
    exit 1
fi
if [ -z "$NIN_HASH_KEY" ]; then
    echo "NIN_HASH_KEY is not set. Generate one with:  php -r 'echo bin2hex(random_bytes(32));'" >&2
    exit 1
fi

php artisan package:discover --ansi

# ---- Database set-up (runs BEFORE config is cached, so the URL override below still works) ----
# Neon: the app should use the POOLED url (host contains "-pooler") in DB_URL, but migrations
# should use the DIRECT url, because the pooler can interfere with schema changes. Put the direct
# one in DB_DIRECT_URL and this script uses it only for the two commands below. Not set = DB_URL is used.
#
# Safety net: if DB_DIRECT_URL is missing (or still points at the pooler), derive the direct host
# by removing "-pooler" from the host name. Neon's direct host is the same name without it.
# channel_binding=require is also dropped because it is not needed and can break the PHP driver.
SETUP_BASE="${DB_DIRECT_URL:-$DB_URL}"
DB_URL_FOR_SETUP=$(printf '%s' "$SETUP_BASE" | sed -e 's/-pooler\././' -e 's/&channel_binding=require//' -e 's/?channel_binding=require&/?/' -e 's/?channel_binding=require$//')
export DB_URL_FOR_SETUP

# The running app uses the direct host too. Through Neon's pooler (PgBouncer, transaction mode) any
# request that runs inside a DB transaction failed with SQLSTATE 25P02 ("current transaction is
# aborted"): rate limiting, and the access flow's "first approval wins" lock. A demo with one
# instance does not need pooling, so the direct host is the safe choice.
if [ -n "$DB_URL_FOR_SETUP" ]; then
    export DB_URL="$DB_URL_FOR_SETUP"
fi

# Create or update the tables. Safe to repeat: only new migrations run.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    DB_URL="$DB_URL_FOR_SETUP" php artisan migrate --force
fi

# Demo data (Tunde, the hospital, a card). The seeder skips itself when users already exist,
# and refuses to run when APP_ENV=production.
if [ "${SEED_DEMO:-false}" = "true" ]; then
    DB_URL="$DB_URL_FOR_SETUP" php artisan db:seed --force
fi

# Cache config and events (env vars exist now, they did not at build time).
# Routes are not cached: one route is a closure.
php artisan config:cache
php artisan event:cache

# The scheduler is what asks the emergency contacts when a patient has not answered. A real
# deployment would use a Render Cron Job; for a demo, a background loop in this container is enough.
if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
    php artisan schedule:work >> /proc/1/fd/1 2>&1 &
fi

exec "$@"
