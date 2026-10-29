# Manual install

This guide runs **Watch Tower** from source — a Laravel 13 + Inertia v3 + React 19 application. Use it to develop Watch Tower or to run it without Docker.

Just want to try it? The [quick start](quick-start.md) runs the Docker image in about 10 minutes.

---

## 1. Requirements

Make sure the following are installed on your machine before starting.

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | **8.3+** (8.4 recommended) | with `mbstring`, `xml`, `bcmath`, `curl`, `pdo_sqlite`, `pdo_mysql`, `zip`, `intl` |
| Composer | **2.6+** | https://getcomposer.org |
| Node.js | **20.x or 22.x LTS** | https://nodejs.org |
| npm | **10+** | bundled with Node |
| Git | latest | |
| SQLite | 3.35+ | default database driver |
| (optional) MySQL | 8.0+ | only if you switch `DB_CONNECTION=mysql` |
| (optional) Redis | 7+ | only if you switch cache/queue/session to redis |

Verify your environment:

```bash
php -v
composer -V
node -v
npm -v
```

---

## 2. Clone the Repository

```bash
git clone https://github.com/Andrewpk/watch-tower.git watch-tower
cd watch-tower
```

---

## 3. Quick Setup (one command)

The composer script does the heavy lifting — install deps, create `.env`, generate the app key, migrate, and build assets.

```bash
composer run setup
```

That is equivalent to running every step in section 4 manually.

---

## 4. Manual Setup (step by step)

If you prefer to run each step yourself, or `composer run setup` fails on your machine.

### 4.1 Install PHP Dependencies

```bash
composer install
```

### 4.2 Install Frontend Dependencies

```bash
npm install
```

### 4.3 Create the Environment File

```bash
cp .env.example .env
```

Then open `.env` and adjust at minimum:

```env
APP_NAME="Watch Tower"
APP_URL=http://localhost:8000
```

### 4.4 Generate the Application Key

```bash
php artisan key:generate
```

### 4.5 Prepare the Database

The project defaults to **SQLite**. Create the database file:

```bash
touch database/database.sqlite
```

Then run migrations:

```bash
php artisan migrate
```

> Want MySQL instead? Set the following in `.env`, create the database manually, then run `php artisan migrate`:
>
> ```env
> DB_CONNECTION=mysql
> DB_HOST=127.0.0.1
> DB_PORT=3306
> DB_DATABASE=watch_tower
> DB_USERNAME=root
> DB_PASSWORD=
> ```

### 4.6 (Optional) Seed Demo Data

```bash
php artisan db:seed
```

### 4.7 Storage Symlink

For files uploaded via the `public` disk:

```bash
php artisan storage:link
```

### 4.8 Build (or run) Frontend Assets

For production-style assets:

```bash
npm run build
```

For local development with HMR, use the dev server in the next section instead.

---

## 5. Running the Application

### 5.1 All-in-one Dev Server (recommended)

The `composer run dev` script boots **four** processes concurrently using `concurrently`:

| Process | Command | Purpose |
| --- | --- | --- |
| `server` | `php artisan serve` | HTTP server on `http://localhost:8000` |
| `queue` | `php artisan queue:listen` | Database queue worker |
| `logs` | `php artisan pail` | Real-time log streaming |
| `vite` | `npm run dev` | Vite dev server + HMR for React |

Run it:

```bash
composer run dev
```

Then open: **http://localhost:8000**

### 5.2 Running Processes Individually

In separate terminals:

```bash
# Terminal 1 – Laravel
php artisan serve

# Terminal 2 – Vite (Inertia React HMR)
npm run dev

# Terminal 3 – Queue worker
php artisan queue:listen --tries=1 --timeout=0

# Terminal 4 – Log tail (Laravel Pail)
php artisan pail
```

A queue worker must always run in production too. Ingested events are processed by the queue, so without a worker nothing is stored.

### 5.3 Scheduler (required for retention)

Each project's `retention_days` is only enforced while the scheduler runs. Run `php artisan schedule:work`, or add a cron entry that calls `php artisan schedule:run` every minute. Without it nothing is pruned and telemetry grows forever.

The scheduler runs `watch:prune` daily, plus `telescope:prune` and `queue:prune-failed`. Try `php artisan watch:prune --dry-run` to see what would be deleted.

The [Docker image](docker.md) runs the scheduler and a queue worker by default. Outside Docker in production, run `php artisan schedule:run` from cron every minute and keep `php artisan queue:work` running under a process manager such as supervisord or systemd.

