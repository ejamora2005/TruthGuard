# Image-analysis workflow repair — 2026-10-13

## Confirmed causes

1. **The detector failed before inference on Windows web requests.** The application log recorded `Fatal Python error: _Py_HashRandomization_Init: failed to get random numbers to initialize Python`, including at 14:30:33 and 14:32:20 on October 13. A local PHP `cli-server` reproduction showed `SystemRoot` present in the parent OS environment but absent from Symfony Process's filtered default child environment. Python exited with code 1 before loading the model. CLI tests alone did not reproduce that environment difference.
2. `DeepfakeCnnClassifier::classify()` collapsed disabled, missing-file, dependency and inference failures into `null`. The saved report could not explain why the CNN had not contributed.
3. `EvidenceVerificationService::apply()` always made a factual assessment primary and replaced the pipeline verdict. The automatic media instruction was supplied to the text-only claim planner and article classifier. The planner then described media as absent even though the original image was stored and displayed.
4. The result template always displayed the factual verdict; the media result was only a diagnostic signal. Original image storage was intact. Replaying the stored file in CLI succeeded, but did not establish that the original web inference succeeded.

## Implementation

- `app/Support/WindowsProcessEnvironment.php` restores only OS `SystemRoot`, `WINDIR`, `PATH`, `TEMP` and `TMP` for Windows subprocesses. It uses local OS environment values, not request headers or CGI variables. It does not disable Python hash randomization. Both CNN inference and OCR media preparation use it.
- `DeepfakeCnnClassifier.php` returns explicit completed/unavailable records, safe failure codes/reasons, runtime, task, input SHA-256, model weight SHA-256, configuration SHA-256, adapter version, raw output, label mapping and preprocessing metadata.
- `DetectionPipeline.php` infers against the persisted original upload. CNN output is retained in full instead of reducing it to a weighted signal. A new upload runs inference again; reading a completed report does not rerun inference.
- `SubmissionTask.php` separates the app's guided instructions from factual text. The composer submits an explicit `media`/`claim` task for guided prompts. With automatic routing, an image/video without a caption is a media request; a supplied factual caption/link remains a claim request. Both can retain separate component results.
- `EvidenceVerificationService.php` independently records media and optional claim assessments. Empty extracted text skips the article classifier for media requests. Text extraction/planning failures cannot erase a successful media result. Claim planning receives attachment/scope metadata so it does not mistake text-only access for a missing upload.
- `MediaAnalysisReport.php` produces deterministic wording from the actual detector output. No language model invents visual defects or changes the detector's label. Low synthetic signal is explicitly not proof of authenticity, absence of editing, or factual accuracy.
- Result Blade partials preserve the existing theme, original preview, zoom and speech controls. Media output is primary for media requests; factual findings, warnings, sources, classifier diagnostics and limitations remain in a separate optional section.
- `DetectionController.php` and `routes/web.php` add an owner/admin-only, CSRF-protected, rate-limited POST retry using the stored original. Concurrent retries are locked. Existing saved reports are not silently reclassified on GET; use **Retry media analysis** to replace an older failed assessment with a fresh run.
- `DetectionVerdictPresenter.php` keeps the new media labels consistent in history/notifications without displaying a fabricated confidence percentage.

Structured components are persisted separately as `fact_check_result.media_assessment` and `fact_check_result.claim_assessment` in the existing JSON column. Media-primary reports use schema version 3 and `primary_task=media`; factual report fields remain available in the claim component. No database migration or changed model weights are required.

## Model contract and limits

Deployment here is a **local Python subprocess**, not a separate container. The actual HTTP multipart test validated the incoming upload, stored original bytes, and passed the stored path to Python; its input hash matched the HTTP upload hash.

The deployed image weights are `automation/models/truthguard_image_model.keras`, SHA-256 `6fe84b2cbf8bdaafca4a2361feb30066355b3da121b78de2fac6fae4c77016eb`. The supplied model configuration records `0=real`, `1=fake`, positive class `fake`, and threshold approximately `0.475`. The loaded archive has one sigmoid output and input shape `(None, 224, 224, 3)`. Its graph contains division by 127.5 and subtraction of 1, consistent with the configuration's RGB float32 **0–255** input. The adapter must not normalize that input a second time.

