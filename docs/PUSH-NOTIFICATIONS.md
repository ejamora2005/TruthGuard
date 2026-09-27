# TruthGuard push notifications

## Architecture and scope

Laravel 13 / PHP 8.3+, Blade + Livewire, Vite, and the React admin UI are retained. Authentication uses existing Laravel sessions, CSRF middleware and account/privacy checks. Existing `notifications` records, the bell, polling, unread/read, mark-all-read and archive controls are retained. The new events create one database notification per user/event; queued push delivery references that same record.

`public/sw.js` remains the only root service worker. Its offline page, navigation policy, static caching and install/update behavior are preserved. Firebase's frontend SDK obtains tokens using that registration. The backend sends **data-only FCM HTTP v1 messages**; the existing worker's native `push` handler displays one system notification whether the page is foregrounded, backgrounded or closed. Do not add a second Firebase messaging worker or send notification-payload messages expecting this custom handler to process them.

Analyses currently execute synchronously and persist completed `Detection` models after text/image/video analysis. An after-commit observer detects completed creations and transitions, including reused analyses, without modifying algorithms. Failed/processing records do not generate completion alerts. Changed, nonempty verification sources on completed detections trigger a deduplicated fact-check update. New feed announcements use the existing scheduled announcement service; baseline seeding remains silent. Email consent and delivery remain separate from push preferences. `NotificationEventService::system($user, $stableEventKey, $genericMessage, $internalUrl)` supports account/system events; do not put sensitive details in its message.

Push token ciphertext is stored using Laravel encryption and a unique SHA-256 lookup hash. Multiple devices are supported. A signed-in user cannot claim another account's registered token or delete its subscription. An encrypted HttpOnly browser cookie identifies the previous token for rotation and logout cleanup. Explicit logout removes that browser's subscription. Device disable persists in an HttpOnly cookie and browser storage; master push and category preferences apply across the account. Categories also govern new in-app events, while the master push switch only governs delivery. Welcome/security email behavior is retained separately.

## Files created

- `database/migrations/2026_09_27_000000_add_push_notifications.php`
- `config/firebase.php`
- `app/Models/PushSubscription.php`, `app/Models/NotificationPreference.php`
- `app/Http/Controllers/PushNotificationController.php`
- `app/Observers/DetectionNotificationObserver.php`
- `app/Services/Notifications/NotificationEventService.php`
- `app/Services/Notifications/NotificationUrl.php`
- `app/Services/Notifications/TruthGuardPushNotificationService.php`
- `app/Jobs/SendPushNotification.php`, `app/Jobs/AnnounceFactCheck.php`
- `resources/js/push-notifications.js`
- `resources/views/notifications/settings.blade.php`
- `tests/Feature/PushNotificationsTest.php`
- `tests/push-service-worker.test.mjs`, `tests/push-permissions.test.mjs`
- This document.

## Files modified

- `.env.example`, `compose.yaml`: runtime Firebase configuration shared by app, worker and scheduler.
- `package.json`, `package-lock.json`: Firebase browser SDK.
- `app/Providers/AppServiceProvider.php`: observer and logout cleanup.
- `app/Services/Notifications/TruthGuardNotificationManager.php`: enable completion notifications.
- `app/Services/Notifications/PublicClaimReviewNotificationService.php`: queue new fact-check announcements.
- `routes/web.php`: authenticated push routes.
- `public/sw.js`: native push and safe notification clicks; private push routes excluded from caching.
- `resources/js/app.js`, `resources/js/admin.jsx`: initialize push client.
- `resources/js/admin/pages/ProfilePage.jsx`: account notification-settings link.
- `resources/views/layouts/partials/pwa.blade.php`: authenticated client initialization marker.
- `resources/views/layouts/partials/notification-center.blade.php`: refresh bell when a push arrives.
- `resources/views/profile.blade.php`, `resources/views/notifications/index.blade.php`: settings panel/link.
- `tests/Feature/NotificationsTest.php`: new completion expectation, explicit email consent/baseline fixtures, current page title.

Pre-existing edits to analysis models/pipeline, `config/services.php`, Python automation and analysis tests were preserved. Existing changes in `.env.example` were retained.

## Migration

The new migration creates `push_subscriptions` and `notification_preferences`, both with cascading user foreign keys. It adds nullable unique `notifications.event_key` for atomic event deduplication. No existing notification rows are removed. Archive keeps event keys, preventing replay of dismissed events. Run the migration before serving the new application code or starting updated workers.

## Routes

