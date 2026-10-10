# TruthGuard load-balancing preparation

This repository is prepared for two Laravel web replicas behind Dokploy's
existing Traefik instance. Nothing in this change deploys to the VPS or
changes the live Traefik configuration.

## Current topology

| Service | Role | Scale decision |
| --- | --- | --- |
| `app` | Laravel 13, Apache/PHP, bundled Vite frontend and synchronous image/video CNN subprocess | Scale to 2 only after VPS capacity check |
| `nlp` | Internal FastAPI/PyTorch Filipino transformer service | Keep at 1; the model artifact is about 516 MiB before runtime overhead |
| `worker` | Database-backed queue worker, including queued push delivery | Keep at 1 initially |
| `scheduler` | Laravel scheduled commands | Keep at exactly 1 |
| `db` | Shared MySQL 8.4 database | Keep at 1; never replicate as an app service |
| `adminer` | Internal database utility | Keep at 1 or disable when unused |

The frontend is not a separate server. Vite builds static assets into the
Laravel image, so the `app` service is the public frontend and backend.

## Why the Laravel app can be replicated

- Sessions use the database driver, not container-local files.
- Cache and queues use MySQL, so both web replicas share state and jobs.
- The `storage` named volume is mounted by `app` and `worker`, so uploaded
  media, public avatars, and Playwright screenshots are visible to every
  service on this single VPS.
- The persistent `APP_KEY` is shared by all containers, preserving encrypted
  cookies and session data.
- Scheduled work remains isolated in the singleton `scheduler` service. Both
  scheduled commands use `withoutOverlapping()`.

This storage arrangement is safe for two replicas on the same Docker host.
It is not a multi-node storage design: do not move replicas to Swarm nodes
until media and private automation artifacts are moved to shared object
storage (S3-compatible storage is the intended Laravel path) and backups are
tested.

## AI and concurrency limitation

The Filipino transformer is already isolated in `nlp` and should not be
replicated casually because each copy loads the large PyTorch model. The
image and video CNNs run as Python subprocesses inside each Laravel `app`
container. A second app replica therefore improves request availability but
can also create a second simultaneous TensorFlow/ffmpeg workload.

Video detection remains synchronous and can run for up to 180 seconds, with a
300-second PHP/Apache request ceiling. This change does not move video work to
the queue because doing so would change the current request/response contract
and requires a product-level polling or notification flow. On a small VPS,
keep one `app` replica until concurrent image/video tests demonstrate safe
headroom.

## Repository changes

- `compose.yaml` now exposes port 80 to Dokploy instead of publishing a fixed
  host port. This is required for multiple Compose containers to coexist.
- `compose.local.yaml` is an opt-in local override that restores
  `127.0.0.1:8080` for browser smoke tests.
- Health checks remain enabled for `app`, `nlp`, and `db`; the app check uses
  Laravel's `/up` endpoint.
- Apache prefork is capped at two request workers per `app` replica and
  recycles workers after 500 connections, limiting concurrent synchronous
  CNN/ffmpeg subprocesses to a bounded level.
- The app, worker, scheduler, and database have conservative, adjustable CPU
  and memory limits. The `nlp` service is capped at 1.5 GiB because its
  transformer model is resident in memory. Override these values only after
  observing actual usage; the defaults are not a claim about the VPS's
  available capacity.
- The app has a 330-second stop grace period so a long-running detection is
  not immediately killed during an intentional restart.
- Docker builds now fail early when Git LFS model files are missing instead of
  producing an image with pointer files.

No model files, model thresholds, database migrations, routes, or detection
logic were changed.

## Dokploy settings to apply manually after approval

Use the existing Docker Compose application. Do not convert this project to a
separate frontend/backend deployment and do not add Nginx.

1. In the Compose application's **Domains** tab, keep the existing domain(s),
   select service `app`, and set container port `80`.
2. Keep HTTPS enabled with the existing Let's Encrypt certificate resolver and
   HTTP-to-HTTPS redirect. Do not replace the generated Traefik labels with
   repository labels; Dokploy generates the labels from the Domains tab.
3. Confirm the Preview Compose output routes the domain to `app:80` and that
   the app is connected to the same network as Traefik. The repository keeps
   `dokploy-network` for the current non-isolated setup.
   Preserve the current Isolated Deployments setting; do not toggle it as part
   of scaling. If it is enabled in the existing project, stop and reconcile
   the generated network with this explicit external network before deploying.
4. Keep the Compose deployment mode. The checked-in image uses `build`, which
   is not supported by Docker Stack deployments.
5. Keep `worker=1`, `scheduler=1`, `nlp=1`, and `db=1`. Only scale `app`.
6. Set `TRUSTED_PROXIES` to the actual Dokploy/Traefik network or proxy CIDR
   shown by the VPS configuration. Do not leave the placeholder or use `*`.
   On the VPS, the Docker network subnet can be inspected with
   `docker network inspect dokploy-network` before choosing the value.
