# Exact-image report reuse

Repeat image uploads are checked before storing another file, dispatching a job, running a detector or calling an external API.

- On the same account, an identical image and compatible request open the first completed, retained report. This is a historical answer: its verdict, citations, assessment date and evidence cutoff are preserved. Prompt-guide or deployment changes do not require paying to open it again.
- An identical upload already queued or processing on that account joins the existing job. A short submission lock covers lookup and queue insertion to prevent duplicate jobs from double taps. Abandoned jobs follow the existing stale-job recovery policy.
- Across accounts, a recent, scope-bound original report can supply an owned copy at zero new API tokens. The original private report remains inaccessible. The recipient gets their own upload path and current submitted text; provider runtime details and unrelated owner signals are excluded. Cross-account eligibility retains cache-generation, verification-policy, reviewed-answer dependency and freshness checks.
- Same-account historical lookup can recognize older reports without the new scope marker. Cross-account shortcut reuse requires a recorded scope marker, so unknown personal/location context from legacy reports is not propagated.

Identity uses the exact SHA-256 image bytes plus task, question, notes, source URL, platform, locale and optional location context. Shipped guide prefixes can vary without changing the actual question. Names, amounts, dates and qualifiers are retained; no fuzzy semantic matching is used. Different bytes or a different question follow normal analysis. This shortcut applies to image uploads; the existing pipeline cache still serves other eligible submissions.

Failed, unavailable, unreadable, incomplete and archived reports are excluded. `force_refresh=true` bypasses the shortcut. Uploaded-image fact-check reports now expose the existing **Retry assessment** action as well, allowing an intentional fresh analysis that may consume API tokens. Opening a saved historical answer does not mean its evidence was checked again today. Existing seven-day report retention still applies.

The composer handles completed reuse responses immediately and clears its submitted draft. Joining a running job retains progress and Stop controls. No database migration or paid validation call is needed.

Verification: `tests/Feature/ImageUploadReuseTest.php`, `tests/Feature/StoredResultReuseTest.php`, and the desktop/mobile cached-response cases in `tests/browser/composer-reset.mjs`. Tests use synthetic images and mocked providers rather than live paid analysis.