The adapter decodes the original with Pillow, applies EXIF display orientation, converts to RGB, and retains the existing bicubic square resize. It checks model shape, activation, mapping and configured normalization before inference, and rejects invalid/non-finite scores rather than guessing a class index or applying a second sigmoid/softmax.

**Available:** loadable trained weights, runtime, class mapping, input-range contract and configured threshold. **Not bundled:** the MobileNetV2 training notebook, a reproducible threshold-selection/validation split, exact original resize/orientation policy, and a calibration study. `train_cnn.py` trains a different, simple CNN and is not proof of this MobileNetV2 model's complete training preprocessing. The model configuration contains historical metrics, not independently reproduced accuracy for current uploads. No threshold or calibration was invented, and no thresholds were tuned against these smoke samples.

## Real inference results

Provenance, download URLs and byte hashes are in `tests/Fixtures/media-analysis/provenance.json`. Assets are unchanged downloads. These two provenance-labelled examples are a small integration smoke set, not an accuracy benchmark.

| Sample | Documented origin | Raw synthetic-class score | Threshold | Returned class | Interpretation |
| --- | --- | ---: | ---: | --- | --- |
| Hugging Face astronaut-on-horse example | AI-generated | 0.0038230433 | 0.475 | real | **False negative / genuine model misclassification** |
| NASA AS11-40-5903 JPEG scan | Camera-origin | 0.0012472669 | 0.475 | real | Low AI-generation signal, consistent with origin |

The generated sample is published with the generation example in [Hugging Face's Stable Diffusion tutorial](https://huggingface.co/blog/stable_diffusion). NASA identifies the camera photograph and photographer in its [AS11-40-5903 record](https://eol.jsc.nasa.gov/SearchPhotos/photo.pl?frame=5903&mission=AS11&roll=40); the distributed scan is not asserted to be unedited.

The headline fixture also completed real inference; it has no authenticity ground-truth label. A corrupt PNG passed file-type validation but failed decoding and produced **Image analysis unavailable**, without a content verdict. The real HTTP upload also returned the generated sample's same score and matching SHA-256. That verifies transport/inference consistency, not detector accuracy.

## Verification performed

- 98-test focused PHP suite: **752 assertions passed**, including four opt-in real-model tests. Then the expanded workflow and Windows subprocess tests passed **11 tests / 110 assertions** (overlapping workflow coverage). Across these runs, 102 distinct PHP tests passed. This includes a media request with the legacy factual pipeline setting disabled, without sending its task instruction to the article classifier.
- Three Python adapter tests passed: actual configuration class direction, reversed mapping rejection, invalid scores/shapes/ranges, RGB channels, EXIF orientation and tensor dimensions. These are preprocessing/contract tests, not real model accuracy tests.
- A real PHP web-SAPI probe reproduced startup failure before the environment fix, then successfully started Python. A genuine multipart upload through that SAPI completed actual model inference after the fix. The temporary probe was loopback-only and is not an application endpoint.
- Headless Chrome checked media reports at 1920, 1536, 768, 390 and 320 px and the Fullscreen API: evidence first, contained preview, no horizontal overflow, displayed saved label, zoom/Escape, speech and expanded model metadata. Retry form submission was browser-tested with a mocked response; backend retry/ownership was separately tested through Laravel.
- Existing result-page browser coverage also passed for all six factual verdicts, image/video/PDF/link/text, portrait/missing/broken images, long prose/URLs, actual local video playback, source/navigation links and speech controls. No JavaScript errors.
- Existing voice/PWA JavaScript checks: five passed. PHP formatting, Blade compilation and whitespace checks were run separately.

Real-model test command (use the project's configured Python environment):

```powershell
$env:TRUTHGUARD_RUN_REAL_MEDIA = '1'
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/RealMediaInferenceTest.php
```

External OCR/search/article services are disabled in the real-model tests to isolate actual CNN transport and persistence. The headline/optional-text branch, service failure, queue and cache tests use explicitly mocked detector or text responses. Real CNN smoke outputs and browser artifacts are written under ignored `storage/tmp-media-inference/`. Regression success must not be reported as real-world fact-checking or AI-detection accuracy.
