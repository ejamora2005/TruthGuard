# Production readiness review — 2026-09-10

Deployment scaffolding and initial hardening are implemented. **Release approval is pending the items below.** Nothing has been deployed and the local application environment/database were not migrated.

## Verified

- Frontend production build passed (Vite reports an existing large ApexCharts chunk).
- Composer dependency updates retain the declared PHP 8.3 compatibility floor. Composer validation passed; Composer and npm audits reported zero known advisories after updating the lock files.
- Compose configuration validated using placeholder validation credentials, without starting services.
- Laravel config, route and Blade caches compiled successfully using isolated temporary cache paths.
- New production checks passed: `/up` responds successfully and the demo-account seeder refuses production execution.
- Full test suite ran using PHP 8.4.15 with PDO SQLite: **109 tests, 79 passed, 22 failures, 8 errors, 440 assertions**. Detailed JUnit results are in local `storage/logs/production-tests.xml` (not committed or included in Docker).

## Release blockers

1. **Container execution is unverified.** Docker Desktop's backend reports `hasNoVirtualization: true` and its Linux engine stays stopped. Enable hardware virtualization and the required Docker/WSL platform on this host, or build and test on a Linux host. Follow `DEPLOYMENT.md` and verify migrations, volume permissions, Playwright browser launch, health checks, worker and scheduler operation.
2. **The full suite is not green.** Authentication tests reference unavailable Volt components (`pages.auth.login`, `pages.auth.register`, `layout.navigation`, password confirmation) and missing email-verification routes. Other failures concern detection redirects/UI expectations, Google account linking, notification baselines and onboarding/history/dashboard content. Reconcile those failures with the intended current behavior and rerun the suite before release. They have not all been classified as stale tests versus application defects or dependency regressions.
3. **Production settings and infrastructure are still required.** Supply the actual HTTPS domain, trusted reverse-proxy addresses, application key, database passwords, SMTP and provider credentials. Configure backups and external monitoring, and perform the staging acceptance checks in `DEPLOYMENT.md`.

## Hardening included

- Secrets, local databases, uploads, development dependencies and browser login state are excluded from the image build context.
- Apache serves only `public/`, disables directory listings, adds response headers and blocks PHP execution in public uploads.
- Production startup requires an application key and rejects debug mode. Secure encrypted sessions and stderr logging are set in the production example.
- Explicit proxy trust configuration supports HTTPS termination. Playwright logging now works with Laravel's cached configuration.
- Scheduler commands prevent overlapping runs; queue retry timing exceeds worker timeout. Container-local compiled views avoid multiple services clearing the same shared view cache.
- Production seeding refuses known demo credentials. Existing imported accounts still require a separate audit.

This review covers deployment preparation and the checks listed above; it is not a complete application security audit.