Super admins see a warning at the top of every page when `watch:prune` has not finished for more than 25 hours, or when queued jobs sit unclaimed for more than 10 minutes. Both thresholds can be changed; see [Retention and maintenance warnings](docker.md#retention-and-maintenance-warnings).

---

## 6. Useful Project Scripts

### Composer Scripts

| Command | Description |
| --- | --- |
| `composer run setup` | Full install + migrate + build |
| `composer run dev` | Boot the four-process dev environment |
| `composer run test` | Clear config, lint check, run Pest tests |
| `composer run lint` | Auto-fix PHP style with Laravel Pint |
| `composer run lint:check` | Pint dry-run (CI mode) |
| `composer run ci:check` | Full CI suite: lint + format + types + tests |

### npm Scripts

| Command | Description |
| --- | --- |
| `npm run dev` | Vite dev server (HMR) |
| `npm run build` | Production build |
| `npm run build:ssr` | Build client + SSR bundle |
| `npm run lint` | ESLint auto-fix |
| `npm run lint:check` | ESLint dry-run |
| `npm run format` | Prettier write |
| `npm run format:check` | Prettier dry-run |
| `npm run types:check` | `tsc --noEmit` type check |

---

## 7. Testing

This project uses **Pest 4**.

```bash
# Run the whole suite
php artisan test --compact

# Filter by name
php artisan test --compact --filter=ExampleTest

# Run a single file
php artisan test --compact tests/Feature/ExampleTest.php
```

Create new tests with:

```bash
php artisan make:test --pest SomeFeatureTest
php artisan make:test --pest --unit SomeUnitTest
```

---

## 8. Code Quality

Before committing PHP changes, run:

```bash
vendor/bin/pint --dirty --format agent
```

Before committing JS / TS / React changes:

```bash
npm run lint
npm run format
npm run types:check
```

---

## 9. Developer Tooling

This project includes several Laravel dev packages — all already wired up:

| Package | What it provides | URL |
| --- | --- | --- |
| **Laravel Telescope** | Request, query, job, log inspector | `/telescope` |
| **Laravel Debugbar** | In-browser debug panel | bottom of every page |
| **Laravel Pail** | Live tailing of Laravel logs | `php artisan pail` |
| **Laravel Boost** | MCP server for AI coding tools | configured in `boost.json` |
| **Wayfinder** | Typed routes generated for React | `@/actions/`, `@/routes/` |

> Telescope and Debugbar are intended for `APP_ENV=local`. Disable or restrict before deploying.

---

## 10. Configuring Optional Services

### Queue Driver

Default is `database`. To switch to Redis:

```env
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Cache / Session

Default is `database`. To use Redis or file:

```env
CACHE_STORE=redis
SESSION_DRIVER=redis
```

### Mail

Default is `log` — emails are written to `storage/logs/laravel.log`. To use SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="hello@watchtower.test"
```

---

## 11. Common Issues

### "Vite manifest not found"
Run `npm run build` (production) or `npm run dev` (local) — Laravel cannot find the compiled assets.

### "SQLSTATE[HY000] [2002]" or DB connection refused
Either the SQLite file is missing (run `touch database/database.sqlite`) or your MySQL service is not running.

### "Class not found" after pulling new code
Refresh autoloaders and caches:

```bash
composer dump-autoload
php artisan optimize:clear
```

### Permissions on `storage/` and `bootstrap/cache/`
On Linux/macOS, ensure your user can write to these:

```bash
chmod -R ug+rwX storage bootstrap/cache
```

### Port 8000 already in use

```bash
php artisan serve --port=8001
```

---

## 12. Project Layout (quick reference)

```
app/                  PHP application code (Http, Models, Jobs, Watch, ...)
bootstrap/            Framework bootstrap & cache
config/               Configuration files
database/             Migrations, seeders, factories, sqlite file
public/               Web root, compiled assets land here
resources/
  js/
    pages/            Inertia React pages
    components/       Shared React components
  css/                Tailwind v4 entry
routes/
  web.php             Inertia / web routes
  api.php             API routes
  console.php         Artisan / scheduled commands
tests/                Pest tests (Feature, Unit, Browser)
vite.config.ts        Vite + Wayfinder + Tailwind config
```

---

## 13. You're Ready

After setup, browse to:

- App: **http://localhost:8000**
- Telescope: **http://localhost:8000/telescope**

Happy hacking.
