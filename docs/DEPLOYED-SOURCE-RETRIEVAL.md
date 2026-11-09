# A deployed check is Unverified while local resolves it

Report 146 located a matching VERA Files page but listed it as unreadable and
cited zero sources. This confirms a retrieval failure, not its underlying network
cause. It does not establish whether the VPS received a timeout, denial, robots
restriction or extraction failure. Do not change the verdict without readable,
relevant evidence.

## Deploy the retrieval fixes

The fetcher now safely decodes gzip/deflate responses even when an origin ignores
the request for uncompressed content. Both compressed and decoded response sizes
remain bounded. An explicit fresh retry also rechecks robots.txt instead of
reusing a temporarily failed robots request. Current disallow rules still apply.

Build and redeploy the updated application, evidence-worker, worker and scheduler
from the same revision. A GitHub push alone does not prove these containers have
been rebuilt. Container startup rebuilds Laravel's configuration cache; changing
only the Environment editor without recreating containers does not reload workers.

Compare the settings in `docs/DOKPLOY-RESEARCH-UPDATE.env` with Dokploy's Environment
editor, preserving API credentials. In particular local expanded research uses
`FACT_CHECK_UNRESTRICTED_RESEARCH=true`, whereas Compose defaults to false unless
explicitly overridden. This setting affects research coverage and API usage; it
does not grant access to a publisher that denies requests.

## Inspect the server without a new paid analysis

In Dokploy, select the **evidence-worker** service and run this command in its
container console:

```sh
php artisan truthguard:evidence-doctor --detection=146
```

This reads the historical report and effective runtime configuration. It includes
the pipeline version, saved per-source HTTP/robots status and extracted-text
length. It excludes API keys, submitted text, URL paths/queries and article bodies.
Repeat the command in the app container and compare `runtime` values if containers
appear out of sync. A previously running worker must be recreated to load new code.

To test just the exact public source through the ordinary safe fetcher:

```sh
php artisan truthguard:evidence-doctor --url="https://verafiles.org/articles/fact-check-atong-ang-photo-video-at-bangkok-airport-ai-generated"
```

The `--url` probe is fresh and bounded, respects robots/access rules, and does not
call discovery providers or AI models. Do **not** add `--live`: that separate option
performs provider/model smoke requests that can consume API quota.

| Source status | What to check or change |
| --- | --- |
| `retrieved` with readable text | Transport currently works. Inspect the saved source's assessment/relevance and pipeline version; the old report does not automatically change. |
| `timeout` / `network_failure` | Check VPS DNS and outbound HTTPS. If a functioning site needs more time, set `FACT_CHECK_FETCH_TIMEOUT=15` and redeploy; compare the bounded probe again. |
| `http_401`, `http_403`, `http_429` | Authentication, publisher restrictions, anti-bot protection or rate limiting. Respect access rules; use another independently readable relevant publication or obtain permitted access. |
| `robots_restricted_or_unavailable` | Read `robots_detail`. A transient robots fetch failure may recover on a fresh probe; `robots_disallow` is an access restriction to respect. |
| `missing_full_text` | The returned page had insufficient extractable text. Check the publisher's current public article format; a headline or search snippet is not article evidence. |
| `unsupported_content_encoding` / `invalid_or_oversized_content_encoding` | The encoding is unsupported, invalid or exceeds the configured decoded byte ceiling. Do not admit these bytes as evidence. |
| `blocked_url` | URL/DNS safety validation rejected the destination. Investigate the resolved address; do not disable protection. |

After retrieval and configuration are corrected, use **Retry assessment** on the
existing report. This intentionally runs a new assessment and can consume API
tokens. Reuploading the identical image can reopen the account's first saved
report, including its historical Unverified outcome. The source probe itself
does not change that report and does not demonstrate improved factual accuracy.
