# Stop a running check

The fact-check loader and its minimized controls now show **Stop fact check**.
The floating progress indicator on other user pages has a separate **Stop** button.
Opening progress, minimizing, hiding the loader or navigating between pages does
not stop the analysis. The stop control becomes available after the upload is
saved and the background check has an ID.

Both controls use the existing styled confirmation dialog. **Keep checking**,
Escape and dismissing the dialog leave the analysis running. Only confirming
**Stop fact check** sends the authenticated, CSRF-protected POST with explicit
confirmation. It targets one report owned by the current user. Confirmation
does not change a report that has already finished.

Queued stopped jobs become no-ops. Running checks use cooperative cancellation
before subsequent model requests, retrieval requests, source retries, progress
updates and result persistence. A request already in flight may finish and can
still incur provider usage; Stop cannot reverse an already-issued request.
The completed response is not allowed to overwrite cancellation. Retry IDs also
prevent an older stopped worker from writing into a newer run of the same report.
Final pending-result writes and cancellation serialize on the report row.

Stopping a retry keeps the previous assessment, sources and recorded usage.
An initial stopped check records an unavailable assessment explaining that the
user stopped it. Existing processing-status values are retained, with a separate
cancellation marker; no database migration or environment setting is required.
Progress responses explicitly distinguish a user stop from a failed analysis.
Opening a stopped report also skips legacy catalog repair, preserving the stop
instead of replacing it with a saved answer.

Deployment must update the web app and evidence worker together. After deployment,
start a check, open Stop, choose Keep checking and verify it continues. Repeat and
confirm Stop. Repeat from the floating indicator on another page. Test confirmation
on mobile as well. Existing logout/session-expiry and stale-job policies are
unchanged.

Automated checks cover confirmation gating, owner scope, idempotence, queued jobs,
in-flight model/source retries, late worker writes, newer-run protection, saved
retry results, page rendering and navigation. Browser component checks use actual
rendered Alpine methods and the shared dialog/helper at desktop and mobile sizes;
network responses are mocked. They are not a production end-to-end deployment test.
No paid model requests are needed for verification.

Verification: 108 PHP tests / 710 assertions, four JavaScript unit tests, six
desktop/mobile browser component scenarios, Pint and the production build passed.
