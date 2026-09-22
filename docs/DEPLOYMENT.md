# TruthGuard production deployment

The Docker image contains PHP 8.4, Apache, compiled Vite assets, Node 24 and Playwright Chromium. Compose runs the web app, a database queue worker, one scheduler, and MySQL 8.4. Application uploads and MySQL data use separate named volumes. No host source code or development secrets are mounted into the image.

## Configure

Use Docker Engine with Compose v2 on a Linux server (Docker Desktop in Linux-container mode for local verification).

1. Copy `.env.production.example` to `.env.production`. Keep this file private and out of Git. This configuration is separate from the local `.env`.
2. Set `APP_URL` to the HTTPS origin, separate strong `DB_PASSWORD` and `DB_ROOT_PASSWORD`, and your SMTP settings. Generate `APP_KEY` once using the command below. Preserve the same key across deployments and backups.
3. Configure Google/Facebook OAuth credentials and register the exact HTTPS callback URLs. Add the API keys for the verification providers you use; see `.env.example` for the complete list of optional integrations.
4. Set `TRUSTED_PROXIES` to the addresses/CIDRs of your reverse proxy. The proxy must replace incoming forwarded headers and set `X-Forwarded-Proto: https`. Do not use `*`. Restrict the backend to this proxy. Compose binds HTTP to loopback on port 8080 by default.
5. Set a strong `PLAYWRIGHT_AUTOMATION_TOKEN` if external automation is used. Browser scraping requires outbound internet access and sufficient memory. Restrict outbound access to internal/private networks at the infrastructure layer when accepting untrusted URLs.

Generate a key without loading application credentials:

```sh
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

On PowerShell, you can instead run your PHP 8.4 executable with `artisan key:generate --show`; copy its output into `.env.production` without changing the local key.

## First deployment

Every Compose command must include `--env-file .env.production` so MySQL and Laravel use the same credentials.

If a deployment reuses an existing MySQL volume, changing `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, or `DB_ROOT_PASSWORD` in Dokploy does not update the already-initialized database. Keep those values stable, restore from backup with matching credentials, or intentionally recreate the database volume only when you are prepared to lose that stored data.

```sh
docker compose --env-file .env.production config --quiet
docker compose --env-file .env.production build --pull
docker compose --env-file .env.production up -d db
docker compose --env-file .env.production run --rm --user www-data app php artisan migrate --force
docker compose --env-file .env.production up -d
docker compose --env-file .env.production ps
docker compose --env-file .env.production logs --tail=100 app worker scheduler
```

Put a TLS reverse proxy in front of `127.0.0.1:8080`, redirect HTTP to HTTPS, and verify `/up`, registration, login, password reset email, OAuth, a fact check, media upload, and the admin dashboard. Align proxy upload limits to 25 MB and upstream request timeouts to 300 seconds because verification currently runs synchronously. HTTPS is required for the production secure session cookie; for an isolated local HTTP smoke test only, use `SESSION_SECURE_COOKIE=false` and an HTTP `APP_URL` in a separate environment file.

Do not run the default database seeder in production: it contains demo accounts and is deliberately blocked. Register your real account, then promote that specific verified account using `php artisan tinker` in the app container and its `is_admin` field. If migrating existing data, audit/remove demo accounts (`admin@truthguard.local` and `test@example.com`) and reset any reused passwords before exposure. A fresh Docker database does not import your Laragon database; plan and verify a separate data migration if you need its contents.

## Updates and operations

Back up the database and the storage volume before updating. Keep encrypted off-host backups of `.env.production`, especially `APP_KEY`, and test restoring all three. Never use `docker compose down -v` on a production installation: it deletes persistent volumes.

For a single-server deployment with downtime:

```sh
docker compose --env-file .env.production build --pull
docker compose --env-file .env.production exec --user www-data app php artisan down --retry=60
docker compose --env-file .env.production stop worker scheduler
docker compose --env-file .env.production run --rm --user www-data app php artisan migrate --force
docker compose --env-file .env.production up -d --force-recreate app worker scheduler
docker compose --env-file .env.production exec --user www-data app php artisan up
```

Retain the previous image with a release-specific `IMAGE_TAG` and a tested backup for rollback. Database migrations may not be reversible; do not blindly run rollback against production data. Restarting containers loads new code and rebuilds config, route and view caches. Startup never runs migrations or seeds automatically. Run only one scheduler. Worker timeout is 300 seconds and database retry delay is 360 seconds to avoid retrying a job while it is still running.

Monitor `/up` for application boot health, plus database connectivity, disk space, failed jobs (`php artisan queue:failed`), email delivery and worker/scheduler operation separately. `/up` alone is not a dependency or functional readiness check. Container logs rotate; retain operational logs externally as needed. Playwright's dedicated file logs reside in the storage volume and also need retention monitoring.

The optional Python deepfake model is disabled in this image. Enabling it requires a separate image extension with Python, the model's dependencies and a mounted model file. Review provider budgets and mail delivery before enabling scheduled public-review notifications, which run every thirty minutes.

## Validation

Use PHP 8.3 or newer with PDO SQLite for tests, and Node 24:

```sh
composer install
php artisan test
npm ci
npm run build
composer audit --locked
npm audit
```

Docker is deployment preparation, not a substitute for a staging acceptance test or a full security audit. Reference: [Laravel deployment](https://laravel.com/framework/docs/master/deployment) and [queue worker timeouts](https://github.com/laravel/docs/blob/13.x/queues.md).
