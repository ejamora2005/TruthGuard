# Fact-checking and media-origin audit — November 6, 2026

## Scope and release status

Audited `main` at `eba2880c96cea91d5910487d81667a3a7f7be4a3`, including
`25a36c29`, `b9baf546`, `af7eeca8`, and `eba2880c`. The initial working tree
contained only untracked `tmp/`. That directory was not inspected or modified.

The full suite still has the 24 failing cases documented below. Commit and push
were initially withheld under the requested test gate. After those results and
the complete change summary were disclosed, the user explicitly requested a
commit and push to `main` on November 6, 2026. This batch packages the audit,
source-coverage, optional cost-control, and Dokploy configuration fixes under
`fix: improve evidence verification, result reuse, and cost controls`.
No production deployment was performed as part of this work.

## Submission-to-result flow

1. `DetectionController::store()` normalizes pasted links, validates input,
   resolves the submitted task, and either dispatches `AnalyzeDetectionEvidence`
   or calls `DetectionPipeline::run()` synchronously.
2. The pipeline persists the upload, calculates byte/perceptual identity, locks
   identical requests, and checks eligible completed results before inference.
3. `buildLocalAnalysis()` runs only the requested media detectors. Image
   screening passes through `MediaInputAssessment` and
   `MediaOriginDecisionPolicy`; it does not decide factual truth.
4. `EvidenceVerificationService::apply()` resolves the request and extracts visible
   text or a safely retrieved page. An admissible exact review of the whole claim
   can now avoid paid planning and research; current-status and context requests
   continue through `ClaimPlanner`. A supplied claim remains separate from its
   supporting article, including when that article is unavailable.
5. `ArticleSearch::retrieve()` searches saved publisher reviews and configured
   discovery providers, safely retrieves documents, records dates, and follows
   relevant linked primary records within the existing retrieval budget.
6. `EvidenceJudge` validates source IDs, literal excerpts, material-match fields,
   expiry, legal status, and supersession. The server aggregates claim outcomes.
7. `ExactFactCheckReconciler` applies admissible exact published ratings or
   abstains on conflicting exact reviews. Related reviews do not inherit ratings.
8. `ReportAnswer` / `ResultContract` normalize the result and citations.
   Optional OpenAI media narration receives detector text rather than pixels and
   cannot change the categorical verdict. The pipeline stores the report and usage.
9. Owned result routes render the saved result. Current researched reports are
   protected from catalogue replacement. Explicit retries preserve the upload,
   gather fresh evidence, and save into the existing report.

## Confirmed existing protections

- Poor-prompt interpretation, whole-submission reference resolution, Filipino /
  English searches, rated-headline handling, and research of searchable unclear
  headings were already implemented in `ClaimPlanner` and `PoorPromptInterpreter`.
- Factual verification and media screening already had separate request routing,
  report components, and claim notices. Explicit media-only requests do not run
  a factual search merely because text appears in an image.
- Evidence admission already checked publication/update cutoffs, approved host
  boundaries, literal bounded excerpts, source IDs, material-match fields,
  proposed versus enacted law, petitions versus rulings, and explicit supersession.
- Safe fetching already blocked private/reserved addresses, pinned validated DNS,
  rechecked redirects, honored robots restrictions, and bounded payload size.
- The recent commits already added exact-review reconciliation, perceptual hashes,
  model/policy-aware result fingerprints, unresolved-result TTLs, and recovery
  wording asking for one screenshot or the exact visible claim and date.
- Existing cache locks prevent identical requests from starting duplicate paid
  analysis. Retention, original assessment dates, explicit refresh bypass, and
  exclusion of incomplete/quota-failed factual results were present.
- Missing camera metadata was already neutral. Calibration requires a pinned
  validated artifact; model scores do not establish factual truth or provenance.
- The mobile layout/report-heading commit was preserved; no frontend files changed.

## Root causes and behavior changes

