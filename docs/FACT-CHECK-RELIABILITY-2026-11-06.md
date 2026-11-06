# Fact-check reliability investigation — November 6, 2026

Status: development diagnostics completed; independent deployment accuracy is **not measured**.

## What was run

The working application made real provider/model requests using the configured
GPT-5.4 analysis, planning, extraction and search models. The four fixed limits
requested by the owner were zero in these CLI processes. No `.env` settings,
weights, live confidence calibration, or deployed production service were changed.
The local application URL is localhost; this investigation does not certify
Dokploy's deployed version or workers.

Read-only collection produced **353 candidate claims** from the local public
review database and three bounded public Fact Check API requests. They contain
255 candidate False labels, 19 Misleading, 9 Missing Context, 4 True and 66
unmapped labels. These are publisher metadata and stored answers, not independent
review of the exact submitted wording. All human-review flags remain false.
The pool is heavily imbalanced and its event grouping needs review.

Four live diagnostic runs contain 22 complete checks of 11 distinct text/media
inputs. They deliberately include development cases and related variants.
The initial GMA transcript reproduced Missing Context at 20,517 reported tokens.
Two intermediate attempts remained unresolved; those failed attempts are retained.
The final complete live run returned:

| Input | Outcome | Reported model tokens |
| --- | --- | ---: |
| GMA poster transcript, retaining its OCR wording | False | 11,712 |
| Original locally saved Suan image | False | 739 |
| PAGASA's stated annual PAR average | True | 9,649 |
| Constructed tenfold alteration of that average | False | 26,406 |

Earlier controls returned True for the documented Apollo 11 landing and Martian
moons, and False for constructed year/count alterations. These few source-checked
development examples do not establish representative accuracy or calibration.
The GMA original upload is unavailable locally: its case is a **text transcript**,
not a live OCR reproduction. The Suan case uses the original local image/hash.

One initial PAGASA control returned Unverified because the pilot's earlier fixed
cutoff correctly excluded an undated page. This was a live-benchmark setup error,
not proof that the underlying statement was false. Live captures now explicitly
record their actual per-case cutoff; fixed historical evaluations retain their
stricter source-date policy. Reports separate these modes.

The four runs used **209,757 provider-reported tokens in total**, including the
unsuccessful investigations. A final live model review against frozen source
records used another **9,936**, returning refuted for both GMA status candidates.
Total observed model usage: **219,693 tokens**. This is not a dollar invoice;
cached input pricing and search-tool charges require billing records. No full
353-case paid run was made before independent review.

## Defects repaired

- An initial identity/context flag prevented usable retrieved evidence from
  establishing the meaning of a quoted post. `evidence-v22` can bind an ambiguous
  subject to a publisher's named reviewed subject when the actual article quotes
  the heading and a separate phrase together. Binding preserves the original
  claim, dates, numbers and negation; it does not assign a verdict. A normal
  validated relationship still needs a literal supporting/refuting passage.
- Shared contiguous phrases can identify an OCR-corrupted subtitle without
  rewriting its wording. A subjectless fragment can become checkable only when
  it has an explicit status/decision term. Unrelated source passages, untrusted
  publisher URLs, conflicting subjects, missing dates/outcomes and photograph
  identity questions cannot use this automatic binding. Optional model-proposed
  bindings have their own literal grounding checks and request-local lifetime.
- Greeting/farewell-only headlines naming an entity remain context rather than
  invented claims of dismissal or appointment. Extra explicit factual wording is
  preserved. Source quotations and identity bindings remain auditable.
- Automatic image routing can reuse a reviewed answer after OCR selects the
  factual task. Whole-submission reviewed answers cannot use a review written
  after the requested cutoff.
- Guide-only submissions are rejected without paid analysis, including previously
  shipped shortcuts. Failed retries show an unavailable result instead of stale
  findings while preserving the prior saved evidence. Queue-mode pending resume
  remains owner-scoped; synchronous mode does not resume background work.
- The projected safe historical summary takes precedence over old task-choice
  narration. Recommended actions do not repeat the explanation. Exact-file origin
  provenance survives as a method reference rather than disappearing from a
  media-only report or becoming factual-claim evidence.

## Software tests

Queue execution settings are isolated in PHPUnit rather than inherited from the
developer's `.env`. Explicit queued tests still enable the evidence queue. Obsolete
assertions were updated to the existing report contract: image submissions can be
factual reports; borderline/blank media findings are inconclusive; unfinished
reports redirect to progress; reused findings retain the same evidence but do not
repeat historical token usage. These changes do not restore unsafe old verdicts
or turn the tests into an accuracy benchmark.

