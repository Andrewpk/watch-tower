# Watch Tower

A self-hosted observability and monitoring dashboard for Laravel applications, built on **Laravel 13**, **Inertia v3**, **React 19**, and **Tailwind v4**. Watch Tower receives telemetry from your apps via [Laravel Nightwatch](https://nightwatch.laravel.com) and gives you a single place to investigate errors, traces, queues, schedules, mail, logs, and more.

---

## What it does

Watch Tower ingests events from one or more client Laravel applications and groups, fingerprints, and visualizes them. The dashboard surfaces:

- **Error tracking** — grouped error occurrences, fingerprinting, comments, and resolution workflows
- **Performance traces** — request traces with database query breakdowns
- **Outgoing HTTP requests** — every external request your app makes
- **Queue jobs** — successful, failed, and retried job runs
- **Scheduled tasks** — historic runs of `php artisan schedule:run`
- **Cache events** — hits, misses, writes, and forgets
- **Mail sends & notifications** — every email or notification dispatched
- **Logs** — application log entries, searchable in-app
- **Metrics** — custom numeric metrics over time
- **Multi-tenant** — organizations and projects, so multiple apps and teams share one Watch Tower instance

---

## Tech stack

| Layer | Tech |
| --- | --- |
| Backend | PHP 8.4, Laravel 13 |
| Frontend | Inertia v3, React 19, TypeScript |
| Styling | Tailwind CSS v4 |
| Tooling | Vite, Wayfinder (typed routes), ESLint, Prettier, Pint |
| Testing | Pest 4 |
| Dev observability | Telescope, Debugbar, Pail, Laravel Boost (MCP) |

---

## Installation

There are two pieces to install — the Watch Tower server, and the Nightwatch client inside each app you want to monitor.

- **[Install Watch Tower (server)](doc/install.md)** — full setup guide: requirements, environment, database, dev server, scripts, testing, and troubleshooting.
- **[Install the client app integration](doc/install-client.md)** — wire up `laravel/nightwatch` in your client Laravel app and point it at your Watch Tower instance.
- **[Run with Docker](doc/docker.md)** — pull the release image from GHCR and run the web server, queue worker, and scheduler.

### Quick start

```bash
git clone <your-repo-url> watch-tower
cd watch-tower
composer run setup
composer run dev
```

Then open **http://localhost:8000**.

For anything that fails or needs explanation, see [doc/install.md](doc/install.md).

---

## Useful commands

| Command | Purpose |
| --- | --- |
| `composer run dev` | Boots server, queue, log tail, and Vite together |
| `composer run setup` | Full install + migrate + build |
| `composer run test` | Lint check + Pest tests |
| `composer run ci:check` | Lint + format + types + tests |
| `php artisan test --compact` | Run the Pest suite |
| `vendor/bin/pint --dirty --format agent` | Format changed PHP files |

---

## Image:
<img width="2928" height="1730" alt="dashboard" src="https://github.com/user-attachments/assets/3afe51c6-9bd9-49f8-a111-7928bb57b77e" />
<img width="2928" height="1730" alt="Requests" src="https://github.com/user-attachments/assets/3d58d925-3400-4fce-b7b8-718747464c02" />
<img width="2928" height="1730" alt="issue" src="https://github.com/user-attachments/assets/917c138c-156a-4fde-84f2-0d959d497c75" />

---

## License

MIT.
