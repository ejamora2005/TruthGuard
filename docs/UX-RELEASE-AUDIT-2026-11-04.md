# TruthGuard release UX audit — November 4, 2026

**Recommendation: hold release.** Anonymous access to uploaded evidence is confirmed. The complete backend suite is not green, and installed-app/physical-device acceptance remains unverified. The UI fixes below are local and reviewable; nothing was committed, pushed, or deployed.

## Scope and evidence

Inspected Laravel/Livewire routes, authentication, submission/queue handling, reviewed-claim lookup, result reuse, result presentation, retention, appearance, manifest and service worker. Ran the actual application in Chrome against a separate SQLite fixture database at `127.0.0.1:8011`. The existing database was not seeded or migrated. Existing media fixtures and account factory defaults were reused. Mail used an array transport, external HTTP was faked, and browser-instance jobs were intercepted. Queued/completed/failed transitions used controlled fixture records; they are not evidence of live model accuracy or production worker health.

The initial screen matrix covered **132 page/device/theme combinations**, including 1366×768 desktop, 390×844 mobile and 320×568 short-mobile viewports in light/dark modes. Screens included landing/public reviews, login/register/reset, privacy, offline/error pages, dashboard, composer, pending/failed/completed reports, history, notifications, and settings sections. No horizontal document overflow or page JavaScript exceptions was detected in that matrix. Internal scrolling and hidden controls were inspected separately; the raw clipping heuristic is not a list of confirmed defects.

Evidence directory: [screenshots and JSON records](../output/validation/ux-audit-20261104/). [Initial matrix](../output/validation/ux-audit-20261104/matrix.json), [application flows](../output/validation/ux-audit-20261104/flows.json), [populated source checks](../output/validation/ux-audit-20261104/sources.json).

These are responsive Chrome tests, **not physical Android/iOS tests**. The browser access host was unavailable, and native Computer Use could not reliably determine the browser URL. No installed TruthGuard shell was successfully inspected. Standalone-mode tests simulate the display mode; they do not certify installation, OS integration or installed-app updates.

## Prioritized findings

### UX-01 — Critical, release blocker: uploaded evidence is publicly accessible

**Affected:** uploaded evidence URLs; website and PWA, all devices/themes. **Status:** open.

**Reproduce:** obtain an existing detection's media URL as its owner, then issue an unauthenticated HEAD request to `/storage/<saved-path>` in a separate session. The local existing application returned **200**, `Content-Type: image/jpeg`, `Content-Length: 46827`, without an explicit private/no-store cache policy. Only headers were read; the original file and URL are deliberately omitted from this report.

**Expected:** private evidence requires ownership authorization on every request, including previews/downloads, after logout and on shared devices. **Actual:** the web server serves the evidence directly, outside report-route authorization. Service-worker exclusions do not prevent direct access or ordinary HTTP caching.

**Evidence:** [sanitized HEAD result](../output/validation/ux-audit-20261104/privacy-probe.json); `app/Services/Detections/DetectionPipeline.php:646` writes to the public disk; `app/Models/Detection.php:71` constructs `/storage/` URLs.

**Fix:** store submission evidence outside the public web root and stream it through an authenticated, owner-authorized endpoint with private/no-store headers. Support video/PDF range requests. Migrate existing evidence carefully, remove direct public delivery, and retest copied URLs after logout/account switching. This needs a storage/delivery change, beyond a wording fix.

### UX-02 — High, release blocker: backend regression suite remains failing

**Affected:** evidence analysis, saved results, media failures and result contracts; all devices/themes. **Status:** open.

**Reproduce:** run PHPUnit with PHP 8.3.28/PDO SQLite and `FACT_CHECK_QUEUE_ENABLED=false`, using `phpunit.xml`. The complete rerun still reports failures/errors. See the final run linked below for authoritative counts and individual assertions.

