# Running Watch Tower with Docker

Every published GitHub release builds a production image (linux/amd64) and pushes it to GitHub Container Registry. It is based on [serversideup/php](https://serversideup.net/open-source/docker-php/docs) with FrankenPHP, runs as the unprivileged `www-data` user and listens on port **8080**.

```bash
docker pull ghcr.io/andrewpk/watch-tower:latest
```

Releases must use semver tags such as `v1.2.3`. They publish the tags `1.2.3`, `1.2` and `latest`. A pre-release such as `v1.0.0-rc.1` publishes `1.0.0-rc.1` and `latest`. `latest` always points at the most recently published release, which can be a pre-release, so pin a version in production.

## Environment

Nothing secret is baked into the image. Pass settings with `-e` or `--env-file`.

| Variable | Notes |
| --- | --- |
| `APP_KEY` | **Required.** Generate one with `echo "base64:$(openssl rand -base64 32)"`. Keep it stable between restarts. |
| `APP_URL` | **Required.** The public URL, for example `https://watch.example.com`. |
| `DB_*` | Defaults to SQLite at `/var/www/html/storage/database/database.sqlite`. For MySQL 8 set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. PostgreSQL is not supported yet ([#19](https://github.com/Andrewpk/watch-tower/issues/19)). |
| `AUTORUN_ENABLED` | Defaults to `false`. Set `true` on the web container to run migrations and cache config, routes, views and events on every start. |

The image sets `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr` (read logs with `docker logs`), `HEALTHCHECK_PATH=/up` and `TELESCOPE_ENABLED=false`.

## Persistent data

Mount one volume at **`/var/www/html/storage`**. It holds the SQLite database (`storage/database/`), uploaded avatars and the framework cache. Do not mount over `/var/www/html/database`: that directory contains the migrations.

A named volume works as is. For a bind mount, the directory must be writable by UID 33 (`www-data`), for example `chown 33:33 ./storage`. Missing subdirectories and the SQLite file are created on start.

## Run

```bash
docker run -d --name watch-tower -p 8080:8080 \
  -e APP_KEY=base64:... \
  -e APP_URL=http://localhost:8080 \
  -e AUTORUN_ENABLED=true \
  -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:1.2.3
```

Check `curl http://localhost:8080/up`, then open the app. There is no sign-up page. Create the first user with the seeder, then log in as `super@admin.test` / `12345678` and change the password straight away. The seeded password is public, so do this before the container is reachable from the internet:

```bash
docker exec watch-tower php artisan db:seed --class=SuperAdminSeeder --force
```

## Queue worker and scheduler

Events are processed by the queue, so run a worker next to the web container. The scheduler uses the same image. Share the same environment and storage volume, and leave `AUTORUN_ENABLED` off so that only the web container migrates. `--no-healthcheck` turns off the web health check, which does not apply to these containers.

```bash
docker run -d --name watch-tower-queue --no-healthcheck \
  -e APP_KEY=base64:... -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:1.2.3 php artisan queue:work --tries=3

docker run -d --name watch-tower-scheduler --no-healthcheck \
  -e APP_KEY=base64:... -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:1.2.3 php artisan schedule:work
```

The scheduler also applies each project's retention (`watch:prune` runs daily), so keep it running or old telemetry is never deleted.

SQLite works for a single host. Containers on different hosts need MySQL.

## Notes

- TLS and reverse proxy setup, SQLite tuning and a Compose stack are tracked in [#19](https://github.com/Andrewpk/watch-tower/issues/19). The app does not trust `X-Forwarded-*` headers yet, so behind an HTTPS proxy generated URLs may use `http://`.
- Build it yourself with `docker build -t watch-tower .`. The PHP image is pinned by digest in the `Dockerfile`; override it with `--build-arg PHP_IMAGE=serversideup/php:<tag>`.
- SQLite runs in WAL mode with a 5 second busy timeout (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`).