All routes use the existing web/session authentication, CSRF and privacy middleware; mutation endpoints do not accept a recipient user ID.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/push/settings` | Public Firebase client values plus own preferences; no-store |
| POST | `/push/subscriptions` | Register/refresh own device; 30 requests/minute |
| DELETE | `/push/subscriptions` | Remove own token/current device and persist device opt-out |
| PATCH | `/push/preferences` | Update own validated boolean settings |
| POST | `/push/test` | Queue an own-account system test; local/testing or admin only; 3/minute |

Existing `/notifications`, peek, read, mark-all-read and delete/archive routes are unchanged. Analysis clicks use the existing protected `/detections/{id}/result` route, with existing ownership enforcement. New fact-check clicks use `/dashboard/fact-checks/{feed_item_id}`. An allowlist rejects external URLs, redirect query strings and unrelated app routes. The worker also validates URLs and focuses/navigates an existing same-origin window where possible.

## Firebase Console setup and environment values

1. Create/select a Firebase project and register a **Web app** in Project settings > General. Copy its config values below. Firebase Authentication is not required; TruthGuard retains Laravel authentication.
2. In Project settings > Cloud Messaging, enable the **Firebase Cloud Messaging API (V1)**. Ensure the **FCM Registration API** is enabled in the same Google Cloud project.
3. In Cloud Messaging > Web configuration > Web Push certificates, generate/import a key pair. Copy the **public** VAPID key to `FIREBASE_VAPID_KEY`.
4. Create/download a server service-account JSON key from Project settings > Service accounts. Use the same project and a service account authorized to send FCM messages. Store it as a Dokploy secret, never in `public/`, a frontend `VITE_*` variable, or Git.
5. Restrict the browser API key appropriately for the deployed web origins/APIs. Test using `https://truthguard.online`; local web push requires HTTPS or localhost. No Firebase login provider or replacement auth flow is needed.

| Variable | Source/value |
| --- | --- |
| `FIREBASE_ENABLED` | `true` after configuration; defaults to `false` |
| `FIREBASE_API_KEY` | Web app `apiKey` |
| `FIREBASE_AUTH_DOMAIN` | Web app `authDomain` |
| `FIREBASE_PROJECT_ID` | Web app `projectId` |
| `FIREBASE_STORAGE_BUCKET` | Web app `storageBucket` |
| `FIREBASE_MESSAGING_SENDER_ID` | Web app `messagingSenderId` |
| `FIREBASE_APP_ID` | Web app `appId` |
| `FIREBASE_VAPID_KEY` | Public Web Push certificate key |
| `FIREBASE_CREDENTIALS_BASE64` | Base64 encoding of complete service-account JSON, stored server-side |
| `FIREBASE_CREDENTIALS_PATH` | Alternative absolute server path to private JSON; mount/read it in app, worker and scheduler if used |

Use one credential option. Base64 is encoding, not encryption: treat it as a private credential. The API key/web app config and public VAPID key are intentionally visible to browsers; the service-account key is never included in `/push/settings`. OAuth access tokens are cached server-side. Credentials and registration tokens are excluded from delivery exception messages and queued payloads.

