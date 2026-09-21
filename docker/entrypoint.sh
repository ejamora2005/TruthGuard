#!/bin/sh
set -eu

if [ "${APP_ENV:-production}" = "production" ]; then
    case "${APP_DEBUG:-false}" in false|0) ;; *) echo 'APP_DEBUG must be false in production.' >&2; exit 1 ;; esac
    if [ -z "${APP_KEY:-}" ]; then
        echo 'Set a persistent APP_KEY before starting production services.' >&2
        exit 1
    fi
fi

mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/views-runtime storage/logs
mkdir -p "${VIEW_COMPILED_PATH:-bootstrap/cache/views}"
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec "$@"