The final full run completed **862 tests / 5,949 assertions with zero failures,
zero errors and four skipped tests**. Validation artifacts are under
`storage/logs/truthguard-reliability-final-v2.*`. The evaluation metric checks
also passed 15 Python tests (including the pre-existing assessment evaluator).
The Python metric tests cover abstention/coverage, source retrieval versus use,
input/hash/cutoff binding, reuse separation, unavailable measurements and rejection
of incomplete independent-review declarations. New evidence guards test original
literal source text and source boundaries, including adversarial mismatches.

## Reproduce the diagnostics

The local, ignored artifact directory is
`output/validation/fact-check-2026-11-06-v1/`. It contains immutable candidate
manifests, all four predictions/source archives, per-run JSON/CSV/HTML reports,
the original Suan hash, a blind independent-review CSV, frozen-source replays and
the investigation summary. Original detections are not overwritten by capture;
normal canonical/source caches may be populated by the application.

Prepare a new candidate export without model enrichment or database writes:

```powershell
php automation/evaluation/prepare_fact_check_cases.php --output=path/to/new-cases.jsonl --google-pages=1
python automation/evaluation/prepare_fact_check_review.py --cases path/to/new-cases.jsonl --output path/to/new-review.csv
```

Validate before making paid requests, then capture a small pilot:

```powershell
php artisan truthguard:capture-evidence path/to/cases.jsonl path/to/new-predictions.jsonl --dry-run
php artisan truthguard:capture-evidence path/to/cases.jsonl path/to/new-predictions.jsonl --take=6
python automation/evaluation/audit_fact_checks.py --cases path/to/cases.jsonl --predictions path/to/new-predictions.jsonl --reports path/to/new-predictions.jsonl.reports.jsonl --output-dir path/to/new-report
```

Outputs cannot overwrite an earlier capture/report. All media hashes and dates
are checked before any paid case runs. Labels, review notes and expected source
URLs are never sent to the analyzer. `--force-research` explicitly tests fresh
research instead of saved-answer reuse. Default fixed-cutoff cases must have a
valid, nonfuture ISO UTC `evaluation_cutoff`. For a live development check, declare
`cutoff_policy="capture_time"`; predictions and archives record both the declared
and actual cutoff. These live outputs need review at their actual cutoff before
being used with the stricter independent-label evaluator/calibrator.

## Remaining release gate

Select and freeze at least 200 independently reviewed event groups before a final
run. Add enough true and genuinely unresolved cases to assess wrong-False errors
and abstention; the four True publisher candidates are insufficient. Include
Filipino and English, original screenshots, multiple assertions, dates, attribution
and qualifiers. Keep related events/images/paraphrases together. Exclude these
development failures and their event variants from the final held-out group.

Reviewers must verify exact claim scope, expected verdict, date cutoff and literal
source passages, and record their names, dates and evidence basis. Do not fill
those declarations from a model prediction or a publisher rating alone. The blind
review sheet contains no TruthGuard predictions. Define acceptable error and
answerable-coverage thresholds before the held-out run; report class results,
uncertainty, citation entailment, cost and failures. The current audit intentionally
returns `release_ready=false` and does not enable a Confidence percentage.

Fresh deployment checks, the HTTP/worker/browser path and continuing source
availability must also be verified on Dokploy. The local software tests and
service-level captures are evidence for their recorded scope only.

## Report 108 cache investigation

Read-only diagnostics supplied from production establish that report 108 used
`evidence-v22`, read the exact poster headline correctly, selected the claim task,
and was neither a reused report nor a retry. Both the OCR and literal-headline
review lookups returned null. The canonical Suan assertion returned False.
Production's Suan review is active, reviewed November 4, expires December 1,
and contains five sources and five variations. Its variations omit the exact
poster headline and two subsequently approved publisher-title variants. The
current source-controlled definition contains eight variations. The deployment
seeder intentionally skips existing reviews, so updating its definitions did not
install these new variations on the existing production record. This is the
confirmed database lookup cause for report 108; the cache defect below is separate.

`truthguard:install-reviewed-variations <slug>` now previews missing bundled
variations for one existing review. Its explicit `--apply` mode installs them
without replacing sources, summaries, verdicts, lifecycle settings or reports.
It requires a current active review with the same canonical claim and verdict,
requires saved sources, rejects variation ownership conflicts transactionally,
and is idempotent. Ordinary deployment seeding still preserves reviewer edits
and deletions. This operational command explicitly restores the listed approved
variations; it is not run automatically on every deployment.

A separate reproducible defect was found in the submission cache: a completed
Unverified report could be returned before the reviewed-answer database was
consulted, even after the matching answer or variation was approved. Conversely,
a cached reviewed finding could survive withdrawal, revision or expiry. With the
new dependency check removed, both approval regression cases fail by reusing the
original unresolved report.

