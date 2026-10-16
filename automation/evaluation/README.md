# Held-out fact-check evaluation

## Factual and context verdict reliability

The application now supports optional empirical calibration of agreement with independent reviewers, separately for each assigned task/verdict. This answers “How often did independently reviewed cases receiving this verdict agree with it in this population?” It is not the probability a statement is true. A False verdict can have high agreement; the evidence heuristic is never relabeled as a probability. Unverified, conflicting, missing-context and technical outputs do not acquire confidence badges.

No reviewed calibration dataset or live fitted artifact is bundled. The fresh live checks and synthetic software tests must not be used as calibration labels. Obtain independent evidence review, with exact submitted text, fixed UTC cutoff, evidence URLs/quotes in `review_basis`, reviewer/date and event groups. Use the existing task annotations schema (`factual` or `context`) plus `label_source="independent_evidence_review"` and `independent_of_development=true`. Include matching `source_url` and original `media_path` / `media_sha256` when applicable. Labels must reflect the task and the evidence available at that cutoff, not previous TruthGuard responses. The program cannot prove reviewer independence from a checkbox.

Capture new predictions without reading gold labels into the analyzer or altering saved detections:

```powershell
php artisan truthguard:capture-evidence data/reviewed-cases.jsonl data/frozen-evidence-v1.jsonl
python automation/evaluation/calibrate_evidence_verdicts.py --annotations data/reviewed-cases.jsonl --predictions data/frozen-evidence-v1.jsonl --population data/evidence-population.json --output data/evidence-calibration-v1.json
```

The capture command makes real API/search requests and writes a separate full report/source archive next to the predictions. It does not update existing detections; the evidence pipeline may create canonical analysis records. Input text hashes, original media hashes and a pipeline identity bind predictions to the reviewed inputs. Capture `claim` task names are accepted as `factual` by the evaluator. Each report remains a fresh prediction, not a gold label. Failures stay in the audit. Avoid resuming an interrupted run by overwriting files; use a new versioned capture.

Population JSON requires an honestly reviewed `scope` string (at most 300 characters), `representative_of_deployment=true` and `independent_review=true`. Keep related events, images and paraphrases together. This conservative fitter permits one observation per independent event and requires separate calibration/test groups; at least 100 observations, including at least 10 agreements and 10 errors, in each split for each deployed task/verdict cell. These are engineering gates, not guarantees of adequate power or universal calibration.

Each cell is fitted only from calibration labels using `(agreements + 1) / (count + 2)`. Test labels are used only to validate: agreement must be within 0.05 of the fitted estimate, the estimate must lie inside the held-out 95% Wilson interval, and that interval must be at most 0.2 wide. Reports include held-out Brier score and uncertainty. A rejected or undersized cell cannot be enabled. Do not reuse a failed test cohort to select a replacement method.

Only passing cells from a genuine `validated_on_supplied_population` artifact can be enabled with `EVIDENCE_CALIBRATION_ENABLED=true`, `EVIDENCE_CALIBRATION_PATH` and the printed `EVIDENCE_CALIBRATION_SHA256`. Clear config and restart workers after configuration changes. Runtime rechecks minimum counts, held-out gates, artifact integrity and exact pipeline identity (source code, evidence policy, model). Changes to routing, prompts, evidence policy or the analysis model invalidate the profile. Public tooltips describe verdict reliability and its tested population, not measured system accuracy. Source access or population drift requires fresh validation. Run `php artisan truthguard:evidence-doctor` to inspect readiness without exposing keys.

## Image score calibration

### Publisher-labeled benchmark experiment

For a downloaded known-origin benchmark whose independent review or training
exclusion remains unresolved, run the separate offline experiment instead of
marking review fields complete:

```powershell
.venv-ai-origin/Scripts/python.exe automation/evaluation/benchmark_image_calibration.py --annotations path/to/annotations.draft.jsonl --output-dir storage/tmp-benchmark-v1 --threads 3
```

It captures the pinned detector's original scores, fits temperature on the existing
calibration split, and evaluates once on the untouched test split. Matching scene
groups and exact duplicates must already be grouped and deduplicated. A completed
report is immutable. After an interruption, repeat the command with `--resume`;
the manifest, model identity, and captured scores must match. Interrupted malformed
records are rejected rather than silently repaired. No network access is allowed
during inference. Progress reports show timing, not test performance.

The report always has `status=experimental_benchmark_only`,
`deployment_eligible=false`, and `validation.passed=false`. It records benchmark
improvement separately from the unresolved review requirements, preserves the
original annotation flags, and cannot enable live confidence. The reviewed fitter
below remains the only deployment-artifact path. Public benchmark results alone
do not establish calibration on real user uploads.

To prepare a portable Google Colab notebook and a small upload ZIP:

```powershell
.venv-ai-origin/Scripts/python.exe automation/evaluation/build_colab_calibration.py --annotations storage/tmp-calibration-download-audit/annotations.draft.jsonl --output-dir storage/tmp-colab-calibration
```