7. Set these environment overrides only when the VPS has been measured to
   support them:

   ```text
   APP_CPU_LIMIT=1.0
   APP_MEMORY_LIMIT=1g
   APP_MEMORY_RESERVATION=512m
   WORKER_CPU_LIMIT=1.0
   WORKER_MEMORY_LIMIT=1g
   SCHEDULER_CPU_LIMIT=0.25
   SCHEDULER_MEMORY_LIMIT=256m
   DB_CPU_LIMIT=1.0
   DB_MEMORY_LIMIT=1g
   NLP_CPU_LIMIT=1.0
   NLP_MEMORY_LIMIT=1536m
   ```

   These values are already the Compose defaults; defining them in Dokploy
   makes the capacity decision explicit and auditable.

### Scaling command

For a staging or approved single-VPS deployment, the equivalent CLI operation
is:

```sh
docker compose --env-file .env.production up -d --build --remove-orphans --scale app=2
```

In Dokploy's **Advanced > Command** field, use the same Compose command with
the project and compose path that Dokploy already generated, for example:

```text
compose -p <dokploy-project-name> -f <compose-path> up -d --build --remove-orphans --scale app=2
```

Dokploy prepends `docker` to a custom Compose command. Preserve any existing
required flags from the command shown by the dashboard; a custom command
replaces the default command rather than appending to it. Do not use the
Application/Swarm replica control for this project: this file is a standard
Docker Compose deployment and contains `build`, while Dokploy's Stack mode
does not support `build`.

If you use Dokploy's separate resource fields rather than the Compose
environment overrides, enter raw Docker API values: `1073741824` memory bytes
for 1 GiB, `1610612736` for 1.5 GiB, `1000000000` nanoCPUs for 1 CPU, and
`250000000` nanoCPUs for 0.25 CPU. Dokploy does not parse `1g` or `1.0` in
those fields.

### Timeouts and health

The repository already sets PHP and Apache request ceilings to 300 seconds,
and the worker timeout/retry values are aligned to avoid early duplicate job
claims. The Dokploy domain should use the existing Traefik HTTPS router and
the app's Docker health check. If this Dokploy installation has a per-router
Traefik `serversTransport` option, set:

```text
dialTimeout=10s
responseHeaderTimeout=300s
idleConnTimeout=310s
```

Do this per TruthGuard router/service only. Do not overwrite the global
Traefik configuration or other applications. If the dashboard does not offer
per-router transport settings, leave Traefik's response timeout unlimited and
retain the repository's 300-second application ceiling; do not introduce a
second proxy.

## Pre-deployment verification

Run these checks in staging or during an approved maintenance window. They
are intentionally not run against the live VPS by this repository change.

```sh
git lfs pull
docker compose --env-file .env.production config --quiet
docker compose --env-file .env.production up -d --build --remove-orphans
docker compose --env-file .env.production ps
docker compose --env-file .env.production up -d --scale app=2 app
docker compose --env-file .env.production ps app
curl -fsS https://<existing-domain>/up
docker compose --env-file .env.production exec app php artisan queue:failed
docker compose --env-file .env.production exec app php artisan truthguard:models:health --load --json
```

Before building, confirm the model files are large binary files rather than
Git LFS pointer text. Dokploy must clone/fetch LFS content for both the app
and `nlp` images.

For distribution, send repeated requests through the existing HTTPS domain
and inspect Traefik access logs plus the two container IDs. Do not add an
application response header solely for testing. Validate, in order:

1. `/up`, login, session keep-alive, logout, and CSRF-protected POST.
2. One image upload and one video upload, including the result page and
   stored media.
3. One long-form text detection through the `nlp` service.
4. One queued push/notification flow and the scheduler logs.
5. Stop one `app` container and confirm Traefik continues serving through the
   remaining healthy replica; then restore it and confirm it re-enters health.

Record CPU, memory, disk, request latency, failed jobs, MySQL health, and
model health. A passing `/up` alone is not sufficient acceptance evidence.

## Rollback

1. In Dokploy, redeploy the previous known-good commit/image and keep the
   existing database and `storage` volumes.
2. Temporarily run one web replica:

   ```sh
   docker compose --env-file .env.production up -d --scale app=1 app
   ```

3. If the repository change itself must be reverted, restore the prior
   `compose.yaml` and keep `compose.local.yaml` unused; do not run
   `docker compose down -v`.
4. Verify `/up`, login, media retrieval, queue health, and AI model health.

No database rollback is part of this procedure. Take and verify database and
storage backups before the first production redeployment.

## Capacity gate

With the checked-in defaults, two `app` replicas reserve about 3.1 GiB across
app, worker, scheduler, database, and NLP services before Dokploy/Traefik,
MySQL overhead, Apache processes, and transient AI memory. The hard container
limits total about 5.75 GiB with two app replicas. This is
why the safe deployment floor is:

- 8 GiB RAM and 4 vCPU for a controlled two-replica rollout.
- 12-16 GiB RAM and 6-8 vCPU preferred for concurrent image/video analysis,
  Playwright, MySQL, and Dokploy on the same VPS.
- 4 GiB RAM: retain one `app` replica and avoid concurrent video inference.

These are capacity recommendations, not a substitute for measurement. Before
scaling, run a representative load test while watching `docker stats`, swap,
disk I/O, MySQL connections, queue depth, and request latency. Any OOM kill,
swap growth, sustained CPU saturation, or video requests exceeding the 300s
ceiling means the two-replica configuration is not ready.