| Priority | File / method | Before | After |
| --- | --- | --- | --- |
| P0 | `FactCheckClaimMatcher::assess()` | Partial entity/action overlap could be exact; negation was ignored in the gate and its `xor` assignment was asymmetric. Non-year quantities, qualifiers, and actor/action order were inadequately distinguished. | Conservative matching checks both entity sets, actions, roles, polarity, ordered signed numbers/dates, and qualifiers. Explicit rating wrappers are separated from the reviewed proposition. Ambiguous paraphrases abstain. |
| P0 | `ExactFactCheckReconciler::apply()` | Titles were concatenated with reviewed claims; inaccessible/unapproved records could be used; conflicting reviews preserved a possibly firm earlier verdict. | Uses the reviewed proposition and published rating separately, requires retrieved text and approved original/final hosts, and emits `CONFLICTING_EVIDENCE` with both sources. A single review cannot override a multi-claim report. |
| P0 | `ArticleSearch::retrieve()`, `collectedReviews()`, `deduplicate()` | Enhanced verification omitted the saved publisher catalogue; discovery order discarded later fact-check metadata; identical bodies could hide differing ratings. | Searches the saved catalogue without refreshing feeds or previews, preserves Google review metadata on existing URLs, and retains differing ratings through deduplication. |
| P0 | `MediaOriginDecisionPolicy::apply()` | Disabling the supporting CNN silently disabled required agreement; an earlier abstention could be overwritten. | Required agreement remains required when the detector is absent, primary abstention is preserved, and unusable input inspection abstains. |
| P1 | `MediaAnalysisReport::result()`, `MediaInputAssessment::inspect()` | Opposite-class confidence could satisfy the threshold; video scores missed the selective threshold; screenshots alone were not decision-limited. | Uses the assigned class's score, rejects invalid scores/labels, applies the decision threshold to sampled video, and permits screenshot/composite abstention. Calibration does not reverse abstention. |
| P1 | `DetectionPipeline::buildRequestFingerprint()`, `findReusableDetection()`; `MediaPerceptualHash::matchesPaths()` | Perceptual reuse depended on the selected mode and trusted a colliding 64-bit dHash. | Uses resolved media-only requests, then checks dimensions and a conservative RGB comparison. Factual auto-routing retains byte identity; worsened input limitations cannot inherit a firm origin finding. |
| P1 | `EvidenceVerificationService::applyClaims()`; `ClaimPlanner::requiresFreshEvidence()` | Explicit refresh could reuse cached search/page evidence; freshness rules differed between reuse and retrieval. | Propagates refresh to extraction/retrieval and uses shared English/Filipino freshness checks. Explicit fresh research bypasses reviewed-answer shortcuts. |
| P1 | `DetectionController::retryMedia()`, `result()`, `restoreKnownFactCheck()` | Synchronous retries could compute but discard the new report; JSON forced `completed`; opening a fresh report could overwrite research with a catalogue answer. | Synchronous retry owns a processing state and persists its result; JSON preserves the typed status; current pipeline reports survive reads unchanged. |
| P1 | `SafeArticleFetcher::fetch()`, `RetrievalBudget`; `EvidenceVerificationService::applyClaims()` | A failed submitted URL could be processed again during discovery. HTTP 408 did not receive the bounded transient retry. | Keeps request-local fetch outcomes, retains failed submitted-page records, retries temporary 408/429/5xx/network failures at most once, and does not retry permanent HTTP failures. |
| P1 | `ResultWording::inaccessibleLink()` / `accept()` | Recovery could miss a technical result caused by link access; arbitrary short-copy rejection invalidated useful saved wording. | Access-specific recovery also handles that result shape, while supplied claims and internal/quota/configuration failures keep their assessment/status. Useful short grounded wording is accepted. |
| P1 | `EvidenceJudge::validate()` | An interested denial or an unrelated review rating could be declared decisive through model match fields. | Adds deterministic guards for denial-only evidence and materially different reviewed propositions, while allowing verification of the fact that a denial occurred. |
| P1 | `OpenAiMediaExplanation::apply()`; reuse / usage recording | Concrete feature/provenance inventions relied on prompt instructions; optional narration failures prevented reuse; some OCR-only usage and reused diagnostics were misleading. | Rejects covered unsupported visual/generator/provenance statements, retains detector output, reuses completed detector results after narration failure, and records OCR usage, explicit cache-hit flags, and zero new tokens on reuse. |

Exact reviews now retain literal retrieved excerpts, the reviewed claim, the
published rating, and source explanations through `ResultContract`. Prior model
wording cannot replace the deterministic exact-review explanation.

## Required examples

All cases use frozen synthetic records and mocked transports; they are regression
tests of application behavior, not newly researched judgments about real posts.

