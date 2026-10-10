# TruthGuard push notifications

## Opening a notification

The notification bell, notification list, and authorized push clicks open
`/notifications/{uuid}`. This signed-in detail page displays the full message,
marks it read, and offers a separate button for its related result or app page.
Announcements without a related destination can be read here without looping back
to the notification list. Another user's or an archived notification returns 404.
The existing notification center remains available at `/notifications`.

## Android settings controls repair

The profile dialog also lives inside an Alpine `x-teleport` template. When the
dialog was inserted after push initialization completed, its controls never got
click listeners. Notification setup now observes newly inserted settings panels
and initializes them after any pending setup. Existing panels retain one binding;
status updates do not trigger setup again. A browser regression check inserts the
template after the first settings response and verifies test requests are sent
exactly once per click, including after moving the existing panel.

The mobile profile stylesheet hid `.truthguard-push-device-actions`, including
the Enable Notifications button required to request permission and subscribe a
new phone. Those controls and the subscription hint now remain visible on mobile.
The test button immediately shows a sending state and repeats delivery/error
feedback directly below the actions, where it remains visible while testing.
A Chrome mobile-viewport regression check loads the profile CSS and verifies
the Enable gesture, pending test feedback, and a rejected subscription request.
This verifies the UI flow, not actual Android OS notification delivery.

### Isolate phone display from server delivery

Notification settings also provide **Test device alert**. With browser permission
already granted, this directly calls the existing service worker's
`showNotification()` without Firebase, Laravel jobs, or a subscription request.
It binds before server settings load, so it works even if `/push/settings` fails.
It never requests permission automatically or subscribes the device. A resolved
API call only means Chrome accepted the display request; check the phone's tray
and popup yourself. If this alert is visible but the server test is not, investigate
the subscription, queue, provider, and background authorization endpoint. This
diagnostic is not a substitute for background push delivery.

## Reliability repair (2026-10-10)

The reported symptom was no popup after enabling/testing push. The production
Firebase configuration, worker processes, and device permissions were not available
for inspection; the precise live failure is not yet confirmed. The code audit found:

- The production test endpoint/button excluded ordinary users.
- Subscription waited on `navigator.serviceWorker.ready` without a deadline; a
  stalled installation could leave controls unbound. PWA and push used separate
  registration paths, and settings did not distinguish configuration readiness.
- Saving the account push switch did not register the current device or explain
  that browser permission/subscription was still needed.
- There was no per-device provider delivery status or completed-job receipt.
- Already queued FCM messages could display after logout/account switching.

Repairs reuse FCM and `/sw.js`. `resources/js/service-worker.js` shares one root
registration, bypasses stale script caches, and bounds activation waiting. Browser
settings/token requests have deadlines and visible errors. The authenticated test
is rate-limited and only targets the current device; the optional 15-second delay
allows the PWA window to be closed before delivery. Status polling distinguishes
queued, retrying, failed, unsubscribed, and provider-accepted outcomes.

`push_deliveries` adds durable per-subscription/event receipts plus shared cache
locks. Replayed successful jobs do not resend. Provider retries are still
at-least-once: a crash after provider acceptance but before recording success can
resend. The worker also stores up to 256 displayed IDs (no message text) to suppress
repeated alerts across worker restarts.

Before displaying or opening a notification, the worker checks
`GET /push/deliveries/{uuid}`. This endpoint uses the encrypted HttpOnly device
cookie and current subscription ownership, not a live page or active login session.
It never accepts a recipient user ID. Logout removes the binding; a different
account login revokes the previous account's browser binding. Removed/disabled
subscriptions, mismatched logged-in accounts and archived messages fail closed.
If the server cannot be reached during this check, the popup is suppressed and the
notification remains in the in-app history. This security tradeoff means provider
acceptance alone never proves a visible popup.

Local diagnostic results: the available Firebase configuration was structurally
valid and Google OAuth accepted its service-account credentials. No real FCM push
was sent, and no Windows/Android OS popup or installed-PWA background delivery was
verified. Production has not been changed or deployed.

### Repair files