Fresh reports now record a signature of relevant approved-answer dependencies.
Cache admission checks the current registry, including expiry transitions without
a database write. Dependencies include submitted/extracted text, complete OCR
regions, planned claims and the existing approved catalog alias. Unrelated review
changes do not invalidate the submission; pure media cache behavior is preserved.
The signature is captured before analysis, so a review change during analysis
also prevents subsequent reuse. This check invalidates a stale candidate; the
normal evidence pipeline still decides the new result. It does not introduce
fuzzy verdict matching, bypass explicit fresh-research requests or rewrite saved
reports on page load.

Targeted verification passed **74 tests / 550 assertions**, covering the installer,
stored-result reuse, reviewed lookup and integration, queued evidence and detection
pipeline suites. The integration regression uses the real evidence service and registry, with
external OCR/planning responses fixed: the approved headline variation is missing,
an unresolved image is cached, the installer restores the variation, the next
submission obtains its reviewed False finding, and a further repeat records zero
new tokens. Other cases cover adding
a variation, disabling, editing, removing and expiring a review, unrelated edits,
and preserving historical records. No paid model calls were made for these tests.
These tests verify software behavior, not a production deployment or measured
fact-check accuracy.

After deploying the command and cache fix, run inside the application container:

```sh
cd /var/www/html
php artisan truthguard:install-reviewed-variations suan-fabricated-bato-shoot-to-kill-quote
php artisan truthguard:install-reviewed-variations suan-fabricated-bato-shoot-to-kill-quote --apply
```

Then submit the original image as a new normal fact check. Historical report 108
is retained; loading its page does not manufacture a new assessment. An explicit
fresh-research request intentionally tests research rather than reviewed-answer
reuse. This repair adds no environment settings and makes no model calls itself.

## Image-specific AI-media explanations

The media explanation request previously received only the detector class, score
and limitations. It could not truthfully describe the uploaded image. Long saved
narration and appended limitations then filled the main explanation with detector
terminology. Factual claim explanations and verdict logic are unchanged by this
follow-up.

Image uploads now pass the original file to the existing media explanation stage.
Its SHA-256 must match the detector input before the bounded image preparer runs.
One vision request asks for a short scene description and up to two located,
visible observations. The observer receives neither the detector label nor its
score. It cannot return a verdict, citations or percentages. Uncertain observations
are omitted, and origin, metadata and detector-attribution assertions are rejected.
The server combines accepted observations with the existing detector finding;
neither the observer nor its description changes the verdict or score.

The main explanation names the visible subject and details in plain language.
Possible artifacts are identified as possible issues. If no visible flaw explains
a positive detector result, it says so rather than manufacturing a justification.
Visible features are observations, not a claim that those features caused a neural
network's prediction. A vision model can still misdescribe pixels; these checks
do not independently establish observation accuracy or image provenance.

Saved observations are displayed only with the matching original detector-input
hash. New uploads and reused results preserve that binding. Historical reports
without observations get a short detector explanation that explicitly says visual
details were not recorded. GET/JSON views perform no model calls or database
rewrites. The existing Details area retains recorded limitations and detector
information; they are no longer appended wholesale to the main media paragraph.

There is at most one optional image-observation request, without hosted search or
paid retries. It replaces the text-only narration for supported image uploads and
can now run for a completed detector whose origin policy is inconclusive. Earlier
versions skipped narration for that case. Image inputs add billable input tokens;
actual returned usage remains recorded, while saved-result reuse records zero new
tokens. The existing explanation enable switch and output budget are retained.
Image preparation or binding failures preserve the assessment without requesting
a guessed visual explanation. See the official [OpenAI image-input guide](https://developers.openai.com/api/docs/guides/images-vision)
for request format, billing and vision limitations.

Tests use fixed external responses, not paid model calls. They cover actual image
input inclusion, label-blind observation requests, hash mismatches, unreadable
inputs, rejected origin/provenance claims, uncertain features, unchanged scores
and verdicts, historical records, HTML/JSON rendering and zero-token reuse. A
pre-existing score-caption assertion also failed against the unchanged baseline;
it now expects the existing Model score label without changing score behavior.
Final targeted verification passed **125 tests / 1,135 assertions**, Pint and
the Vite production build. No paid model calls were used during verification.

## Text-only name expansion

The submitted text `shoot to kill order for bato dela rosa` exposed a discovery
defect: replacing the nickname inside a complete name produced a duplicate
surname. Sequential expansion also duplicated already-complete names such as
Lordan Suan and already-expanded agency labels. The interpreter now matches
complete names first and expands aliases in one pass, preserving original text
and its first search. An interpretation version participates in submission-cache
identity. This changes discovery wording only; it does not assign a verdict or
add Suan to an order question that does not name him.

Targeted validation passed **58 tests / 430 assertions** across interpretation,
question-focused planning, reviewed answers and result reuse. No paid model calls
were made. The screenshot confirms the text was accepted, but does not identify
which exact sources, passages or rejection reasons produced this report. The
search defect is confirmed; its role in that production result remains unproven.
