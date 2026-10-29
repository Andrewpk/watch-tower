# Running Watch Tower with Docker

Every published GitHub release builds a production image (linux/amd64) and pushes it to GitHub Container Registry. It is based on [serversideup/php](https://serversideup.net/open-source/docker-php/docs) with FrankenPHP, runs as the unprivileged `www-data` user and listens on port **8080**.

```bash
docker pull ghcr.io/andrewpk/watch-tower:latest
```

Releases must use semver tags such as `v1.2.3`. They publish the tags `1.2.3`, `1.2` and `latest`. A pre-release such as `v1.0.0-rc.1` publishes `1.0.0-rc.1` and `latest`. `latest` always points at the most recently published release, which can be a pre-release, so pin a version in production.

## What runs in the container

The container's main process is supervisord. It runs three programs:

- the web server (FrankenPHP)
- a queue worker (`queue:work`), which stores ingested events
- the scheduler (`schedule:work`), which applies retention

Both of the last two matter. Without the worker, ingested events are never stored and dashboards stay empty. Without the scheduler, retention never runs, telemetry grows without bound and, on SQLite, a full disk takes down login and ingest too.

The image starts them by default, so a single container works on its own. If you run the worker and scheduler in containers of their own, turn them off on the web container: see [Separate worker and scheduler containers](#separate-worker-and-scheduler-containers).

## Environment

Nothing secret is baked into the image. Pass settings with `-e` or `--env-file`.

| Variable | Notes |
| --- | --- |
| `APP_KEY` | **Required.** Generate one with `echo "base64:$(openssl rand -base64 32)"`. Keep it stable between restarts. |
| `APP_URL` | **Required.** The public URL, for example `https://watch.example.com`. |
| `DB_*` | Defaults to SQLite at `/var/www/html/storage/database/database.sqlite`. For MySQL 8 set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. PostgreSQL is not supported yet ([#19](https://github.com/Andrewpk/watch-tower/issues/19)). |
| `TRUSTED_PROXIES` | Off by default. Set it when a reverse proxy or load balancer terminates TLS in front of the container. See [Behind a reverse proxy](#behind-a-reverse-proxy). |
| `AUTORUN_ENABLED` | Defaults to `false`. Set `true` on the web container to run migrations and cache config, routes, views and events on every start. |
| `RUN_QUEUE_WORKER` | Defaults to `true`. Set `false` to not start the queue worker in this container. Must be `true` or `false`. Ignored when you override the container command. |
| `RUN_SCHEDULER` | Defaults to `true`. Set `false` to not start the scheduler in this container. Must be `true` or `false`. Ignored when you override the container command. |
| `WATCH_PRUNE_OVERDUE_HOURS`, `WATCH_QUEUE_STALLED_MINUTES` | Optional. Thresholds for the admin warnings. See [Retention and maintenance warnings](#retention-and-maintenance-warnings). |

The image sets `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr` (read logs with `docker logs`), `HEALTHCHECK_PATH=/up`, `TELESCOPE_ENABLED=false`, `RUN_QUEUE_WORKER=true`, `RUN_SCHEDULER=true` and `TRUSTED_PROXY=off` (see [Behind a reverse proxy](#behind-a-reverse-proxy)).

## Persistent data

Mount one volume at **`/var/www/html/storage`**. It holds the SQLite database (`storage/database/`), uploaded avatars and the framework cache. Do not mount over `/var/www/html/database`: that directory contains the migrations.

A named volume works as is. For a bind mount, the directory must be writable by UID 33 (`www-data`), for example `chown 33:33 ./storage`. Missing subdirectories and the SQLite file are created on start.

## Run

```bash
docker run -d --name watch-tower --restart unless-stopped -p 8080:8080 \
  -e APP_KEY=base64:... \
  -e APP_URL=http://localhost:8080 \
  -e AUTORUN_ENABLED=true \
  -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:1.2.3
```

`--restart unless-stopped` matters: if a program keeps failing, the container stops on purpose and the restart policy starts a fresh one. See [Stopping and restarts](#stopping-and-restarts). Plan for about 512 MB of memory for the three processes together.

Check `curl http://localhost:8080/up`, then open the app. There is no sign-up page. Create the first user with the seeder, then log in as `super@admin.test` / `12345678` and change the password straight away. The seeded password is public, so do this before the container is reachable from the internet:

```bash
docker exec watch-tower php artisan db:seed --class=SuperAdminSeeder --force
```

## Behind a reverse proxy

When a proxy terminates TLS, the container only sees plain HTTP. Laravel then ignores `X-Forwarded-Proto` and `X-Forwarded-Host`, so redirects and asset URLs use `http://` (browsers block them as mixed content on an `https://` page) and every user appears to come from the proxy's IP address. Make your proxy send `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host`, then set:

```bash
-e TRUSTED_PROXIES='*'
```

- `TRUSTED_PROXIES='*'` trusts whichever peer connects. Use it only when the container is reachable through your proxy alone (an internal Docker network, or a port that is not published). If clients can reach the container directly, they can forge `X-Forwarded-For` to pick their own IP and get around login throttling.
- `TRUSTED_PROXIES='10.0.0.0/8,172.16.0.0/12'` trusts only the listed IPs and CIDR ranges. Use it when other hosts can reach the container, or when several proxies sit in front of it (`*` trusts only the immediate peer).

Leave it unset if the container is not behind a proxy.

The image sets the base image's `TRUSTED_PROXY` (note the singular name) to `off`. Its default, `cloudflare`, makes Caddy replace the connection's address with a client IP from `CF-Connecting-IP` or `X-Forwarded-For` whenever the peer has a private or Cloudflare address. Behind a proxy that passes those headers through, clients could then choose their own IP, and a `TRUSTED_PROXIES` list would never match. Leave it `off` and use `TRUSTED_PROXIES` instead.

## Separate worker and scheduler containers

Use this when you want to scale or restart the worker and scheduler apart from the web server. Set both flags to `false` on the web container, then start the others from the same image. Share the same environment and storage volume, and leave `AUTORUN_ENABLED` off so that only the web container migrates.

```bash
docker run -d --name watch-tower --restart unless-stopped -p 8080:8080 \
  -e RUN_QUEUE_WORKER=false -e RUN_SCHEDULER=false \
  -e APP_KEY=base64:... -e APP_URL=http://localhost:8080 -e AUTORUN_ENABLED=true \
  -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:1.2.3

docker run -d --name watch-tower-queue --restart unless-stopped \
  --health-cmd healthcheck-queue \
  -e APP_KEY=base64:... -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:1.2.3 php artisan queue:work --tries=3

docker run -d --name watch-tower-scheduler --restart unless-stopped \
  --health-cmd healthcheck-schedule \
  -e APP_KEY=base64:... -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:1.2.3 php artisan schedule:work
```

`healthcheck-queue` and `healthcheck-schedule` come with the base image. They replace the web health check, which does not apply to these containers.

> **Upgrading from an earlier image?** If you already run a worker and a scheduler in their own containers, set `RUN_QUEUE_WORKER=false` and `RUN_SCHEDULER=false` on the web container. Without that, the web container also starts a worker and a scheduler. A second scheduler is harmless (see below), but an extra worker adds database contention, most noticeably on SQLite.

- **Several replicas of the web container.** Each runs its own scheduler. The daily tasks use `onOneServer()`, so they run once as long as the replicas share one database cache (the default `CACHE_STORE=database`). Do not give replicas separate caches.
- **SQLite.** Run one worker per deployment. Several workers on one SQLite file can fail to claim a job under write contention.
- **Several hosts or workers.** Use MySQL 8.

## Stopping and restarts

- **Stopping.** On SIGTERM supervisord stops the three programs in parallel. The worker finishes its current job first, and the scheduler releases its locks.
- **A program that keeps failing.** supervisord restarts a crashed program. If it fails to start repeatedly, supervisord gives up on it, and the image then stops the container with exit code 0. Use `--restart unless-stopped` or `always` (or your platform's equivalent), because Docker's default (`no`) and `on-failure` do not restart a container that exits with code 0.
- **Long jobs.** The worker gets up to 85 seconds to finish a job after SIGTERM, but Docker kills the container after 10 seconds by default. Pass `--stop-timeout 90` (Compose: `stop_grace_period: 90s`) so a long job, such as deleting a large project, can finish. A job that is interrupted is retried.
- **Status.** `docker exec watch-tower supervisorctl -c /etc/supervisor/watch-tower.conf status` lists the three programs.
- **Logs.** All three write to `docker logs`, interleaved and without a prefix.
- **Writable `/tmp`.** supervisord keeps its socket and pid file in `/tmp`. Do not mount it read-only.

## Retention and maintenance warnings

Watch Tower records when `watch:prune` last finished. Super admins see a warning at the top of every page when:

- retention has not finished within `WATCH_PRUNE_OVERDUE_HOURS` (default `25`), or has never run on an install older than that. The scheduler is probably not running.
- a queued job has waited unclaimed for `WATCH_QUEUE_STALLED_MINUTES` (default `10`). The queue worker is probably not running. This check only applies to the `database` queue driver, which is the default.

Settings > Projects shows when retention last ran and how many rows it deleted.

To see what the next prune would delete without deleting anything:

```bash
docker exec watch-tower php artisan watch:prune --dry-run
```

`/up` does not fail when these warnings show. A restart cannot fix a missing scheduler or worker, and a failing health check would restart a healthy web server in a loop.

## Notes

- Example proxy configurations, SQLite tuning and a Compose stack are tracked in [#19](https://github.com/Andrewpk/watch-tower/issues/19). See [Behind a reverse proxy](#behind-a-reverse-proxy) for the trusted proxy settings.
- Build it yourself with `docker build -t watch-tower .`. The PHP image is pinned by digest in the `Dockerfile`; override it with `--build-arg PHP_IMAGE=serversideup/php:<tag>`.
- SQLite runs in WAL mode with a 5 second busy timeout (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`).