- `app/Http/Controllers/PushNotificationController.php`: device-bound test/status and background authorization.
- `app/Jobs/SendPushNotification.php`: shared lock, durable receipts, safe retry diagnostics.
- `app/Services/Notifications/TruthGuardPushNotificationService.php`: provider result and OAuth diagnostic.
- `app/Services/Notifications/NotificationEventService.php`: current-device targeting and delayed tests.
- `app/Providers/AppServiceProvider.php`: logout cookie removal and account-switch revocation.
- `app/Services/Notifications/PushConfiguration.php`, `app/Console/Commands/PushDoctor.php`: safe configuration checks.
- `app/Models/PushDelivery.php`, `database/migrations/2026_10_10_190000_create_push_deliveries_table.php`: delivery receipts.
- `resources/js/service-worker.js`, `resources/js/pwa.js`, `resources/js/push-notifications.js`: shared registration and settings feedback.
- `public/sw.js`: background authorization, persistent duplicate suppression, safe clicks.
- `resources/views/notifications/settings.blade.php`, `routes/web.php`: delayed test UI and secure endpoints.
- `docker/apache.conf`: revalidate the root worker and declare its allowed scope.
- `tests/Feature/PushReliabilityTest.php`, `tests/Feature/PushNotificationsTest.php`, `tests/push-permissions.test.mjs`, `tests/push-service-worker.test.mjs`, `tests/push-worker-registration.test.mjs`: regression coverage.
- `docs/PUSH-NOTIFICATIONS.md`: setup and verification instructions.

Existing edits to manifest background color and service-worker cache version were
preserved; the manifest was not changed by this repair.

### Repair verification

- 38 focused Laravel tests / 150 assertions passed (push reliability, push,
  notifications, announcements, production configuration).
- 15 browser/service-worker/registration checks passed. Firebase/browser permission
  responses are mocked; no installed-app OS notification delivery is claimed.
- Production Vite build, Blade compilation and push route registration passed.
- Local credential exchange with Google OAuth passed, without sending a notification.
- The new delivery-table migration was tested on isolated SQLite databases, not
  applied to the local application database or production. The diagnostic correctly
  flags the missing `push_deliveries` table until deployment migration runs.
- Existing Compose environment forwarding and worker timeout/retry settings were
  inspected and retained. No production container build or Apache runtime was tested.

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

The original migration creates `push_subscriptions` and `notification_preferences`, both with cascading user foreign keys. It adds nullable unique `notifications.event_key` for atomic event deduplication. No existing notification rows are removed. Archive keeps event keys, preventing replay of dismissed events. Run the migration before serving the new application code or starting updated workers.

The repair migration `2026_10_10_190000_create_push_deliveries_table.php` must also run before the updated worker starts. It does not modify detection data.

## Routes

Settings and mutation routes use existing session authentication, CSRF and privacy middleware; mutation endpoints do not accept a recipient user ID. The background delivery-validation route instead requires the encrypted device cookie, allowing opted-in devices to receive alerts without an open page.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/push/settings` | Public Firebase client values plus own preferences; no-store |
| POST | `/push/subscriptions` | Register/refresh own device; 30 requests/minute |
| DELETE | `/push/subscriptions` | Remove own token/current device and persist device opt-out |
| PATCH | `/push/preferences` | Update own validated boolean settings |
| POST | `/push/test` | Queue a current-device test; optional `delay_seconds` of 0, 15 or 30; 3/minute |
| GET | `/push/test/{uuid}` | Own notification/provider-delivery status; 60/minute |
| GET | `/push/deliveries/{uuid}` | Cookie-bound background authorization and canonical message; no-store; 120/minute |

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
node --test tests/push-service-worker.test.mjs tests/push-permissions.test.mjs tests/push-worker-registration.test.mjs
npm run build
```

The browser tests use mocked Firebase responses and installed headless Google Chrome (`channel: chrome`), not live device delivery.

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
3. Keep Push Notifications and System Notifications enabled and save preferences. Click **Send Test Notification** as any signed-in user. It targets only this device. Watch the delivery status. A queued or Firebase-accepted response does not certify OS display.
4. Confirm the worker sends, the device displays the alert, and the bell represents the same event. Test mark-read/mark-all/archive. Disable one category and confirm its next event is suppressed. Disable push globally and confirm in-app events still appear for enabled categories.
5. Submit an image/video/text analysis as an ordinary user. Confirm the alert arrives only after successful completion and opens that user's result. Repeat on another device and verify both can receive it. Disable one device and reload to confirm it stays disabled. Explicitly sign out to check token cleanup.

Android checklist: open `https://truthguard.online` in supported Chrome, install the PWA, and enable notifications in its settings. Test while foregrounded, while another app is open, after returning to the home screen, and after closing the PWA window. To test with the window closed, select the 15-second test delay, send the test, then close the PWA window without logging out. Do not force-stop Chrome. Tap the alert and verify the existing app window is reused, or a new window opens when none exists. Protected results still require authentication.