**Expected:** a reproducible green suite before release. **Actual:** the final run executed **648 tests, 4,736 assertions: 31 failures, 2 errors, 4 skipped and 4 risky**. Failures include the evidence-input budget (26,585 bytes against a 22,000-byte ceiling), saved-result/wording expectations, media failure handling, current-status research and search request contracts. One error is local OpenSSL test-key creation; another concerns an unmet mocked research call. These have not all been classified as product defects versus obsolete tests or configuration assumptions.

**Evidence:** [full final JUnit](../storage/logs/ux-audit-20261104-final.xml), [full final output](../storage/logs/ux-audit-20261104-final.txt). The initial valid run had 647 tests, 38 failures and 3 errors with the local queued-analysis setting; forcing synchronous defaults reduced the baseline to 31 failures and 2 errors. The PHP 8.4 attempt without SQLite is excluded from product conclusions.

**Fix:** make the suite independent of developer `.env` settings, classify every remaining assertion, repair genuine behavior regressions, and rerun. Do not remove assertions merely to obtain a green run. Recheck byte/call limits and immutable saved results before authorizing live inference tests.

### UX-03 — High: History hid unfinished and failed checks

**Affected:** History, desktop/mobile, both themes. **Status:** fixed locally.

**Reproduce:** submit a queued check, or let it fail before `analyzed_at` is populated; open History with its default date range. **Expected:** saved submissions remain reachable. **Actual before fix:** counts included the records while the list said “No matching history found.” Date predicates tested only `analyzed_at`, excluding NULL values.

**Fix applied:** use submission time when analysis time is absent, consistent with retention. Local-date boundaries remain UTC-converted. Added coverage for queued, processing and failed submissions, including inclusion/exclusion by date.

**Evidence:** [before](../output/validation/ux-audit-20261104/user-desktop-light--history.png), [after](../output/validation/ux-audit-20261104/after-desktop-light--history.png); `DashboardController.php:84`; `HistoryTimezoneTest.php`. Related suite: **47 passed, 416 assertions**.

### UX-04 — High: onboarding opened overlapping dialogs and lost keyboard focus

**Affected:** first-login dashboard, password setup and optional tools, desktop/mobile, both themes. **Status:** fixed locally.

**Reproduce:** sign in with a fixture account needing consent, tour and password setup; accept consent and finish all eight tour steps. **Expected:** one dialog at a time, with focus inside it. **Actual before fix:** password setup and “Choose your tools” were both visible; Tab reached dashboard links behind them.

**Fix applied:** consent → tour → password setup → optional tools. “Later” hands off to optional tools. Added initial focus, Tab containment, Escape dismissal and a focus destination when optional setup closes. Permissions were not requested during testing and no password was changed through this flow.

**Evidence:** [before state and escaped focus](../output/validation/ux-audit-20261104/onboarding-before.json), [after sequence and contained focus](../output/validation/ux-audit-20261104/onboarding-after.json), [before screenshot](../output/validation/ux-audit-20261104/onboarding-before.png), [after screenshot](../output/validation/ux-audit-20261104/onboarding-after.png).

### UX-05 — Medium: mobile results delayed the answer and repeated the submission

**Affected:** completed reports, especially mobile/short-mobile, both themes. **Status:** fixed locally.

**Reproduce:** open a text-only result at 390px width. **Expected:** verdict and explanation first, supporting evidence afterward. **Actual before fix:** a tall evidence panel and duplicate “Your question” consumed most of the first screen before the verdict.

**Fix applied:** the verdict appears first in the single-column layout; text evidence uses its intrinsic height; text-only questions are not repeated. Saved-result notices retain the original assessment date but replace API-token jargon with “This saved assessment was reused without a new analysis.” Sources, uncertainty and actionable failures remain intact. Desktop two-column positioning is preserved.

**Evidence:** [before dark mobile](../output/validation/ux-audit-20261104/user-mobile-dark--detections-1-result.png), [after dark mobile](../output/validation/ux-audit-20261104/after-mobile-dark--detections-1-result.png). Visual order is fixed; a full assistive-technology reading-order review remains outstanding.