| Example | Coverage / expected behavior |
| --- | --- |
| “Posts on Rep. Suan’s ‘shoot-to-kill’ resolution vs Bato dela Rosa FAKE” | Enhanced integration: researches the rated headline and applies the matching publisher's rating. |
| “Suan filed a shoot-to-kill resolution against Bato dela Rosa” | Enhanced integration: exact raw proposition uses the published rating; opposite polarity or a different actor/target cannot inherit it. |
| “GMA SARADO NA?! HATOL SA KORTE?!” | Enhanced integration: the short mixed-language headline is researched and can use an exact published review. |
| Inaccessible Facebook post without a caption | Existing feature coverage: safe access failure, no paid model call, one screenshot or exact visible claim/date requested once. |
| Inaccessible article plus a factual claim | Enhanced integration: the supplied statement continues to be checked and link access does not replace its result. |
| Same image with another encoding | Stored-result tests: different PNG compression levels and PNG/JPEG/WebP versions reuse an eligible media-only result with zero new API tokens. |
| Real compressed/text-overlaid screenshot with borderline score | Policy and workflow tests: remains inconclusive even with a supporting detector score; absence of metadata is neutral. |
| Two exact fact-check sources with conflicting ratings | Unit and enhanced integration: explicit conflict and human-review requirement, with both sources retained. |

Additional cases cover same-hash different-color images, swapped actor roles,
swapped quantities/actions, signed values, dates, qualifiers, failure retries,
refresh propagation, literal source passages, status preservation, and narration
grounding. Existing in-scope fixtures were updated to distinguish strong detector
agreement from screenshot/borderline abstention and to use the current wording contract.

## Validation

PHP on the default shell is 8.1; validation uses the installed PHP 8.3.28 executable
required by this project. OpenSSL fixture validation uses that installation's
`extras/ssl/openssl.cnf` through a process-local `OPENSSL_CONF` setting.

| Check | Final result |
| --- | --- |
| Initial focused units and relevant feature workflows | **273 tests, 1,820 assertions; passed** |
| Source-coverage follow-up selections | **38 tests / 262 assertions and 107 tests / 972 assertions; passed** |
| Latest complete PHPUnit suite | **824 tests, 5,591 assertions; 24 failures, 0 errors, 4 skipped, 2 risky** |
| Pint on every changed PHP file, including new tests | Passed |
| `git diff --check` | Passed |
| Original `tmp/` and unrelated untracked files | Untouched |
| Commit / push | Explicitly requested after disclosure of the 24 remaining failures |

The earlier broad run had 719 tests, 4,946 assertions, 57 failures and 5 errors.
The audit's subsequent 780-test run had 24 failures. The latest 824-test run has
exactly the same 24 failing test-case names, with no added or removed failures.
Every remaining failing test name also failed in the earlier broad run. This comparison
does not turn a failing full suite into a release approval. The final broad run
completed in 55.505 seconds. Its failure groups are:

| Test class | Failures | Observed blocker |
| --- | ---: | --- |
| `CanonicalFactCheckResultTest` | 1 | Saved-result HTML assertions |
| `CommittedResultPageTest` | 3 | Historical presentation and pending-report expectations |
| `DetectionPipelineTest` | 7 | Synchronous expectations versus configured queued submission behavior |
| `DetectionSubmissionValidationTest` | 5 | Guide-only submissions accepted instead of validation errors |
| `FactCheckWorkspaceTest` | 2 | Existing result-layout assertions |
| `ImageExtractionFailureTest` | 1 | Synchronous versus queued retry expectation |
| `PendingReportJsonTest` | 1 | Opening the workspace resumes pending-work state unexpectedly for the test |
| `PhotoQuestionWorkflowTest` | 1 | Existing task-choice report presentation assertion |
| `ResultSourceRenderingTest` | 2 | Pending/failed routing and shortcut presentation assertions |
| `ReviewedMediaLookupTest` | 1 | Expected provenance source missing from the public result |

These are unresolved release blockers, not assertions that each failing test is
necessarily a production defect. The guide-only validation and missing provenance
source deserve separate correctness investigation; UI/queue expectations need
comparison with their intended behavior before changes outside this patch.

Machine-readable results remain in ignored workspace artifacts:

- `storage/logs/truthguard-audit-regressions.xml` and its `.txt` output
- `storage/logs/truthguard-audit-final.xml` and its `.txt` output
- `storage/logs/truthguard-audit-baseline.xml`