## Limitations and manual work

- Browser/OS support, notification permission, network access, Android battery/Doze settings, browser force-stop, cleared browser data and vendor restrictions can delay or prevent delivery. A closed app window is supported; an OS-force-stopped browser cannot be guaranteed to receive pushes.
- A visible notification already delivered to the OS cannot reliably be recalled on logout. Messages are intentionally generic; protected results require login. Tokens are removed on explicit application logout; automatic session expiration alone does not revoke an opted-in device until logout middleware runs or the user disables it.
- FCM/queue delivery is at-least-once, not an exactly-once guarantee. Stable notification IDs/tags replace repeated visible alerts; event keys prevent duplicate in-app records. Retryable failures remain in the existing failed-jobs mechanism after retries are exhausted.
- Existing uploaded-media storage and its access rules were not changed. Push payloads never include submission contents or media URLs.
- Firebase project setup, credentials, production migration/deployment and real Android delivery verification remain manual. The local Google OAuth credential check succeeded; no actual FCM device push was sent and production was not changed.

## Earlier implementation verification report (historical)

**Verified:** 37 focused PHP tests / 155 assertions passed; eight browser/service-worker tests passed; the production Vite build passed (existing large ApexCharts chunk warning); Compose configuration validation and `git diff --check` passed. The full suite ran 132 tests / 599 assertions with nine errors and 15 failures; none of those were notification tests. The additional CSRF test was verified in the focused run after the full run started.

Focused PHP tests cover token creation/multiple devices/deduplication/rotation/removal, authentication, ownership, CSRF, preferences, completion and evidence events, announcements, OAuth signing/cache, safe payloads/URLs, invalid-token cleanup, retryable failures, read state, logout and self-test access. Browser/worker tests cover default/denied/unsupported/granted permission, explicit consent, existing-worker registration, persisted disable, notification display, click focus and external-URL rejection. Production Vite build and Compose config validation passed.

The complete legacy test suite is not green: failures include obsolete Livewire auth component names and verification routes, older UI text expectations, Google-auth assumptions, and a unit test using the Schema facade without an application container. These areas were not rewritten for the push feature. Test logs are in `storage/logs/push-regression-final.txt` and JUnit XML alongside it; do not interpret the focused checks as a clean full-suite result.

## Production verification after approval

Do not deploy until the owner approves. Keep the same `APP_KEY` on app, worker and
scheduler: subscriptions and device cookies depend on it. Keep all Firebase values
identical on those services using the existing Compose environment anchor.
Use `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`,
`SESSION_SECURE_COOKIE=true`, `APP_URL=https://truthguard.online`, and configure
`TRUSTED_PROXIES` for the actual Traefik proxy network. Do not expose the internal
app port to untrusted traffic when trusting proxy headers. `DB_QUEUE_RETRY_AFTER`
should remain greater than worker timeout (existing values: 360 and 300 seconds;
push job timeout: 45 seconds). Outbound HTTPS to Google OAuth/FCM is required.

Run the migration once against the shared database using the new image, then
recreate the app/worker/scheduler services. Their entrypoint refreshes config,
routes and views. In the Dokploy service terminal, run:

```sh
# Run in both app and worker containers; prints no key or token values.
php artisan truthguard:push-doctor --check-provider
php artisan queue:failed
```

If status remains queued, inspect worker logs and queue backlog. If status is
retrying/failed, inspect its sanitized HTTP/provider code: 401/403 point to service
credentials/project authorization; SENDER_ID_MISMATCH to a different Firebase
project; THIRD_PARTY_AUTH_ERROR to Web Push credentials; 429/503 to transient
quota/service failures. Invalid/expired registration tokens are removed; click
Enable Notifications again on the affected device.

A Firebase-accepted status without a visible alert requires checking Chrome site
notification permission, Windows notification settings/Do Not Disturb or Android
app notification permission/battery restrictions. Reopen the PWA once after the
release to update `/sw.js`. Private `/push/*` responses must not be cached by a CDN.
Do not configure a separate `firebase-messaging-sw.js`.

For Windows and Android separately, record: foreground popup, minimized popup,
15-second delayed popup after closing the window, notification click target, offline
fallback, logout/account-switch suppression, and disabled-permission feedback.
No code-only test substitutes for these device checks.
