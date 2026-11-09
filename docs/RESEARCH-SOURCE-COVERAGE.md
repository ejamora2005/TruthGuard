# Relevant source coverage

`evidence-v32` adds `FACT_CHECK_MIN_RELEVANT_SOURCES=10`. This is a coverage
goal, not a ceiling or permission to add weak citations. If a useful batch
contains more than ten qualifying sources, all validated citations can remain.
Complex or conflicting claims still require the ordinary resolution checks.

After initial claim resolution, a coverage pass tries targeted provider queries,
then an unused web discovery request, then cited original records as needed.
The evidence judge reuses earlier completed reviews and sends only new source
documents. An exact publisher review or saved reviewed-claim entry still
provides useful evidence, but does not bypass coverage research when this goal
is enabled. Explicit AI-origin screening does not acquire a ten-source target.

Only sources admitted as supporting, contradicting, or directly relevant context
count. Unreadable pages, snippets, unrelated results, superseded relationships,
duplicate URLs, identical content and flagged syndicated copies cannot inflate
the distinct count. A relevant source can agree or disagree with the final
finding; popularity and source quantity do not determine the verdict.

Useful additional sources count as research progress even after the verdict is
resolved, allowing provider pagination to continue toward the goal. This does
not revive recursive crawling or repeat paid discovery for an unchanged gap.
The coverage pass returns the usable result when available discovery is
exhausted or a provider/review cannot continue. A shortfall is disclosed under
Source verification, with the actual count recorded in
`diagnostics.source_coverage`. It does not fabricate ten citations or force a
different verdict. Reviewing more evidence can increase runtime and API usage.

Compose forwards the new setting to the app and all workers. Both production
templates and `DOKPLOY-RESEARCH-UPDATE.env` include it. Deploy the changed code
and configuration and restart evidence workers. No migration is required.
The pipeline/cache identity includes the coverage target. Opening retained
reports does not run paid research; an intentionally retained first-upload
report can still have its historical source count. Use an explicit Retry to
request a fresh assessment and broader coverage for that report.

Tests use synthetic providers and model responses. Coverage cases opt into ten;
older single-source scheduling fixtures use a zero goal to preserve their
original scope. These tests verify behavior, not measured factual accuracy.

The Source verification page displays all qualifying citation cards. Additional
relevant pages already retrieved during the check can appear in a separate
reference group, including government, institutional, research and news pages.
They are explicitly not verdict evidence and do not increase the cited-source
count. A matching, fetched page or a validated same-event passage is required;
unreadable, unrelated, superseded and duplicate references are excluded. Publishers
are interleaved for visibility. Opening a report does not initiate new searches.
Related public-review cards are also matched to the assessed claim locally,
instead of displaying unrelated recent reviews.
