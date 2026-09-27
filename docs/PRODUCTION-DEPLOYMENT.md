# TruthGuard production deployment readiness

## Trained model architecture

TruthGuard uses three trained inference artifacts in production:

| Model | Purpose | Runtime | Artifact | Loader | Caller |
| --- | --- | --- | --- | --- | --- |
| Filipino article transformer | Long-form Filipino fake-news article classification | Internal FastAPI `nlp` service, Python, PyTorch, Transformers | `automation/models/filipino-transformer/best_model/` | `automation/nlp/transformer_inference.py` | `App\Services\Detections\FilipinoNewsClassifier` over `TRUTHGUARD_NLP_URL` |
| Image CNN | Uploaded image synthetic-media/deepfake scoring | Laravel app/worker subprocess using `/opt/truthguard-deepfake-venv/bin/python`, TensorFlow CPU, Pillow, NumPy | `automation/models/truthguard_image_model.keras` plus `truthguard_image_model_config.json` | `automation/deepfake-models/cnn_adapter.py` | `App\Services\Detections\DeepfakeCnnClassifier` |
| Video face CNN | Uploaded video frame/face synthetic-media scoring | Laravel app/worker subprocess using the same Python venv, TensorFlow CPU, OpenCV, ffmpeg | `automation/models/truthguard_video_face_model.keras` plus `truthguard_video_face_model_config.json` | `automation/deepfake-models/cnn_adapter.py` | `App\Services\Detections\DeepfakeCnnClassifier` |

The old local `automation/models/deepfake-cnn.keras` checkpoint is not wired in
`config/services.php` and remains ignored. The production artifacts above are
tracked with Git LFS so a fresh deployment must run with LFS objects available
before Docker builds.

## Docker services

Production uses these Compose services:

| Service | Purpose | Public? |
| --- | --- | --- |
| `app` | Apache/PHP Laravel web app, frontend bundle, CNN Python venv | Yes, through Dokploy/proxy |
| `worker` | Existing database queue worker; sends push jobs and runs analysis jobs queued by Laravel | No |
| `scheduler` | Laravel scheduler for retention and fact-check announcements | No |
| `nlp` | Internal Filipino transformer FastAPI service on port 8765 | No |
| `db` | MySQL 8.4 database | No |
| `adminer` | Adminer utility on the internal/Dokploy network | Dokploy controlled |

Laravel containers use `TRUTHGUARD_NLP_URL=http://nlp:8765`; they do not call
another container through `localhost`.

## Environment variables

| Variable | Required by | Secret? | Local value needed? | Dokploy value needed? | Purpose |
| --- | --- | --- | --- | --- | --- |
| `APP_KEY` | Laravel | Yes | Yes | Yes | Existing stable Laravel encryption key |
| `APP_URL` | Laravel | No | Yes | Yes | Absolute app URL, `https://truthguard.online` in production |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | MySQL/Laravel | Yes | Yes for MySQL | Yes | Database credentials |
| `QUEUE_CONNECTION` | Laravel/worker | No | Yes | Yes | Must be `database` for async push/model flow |
| `DEEPFAKE_MODEL_ENABLED` | Laravel analysis | No | Optional | Yes | Enables trained image/video CNN classifier |
| `DEEPFAKE_MODEL_PYTHON_BINARY` | Laravel analysis | No | Optional | Usually default | Python binary for CNN subprocess |
| `DEEPFAKE_MODEL_SCRIPT_PATH` | Laravel analysis | No | Optional | Usually default | CNN adapter script path |
| `DEEPFAKE_CNN_MODEL_PATH`, `DEEPFAKE_CNN_CONFIG_PATH` | Image CNN | No | Optional | Usually default | Image Keras model and config |
| `DEEPFAKE_VIDEO_MODEL_PATH`, `DEEPFAKE_VIDEO_CONFIG_PATH` | Video CNN | No | Optional | Usually default | Video Keras model and config |
| `DEEPFAKE_CNN_*`, `DEEPFAKE_VIDEO_*` thresholds/settings | CNN models | No | Optional | Usually default | Image/video preprocessing, thresholds, labels and frame sampling |
| `TRUTHGUARD_NLP_ENABLED` | Laravel analysis | No | Optional | Yes | Enables Filipino article classifier calls |
| `TRUTHGUARD_NLP_URL` | Laravel analysis | No | Optional | Yes | Internal URL, `http://nlp:8765` in Docker |
| `TRUTHGUARD_NLP_MODEL_DIR` | NLP service | No | Optional | Usually default | Transformer model directory inside the `nlp` image |
| `TRUTHGUARD_NLP_*` thresholds/timeouts | NLP service and Laravel | No | Optional | Usually default | Prediction threshold, text length and timeout tuning |
| `FIREBASE_ENABLED` | Laravel push | No | Yes | Yes | Enables queued FCM delivery |
| `FIREBASE_API_KEY`, `FIREBASE_AUTH_DOMAIN`, `FIREBASE_PROJECT_ID`, `FIREBASE_STORAGE_BUCKET`, `FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_APP_ID`, `FIREBASE_VAPID_KEY` | Browser push client | No, public web config | Yes | Yes | Firebase web app and public VAPID configuration |
| `FIREBASE_CREDENTIALS_BASE64` or `FIREBASE_CREDENTIALS_PATH` | Laravel push | Yes | Yes | Yes | Server-side Firebase Admin credential; use one source |
| `OPENAI_API_KEY` | Optional OpenAI analysis/enrichment | Yes | If enabled | If enabled | External AI comparison and claim extraction |
| `GOOGLE_FACT_CHECK_API_KEY`, `GNEWS_API_KEY`, `NEWSAPI_API_KEY`, `OPENWEATHER_API_KEY` | Evidence services | Yes | If enabled | If enabled | Live evidence enrichment |
| Mail and OAuth variables | Auth/email flows | Yes where tokens/passwords | If enabled | If enabled | Login, email and integrations |

Use `docs/DOKPLOY-ENV.example` as the safe Dokploy template. Replace
placeholders only in Dokploy, not in Git.

## Deployment checks

Run these before production cutover:

```sh
git lfs pull
docker compose config --quiet
docker compose build app worker scheduler nlp
docker compose run --rm app php artisan migrate --force
docker compose up -d app worker scheduler nlp db
docker compose exec app php artisan truthguard:models:health --load --json
docker compose exec app php artisan queue:restart
docker compose logs --tail=100 worker
```

After deployment, sign in to `https://truthguard.online`, enable browser push,
submit one text article, one image upload and one video upload, then confirm the
same database notification is visible in the bell and queued push delivery is
accepted by Firebase. Do not claim device display until the device notification
is actually observed.