References: [Firebase web setup](https://firebase.google.com/docs/cloud-messaging/web/get-started), [receiving messages with an existing worker](https://firebase.google.com/docs/cloud-messaging/web/receive-messages), [HTTP v1 authorization](https://firebase.google.com/docs/cloud-messaging/send/v1-api), [token cleanup](https://firebase.google.com/docs/cloud-messaging/manage-tokens).

## Local commands

Use PHP 8.3+ (this machine's default `php` is 8.1, so select its installed PHP 8.4 binary). Configure `.env` with Firebase values; do not regenerate an existing `APP_KEY`.

```sh
npm ci
php artisan migrate
php artisan config:clear
npm run build
php artisan queue:work --tries=4 --timeout=60
# In another terminal, if not using Laragon:
php artisan serve
```

Use `QUEUE_CONNECTION=database` for asynchronous delivery. The existing `sync` connection remains supported for tests but sends during the request. Production already uses the database queue. New fact checks continue to use the existing scheduler/feed announcement command.

Tests:

```sh
php artisan test --filter='PushNotificationsTest|NotificationsTest|ProductionConfigurationTest|ProfileTest'
node --test tests/push-service-worker.test.mjs tests/push-permissions.test.mjs
npm run build
```

The browser tests use mocked Firebase responses and real headless Chromium, not live Firebase. If Chromium is absent, run `npx playwright install chromium`.

On this Windows machine, the successful PHP test invocation enables SQLite for the process and points OpenSSL at the installed config:

```powershell
$env:OPENSSL_CONF='C:\laragon\bin\php\php-8.4.15-nts-Win32-vs17-x64\extras\ssl\openssl.cnf'
& 'C:\laragon\bin\php\php-8.4.15-nts-Win32-vs17-x64\php.exe' -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter='PushNotificationsTest|NotificationsTest|ProductionConfigurationTest|ProfileTest'
# Use npm.cmd on machines where PowerShell execution policy blocks npm.ps1.
```

## Dokploy / production

Set the Firebase values in Dokploy, retain `APP_URL=https://truthguard.online`, HTTPS, the existing stable `APP_KEY`, and `QUEUE_CONNECTION=database`. Compose now forwards these values to all application services. No Dockerfile, queue architecture or additional daemon is required. Rebuild/redeploy the app image so it includes the locked Firebase dependency and Vite bundle.

During a controlled deployment, before exposing updated application code:

```sh
docker compose build app worker scheduler
docker compose run --rm app php artisan migrate --force
```

Then redeploy/recreate app, worker and scheduler with the updated image/environment. If invoking Compose directly:

```sh
docker compose up -d --build app worker scheduler
```

If configuration was cached outside the normal image lifecycle, refresh it in each running application service and restart the queue worker:

```sh
docker compose exec app php artisan config:cache
docker compose exec worker php artisan config:cache
docker compose exec scheduler php artisan config:cache
docker compose exec app php artisan queue:restart
docker compose logs --tail=100 worker
```

Build the new image first when using `compose run` to migrate; in Dokploy use the equivalent migration/pre-deploy step against the new image. No production deployment or live migration was performed by this implementation task. Keep `/sw.js` reachable at the root and exempt it from any proxy rule that serves stale worker contents indefinitely.

## Testing a real notification

1. Sign in and open `/profile#notification-settings` (admin React profile also links here).
2. Click **Enable Notifications**, then accept the browser prompt. There is no automatic permission popup. A denial disables the Enable button and explains browser settings; Not Now makes no permission request.
3. Keep Push Notifications and System Notifications enabled and save preferences. In local/development or an admin account, click **Send Test Notification**. It only targets the signed-in account. A queued response does not certify device delivery.
4. Confirm the worker sends, the device displays the alert, and the bell represents the same event. Test mark-read/mark-all/archive. Disable one category and confirm its next event is suppressed. Disable push globally and confirm in-app events still appear for enabled categories.
5. Submit an image/video/text analysis as an ordinary user. Confirm the alert arrives only after successful completion and opens that user's result. Repeat on another device and verify both can receive it. Disable one device and reload to confirm it stays disabled. Explicitly sign out to check token cleanup.

Android checklist: open `https://truthguard.online` in supported Chrome, install the PWA, and enable notifications in its settings. Test while foregrounded, while another app is open, after returning to the home screen, and after closing the PWA window. To trigger a notification while the phone PWA is closed, use the same account from another device or queue a test while the worker is paused and then restart the worker. Tap the alert and verify the existing app window is reused, or a new window opens when none exists. Protected results still require authentication.

## Limitations and manual work

- Browser/OS support, notification permission, network access, Android battery/Doze settings, browser force-stop, cleared browser data and vendor restrictions can delay or prevent delivery. A closed app window is supported; an OS-force-stopped browser cannot be guaranteed to receive pushes.
- A visible notification already delivered to the OS cannot reliably be recalled on logout. Messages are intentionally generic; protected results require login. Tokens are removed on explicit application logout; automatic session expiration alone does not revoke an opted-in device until logout middleware runs or the user disables it.
- FCM/queue delivery is at-least-once, not an exactly-once guarantee. Stable notification IDs/tags replace repeated visible alerts; event keys prevent duplicate in-app records. Retryable failures remain in the existing failed-jobs mechanism after retries are exhausted.
- Existing uploaded-media storage and its access rules were not changed. Push payloads never include submission contents or media URLs.
- Firebase project setup, credentials, production migration/deployment and real Android delivery verification remain manual. Firebase was not contacted with live credentials and production was not changed.

## Verification report

**Verified:** 37 focused PHP tests / 155 assertions passed; eight browser/service-worker tests passed; the production Vite build passed (existing large ApexCharts chunk warning); Compose configuration validation and `git diff --check` passed. The full suite ran 132 tests / 599 assertions with nine errors and 15 failures; none of those were notification tests. The additional CSRF test was verified in the focused run after the full run started.

Focused PHP tests cover token creation/multiple devices/deduplication/rotation/removal, authentication, ownership, CSRF, preferences, completion and evidence events, announcements, OAuth signing/cache, safe payloads/URLs, invalid-token cleanup, retryable failures, read state, logout and self-test access. Browser/worker tests cover default/denied/unsupported/granted permission, explicit consent, existing-worker registration, persisted disable, notification display, click focus and external-URL rejection. Production Vite build and Compose config validation passed.

The complete legacy test suite is not green: failures include obsolete Livewire auth component names and verification routes, older UI text expectations, Google-auth assumptions, and a unit test using the Schema facade without an application container. These areas were not rewritten for the push feature. Test logs are in `storage/logs/push-regression-final.txt` and JUnit XML alongside it; do not interpret the focused checks as a clean full-suite result.
