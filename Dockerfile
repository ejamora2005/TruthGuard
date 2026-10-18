FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build && npm prune --omit=dev

# Cache the verified checkpoint independently of application and UI changes.
FROM python:3.11-slim-bookworm AS ai-origin-weights
WORKDIR /model-build
COPY automation/deepfake-models/install_ai_origin.py ./automation/deepfake-models/install_ai_origin.py
COPY automation/models/ai-origin-broad/source.json automation/models/ai-origin-broad/config.json automation/models/ai-origin-broad/preprocessor_config.json automation/models/ai-origin-broad/siglip_config.json ./automation/models/ai-origin-broad/
RUN python -u automation/deepfake-models/install_ai_origin.py

FROM php:8.4-apache-bookworm AS app
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PLAYWRIGHT_BROWSERS_PATH=/opt/playwright \
    HOME=/var/www/html/storage \
    VIEW_COMPILED_PATH=/var/www/html/bootstrap/cache/views
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libicu-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    python3 python3-venv python3-pip libgomp1 libglib2.0-0 libgl1 ffmpeg poppler-utils \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring zip intl gd bcmath pcntl opcache \
    && a2enmod rewrite headers remoteip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY automation/deepfake-models/requirements.txt ./automation/deepfake-models/requirements.txt
RUN python3 -m venv /opt/truthguard-deepfake-venv \
    && /opt/truthguard-deepfake-venv/bin/python -m pip install --no-cache-dir --upgrade pip \
    && /opt/truthguard-deepfake-venv/bin/python -m pip install --no-cache-dir -r automation/deepfake-models/requirements.txt
# Keep the image-origin detector's pinned CPU packages separate from TensorFlow.
COPY automation/deepfake-models/requirements-ai-origin.txt ./automation/deepfake-models/requirements-ai-origin.txt
RUN python3 -m venv /var/www/html/.venv-ai-origin \
    && /var/www/html/.venv-ai-origin/bin/python -m pip install --no-cache-dir --upgrade pip \
    && /var/www/html/.venv-ai-origin/bin/python -m pip install \
        --no-cache-dir \
        --extra-index-url https://download.pytorch.org/whl/cpu \
        -r automation/deepfake-models/requirements-ai-origin.txt \
    && /var/www/html/.venv-ai-origin/bin/python -m pip check \
    && /var/www/html/.venv-ai-origin/bin/python -c \
        "import torch, torchvision, timm, peft; from transformers import SiglipImageProcessor, SiglipVisionModel; assert torch.version.cuda is None; print('AI-origin CPU runtime ready')"
COPY --from=frontend /app/node_modules ./node_modules
RUN node node_modules/playwright/cli.js install --with-deps chromium \
    && chmod -R a+rX /opt/playwright && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=ai-origin-weights /model-build/automation/models/ai-origin-broad/pytorch_model.pt ./automation/models/ai-origin-broad/pytorch_model.pt
# Recheck the packaged weights against the application image's bundled configs.
RUN /var/www/html/.venv-ai-origin/bin/python automation/deepfake-models/install_ai_origin.py
# Fail the image build if Git LFS objects were not present in the checkout.
# The model contents are unchanged; this prevents a misleading healthy image
# with tiny LFS pointer files in place of the trained artifacts.
RUN test "$(wc -c < automation/models/truthguard_image_model.keras)" -gt 1000000 \
    && test "$(wc -c < automation/models/truthguard_video_face_model.keras)" -gt 1000000 \
    && test "$(wc -c < automation/models/filipino-transformer/best_model/model.safetensors)" -gt 100000000
COPY --from=frontend /app/public/build ./public/build
RUN mkdir -p bootstrap/cache/views storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/views-runtime storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/production.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/truthguard-entrypoint
ENTRYPOINT ["truthguard-entrypoint"]
CMD ["apache2-foreground"]
