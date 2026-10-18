# Image-origin detector packaging for Dokploy

The production Dockerfile packages the image-origin detector in the shared app,
worker and scheduler image. It does not enable the detector or calibration.

## What the build does

- Installs `requirements-ai-origin.txt` into the isolated
  `/var/www/html/.venv-ai-origin` CPU environment. The existing TensorFlow/video
  environment stays at `/opt/truthguard-deepfake-venv`.
- Checks package dependencies and imports before completing the runtime layer.
- Downloads the checkpoint in the independent `ai-origin-weights` stage using
  `install_ai_origin.py`. That stage depends only on the downloader and the four
  pinned model metadata/configuration files, so ordinary application changes can
  reuse Docker's cached download layer.
- Copies the verified checkpoint into
  `/var/www/html/automation/models/ai-origin-broad/pytorch_model.pt` and verifies
  it again against the final image's configuration files.

The installer requires release `6455cf791436ee914c9556ab71578cce9761fef7`,
size **2,105,483,083 bytes**, and SHA-256
`caae0c005d8e37e7aa086aa241d1c9445d296ef77649004655c14f5c81130d4b`.
Incorrect weights or configuration files fail the build. The checkpoint remains
excluded from Git and the local Docker build context. It is downloaded during
the image build, not during an upload or container startup.

The three configuration JSON files retain their audited CRLF bytes through
explicit `-text` rules in `.gitattributes`. Normalizing them to LF changes their
SHA-256 hashes even when the JSON settings are identical. Keep the byte-preserving
rules and the original manifest/calibration hashes together. The installer tests
exercise Git staging and both Linux-style and Windows-style checkouts to check
that packaged configuration bytes still match the calibration identity.

The first build needs network access to the package indexes and Hugging Face,
plus disk space for dependencies, the checkpoint, and Docker's build layers.
Cache reuse depends on the builder retaining those layers; a clean build can
download the model again. A failed download fails the build without publishing
an incomplete image.

## Settings while preparing the server

```dotenv
AI_ORIGIN_ENABLED=false
AI_ORIGIN_MODEL_PATH=automation/models/ai-origin-broad
AI_ORIGIN_PYTHON_BINARY=.venv-ai-origin/bin/python
AI_ORIGIN_TIMEOUT=240
AI_ORIGIN_CPU_THREADS=1
MEDIA_CALIBRATION_ENABLED=false
MEDIA_CALIBRATION_PATH=automation/models/ai-origin-broad/calibration.json
MEDIA_CALIBRATION_SHA256=d6f9cf0eaeb6dd2464259cf6305f8f00ba23df4a7a3ae7a33782d28cd8034c6c
```

Use these as production preparation settings, not as replacements for the
already enabled Windows development settings. Environment overrides in Dokploy
take precedence over the repository's defaults.

## Checks still required on the target server

1. Build the new image and confirm the runtime import and checkpoint checks pass.
2. Set a memory/concurrency budget before running image inference. The web app
   and general worker defaults are 1 GiB each. The dedicated `evidence-worker`
   has a 4 GiB limit, one CPU, and one queue process. Set
   `FACT_CHECK_QUEUE_ENABLED=true` only when this worker is deployed and the
   database's `jobs` / `failed_jobs` tables and
   `2026_10_13_010000_allow_pending_detection_assessments` migration are ready.
   New submissions and media retries then run
   on the `evidence` connection/queue, rather than in the web container. Keep one
   evidence worker on the shared 8 GiB host; do not scale it without a new memory
   budget. Host swap does not remove container memory limits.
3. Confirm the actual model, preprocessing, parameter precision and predictions
   on the target Linux runtime. Build-time imports and a checksum verify
   packaging, not inference correctness or calibration transfer.
4. Enable image detection and calibration only after those runtime checks,
   then recreate the relevant containers with their final environment settings.

The committed calibration passed a separate 1,200-image Windows CPU evaluation.
Its label is **Calibrated model score**, scoped to that benchmark. The production
Linux runtime has not yet been validated. Keep its calibration disabled until
that verification is complete. Existing reports retain their saved scores.

No OpenAI calls are needed for downloading, checking, or testing the detector
offline. A normal website submission can still call OpenAI for its explanation.

## Linux smoke test reported on 2026-10-18

The operator ran the packaged model in an isolated container with one CPU,
4 GiB RAM, no swap, no network, a read-only root, and one inference thread. The
model loaded in **14.6 seconds**, scored a generated flat gray diagnostic image
in **13.1 seconds**, and exited **0**. Reported peak container memory was
**2,588.8 MiB** (process peak: 2,516.5 MiB). The model hash matched the pinned
release; parameter dtypes matched the calibration capture (448 bfloat16 and
506 float32 parameter tensors). These are operator-reported measurements, not
a locally rerun Docker test.

This establishes that the packaged model can load and infer within that limit.
The synthetic diagnostic image establishes neither accuracy nor numerical
agreement with the Windows evaluation. The real-image runtime comparison is
still outstanding; do not mark Linux calibration verification complete from
this smoke test.

The evidence worker uses `memswap_limit` equal to `mem_limit`, matching the
no-container-swap smoke test. The memory limit is a ceiling, not a preallocated
4 GiB reservation. Its default reservation is 512 MiB. Other services can still
consume host RAM; monitor the first real analysis under normal host load.

```dotenv
FACT_CHECK_QUEUE_ENABLED=true
AI_ORIGIN_CPU_THREADS=1
EVIDENCE_WORKER_CPU_LIMIT=1.0
EVIDENCE_WORKER_MEMORY_LIMIT=4g
EVIDENCE_WORKER_MEMORY_RESERVATION=512m
```

Queue preparation does not enable `AI_ORIGIN_ENABLED` or
`MEDIA_CALIBRATION_ENABLED`. Keep those false until the target-runtime checks
above are complete. A failed queued retry keeps the previous result and displays
a retry failure notice. Refreshing the result page only reads saved status.

Reference: [Docker Compose memory settings](https://docs.docker.com/reference/compose-file/services/#memswap_limit)
and [Laravel worker timeouts](https://laravel.com/docs/13.x/queues#worker-timeouts).