The focused selection covers `ArticleSearchDiagnosticsTest`, the changed matcher,
reconciler, resolver, wording, retry, evidence-meaning and media-policy units;
`EnhancedEvidenceIntegrationTest`, `MediaExplanationGroundingTest`,
`StoredResultReuseTest`, `EvidenceSubmissionCoverageTest`, `PastedLinkExtractionTest`,
`AiOriginWorkflowTest`, `GeneratedResultWordingTest`, `OpenAiMediaExplanationTest`,
`MediaAnalysisWorkflowTest`, `EvidenceVerificationTest`,
`QuestionDirectedAnalysisTest`, and `VideoScreeningTest`.

The initial audit did not change frontend source. The subsequent source-coverage
fix updates the report's assistance details; `npm.cmd run build` passed (Vite's
existing large-chunk warning remains). No provider API keys, live inference
budgets, or production settings were changed. No deployment command has been executed.

## Source-coverage and usage follow-up

The screenshot's generic opening and 47,383-token total prompted these further
changes. No local report with that exact total was found, so the specific live
report's source failure and usage breakdown have not been reproduced.

- Claim-aware literal passages are selected across the available retrieved article
  text, within the existing encoded model-input budget. Previously, each article
  contributed only its beginning. Fetch-time text limits still apply; this does
  not claim coverage of material beyond those limits. Excerpts must occur both
  in the original source and in text actually supplied to the reviewer.
- Completed reviews of unchanged articles are reused within a request. A later
  round sends new documents and earlier validated findings, preserving conflicts
  and allowing explicit dated supersession. Failed model reviews remain retryable.
- Approved publisher pages can contribute their own nested JSON-LD `ClaimReview`
  proposition and named rating. Metadata about a different page and numeric-only
  ratings are rejected. Multiple reviews on one page are not arbitrarily selected.
- Exact saved reviews are retrieved before paid decomposition. Only an admissible
  whole-claim match can skip planning/search/review calls; conflicting exact
  ratings abstain. Supporting article text no longer becomes part of the final
  claim string, which previously prevented an otherwise exact match.
- New unresolved explanations name the claim and the recorded evidence gap.
  Partially established reports lead with a validated finding, retain its source
  citation, then identify unresolved assertions. Historical boilerplate can be
  presented more clearly without changing its saved verdict or evidence.
- New reports record provider-reported usage by extraction, planning, source
  search, evidence-review round, and optional media explanation. Input/output,
  cached input, reasoning subsets, request counts, and retries remain separate.
  Cached/reasoning subsets are not added to totals. Reported usage from retried
  responses is retained even when final structured output is invalid. Unknown
  usage is not estimated. Saved-result reuse shows new tokens as zero and labels
  historical totals separately. Old reports do not acquire invented breakdowns.

The first follow-up selection passed 78 tests / 479 assertions. A subsequent
38-test selection passed 262 assertions, including the new publisher-page path,
late-article coverage, incremental conflict preservation, failed-review retry,
multibyte byte limits, token accounting, partial-result wording, and rendered
usage/reuse details. The final 107-test follow-up selection passed 972 assertions,
covering all supported input formats, the public result contract, dated answers,
and the updated source-review and wording behavior. A regression test also verifies
that a new dated correction can supersede a cached earlier finding without
resending the older document. The final full run covers that test and the last
presentation fixes; its 24 failures exactly match the previous audit's failures.
These are offline fixture tests, not a measurement of live
source availability, factual accuracy, or real API cost savings.

Follow-up artifacts are `storage/logs/truthguard-coverage-regressions.xml`,
`storage/logs/truthguard-coverage-followup.xml`, and the final
`storage/logs/truthguard-coverage-final.xml` / `.txt`.

## Files changed

Application:

- `app/Http/Controllers/DetectionController.php`
- `app/Providers/AppServiceProvider.php` and `config/fact_check.php`
- `.env.example` and `.env.production.example` (actual environment files preserved)
- `compose.yaml` forwards all four optional-limit settings through the shared
  app/worker/scheduler environment; `docs/DEPLOYMENT.md` documents Dokploy setup.
