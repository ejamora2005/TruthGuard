# TruthGuard public image-origin benchmark

This workflow obtains publisher-labeled images without asking the researcher to
collect personal photographs. It uses the University of Naples Federico II
B-Free extended Synthbuster package:

- `real_RAISE_1k.zip`: camera photographs from RAISE;
- `sd3_flux.zip`: generated images from FLUX and Stable Diffusion 3.

The archives total about 4.7 GB. Extracted images and the 2.1 GB detector require
additional working space. Keep at least 12 GB free. These images are evaluation
data only and must never be copied into a training directory.

## 1. Download and verify

```powershell
$python = ".venv-ai-origin\Scripts\python.exe"
& $python automation\evaluation\download_public_image_benchmark.py `
  --output-dir automation\evaluation\public-benchmark
```

The downloader verifies the official archive checksums before safe extraction.
Interrupted downloads use a resumable `.part` file and are not treated as valid
data. Run the same command again to continue from the saved byte offset.

## 2. Create immutable annotations

```powershell
& $python automation\evaluation\prepare_public_image_benchmark.py `
  --dataset-dir automation\evaluation\public-benchmark\datasets `
  --output automation\evaluation\public-benchmark\annotations.jsonl
```

The manifest records SHA-256, source URL, publisher provenance, source family,
and a deterministic calibration/test split. It explicitly records that publisher
labels have not been independently human-reviewed and that training overlap has
not been proven. Never change those fields without an actual documented review.
The all-in-one downloader also creates controlled JPEG-compressed, resized,
screenshot-framed, text-overlay, and color/contrast-filtered variants for 100
originals per source. Each derivative inherits its original `media_group`, so an
original and its derivatives always remain in the same split.

## 3. Run the frozen detector

```powershell
& $python automation\evaluation\benchmark_image_calibration.py `
  --annotations automation\evaluation\public-benchmark\annotations.jsonl `
  --output-dir automation\evaluation\public-benchmark\run-001 `
  --threads 4
```

This can take hours on CPU. The report measures held-out performance and false
classifications, but remains experimental and cannot automatically alter the
production threshold. Threshold selection must use the calibration split; final
metrics are reported once on the untouched test split.

## Required follow-up for TruthGuard's actual uploads

The public benchmark does not represent every Facebook screenshot, meme, or
Philippine news graphic. After the original-image benchmark, derive compression,
resize, screenshot-frame, and text-overlay variants from calibration images only,
keep every derivative in its original `media_group`, and evaluate a separately
frozen robustness test. Never split an original and its derivatives across
calibration and test.

Sources: the [B-Free project](https://grip-unina.github.io/B-Free/), the
[official archive directory](https://www.grip.unina.it/download/prog/B-Free/extended_synthbuster/),
and the [Synthbuster dataset record](https://zenodo.org/records/10066460).
