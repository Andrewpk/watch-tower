# Quick start

Run Watch Tower with Docker and see your first request in about 10 minutes.

**You need:**

- Docker
- A Laravel 10, 11, 12 or 13 app to monitor

No Docker? Use the [manual install](manual-install.md) instead.

---

## 1. Start Watch Tower (about 3 minutes)

1. Create a folder and a settings file. The file holds your app key, so keep it: you need the same key every time you start the container.

   ```bash
   mkdir watch-tower && cd watch-tower
   echo "APP_KEY=base64:$(openssl rand -base64 32)" > watch-tower.env
   echo "APP_URL=http://localhost:8080" >> watch-tower.env
   echo "AUTORUN_ENABLED=true" >> watch-tower.env
   ```

2. Start the container. The first pull downloads about 1 GB.

   ```bash
   docker run -d --name watch-tower --restart unless-stopped \
     --platform linux/amd64 -p 8080:8080 --stop-timeout 90 \
     --env-file watch-tower.env \
     -v watch-tower-storage:/var/www/html/storage \
     ghcr.io/andrewpk/watch-tower:latest
   ```

3. Check that it is up:

   ```bash
   curl http://localhost:8080/up
   ```

   **You should see:** an HTML page that contains `Application up`.

One container runs the web server, the queue worker and the scheduler. Your data lives in the `watch-tower-storage` volume.

---

## 2. Create your login (about 2 minutes)

1. Create the first user:

   ```bash
   docker exec watch-tower php artisan db:seed --class=SuperAdminSeeder --force
   ```

2. Open **http://localhost:8080** and log in as `super@admin.test` with password `12345678`.
3. You land on **Settings > Profile**. Change the email and the password now. The seeded password is public.

---

## 3. Create a project (about 1 minute)

1. Open **Settings > Projects** and click **New project**.
2. Enter a name. Keep the other defaults. Click **Create project**.
3. Copy the **API key**. It is shown only once.

Lost the key? Click **Rotate API key** on the project to get a new one.

---

## 4. Connect your Laravel app (about 4 minutes)

Run these in **your app's** folder, not in Watch Tower.

1. Install Nightwatch:

   ```bash
   composer require laravel/nightwatch
   ```

2. Add the API key from step 3 to your app's `.env`:

   ```env
   NIGHTWATCH_TOKEN=paste-the-api-key-here
   ```

3. Point Nightwatch at Watch Tower. In `app/Providers/AppServiceProvider.php`, add this line to `boot()`:

   ```php
   public function boot(): void
   {
       $_SERVER['NIGHTWATCH_BASE_URL'] = 'http://localhost:8080';
   }
   ```

4. Start the agent and leave it running:

   ```bash
   php artisan nightwatch:agent
   ```

5. Open any page of your app in the browser.

**You should see:** within about 10 seconds, the agent prints `Ingest successful`.

Your app runs in Docker too (for example Laravel Sail)? Use `http://host.docker.internal:8080` instead of `http://localhost:8080` in step 3.

---

## 5. See your data (under 1 minute)

1. Open **http://localhost:8080**.
2. Click **Requests** in the sidebar.

**You should see:** the page you just opened in your app.

Done. Watch Tower is running and your app is connected.

---

## Something not working?

| What you see | What to do |
| --- | --- |
| `curl` cannot connect | Run `docker logs watch-tower` and read the last lines. |
| Agent prints `Authentication failed` | The key or the URL is wrong. Check `NIGHTWATCH_TOKEN` and the URL in `AppServiceProvider`. Rotate the key if you lost it. |
| Agent runs but never prints `Ingest successful` | Open a page in your app. Check that `NIGHTWATCH_ENABLED` is not `false` in your app's `.env`. |
| Banner about queued jobs or retention | Run `docker logs watch-tower`. The worker or the scheduler is failing. |

More settings and fixes: [Docker reference](docker.md).

---

## Before you use it in production

1. **Pin a version.** Replace `latest` with a release tag such as `1.2.3`. `latest` can be a pre-release.
2. **Use HTTPS.** Put a reverse proxy in front, set `APP_URL` to the public `https://` URL, and set `TRUSTED_PROXIES`. See [Behind a reverse proxy](docker.md#behind-a-reverse-proxy).
3. **Keep the agent running** in your app with a process manager such as Supervisor. See the [Nightwatch docs](https://nightwatch.laravel.com/docs/start-guide).
4. **Back up** `watch-tower.env` and the `watch-tower-storage` volume. The volume holds all your data. A new `APP_KEY` logs everyone out.

---

## Upgrade or remove

**Upgrade.** Your data stays in the volume.

```bash
docker pull ghcr.io/andrewpk/watch-tower:latest
docker rm -f watch-tower
```

Then run the `docker run` command from step 1 again.

**Remove everything.** This deletes all your data.

```bash
docker rm -f watch-tower
docker volume rm watch-tower-storage
```