- `app/Services/Detections/DetectionPipeline.php`
- `app/Services/Detections/Evidence/{ArticleSearch,ClaimPlanner,EvidenceJudge,RetrievalBudget,SafeArticleFetcher}.php`
- New `app/Services/Detections/Evidence/{EvidencePassages,TokenUsage,ReportApiBudget,ReportBudgetExceeded}.php` and `app/Services/Detections/EvidenceSummary.php`
- `app/Services/Detections/Evidence/{OpenAiWebSearch,ResponsesTransport,StructuredAnalysis}.php`
- `app/Services/Detections/{EvidenceVerdictCalibration,EvidenceVerificationService,ExactFactCheckReconciler,FactCheckClaimMatcher,FactCheckEvidencePolicy,LiveVerificationEvidenceService}.php`
- `app/Services/Detections/{MediaAnalysisReport,MediaInputAssessment,MediaOriginDecisionPolicy,MediaPerceptualHash,OpenAiMediaExplanation,ResultContract,ResultWording,TrustedFactCheckVerdictResolver}.php`
- `app/Services/Detections/ReportAnswer.php`
- `app/Services/Detections/{FactCheckResultService,OpenAiDetectionReportService,OpenAiExtractionFailure}.php`
- `resources/views/detections/partials/advanced-result-card.blade.php`

Tests:

- New `tests/Feature/EnhancedEvidenceIntegrationTest.php`
- New `tests/Feature/MediaExplanationGroundingTest.php`
- New `tests/Unit/{EvidenceReviewCoverage,EvidenceSummary,TokenUsage}Test.php`
- New `tests/Unit/{ReportApiBudget,OptionalResearchLimits}Test.php` and `tests/Feature/EvidenceCostSchedulingTest.php`
- `tests/Feature/{AiOriginWorkflow,EvidenceVerification,GeneratedResultWording,MediaAnalysisWorkflow,OpenAiMediaExplanation,StoredResultReuse}Test.php`
- `tests/Feature/{PendingReportJson,PhotoQuestionWorkflow,QuestionDirectedAnalysis,VideoScreening}Test.php`
- `tests/Feature/TaskRoutingAndHeadlineTest.php` and `tests/Unit/{ResearchCostControls,ResearchWorkflow}Test.php`
- `tests/Unit/{ArticleSearchDiagnostics,EvidenceMeaningGuard,ExactFactCheckReconciler,FactCheckClaimMatcher,MediaOriginDecisionPolicy,ResultWording,SafeArticleFetcherRetry,TrustedFactCheckVerdictResolver}Test.php`
- This audit document.

## Environment and deployment

No migration was introduced. Keep the supplied reuse/media values:

```dotenv
FACT_CHECK_RESULT_REUSE_GENERATION=exact-review-2026-11-06-v5
FACT_CHECK_RESULT_REUSE_UNRESOLVED_SECONDS=86400
AI_ORIGIN_MINIMUM_CLASS_SCORE=0.90
AI_ORIGIN_REQUIRE_DETECTOR_AGREEMENT=true
AI_ORIGIN_SUPPORTING_MINIMUM_CLASS_SCORE=0.75
MEDIA_CALIBRATION_ENABLED=true
```

The cost/quality follow-up adds two optional report-wide spending settings and
supports `0` for the existing claim/model-call settings. Config defaults and the
example environment files now use no fixed caps for these four settings:

```dotenv
FACT_CHECK_MAX_MODEL_CALLS=0
FACT_CHECK_REPORT_MAX_API_REQUESTS=0
FACT_CHECK_REPORT_STOP_AFTER_TOKENS=0
FACT_CHECK_MAX_CLAIMS=0
```

Zero support requires this updated code; the previous deployed implementation
does not interpret all these zeros as unlimited. Do not copy these values into
an older deployment before installing the update.

Zero means no fixed limit for that setting; it does not remove retrieval
timeouts, payload bounds, finite research rounds, or bounded retry safeguards.
The actual `.env` and production environment were not rewritten: existing
positive values continue to apply until the operator changes them. Positive
report request limits include extraction, planning, discovery, review, narration,
the legacy pipeline, and retries. A positive token threshold stops subsequent
calls after measured usage reaches it; it cannot guarantee a ceiling for an
already running hosted search. This limitation is explicit in saved diagnostics
and regression-tested using a 47,383-token response. Do not present it as a hard
provider billing cap. No model selection or source/output limit was lowered.

The enhanced pipeline now reviews ordinary provider documents before invoking
OpenAI web search. A resolved claim skips that paid discovery. A remaining gap
can trigger one paid fallback and review of newly found documents; unchanged
documents retain their validated earlier findings. A failed review, an exhausted
model-call allowance, or unavailable model configuration does not trigger more
paid search. An exact published review discovered by an ordinary provider can
also avoid the relationship-model call. These changes improve useful work per
request without relying on low fixed caps to control cost.