Upload the generated notebook in Colab, select a GPU, and run its cells in order.
The ZIP contains only an explicit code/config whitelist and the portable annotation
manifest. It excludes images, weights, credentials and `.env`. Colab downloads the
two official archives with pinned MD5 checks and the unchanged model at its pinned
revision/SHA-256. Scores and progress go to an authorized Google Drive folder;
image/model downloads stay on the temporary VM. No OpenAI API calls are made.

The separate CUDA FP32 benchmark adapter supports GPUs without native BF16 such
as T4. It does not change the application adapter. CUDA receives its own adapter
identity and hardware/library profile, and cannot resume from CPU captures. Drive
run folders include the environment and bundle fingerprints so incompatible runs
remain separate. Reconnecting to the same profile resumes verified saved scores.
The output remains experimental and must not be enabled on the CPU detector.
Actual Colab GPU execution must be checked in Colab; local packaging tests do not
measure CUDA inference speed, numerical agreement, or calibration quality.

To import a completed Colab result ZIP for local review (no new inference or API
calls), keep the original notebook bundle and run:

```powershell
.venv-ai-origin/Scripts/python.exe automation/evaluation/review_colab_results.py --archive C:/Downloads/TruthGuard-GPU-results-RUN_ID.zip --bundle storage/tmp-colab-calibration/TruthGuard-calibration-bundle.zip --output-dir output/calibration/gpu-RUN_ID
```

The importer checks archive contents/CRC, complete score capture, original bundle
code and labels/splits, exact local checkpoint/configuration, and reproduces the
existing temperature fit and held-out metrics. Numeric comparison allows tiny
Linux/Windows math differences; counts and split identities must match exactly.
It refuses to overwrite prior imports and saves the unchanged returned files,
original ZIP, machine-readable `review.json`, and readable `review.html`.
This verification does not constitute independent label/population review or
authorize transferring GPU FP32 calibration to CPU BF16. No settings, verdicts,
thresholds, or saved user reports are changed by the importer.

The completed `22bebf65575dda12` T4 run was imported locally on 2026-10-16:
2,994 unique images, with 1,495 calibration and 1,499 held-out test images.
At the original raw 0.5 threshold, 1,433/1,499 test images were classified correctly
(95.60% on this benchmark): 435/497 camera, 501/501 FLUX and 497/501 SD3 images.
Temperature 0.757461938 improved held-out log loss from 0.119739 to 0.112887,
Brier score from 0.033585 to 0.032684 and 10-bin calibration error from 0.052621
to 0.042794. The paired log-loss-difference 95% interval was
[-0.011375, -0.001837]. Temperature scaling did not change classification accuracy.
These numbers describe the supplied original-file benchmark and its class ratio,
not the live CPU service or all user uploads. Independent review, training overlap,
deployment-population suitability and exact-runtime calibration remain unresolved;
live calibrated confidence remains disabled. The original final test split stays
sealed rather than being reused to choose a new method.

The application supports a separate, optional image-origin calibrator. No validated live calibration artifact is bundled. The dataset directories currently contain placeholders; synthetic unit-test artifacts and the nine-image smoke set are not calibration evidence.

1. Copy `image-calibration.annotations.example.jsonl` and `image-calibration.population.example.json` to a working dataset folder. The examples deliberately fail review validation until replaced with real records. Supply independently reviewed real-camera and AI-generation provenance, original file paths/hashes, training exclusion and a reviewed population/prevalence scope. Never use a previous detector verdict as a ground-truth label.
2. Group related captures, prompts, source scenes, crops and recompressions together. Reserve separate calibration and test groups before inference. At least 100 independent groups of each class in each split are required for the deployment gate (400 groups total); these minimums do not guarantee calibration. The final test set must remain untouched while selecting the method.
3. Capture the exact pinned model's original, unrounded outputs and fit temperature scaling:

```powershell
.venv-ai-origin/Scripts/python.exe automation/evaluation/capture_image_calibration_scores.py --annotations data/labels.jsonl --output data/frozen-scores-v1.jsonl
python automation/evaluation/calibrate_image_scores.py --annotations data/labels.jsonl --predictions data/frozen-scores-v1.jsonl --population data/population.json --output data/calibration-v1.json
```

The fitter uses only calibration labels to learn temperature, and evaluates on the test labels once. Its report includes before/after log loss, positive-class Brier score, ECE, reliability bins, independent group counts and a paired cluster-bootstrap log-loss interval. Weights, raw labels, the model threshold and the existing abstention rule remain unchanged. Rejected or undersized fits stay `fitted_not_validated` and cannot be enabled by the runtime. Output files refuse overwrite to preserve the evidence trail. Do not tune another method on the same final test set after inspecting results.

Only after a genuine reviewed run exports `validated_on_supplied_population`, set `MEDIA_CALIBRATION_ENABLED=true`, `MEDIA_CALIBRATION_PATH` to the versioned artifact and `MEDIA_CALIBRATION_SHA256` to the hash printed by the fitter. Then clear Laravel's config and restart queue workers. The runtime requires exact model, config, processor and adapter identity matches. It shows Confidence only for a validated, applicable artifact; the recorded explanation names its population. A changed checkpoint or preprocessing requires fresh calibration and evaluation. Dataset population and real/AI prevalence must match the intended uploads: public benchmark labels alone do not establish deployment suitability.

