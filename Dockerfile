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
#
# supervisord is the container's main process. It runs three programs: the web
# server, the queue worker and the scheduler. RUN_QUEUE_WORKER=false and
# RUN_SCHEDULER=false turn the last two off for setups that run them in
# containers of their own.

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

# supervisord (Debian's supervisor package, which pulls in python3) runs the web
# server, the queue worker and the scheduler; see /etc/supervisor/watch-tower.conf.
# Not version-pinned, so rebuilds pick up Debian security updates.
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*

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

COPY --chmod=644 <<'EOF' /etc/supervisor/watch-tower.conf
; Process manager for the Watch Tower image (see doc/docker.md).
;
; Runs the web server, the queue worker and the scheduler in one container.
; RUN_QUEUE_WORKER=false and RUN_SCHEDULER=false stop the last two from
; starting, for setups that run them in containers of their own. The image
; sets both to true; each must be true or false.
;
; The three programs form one group, so SIGTERM stops them in parallel.
; Inspect with: supervisorctl -c /etc/supervisor/watch-tower.conf status

[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid
childlogdir=/tmp

[unix_http_server]
file=/tmp/supervisor.sock
chmod=0700

[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///tmp/supervisor.sock

[group:watch-tower]
programs=web,queue-worker,scheduler
priority=100

[program:web]
command=frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
directory=/var/www/html
priority=10
autostart=true
autorestart=true
startsecs=3
startretries=3
stopsignal=TERM
stopwaitsecs=20
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-worker]
command=php /var/www/html/artisan queue:work --tries=3 --sleep=3 --max-time=3600 --memory=128
directory=/var/www/html
priority=20
autostart=%(ENV_RUN_QUEUE_WORKER)s
autorestart=true
startsecs=5
startretries=10
stopsignal=TERM
stopwaitsecs=85
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:scheduler]
command=php /var/www/html/artisan schedule:work --whisper
directory=/var/www/html
priority=30
autostart=%(ENV_RUN_SCHEDULER)s
autorestart=true
startsecs=5
startretries=10
stopsignal=TERM
stopwaitsecs=20
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[eventlistener:exit-on-fatal]
command=/usr/local/bin/watch-tower-exit-on-fatal
events=PROCESS_STATE_FATAL
priority=1
autorestart=true
stdout_logfile=NONE
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
EOF

# Supervisor event listener. supervisord gives up on a program that keeps
# failing to start (FATAL) but keeps running itself; stop it instead so the
# container exits and the platform's restart policy starts a fresh one.
COPY --chmod=755 <<'EOF' /usr/local/bin/watch-tower-exit-on-fatal
#!/bin/sh
while :; do
    printf 'READY\n'
    IFS= read -r header || exit 0
    payload=$(head -c "${header##*len:}")
    echo "watch-tower: supervisord gave up on a program ($payload). Stopping the container." >&2
    kill -TERM "$PPID"
    printf 'RESULT 2\nOK'
done
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
#
# Set RUN_QUEUE_WORKER and RUN_SCHEDULER to false on the web container when the
# worker and scheduler run in containers of their own. They have no effect when
# CMD is overridden.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/database/database.sqlite \
    DB_JOURNAL_MODE=wal \
    DB_BUSY_TIMEOUT=5000 \
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    TELESCOPE_ENABLED=false \
    RUN_QUEUE_WORKER=true \
    RUN_SCHEDULER=true

# By default the base image's Caddy replaces REMOTE_ADDR with a client IP taken
# from CF-Connecting-IP or X-Forwarded-For whenever the peer has a private or
# Cloudflare address, so clients behind an ordinary reverse proxy could choose
# their own IP. Turn that off: Laravel's TRUSTED_PROXIES is the only trust setting.
ENV TRUSTED_PROXY=off

# vendor changes least often, so it gets its own layer.
COPY --from=build --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=build --chown=www-data:www-data /dist ./

EXPOSE 8080

CMD ["supervisord", "-n", "-c", "/etc/supervisor/watch-tower.conf"]