At the user's preference for meaningful evidence over arbitrary low limits,
unlimited claim mode requests every checkable assertion in the requested scope
and preserves all valid returned claims. Unlimited model-call mode permits the
bounded workflow's needed stages; it does not create a new unbounded agent loop.
More tokens or calls do not establish factual accuracy: literal passages,
material matches, dates, conflicts, and explicit uncertainty still govern results.

Cost-control checks passed 140 tests / 1,116 assertions. The subsequent optional
limit, accounting, and scheduling selection passed 75 tests / 507 assertions. The production
build passed again. Final cost-follow-up suite artifacts are
`storage/logs/truthguard-cost-final.xml` / `.txt`.
The final full run completed 806 tests / 5,486 assertions with exactly the same
24 failing cases as the earlier audit, zero errors, four skipped, and two risky.
No new failing case names remain. Commit and push were initially withheld;
the user subsequently explicitly requested both after reviewing these results.
Production deployment has not been performed. The actual `.env` remains unchanged.

Changed policy/pipeline versions and fingerprint identities exclude old results
without changing the generation setting. Required detector agreement now abstains
if the supporting detector is disabled or unavailable; a deployed validated CNN
must be enabled/healthy to obtain a positive agreement-based decision. Existing
evidence-calibration artifacts are bound to pipeline identity and need revalidation
after evidence-policy changes. Media calibration settings remain intact.

After the test gate passes and a reviewed commit has been pushed, follow the
repository's deployment procedure with a database/storage backup and the existing
production environment. For its documented single-server Compose deployment:

```sh
git pull --ff-only origin main
git lfs pull
docker compose --env-file .env.production build --pull app
docker compose --env-file .env.production exec --user www-data app php artisan down --retry=60
docker compose --env-file .env.production stop worker evidence-worker scheduler
docker compose --env-file .env.production run --rm --user www-data app php artisan migrate --force
docker compose --env-file .env.production up -d --force-recreate app worker evidence-worker scheduler
docker compose --env-file .env.production exec --user www-data app php artisan queue:restart
docker compose --env-file .env.production exec --user www-data app php artisan up
```

The migration command also ensures the earlier perceptual-hash migration is
present; this patch adds none. Production entrypoint/cache handling and rollback
procedures remain as documented in `docs/DEPLOYMENT.md`.

## Remaining limitations

Question-headline follow-up: a production screenshot showed the generic preset
instruction, full GMA/court/Mel Tiangco thumbnail, and a rhetorical-only
`GMA SARADO NA?!` target. The planner's `requires_context` classification caused
the judge to downgrade every decisive relationship. The earlier GMA integration
test mocked the planner and did not cover this actual path. The fix recognizes
literal named-subject status questions as candidates without assigning truth,
preserves the whole OCR context, and restores an explicit status question if
only a secondary fact was extracted. Real identity/location/legal-scope gaps,
open-ended questions, proposals, opinions, and conditional claims retain their
existing protections. Directly relevant passages inside a fact-check can be
assessed as direct reporting; inheriting a published rating still requires an
exact claim match. A generic preset also no longer blocks time-insensitive exact
review lookup merely because it is nonempty. Fresh-status research is preserved.
The pipeline version is `evidence-v20`, excluding the older unresolved result
from fresh-submission reuse. Existing reports require a fresh recheck.

The user supplied the Rappler URL ending
`no-court-order-gma-network-shutdown-over-libel-complaint/`. Its article body was
not accessible through this session's browser tool. Frozen OCR and explicitly
synthetic article passages test this code path; no live factual verdict about
that article is claimed. The focused planner, evidence, matching, input-budget,
and meaning-guard selection passed 188 tests / 1,236 assertions. Regression
coverage rejects transferring an unrelated older Marcos shutdown rating and
preserves a specific user question's single target without adding competing
poster headlines. Artifacts: `storage/logs/truthguard-headline-focused.xml` and
`storage/logs/truthguard-headline-final.xml` / `.txt`.
The final full run completed 824 tests / 5,591 assertions, with the identical
24 failing case names from the cost follow-up, zero errors, four skipped, and
two risky. No frontend files changed in this follow-up; Pint and diff checks
passed. This fix is packaged as
`fix: verify question-framed headlines against full submission` under the user's
existing authorization to commit and push the fact-checking fixes to `main`.