Run `python -m unittest discover -s automation/evaluation -p 'test_*.py'` and `php vendor/bin/phpunit tests/Unit/MediaScoreCalibrationTest.php` to test math, split leakage protection, artifact integrity and presentation. These synthetic tests do not measure detector calibration or accuracy.

No independently labeled claim-level evaluation set was found. `annotations.template.csv` deliberately contains only headers. No labels or performance results are generated. The four synthetic unit tests exercise this evaluator, not TruthGuard accuracy.

## Prepare the labels

Copy the template to a separately reviewed dataset. For every claim record its exact original wording/language, posting date if known, an immutable UTC evaluation cutoff, event/duplicate/media groups, split, gold label, reviewer, review date, original evidence URLs/quotes, and annotation notes. Use `human_reviewed=true` only after actual human review. Independent review and adjudication are recommended; the program cannot verify a person's independence from a CSV field.

Keep related events, paraphrases, syndicated reports, and related images/videos in the same split. The script rejects shared event/duplicate/media groups and normalized exact claim text across splits. Reviewers must assign paraphrase groups; the script cannot detect every semantic duplicate. Tune thresholds/mappings only on validation data. Seal the final test set before comparing systems.

Six labels are fixed:

| Label | Meaning |
| --- | --- |
| `supported` | Direct evidence supports the material factual assertion(s). |
| `refuted` | Direct evidence contradicts the material factual assertion(s). |
| `mixed` | Identifiable factual parts have different established outcomes. |
| `insufficient` | Evidence is absent, incomplete, or unresolved/conflicting. |
| `needs_context` | Missing wording/date/scope prevents a meaningful assessment. |
| `not_fact_checkable` | Opinion, question, prediction, or non-factual expression. |

`insufficient` and `needs_context` count as abstentions. For headline ambiguity, document why a label was chosen instead of silently treating a headline as a factual assertion. Set a cutoff before retrieval and freeze accessible source content/metadata; later articles must not leak into either system's inputs.

## Capture comparable predictions

Create one JSONL file per system, with exactly one object for every test `claim_id`. Use the same claims, cutoff strings, and reviewed labels across files. Required fields are `claim_id`, `evaluation_cutoff`, and `label`. Optional observed fields are `latency_ms`, `search_requests`, `page_requests`, `input_tokens`, and `output_tokens`; omit missing measurements instead of estimating them.

Human evidence review can additionally supply `evidence_reviewed_by`, `retrieval_relevance` (a boolean judgment per retrieved item), and `citation_correctness` (a boolean judgment per citation). Define the review rubric and denominators before annotation. Do not fill these from the model's own match flags.

For the enhanced pipeline, map `assessment_label` as follows: Supported to `supported`, Refuted to `refuted`, Mixed to `mixed`, Insufficient evidence to `insufficient`, Needs context to `needs_context`, and Opinion / Not fact-checkable to `not_fact_checkable`. Preserve both raw outputs and mapped files.

For the legacy pipeline, freeze a policy before testing: TRUE to `supported`, FALSE to `refuted`, PARTLY_TRUE to `mixed`, UNVERIFIABLE to `insufficient`. MISLEADING and OUTDATED require a reviewed semantic mapping or a compatible restricted cohort defined in advance; the evaluator rejects raw/unmapped labels. Never drop inconvenient predictions after seeing outcomes. Run legacy and enhanced predictions without overwriting production reports.

The trained NLP model's “Likely Real/Fake News” labels describe article-style classes. They are **not** automatically equivalent to factual support/refutation. Compare it alone only on an independently annotated compatible task/cohort with a validation-defined mapping and documented coverage. The enhanced system intentionally keeps that model diagnostic separate, so its evidence-only and combined factual verdicts are identical by design; there is no justified claim of an ensemble accuracy gain.

## Run

From the repository root, using Python 3.10 or later:

```powershell
python -m unittest discover -s automation/evaluation -p 'test_*.py' -v
python automation/evaluation/evaluate.py --template reviewed-claims.csv
python automation/evaluation/evaluate.py --annotations reviewed-claims.csv --predictions legacy.jsonl enhanced.jsonl --output comparison.json
```

The template command refuses to overwrite a file. The comparison requires matching prediction IDs and cutoffs; unknown labels, duplicate IDs, missing predictions, or split leakage fail rather than silently shrinking the sample. With an empty annotation template the result is `unmeasured`, sample size zero.

The report includes sample size/distribution, overall accuracy including abstentions, per-class precision/recall/F1, macro precision/recall/F1 over all six classes, confusion matrix, abstention rate, answered count/accuracy, observed latency/API usage, and separately reviewed retrieval/citation metrics. Undefined per-class metrics are zero and absent operational measurements are null. Always publish answered accuracy with coverage and overall accuracy; never remove abstentions to inflate results. The annotation SHA-256 identifies the supplied label snapshot. No thresholds are fitted by the evaluator.
