# Production image for Watch Tower (Laravel + Inertia + React) on FrankenPHP.
#
#   docker build -t watch-tower .
#   docker build --build-arg PHP_IMAGE=serversideup/php:8.5-frankenphp-trixie -t watch-tower .
#
# The PHP image is pinned by digest, so every build gets the same PHP and OS packages. Dependabot
# moves the pin. To find a digest:
#   docker buildx imagetools inspect serversideup/php:<tag>
#
# The base image runs as the unprivileged www-data user, serves on port 8080
# and keeps the app in /var/www/html. See https://serversideup.net/open-source/docker-php/docs

ARG PHP_IMAGE=serversideup/php:8.4-frankenphp-trixie-v5.0.2@sha256:a535961aa62a118860292b3b04f689ee56a07f47938a614660e67fe98ff6853f
ARG NODE_IMAGE=node:24-bookworm-slim

# ---------------------------------------------------------------------------
# Node.js source. Only the binary and npm are borrowed from this image.
# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS node

# ---------------------------------------------------------------------------
# Build stage: PHP dependencies and front-end assets.
#
# `npm run build` needs PHP and the app's vendor/ directory, because the
# Wayfinder Vite plugin runs `php artisan wayfinder:generate` on build start.
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS build

USER root
WORKDIR /var/www/html

# Add Node.js without touching /usr/local, which holds PHP.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# PHP dependencies first so this layer is cached until composer.lock changes.
# Scripts are skipped here because `artisan` is not copied yet.
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts \
    && composer check-platform-reqs --no-dev

# Node dependencies, cached until package-lock.json changes.
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

# Application source, then the steps that need it.
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan storage:link \
    && npm run build

# Collect only what the running app needs. Excluded on purpose: node_modules,
# tests, docs, dev configs and resources/js (the Blade layout only reads the
# Vite manifest in public/build).
RUN mkdir /dist \
    && cp -a --parents \
        app bootstrap config database public resources/views routes storage \
        artisan composer.json composer.lock \
        /dist/

# ---------------------------------------------------------------------------
# Runtime stage
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime

USER root

# Runs before the base image's Laravel automations (50-*). Makes sure the
# storage tree and the default SQLite file exist, so a freshly mounted empty
# volume works. Sourced by the entrypoint, so it must stay POSIX sh.
COPY --chmod=755 <<'EOF' /etc/entrypoint.d/40-watch-tower-storage.sh
#!/bin/sh
: "${APP_BASE_DIR:=/var/www/html}"

prepare_storage() {
    mkdir -p \
        "$APP_BASE_DIR/storage/app/public" \
        "$APP_BASE_DIR/storage/app/private" \
        "$APP_BASE_DIR/storage/framework/cache/data" \
        "$APP_BASE_DIR/storage/framework/sessions" \
        "$APP_BASE_DIR/storage/framework/views" \
        "$APP_BASE_DIR/storage/logs" || return 1

    if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ "$DB_DATABASE" != ":memory:" ]; then
        mkdir -p "$(dirname "$DB_DATABASE")" && touch "$DB_DATABASE" || return 1
    fi
}

if ! prepare_storage; then
    echo "Cannot write to the storage directory or SQLite file. Make the mounted volume writable by UID 33 (www-data)." >&2
    exit 1
fi
EOF

USER www-data
WORKDIR /var/www/html

# Defaults for a container deployment. Everything here can be overridden at
# runtime. Do not put secrets (APP_KEY, DB passwords) in the image: pass them
# with -e, --env-file or your orchestrator's secrets.
#
# AUTORUN_ENABLED is left at the base image default (false). Set it to true on
# the web container to run migrations and the config/route/view/event caches
# on every start. Leave it off for queue and scheduler containers.
#
# SQLite: WAL lets reads run while a write is in progress, and the busy timeout
# makes a second writer wait up to 5 seconds instead of failing with
# "database is locked" during bursts of ingest.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/database/database.sqlite \
    DB_JOURNAL_MODE=wal \
    DB_BUSY_TIMEOUT=5000 \
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    TELESCOPE_ENABLED=false

# By default the base image's Caddy replaces REMOTE_ADDR with a client IP taken
# from CF-Connecting-IP or X-Forwarded-For whenever the peer has a private or
# Cloudflare address, so clients behind an ordinary reverse proxy could choose
# their own IP. Turn that off: Laravel's TRUSTED_PROXIES is the only trust setting.
ENV TRUSTED_PROXY=off

# vendor changes least often, so it gets its own layer.
COPY --from=build --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=build --chown=www-data:www-data /dist ./

EXPOSE 8080
