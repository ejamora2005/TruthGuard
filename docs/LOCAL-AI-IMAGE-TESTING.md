# Local AI-image detector trial

The optional detector is `Bombek1/ai-image-detector-siglip-dinov2`, release `6455cf791436ee914c9556ab71578cce9761fef7`. The local CPU runtime is `.venv-ai-origin/Scripts/python.exe`, and the checkpoint is `automation/models/ai-origin-broad/pytorch_model.pt`. The adapter checks the pinned checkpoint/config hashes and does not download files while classifying an upload.

Local `.env` settings:

```dotenv
AI_ORIGIN_ENABLED=true
AI_ORIGIN_MODEL_PATH=automation/models/ai-origin-broad
AI_ORIGIN_PYTHON_BINARY=.venv-ai-origin/Scripts/python.exe
AI_ORIGIN_TIMEOUT=240
```

After changing these settings, run `php artisan config:clear`. Restart a long-running local worker if one is running. The production template remains disabled until deployment dependencies, memory and model delivery are prepared.

## Try an upload

1. Open the local Fact Check workspace and choose **Check for AI-made media**.
2. Upload an original image and submit the filled question. General fact-checking remains a separate requested task.
3. Open **View details** on the new report. **Media detector** should identify **OpenFake SigLIP2 + DINOv2 AI-origin detector** with status **Completed**.
4. Images use only this AI-origin detector. MobileNet image screening is no longer run or used as a fallback; video retains its separate face detector. A disabled or failed origin detector produces an unavailable result. Historical reports keep their original readings. The retired single-backbone SigLIP adapter and metadata have been removed; its previously downloaded weights are not loaded.
5. Review several independently documented camera and AI-generation examples. Record wrong predictions and inconclusive results, plus latency. Do not use previous TruthGuard verdicts as labels. A few successful examples do not measure accuracy or calibrate confidence.

The published classifier decision threshold and existing selective/inconclusive policy are unchanged. Without a matching validated artifact, the class score remains uncalibrated. A reviewed Platt benchmark artifact displays **Calibrated model score** and its benchmark scope; it does not claim deployment-wide confidence. This model screens image origin; it does not verify a depicted event or a news caption. Video keeps the existing video detector.

CPU inference can be slow, particularly with little free memory. Process one image at a time and avoid duplicate submissions while one is running. If analysis does not complete, inspect the saved detector status rather than treating failure as a real-image finding.

The detector itself consumes no OpenAI tokens. Normal UI submissions may still use OpenAI for the configured short explanation, and factual checks can additionally use extraction and research. Offline integration tests disable those requests. Opening a saved report does not generate another explanation.

Do not commit `.env`, downloaded checkpoint weights, uploads, test outputs or saved reports. Commit the reviewed adapter/configuration/UI changes only after the trial; deploying the checkpoint needs a separate model-delivery plan.

## Calibration preparation

Calibration is not enabled until genuine known-origin data passes the existing validation checks. The repository's dataset folders are empty, and the example manifests contain placeholders. Prior TruthGuard scores and visually guessed labels are not ground truth.

The current calibration gate requires at least 100 independently grouped original camera images and 100 independently grouped AI images in **each** of the calibration and untouched test sets: at least 400 reviewed images in total. Keep images from the same source, generation session, or duplicate family in a single split. Document origin, review, image hashes, training independence, and whether the population represents expected TruthGuard uploads. This project minimum does not guarantee universal calibration.

Use `automation/evaluation/image-calibration.annotations.example.jsonl` and `image-calibration.population.example.json` for the required fields. Do not mark review or training-overlap checks complete until they have actually been performed.

With reviewed manifests available, capture predictions offline and fit a new artifact:

```powershell
.\.venv-ai-origin\Scripts\python.exe automation/evaluation/capture_image_calibration_scores.py --annotations path/to/reviewed-labels.jsonl --output path/to/new-scores.jsonl
.\.venv-ai-origin\Scripts\python.exe automation/evaluation/calibrate_image_scores.py --annotations path/to/reviewed-labels.jsonl --predictions path/to/new-scores.jsonl --population path/to/reviewed-population.json --output path/to/new-calibration.json
```

Enable `MEDIA_CALIBRATION_ENABLED` only when the artifact reports `validated_on_supplied_population`, its model identity matches the deployed origin detector, and its SHA-256 is pinned in `MEDIA_CALIBRATION_SHA256`. Set `MEDIA_CALIBRATION_PATH` to that artifact. Failed validation leaves the raw model score uncalibrated. Do not repeatedly tune on the test set. Temperature and Platt calibration adjust scores; they do not retrain the model or change the existing verdict policy. Platt artifacts must preserve fitting and target runtime identities; benchmark-only validation uses the model-score label described in `ASSESSMENT-ACCURACY.md`.

`php artisan truthguard:models:health` now reports `image_origin` rather than the retired `image_cnn` health entry. The optional `--load` probe uses the origin model for images and the existing face detector for video; it does not measure calibration or accuracy.

## Colab result review: 16 October 2026

The returned `TruthGuard-GPU-results-22bebf65575dda12.zip` has SHA-256 `3377f55c5bcef8670b4856733b065c0054a9efc677349b41cb8dd15447ad18e5`. Its 2,994 unique originals were split into 1,495 calibration images and 1,499 test images. The reported temperature fit improved test log loss from 0.119739 to 0.112887; original-threshold test accuracy was 1,433/1,499 (95.60%). Camera images contributed 62 false positives out of 497. These are benchmark measurements, not validated confidence for TruthGuard uploads.

Deployment validation did **not** pass. The returned annotations have no completed independent image reviews or verified image-level training exclusion, and the population manifest is still a template. All 2,994 original paths were missing locally at review time. The Colab run used the CUDA FP32 adapter, while the application uses the CPU adapter with different precision. A comparison of 32 existing FLUX calibration predictions found a maximum score difference of 0.00036341 and no class-decision changes; that narrow comparison does not validate equivalence across camera images, generators or the test population.

`MEDIA_CALIBRATION_ENABLED` therefore remains `false`. The runtime rejects experimental artifacts and mismatched adapter identities. Keep the returned benchmark sealed; do not tune another method against its inspected test results. Restore original files, complete genuine provenance and population review, and validate the actual deployment runtime with appropriately independent evaluation data before enabling calibrated confidence. Passing software tests alone cannot satisfy these data requirements.
