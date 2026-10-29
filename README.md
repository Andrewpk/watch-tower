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

## Quick start

**[Follow the quick start](doc/quick-start.md)**: run Watch Tower with Docker and see your first request in about 10 minutes.

```bash
mkdir watch-tower && cd watch-tower
echo "APP_KEY=base64:$(openssl rand -base64 32)" > watch-tower.env
echo "APP_URL=http://localhost:8080" >> watch-tower.env
echo "AUTORUN_ENABLED=true" >> watch-tower.env

docker run -d --name watch-tower --restart unless-stopped \
  --platform linux/amd64 -p 8080:8080 --stop-timeout 90 \
  --env-file watch-tower.env \
  -v watch-tower-storage:/var/www/html/storage \
  ghcr.io/andrewpk/watch-tower:latest
```

Then follow the guide to create your login and connect your app.

## Other ways to install

- **[Docker reference](doc/docker.md)**: every setting, reverse proxies, separate worker and scheduler containers.
- **[Manual install](doc/manual-install.md)**: run from source with PHP, Composer and Node. Use this to develop Watch Tower.
- **[Client app integration](doc/install-client.md)**: connect `laravel/nightwatch` in each app you want to monitor.

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

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, the checks CI runs and PR conventions. To report a security vulnerability, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

---

## License

Watch Tower is open-source software licensed under the [MIT license](LICENSE). It is a fork of [AmadulHaque/watch-tower](https://github.com/AmadulHaque/watch-tower).
