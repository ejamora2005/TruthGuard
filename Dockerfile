FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build && npm prune --omit=dev

FROM php:8.4-apache-bookworm AS app
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PLAYWRIGHT_BROWSERS_PATH=/opt/playwright \
    HOME=/var/www/html/storage \
    VIEW_COMPILED_PATH=/var/www/html/bootstrap/cache/views
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libicu-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring zip intl gd bcmath pcntl opcache \
    && a2enmod rewrite headers remoteip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=frontend /app/node_modules ./node_modules
RUN node node_modules/playwright/cli.js install --with-deps chromium \
    && chmod -R a+rX /opt/playwright && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
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
