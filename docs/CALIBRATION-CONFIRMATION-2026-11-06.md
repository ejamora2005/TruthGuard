# Calibration confirmation - November 6, 2026

## Verified directly in the local application

- Image-score calibration is enabled in the bootstrapped Laravel configuration.
- The configured calibration SHA-256 matches the artifact on disk.
- Actual model weights, model configuration, and image preprocessor hashes match the artifact model identity.
- The application accepts the artifact as `calibrated_benchmark`.
- A representative inconclusive/disagreement payload now displays `Calibrated AI-signal score`, preserving the calibrated positive-class score and removing the incorrect uncalibrated tooltip. The verdict remains inconclusive; a calibrated score does not override detector disagreement.
- 27 calibration, score badge, and origin-decision tests passed, with 192 assertions. These are software checks, not an accuracy measurement.

## Recorded evaluation evidence (not rerun in this confirmation)

The pinned artifact describes 400 calibration images and 1,200 evaluation images from a balanced OpenFake image benchmark. Its Linux review replays the same 1,200-image benchmark, not additional independent observations. Recorded Linux AUC is 0.948869, Brier score 0.088104, ECE with ten bins 0.026958, and log loss 0.284486. AUC is not classification accuracy. The artifact is scoped to benchmark image scores, not all live uploads or video-level confidence.

Sources: `automation/models/ai-origin-broad/calibration.json`, `calibration-review.json`, and `calibration-linux-review.json`.

## Not confirmed

Unauthenticated access to production report 105 redirects to `/login`. Production calibration environment settings, active artifact/model hashes, worker configuration, current deployed commit, and the private saved report were not accessible in this check. Local success is not production confirmation.

The existing reliability audit explicitly states that independent deployment accuracy is not measured. Neither calibration, passing software tests, nor agreement on selected examples establishes 100% accuracy across images, videos, links, and factual claims. Defense statements must retain the evaluated population, metrics, runtime, and limitations.