Report-voice follow-up: generated research narration such as "I researched",
"I checked", and "I reviewed" is rejected by the presentation contract, and
those legacy narration sentences are omitted while preserving the actual
finding and source IDs. Static verification/context failure messages now use
neutral report language. This cleanup requires no additional model request.
The focused wording, public-contract, generation, evidence, and headline checks
passed 88 tests / 592 assertions. That presentation follow-up used the earlier
806-test full-suite baseline without rerunning the suite at that stage.

- Matching is deliberately conservative. Unrecognized cross-language paraphrases
  can remain unresolved; shared keywords are not enough to inherit a verdict.
- Missing, blocked, truncated, or unindexed publisher content still limits evidence.
  Decisive reconciliation requires retrieved text and structured published-review
  metadata; a bare headline cannot manufacture a rating or a passage.
- The overall exact-review override intentionally avoids multi-claim reports;
  those continue through individual evidence relationships and aggregation.
- Perceptual reuse requires a matching dHash, dimensions, and conservative pixel
  comparison. Some resized/recompressed equivalents will require new analysis.
  GD absence safely falls back to byte identity.
- Denial detection and concrete narration-detail checks are conservative language
  guards, not a formal proof of semantic entailment for arbitrary model prose.
- Video remains sampled-frame/face screening and does not establish complete
  motion, audio, provenance, or factual truth. There is no second independent
  whole-video origin detector in this change.
- These tests establish control flow and safeguards, not empirical detector accuracy,
  production provider availability, or the truth of the example real-world claims.
  Accuracy is not guaranteed.

### Reviewed database answers and deployment follow-up

The narration commit changed report wording; it did not repair database lookup.
Read-only inspection of the actual local database found an active, unexpired
`suan-fabricated-bato-shoot-to-kill-quote` reviewed answer (ID 7, False), with
eight approved variations. The whole-submission hash lookup misses when a known
assertion is accompanied by other OCR regions. After planning, the pipeline did
not previously consult these reviewed answers for individual assertions.

`evidence-v21` now checks complete, literal planned assertions against approved
database variations before researching unresolved assertions. Saved True/False
findings participate in the ordinary per-claim aggregation; one saved answer
cannot resolve additional unsupported claims. No fuzzy keyword verdict matching
was added. Inactive/expired reviews, reviews newer than the requested cutoff,
changed scope, context-bound dates/qualifiers and unresolved references remain
ineligible. Other saved rating categories retain their existing whole-submission
reuse and evidence/context paths rather than being converted into binary findings.
Forced refresh, fresh-status research and context requests retain research.
An exact text caption no longer skips reading an attached image or a separate
specific question. Provenance appears in `diagnostics.reviewed_answers`, and
saved findings are marked `reviewed_database`, never falsely reported as newly
retrieved article bodies. OCR/planning usage remains counted.

The production entrypoint migrated the tables but seeded only reviewed media.
It now also installs missing packaged reviewed text answers. The text seeder
preserves existing results, disabled state, expiry dates, approved variations
and variation ownership. Production contents have not been inspected; missing
production rows are a deployment risk established from the startup script,
not a claim about the live database.

A read-only check against actual local review #7 used the wrapped uploaded
headline plus separate date/publisher regions. The old whole-text match was
false; the new per-claim match returned False, the correct reviewed ID/slug,
and zero lookup API tokens. Regression tests use real database models, lookup,
planner postprocessing, aggregation and report formatting with external OCR and
model responses fixed as test inputs. They do not reproduce a live model run.
Final focused checks passed 58 tests / 286 assertions. The full run before the
final region/scope guards completed 832 tests / 5,627 assertions, 24 failures,
zero errors, four skipped and two risky. XML comparison confirmed all 24 failing
case names are identical to the preceding 824-test audit. Artifacts:
`storage/logs/truthguard-reviewed-database-final.xml` / `.txt` and
`storage/logs/truthguard-reviewed-database-focused.xml`. Pint and diff checks
passed; no frontend changes or environment/model/limit changes were made.

The separate `public_claim_reviews` feed is discovery metadata, not the curated
reviewed-answer table. Some stored `full_claim` values contain article
introductions, including denials and background, rather than a literal reviewed
proposition. This change does not turn those introductory paragraphs or bare
source-card titles into decisive evidence. Retrieved text, exact scope and
published-review validation still apply to that path. Existing saved reports
require a recheck after deployment; they are not rewritten by this patch.