### UX-06 — Medium: narrow settings labels, dark privacy contrast and reduced motion

**Affected:** Appearance at 320px; Privacy Policy in dark mode; floating progress indicator on all devices. **Status:** fixed locally.

**Reproduce:** open Appearance at 320×568; view Privacy Policy in dark mode; enable reduced motion while an analysis indicator exists. **Expected:** whole readable labels, readable headings and quiet status indicators. **Actual before fix:** “System”/“Standard” broke within words; the Retention heading used dark blue on a dark panel; the progress spinner/pulse still animated under reduced motion.

**Fix applied:** narrow choices use full-width controls with a 44px minimum height; the dark Retention heading receives a readable light color; progress decoration stops animating under reduced motion. The settings dialog retains internal scrolling.

**Evidence:** [Appearance before](../output/validation/ux-audit-20261104/user-short-mobile-light--profile-section-appearance.png), [after](../output/validation/ux-audit-20261104/after-short-mobile-light--profile-section-appearance.png); `resources/css/appearance.css`, `resources/css/public-appearance.css`.

### UX-07 — Medium: private HTML lacks consistent no-store headers

**Affected:** dashboard, history, notifications and result HTML; all devices/themes. **Status:** open hardening finding, not a demonstrated Back-button disclosure.

**Reproduce:** inspect authenticated response headers. **Expected:** a consistent private/no-store policy for account/evidence pages. **Actual:** these pages returned `no-cache, private`; Profile and progress responses already used `no-store`. Browser Back/Forward after logout returned to login in the tested Chrome flow, and no private page appeared in the service-worker cache.

**Evidence:** `flows.json`, “Authenticated cache headers and ownership” and “Back and Forward after logout.”

**Fix:** centralize private-response cache policy and validate logout, browser history snapshots and shared-device behavior in installed Chrome/Edge, Android and Safari. Treat this separately from UX-01's confirmed direct-file exposure.

### UX-08 — Medium: account timestamps and retention wording need reconciliation

**Affected:** Settings → Account & Privacy, privacy notice, desktop/mobile, both themes. **Status:** open.

**Reproduce:** sign in and compare the local header clock with “Last login.” The fixture showed approximately 9:51 AM in the header and 1:51 AM for last login, without a timezone label. Also compare the notice's last-seven-days retention statement with archived history.

**Expected:** explicitly labeled, consistent local times and an accurate distinction between hiding, archiving and deleting data. **Actual:** Profile formats the stored UTC timestamp directly; the retention service archives records rather than deleting records/uploads. The notice also explicitly says review is needed before public deployment.

**Evidence:** `resources/views/profile.blade.php:27`; `DetectionRetentionService.php:71`; `resources/views/privacy/partials/policy-content.blade.php:49` and `:78`; initial Account screen in `matrix.json`.

**Fix:** convert account timestamps to the intended user timezone and label them. Establish and approve the actual retention/deletion behavior, then align the notice and account controls. The substantive privacy notice was preserved during this audit.

### UX-09 — Low: offline fallback always uses light colors

**Affected:** offline fallback, desktop/mobile, dark mode. **Status:** open.

**Reproduce:** select dark mode, disconnect and navigate to History. **Expected:** a readable fallback consistent with the selected or system appearance. **Actual:** the fallback is readable but always light; `public/offline.html` declares `color-scheme: light` and fixed light colors. Both dark-mode matrix attempts also recorded a light fallback.

**Fix:** add a static dark palette based on system preference, then decide how an account-selected mode should be carried to the fallback without transferring another account's preference. Keep the fallback free of private account/evidence content.

## What passed and what remains untested

