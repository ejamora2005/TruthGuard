# Research completion and unrestricted discovery

This describes the v29 baseline. The [progressive review update](PROGRESSIVE-RESEARCH-2026-11-09.md)
changes scheduling in v30 to review relevant batches and stop unnecessary work
before exhaustive pagination.

The investigation of TG-25 inspected stored processing diagnostics only. It did
not re-check the submitted claim, run a new provider assessment, or change the
saved verdict. Seven ordinary provider searches returned HTTP 200. OpenAI's
search tool completed and listed 25 consulted sources, yielding five candidate
links, but the surrounding response was marked `incomplete`. Its 500 output
tokens exactly matched the configured allowance. Output truncation is the
likely explanation; the original diagnostic did not retain `incomplete_details`
and cannot prove that reason retrospectively.

The pipeline counted that response as a search failure even though discovery
produced usable URLs. That caused the blanket incomplete-search explanation.
The evidence stages completed, but no decisive source was accepted. A retrieval
or wording repair alone cannot establish a different factual verdict.

## Changes

- A completed search with usable URLs and incomplete response text is recorded
  as `partial_results`. Links are independently fetched and reviewed. This does
  not count as an API outage or turn a search snippet into evidence.
- Diagnostics retain the response status, incomplete reason, recovery attempts,
  and usage for every attempt. A truncated search with no usable discovery can
  receive one recovery attempt within its request timeout and retry policy.
- `FACT_CHECK_UNRESTRICTED_RESEARCH=true` bypasses fixed per-report request/token
  stops, claim limits, source/page/search counts, model-call limits, web-search
  call limits, and overall retrieval-time/round caps. OpenAI requests omit
  application output-token and web-tool-call limits in this mode.
- Query variants are consumed in successive batches without repeating exhausted
  variants. Google review pagination continues until no new continuation token
  is returned; repeated tokens stop pagination. GNews and NewsAPI continue
  through available pages until results end, repeat, or the provider rejects
  further access. Account-compatible page sizes are retained. All returned discovery links
  remain eligible. Research ends when claims resolve or query variants run out.
- Relevant collected publisher reviews remain the first discovery path.
  Matching published reviews still require article evidence and correct claim,
  scope, dates and quantities. Unknown publishers remain discoverable and can
  enter the existing additional-web-source review path.
- The worker job has no fixed total runtime in unrestricted mode. Active
  requests maintain a heartbeat. Submission age alone does not expire an active
  long-running assessment; abandoned jobs still expire and Stop still works.

Provider account restrictions, context packing, input safety checks, network
timeouts, deduplication, and evidence validation remain. Unrestricted mode is
not a claim that every webpage can be accessed or every factual question can
be resolved. It does not force a matching verdict or manufacture sources.

The local environment enables unrestricted mode and uses 120-second individual
OpenAI request timeouts. Existing saved reports are not automatically retried.
The evidence pipeline version is `evidence-v29`, separating new assessments
from previous result reuse.

## Verification

Automated tests use synthetic claims and mocked providers, including publisher
links on Rappler, VERA Files, AFP and Full Fact. They cover output truncation,
discovery recovery, usage accounting, uncapped query/review progress, pagination
beyond previous caps, cancellation, abandoned jobs, and unchanged evidence gates.

The final targeted regression run passed 183 tests with 1,231 assertions. Local
runtime inspection confirmed unrestricted mode, no per-report request/token
stop, no model-call allowance, and no fixed evidence-job timeout. No report was
submitted or rechecked as part of verification.

OpenAI documents that output-token exhaustion can produce an incomplete response:
[Reasoning models](https://developers.openai.com/api/docs/guides/reasoning).