| Flow | Evidence-based result |
|---|---|
| Registration, login/logout, password reset and session expiry | Backend tests passed with fake notifications/mail. Actual browser login, invalid-password feedback, logout, account switching and logout Back/Forward were exercised. Live reset email delivery, real expiration waits and provider login are untested. |
| Google/Facebook | Both routes returned 302 to the correct provider host. Mocked callback/account-linking tests passed. Consent/login/callback with real provider accounts was not completed. |
| Consent and startup prompts | Actual consent-first redirect, eight-step tour and sequential setup verified using synthetic accounts. |
| Text/image/video/PDF/link submissions | Existing files reached the real upload endpoint and returned 202 queued; empty/punctuation claims, FTP URLs, 4,001-character claims, executables and a >20MB file returned 422 with specific errors. Public/private links were accepted into the queue; actual external retrieval/inference was intentionally not run. |
| Unrelated-claim protection and reuse | Reviewed-claim tests rejected negations, changed dates, partial claims and appended unrelated claims. Stored-reuse tests covered changed input/question/task/model, age, failures and current-status freshness. These are controlled tests, not a factual-accuracy certification. |
| Progress and navigation | Actual queued view, minimize, navigation to History, background completion prompt, finished report and stale timeout were exercised with controlled status changes. The corrected browser fixtures verified upload/poll/resume, transient errors and terminal loader shutdown. Physical network-loss/worker recovery remains unverified. |
| Sources and previews | Populated desktop/mobile light/dark source articles, publisher filtering, two recorded trusted-source links and image-preview open/Escape-close passed. External publisher availability and full media/PDF playback/download behavior remain unverified. |
| History and notifications | Real populated/no-match filters, notification detail and Back were exercised; date/retention/inbox tests passed. No real device notification was sent. |
| Theme and accessibility | Account A's dark preference did not become Account B's or the guest's preference; returning to A restored dark mode. Visible login focus, setup Tab containment, viewport overflow and selected contrast/motion issues were checked. Full screen-reader/WCAG certification, all zoom levels, real touch keyboards and hardware permission prompts are untested. |
| PWA/cache/offline | Real root service-worker registration, offline History fallback, reconnect to History and cache enumeration passed in isolated Chrome. Recorded cache URLs were static assets; no dashboard/history/profile/result HTML was present. Worker/push tests exercised revocation and account isolation. Native installation/launch, OS Back, installed-shell updates, multi-tab update races, iOS and shared-device installed logout are outstanding. |

## Verification and handoff

- **64/64 JavaScript tests passed**, including PWA startup/login/offline, push authorization, theme isolation, progress and voice behavior. [Final output](../storage/logs/ux-audit-20261104-js-final.txt).
- **100 targeted PHP tests passed, 690 assertions** for authentication, session handling, onboarding, appearance, validation, reviewed matching, stored reuse and queued reports. [JUnit](../storage/logs/ux-audit-20261104-verified.xml).
- **47 additional PHP tests passed, 416 assertions**, including the History fix and local date boundaries. [JUnit](../storage/logs/ux-audit-20261104-history.xml).
- Production Vite build passed; the existing large ApexCharts chunk warning remains. `git diff --check` passed.
- **33 final visual/interaction checks completed**, with explicit light/dark assertions, no horizontal overflow or page JavaScript exceptions, mobile verdict placement checks, populated/no-match History filters and notification detail navigation. [Records](../output/validation/ux-audit-20261104/verification.json). The floating spinner/pulse no longer ran under reduced motion in this pass; this is not a complete motion audit. The complete backend run is linked under UX-02 and remains a release gate.

Existing edits to the user layout, composer and result card were preserved. Audit edits are confined to the History filter, setup dialog sequencing/focus, result presentation, scoped appearance/contrast/motion styles and relevant tests. Local fixture helpers/database/uploads remain under ignored `storage/app/private/ux-audit`; evidence remains under `output/validation/ux-audit-20261104`. The loopback-only audit server was stopped after verification. Do not publish the fixture server or its synthetic accounts.

Before release: resolve direct evidence access; reconcile the complete backend failures; approve the actual privacy/retention behavior; and complete a staging acceptance pass with real OAuth credentials, an authorized mail sink, approved live-analysis budget, functioning workers and installed desktop/Android/iOS clients.
